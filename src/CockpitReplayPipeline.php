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
    private const SLOW_TAXI_SPEED_KT = 5.0;
    private const GROUND_SPEED_MAX_KT = 20.0;
    private const FT_PER_M = 3.280839895;
    private const POSITION_GOOD_MAX_GAP_S = 1.0;
    private const POSITION_DEGRADED_MAX_GAP_S = 3.0;
    private const ATTITUDE_GOOD_MAX_GAP_S = 0.5;
    private const ATTITUDE_DEGRADED_MAX_GAP_S = 2.0;

    /** @var array<string,float> */
    private array $profiling = array();

    /** @var array<string,mixed> */
    private array $diagnostics = array();

    /**
     * @param array<string,mixed> $recording
     * @param list<array<string,mixed>> $rawGpsRows
     * @param list<array<string,mixed>> $rawAhrsRows
     * @param list<array{seconds: float, row: array<string,string>}> $g3xSamples
     * @param list<array<string,mixed>> $phases
     * @return array{samples:list<array<string,mixed>>,diagnostics:array<string,mixed>,profiling:array<string,float>}
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

        $this->diagnostics = array(
            'raw_position_outliers_rejected' => 0,
            'max_ground_position_jump_before_m' => 0.0,
            'max_ground_position_jump_after_m' => 0.0,
            'max_ground_implied_speed_before_kt' => 0.0,
            'max_ground_implied_speed_after_kt' => 0.0,
            'count_altitude_spikes_rejected' => 0,
            'stationary_segments_count' => 0,
            'max_stationary_altitude_variation_ft' => 0.0,
            'max_ground_vertical_speed_fpm' => 0.0,
            'max_heading_delta_stationary_deg' => 0.0,
            'max_heading_delta_ground_deg' => 0.0,
            'heading_source_counts' => array('g3x' => 0, 'ahrs' => 0, 'gps_course' => 0, 'held' => 0),
        );

        $positionKnots = $this->buildPositionKnots($gpsPoints, $g3xPoints);
        $positionKnots = $this->rejectPositionOutliers($positionKnots, $origin);

        $stationaryStarted = microtime(true);
        $positionKnots = $this->applyStationaryHold($positionKnots);
        $this->profiling['stationary_detection_s'] = round(microtime(true) - $stationaryStarted, 4);

        $positionKnotsEnu = $this->positionKnotsToEnu($positionKnots, $origin);

        $headingKnots = $this->buildHeadingKnots($gpsPoints, $ahrsPoints, $g3xPoints);
        $pitchKnots = $this->buildScalarKnots($ahrsPoints, $g3xPoints, 'pitch_deg', 'pitch_deg');
        $rollKnots = $this->buildScalarKnots($ahrsPoints, $g3xPoints, 'roll_deg', 'roll_deg');
        $speedKnots = $this->buildSpeedKnots($gpsPoints, $g3xPoints);
        $altitudeKnots = $this->buildAltitudeKnots($gpsPoints, $g3xPoints);
        $altitudeBaseline = $this->computeAltitudeBaseline($gpsPoints, $g3xPoints);

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
        $headingElapsed = 0.0;
        $enuStarted = microtime(true);
        $samples = $this->buildGridSamples(
            $gridTimes,
            $positionKnotsEnu,
            $headingKnots,
            $pitchKnots,
            $rollKnots,
            $speedKnots,
            $altitudeKnots,
            $origin,
            $phases,
            $headingElapsed
        );
        $samples = $this->stabilizeHeadingSamples($samples);
        $samples = $this->stabilizeAltitudes($samples, $altitudeBaseline);
        $this->profiling['enu_interpolation_s'] = round(microtime(true) - $enuStarted - $headingElapsed, 4);
        $this->profiling['heading_interpolation_s'] = round($headingElapsed, 4);

        $maxReplayDt = $this->maxTimeGapFromSeries(array_map(static fn(array $s): array => array('t' => (float)$s['t']), $samples));

        $diagnostics = array_merge($this->diagnostics, array(
            'altitude_baseline_ft' => $altitudeBaseline['altitude_ft'] !== null ? round($altitudeBaseline['altitude_ft'], 1) : null,
            'altitude_source_used' => $altitudeBaseline['source'],
        ));

        return array(
            'samples' => $samples,
            'profiling' => $this->profiling,
            'diagnostics' => array_merge(array(
                'sample_rate_hz' => self::SAMPLE_RATE_HZ,
                'fixed_timestep_s' => self::FIXED_TIMESTEP_S,
                'raw_gps_count' => $rawGpsCount,
                'raw_ahrs_count' => $rawAhrsCount,
                'raw_g3x_count' => count($g3xPoints),
                'replay_sample_count' => count($samples),
                'replay_duration_s' => round($duration, 3),
                'max_raw_gps_gap_s' => round($maxRawGpsGap, 3),
                'max_replay_dt_s' => round($maxReplayDt, 3),
                'profiling' => $this->profiling,
                'enu_origin' => array(
                    'lat' => $origin['lat'],
                    'lon' => $origin['lon'],
                    'alt_m' => $origin['alt_m'],
                    'time_s' => $origin['t'],
                ),
                'warnings' => $warnings,
            ), $diagnostics),
        );
    }

    /**
     * @param list<float> $gridTimes
     * @param list<array{t:float,e:float,n:float,u:float}> $positionKnotsEnu
     * @param list<array{t:float,v:float}> $headingKnots
     * @param list<array{t:float,v:float}> $pitchKnots
     * @param list<array{t:float,v:float}> $rollKnots
     * @param list<array{t:float,v:float}> $speedKnots
     * @param list<array{t:float,v:float}> $altitudeKnots
     * @param array{lat:float,lon:float,alt_m:float,t:float} $origin
     * @param list<array<string,mixed>> $phases
     * @param float $headingElapsed set by reference
     * @return list<array<string,mixed>>
     */
    private function buildGridSamples(
        array $gridTimes,
        array $positionKnotsEnu,
        array $headingKnots,
        array $pitchKnots,
        array $rollKnots,
        array $speedKnots,
        array $altitudeKnots,
        array $origin,
        array $phases,
        float &$headingElapsed = 0.0
    ): array {
        $enuCursor = new ReplaySeriesCursor($positionKnotsEnu);
        $headingCursor = new ReplaySeriesCursor($headingKnots);
        $pitchCursor = new ReplaySeriesCursor($pitchKnots);
        $rollCursor = new ReplaySeriesCursor($rollKnots);
        $speedCursor = new ReplaySeriesCursor($speedKnots);
        $altitudeCursor = new ReplaySeriesCursor($altitudeKnots);

        $samples = array();
        $previousAltFt = null;
        $phaseIndex = 0;
        $phaseCount = count($phases);

        foreach ($gridTimes as $index => $timeS) {
            $enuSeg = $enuCursor->segmentAt($timeS);
            $geo = null;
            if ($enuSeg !== null) {
                $enu = ReplaySeriesCursor::lerpEnu($positionKnotsEnu, $enuSeg);
                $geo = $this->enuToGeodetic($enu['e'], $enu['n'], $enu['u'], $origin);
            }

            $altitudeFt = ReplaySeriesCursor::lerpScalar($altitudeKnots, $altitudeCursor->segmentAt($timeS));
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

            $positionGap = $enuCursor->nearestGap($timeS);
            $attitudeGap = min(
                $headingCursor->nearestGap($timeS),
                min($pitchCursor->nearestGap($timeS), $rollCursor->nearestGap($timeS))
            );

            while ($phaseIndex + 1 < $phaseCount && (float)($phases[$phaseIndex + 1]['start_seconds'] ?? 0) <= $timeS) {
                $phaseIndex++;
            }
            $phase = '';
            if ($phaseIndex < $phaseCount) {
                $start = (float)($phases[$phaseIndex]['start_seconds'] ?? 0);
                $end = (float)($phases[$phaseIndex]['end_seconds'] ?? 0);
                if ($timeS >= $start && $timeS <= $end) {
                    $phase = (string)($phases[$phaseIndex]['phase'] ?? '');
                }
            }

            $headingSeg = $headingCursor->segmentAt($timeS);
            $headingStarted = microtime(true);
            $headingDeg = ReplaySeriesCursor::lerpHeading($headingKnots, $headingSeg);
            $headingElapsed += microtime(true) - $headingStarted;

            $samples[] = array(
                'sample_index' => $index,
                't' => round($timeS, 3),
                'lat' => $geo !== null ? round($geo['lat'], 7) : null,
                'lon' => $geo !== null ? round($geo['lon'], 7) : null,
                'altitude_ft' => $altitudeFt !== null ? round($altitudeFt, 1) : null,
                'heading_deg' => $headingDeg,
                'pitch_deg' => ReplaySeriesCursor::lerpScalar($pitchKnots, $pitchCursor->segmentAt($timeS)),
                'roll_deg' => ReplaySeriesCursor::lerpScalar($rollKnots, $rollCursor->segmentAt($timeS)),
                'ground_speed_kt' => ReplaySeriesCursor::lerpScalar($speedKnots, $speedCursor->segmentAt($timeS)),
                'vertical_speed_fpm' => $verticalSpeedFpm !== null ? round($verticalSpeedFpm, 1) : null,
                'phase' => $phase,
                'position_quality' => $this->qualityFromGap($positionGap, self::POSITION_GOOD_MAX_GAP_S, self::POSITION_DEGRADED_MAX_GAP_S),
                'altitude_quality' => $this->qualityFromGap($altitudeCursor->nearestGap($timeS), self::POSITION_GOOD_MAX_GAP_S, self::POSITION_DEGRADED_MAX_GAP_S),
                'attitude_quality' => $this->qualityFromGap($attitudeGap, self::ATTITUDE_GOOD_MAX_GAP_S, self::ATTITUDE_DEGRADED_MAX_GAP_S),
            );
        }

        return $samples;
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
     * @return list<array{t:float,lat:float,lon:float,alt_m:float,speed_kt:float,source:string}>
     */
    private function rejectPositionOutliers(array $knots, array $origin): array
    {
        if (count($knots) < 3) {
            return $knots;
        }

        $enu = array_map(function (array $knot) use ($origin): array {
            $converted = $this->geodeticToEnu($knot['lat'], $knot['lon'], $knot['alt_m'], $origin);
            return $knot + $converted;
        }, $knots);

        $this->updateGroundJumpDiagnostics($enu, '_before');

        $reject = array();
        $accepted = array(0);
        for ($i = 1; $i < count($enu); $i++) {
            $prevIdx = $accepted[count($accepted) - 1];
            $prev = $enu[$prevIdx];
            $curr = $enu[$i];
            $dt = (float)$curr['t'] - (float)$prev['t'];
            if ($dt <= 0.0) {
                $reject[$i] = true;
                continue;
            }

            $distanceM = hypot((float)$curr['e'] - (float)$prev['e'], (float)$curr['n'] - (float)$prev['n']);
            $impliedKt = $this->metersPerSecondToKnots($distanceM / $dt);
            $groundSpeed = min((float)$prev['speed_kt'], (float)$curr['speed_kt']);
            $altDeltaFt = abs(((float)$curr['alt_m'] - (float)$prev['alt_m']) * self::FT_PER_M);
            $isGround = $groundSpeed < self::GROUND_SPEED_MAX_KT;

            if ($isGround && (
                $impliedKt > 40.0
                || ($dt <= 0.5 && $distanceM > 20.0)
                || ($dt > 1.0 && $distanceM > 50.0 && $impliedKt > 25.0)
                || $distanceM > 100.0
                || ($altDeltaFt > 35.0 && $distanceM > 10.0)
            )) {
                $reject[$i] = true;
                continue;
            }

            if ($isGround && $i + 1 < count($enu)) {
                $next = $enu[$i + 1];
                $returnDt = (float)$next['t'] - (float)$prev['t'];
                if ($returnDt > 0.0 && $returnDt <= 2.0) {
                    $prevToCurr = hypot((float)$curr['e'] - (float)$prev['e'], (float)$curr['n'] - (float)$prev['n']);
                    $currToNext = hypot((float)$next['e'] - (float)$curr['e'], (float)$next['n'] - (float)$curr['n']);
                    $prevToNext = hypot((float)$next['e'] - (float)$prev['e'], (float)$next['n'] - (float)$prev['n']);
                    if ($prevToCurr > 12.0 && $currToNext > 12.0 && $prevToNext < 6.0) {
                        $reject[$i] = true;
                    }
                }
            }

            if (isset($reject[$i])) {
                continue;
            }
            $accepted[] = $i;
        }

        if (!$reject) {
            $this->updateGroundJumpDiagnostics($enu, '_after');
            return $knots;
        }

        $filtered = array();
        foreach ($knots as $idx => $knot) {
            if (!isset($reject[$idx])) {
                $filtered[] = $knot;
            }
        }

        $this->diagnostics['raw_position_outliers_rejected'] = count($reject);
        $filteredEnu = array_map(function (array $knot) use ($origin): array {
            $converted = $this->geodeticToEnu($knot['lat'], $knot['lon'], $knot['alt_m'], $origin);
            return $knot + $converted;
        }, $filtered);
        $this->updateGroundJumpDiagnostics($filteredEnu, '_after');

        return $filtered;
    }

    /**
     * @param list<array<string,float|string>> $enu
     */
    private function updateGroundJumpDiagnostics(array $enu, string $suffix): void
    {
        $maxJump = 0.0;
        $maxSpeed = 0.0;
        for ($i = 1; $i < count($enu); $i++) {
            $before = $enu[$i - 1];
            $after = $enu[$i];
            $dt = (float)$after['t'] - (float)$before['t'];
            if ($dt <= 0.0) {
                continue;
            }
            $groundSpeed = min((float)$before['speed_kt'], (float)$after['speed_kt']);
            if ($groundSpeed >= self::GROUND_SPEED_MAX_KT) {
                continue;
            }
            $distanceM = hypot((float)$after['e'] - (float)$before['e'], (float)$after['n'] - (float)$before['n']);
            $maxJump = max($maxJump, $distanceM);
            $maxSpeed = max($maxSpeed, $this->metersPerSecondToKnots($distanceM / $dt));
        }

        $this->diagnostics['max_ground_position_jump' . $suffix . '_m'] = round($maxJump, 2);
        $this->diagnostics['max_ground_implied_speed' . $suffix . '_kt'] = round($maxSpeed, 1);
    }

    private function metersPerSecondToKnots(float $metersPerSecond): float
    {
        return $metersPerSecond * 1.943844492;
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
        $sourceCounts = array('g3x' => 0, 'ahrs' => 0, 'gps_course' => 0, 'held' => 0);
        foreach ($g3xPoints as $point) {
            if ($point['heading_deg'] !== null) {
                $knots[] = array('t' => $point['t'], 'v' => $point['heading_deg'], 'priority' => 3, 'source' => 'g3x');
                $sourceCounts['g3x']++;
            }
        }
        foreach ($ahrsPoints as $point) {
            if ($point['heading_deg'] !== null) {
                $knots[] = array('t' => $point['t'], 'v' => $point['heading_deg'], 'priority' => 2, 'source' => 'ahrs');
                $sourceCounts['ahrs']++;
            }
        }
        foreach ($gpsPoints as $point) {
            if ($point['speed_kt'] >= self::GPS_HEADING_MIN_SPEED_KT && $point['course'] >= 0.0) {
                $knots[] = array('t' => $point['t'], 'v' => self::normalizeDegrees($point['course']), 'priority' => 1, 'source' => 'gps_course');
                $sourceCounts['gps_course']++;
            }
        }
        $this->diagnostics['heading_source_counts'] = $sourceCounts;

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
     * @param list<array{t:float,lat:float,lon:float,alt_m:float,speed_kt:float,course:float,hacc:float|null,vacc:float|null,source:string}> $gpsPoints
     * @param list<array{t:float,lat:float,lon:float,alt_m:float,speed_kt:float,heading_deg:float|null,pitch_deg:float|null,roll_deg:float|null,alt_ft:float|null,vs_fpm:float|null}> $g3xPoints
     * @return array{altitude_ft:float|null,source:string}
     */
    private function computeAltitudeBaseline(array $gpsPoints, array $g3xPoints): array
    {
        $g3xAltitudes = array();
        foreach ($g3xPoints as $point) {
            if ($point['t'] < 0.0 || $point['t'] > 60.0 || $point['alt_ft'] === null) {
                continue;
            }
            $g3xAltitudes[] = (float)$point['alt_ft'];
        }
        $g3xAltitudes = $this->rejectAltitudeOutliers($g3xAltitudes);
        if ($g3xAltitudes) {
            return array('altitude_ft' => $this->median($g3xAltitudes), 'source' => 'g3x_or_baro_median_first_60s');
        }

        $gpsAltitudes = array();
        foreach ($gpsPoints as $point) {
            if ($point['t'] < 0.0 || $point['t'] > 60.0) {
                continue;
            }
            $gpsAltitudes[] = (float)$point['alt_m'] * self::FT_PER_M;
        }
        $gpsAltitudes = $this->rejectAltitudeOutliers($gpsAltitudes);
        if ($gpsAltitudes) {
            return array('altitude_ft' => $this->median($gpsAltitudes), 'source' => 'gps_median_first_60s');
        }

        return array('altitude_ft' => null, 'source' => 'unavailable');
    }

    /**
     * @param list<float> $values
     * @return list<float>
     */
    private function rejectAltitudeOutliers(array $values): array
    {
        if (count($values) < 3) {
            return $values;
        }
        $median = $this->median($values);
        return array_values(array_filter(
            $values,
            static fn(float $value): bool => abs($value - $median) <= 75.0
        ));
    }

    /**
     * @param list<float> $values
     */
    private function median(array $values): float
    {
        sort($values, SORT_NUMERIC);
        $count = count($values);
        if ($count === 0) {
            return 0.0;
        }
        $mid = intdiv($count, 2);
        if ($count % 2 === 1) {
            return (float)$values[$mid];
        }
        return ((float)$values[$mid - 1] + (float)$values[$mid]) / 2.0;
    }

    /**
     * @param list<array<string,mixed>> $samples
     * @param array{altitude_ft:float|null,source:string} $baseline
     * @return list<array<string,mixed>>
     */
    private function stabilizeAltitudes(array $samples, array $baseline): array
    {
        if (!$samples) {
            return $samples;
        }

        $baselineFt = $baseline['altitude_ft'];
        if ($baselineFt === null) {
            $raw = array_values(array_filter(
                array_map(static fn(array $sample): ?float => isset($sample['altitude_ft']) && is_numeric($sample['altitude_ft']) ? (float)$sample['altitude_ft'] : null, $samples),
                static fn(?float $value): bool => $value !== null
            ));
            $baselineFt = $raw ? $this->median($this->rejectAltitudeOutliers($raw)) : 0.0;
        }

        $stationarySegments = $this->stationarySampleSegments($samples);
        $segmentAltitudeByIndex = array();
        foreach ($stationarySegments as [$from, $to]) {
            $alts = array();
            for ($i = $from; $i <= $to; $i++) {
                if (isset($samples[$i]['altitude_ft']) && is_numeric($samples[$i]['altitude_ft'])) {
                    $alts[] = (float)$samples[$i]['altitude_ft'];
                }
            }
            $segmentAlt = $alts ? $this->median($this->rejectAltitudeOutliers($alts)) : (float)$baselineFt;
            if (abs($segmentAlt - (float)$baselineFt) > 35.0) {
                $segmentAlt = (float)$baselineFt;
            }
            for ($i = $from; $i <= $to; $i++) {
                $segmentAltitudeByIndex[$i] = $segmentAlt;
            }
        }
        $this->diagnostics['stationary_segments_count'] = count($stationarySegments);

        $previousAlt = null;
        $maxGroundVs = 0.0;
        foreach ($samples as $i => &$sample) {
            $speed = isset($sample['ground_speed_kt']) && is_numeric($sample['ground_speed_kt']) ? (float)$sample['ground_speed_kt'] : 0.0;
            $phase = (string)($sample['phase'] ?? '');
            $rawAlt = isset($sample['altitude_ft']) && is_numeric($sample['altitude_ft']) ? (float)$sample['altitude_ft'] : (float)$baselineFt;
            $ground = $this->isGroundPhase($phase) || $speed < self::SLOW_TAXI_SPEED_KT;
            $altitudeQuality = ($baseline['source'] !== 'gps_median_first_60s' && $baseline['source'] !== 'unavailable') ? 'GOOD' : 'DEGRADED';

            if (isset($segmentAltitudeByIndex[$i])) {
                $alt = $segmentAltitudeByIndex[$i];
                $vs = 0.0;
            } elseif ($ground || $speed < self::GROUND_SPEED_MAX_KT) {
                $alt = $previousAlt !== null ? $previousAlt + (((float)$baselineFt - $previousAlt) * 0.08) : (float)$baselineFt;
                if (abs($alt - (float)$baselineFt) < 0.25) {
                    $alt = (float)$baselineFt;
                }
                $vs = 0.0;
            } else {
                $alt = $previousAlt !== null ? $previousAlt + (($rawAlt - $previousAlt) * 0.35) : $rawAlt;
                $deltaFt = $previousAlt !== null ? $alt - $previousAlt : 0.0;
                $vs = self::FIXED_TIMESTEP_S > 0.0 ? ($deltaFt / self::FIXED_TIMESTEP_S) * 60.0 : 0.0;
                $maxVs = $speed < self::GROUND_SPEED_MAX_KT ? 3000.0 : 6000.0;
                if (abs($vs) > $maxVs) {
                    $this->diagnostics['count_altitude_spikes_rejected']++;
                    $vs = max(-$maxVs, min($maxVs, $vs));
                    $alt = ($previousAlt ?? $alt) + (($vs / 60.0) * self::FIXED_TIMESTEP_S);
                    $altitudeQuality = 'DEGRADED';
                }
            }

            if ($this->isGroundPhase($phase) || $speed < self::SLOW_TAXI_SPEED_KT) {
                $vs = 0.0;
            }
            if ($this->isGroundPhase($phase) || $speed < self::GROUND_SPEED_MAX_KT) {
                $maxGroundVs = max($maxGroundVs, abs((float)$vs));
            }

            $sample['altitude_ft'] = round($alt, 1);
            $sample['vertical_speed_fpm'] = round($vs, 1);
            $sample['altitude_quality'] = $altitudeQuality;
            $previousAlt = $alt;
        }
        unset($sample);

        $this->diagnostics['max_ground_vertical_speed_fpm'] = round($maxGroundVs, 1);
        $this->diagnostics['max_stationary_altitude_variation_ft'] = round($this->maxStationaryAltitudeVariation($samples, $stationarySegments), 1);

        return $samples;
    }

    /**
     * @param list<array<string,mixed>> $samples
     * @return list<array<string,mixed>>
     */
    private function stabilizeHeadingSamples(array $samples): array
    {
        $previous = null;
        $maxStationaryDelta = 0.0;
        $maxGroundDelta = 0.0;
        foreach ($samples as &$sample) {
            $raw = isset($sample['heading_deg']) && is_numeric($sample['heading_deg']) ? self::normalizeDegrees((float)$sample['heading_deg']) : null;
            $speed = isset($sample['ground_speed_kt']) && is_numeric($sample['ground_speed_kt']) ? (float)$sample['ground_speed_kt'] : 0.0;
            $phase = (string)($sample['phase'] ?? '');
            $isStationary = $speed < self::STATIONARY_SPEED_KT;
            $isGround = $this->isGroundPhase($phase) || $speed < self::GROUND_SPEED_MAX_KT;

            if ($previous === null) {
                $heading = $raw ?? 0.0;
            } elseif ($raw === null) {
                $heading = $previous;
                $counts = $this->diagnostics['heading_source_counts'];
                if (is_array($counts)) {
                    $counts['held'] = (int)($counts['held'] ?? 0) + 1;
                    $this->diagnostics['heading_source_counts'] = $counts;
                }
            } elseif ($isStationary) {
                $heading = $previous;
            } elseif ($speed < self::SLOW_TAXI_SPEED_KT) {
                $heading = $this->smoothAngle($previous, $raw, 0.12);
            } elseif ($isGround) {
                $heading = $this->smoothAngle($previous, $raw, 0.22);
            } else {
                $heading = $this->smoothAngle($previous, $raw, 0.45);
            }

            if ($previous !== null) {
                $delta = abs(self::angleDelta($previous, $heading));
                if ($isStationary) {
                    $maxStationaryDelta = max($maxStationaryDelta, $delta);
                }
                if ($isGround) {
                    $maxGroundDelta = max($maxGroundDelta, $delta);
                }
            }

            $sample['heading_deg'] = round(self::normalizeDegrees($heading), 2);
            $previous = (float)$sample['heading_deg'];
        }
        unset($sample);

        $this->diagnostics['max_heading_delta_stationary_deg'] = round($maxStationaryDelta, 2);
        $this->diagnostics['max_heading_delta_ground_deg'] = round($maxGroundDelta, 2);
        return $samples;
    }

    private function smoothAngle(float $from, float $to, float $alpha): float
    {
        return self::normalizeDegrees($from + self::angleDelta($from, $to) * max(0.0, min(1.0, $alpha)));
    }

    /**
     * @param list<array<string,mixed>> $samples
     * @return list<array{0:int,1:int}>
     */
    private function stationarySampleSegments(array $samples): array
    {
        $segments = array();
        $start = null;
        foreach ($samples as $idx => $sample) {
            $speed = isset($sample['ground_speed_kt']) && is_numeric($sample['ground_speed_kt']) ? (float)$sample['ground_speed_kt'] : 0.0;
            if ($speed < self::STATIONARY_SPEED_KT) {
                $start ??= $idx;
                continue;
            }
            if ($start !== null) {
                $duration = ((float)$samples[$idx - 1]['t']) - ((float)$samples[$start]['t']);
                if ($duration >= self::STATIONARY_MIN_DURATION_S) {
                    $segments[] = array($start, $idx - 1);
                }
                $start = null;
            }
        }
        if ($start !== null) {
            $duration = ((float)$samples[count($samples) - 1]['t']) - ((float)$samples[$start]['t']);
            if ($duration >= self::STATIONARY_MIN_DURATION_S) {
                $segments[] = array($start, count($samples) - 1);
            }
        }
        return $segments;
    }

    /**
     * @param list<array<string,mixed>> $samples
     * @param list<array{0:int,1:int}> $segments
     */
    private function maxStationaryAltitudeVariation(array $samples, array $segments): float
    {
        $max = 0.0;
        foreach ($segments as [$from, $to]) {
            $alts = array();
            for ($i = $from; $i <= $to; $i++) {
                if (isset($samples[$i]['altitude_ft']) && is_numeric($samples[$i]['altitude_ft'])) {
                    $alts[] = (float)$samples[$i]['altitude_ft'];
                }
            }
            if ($alts) {
                $max = max($max, max($alts) - min($alts));
            }
        }
        return $max;
    }

    private function isGroundPhase(string $phase): bool
    {
        $normalized = strtolower($phase);
        return str_contains($normalized, 'preflight')
            || str_contains($normalized, 'taxi')
            || str_contains($normalized, 'ground')
            || str_contains($normalized, 'block');
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

/**
 * Moving-pointer bracket search over sorted replay knot series.
 *
 * @template T of array{t:float}
 */
final class ReplaySeriesCursor
{
    private int $index = 0;

    /** @param list<array{t:float}> $knots */
    public function __construct(private array $knots)
    {
    }

    /**
     * @return array{before:int,after:int,ratio:float,edge:string}|null
     */
    public function segmentAt(float $timeS): ?array
    {
        if ($this->knots === array()) {
            return null;
        }

        $lastIdx = count($this->knots) - 1;
        if ($timeS <= $this->knots[0]['t']) {
            return array('before' => 0, 'after' => 0, 'ratio' => 0.0, 'edge' => 'before');
        }
        if ($timeS >= $this->knots[$lastIdx]['t']) {
            return array('before' => $lastIdx, 'after' => $lastIdx, 'ratio' => 0.0, 'edge' => 'after');
        }

        while ($this->index + 1 < count($this->knots) && $this->knots[$this->index + 1]['t'] < $timeS) {
            $this->index++;
        }

        $before = $this->index;
        $after = min($before + 1, $lastIdx);
        $span = max(0.001, $this->knots[$after]['t'] - $this->knots[$before]['t']);
        $ratio = max(0.0, min(1.0, ($timeS - $this->knots[$before]['t']) / $span));

        return array(
            'before' => $before,
            'after' => $after,
            'ratio' => $ratio,
            'edge' => 'interp',
        );
    }

    public function nearestGap(float $timeS): float
    {
        $segment = $this->segmentAt($timeS);
        if ($segment === null) {
            return 9999.0;
        }
        if ($segment['edge'] === 'before') {
            return abs($timeS - $this->knots[0]['t']);
        }
        if ($segment['edge'] === 'after') {
            $lastIdx = count($this->knots) - 1;
            return abs($timeS - $this->knots[$lastIdx]['t']);
        }

        return min(
            abs($timeS - $this->knots[$segment['before']]['t']),
            abs($timeS - $this->knots[$segment['after']]['t'])
        );
    }

    /**
     * @param list<array{t:float,e:float,n:float,u:float}> $knots
     * @param array{before:int,after:int,ratio:float,edge:string}|null $segment
     * @return array{e:float,n:float,u:float}|null
     */
    public static function lerpEnu(array $knots, ?array $segment): ?array
    {
        if ($segment === null || $knots === array()) {
            return null;
        }
        if ($segment['edge'] === 'before') {
            return array('e' => $knots[0]['e'], 'n' => $knots[0]['n'], 'u' => $knots[0]['u']);
        }
        if ($segment['edge'] === 'after') {
            $last = $knots[count($knots) - 1];
            return array('e' => $last['e'], 'n' => $last['n'], 'u' => $last['u']);
        }

        $before = $knots[$segment['before']];
        $after = $knots[$segment['after']];
        $ratio = $segment['ratio'];
        return array(
            'e' => $before['e'] + ($after['e'] - $before['e']) * $ratio,
            'n' => $before['n'] + ($after['n'] - $before['n']) * $ratio,
            'u' => $before['u'] + ($after['u'] - $before['u']) * $ratio,
        );
    }

    /**
     * @param list<array{t:float,v:float}> $knots
     * @param array{before:int,after:int,ratio:float,edge:string}|null $segment
     */
    public static function lerpScalar(array $knots, ?array $segment): ?float
    {
        if ($segment === null || $knots === array()) {
            return null;
        }
        if ($segment['edge'] === 'before') {
            return (float)$knots[0]['v'];
        }
        if ($segment['edge'] === 'after') {
            return (float)$knots[count($knots) - 1]['v'];
        }

        $before = (float)$knots[$segment['before']]['v'];
        $after = (float)$knots[$segment['after']]['v'];
        return $before + ($after - $before) * $segment['ratio'];
    }

    /**
     * @param list<array{t:float,v:float}> $knots
     * @param array{before:int,after:int,ratio:float,edge:string}|null $segment
     */
    public static function lerpHeading(array $knots, ?array $segment): ?float
    {
        if ($segment === null || $knots === array()) {
            return null;
        }
        if ($segment['edge'] === 'before') {
            return self::normalizeDegrees((float)$knots[0]['v']);
        }
        if ($segment['edge'] === 'after') {
            return self::normalizeDegrees((float)$knots[count($knots) - 1]['v']);
        }

        $start = self::normalizeDegrees((float)$knots[$segment['before']]['v']);
        $end = self::normalizeDegrees((float)$knots[$segment['after']]['v']);
        $delta = self::angleDelta($start, $end);
        return self::normalizeDegrees($start + $delta * $segment['ratio']);
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
