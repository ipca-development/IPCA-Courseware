<?php
declare(strict_types=1);

require_once __DIR__ . '/G3XFlightStreamParser.php';

/**
 * Phase 1 replay reconstruction: fixed-rate timeline with ENU position interpolation.
 */
final class CockpitReplayPipeline
{
    private const SAMPLE_RATE_HZ = 10;
    private const FIXED_TIMESTEP_S = 0.1;
    private const EARTH_RADIUS_M = 6378137.0;
    private const STATIONARY_SPEED_KT = 1.0;
    private const STATIONARY_MIN_DURATION_S = 2.0;
    private const GPS_HEADING_MIN_SPEED_KT = 5.0;
    private const POSITION_GOOD_MAX_GAP_S = 1.0;
    private const POSITION_DEGRADED_MAX_GAP_S = 3.0;
    private const ATTITUDE_GOOD_MAX_GAP_S = 0.5;
    private const ATTITUDE_DEGRADED_MAX_GAP_S = 2.0;

    /**
     * @param array<string,mixed> $recording
     * @param list<array<string,mixed>> $rawGpsRows
     * @param list<array<string,mixed>> $rawAhrsRows
     * @param list<array{seconds: float, row: array<string,string>}> $g3xSamples
     * @param list<array<string,mixed>> $phases
     * @return array{samples:list<array<string,mixed>>,diagnostics:array<string,mixed>}
     */
    public function build(
        array $recording,
        array $rawGpsRows,
        array $rawAhrsRows,
        array $g3xSamples,
        array $phases
    ): array {
        $warnings = array();
        $gpsPoints = $this->normalizeGpsPoints($rawGpsRows);
        $ahrsPoints = $this->normalizeAhrsPoints($rawAhrsRows);
        $g3xPoints = $this->normalizeG3xPoints($g3xSamples);

        $rawGpsCount = count($gpsPoints);
        $rawAhrsCount = count($ahrsPoints);
        if ($rawGpsCount === 0 && count($g3xPoints) === 0) {
            throw new RuntimeException('No GPS or G3X position samples available for replay reconstruction.');
        }

        $maxRawGpsGap = $this->maxTimeGap($gpsPoints);
        if ($maxRawGpsGap > 2.0) {
            $warnings[] = 'Raw GPS has gaps larger than 2 seconds.';
        }
        if ($rawAhrsCount === 0) {
            $warnings[] = 'AHRS samples missing; attitude replay quality may be low.';
        }
        if (!$g3xPoints) {
            $warnings[] = 'G3X not attached; replay uses phone GPS and AHRS only.';
        }

        $origin = $this->firstGoodGpsOrigin($gpsPoints, $g3xPoints);
        if ($origin === null) {
            throw new RuntimeException('Could not determine replay ENU origin from GPS/G3X.');
        }

        $positionKnots = $this->buildPositionKnots($gpsPoints, $g3xPoints);
        $positionKnots = $this->applyStationaryHold($positionKnots);
        $positionKnotsEnu = $this->positionKnotsToEnu($positionKnots, $origin);

        $headingKnots = $this->buildHeadingKnots($gpsPoints, $ahrsPoints, $g3xPoints);
        $pitchKnots = $this->buildScalarKnots($ahrsPoints, $g3xPoints, 'pitch_deg', 'pitch_deg');
        $rollKnots = $this->buildScalarKnots($ahrsPoints, $g3xPoints, 'roll_deg', 'roll_deg');
        $speedKnots = $this->buildSpeedKnots($gpsPoints, $g3xPoints);
        $altitudeKnots = $this->buildAltitudeKnots($gpsPoints, $g3xPoints);

        $duration = max(
            0.0,
            (float)($recording['duration_seconds'] ?? 0),
            $this->lastTime($positionKnotsEnu),
            $this->lastTime($headingKnots)
        );
        if ($duration <= 0.0) {
            throw new RuntimeException('Recording duration is zero; cannot build replay timeline.');
        }

        $gridTimes = $this->buildFixedTimeline($duration);
        $samples = array();
        $previousAltFt = null;

        foreach ($gridTimes as $index => $timeS) {
            $enu = $this->interpolateEnu($timeS, $positionKnotsEnu);
            $geo = $enu !== null
                ? $this->enuToGeodetic($enu['e'], $enu['n'], $enu['u'], $origin)
                : null;

            $altitudeFt = $this->interpolateScalar($timeS, $altitudeKnots);
            if ($altitudeFt === null && $geo !== null) {
                $altitudeFt = $geo['alt_ft'];
            }

            $verticalSpeedFpm = null;
            if ($altitudeFt !== null && $previousAltFt !== null && self::FIXED_TIMESTEP_S > 0) {
                $verticalSpeedFpm = (($altitudeFt - $previousAltFt) / self::FIXED_TIMESTEP_S) * 60.0;
            }
            if ($altitudeFt !== null) {
                $previousAltFt = $altitudeFt;
            }

            $positionGap = $this->nearestKnotGapSeconds($timeS, $positionKnotsEnu);
            $attitudeGap = min(
                $this->nearestKnotGapSeconds($timeS, $headingKnots),
                min(
                    $this->nearestKnotGapSeconds($timeS, $pitchKnots),
                    $this->nearestKnotGapSeconds($timeS, $rollKnots)
                )
            );

            $samples[] = array(
                'sample_index' => $index,
                't' => round($timeS, 3),
                'lat' => $geo !== null ? round($geo['lat'], 7) : null,
                'lon' => $geo !== null ? round($geo['lon'], 7) : null,
                'altitude_ft' => $altitudeFt !== null ? round($altitudeFt, 1) : null,
                'heading_deg' => $this->interpolateHeading($timeS, $headingKnots),
                'pitch_deg' => $this->interpolateScalar($timeS, $pitchKnots),
                'roll_deg' => $this->interpolateScalar($timeS, $rollKnots),
                'ground_speed_kt' => $this->interpolateScalar($timeS, $speedKnots),
                'vertical_speed_fpm' => $verticalSpeedFpm !== null ? round($verticalSpeedFpm, 1) : null,
                'phase' => $this->phaseAtTime($timeS, $phases),
                'position_quality' => $this->qualityFromGap($positionGap, self::POSITION_GOOD_MAX_GAP_S, self::POSITION_DEGRADED_MAX_GAP_S),
                'altitude_quality' => $this->qualityFromGap($this->nearestKnotGapSeconds($timeS, $altitudeKnots), self::POSITION_GOOD_MAX_GAP_S, self::POSITION_DEGRADED_MAX_GAP_S),
                'attitude_quality' => $this->qualityFromGap($attitudeGap, self::ATTITUDE_GOOD_MAX_GAP_S, self::ATTITUDE_DEGRADED_MAX_GAP_S),
            );
        }

        $maxReplayDt = $this->maxTimeGapFromSeries(array_map(static fn(array $s): array => array('t' => (float)$s['t']), $samples));

        return array(
            'samples' => $samples,
            'diagnostics' => array(
                'sample_rate_hz' => self::SAMPLE_RATE_HZ,
                'fixed_timestep_s' => self::FIXED_TIMESTEP_S,
                'raw_gps_count' => $rawGpsCount,
                'raw_ahrs_count' => $rawAhrsCount,
                'raw_g3x_count' => count($g3xPoints),
                'replay_sample_count' => count($samples),
                'replay_duration_s' => round($duration, 3),
                'max_raw_gps_gap_s' => round($maxRawGpsGap, 3),
                'max_replay_dt_s' => round($maxReplayDt, 3),
                'enu_origin' => array(
                    'lat' => $origin['lat'],
                    'lon' => $origin['lon'],
                    'alt_m' => $origin['alt_m'],
                    'time_s' => $origin['t'],
                ),
                'warnings' => $warnings,
            ),
        );
    }

