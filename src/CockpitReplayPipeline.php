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
    private const GPS_HEADING_MIN_SPEED_KT = 8.0;
    private const SLOW_TAXI_SPEED_KT = 5.0;
    private const GROUND_SPEED_MAX_KT = 20.0;
    private const FT_PER_M = 3.280839895;
    private const POSITION_GOOD_MAX_GAP_S = 1.0;
    private const POSITION_DEGRADED_MAX_GAP_S = 3.0;
    private const POSITION_ABSURD_IMPLIED_SPEED_KT = 250.0;
    private const POSITION_ABSURD_MISMATCH_KT = 150.0;
    private const ATTITUDE_GOOD_MAX_GAP_S = 0.5;
    private const ATTITUDE_DEGRADED_MAX_GAP_S = 2.0;
    private const GROUND_PITCH_RATE_LIMIT_DEG_S = 10.0;
    private const GROUND_ROLL_RATE_LIMIT_DEG_S = 15.0;
    private const AIRBORNE_PITCH_RATE_LIMIT_DEG_S = 30.0;
    private const AIRBORNE_ROLL_RATE_LIMIT_DEG_S = 55.0;
    private const FALLBACK_MAGNETIC_VARIATION_DEG = -10.8;
    private const FALLBACK_MAGNETIC_VARIATION_SOURCE = 'ktrm_region_fallback';
    private const HEADING_OWNER_VALID_GAP_S = 1.25;
    private const HEADING_OWNER_SWITCH_AFTER_LOSS_S = 2.0;
    private const HEADING_OWNER_RETURN_STABLE_S = 2.0;
    private const HEADING_OWNER_RETURN_BLEND_S = 4.0;

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
     * @param array<string,mixed> $options
     * @return array{samples:list<array<string,mixed>>,diagnostics:array<string,mixed>,profiling:array<string,float>}
     */
    public function build(
        array $recording,
        array $rawGpsRows,
        array $rawAhrsRows,
        array $g3xSamples,
        array $phases,
        array $options = array()
    ): array {
        $warnings = array();
        $sourceMode = (string)($options['replay_source_mode'] ?? 'multi_source');
        if ($sourceMode !== 'g3x_only') {
            $sourceMode = 'multi_source';
        }
        $gpsPoints = $this->normalizeGpsPoints($rawGpsRows);
        $ahrsPoints = $this->normalizeAhrsPoints($rawAhrsRows);
        $g3xPoints = $this->normalizeG3xPoints($g3xSamples);
        $availableGpsCount = count($gpsPoints);
        $availableAhrsCount = count($ahrsPoints);

        if ($sourceMode === 'g3x_only') {
            $gpsPoints = array();
            $ahrsPoints = array();
        }

        $rawGpsCount = count($gpsPoints);
        $rawAhrsCount = count($ahrsPoints);
        if ($rawGpsCount === 0 && count($g3xPoints) === 0) {
            throw new RuntimeException('No GPS or G3X position samples available for replay reconstruction.');
        }

        $maxRawGpsGap = $this->maxTimeGap($gpsPoints);
        $maxRawG3xGap = $this->maxTimeGap($g3xPoints);
        if ($sourceMode !== 'g3x_only' && $maxRawGpsGap > 2.0) {
            $warnings[] = 'Raw GPS has gaps larger than 2 seconds.';
        }
        if ($sourceMode === 'g3x_only' && $maxRawG3xGap > 2.0) {
            $warnings[] = 'G3X-only replay has G3X gaps larger than 2 seconds.';
        }
        if ($sourceMode !== 'g3x_only' && $rawAhrsCount === 0) {
            $warnings[] = 'AHRS samples missing; attitude replay quality may be low.';
        }
        if (!$g3xPoints) {
            $warnings[] = 'G3X not attached; replay uses phone GPS and AHRS only.';
        } elseif ($sourceMode === 'g3x_only') {
            $warnings[] = 'Replay v2 built in G3X-only diagnostic mode; GPS/AHRS evidence intentionally ignored.';
        }

        $origin = $this->firstGoodGpsOrigin($gpsPoints, $g3xPoints);
        if ($origin === null) {
            throw new RuntimeException('Could not determine replay ENU origin from GPS/G3X.');
        }

        $this->diagnostics = array(
            'replay_source_mode' => $sourceMode,
            'raw_gps_available_count' => $availableGpsCount,
            'raw_ahrs_available_count' => $availableAhrsCount,
            'raw_gps_used_count' => $rawGpsCount,
            'raw_ahrs_used_count' => $rawAhrsCount,
            'raw_g3x_used_count' => count($g3xPoints),
            'raw_position_outliers_rejected' => 0,
            'max_ground_position_jump_before_m' => 0.0,
            'max_ground_position_jump_after_m' => 0.0,
            'max_ground_implied_speed_before_kt' => 0.0,
            'max_ground_implied_speed_after_kt' => 0.0,
            'count_altitude_spikes_rejected' => 0,
            'stationary_segments_count' => 0,
            'max_stationary_altitude_variation_ft' => 0.0,
            'max_ground_vertical_speed_fpm' => 0.0,
            'count_ground_altitude_stabilized' => 0,
            'max_heading_delta_stationary_deg' => 0.0,
            'max_heading_delta_ground_deg' => 0.0,
            'heading_source_counts' => array('g3x' => 0, 'ahrs' => 0, 'track_fallback' => 0, 'held' => 0),
            'track_source_counts' => array('g3x_track' => 0, 'gps_course' => 0),
            'magnetic_variation_source_counts' => array('g3x_magvar' => 0, 'ahrs_magnetic_variation' => 0, self::FALLBACK_MAGNETIC_VARIATION_SOURCE => 0),
            'count_heading_magnetic_to_true_converted' => 0,
            'count_heading_track_fallback_samples' => 0,
            'heading_owner' => 'unknown',
            'heading_owner_changes' => array(),
            'owner_switch_count' => 0,
            'owner_switch_reason' => array(),
            'owner_hold_events' => 0,
            'owner_blend_events' => 0,
            'owner_blend_duration' => self::HEADING_OWNER_RETURN_BLEND_S,
            'count_heading_owner_transitions' => 0,
            'max_groundspeed_delta_kt' => 0.0,
            'max_taxi_implied_speed_kt' => 0.0,
            'max_position_speed_mismatch_kt' => 0.0,
            'count_position_outliers' => 0,
            'count_takeoff_transition_position_corrections' => 0,
            'first_position_discontinuity_time_s' => null,
            'first_position_discontinuity_source' => null,
            'count_speed_outliers' => 0,
            'count_heading_outliers' => 0,
            'count_attitude_spikes_clamped' => 0,
            'max_pitch_delta_state_deg' => 0.0,
            'max_roll_delta_state_deg' => 0.0,
            'count_low_quality_samples' => 0,
            'count_degraded_samples' => 0,
            'quality_counts_by_field' => array(),
            'quality_source_counts_by_field' => array(),
            'low_quality_reason_counts' => array(),
            'degraded_quality_reason_counts' => array(),
            'count_position_corrections_degraded' => 0,
            'count_position_corrections_low' => 0,
            'count_empty_phase_samples' => 0,
        );

        $positionKnots = $this->buildPositionKnots($gpsPoints, $g3xPoints);
        $positionKnots = $this->rejectPositionOutliers($positionKnots, $origin);

        $stationaryStarted = microtime(true);
        $positionKnots = $this->applyStationaryHold($positionKnots);
        $this->profiling['stationary_detection_s'] = round(microtime(true) - $stationaryStarted, 4);

        $positionKnotsEnu = $this->positionKnotsToEnu($positionKnots, $origin);

        $g3xHeadingKnots = $this->buildG3xHeadingKnots($g3xPoints);
        $ahrsHeadingKnots = $this->buildAhrsHeadingKnots($ahrsPoints);
        $headingMagneticKnots = $this->buildHeadingMagneticKnots($ahrsPoints, $g3xPoints);
        $trackKnots = $this->buildTrackKnots($gpsPoints, $g3xPoints);
        $trackFallbackHeadingKnots = $this->buildTrackFallbackHeadingKnots($gpsPoints);
        $this->diagnostics['heading_source_counts'] = array(
            'g3x' => count($g3xHeadingKnots),
            'ahrs' => count($ahrsHeadingKnots),
            'track_fallback' => count($trackFallbackHeadingKnots),
            'held' => 0,
        );
        $this->diagnostics['count_heading_magnetic_to_true_converted'] = count($g3xHeadingKnots) + count($ahrsHeadingKnots);
        $headingKnots = $g3xHeadingKnots !== array()
            ? $g3xHeadingKnots
            : ($ahrsHeadingKnots !== array() ? $ahrsHeadingKnots : $trackFallbackHeadingKnots);
        $magneticVariationKnots = $this->buildMagneticVariationKnots($ahrsPoints, $g3xPoints);
        $compassDeviationKnots = $this->buildCompassDeviationKnots($ahrsPoints, $g3xPoints);
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
            $headingMagneticKnots,
            $trackKnots,
            $magneticVariationKnots,
            $compassDeviationKnots,
            $pitchKnots,
            $rollKnots,
            $speedKnots,
            $altitudeKnots,
            $origin,
            $phases,
            $headingElapsed
        );
        $samples = $this->applyHeadingOwnership($samples, $g3xHeadingKnots, $ahrsHeadingKnots, $trackFallbackHeadingKnots);
        $samples = $this->stabilizeAltitudes($samples, $altitudeBaseline);
        $samples = $this->validateReplaySamples($samples);
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
                'g3x_only_available_gps_count' => $availableGpsCount,
                'g3x_only_available_ahrs_count' => $availableAhrsCount,
                'max_raw_g3x_gap_s' => round($maxRawG3xGap, 3),
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
     * @param list<array{t:float,e:float,n:float,u:float,source?:string}> $positionKnotsEnu
     * @param list<array{t:float,v:float,source?:string}> $headingKnots
     * @param list<array{t:float,v:float,source?:string}> $headingMagneticKnots
     * @param list<array{t:float,v:float,source?:string}> $trackKnots
     * @param list<array{t:float,v:float,source?:string}> $magneticVariationKnots
     * @param list<array{t:float,v:float,source?:string}> $compassDeviationKnots
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
        array $headingMagneticKnots,
        array $trackKnots,
        array $magneticVariationKnots,
        array $compassDeviationKnots,
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
        $headingMagneticCursor = new ReplaySeriesCursor($headingMagneticKnots);
        $trackCursor = new ReplaySeriesCursor($trackKnots);
        $magneticVariationCursor = new ReplaySeriesCursor($magneticVariationKnots);
        $compassDeviationCursor = new ReplaySeriesCursor($compassDeviationKnots);
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
            $pitchSeg = $pitchCursor->segmentAt($timeS);
            $rollSeg = $rollCursor->segmentAt($timeS);
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
            $trackSeg = $trackCursor->segmentAt($timeS);
            $speedSeg = $speedCursor->segmentAt($timeS);
            $headingStarted = microtime(true);
            $headingDeg = ReplaySeriesCursor::lerpHeading($headingKnots, $headingSeg);
            $trackDeg = ReplaySeriesCursor::lerpHeading($trackKnots, $trackSeg);
            $headingElapsed += microtime(true) - $headingStarted;
            $headingMagnetic = ReplaySeriesCursor::lerpHeading($headingMagneticKnots, $headingMagneticCursor->segmentAt($timeS));
            $magneticVariation = ReplaySeriesCursor::lerpScalar($magneticVariationKnots, $magneticVariationCursor->segmentAt($timeS));
            $compassDeviation = ReplaySeriesCursor::lerpScalar($compassDeviationKnots, $compassDeviationCursor->segmentAt($timeS));
            $headingSource = $this->scalarSourceFromSegment($headingKnots, $headingSeg);
            $positionSource = $this->seriesSourceFromSegment($positionKnotsEnu, $enuSeg);
            $trackSource = $this->scalarSourceFromSegment($trackKnots, $trackSeg);
            $speedSource = $this->scalarSourceFromSegment($speedKnots, $speedSeg);
            $variationSource = $this->scalarSourceFromSegment($magneticVariationKnots, $magneticVariationCursor->segmentAt($timeS));
            $deviationSource = $this->scalarSourceFromSegment($compassDeviationKnots, $compassDeviationCursor->segmentAt($timeS));
            $headingReference = $headingDeg !== null ? 'TRUE' : 'UNKNOWN';
            if ($headingDeg === null && $trackDeg !== null) {
                $headingDeg = $trackDeg;
                $headingSource = 'track_fallback';
                $headingReference = 'TRUE';
            }
            $rawPitch = ReplaySeriesCursor::lerpScalar($pitchKnots, $pitchSeg);
            $rawRoll = ReplaySeriesCursor::lerpScalar($rollKnots, $rollSeg);
            $pitchSource = $this->scalarSourceFromSegment($pitchKnots, $pitchSeg);
            $rollSource = $this->scalarSourceFromSegment($rollKnots, $rollSeg);
            $rawAttitudeSource = $this->combineAttitudeSources($pitchSource, $rollSource);
            $rawAttitudeQuality = $this->qualityFromGap($attitudeGap, self::ATTITUDE_GOOD_MAX_GAP_S, self::ATTITUDE_DEGRADED_MAX_GAP_S);
            $headingQuality = $rawAttitudeQuality;
            $positionQuality = $this->qualityFromGap($positionGap, self::POSITION_GOOD_MAX_GAP_S, self::POSITION_DEGRADED_MAX_GAP_S);
            $trackQuality = $this->qualityFromGap($trackCursor->nearestGap($timeS), self::POSITION_GOOD_MAX_GAP_S, self::POSITION_DEGRADED_MAX_GAP_S);
            $speedQuality = $this->qualityFromGap($speedCursor->nearestGap($timeS), self::POSITION_GOOD_MAX_GAP_S, self::POSITION_DEGRADED_MAX_GAP_S);
            if ($headingSource === 'track_fallback') {
                $headingQuality = $this->worseQuality($headingQuality, 'DEGRADED');
                $this->diagnostics['count_heading_track_fallback_samples'] = (int)($this->diagnostics['count_heading_track_fallback_samples'] ?? 0) + 1;
            }
            if ($variationSource === self::FALLBACK_MAGNETIC_VARIATION_SOURCE) {
                $headingQuality = $this->worseQuality($headingQuality, 'DEGRADED');
            }

            $samples[] = array(
                'sample_index' => $index,
                't' => round($timeS, 3),
                'lat' => $geo !== null ? round($geo['lat'], 7) : null,
                'lon' => $geo !== null ? round($geo['lon'], 7) : null,
                'altitude_ft' => $altitudeFt !== null ? round($altitudeFt, 1) : null,
                'heading_deg' => $headingDeg,
                'heading_deg_true' => $headingDeg,
                'heading_deg_magnetic' => $headingMagnetic,
                'track_deg_true' => $trackDeg,
                'wind_direction_deg_true' => null,
                'magnetic_variation_deg' => $magneticVariation,
                'magnetic_variation_source' => $variationSource,
                'compass_deviation_deg' => $compassDeviation,
                'compass_deviation_source' => $deviationSource,
                'heading_reference' => $headingReference,
                'track_reference' => $trackDeg !== null ? 'TRUE' : 'UNKNOWN',
                'heading_source' => $headingSource,
                'heading_owner' => $headingSource === 'track_fallback' ? 'track_fallback' : $headingSource,
                'heading_quality' => $headingQuality,
                'track_source' => $trackSource,
                'track_quality' => $trackQuality,
                'speed_source' => $speedSource,
                'speed_quality' => $speedQuality,
                'position_source' => $positionSource,
                'position_quality_reason' => $this->gapQualityReason('position', $positionQuality),
                'track_quality_reason' => $this->gapQualityReason('track', $trackQuality),
                'speed_quality_reason' => $this->gapQualityReason('speed', $speedQuality),
                'heading_quality_reason' => $this->gapQualityReason('heading', $headingQuality),
                'attitude_quality_reason' => $this->gapQualityReason('attitude', $rawAttitudeQuality),
                'crab_angle_deg' => ($headingDeg !== null && $trackDeg !== null) ? round($this->crabAngle($headingDeg, $trackDeg), 2) : null,
                'pitch_deg' => $rawPitch,
                'roll_deg' => $rawRoll,
                'raw_pitch_deg' => $rawPitch,
                'raw_roll_deg' => $rawRoll,
                'raw_attitude_source' => $rawAttitudeSource,
                'raw_attitude_quality' => $rawAttitudeQuality,
                'ground_speed_kt' => ReplaySeriesCursor::lerpScalar($speedKnots, $speedSeg),
                'vertical_speed_fpm' => $verticalSpeedFpm !== null ? round($verticalSpeedFpm, 1) : null,
                'phase' => $phase,
                'position_quality' => $positionQuality,
                'altitude_quality' => $this->qualityFromGap($altitudeCursor->nearestGap($timeS), self::POSITION_GOOD_MAX_GAP_S, self::POSITION_DEGRADED_MAX_GAP_S),
                'attitude_quality' => $headingQuality,
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
     * @return list<array{t:float,pitch_deg:float|null,roll_deg:float|null,heading_deg:float|null,heading_deg_magnetic:float|null,magnetic_variation_deg:float|null,magnetic_variation_source:string,compass_deviation_deg:float|null,compass_deviation_source:string,heading_reference:string}>
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
            $headingMagnetic = isset($row['correctedMagneticHeading']) && is_numeric($row['correctedMagneticHeading'])
                ? (float)$row['correctedMagneticHeading']
                : (isset($row['magneticHeading']) && is_numeric($row['magneticHeading']) ? (float)$row['magneticHeading'] : null);
            $trueHeading = isset($row['trueHeading']) && is_numeric($row['trueHeading']) ? (float)$row['trueHeading'] : null;
            $variation = isset($row['magneticVariation']) && is_numeric($row['magneticVariation']) ? (float)$row['magneticVariation'] : null;
            $deviation = isset($row['compassDeviation']) && is_numeric($row['compassDeviation']) ? (float)$row['compassDeviation'] : 0.0;
            $variationSource = $variation !== null ? 'ahrs_magnetic_variation' : self::FALLBACK_MAGNETIC_VARIATION_SOURCE;
            $variation ??= self::FALLBACK_MAGNETIC_VARIATION_DEG;
            $headingTrue = $trueHeading !== null
                ? self::normalizeDegrees($trueHeading)
                : ($headingMagnetic !== null ? $this->magneticToTrue($headingMagnetic, $variation, $deviation) : null);
            $points[] = array(
                't' => $t,
                'pitch_deg' => $pitch,
                'roll_deg' => $roll,
                'heading_deg' => $headingTrue,
                'heading_deg_magnetic' => $headingMagnetic !== null ? self::normalizeDegrees($headingMagnetic) : null,
                'magnetic_variation_deg' => $variation,
                'magnetic_variation_source' => $variationSource,
                'compass_deviation_deg' => $deviation,
                'compass_deviation_source' => isset($row['compassDeviation']) && is_numeric($row['compassDeviation']) ? 'ahrs' : 'none',
                'heading_reference' => $headingTrue !== null ? 'TRUE' : 'UNKNOWN',
            );
        }
        usort($points, fn(array $a, array $b): int => $a['t'] <=> $b['t']);
        return $points;
    }

    /**
     * @param list<array{seconds: float, row: array<string,string>}> $g3xSamples
     * @return list<array{t:float,lat:float,lon:float,alt_m:float,speed_kt:float,heading_deg:float|null,heading_deg_magnetic:float|null,track_deg_true:float|null,magnetic_variation_deg:float|null,magnetic_variation_source:string,compass_deviation_deg:float|null,compass_deviation_source:string,pitch_deg:float|null,roll_deg:float|null,alt_ft:float|null,vs_fpm:float|null}>
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
            $headingMagnetic = G3XFlightStreamParser::numericValue($row, 'Magnetic Heading (deg)', 'HDG');
            $variation = G3XFlightStreamParser::numericValue($row, 'Magnetic Variation (deg)', 'MagVar');
            $variationSource = $variation !== null ? 'g3x_magvar' : self::FALLBACK_MAGNETIC_VARIATION_SOURCE;
            $variation ??= self::FALLBACK_MAGNETIC_VARIATION_DEG;
            $trackTrue = G3XFlightStreamParser::numericValue($row, 'GPS Ground Track (deg)', 'TRK', 'Track');
            $points[] = array(
                't' => $t,
                'lat' => (float)$lat,
                'lon' => (float)$lon,
                'alt_m' => $altM,
                'speed_kt' => (float)(G3XFlightStreamParser::numericValue($row, 'GPS Ground Speed (kt)', 'GndSpd') ?? 0.0),
                'heading_deg' => $headingMagnetic !== null ? $this->magneticToTrue((float)$headingMagnetic, (float)$variation, 0.0) : null,
                'heading_deg_magnetic' => $headingMagnetic !== null ? self::normalizeDegrees((float)$headingMagnetic) : null,
                'track_deg_true' => $trackTrue !== null ? self::normalizeDegrees((float)$trackTrue) : null,
                'magnetic_variation_deg' => (float)$variation,
                'magnetic_variation_source' => $variationSource,
                'compass_deviation_deg' => 0.0,
                'compass_deviation_source' => 'none',
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
                'hacc' => $point['hacc'],
                'vacc' => $point['vacc'],
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
                $merged[count($merged) - 1] = $this->preferredPositionKnot($last, $knot);
                continue;
            }
            $merged[] = $knot;
        }
        return $merged;
    }

    /**
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     * @return array<string,mixed>
     */
    private function preferredPositionKnot(array $a, array $b): array
    {
        $aSource = (string)($a['source'] ?? '');
        $bSource = (string)($b['source'] ?? '');
        $aSpeed = isset($a['speed_kt']) && is_numeric($a['speed_kt']) ? (float)$a['speed_kt'] : 0.0;
        $bSpeed = isset($b['speed_kt']) && is_numeric($b['speed_kt']) ? (float)$b['speed_kt'] : 0.0;

        if ($aSource === 'gps' && $bSource === 'g3x' && $this->isGoodMovingGpsKnot($a) && $bSpeed < self::STATIONARY_SPEED_KT) {
            return $a;
        }
        if ($aSource === 'g3x' && $bSource === 'gps' && $this->isGoodMovingGpsKnot($b) && $aSpeed < self::STATIONARY_SPEED_KT) {
            return $b;
        }

        return $bSource === 'g3x' ? $b : $a;
    }

    /**
     * @param array<string,mixed> $knot
     */
    private function isGoodMovingGpsKnot(array $knot): bool
    {
        if ((string)($knot['source'] ?? '') !== 'gps') {
            return false;
        }
        $speed = isset($knot['speed_kt']) && is_numeric($knot['speed_kt']) ? (float)$knot['speed_kt'] : 0.0;
        $hacc = isset($knot['hacc']) && is_numeric($knot['hacc']) ? (float)$knot['hacc'] : null;

        return $speed >= self::GPS_HEADING_MIN_SPEED_KT && ($hacc === null || $hacc <= 30.0);
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
     * @return list<array{t:float,e:float,n:float,u:float,source?:string}>
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
                'source' => (string)($knot['source'] ?? 'unknown'),
            );
        }
        return $enu;
    }

    /**
     * @param list<array{t:float,lat:float,lon:float,alt_m:float,speed_kt:float,course:float,hacc:float|null,vacc:float|null,source:string}> $gpsPoints
     * @param list<array{t:float,pitch_deg:float|null,roll_deg:float|null,heading_deg:float|null,heading_deg_magnetic:float|null,magnetic_variation_deg:float|null,magnetic_variation_source:string,compass_deviation_deg:float|null,compass_deviation_source:string,heading_reference:string}> $ahrsPoints
     * @param list<array{t:float,lat:float,lon:float,alt_m:float,speed_kt:float,heading_deg:float|null,heading_deg_magnetic:float|null,track_deg_true:float|null,magnetic_variation_deg:float|null,magnetic_variation_source:string,compass_deviation_deg:float|null,compass_deviation_source:string,pitch_deg:float|null,roll_deg:float|null,alt_ft:float|null,vs_fpm:float|null}> $g3xPoints
     * @return list<array{t:float,v:float,source?:string}>
     */
    private function buildHeadingKnots(array $gpsPoints, array $ahrsPoints, array $g3xPoints): array
    {
        $knots = array();
        $sourceCounts = array('g3x' => 0, 'ahrs' => 0, 'track_fallback' => 0, 'held' => 0);
        $converted = 0;
        foreach ($g3xPoints as $point) {
            if ($point['heading_deg'] !== null) {
                $knots[] = array('t' => $point['t'], 'v' => $point['heading_deg'], 'priority' => 3, 'source' => 'g3x');
                $sourceCounts['g3x']++;
                if ($point['heading_deg_magnetic'] !== null) {
                    $converted++;
                }
            }
        }
        foreach ($ahrsPoints as $point) {
            if ($point['heading_deg'] !== null) {
                $knots[] = array('t' => $point['t'], 'v' => $point['heading_deg'], 'priority' => 2, 'source' => 'ahrs');
                $sourceCounts['ahrs']++;
                if ($point['heading_deg_magnetic'] !== null) {
                    $converted++;
                }
            }
        }
        if ($knots === array()) {
            foreach ($gpsPoints as $point) {
                if ($point['speed_kt'] >= self::GPS_HEADING_MIN_SPEED_KT && $point['course'] >= 0.0) {
                    $knots[] = array('t' => $point['t'], 'v' => self::normalizeDegrees($point['course']), 'priority' => 1, 'source' => 'track_fallback');
                    $sourceCounts['track_fallback']++;
                }
            }
        }
        $this->diagnostics['heading_source_counts'] = $sourceCounts;
        $this->diagnostics['count_heading_magnetic_to_true_converted'] = $converted;

        usort($knots, fn(array $a, array $b): int => $a['t'] <=> $b['t'] ?: $b['priority'] <=> $a['priority']);

        return $this->mergeScalarKnots($knots);
    }

    /**
     * @param list<array{t:float,lat:float,lon:float,alt_m:float,speed_kt:float,heading_deg:float|null,heading_deg_magnetic:float|null,track_deg_true:float|null,magnetic_variation_deg:float|null,magnetic_variation_source:string,compass_deviation_deg:float|null,compass_deviation_source:string,pitch_deg:float|null,roll_deg:float|null,alt_ft:float|null,vs_fpm:float|null}> $g3xPoints
     * @return list<array{t:float,v:float,source?:string}>
     */
    private function buildG3xHeadingKnots(array $g3xPoints): array
    {
        $knots = array();
        foreach ($g3xPoints as $point) {
            if ($point['heading_deg'] !== null) {
                $knots[] = array('t' => $point['t'], 'v' => $point['heading_deg'], 'priority' => 1, 'source' => 'g3x');
            }
        }
        usort($knots, fn(array $a, array $b): int => $a['t'] <=> $b['t']);
        return $this->mergeScalarKnots($knots);
    }

    /**
     * @param list<array{t:float,pitch_deg:float|null,roll_deg:float|null,heading_deg:float|null,heading_deg_magnetic:float|null,magnetic_variation_deg:float|null,magnetic_variation_source:string,compass_deviation_deg:float|null,compass_deviation_source:string,heading_reference:string}> $ahrsPoints
     * @return list<array{t:float,v:float,source?:string}>
     */
    private function buildAhrsHeadingKnots(array $ahrsPoints): array
    {
        $knots = array();
        foreach ($ahrsPoints as $point) {
            if ($point['heading_deg'] !== null) {
                $knots[] = array('t' => $point['t'], 'v' => $point['heading_deg'], 'priority' => 1, 'source' => 'ahrs');
            }
        }
        usort($knots, fn(array $a, array $b): int => $a['t'] <=> $b['t']);
        return $this->mergeScalarKnots($knots);
    }

    /**
     * @param list<array{t:float,lat:float,lon:float,alt_m:float,speed_kt:float,course:float,hacc:float|null,vacc:float|null,source:string}> $gpsPoints
     * @return list<array{t:float,v:float,source?:string}>
     */
    private function buildTrackFallbackHeadingKnots(array $gpsPoints): array
    {
        $knots = array();
        foreach ($gpsPoints as $point) {
            if ($point['speed_kt'] >= self::GPS_HEADING_MIN_SPEED_KT && $point['course'] >= 0.0) {
                $knots[] = array('t' => $point['t'], 'v' => self::normalizeDegrees($point['course']), 'priority' => 1, 'source' => 'track_fallback');
            }
        }
        usort($knots, fn(array $a, array $b): int => $a['t'] <=> $b['t']);
        return $this->mergeScalarKnots($knots);
    }

    /**
     * @param list<array{t:float,pitch_deg:float|null,roll_deg:float|null,heading_deg:float|null,heading_deg_magnetic:float|null,magnetic_variation_deg:float|null,magnetic_variation_source:string,compass_deviation_deg:float|null,compass_deviation_source:string,heading_reference:string}> $ahrsPoints
     * @param list<array{t:float,lat:float,lon:float,alt_m:float,speed_kt:float,heading_deg:float|null,heading_deg_magnetic:float|null,track_deg_true:float|null,magnetic_variation_deg:float|null,magnetic_variation_source:string,compass_deviation_deg:float|null,compass_deviation_source:string,pitch_deg:float|null,roll_deg:float|null,alt_ft:float|null,vs_fpm:float|null}> $g3xPoints
     * @return list<array{t:float,v:float,source?:string}>
     */
    private function buildHeadingMagneticKnots(array $ahrsPoints, array $g3xPoints): array
    {
        $knots = array();
        foreach ($g3xPoints as $point) {
            if ($point['heading_deg_magnetic'] !== null) {
                $knots[] = array('t' => $point['t'], 'v' => $point['heading_deg_magnetic'], 'priority' => 2, 'source' => 'g3x');
            }
        }
        foreach ($ahrsPoints as $point) {
            if ($point['heading_deg_magnetic'] !== null) {
                $knots[] = array('t' => $point['t'], 'v' => $point['heading_deg_magnetic'], 'priority' => 1, 'source' => 'ahrs');
            }
        }
        usort($knots, fn(array $a, array $b): int => $a['t'] <=> $b['t'] ?: $b['priority'] <=> $a['priority']);
        return $this->mergeScalarKnots($knots);
    }

    /**
     * @param list<array{t:float,lat:float,lon:float,alt_m:float,speed_kt:float,course:float,hacc:float|null,vacc:float|null,source:string}> $gpsPoints
     * @param list<array{t:float,lat:float,lon:float,alt_m:float,speed_kt:float,heading_deg:float|null,heading_deg_magnetic:float|null,track_deg_true:float|null,magnetic_variation_deg:float|null,magnetic_variation_source:string,compass_deviation_deg:float|null,compass_deviation_source:string,pitch_deg:float|null,roll_deg:float|null,alt_ft:float|null,vs_fpm:float|null}> $g3xPoints
     * @return list<array{t:float,v:float,source?:string}>
     */
    private function buildTrackKnots(array $gpsPoints, array $g3xPoints): array
    {
        $knots = array();
        $sourceCounts = array('g3x_track' => 0, 'gps_course' => 0);
        foreach ($g3xPoints as $point) {
            if ($point['track_deg_true'] !== null && $point['speed_kt'] >= self::GPS_HEADING_MIN_SPEED_KT) {
                $knots[] = array('t' => $point['t'], 'v' => $point['track_deg_true'], 'priority' => 2, 'source' => 'g3x_track');
                $sourceCounts['g3x_track']++;
            }
        }
        foreach ($gpsPoints as $point) {
            if ($point['speed_kt'] >= self::GPS_HEADING_MIN_SPEED_KT && $point['course'] >= 0.0) {
                $knots[] = array('t' => $point['t'], 'v' => self::normalizeDegrees($point['course']), 'priority' => 1, 'source' => 'gps_course');
                $sourceCounts['gps_course']++;
            }
        }
        $this->diagnostics['track_source_counts'] = $sourceCounts;
        usort($knots, fn(array $a, array $b): int => $a['t'] <=> $b['t'] ?: $b['priority'] <=> $a['priority']);
        return $this->mergeScalarKnots($knots);
    }

    /**
     * @return list<array{t:float,v:float,source?:string}>
     */
    private function buildMagneticVariationKnots(array $ahrsPoints, array $g3xPoints): array
    {
        $sourceCounts = array('g3x_magvar' => 0, 'ahrs_magnetic_variation' => 0, self::FALLBACK_MAGNETIC_VARIATION_SOURCE => 0);
        $g3xValues = array();
        foreach ($g3xPoints as $point) {
            if ($point['magnetic_variation_deg'] !== null && (string)$point['magnetic_variation_source'] === 'g3x_magvar') {
                $g3xValues[] = (float)$point['magnetic_variation_deg'];
            }
        }
        if ($g3xValues !== array()) {
            $sourceCounts['g3x_magvar'] = count($g3xValues);
            $this->diagnostics['magnetic_variation_source_counts'] = $sourceCounts;
            return array(array('t' => 0.0, 'v' => $this->median($g3xValues), 'source' => 'g3x_magvar'));
        }

        $ahrsValues = array();
        foreach ($ahrsPoints as $point) {
            if ($point['magnetic_variation_deg'] !== null && (string)$point['magnetic_variation_source'] === 'ahrs_magnetic_variation') {
                $ahrsValues[] = (float)$point['magnetic_variation_deg'];
            }
        }
        if ($ahrsValues !== array()) {
            $sourceCounts['ahrs_magnetic_variation'] = count($ahrsValues);
            $this->diagnostics['magnetic_variation_source_counts'] = $sourceCounts;
            return array(array('t' => 0.0, 'v' => $this->median($ahrsValues), 'source' => 'ahrs_magnetic_variation'));
        }

        $sourceCounts[self::FALLBACK_MAGNETIC_VARIATION_SOURCE] = 1;
        $this->diagnostics['magnetic_variation_source_counts'] = $sourceCounts;
        return array(array('t' => 0.0, 'v' => self::FALLBACK_MAGNETIC_VARIATION_DEG, 'source' => self::FALLBACK_MAGNETIC_VARIATION_SOURCE));
    }

    /**
     * @return list<array{t:float,v:float,source?:string}>
     */
    private function buildCompassDeviationKnots(array $ahrsPoints, array $g3xPoints): array
    {
        $ahrsValues = array();
        foreach ($ahrsPoints as $point) {
            if (($point['compass_deviation_source'] ?? 'none') !== 'none' && isset($point['compass_deviation_deg']) && is_numeric($point['compass_deviation_deg'])) {
                $ahrsValues[] = (float)$point['compass_deviation_deg'];
            }
        }
        if ($ahrsValues !== array()) {
            return array(array('t' => 0.0, 'v' => $this->median($ahrsValues), 'source' => 'ahrs'));
        }

        return array(array('t' => 0.0, 'v' => 0.0, 'source' => 'none'));
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
                $knots[] = array('t' => $point['t'], 'v' => (float)$value, 'priority' => 2, 'source' => 'g3x');
            }
        }
        foreach ($ahrsPoints as $point) {
            $value = $point[$ahrsKey] ?? null;
            if ($value !== null) {
                $knots[] = array('t' => $point['t'], 'v' => (float)$value, 'priority' => 1, 'source' => 'ahrs');
            }
        }
        usort($knots, fn(array $a, array $b): int => $a['t'] <=> $b['t'] ?: $b['priority'] <=> $a['priority']);
        return $this->mergeScalarKnots($knots);
    }

    /**
     * @param list<array{t:float,v:float,source?:string}> $knots
     * @param array{before:int,after:int,ratio:float,edge:string}|null $segment
     */
    private function scalarSourceFromSegment(array $knots, ?array $segment): string
    {
        if ($segment === null || $knots === array()) {
            return 'unavailable';
        }
        if ($segment['edge'] === 'before') {
            return (string)($knots[0]['source'] ?? 'unknown');
        }
        if ($segment['edge'] === 'after') {
            return (string)($knots[count($knots) - 1]['source'] ?? 'unknown');
        }

        $before = $knots[$segment['before']];
        $after = $knots[$segment['after']];
        $beforeSource = (string)($before['source'] ?? 'unknown');
        $afterSource = (string)($after['source'] ?? 'unknown');
        if ($beforeSource === $afterSource) {
            return $beforeSource;
        }

        return $beforeSource . '_to_' . $afterSource;
    }

    /**
     * @param list<array{t:float,source?:string}> $knots
     * @param array{before:int,after:int,ratio:float,edge:string}|null $segment
     */
    private function seriesSourceFromSegment(array $knots, ?array $segment): string
    {
        return $this->scalarSourceFromSegment($knots, $segment);
    }

    private function gapQualityReason(string $field, string $quality): string
    {
        return match (strtoupper($quality)) {
            'GOOD' => $field . '_reliable_source_or_clean_interpolation',
            'DEGRADED' => $field . '_interpolated_or_held_but_plausible',
            'LOW' => $field . '_source_gap_or_unreliable',
            default => $field . '_unknown_quality',
        };
    }

    private function combineAttitudeSources(string $pitchSource, string $rollSource): string
    {
        if ($pitchSource === $rollSource) {
            return $pitchSource;
        }
        if ($pitchSource === 'unavailable') {
            return $rollSource;
        }
        if ($rollSource === 'unavailable') {
            return $pitchSource;
        }

        return 'pitch_' . $pitchSource . '_roll_' . $rollSource;
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
            $knots[] = array('t' => $point['t'], 'v' => $point['speed_kt'], 'priority' => 2, 'source' => 'g3x');
        }
        foreach ($gpsPoints as $point) {
            $knots[] = array('t' => $point['t'], 'v' => $point['speed_kt'], 'priority' => 1, 'source' => 'gps');
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
        $previousVs = 0.0;
        $groundHoldAlt = null;
        $countGroundAltitudeStabilized = 0;
        $maxGroundVs = 0.0;
        foreach ($samples as $i => &$sample) {
            $speed = isset($sample['ground_speed_kt']) && is_numeric($sample['ground_speed_kt']) ? (float)$sample['ground_speed_kt'] : 0.0;
            $phase = (string)($sample['phase'] ?? '');
            $rawAlt = isset($sample['altitude_ft']) && is_numeric($sample['altitude_ft']) ? (float)$sample['altitude_ft'] : (float)$baselineFt;
            $ground = $this->isGroundPhase($phase) || $speed < self::GROUND_SPEED_MAX_KT;
            $altitudeQuality = ($baseline['source'] !== 'gps_median_first_60s' && $baseline['source'] !== 'unavailable') ? 'GOOD' : 'DEGRADED';

            if ($ground) {
                if ($groundHoldAlt === null) {
                    if ($previousAlt !== null) {
                        $groundHoldAlt = $previousAlt;
                    } else {
                        $groundHoldAlt = $segmentAltitudeByIndex[$i] ?? (float)$baselineFt;
                    }
                    if ($previousAlt === null && abs($groundHoldAlt - (float)$baselineFt) > 35.0) {
                        $groundHoldAlt = (float)$baselineFt;
                    }
                }
                $alt = $groundHoldAlt;
                $vs = 0.0;
                $altitudeQuality = $this->worseQuality($altitudeQuality, 'DEGRADED');
                if (abs($rawAlt - $alt) > 2.0) {
                    $countGroundAltitudeStabilized++;
                }
            } else {
                $groundHoldAlt = null;
                if ($previousAlt !== null && $previousAlt <= (float)$baselineFt + 50.0 && $rawAlt < $previousAlt) {
                    $rawAlt = $previousAlt;
                    $altitudeQuality = $this->worseQuality($altitudeQuality, 'DEGRADED');
                }
                $targetAlt = $previousAlt !== null ? $previousAlt + (($rawAlt - $previousAlt) * 0.35) : $rawAlt;
                $desiredVs = $previousAlt !== null && self::FIXED_TIMESTEP_S > 0.0
                    ? (($targetAlt - $previousAlt) / self::FIXED_TIMESTEP_S) * 60.0
                    : 0.0;
                $maxVs = 2500.0;
                $maxVsDelta = 200.0;
                $vs = $previousAlt !== null
                    ? max($previousVs - $maxVsDelta, min($previousVs + $maxVsDelta, $desiredVs))
                    : $desiredVs;
                if (abs($vs) > $maxVs) {
                    $this->diagnostics['count_altitude_spikes_rejected']++;
                    $vs = max(-$maxVs, min($maxVs, $vs));
                    $altitudeQuality = 'DEGRADED';
                }
                if ($previousAlt !== null && abs($vs - $desiredVs) > 0.1) {
                    $this->diagnostics['count_altitude_spikes_rejected']++;
                    $altitudeQuality = $this->worseQuality($altitudeQuality, 'DEGRADED');
                }
                $alt = $previousAlt !== null ? $previousAlt + (($vs / 60.0) * self::FIXED_TIMESTEP_S) : $targetAlt;
            }

            if ($ground) {
                $vs = 0.0;
            }
            if ($ground) {
                $maxGroundVs = max($maxGroundVs, abs((float)$vs));
            }

            $sample['altitude_ft'] = round($alt, 1);
            $sample['vertical_speed_fpm'] = round($vs, 1);
            $sample['altitude_source'] = (string)($baseline['source'] ?? 'unknown');
            $sample['altitude_quality'] = $altitudeQuality;
            $sample['altitude_quality_reason'] = $altitudeQuality === 'GOOD'
                ? 'altitude_reliable_source_or_clean_interpolation'
                : 'altitude_stabilized_or_smoothed_plausible';
            $previousAlt = $alt;
            $previousVs = (float)$vs;
        }
        unset($sample);

        $this->diagnostics['max_ground_vertical_speed_fpm'] = round($maxGroundVs, 1);
        $this->diagnostics['count_ground_altitude_stabilized'] = $countGroundAltitudeStabilized;
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
            $raw = isset($sample['heading_deg_true']) && is_numeric($sample['heading_deg_true'])
                ? self::normalizeDegrees((float)$sample['heading_deg_true'])
                : (isset($sample['heading_deg']) && is_numeric($sample['heading_deg']) ? self::normalizeDegrees((float)$sample['heading_deg']) : null);
            $speed = isset($sample['ground_speed_kt']) && is_numeric($sample['ground_speed_kt']) ? (float)$sample['ground_speed_kt'] : 0.0;
            $phase = (string)($sample['phase'] ?? '');
            $isStationary = $speed < self::STATIONARY_SPEED_KT;
            $isGround = $this->isGroundPhase($phase) || $speed < self::GROUND_SPEED_MAX_KT;

            if ($previous === null) {
                $heading = $raw ?? 0.0;
            } elseif ($raw === null) {
                $heading = $previous;
                $sample['heading_source'] = 'held';
                $sample['heading_reference'] = 'TRUE';
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

            $sample['heading_deg_true'] = round(self::normalizeDegrees($heading), 2);
            $sample['heading_deg'] = $sample['heading_deg_true'];
            $stateMagnetic = $this->trueToMagnetic(
                (float)$sample['heading_deg_true'],
                isset($sample['magnetic_variation_deg']) && is_numeric($sample['magnetic_variation_deg']) ? (float)$sample['magnetic_variation_deg'] : null,
                isset($sample['compass_deviation_deg']) && is_numeric($sample['compass_deviation_deg']) ? (float)$sample['compass_deviation_deg'] : 0.0
            );
            $sample['heading_deg_magnetic'] = $stateMagnetic !== null ? round($stateMagnetic, 2) : null;
            $sample['crab_angle_deg'] = isset($sample['track_deg_true']) && is_numeric($sample['track_deg_true'])
                ? round($this->crabAngle((float)$sample['heading_deg_true'], (float)$sample['track_deg_true']), 2)
                : null;
            $previous = (float)$sample['heading_deg_true'];
        }
        unset($sample);

        $this->diagnostics['max_heading_delta_stationary_deg'] = round($maxStationaryDelta, 2);
        $this->diagnostics['max_heading_delta_ground_deg'] = round($maxGroundDelta, 2);
        return $samples;
    }

    /**
     * Heading ownership is intentionally stateful. G3X owns heading while valid;
     * AHRS and track fallback only take ownership after sustained loss of better sources.
     *
     * @param list<array<string,mixed>> $samples
     * @param list<array{t:float,v:float,source?:string}> $g3xHeadingKnots
     * @param list<array{t:float,v:float,source?:string}> $ahrsHeadingKnots
     * @param list<array{t:float,v:float,source?:string}> $trackFallbackHeadingKnots
     * @return list<array<string,mixed>>
     */
    private function applyHeadingOwnership(array $samples, array $g3xHeadingKnots, array $ahrsHeadingKnots, array $trackFallbackHeadingKnots): array
    {
        if ($samples === array()) {
            return $samples;
        }

        $g3xCursor = new ReplaySeriesCursor($g3xHeadingKnots);
        $ahrsCursor = new ReplaySeriesCursor($ahrsHeadingKnots);
        $trackCursor = new ReplaySeriesCursor($trackFallbackHeadingKnots);

        $owner = 'unknown';
        $lastHeading = null;
        $g3xLossStart = null;
        $g3xStableStart = null;
        $blendStart = null;
        $blendFrom = null;
        $ahrsBlendStart = null;
        $ahrsBlendFrom = null;
        $holdingOwner = null;
        $ownerChanges = array();
        $ownerSwitchReasons = array();
        $ownerHoldEvents = 0;
        $ownerBlendEvents = 0;
        $maxStationaryDelta = 0.0;
        $maxGroundDelta = 0.0;

        foreach ($samples as $idx => &$sample) {
            $timeS = isset($sample['t']) && is_numeric($sample['t']) ? (float)$sample['t'] : 0.0;
            $g3xHeading = $this->candidateHeading($g3xHeadingKnots, $g3xCursor, $timeS);
            $ahrsHeading = $this->candidateHeading($ahrsHeadingKnots, $ahrsCursor, $timeS);
            $trackHeading = $this->candidateHeading($trackFallbackHeadingKnots, $trackCursor, $timeS);
            $g3xValid = $g3xHeading !== null;
            $ahrsValid = $ahrsHeading !== null;
            $trackValid = $trackHeading !== null;
            $previousHeading = $lastHeading;
            $headingQuality = (string)($sample['attitude_quality'] ?? 'GOOD');
            $source = 'held';

            if ($owner === 'unknown') {
                if ($g3xValid) {
                    $owner = 'g3x';
                    $heading = $g3xHeading;
                    $source = 'g3x';
                    $ownerChanges[] = array('t' => round($timeS, 3), 'owner' => 'g3x', 'reason' => 'initial_g3x_available');
                } elseif ($ahrsValid) {
                    $owner = 'ahrs';
                    $heading = $ahrsHeading;
                    $source = 'ahrs';
                    $headingQuality = $this->worseQuality($headingQuality, 'DEGRADED');
                    $ownerChanges[] = array('t' => round($timeS, 3), 'owner' => 'ahrs', 'reason' => 'initial_g3x_unavailable');
                } elseif ($trackValid) {
                    $owner = 'track_fallback';
                    $heading = $trackHeading;
                    $source = 'track_fallback';
                    $headingQuality = $this->worseQuality($headingQuality, 'DEGRADED');
                    $ownerChanges[] = array('t' => round($timeS, 3), 'owner' => 'track_fallback', 'reason' => 'initial_no_heading_source');
                } else {
                    $heading = $previousHeading ?? 0.0;
                    $headingQuality = $this->worseQuality($headingQuality, 'LOW');
                }
            } elseif ($owner === 'g3x') {
                if ($g3xValid) {
                    $heading = $g3xHeading;
                    $source = 'g3x';
                    $g3xLossStart = null;
                    $holdingOwner = null;
                } else {
                    $g3xLossStart ??= $timeS;
                    $lossDuration = $timeS - $g3xLossStart;
                    if ($lossDuration >= self::HEADING_OWNER_SWITCH_AFTER_LOSS_S && $ahrsValid) {
                        $owner = 'ahrs';
                        $ahrsBlendStart = $timeS;
                        $ahrsBlendFrom = $previousHeading ?? $ahrsHeading;
                        $heading = $previousHeading ?? $ahrsHeading;
                        $source = 'g3x_to_ahrs';
                        $headingQuality = $this->worseQuality($headingQuality, 'DEGRADED');
                        $reason = 'g3x_unavailable_for_' . number_format($lossDuration, 1, '.', '') . 's';
                        $ownerChanges[] = array('t' => round($timeS, 3), 'owner' => 'ahrs', 'reason' => $reason);
                        $ownerSwitchReasons[] = $reason;
                        $ownerBlendEvents++;
                        $g3xStableStart = null;
                        $blendStart = null;
                        $blendFrom = null;
                        $holdingOwner = null;
                    } else {
                        $heading = $previousHeading ?? ($ahrsHeading ?? ($trackHeading ?? 0.0));
                        $source = 'held';
                        $headingQuality = $this->worseQuality($headingQuality, 'DEGRADED');
                        if ($holdingOwner !== 'g3x') {
                            $ownerHoldEvents++;
                            $holdingOwner = 'g3x';
                        }
                    }
                }
            } elseif ($owner === 'ahrs') {
                if ($g3xValid) {
                    $ahrsBlendStart = null;
                    $ahrsBlendFrom = null;
                    $g3xStableStart ??= $timeS;
                    $stableDuration = $timeS - $g3xStableStart;
                    if ($stableDuration >= self::HEADING_OWNER_RETURN_STABLE_S) {
                        if ($blendStart === null) {
                            $blendStart = $timeS;
                            $blendFrom = $previousHeading ?? ($ahrsHeading ?? $g3xHeading);
                            $ownerBlendEvents++;
                        }
                        $ratio = max(0.0, min(1.0, ($timeS - $blendStart) / self::HEADING_OWNER_RETURN_BLEND_S));
                        $heading = $this->smoothAngle((float)$blendFrom, $g3xHeading, $ratio);
                        $source = 'ahrs_to_g3x';
                        $headingQuality = $this->worseQuality($headingQuality, 'DEGRADED');
                        if ($ratio >= 1.0) {
                            $owner = 'g3x';
                            $heading = $g3xHeading;
                            $source = 'g3x';
                            $reason = 'g3x_returned_stable_and_blended';
                            $ownerChanges[] = array('t' => round($timeS, 3), 'owner' => 'g3x', 'reason' => $reason);
                            $ownerSwitchReasons[] = $reason;
                            $g3xLossStart = null;
                            $g3xStableStart = null;
                            $blendStart = null;
                            $blendFrom = null;
                        }
                    } else {
                        $heading = $ahrsValid ? $ahrsHeading : ($previousHeading ?? $g3xHeading);
                        $source = $ahrsValid ? 'ahrs' : 'held';
                        $headingQuality = $this->worseQuality($headingQuality, 'DEGRADED');
                    }
                } else {
                    $g3xStableStart = null;
                    $blendStart = null;
                    $blendFrom = null;
                    if ($ahrsValid) {
                        if ($ahrsBlendStart !== null) {
                            $ratio = max(0.0, min(1.0, ($timeS - $ahrsBlendStart) / self::HEADING_OWNER_RETURN_BLEND_S));
                            $heading = $this->smoothAngle((float)$ahrsBlendFrom, $ahrsHeading, $ratio);
                            $source = 'g3x_to_ahrs';
                            if ($ratio >= 1.0) {
                                $ahrsBlendStart = null;
                                $ahrsBlendFrom = null;
                                $source = 'ahrs';
                            }
                        } else {
                            $heading = $ahrsHeading;
                            $source = 'ahrs';
                        }
                        $headingQuality = $this->worseQuality($headingQuality, 'DEGRADED');
                    } elseif ($trackValid) {
                        $owner = 'track_fallback';
                        $heading = $trackHeading;
                        $source = 'track_fallback';
                        $headingQuality = $this->worseQuality($headingQuality, 'LOW');
                        $reason = 'ahrs_unavailable_after_g3x_loss';
                        $ownerChanges[] = array('t' => round($timeS, 3), 'owner' => 'track_fallback', 'reason' => $reason);
                        $ownerSwitchReasons[] = $reason;
                    } else {
                        $heading = $previousHeading ?? 0.0;
                        $source = 'held';
                        $headingQuality = $this->worseQuality($headingQuality, 'LOW');
                    }
                }
            } else {
                if ($g3xValid) {
                    $owner = 'g3x';
                    $heading = $g3xHeading;
                    $source = 'g3x';
                    $reason = 'g3x_available_after_track_fallback';
                    $ownerChanges[] = array('t' => round($timeS, 3), 'owner' => 'g3x', 'reason' => $reason);
                    $ownerSwitchReasons[] = $reason;
                } elseif ($ahrsValid) {
                    $owner = 'ahrs';
                    $heading = $ahrsHeading;
                    $source = 'ahrs';
                    $headingQuality = $this->worseQuality($headingQuality, 'DEGRADED');
                    $reason = 'ahrs_available_after_track_fallback';
                    $ownerChanges[] = array('t' => round($timeS, 3), 'owner' => 'ahrs', 'reason' => $reason);
                    $ownerSwitchReasons[] = $reason;
                } else {
                    $heading = $trackHeading ?? ($previousHeading ?? 0.0);
                    $source = $trackValid ? 'track_fallback' : 'held';
                    $headingQuality = $this->worseQuality($headingQuality, 'LOW');
                }
            }

            $heading = self::normalizeDegrees((float)$heading);
            $sample['heading_deg_true'] = round($heading, 2);
            $sample['heading_deg'] = $sample['heading_deg_true'];
            $sample['heading_owner'] = $owner;
            $sample['heading_source'] = $source;
            $sample['heading_reference'] = 'TRUE';
            $sample['heading_quality'] = $headingQuality;
            $sample['attitude_quality'] = $this->worseQuality((string)($sample['attitude_quality'] ?? 'GOOD'), $headingQuality);
            $stateMagnetic = $this->trueToMagnetic(
                (float)$sample['heading_deg_true'],
                isset($sample['magnetic_variation_deg']) && is_numeric($sample['magnetic_variation_deg']) ? (float)$sample['magnetic_variation_deg'] : null,
                isset($sample['compass_deviation_deg']) && is_numeric($sample['compass_deviation_deg']) ? (float)$sample['compass_deviation_deg'] : 0.0
            );
            $sample['heading_deg_magnetic'] = $stateMagnetic !== null ? round($stateMagnetic, 2) : null;
            $sample['crab_angle_deg'] = isset($sample['track_deg_true']) && is_numeric($sample['track_deg_true'])
                ? round($this->crabAngle((float)$sample['heading_deg_true'], (float)$sample['track_deg_true']), 2)
                : null;

            if ($previousHeading !== null) {
                $delta = abs(self::angleDelta($previousHeading, (float)$sample['heading_deg_true']));
                $speed = isset($sample['ground_speed_kt']) && is_numeric($sample['ground_speed_kt']) ? (float)$sample['ground_speed_kt'] : 0.0;
                $phase = (string)($sample['phase'] ?? '');
                $isStationary = $speed < self::STATIONARY_SPEED_KT;
                $isGround = $this->isGroundPhase($phase) || $speed < self::GROUND_SPEED_MAX_KT;
                if ($isStationary) {
                    $maxStationaryDelta = max($maxStationaryDelta, $delta);
                }
                if ($isGround) {
                    $maxGroundDelta = max($maxGroundDelta, $delta);
                }
            }

            $lastHeading = (float)$sample['heading_deg_true'];
            $samples[$idx] = $sample;
        }
        unset($sample);

        $this->diagnostics['heading_owner'] = $owner;
        $this->diagnostics['heading_owner_changes'] = $ownerChanges;
        $this->diagnostics['owner_switch_count'] = max(0, count($ownerChanges) - 1);
        $this->diagnostics['owner_switch_reason'] = $ownerSwitchReasons;
        $this->diagnostics['owner_hold_events'] = $ownerHoldEvents;
        $this->diagnostics['owner_blend_events'] = $ownerBlendEvents;
        $this->diagnostics['owner_blend_duration'] = self::HEADING_OWNER_RETURN_BLEND_S;
        $this->diagnostics['count_heading_owner_transitions'] = max(0, count($ownerChanges) - 1);
        $this->diagnostics['max_heading_delta_stationary_deg'] = round($maxStationaryDelta, 2);
        $this->diagnostics['max_heading_delta_ground_deg'] = round($maxGroundDelta, 2);

        return $samples;
    }

    /**
     * @param list<array{t:float,v:float,source?:string}> $knots
     */
    private function candidateHeading(array $knots, ReplaySeriesCursor $cursor, float $timeS): ?float
    {
        if ($knots === array() || $cursor->nearestGap($timeS) > self::HEADING_OWNER_VALID_GAP_S) {
            return null;
        }

        return ReplaySeriesCursor::lerpHeading($knots, $cursor->segmentAt($timeS));
    }

    private function smoothAngle(float $from, float $to, float $alpha): float
    {
        return self::normalizeDegrees($from + self::angleDelta($from, $to) * max(0.0, min(1.0, $alpha)));
    }

    private function magneticToTrue(float $magneticDeg, float $magneticVariationDeg, float $compassDeviationDeg = 0.0): float
    {
        return self::normalizeDegrees($magneticDeg + $compassDeviationDeg + $magneticVariationDeg);
    }

    private function trueToMagnetic(float $trueDeg, ?float $magneticVariationDeg, ?float $compassDeviationDeg = 0.0): ?float
    {
        if ($magneticVariationDeg === null) {
            return null;
        }
        return self::normalizeDegrees($trueDeg - ($compassDeviationDeg ?? 0.0) - $magneticVariationDeg);
    }

    private function crabAngle(float $headingDegTrue, float $trackDegTrue): float
    {
        $angle = self::angleDelta($trackDegTrue, $headingDegTrue);
        return $angle >= 179.995 ? -180.0 : $angle;
    }

    /**
     * Final replay-payload validation pass. This works on the fixed 10 Hz samples, so
     * the quality flags describe what the frontend will actually render.
     *
     * @param list<array<string,mixed>> $samples
     * @return list<array<string,mixed>>
     */
    private function validateReplaySamples(array $samples): array
    {
        if (!$samples) {
            return $samples;
        }

        $maxGroundspeedDelta = 0.0;
        $maxTaxiImpliedSpeed = 0.0;
        $maxMismatch = 0.0;
        $countPositionOutliers = 0;
        $countTakeoffTransitionPositionCorrections = 0;
        $firstPositionDiscontinuityTime = null;
        $firstPositionDiscontinuitySource = null;
        $countSpeedOutliers = 0;
        $countHeadingOutliers = 0;
        $countAttitudeSpikesClamped = 0;
        $countOriginalEmptyPhase = 0;
        $maxGroundHeadingDelta = 0.0;
        $maxPitchDeltaState = 0.0;
        $maxRollDeltaState = 0.0;

        $lastSpeed = null;
        $lastHeading = null;
        $lastPitch = null;
        $lastRoll = null;

        for ($i = 0; $i < count($samples); $i++) {
            $sample = $samples[$i];
            $phase = trim((string)($sample['phase'] ?? ''));
            if ($phase === '') {
                $countOriginalEmptyPhase++;
                $phase = $this->fallbackPhaseForSample($sample);
                $sample['phase'] = $phase;
            }

            $speed = isset($sample['ground_speed_kt']) && is_numeric($sample['ground_speed_kt'])
                ? max(0.0, (float)$sample['ground_speed_kt'])
                : 0.0;
            $ground = $this->isGroundPhase($phase) || $speed < self::GROUND_SPEED_MAX_KT;
            $groundStep = $ground || ($lastSpeed !== null && min($lastSpeed, $speed) < self::GROUND_SPEED_MAX_KT);

            $rawDelta = 0.0;
            if ($lastSpeed !== null) {
                $rawDelta = $speed - $lastSpeed;
                $prevPhaseForSpeed = $i > 0 ? (string)($samples[$i - 1]['phase'] ?? '') : '';
                $takeoffRollSpeedStep = $this->isTakeoffRollSpeedStep($prevPhaseForSpeed, $phase, $lastSpeed, $speed);
                $maxSpeedDelta = $takeoffRollSpeedStep ? 1.0 : 0.3;
                if (($groundStep || $takeoffRollSpeedStep) && abs($rawDelta) > $maxSpeedDelta) {
                    $countSpeedOutliers++;
                    $speed = $lastSpeed + max(-$maxSpeedDelta, min($maxSpeedDelta, $rawDelta));
                    $sample['ground_speed_kt'] = round($speed, 2);
                    $sample['speed_quality'] = $this->worseQuality((string)($sample['speed_quality'] ?? 'GOOD'), 'DEGRADED');
                    $sample['speed_quality_reason'] = 'speed_delta_rate_limited_plausible';
                    $groundStep = $ground || min($lastSpeed, $speed) < self::GROUND_SPEED_MAX_KT;
                }
                if ($groundStep) {
                    $maxGroundspeedDelta = max($maxGroundspeedDelta, abs($speed - $lastSpeed));
                }
            }

            if ($i > 0) {
                $prev = $samples[$i - 1];
                $dt = max(0.001, (float)$sample['t'] - (float)$prev['t']);
                $impliedSpeed = $this->impliedSpeedKt($prev, $sample, $dt);
                $mismatch = abs($impliedSpeed - $speed);
                $prevPhase = (string)($prev['phase'] ?? '');
                $takeoffTransition = $this->isTakeoffTransition($prevPhase, $phase, $lastSpeed, $speed);
                $absurdPositionDiscontinuity = $impliedSpeed > self::POSITION_ABSURD_IMPLIED_SPEED_KT
                    || $mismatch > self::POSITION_ABSURD_MISMATCH_KT;
                if ($groundStep && ($impliedSpeed > 40.0 || $mismatch > 10.0)) {
                    $countPositionOutliers++;
                    $projected = $this->projectPositionFromSpeed($prev, $sample, $speed, $dt);
                    $corrected = false;
                    if ($projected !== null) {
                        $sample['lat'] = round($projected['lat'], 7);
                        $sample['lon'] = round($projected['lon'], 7);
                        $impliedSpeed = $this->impliedSpeedKt($prev, $sample, $dt);
                        $mismatch = abs($impliedSpeed - $speed);
                        $corrected = $impliedSpeed <= 40.0 && $mismatch <= 10.0;
                    }
                    if ($corrected) {
                        $sample['position_quality'] = 'DEGRADED';
                        $sample['position_quality_reason'] = 'position_projected_correction_plausible';
                        $this->diagnostics['count_position_corrections_degraded']++;
                    } else {
                        $sample['position_quality'] = 'LOW';
                        $sample['position_quality_reason'] = 'position_correction_unreliable_or_impossible';
                        $this->diagnostics['count_position_corrections_low']++;
                    }
                } elseif ($absurdPositionDiscontinuity) {
                    $countPositionOutliers++;
                    if ($takeoffTransition) {
                        $countTakeoffTransitionPositionCorrections++;
                    }
                    if ($firstPositionDiscontinuityTime === null) {
                        $firstPositionDiscontinuityTime = (float)$sample['t'];
                        $firstPositionDiscontinuitySource = $takeoffTransition
                            ? 'post_interpolation_takeoff_transition'
                            : 'post_interpolation_position_discontinuity';
                    }
                    $projected = $this->projectPositionFromSpeed($prev, $sample, $speed, $dt);
                    $corrected = false;
                    if ($projected !== null) {
                        $sample['lat'] = round($projected['lat'], 7);
                        $sample['lon'] = round($projected['lon'], 7);
                        $impliedSpeed = $this->impliedSpeedKt($prev, $sample, $dt);
                        $mismatch = abs($impliedSpeed - $speed);
                        $corrected = $impliedSpeed <= self::POSITION_ABSURD_IMPLIED_SPEED_KT
                            && $mismatch <= self::POSITION_ABSURD_MISMATCH_KT;
                    }
                    if ($corrected) {
                        $sample['position_quality'] = 'DEGRADED';
                        $sample['position_quality_reason'] = 'position_absurd_discontinuity_projected_plausible';
                        $this->diagnostics['count_position_corrections_degraded']++;
                    } else {
                        $sample['position_quality'] = 'LOW';
                        $sample['position_quality_reason'] = 'position_absurd_discontinuity_unreliable';
                        $this->diagnostics['count_position_corrections_low']++;
                    }
                } elseif (!$ground && $mismatch > 15.0) {
                    $sample['position_quality'] = $this->worseQuality((string)$sample['position_quality'], 'DEGRADED');
                    $sample['position_quality_reason'] = 'position_speed_mismatch_degraded';
                } elseif ($mismatch > 5.0) {
                    $sample['position_quality'] = $this->worseQuality((string)$sample['position_quality'], 'DEGRADED');
                    $sample['position_quality_reason'] = 'position_minor_speed_mismatch_degraded';
                }
                $maxMismatch = max($maxMismatch, $mismatch);
                if ($groundStep) {
                    $maxTaxiImpliedSpeed = max($maxTaxiImpliedSpeed, $impliedSpeed);
                }
            } else {
                $sample['position_quality'] = $this->worseQuality((string)$sample['position_quality'], 'DEGRADED');
                $sample['position_quality_reason'] = 'initial_position_sample_degraded';
            }

            $heading = isset($sample['heading_deg_true']) && is_numeric($sample['heading_deg_true'])
                ? self::normalizeDegrees((float)$sample['heading_deg_true'])
                : (isset($sample['heading_deg']) && is_numeric($sample['heading_deg'])
                    ? self::normalizeDegrees((float)$sample['heading_deg'])
                    : ($lastHeading ?? 0.0));
            if ($lastHeading !== null) {
                $headingDelta = self::angleDelta($lastHeading, $heading);
                if ($groundStep && abs($headingDelta) > 10.0) {
                    $countHeadingOutliers++;
                    $heading = self::normalizeDegrees($lastHeading + max(-10.0, min(10.0, $headingDelta)));
                    $sample['heading_quality'] = $this->worseQuality((string)($sample['heading_quality'] ?? 'GOOD'), 'DEGRADED');
                    $sample['heading_quality_reason'] = 'heading_ground_delta_rate_limited_plausible';
                    $sample['attitude_quality'] = $this->worseQuality((string)$sample['attitude_quality'], 'DEGRADED');
                }
                if ($groundStep) {
                    $maxGroundHeadingDelta = max($maxGroundHeadingDelta, abs(self::angleDelta($lastHeading, $heading)));
                }
            }
            $sample['heading_deg_true'] = round($heading, 2);
            $sample['heading_deg'] = $sample['heading_deg_true'];
            $stateMagnetic = $this->trueToMagnetic(
                (float)$sample['heading_deg_true'],
                isset($sample['magnetic_variation_deg']) && is_numeric($sample['magnetic_variation_deg']) ? (float)$sample['magnetic_variation_deg'] : null,
                isset($sample['compass_deviation_deg']) && is_numeric($sample['compass_deviation_deg']) ? (float)$sample['compass_deviation_deg'] : 0.0
            );
            $sample['heading_deg_magnetic'] = $stateMagnetic !== null ? round($stateMagnetic, 2) : null;
            $sample['crab_angle_deg'] = isset($sample['track_deg_true']) && is_numeric($sample['track_deg_true'])
                ? round($this->crabAngle((float)$sample['heading_deg_true'], (float)$sample['track_deg_true']), 2)
                : null;

            $rawPitch = isset($sample['raw_pitch_deg']) && is_numeric($sample['raw_pitch_deg'])
                ? (float)$sample['raw_pitch_deg']
                : (isset($sample['pitch_deg']) && is_numeric($sample['pitch_deg']) ? (float)$sample['pitch_deg'] : ($lastPitch ?? 0.0));
            $rawRoll = isset($sample['raw_roll_deg']) && is_numeric($sample['raw_roll_deg'])
                ? (float)$sample['raw_roll_deg']
                : (isset($sample['roll_deg']) && is_numeric($sample['roll_deg']) ? (float)$sample['roll_deg'] : ($lastRoll ?? 0.0));
            $sample['raw_pitch_deg'] = round($rawPitch, 2);
            $sample['raw_roll_deg'] = round($rawRoll, 2);
            $sample['raw_attitude_quality'] = (string)($sample['raw_attitude_quality'] ?? $sample['attitude_quality'] ?? 'unknown');

            $dt = 0.1;
            if ($i > 0 && isset($samples[$i - 1]['t'], $sample['t'])) {
                $dt = max(0.001, (float)$sample['t'] - (float)$samples[$i - 1]['t']);
            }
            $pitchLimit = ($groundStep ? self::GROUND_PITCH_RATE_LIMIT_DEG_S : self::AIRBORNE_PITCH_RATE_LIMIT_DEG_S) * $dt;
            $rollLimit = ($groundStep ? self::GROUND_ROLL_RATE_LIMIT_DEG_S : self::AIRBORNE_ROLL_RATE_LIMIT_DEG_S) * $dt;
            $statePitch = $this->rateLimitScalar($lastPitch, $rawPitch, $pitchLimit);
            $stateRoll = $this->rateLimitScalar($lastRoll, $rawRoll, $rollLimit);

            if ($lastPitch !== null) {
                $rawPitchDelta = $rawPitch - $lastPitch;
                $statePitchDelta = $statePitch - $lastPitch;
                $maxPitchDeltaState = max($maxPitchDeltaState, abs($statePitchDelta));
                if (abs($rawPitchDelta) > $pitchLimit + 0.0001) {
                    $countAttitudeSpikesClamped++;
                    $sample['attitude_quality'] = $this->worseQuality((string)$sample['attitude_quality'], 'DEGRADED');
                    $sample['attitude_quality_reason'] = 'raw_pitch_clamped_reconstructed_state_plausible';
                }
            }
            if ($lastRoll !== null) {
                $rawRollDelta = $rawRoll - $lastRoll;
                $stateRollDelta = $stateRoll - $lastRoll;
                $maxRollDeltaState = max($maxRollDeltaState, abs($stateRollDelta));
                if (abs($rawRollDelta) > $rollLimit + 0.0001) {
                    $countAttitudeSpikesClamped++;
                    $sample['attitude_quality'] = $this->worseQuality((string)$sample['attitude_quality'], 'DEGRADED');
                    $sample['attitude_quality_reason'] = 'raw_roll_clamped_reconstructed_state_plausible';
                }
            }

            $sample['pitch_deg'] = round($statePitch, 2);
            $sample['roll_deg'] = round($stateRoll, 2);
            $sample['visual_pitch_deg'] = $sample['pitch_deg'];
            $sample['visual_roll_deg'] = $sample['roll_deg'];

            if ((string)($sample['track_quality'] ?? '') === 'LOW' && $speed < self::GPS_HEADING_MIN_SPEED_KT) {
                $sample['track_quality'] = 'DEGRADED';
                $sample['track_quality_reason'] = 'track_unavailable_or_held_below_moving_speed';
            }
            if ((string)($sample['speed_quality'] ?? '') === 'LOW' && $speed < self::SLOW_TAXI_SPEED_KT) {
                $sample['speed_quality'] = 'DEGRADED';
                $sample['speed_quality_reason'] = 'speed_terminal_or_stationary_hold_plausible';
            }
            if ((string)($sample['heading_quality'] ?? '') === 'LOW' && (string)($sample['heading_source'] ?? '') === 'held' && $speed < self::SLOW_TAXI_SPEED_KT) {
                $sample['heading_quality'] = 'DEGRADED';
                $sample['heading_quality_reason'] = 'heading_held_while_stationary_plausible';
                if ((string)($sample['attitude_quality'] ?? '') === 'LOW') {
                    $sample['attitude_quality'] = 'DEGRADED';
                    $sample['attitude_quality_reason'] = 'attitude_heading_held_while_stationary_plausible';
                }
            }

            if ((string)$sample['position_quality'] === 'GOOD' && ($ground || $i === 0)) {
                $sample['position_quality'] = 'DEGRADED';
            }

            $samples[$i] = $sample;
            $lastSpeed = $speed;
            $lastHeading = (float)$sample['heading_deg_true'];
            $lastPitch = $statePitch;
            $lastRoll = $stateRoll;
        }

        $low = 0;
        $degraded = 0;
        $qualityCountsByField = array();
        $qualitySourceCountsByField = array();
        $lowReasonCounts = array();
        $degradedReasonCounts = array();
        $finalMaxGroundspeedDelta = 0.0;
        $finalMaxTaxiImpliedSpeed = 0.0;
        $finalMaxMismatch = 0.0;
        foreach ($samples as $sample) {
            foreach (array(
                'position' => array('quality' => 'position_quality', 'source' => 'position_source', 'reason' => 'position_quality_reason'),
                'altitude' => array('quality' => 'altitude_quality', 'source' => 'altitude_source', 'reason' => 'altitude_quality_reason'),
                'attitude' => array('quality' => 'attitude_quality', 'source' => 'raw_attitude_source', 'reason' => 'attitude_quality_reason'),
                'heading' => array('quality' => 'heading_quality', 'source' => 'heading_source', 'reason' => 'heading_quality_reason'),
                'track' => array('quality' => 'track_quality', 'source' => 'track_source', 'reason' => 'track_quality_reason'),
                'speed' => array('quality' => 'speed_quality', 'source' => 'speed_source', 'reason' => 'speed_quality_reason'),
            ) as $field => $meta) {
                $quality = strtoupper((string)($sample[$meta['quality']] ?? 'UNKNOWN'));
                $source = (string)($sample[$meta['source']] ?? 'unavailable');
                $reason = (string)($sample[$meta['reason']] ?? strtolower($field) . '_no_reason_recorded');
                $qualityCountsByField[$field][$quality] = (int)($qualityCountsByField[$field][$quality] ?? 0) + 1;
                $qualitySourceCountsByField[$field][$source . '|' . $quality] = (int)($qualitySourceCountsByField[$field][$source . '|' . $quality] ?? 0) + 1;
                if ($quality === 'LOW') {
                    $lowReasonCounts[$field . '|' . $reason] = (int)($lowReasonCounts[$field . '|' . $reason] ?? 0) + 1;
                } elseif ($quality === 'DEGRADED') {
                    $degradedReasonCounts[$field . '|' . $reason] = (int)($degradedReasonCounts[$field . '|' . $reason] ?? 0) + 1;
                }
            }
            $qualities = array(
                (string)($sample['position_quality'] ?? ''),
                (string)($sample['altitude_quality'] ?? ''),
                (string)($sample['attitude_quality'] ?? ''),
                (string)($sample['heading_quality'] ?? ''),
                (string)($sample['track_quality'] ?? ''),
                (string)($sample['speed_quality'] ?? ''),
            );
            if (in_array('LOW', $qualities, true)) {
                $low++;
            } elseif (in_array('DEGRADED', $qualities, true)) {
                $degraded++;
            }
        }
        for ($i = 1; $i < count($samples); $i++) {
            $before = $samples[$i - 1];
            $after = $samples[$i];
            $dt = max(0.001, (float)$after['t'] - (float)$before['t']);
            $beforeSpeed = isset($before['ground_speed_kt']) && is_numeric($before['ground_speed_kt']) ? (float)$before['ground_speed_kt'] : 0.0;
            $afterSpeed = isset($after['ground_speed_kt']) && is_numeric($after['ground_speed_kt']) ? (float)$after['ground_speed_kt'] : 0.0;
            $groundStep = min($beforeSpeed, $afterSpeed) < self::GROUND_SPEED_MAX_KT;
            $implied = $this->impliedSpeedKt($before, $after, $dt);
            $mismatch = abs($implied - $afterSpeed);
            if ($groundStep) {
                $finalMaxGroundspeedDelta = max($finalMaxGroundspeedDelta, abs($afterSpeed - $beforeSpeed));
                $finalMaxTaxiImpliedSpeed = max($finalMaxTaxiImpliedSpeed, $implied);
                $finalMaxMismatch = max($finalMaxMismatch, $mismatch);
            }
        }

        $this->diagnostics['max_groundspeed_delta_kt'] = round($finalMaxGroundspeedDelta, 2);
        $this->diagnostics['max_taxi_implied_speed_kt'] = round($finalMaxTaxiImpliedSpeed, 1);
        $this->diagnostics['max_position_speed_mismatch_kt'] = round($finalMaxMismatch, 1);
        $this->diagnostics['max_heading_delta_ground_deg'] = round($maxGroundHeadingDelta, 2);
        $this->diagnostics['count_position_outliers'] = $countPositionOutliers;
        $this->diagnostics['count_takeoff_transition_position_corrections'] = $countTakeoffTransitionPositionCorrections;
        $this->diagnostics['first_position_discontinuity_time_s'] = $firstPositionDiscontinuityTime !== null
            ? round($firstPositionDiscontinuityTime, 3)
            : null;
        $this->diagnostics['first_position_discontinuity_source'] = $firstPositionDiscontinuitySource;
        $this->diagnostics['count_speed_outliers'] = $countSpeedOutliers;
        $this->diagnostics['count_heading_outliers'] = $countHeadingOutliers;
        $this->diagnostics['count_attitude_spikes_clamped'] = $countAttitudeSpikesClamped;
        $this->diagnostics['max_pitch_delta_state_deg'] = round($maxPitchDeltaState, 2);
        $this->diagnostics['max_roll_delta_state_deg'] = round($maxRollDeltaState, 2);
        $this->diagnostics['count_low_quality_samples'] = $low;
        $this->diagnostics['count_degraded_samples'] = $degraded;
        $this->diagnostics['quality_counts_by_field'] = $qualityCountsByField;
        $this->diagnostics['quality_source_counts_by_field'] = $qualitySourceCountsByField;
        $this->diagnostics['low_quality_reason_counts'] = $lowReasonCounts;
        $this->diagnostics['degraded_quality_reason_counts'] = $degradedReasonCounts;
        $this->diagnostics['count_empty_phase_samples'] = 0;
        $this->diagnostics['count_original_empty_phase_samples'] = $countOriginalEmptyPhase;

        return $samples;
    }

    private function impliedSpeedKt(array $before, array $after, float $dt): float
    {
        if (!isset($before['lat'], $before['lon'], $after['lat'], $after['lon']) || $before['lat'] === null || $before['lon'] === null || $after['lat'] === null || $after['lon'] === null) {
            return 0.0;
        }
        $meters = $this->distanceMeters((float)$before['lat'], (float)$before['lon'], (float)$after['lat'], (float)$after['lon']);
        return $this->metersPerSecondToKnots($meters / max(0.001, $dt));
    }

    private function distanceMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2.0) ** 2 + cos($lat1Rad) * cos($lat2Rad) * sin($dLon / 2.0) ** 2;
        return 6371000.0 * 2.0 * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a)));
    }

    private function rateLimitScalar(?float $previous, float $target, float $maxDelta): float
    {
        if ($previous === null) {
            return $target;
        }
        return $previous + max(-$maxDelta, min($maxDelta, $target - $previous));
    }

    /**
     * @return array{lat:float,lon:float}|null
     */
    private function projectPositionFromSpeed(array $before, array $after, float $speedKt, float $dt): ?array
    {
        if (!isset($before['lat'], $before['lon']) || $before['lat'] === null || $before['lon'] === null) {
            return null;
        }

        $lat = (float)$before['lat'];
        $lon = (float)$before['lon'];
        $heading = isset($before['heading_deg']) && is_numeric($before['heading_deg'])
            ? (float)$before['heading_deg']
            : (isset($after['heading_deg']) && is_numeric($after['heading_deg']) ? (float)$after['heading_deg'] : 0.0);
        $distanceM = max(0.0, $speedKt / 1.943844492) * max(0.0, $dt);
        $headingRad = deg2rad($heading);
        $north = cos($headingRad) * $distanceM;
        $east = sin($headingRad) * $distanceM;
        $latRad = deg2rad($lat);

        return array(
            'lat' => $lat + rad2deg($north / self::EARTH_RADIUS_M),
            'lon' => $lon + rad2deg($east / (self::EARTH_RADIUS_M * max(0.001, cos($latRad)))),
        );
    }

    private function worseQuality(string $current, string $candidate): string
    {
        $rank = array('GOOD' => 0, 'DEGRADED' => 1, 'LOW' => 2);
        $currentRank = $rank[strtoupper($current)] ?? 1;
        $candidateRank = $rank[strtoupper($candidate)] ?? 1;
        return $candidateRank > $currentRank ? strtoupper($candidate) : strtoupper($current);
    }

    private function fallbackPhaseForSample(array $sample): string
    {
        $t = isset($sample['t']) && is_numeric($sample['t']) ? (float)$sample['t'] : 0.0;
        $speed = isset($sample['ground_speed_kt']) && is_numeric($sample['ground_speed_kt']) ? (float)$sample['ground_speed_kt'] : 0.0;
        $alt = isset($sample['altitude_ft']) && is_numeric($sample['altitude_ft']) ? (float)$sample['altitude_ft'] : 0.0;

        if ($speed < self::STATIONARY_SPEED_KT && $t < 180.0) {
            return 'Preflight';
        }
        if ($speed < 35.0) {
            return $t < 2400.0 ? 'Taxi Out' : 'Taxi Back / Stop';
        }
        if ($speed < 55.0 && $t < 900.0) {
            return 'Takeoff';
        }
        if ($t > 2400.0 && $speed < 65.0) {
            return 'Landing/Rollout';
        }
        if ($alt < 500.0 && $t < 1200.0) {
            return 'Climb';
        }
        if ($t > 2000.0) {
            return 'Descent';
        }
        return 'Cruise/Maneuvering';
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

    private function isTakeoffTransition(string $beforePhase, string $afterPhase, ?float $beforeSpeed, float $afterSpeed): bool
    {
        $before = strtolower($beforePhase);
        $after = strtolower($afterPhase);
        $fromGround = $this->isGroundPhase($before) || ($beforeSpeed !== null && $beforeSpeed < self::GROUND_SPEED_MAX_KT);
        $toTakeoff = str_contains($after, 'takeoff');

        return $fromGround && $toTakeoff;
    }

    private function isTakeoffRollSpeedStep(string $beforePhase, string $afterPhase, float $beforeSpeed, float $afterSpeed): bool
    {
        $before = strtolower($beforePhase);
        $after = strtolower($afterPhase);
        $takeoffContext = str_contains($before, 'takeoff') || str_contains($after, 'takeoff');

        return $takeoffContext && min($beforeSpeed, $afterSpeed) < 45.0;
    }

    /**
     * @param list<array{t:float,v:float,priority:int,source?:string}> $knots
     * @return list<array{t:float,v:float,source?:string}>
     */
    private function mergeScalarKnots(array $knots): array
    {
        $merged = array();
        foreach ($knots as $knot) {
            if (!$merged) {
                $merged[] = array(
                    't' => $knot['t'],
                    'v' => $knot['v'],
                    'source' => (string)($knot['source'] ?? 'unknown'),
                );
                continue;
            }
            $last = $merged[count($merged) - 1];
            if (abs($knot['t'] - $last['t']) <= 0.05) {
                continue;
            }
            $merged[] = array(
                't' => $knot['t'],
                'v' => $knot['v'],
                'source' => (string)($knot['source'] ?? 'unknown'),
            );
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