    /**
     * @param list<array<string,mixed>> $rawGpsRows
     * @return list<array{t:float,lat:float,lon:float,alt_m:float,speed_kt:float,course:float,hacc:float|null,vacc:float|null,source:string}>
     */
    private function normalizeGpsPoints(array $rawGpsRows): array
    {
        $points = array();
        foreach ($rawGpsRows as $row) {
            if (!isset($row['secondsSinceRecordingStart']) || !is_numeric($row['secondsSinceRecordingStart'])) {
                continue;
            }
            $t = (float)$row['secondsSinceRecordingStart'];
            if ($t < 0.0) {
                continue;
            }
            if (!isset($row['latitude'], $row['longitude']) || !is_numeric($row['latitude']) || !is_numeric($row['longitude'])) {
                continue;
            }
            $altM = isset($row['altitude']) && is_numeric($row['altitude']) ? (float)$row['altitude'] : 0.0;
            $speedKt = isset($row['speedKnots']) && is_numeric($row['speedKnots']) ? (float)$row['speedKnots'] : 0.0;
            $course = isset($row['course']) && is_numeric($row['course']) ? (float)$row['course'] : -1.0;
            $points[] = array(
                't' => $t,
                'lat' => (float)$row['latitude'],
                'lon' => (float)$row['longitude'],
                'alt_m' => $altM,
                'speed_kt' => max(0.0, $speedKt),
                'course' => $course,
                'hacc' => isset($row['horizontalAccuracy']) && is_numeric($row['horizontalAccuracy']) ? (float)$row['horizontalAccuracy'] : null,
                'vacc' => isset($row['verticalAccuracy']) && is_numeric($row['verticalAccuracy']) ? (float)$row['verticalAccuracy'] : null,
                'source' => 'gps',
            );
        }
        usort($points, fn(array $a, array $b): int => $a['t'] <=> $b['t']);
        return $this->dedupeTimes($points);
    }

    /**
     * @param list<array<string,mixed>> $rawAhrsRows
     * @return list<array{t:float,pitch_deg:float|null,roll_deg:float|null,heading_deg:float|null}>
     */
    private function normalizeAhrsPoints(array $rawAhrsRows): array
    {
        $points = array();
        foreach ($rawAhrsRows as $row) {
            if (!isset($row['secondsSinceRecordingStart']) || !is_numeric($row['secondsSinceRecordingStart'])) {
                continue;
            }
            $t = (float)$row['secondsSinceRecordingStart'];
            if ($t < 0.0) {
                continue;
            }
            $rawPitch = isset($row['pitch']) && is_numeric($row['pitch']) ? (float)$row['pitch'] : null;
            $rawRoll = isset($row['roll']) && is_numeric($row['roll']) ? (float)$row['roll'] : null;
            $pitch = isset($row['calibratedPitch']) && is_numeric($row['calibratedPitch'])
                ? (float)$row['calibratedPitch']
                : ($rawPitch !== null ? -$rawPitch : null);
            $roll = isset($row['calibratedRoll']) && is_numeric($row['calibratedRoll'])
                ? (float)$row['calibratedRoll']
                : $rawRoll;
            $heading = isset($row['correctedMagneticHeading']) && is_numeric($row['correctedMagneticHeading'])
                ? (float)$row['correctedMagneticHeading']
                : (isset($row['magneticHeading']) && is_numeric($row['magneticHeading']) ? (float)$row['magneticHeading'] : null);
            $points[] = array(
                't' => $t,
                'pitch_deg' => $pitch,
                'roll_deg' => $roll,
                'heading_deg' => $heading !== null ? self::normalizeDegrees($heading) : null,
            );
        }
        usort($points, fn(array $a, array $b): int => $a['t'] <=> $b['t']);
        return $points;
    }

    /**
     * @param list<array{seconds: float, row: array<string,string>}> $g3xSamples
     * @return list<array{t:float,lat:float,lon:float,alt_m:float,speed_kt:float,heading_deg:float|null,pitch_deg:float|null,roll_deg:float|null,alt_ft:float|null,vs_fpm:float|null}>
     */
    private function normalizeG3xPoints(array $g3xSamples): array
    {
        $points = array();
        foreach ($g3xSamples as $sample) {
            $t = (float)$sample['seconds'];
            if ($t < 0.0) {
                continue;
            }
            $row = $sample['row'];
            $lat = G3XFlightStreamParser::numericValue($row, 'Latitude (deg)', 'Latitude');
            $lon = G3XFlightStreamParser::numericValue($row, 'Longitude (deg)', 'Longitude');
            if ($lat === null || $lon === null) {
                continue;
            }
            $altFt = G3XFlightStreamParser::numericValue($row, 'GPS Altitude (ft)', 'AltGPS');
            if ($altFt === null) {
                $altFt = G3XFlightStreamParser::numericValue($row, 'Baro Altitude (ft)', 'AltInd');
            }
            $altM = $altFt !== null ? (float)$altFt * 0.3048 : 0.0;
            $points[] = array(
                't' => $t,
                'lat' => (float)$lat,
                'lon' => (float)$lon,
                'alt_m' => $altM,
                'speed_kt' => (float)(G3XFlightStreamParser::numericValue($row, 'GPS Ground Speed (kt)', 'GndSpd') ?? 0.0),
                'heading_deg' => ($h = G3XFlightStreamParser::numericValue($row, 'Magnetic Heading (deg)', 'HDG')) !== null ? self::normalizeDegrees((float)$h) : null,
                'pitch_deg' => G3XFlightStreamParser::numericValue($row, 'Pitch (deg)', 'Pitch'),
                'roll_deg' => G3XFlightStreamParser::numericValue($row, 'Roll (deg)', 'Roll'),
                'alt_ft' => $altFt,
                'vs_fpm' => G3XFlightStreamParser::numericValue($row, 'Vertical Speed (ft/min)', 'VSpd'),
            );
        }
        usort($points, fn(array $a, array $b): int => $a['t'] <=> $b['t']);
        return $points;
    }

    /**
     * @param list<array{t:float,lat:float,lon:float,alt_m:float,speed_kt:float,course:float,hacc:float|null,vacc:float|null,source:string}> $gpsPoints
     * @param list<array{t:float,lat:float,lon:float,alt_m:float,speed_kt:float,heading_deg:float|null,pitch_deg:float|null,roll_deg:float|null,alt_ft:float|null,vs_fpm:float|null}> $g3xPoints
     * @return array{lat:float,lon:float,alt_m:float,t:float}|null
     */
    private function firstGoodGpsOrigin(array $gpsPoints, array $g3xPoints): ?array
    {
        foreach ($gpsPoints as $point) {
            if ($point['hacc'] !== null && $point['hacc'] > 50.0) {
                continue;
            }
            return array(
                'lat' => $point['lat'],
                'lon' => $point['lon'],
                'alt_m' => $point['alt_m'],
                't' => $point['t'],
            );
        }
        foreach ($g3xPoints as $point) {
            return array(
                'lat' => $point['lat'],
                'lon' => $point['lon'],
                'alt_m' => $point['alt_m'],
                't' => $point['t'],
            );
        }
        return null;
    }

    /**
     * @param list<array{t:float,lat:float,lon:float,alt_m:float,speed_kt:float,course:float,hacc:float|null,vacc:float|null,source:string}> $gpsPoints
     * @param list<array{t:float,lat:float,lon:float,alt_m:float,speed_kt:float,heading_deg:float|null,pitch_deg:float|null,roll_deg:float|null,alt_ft:float|null,vs_fpm:float|null}> $g3xPoints
     * @return list<array{t:float,lat:float,lon:float,alt_m:float,speed_kt:float,source:string}>
     */
    private function buildPositionKnots(array $gpsPoints, array $g3xPoints): array
    {
        $knots = array();
        foreach ($gpsPoints as $point) {
            if ($point['hacc'] !== null && $point['hacc'] > 30.0) {
                continue;
            }
            $knots[] = array(
                't' => $point['t'],
                'lat' => $point['lat'],
                'lon' => $point['lon'],
                'alt_m' => $point['alt_m'],
                'speed_kt' => $point['speed_kt'],
                'source' => 'gps',
            );
        }
        foreach ($g3xPoints as $point) {
            $knots[] = array(
                't' => $point['t'],
                'lat' => $point['lat'],
                'lon' => $point['lon'],
                'alt_m' => $point['alt_m'],
                'speed_kt' => $point['speed_kt'],
                'source' => 'g3x',
            );
        }
        usort($knots, fn(array $a, array $b): int => $a['t'] <=> $b['t']);

        $merged = array();
        foreach ($knots as $knot) {
            if (!$merged) {
                $merged[] = $knot;
                continue;
            }
            $last = $merged[count($merged) - 1];
            if (abs($knot['t'] - $last['t']) <= 0.05) {
                if ($knot['source'] === 'g3x') {
                    $merged[count($merged) - 1] = $knot;
                }
                continue;
            }
            $merged[] = $knot;
        }
        return $merged;
    }

    /**
     * @param list<array{t:float,lat:float,lon:float,alt_m:float,speed_kt:float,source:string}> $knots
     * @return list<array{t:float,lat:float,lon:float,alt_m:float,speed_kt:float,source:string}>
     */
    private function applyStationaryHold(array $knots): array
    {
        if (count($knots) < 2) {
            return $knots;
        }

        $segments = array();
        $start = 0;
        for ($i = 1; $i < count($knots); $i++) {
            $stationary = ($knots[$i]['speed_kt'] < self::STATIONARY_SPEED_KT)
                && ($knots[$i - 1]['speed_kt'] < self::STATIONARY_SPEED_KT);
            if (!$stationary) {
                if ($i - 1 > $start) {
                    $segments[] = array($start, $i - 1);
                }
                $start = $i;
            }
        }
        if (count($knots) - 1 > $start) {
            $segments[] = array($start, count($knots) - 1);
        }

        foreach ($segments as [$from, $to]) {
            $duration = $knots[$to]['t'] - $knots[$from]['t'];
            if ($duration < self::STATIONARY_MIN_DURATION_S) {
                continue;
            }
            $latSum = 0.0;
            $lonSum = 0.0;
            $altSum = 0.0;
            $count = $to - $from + 1;
            for ($i = $from; $i <= $to; $i++) {
                $latSum += $knots[$i]['lat'];
                $lonSum += $knots[$i]['lon'];
                $altSum += $knots[$i]['alt_m'];
            }
            $avgLat = $latSum / $count;
            $avgLon = $lonSum / $count;
            $avgAlt = $altSum / $count;
            for ($i = $from; $i <= $to; $i++) {
                $knots[$i]['lat'] = $avgLat;
                $knots[$i]['lon'] = $avgLon;
                $knots[$i]['alt_m'] = $avgAlt;
            }
        }

        return $knots;
    }

    /**
     * @param list<array{t:float,lat:float,lon:float,alt_m:float,speed_kt:float,source:string}> $knots
     * @param array{lat:float,lon:float,alt_m:float,t:float} $origin
     * @return list<array{t:float,e:float,n:float,u:float}>
     */
    private function positionKnotsToEnu(array $knots, array $origin): array
    {
        $enu = array();
        foreach ($knots as $knot) {
            $converted = $this->geodeticToEnu($knot['lat'], $knot['lon'], $knot['alt_m'], $origin);
            $enu[] = array(
                't' => $knot['t'],
                'e' => $converted['e'],
                'n' => $converted['n'],
                'u' => $converted['u'],
            );
        }
        return $enu;
    }

    /**
     * @param list<array{t:float,lat:float,lon:float,alt_m:float,speed_kt:float,course:float,hacc:float|null,vacc:float|null,source:string}> $gpsPoints
     * @param list<array{t:float,pitch_deg:float|null,roll_deg:float|null,heading_deg:float|null}> $ahrsPoints
     * @param list<array{t:float,lat:float,lon:float,alt_m:float,speed_kt:float,heading_deg:float|null,pitch_deg:float|null,roll_deg:float|null,alt_ft:float|null,vs_fpm:float|null}> $g3xPoints
     * @return list<array{t:float,v:float}>
     */
    private function buildHeadingKnots(array $gpsPoints, array $ahrsPoints, array $g3xPoints): array
    {
        $knots = array();
        foreach ($g3xPoints as $point) {
            if ($point['heading_deg'] !== null) {
                $knots[] = array('t' => $point['t'], 'v' => $point['heading_deg'], 'priority' => 3);
            }
        }
        foreach ($ahrsPoints as $point) {
            if ($point['heading_deg'] !== null) {
                $knots[] = array('t' => $point['t'], 'v' => $point['heading_deg'], 'priority' => 2);
            }
        }
        foreach ($gpsPoints as $point) {
            if ($point['speed_kt'] >= self::GPS_HEADING_MIN_SPEED_KT && $point['course'] >= 0.0) {
                $knots[] = array('t' => $point['t'], 'v' => self::normalizeDegrees($point['course']), 'priority' => 1);
            }
        }

        usort($knots, fn(array $a, array $b): int => $a['t'] <=> $b['t'] ?: $b['priority'] <=> $a['priority']);

        $merged = array();
        foreach ($knots as $knot) {
            if (!$merged) {
                $merged[] = array('t' => $knot['t'], 'v' => $knot['v']);
                continue;
            }
            $last = $merged[count($merged) - 1];
            if (abs($knot['t'] - $last['t']) <= 0.05) {
                continue;
            }
            $merged[] = array('t' => $knot['t'], 'v' => $knot['v']);
        }
        return $merged;
    }

    /**
     * @param list<array{t:float,pitch_deg:float|null,roll_deg:float|null,heading_deg:float|null}> $ahrsPoints
     * @param list<array{t:float,lat:float,lon:float,alt_m:float,speed_kt:float,heading_deg:float|null,pitch_deg:float|null,roll_deg:float|null,alt_ft:float|null,vs_fpm:float|null}> $g3xPoints
     * @return list<array{t:float,v:float}>
     */
    private function buildScalarKnots(array $ahrsPoints, array $g3xPoints, string $ahrsKey, string $g3xKey): array
    {
        $knots = array();
        foreach ($g3xPoints as $point) {
            $value = $point[$g3xKey] ?? null;
            if ($value !== null) {
                $knots[] = array('t' => $point['t'], 'v' => (float)$value, 'priority' => 2);
            }
        }
        foreach ($ahrsPoints as $point) {
            $value = $point[$ahrsKey] ?? null;
            if ($value !== null) {
                $knots[] = array('t' => $point['t'], 'v' => (float)$value, 'priority' => 1);
            }
        }
        usort($knots, fn(array $a, array $b): int => $a['t'] <=> $b['t'] ?: $b['priority'] <=> $a['priority']);
        return $this->mergeScalarKnots($knots);
    }

    /**
     * @param list<array{t:float,lat:float,lon:float,alt_m:float,speed_kt:float,course:float,hacc:float|null,vacc:float|null,source:string}> $gpsPoints
     * @param list<array{t:float,lat:float,lon:float,alt_m:float,speed_kt:float,heading_deg:float|null,pitch_deg:float|null,roll_deg:float|null,alt_ft:float|null,vs_fpm:float|null}> $g3xPoints
     * @return list<array{t:float,v:float}>
     */
    private function buildSpeedKnots(array $gpsPoints, array $g3xPoints): array
    {
        $knots = array();
        foreach ($g3xPoints as $point) {
            $knots[] = array('t' => $point['t'], 'v' => $point['speed_kt'], 'priority' => 2);
        }
        foreach ($gpsPoints as $point) {
            $knots[] = array('t' => $point['t'], 'v' => $point['speed_kt'], 'priority' => 1);
        }
        usort($knots, fn(array $a, array $b): int => $a['t'] <=> $b['t'] ?: $b['priority'] <=> $a['priority']);
        return $this->mergeScalarKnots($knots);
    }

    /**
     * @param list<array{t:float,lat:float,lon:float,alt_m:float,speed_kt:float,course:float,hacc:float|null,vacc:float|null,source:string}> $gpsPoints
     * @param list<array{t:float,lat:float,lon:float,alt_m:float,speed_kt:float,heading_deg:float|null,pitch_deg:float|null,roll_deg:float|null,alt_ft:float|null,vs_fpm:float|null}> $g3xPoints
     * @return list<array{t:float,v:float}>
     */
    private function buildAltitudeKnots(array $gpsPoints, array $g3xPoints): array
    {
        $knots = array();
        foreach ($g3xPoints as $point) {
            if ($point['alt_ft'] !== null) {
                $knots[] = array('t' => $point['t'], 'v' => (float)$point['alt_ft'], 'priority' => 2);
            }
        }
        foreach ($gpsPoints as $point) {
            $knots[] = array('t' => $point['t'], 'v' => $point['alt_m'] * 3.280839895, 'priority' => 1);
        }
        usort($knots, fn(array $a, array $b): int => $a['t'] <=> $b['t'] ?: $b['priority'] <=> $a['priority']);
        return $this->mergeScalarKnots($knots);
    }

    /**
     * @param list<array{t:float,v:float,priority:int}> $knots
     * @return list<array{t:float,v:float}>
     */
    private function mergeScalarKnots(array $knots): array
    {
        $merged = array();
        foreach ($knots as $knot) {
            if (!$merged) {
                $merged[] = array('t' => $knot['t'], 'v' => $knot['v']);
                continue;
            }
            $last = $merged[count($merged) - 1];
            if (abs($knot['t'] - $last['t']) <= 0.05) {
                continue;
            }
            $merged[] = array('t' => $knot['t'], 'v' => $knot['v']);
        }
        return $merged;
    }

    /**
     * @return list<float>
     */
    private function buildFixedTimeline(float $duration): array
    {
        $times = array();
        for ($t = 0.0; $t <= $duration + 1e-6; $t += self::FIXED_TIMESTEP_S) {
            $times[] = round($t, 3);
        }
        return $times;
    }

    /**
     * @param list<array{t:float,e:float,n:float,u:float}> $knots
     * @return array{e:float,n:float,u:float}|null
     */
    private function interpolateEnu(float $timeS, array $knots): ?array
    {
        if (!$knots) {
            return null;
        }
        if ($timeS <= $knots[0]['t']) {
            return array('e' => $knots[0]['e'], 'n' => $knots[0]['n'], 'u' => $knots[0]['u']);
        }
        $last = $knots[count($knots) - 1];
        if ($timeS >= $last['t']) {
            return array('e' => $last['e'], 'n' => $last['n'], 'u' => $last['u']);
        }

        $before = $knots[0];
        $after = $last;
        for ($i = 1; $i < count($knots); $i++) {
            if ($knots[$i]['t'] >= $timeS) {
                $after = $knots[$i];
                $before = $knots[$i - 1];
                break;
            }
        }

        $span = max(0.001, $after['t'] - $before['t']);
        $ratio = max(0.0, min(1.0, ($timeS - $before['t']) / $span));
        return array(
            'e' => $before['e'] + ($after['e'] - $before['e']) * $ratio,
            'n' => $before['n'] + ($after['n'] - $before['n']) * $ratio,
            'u' => $before['u'] + ($after['u'] - $before['u']) * $ratio,
        );
    }

    /**
     * @param list<array{t:float,v:float}> $knots
     */
    private function interpolateScalar(float $timeS, array $knots): ?float
    {
        if (!$knots) {
            return null;
        }
        if ($timeS <= $knots[0]['t']) {
            return (float)$knots[0]['v'];
        }
        $last = $knots[count($knots) - 1];
        if ($timeS >= $last['t']) {
            return (float)$last['v'];
        }

        $before = $knots[0];
        $after = $last;
        for ($i = 1; $i < count($knots); $i++) {
            if ($knots[$i]['t'] >= $timeS) {
                $after = $knots[$i];
                $before = $knots[$i - 1];
                break;
            }
        }

        $span = max(0.001, $after['t'] - $before['t']);
        $ratio = max(0.0, min(1.0, ($timeS - $before['t']) / $span));
        return (float)$before['v'] + ((float)$after['v'] - (float)$before['v']) * $ratio;
    }

    /**
     * @param list<array{t:float,v:float}> $knots
     */
    private function interpolateHeading(float $timeS, array $knots): ?float
    {
        if (!$knots) {
            return null;
        }
        if ($timeS <= $knots[0]['t']) {
            return self::normalizeDegrees((float)$knots[0]['v']);
        }
        $last = $knots[count($knots) - 1];
        if ($timeS >= $last['t']) {
            return self::normalizeDegrees((float)$last['v']);
        }

        $before = $knots[0];
        $after = $last;
        for ($i = 1; $i < count($knots); $i++) {
            if ($knots[$i]['t'] >= $timeS) {
                $after = $knots[$i];
                $before = $knots[$i - 1];
                break;
            }
        }

        $span = max(0.001, $after['t'] - $before['t']);
        $ratio = max(0.0, min(1.0, ($timeS - $before['t']) / $span));
        $start = self::normalizeDegrees((float)$before['v']);
        $delta = self::angleDelta($start, self::normalizeDegrees((float)$after['v']));
        return self::normalizeDegrees($start + $delta * $ratio);
    }

    /**
     * @param list<array{t:float}> $knots
     */
    private function nearestKnotGapSeconds(float $timeS, array $knots): float
    {
        if (!$knots) {
            return 9999.0;
        }
        $best = abs($timeS - $knots[0]['t']);
        foreach ($knots as $knot) {
            $best = min($best, abs($timeS - $knot['t']));
        }
        return $best;
    }

    private function qualityFromGap(float $gapSeconds, float $goodMax, float $degradedMax): string
    {
        if ($gapSeconds <= $goodMax) {
            return 'GOOD';
        }
        if ($gapSeconds <= $degradedMax) {
            return 'DEGRADED';
        }
        return 'LOW';
    }

    /**
     * @param list<array<string,mixed>> $phases
     */
    private function phaseAtTime(float $timeS, array $phases): string
    {
        foreach ($phases as $phase) {
            $start = (float)($phase['start_seconds'] ?? 0);
            $end = (float)($phase['end_seconds'] ?? 0);
            if ($timeS >= $start && $timeS <= $end) {
                return (string)($phase['phase'] ?? '');
            }
        }
        return '';
    }

    /**
     * @param list<array{t:float}> $points
     */
    private function maxTimeGap(array $points): float
    {
        return $this->maxTimeGapFromSeries($points);
    }

    /**
     * @param list<array{t:float}> $points
     */
    private function maxTimeGapFromSeries(array $points): float
    {
        if (count($points) < 2) {
            return 0.0;
        }
        $max = 0.0;
        for ($i = 1; $i < count($points); $i++) {
            $max = max($max, $points[$i]['t'] - $points[$i - 1]['t']);
        }
        return $max;
    }

    /**
     * @param list<array{t:float}> $points
     */
    private function lastTime(array $points): float
    {
        if (!$points) {
            return 0.0;
        }
        return (float)$points[count($points) - 1]['t'];
    }

    /**
     * @param array{lat:float,lon:float,alt_m:float,t:float} $origin
     * @return array{lat:float,lon:float,alt_ft:float}
     */
    private function enuToGeodetic(float $e, float $n, float $u, array $origin): array
    {
        $lat0Rad = deg2rad($origin['lat']);
        $lon0Rad = deg2rad($origin['lon']);
        $lat = $origin['lat'] + rad2deg($n / self::EARTH_RADIUS_M);
        $lon = $origin['lon'] + rad2deg($e / (self::EARTH_RADIUS_M * cos($lat0Rad)));
        $altM = $origin['alt_m'] + $u;
        return array(
            'lat' => $lat,
            'lon' => $lon,
            'alt_ft' => $altM * 3.280839895,
        );
    }

    /**
     * @param array{lat:float,lon:float,alt_m:float,t:float} $origin
     * @return array{e:float,n:float,u:float}
     */
    private function geodeticToEnu(float $lat, float $lon, float $altM, array $origin): array
    {
        $latRad = deg2rad($lat);
        $lonRad = deg2rad($lon);
        $lat0Rad = deg2rad($origin['lat']);
        $lon0Rad = deg2rad($origin['lon']);
        $dLat = $latRad - $lat0Rad;
        $dLon = $lonRad - $lon0Rad;
        return array(
            'e' => $dLon * cos($lat0Rad) * self::EARTH_RADIUS_M,
            'n' => $dLat * self::EARTH_RADIUS_M,
            'u' => $altM - $origin['alt_m'],
        );
    }

    /**
     * @param list<array{t:float}> $points
     * @return list<array{t:float}>
     */
    private function dedupeTimes(array $points): array
    {
        $deduped = array();
        foreach ($points as $point) {
            if (!$deduped) {
                $deduped[] = $point;
                continue;
            }
            $last = $deduped[count($deduped) - 1];
            if (abs($point['t'] - $last['t']) < 0.001) {
                continue;
            }
            $deduped[] = $point;
        }
        return $deduped;
    }

    private static function normalizeDegrees(float $value): float
    {
        $value = fmod($value, 360.0);
        if ($value < 0.0) {
            $value += 360.0;
        }
        return $value;
    }

    private static function angleDelta(float $from, float $to): float
    {
        $delta = self::normalizeDegrees($to) - self::normalizeDegrees($from);
        if ($delta > 180.0) {
            $delta -= 360.0;
        } elseif ($delta < -180.0) {
            $delta += 360.0;
        }
        return $delta;
    }
}
