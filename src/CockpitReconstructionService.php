<?php
declare(strict_types=1);

require_once __DIR__ . '/CockpitRecorderService.php';
require_once __DIR__ . '/G3XFlightStreamParser.php';
require_once __DIR__ . '/tv_adsb_status.php';

/**
 * Builds derived flight reconstruction data from preserved Cockpit Recorder evidence.
 *
 * Phase 1 intentionally uses GPS + AHRS only. No grading, ACS analysis, or coaching.
 */
final class CockpitReconstructionService
{
    private const RECORDINGS_TABLE = 'ipca_cockpit_recordings';
    private const JOB_TABLE = 'ipca_cockpit_reconstruction_jobs';
    private const SAMPLE_TABLE = 'ipca_cockpit_flight_samples';
    private const PHASE_TABLE = 'ipca_cockpit_flight_phases';
    private const EVENT_TABLE = 'ipca_cockpit_timeline_events';
    private const ADSB_TABLE = 'ipca_cockpit_adsb_enrichments';
    private const ADSB_OWNSHIP_TABLE = 'ipca_cockpit_adsb_ownship_samples';

    /** @var array<string,mixed> */
    private array $lastAhrsCalibration = array();

    /** @var array<string,mixed> */
    private array $lastSourceAlignment = array();

    /** @var array<string,mixed> */
    private array $lastG3XAlignment = array();

    /** @var list<string> */
    private const G3X_COLUMNS = array(
        'Date (yyyy-mm-dd)',
        'Time (hh:mm:ss)',
        'UTC Time (hh:mm:ss)',
        'UTC Offset (hh:mm)',
        'Latitude (deg)',
        'Longitude',
        'GPS Altitude (ft)',
        'GPS Fix Status',
        'GPS Time of Week (sec)',
        'GPS Ground Speed (kt)',
        'GPS Ground Track (deg)',
        'GPS Velocity E (m/sec)',
        'GPS Velocity N (m/sec)',
        'GPS Velocity U (m/sec)',
        'Magnetic Heading (deg)',
        'GPS HFOM (ft)',
        'GPS VFOM (ft)',
        'GPS Sats',
        'Pressure Altitude (ft)',
        'Baro Altitude (ft)',
        'Vertical Speed (ft/min)',
        'Indicated Airspeed (kt)',
        'True Airspeed (kt)',
        'Pitch (deg)',
        'Roll (deg)',
        'Lateral Acceleration (G)',
        'Normal Acceleration (G)',
        'AOA Cp',
        'AOA',
        'Selected Heading (deg)',
        'Selected Altitude (ft)',
        'Selected Vertical Speed (ft/min)',
        'Selected Airspeed (kt)',
        'Baro Setting (inch Hg)',
        'COM Frequency 1 (MHz)',
        'COM Frequency 2 (MHz)',
        'NAV Frequency 2 (MHz)',
        'Active Nav Source',
        'Nav Annunciation',
        'Nav Identifier',
        'Nav Distance (nm)',
        'Nav Bearing (deg)',
        'Nav Course (deg)',
        'Nav Cross Track Distance (nm)',
        'Horizontal CDI Deflection',
        'Horizontal CDI Full Scale (ft)',
        'Horizontal CDI Scale',
        'Vertical CDI Deflection',
        'Vertical CDI Full Scale (ft)',
        'VNAV CDI Deflection',
        'VNAV Altitude (ft)',
        'Autopilot State',
        'FD Lateral Mode',
        'FD Vertical Mode',
        'FD Roll Command (deg)',
        'FD Pitch Command (deg)',
        'FD Altitude (ft)',
        'AP Roll Command (deg)',
        'AP Pitch Command (deg)',
        'AP VS Command (ft/min)',
        'AP Altitude Command (ft)',
        'AP Roll Torque (%)',
        'AP Pitch Torque (%)',
        'Magnetic Variation (deg)',
        'Outside Air Temp (deg C)',
        'Density Altitude (ft)',
        'Height Above Ground (ft)',
        'Wind Speed (kt)',
        'Wind Direction (deg)',
        'AHRS/Mag 1 Status',
        'SFD AHRS Status',
        'Pitch Delta (deg)',
        'Roll Delta (deg)',
        'AHRS 1 Dev (%)',
        'SFD AHRS Dev (%)',
        'Network Status',
        'Transponder Code',
        'Transponder Mode',
        'Manifold Press (inch Hg)',
        'RPM',
        'Oil Press (PSI)',
        'Oil Temp (deg F)',
        'Fuel Qty (gal)',
        'Fuel Flow (gal/hour)',
        'Fuel Press (PSI)',
        'Elevator Trim',
        'Coolant Temp 1 (deg F)',
        'Coolant Temp 2 (deg F)',
        'Volts',
        'Amps',
        'EGT1 (deg F)',
        'EGT2 (deg F)',
        'CAS Alert',
        'Terrain Alert',
    );

    /** @var list<string> */
    private const G3X_SHORT_COLUMNS = array(
        'Lcl Date', 'Lcl Time', 'UTC Time', 'UTCOfst', 'Latitude', 'Longitude', 'AltGPS', 'GPSfix', '',
        'GndSpd', 'TRK', 'GPSVelE', 'GPSVelN', 'GPSVelU', 'HDG', 'HFOM', 'VFOM', '', 'AltP', 'AltInd',
        'VSpd', 'IAS', 'TAS', 'Pitch', 'Roll', 'LatAc', 'NormAc', '', 'AOA', 'SelHDG', 'SelALT',
        'SelVSpd', 'SelIAS', 'Baro', 'COM1', 'COM2', 'NAV2', 'NavSrc', '', 'NavIdent', 'NavDist',
        'NavBrg', 'NavCRS', 'NavXTK', 'HCDI', '', '', 'VCDI', '', 'VNAV CDI', 'VNAVAlt', '', '', '',
        '', '', '', '', '', '', '', '', '', 'MagVar', 'OAT', 'AltD', 'AGL', 'WndSpd', 'WndDr', '',
        '', '', '', '', '', '', '', '', 'E1 MAP', 'E1 RPM', 'E1 OilP', 'E1 OilT', 'FQty1',
        'E1 FFlow', 'E1 FPres', 'PTrim', '', '', 'Volts1', 'Amps1', 'E1 EGT1', 'E1 EGT2', '', '',
    );

    public function __construct(private PDO $pdo)
    {
    }

    public static function tablesPresent(PDO $pdo): bool
    {
        $required = array(self::JOB_TABLE, self::SAMPLE_TABLE, self::PHASE_TABLE, self::EVENT_TABLE);
        foreach ($required as $table) {
            $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
            $stmt->execute(array($table));
            if ($stmt->fetchColumn() === false) {
                return false;
            }
        }
        return true;
    }

    public function requireTables(): void
    {
        if (!self::tablesPresent($this->pdo)) {
            throw new RuntimeException('Apply scripts/sql/2026_06_23_cockpit_recorder_reconstruction_foundation.sql first.');
        }
        if (!$this->columnPresent(self::SAMPLE_TABLE, 'estimated_indicated_altitude_ft') || !$this->columnPresent(self::SAMPLE_TABLE, 'ahrs_acceleration_y_g') || !$this->columnPresent(self::SAMPLE_TABLE, 'estimated_wind_speed_kt')) {
            throw new RuntimeException('Apply scripts/sql/2026_06_24_cockpit_recorder_derived_replay_values.sql before reconstructing.');
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function reconstruct(string $id, array $options = array()): array
    {
        $this->requireTables();
        $recorder = new CockpitRecorderService($this->pdo);
        $recording = $recorder->recordingByAnyId($id);
        if (!$recording) {
            throw new RuntimeException('Recording not found.');
        }

        $recordingId = (int)$recording['id'];
        $jobId = $this->createJob($recordingId);
        $this->setRecordingStatus($recordingId, 'processing', 'processing', 'not_started', null);
        $inTransaction = false;

        try {
            $gpsSamples = $this->loadGPS($recording);
            $ahrsSamples = $this->loadAHRS($recording);
            if (!$gpsSamples && !$ahrsSamples) {
                throw new RuntimeException('No GPS or AHRS samples available for reconstruction.');
            }

            $adsbSamples = $this->loadAdsbOwnship($recordingId);
            $samples = $this->buildCanonicalSamples($recording, $gpsSamples, $ahrsSamples, $adsbSamples, $options);
            $timeline = $this->detectTimeline($recording, $samples);
            $summary = $this->buildSummary($recording, $samples, $timeline['phases'], $timeline['events']);
            $json = json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $this->pdo->beginTransaction();
            $inTransaction = true;
            $this->clearDerivedData($recordingId);
            $this->storeSamples($recordingId, $samples);
            $this->updateJob($jobId, 'processing', 45, null);
            $this->storePhases($recordingId, $timeline['phases']);
            $this->storeEvents($recordingId, $timeline['events']);
            $this->setRecordingStatus($recordingId, 'ready', 'ready', (string)($recording['adsb_status'] ?? 'not_started'), $json ?: null);
            $this->ensureAdsbScaffold($recording);
            $this->updateJob($jobId, 'ready', 100, null);
            $this->pdo->commit();
            $inTransaction = false;

            return array(
                'ok' => true,
                'recording_id' => $recordingId,
                'sample_count' => count($samples),
                'phase_count' => count($timeline['phases']),
                'event_count' => count($timeline['events']),
                'summary' => $summary,
            );
        } catch (Throwable $e) {
            if ($inTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->setRecordingStatus($recordingId, 'failed', 'failed', (string)($recording['adsb_status'] ?? 'not_started'), null);
            $this->updateJob($jobId, 'failed', 0, $e->getMessage());
            throw $e;
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function status(string $id): array
    {
        $recording = (new CockpitRecorderService($this->pdo))->recordingByAnyId($id);
        if (!$recording) {
            return array('ok' => false, 'error' => 'Recording not found.');
        }

        $recordingId = (int)$recording['id'];
        $sampleCount = $this->countRows(self::SAMPLE_TABLE, $recordingId);
        $samples = $this->sampleRows($recordingId, 20000);
        return array(
            'ok' => true,
            'recording_id' => $recordingId,
            'reconstruction_status' => (string)($recording['reconstruction_status'] ?? 'not_started'),
            'timeline_status' => (string)($recording['timeline_status'] ?? 'not_started'),
            'adsb_status' => (string)($recording['adsb_status'] ?? 'not_started'),
            'sample_count' => $this->countRows(self::SAMPLE_TABLE, $recordingId),
            'phase_count' => $this->countRows(self::PHASE_TABLE, $recordingId),
            'event_count' => $this->countRows(self::EVENT_TABLE, $recordingId),
            'summary' => self::decodeJson((string)($recording['reconstruction_summary_json'] ?? '')),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function replayPayload(string $id): array
    {
        $recording = (new CockpitRecorderService($this->pdo))->recordingByAnyId($id);
        if (!$recording) {
            return array('ok' => false, 'error' => 'Recording not found.');
        }

        $recordingId = (int)$recording['id'];
        $sampleCount = $this->countRows(self::SAMPLE_TABLE, $recordingId);
        $samples = $this->sampleRows($recordingId, 30000);
        return array(
            'ok' => true,
            'recording' => array(
                'id' => $recordingId,
                'recording_id' => (string)$recording['recording_uid'],
                'duration' => (float)($recording['duration_seconds'] ?? 0),
                'started_at' => $recording['started_at'] ?? null,
                'aircraft' => array(
                    'registration' => (string)($recording['aircraft_registration'] ?? ''),
                    'display_name' => (string)($recording['aircraft_display_name'] ?? ''),
                    'type' => (string)($recording['aircraft_type'] ?? ''),
                    'adsb_hex' => (string)($recording['aircraft_adsb_hex'] ?? ''),
                ),
                'audio_url' => '/admin/cockpit_recorder_audio.php?id=' . $recordingId,
                'reconstruction_status' => (string)($recording['reconstruction_status'] ?? 'not_started'),
                'timeline_status' => (string)($recording['timeline_status'] ?? 'not_started'),
                'adsb_status' => (string)($recording['adsb_status'] ?? 'not_started'),
            ),
            'summary' => self::decodeJson((string)($recording['reconstruction_summary_json'] ?? '')),
            'phases' => $this->phaseRows($recordingId),
            'events' => $this->eventRows($recordingId),
            'sample_count' => $sampleCount,
            'sample_payload_count' => count($samples),
            'sample_payload_downsampled' => $sampleCount > count($samples),
            'samples' => $samples,
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function phaseRows(int $recordingId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . self::PHASE_TABLE . ' WHERE recording_id = ? ORDER BY phase_order ASC, start_seconds ASC');
        $stmt->execute(array($recordingId));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? array_map(fn(array $row): array => $this->publicPhase($row), $rows) : array();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function eventRows(int $recordingId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . self::EVENT_TABLE . ' WHERE recording_id = ? ORDER BY start_seconds ASC, id ASC');
        $stmt->execute(array($recordingId));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? array_map(fn(array $row): array => $this->publicEvent($row), $rows) : array();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function sampleRows(int $recordingId, int $limit = 5000): array
    {
        $limit = max(1, min(100000, $limit));
        $count = $this->countRows(self::SAMPLE_TABLE, $recordingId);
        $columns = implode(', ', array(
            'sample_time_utc',
            'seconds_since_start',
            'latitude',
            'longitude',
            'gps_altitude_ft',
            'baro_altitude_ft',
            'vertical_speed_fpm',
            'adsb_baro_altitude_ft',
            'adsb_vertical_speed_fpm',
            'estimated_baro_altitude_ft',
            'estimated_vertical_speed_fpm',
            'field_calibrated_altitude_ft',
            'field_calibrated_true_altitude_ft',
            'estimated_indicated_altitude_ft',
            'estimated_true_altitude_from_indicated_ft',
            'altimeter_setting_inhg',
            'altimeter_setting_source',
            'airport_elevation_ft',
            'airport_elevation_source',
            'field_altitude_offset_ft',
            'oat_c',
            'oat_source',
            'altitude_source',
            'altitude_quality',
            'vertical_speed_source',
            'vertical_speed_quality',
            'estimated_slip_skid_g',
            'estimated_slip_skid_quality',
            'estimated_slip_skid_source',
            'ahrs_acceleration_x_g',
            'ahrs_acceleration_y_g',
            'ahrs_acceleration_z_g',
            'estimated_wind_speed_kt',
            'estimated_wind_direction_deg_true',
            'estimated_wind_quality',
            'estimated_wind_source',
            'estimated_tas_kt',
            'wind_estimation_method',
            'groundspeed_kt',
            'magnetic_track_deg',
            'pitch_deg',
            'roll_deg',
            'magnetic_heading_deg',
            'true_heading_deg',
        ));
        if ($count > $limit) {
            $stride = max(1, (int)ceil($count / $limit));
            $stmt = $this->pdo->query('
                SELECT ' . $columns . '
                FROM ' . self::SAMPLE_TABLE . '
                WHERE recording_id = ' . (int)$recordingId . '
                  AND (MOD(sample_index, ' . $stride . ') = 0 OR sample_index = ' . ($count - 1) . ')
                ORDER BY sample_index ASC
            ');
        } else {
            $stmt = $this->pdo->query('SELECT ' . $columns . ' FROM ' . self::SAMPLE_TABLE . ' WHERE recording_id = ' . (int)$recordingId . ' ORDER BY sample_index ASC');
        }
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();
        return is_array($rows) ? array_map(fn(array $row): array => $this->publicSample($row), $rows) : array();
    }

    public function streamG3XCsv(string $id): void
    {
        $recording = (new CockpitRecorderService($this->pdo))->recordingByAnyId($id);
        if (!$recording) {
            throw new RuntimeException('Recording not found.');
        }

        $recordingId = (int)$recording['id'];
        $out = fopen('php://output', 'w');
        if ($out === false) {
            throw new RuntimeException('Could not open output stream.');
        }

        fputcsv($out, array('#airframe_info', 'log_version="1.00"', 'log_content_version="1.02"', 'product="IPCA Flight Intelligence"', 'aircraft_ident="' . (string)($recording['aircraft_registration'] ?? '') . '"'));
        fputcsv($out, self::G3X_COLUMNS);
        fputcsv($out, self::G3X_SHORT_COLUMNS);

        $stmt = $this->pdo->prepare('SELECT g3x_row_json FROM ' . self::SAMPLE_TABLE . ' WHERE recording_id = ? ORDER BY sample_index ASC');
        $stmt->execute(array($recordingId));
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $decoded = self::decodeJson((string)($row['g3x_row_json'] ?? ''));
            $line = array();
            foreach (self::G3X_COLUMNS as $column) {
                $line[] = isset($decoded[$column]) ? (string)$decoded[$column] : '';
            }
            fputcsv($out, $line);
        }
        fclose($out);
    }

    private function createJob(int $recordingId): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO ' . self::JOB_TABLE . ' (recording_id, status, progress, started_at, created_at, updated_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
        $stmt->execute(array($recordingId, 'processing', 1));
        return (int)$this->pdo->lastInsertId();
    }

    private function updateJob(int $jobId, string $status, int $progress, ?string $error): void
    {
        $stmt = $this->pdo->prepare('UPDATE ' . self::JOB_TABLE . ' SET status = ?, progress = ?, error_message = ?, completed_at = IF(? IN (\'ready\', \'failed\'), CURRENT_TIMESTAMP, completed_at), updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute(array($status, max(0, min(100, $progress)), $error, $status, $jobId));
    }

    public function markReconstructionFailed(string $id, string $error): void
    {
        $recording = (new CockpitRecorderService($this->pdo))->recordingByAnyId($id);
        if (!$recording) {
            return;
        }

        $stmt = $this->pdo->prepare('UPDATE ' . self::RECORDINGS_TABLE . ' SET reconstruction_status = \'failed\', timeline_status = \'failed\', error_message = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute(array(substr($error, 0, 2000), (int)$recording['id']));
    }

    private function clearDerivedData(int $recordingId): void
    {
        foreach (array(self::SAMPLE_TABLE, self::PHASE_TABLE, self::EVENT_TABLE) as $table) {
            $stmt = $this->pdo->prepare('DELETE FROM ' . $table . ' WHERE recording_id = ?');
            $stmt->execute(array($recordingId));
        }
    }

    private function setRecordingStatus(int $recordingId, string $reconstruction, string $timeline, string $adsb, ?string $summaryJson): void
    {
        $stmt = $this->pdo->prepare('UPDATE ' . self::RECORDINGS_TABLE . ' SET reconstruction_status = ?, timeline_status = ?, adsb_status = ?, reconstruction_summary_json = ?, reconstructed_at = IF(? = \'ready\', CURRENT_TIMESTAMP, reconstructed_at), updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute(array($reconstruction, $timeline, $adsb, $summaryJson, $reconstruction, $recordingId));
    }

    /**
     * @param array<string,mixed> $recording
     * @return list<array<string,mixed>>
     */
    private function loadGPS(array $recording): array
    {
        $path = $this->safeStoredPath((string)($recording['gps_storage_path'] ?? ''), CockpitRecorderService::gpsRoot());
        return $path ? $this->loadJsonList($path) : array();
    }

    /**
     * @param array<string,mixed> $recording
     * @return list<array<string,mixed>>
     */
    private function loadAHRS(array $recording): array
    {
        $path = $this->safeStoredPath((string)($recording['ahrs_storage_path'] ?? ''), CockpitRecorderService::ahrsRoot());
        return $path ? $this->loadJsonList($path) : array();
    }

    /**
     * @param array<string,mixed> $recording
     * @param list<array<string,mixed>> $gps
     * @return list<array{seconds: float, row: array<string,string>}>
     */
    private function loadG3XNormalized(array $recording, array $gps): array
    {
        if (!$this->columnPresent(self::RECORDINGS_TABLE, 'g3x_storage_path')) {
            return array();
        }
        $path = $this->safeStoredPath((string)($recording['g3x_storage_path'] ?? ''), CockpitRecorderService::g3xRoot());
        if ($path === null) {
            return array();
        }

        try {
            $parsed = G3XFlightStreamParser::parseFile($path);
        } catch (Throwable) {
            return array();
        }

        $startedAt = self::dateTime((string)($recording['started_at'] ?? ''));
        if ($startedAt === null) {
            return array();
        }

        $offsetSeconds = $this->computeG3XOffsetSeconds($recording, $gps, $parsed['rows'], $startedAt);
        $this->lastG3XAlignment = array(
            'available' => true,
            'row_count' => (int)$parsed['row_count'],
            'aircraft_ident' => (string)$parsed['aircraft_ident'],
            'offset_seconds' => $offsetSeconds,
        );

        $normalized = array();
        foreach ($parsed['rows'] as $row) {
            $utc = G3XFlightStreamParser::rowUtcTimestamp($row);
            if ($utc === null) {
                continue;
            }
            $seconds = $utc->getTimestamp() - $startedAt->getTimestamp() + $offsetSeconds;
            $normalized[] = array(
                'seconds' => (float)$seconds,
                'row' => $row,
            );
        }
        usort($normalized, fn(array $a, array $b): int => ((float)$a['seconds']) <=> ((float)$b['seconds']));
        return $normalized;
    }

    /**
     * @param array<string,mixed> $recording
     * @param list<array<string,mixed>> $gps
     * @param list<array<string,string>> $g3xRows
     */
    private function computeG3XOffsetSeconds(array $recording, array $gps, array $g3xRows, DateTimeImmutable $startedAt): float
    {
        $stored = isset($recording['g3x_time_offset_seconds']) && is_numeric($recording['g3x_time_offset_seconds'])
            ? (float)$recording['g3x_time_offset_seconds']
            : null;
        if ($stored !== null) {
            return $stored;
        }

        $firstUtc = G3XFlightStreamParser::firstUtcTimestamp($g3xRows);
        if ($firstUtc === null) {
            return 0.0;
        }

        $baseOffset = 0.0;
        $deltas = array();
        foreach ($gps as $gpsRow) {
            if (!isset($gpsRow['seconds'], $gpsRow['latitude'], $gpsRow['longitude'])) {
                continue;
            }
            $seconds = (float)$gpsRow['seconds'];
            if ($seconds < 0 || $seconds > 180.0) {
                continue;
            }
            $lat = (float)$gpsRow['latitude'];
            $lon = (float)$gpsRow['longitude'];
            $bestDelta = null;
            $bestDistance = INF;
            foreach ($g3xRows as $g3xRow) {
                $utc = G3XFlightStreamParser::rowUtcTimestamp($g3xRow);
                $gLat = G3XFlightStreamParser::numericValue($g3xRow, 'Latitude (deg)');
                $gLon = G3XFlightStreamParser::numericValue($g3xRow, 'Longitude (deg)', 'Longitude');
                if ($utc === null || $gLat === null || $gLon === null) {
                    continue;
                }
                $g3xSeconds = (float)($utc->getTimestamp() - $firstUtc->getTimestamp());
                $distance = self::haversineMeters($lat, $lon, $gLat, $gLon);
                if ($distance < $bestDistance) {
                    $bestDistance = $distance;
                    $bestDelta = $seconds - $g3xSeconds;
                }
            }
            if ($bestDelta !== null && $bestDistance <= 250.0) {
                $deltas[] = $bestDelta;
            }
        }

        if ($deltas) {
            sort($deltas, SORT_NUMERIC);
            $mid = intdiv(count($deltas), 2);
            $baseOffset = count($deltas) % 2 === 1
                ? (float)$deltas[$mid]
                : (((float)$deltas[$mid - 1] + (float)$deltas[$mid]) / 2.0);
        }

        return $baseOffset + ($startedAt->getTimestamp() - $firstUtc->getTimestamp());
    }

    /**
     * @param list<array<string,mixed>> $samples
     * @param list<array{seconds: float, row: array<string,string>}> $g3xSamples
     * @return list<array<string,mixed>>
     */
    private function applyG3XEnrichment(array $samples, array $g3xSamples): array
    {
        foreach ($samples as $index => $sample) {
            $seconds = (float)($sample['seconds_since_start'] ?? 0);
            $near = $this->nearestG3XBySeconds($g3xSamples, $seconds, 1.25);
            if ($near === null) {
                continue;
            }
            $row = $near['row'];
            $samples[$index] = $this->mergeG3XIntoSample($sample, $row);
        }
        return $samples;
    }

    /**
     * @param list<array{seconds: float, row: array<string,string>}> $g3xSamples
     * @return array{seconds: float, row: array<string,string>}|null
     */
    private function nearestG3XBySeconds(array $g3xSamples, float $seconds, float $maxDelta): ?array
    {
        $best = null;
        $bestDelta = $maxDelta;
        foreach ($g3xSamples as $sample) {
            $delta = abs((float)$sample['seconds'] - $seconds);
            if ($delta <= $bestDelta) {
                $bestDelta = $delta;
                $best = $sample;
            }
        }
        return $best;
    }

    /**
     * @param array<string,mixed> $sample
     * @param array<string,string> $row
     * @return array<string,mixed>
     */
    private function mergeG3XIntoSample(array $sample, array $row): array
    {
        $pitch = G3XFlightStreamParser::numericValue($row, 'Pitch (deg)');
        $roll = G3XFlightStreamParser::numericValue($row, 'Roll (deg)');
        $heading = G3XFlightStreamParser::numericValue($row, 'Magnetic Heading (deg)');
        $baroAlt = G3XFlightStreamParser::numericValue($row, 'Baro Altitude (ft)');
        $vs = G3XFlightStreamParser::numericValue($row, 'Vertical Speed (ft/min)');
        $ias = G3XFlightStreamParser::numericValue($row, 'Indicated Airspeed (kt)');
        $tas = G3XFlightStreamParser::numericValue($row, 'True Airspeed (kt)');
        $latAc = G3XFlightStreamParser::numericValue($row, 'Lateral Acceleration (G)');
        $normAc = G3XFlightStreamParser::numericValue($row, 'Normal Acceleration (G)');
        $oat = G3XFlightStreamParser::numericValue($row, 'Outside Air Temp (deg C)');
        $baro = G3XFlightStreamParser::numericValue($row, 'Baro Setting (inch Hg)');
        $windSpeed = G3XFlightStreamParser::numericValue($row, 'Wind Speed (kt)');
        $windDir = G3XFlightStreamParser::numericValue($row, 'Wind Direction (deg)');
        $groundspeed = G3XFlightStreamParser::numericValue($row, 'GPS Ground Speed (kt)');
        $track = G3XFlightStreamParser::numericValue($row, 'GPS Ground Track (deg)');
        $gpsAlt = G3XFlightStreamParser::numericValue($row, 'GPS Altitude (ft)');
        $latitude = G3XFlightStreamParser::numericValue($row, 'Latitude (deg)');
        $longitude = G3XFlightStreamParser::numericValue($row, 'Longitude (deg)', 'Longitude');
        $apState = trim((string)($row['Autopilot State'] ?? ''));

        if ($pitch !== null) {
            $sample['pitch_deg'] = $pitch;
        }
        if ($roll !== null) {
            $sample['roll_deg'] = $roll;
        }
        if ($heading !== null) {
            $sample['magnetic_heading_deg'] = self::normalizeDegrees($heading);
        }
        if ($baroAlt !== null) {
            $sample['baro_altitude_ft'] = $baroAlt;
            $sample['estimated_baro_altitude_ft'] = $baroAlt;
            $sample['estimated_indicated_altitude_ft'] = $baroAlt;
            $sample['altitude_source'] = 'g3x_baro';
            $sample['altitude_quality'] = 'good';
        }
        if ($vs !== null) {
            $sample['vertical_speed_fpm'] = $vs;
            $sample['estimated_vertical_speed_fpm'] = $vs;
            $sample['vertical_speed_source'] = 'g3x';
            $sample['vertical_speed_quality'] = 'good';
        }
        if ($latAc !== null) {
            $sample['estimated_slip_skid_g'] = $latAc;
            $sample['estimated_slip_skid_source'] = 'g3x';
            $sample['estimated_slip_skid_quality'] = 'good';
        }
        if ($normAc !== null) {
            $sample['acceleration_g'] = $normAc;
        }
        if ($oat !== null) {
            $sample['oat_c'] = $oat;
            $sample['oat_source'] = 'g3x';
        }
        if ($baro !== null) {
            $sample['altimeter_setting_inhg'] = $baro;
            $sample['altimeter_setting_source'] = 'g3x';
        }
        if ($windSpeed !== null) {
            $sample['wind_speed_kt'] = $windSpeed;
            $sample['estimated_wind_speed_kt'] = $windSpeed;
            $sample['estimated_wind_source'] = 'g3x';
            $sample['estimated_wind_quality'] = 'good';
        }
        if ($windDir !== null) {
            $sample['wind_direction_deg'] = $windDir;
            $sample['estimated_wind_direction_deg_true'] = $windDir;
        }
        if ($tas !== null) {
            $sample['estimated_tas_kt'] = $tas;
        }
        if ($groundspeed !== null) {
            $sample['groundspeed_kt'] = $groundspeed;
        }
        if ($track !== null) {
            $sample['magnetic_track_deg'] = $track;
            $sample['true_track_deg'] = $track;
        }
        if ($gpsAlt !== null && ($sample['gps_altitude_ft'] ?? null) === null) {
            $sample['gps_altitude_ft'] = $gpsAlt;
            $sample['gps_altitude_m'] = $gpsAlt / 3.280839895;
        }
        if ($latitude !== null) {
            $sample['latitude'] = $latitude;
        }
        if ($longitude !== null) {
            $sample['longitude'] = $longitude;
        }
        if ($apState !== '') {
            $sample['autopilot_status'] = $apState;
        }
        if ($ias !== null) {
            $sample['wind_estimation_method'] = 'g3x_ias_' . number_format($ias, 1, '.', '');
        }

        $mask = trim((string)($sample['source_mask'] ?? ''));
        if (!str_contains($mask, 'g3x')) {
            $sample['source_mask'] = trim($mask . ' g3x');
        }
        $sample['g3x_row'] = $this->buildImportedG3XRow($row);
        return $sample;
    }

    /**
     * @param array<string,string> $row
     * @return array<string,string>
     */
    private function buildImportedG3XRow(array $row): array
    {
        $mapped = array_fill_keys(self::G3X_COLUMNS, '');
        foreach (self::G3X_COLUMNS as $column) {
            if (isset($row[$column])) {
                $mapped[$column] = (string)$row[$column];
            }
        }
        return $mapped;
    }

    private static function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000.0;
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dPhi = deg2rad($lat2 - $lat1);
        $dLambda = deg2rad($lon2 - $lon1);
        $a = sin($dPhi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dLambda / 2) ** 2;
        return 2 * $earthRadius * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadAdsbOwnship(int $recordingId): array
    {
        if (!$this->tablePresent(self::ADSB_OWNSHIP_TABLE)) {
            return array();
        }
        $stmt = $this->pdo->prepare('SELECT * FROM ' . self::ADSB_OWNSHIP_TABLE . ' WHERE recording_id = ? ORDER BY seconds_since_start ASC');
        $stmt->execute(array($recordingId));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadJsonList(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return array();
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return array();
        }
        if ($decoded === array()) {
            return array();
        }
        return array_keys($decoded) === range(0, count($decoded) - 1) ? $decoded : array();
    }

    private function safeStoredPath(string $relativePath, string $root): ?string
    {
        $relativePath = trim($relativePath);
        if ($relativePath === '') {
            return null;
        }
        $path = CockpitRecorderService::projectRoot() . '/' . ltrim($relativePath, '/');
        $real = realpath($path);
        $realRoot = realpath($root);
        if ($real === false || $realRoot === false || !str_starts_with($real, $realRoot) || !is_file($real)) {
            return null;
        }
        return $real;
    }

    /**
     * @param array<string,mixed> $recording
     * @param list<array<string,mixed>> $gpsSamples
     * @param list<array<string,mixed>> $ahrsSamples
     * @param list<array<string,mixed>> $adsbSamples
     * @return list<array<string,mixed>>
     */
    private function buildCanonicalSamples(array $recording, array $gpsSamples, array $ahrsSamples, array $adsbSamples, array $options = array()): array
    {
        $gps = array_values(array_filter(array_map(fn(array $row): array => $this->normalizeGPS($row), $gpsSamples), fn(array $row): bool => isset($row['seconds'])));
        $ahrs = array_values(array_filter(array_map(fn(array $row): array => $this->normalizeAHRS($row), $ahrsSamples), fn(array $row): bool => isset($row['seconds'])));
        $adsb = array_values(array_filter(array_map(fn(array $row): array => $this->normalizeAdsbOwnship($row), $adsbSamples), fn(array $row): bool => isset($row['seconds'])));
        usort($gps, fn(array $a, array $b): int => ((float)$a['seconds']) <=> ((float)$b['seconds']));
        usort($ahrs, fn(array $a, array $b): int => ((float)$a['seconds']) <=> ((float)$b['seconds']));
        usort($adsb, fn(array $a, array $b): int => ((float)$a['seconds']) <=> ((float)$b['seconds']));
        $calibration = $this->buildAhrsCalibration($gps, $ahrs);
        $this->lastAhrsCalibration = $calibration;
        $this->lastSourceAlignment = $this->buildSourceAlignment($recording, $gps, $ahrs, $adsb);

        $times = array();
        foreach ($gps as $row) {
            $times[(string)round((float)$row['seconds'], 1)] = (float)$row['seconds'];
        }
        foreach ($ahrs as $row) {
            $times[(string)round((float)$row['seconds'], 1)] = (float)$row['seconds'];
        }
        foreach ($adsb as $row) {
            $times[(string)round((float)$row['seconds'], 1)] = (float)$row['seconds'];
        }
        asort($times);

        $samples = array();
        $lastGps = null;
        $lastAhrs = null;
        foreach (array_values($times) as $seconds) {
            $gpsNear = $this->nearestBySeconds($gps, $seconds, 3.0);
            if ($gpsNear !== null) {
                $lastGps = $gpsNear;
            }
            $ahrsNear = $this->nearestBySeconds($ahrs, $seconds, 1.0);
            if ($ahrsNear !== null) {
                $lastAhrs = $ahrsNear;
            }
            $gpsUse = $gpsNear ?? $lastGps;
            $ahrsUse = $ahrsNear ?? $lastAhrs;
            $adsbUse = $this->interpolateAdsbBySeconds($adsb, $seconds, 30.0);
            $sample = $this->mergeSample($recording, $seconds, $gpsUse, $ahrsUse, $adsbUse, $calibration);
            $sample['sample_index'] = count($samples);
            $samples[] = $sample;
        }

        $samples = $this->addDerivedReplayValues($recording, $samples, $options);
        $g3xSamples = $this->loadG3XNormalized($recording, $gps);
        if ($g3xSamples) {
            $samples = $this->applyG3XEnrichment($samples, $g3xSamples);
        }
        return $samples;
    }

    /**
     * @param list<array<string,mixed>> $samples
     * @return list<array<string,mixed>>
     */
    private function addDerivedReplayValues(array $recording, array $samples, array $options = array()): array
    {
        $altimeter = $this->selectAltimeterSetting($recording, $samples, $options);
        $fieldCalibration = $this->buildFieldAltitudeCalibration($recording, $samples, $options);
        $oat = $this->selectOat($recording, $options);
        foreach ($samples as $index => $sample) {
            $gpsAltFt = isset($sample['gps_altitude_ft']) ? (float)$sample['gps_altitude_ft'] : null;
            $fieldAlt = $fieldCalibration !== null && $gpsAltFt !== null
                ? $gpsAltFt + (float)$fieldCalibration['field_offset_ft']
                : null;
            if ($fieldAlt !== null) {
                $estimatedIndicated = $this->estimatedIndicatedAltitudeFt(
                    $fieldAlt,
                    (float)$fieldCalibration['airport_elevation_ft'],
                    $oat !== null ? (float)$oat['oat_c'] : null
                );
                $samples[$index]['field_calibrated_altitude_ft'] = round($fieldAlt, 1);
                $samples[$index]['field_calibrated_true_altitude_ft'] = round($fieldAlt, 1);
                $samples[$index]['estimated_indicated_altitude_ft'] = round($estimatedIndicated, 1);
                $samples[$index]['estimated_true_altitude_from_indicated_ft'] = round($fieldAlt, 1);
                $samples[$index]['estimated_baro_altitude_ft'] = round($estimatedIndicated, 1);
                $samples[$index]['baro_altitude_ft'] = round($estimatedIndicated, 1);
                $samples[$index]['airport_elevation_ft'] = (float)$fieldCalibration['airport_elevation_ft'];
                $samples[$index]['airport_elevation_source'] = (string)$fieldCalibration['source'];
                $samples[$index]['field_altitude_offset_ft'] = (float)$fieldCalibration['field_offset_ft'];
                $samples[$index]['altimeter_setting_inhg'] = $altimeter !== null ? (float)$altimeter['setting_inhg'] : null;
                $samples[$index]['altimeter_setting_source'] = $altimeter !== null ? (string)$altimeter['source'] : 'unavailable';
                $samples[$index]['oat_c'] = $oat !== null ? (float)$oat['oat_c'] : null;
                $samples[$index]['oat_source'] = $oat !== null ? (string)$oat['source'] : 'unavailable';
                $samples[$index]['altitude_quality'] = $altimeter !== null && $oat !== null ? 'good' : ($altimeter !== null ? 'fair' : 'low');
            } else {
                $samples[$index]['field_calibrated_altitude_ft'] = null;
                $samples[$index]['field_calibrated_true_altitude_ft'] = null;
                $samples[$index]['estimated_indicated_altitude_ft'] = null;
                $samples[$index]['estimated_true_altitude_from_indicated_ft'] = null;
                $samples[$index]['estimated_baro_altitude_ft'] = null;
                $samples[$index]['baro_altitude_ft'] = null;
                $samples[$index]['airport_elevation_ft'] = $fieldCalibration !== null ? (float)$fieldCalibration['airport_elevation_ft'] : null;
                $samples[$index]['airport_elevation_source'] = $fieldCalibration !== null ? (string)$fieldCalibration['source'] : 'unavailable';
                $samples[$index]['field_altitude_offset_ft'] = null;
                $samples[$index]['altimeter_setting_inhg'] = $altimeter !== null ? (float)$altimeter['setting_inhg'] : null;
                $samples[$index]['altimeter_setting_source'] = $altimeter !== null ? (string)$altimeter['source'] : 'unavailable';
                $samples[$index]['oat_c'] = $oat !== null ? (float)$oat['oat_c'] : null;
                $samples[$index]['oat_source'] = $oat !== null ? (string)$oat['source'] : 'unavailable';
                $samples[$index]['altitude_quality'] = $gpsAltFt !== null ? 'low' : 'unavailable';
            }
            $samples[$index]['altitude_source'] = $samples[$index]['estimated_indicated_altitude_ft'] !== null ? 'estimated_indicated' : ($gpsAltFt !== null ? 'gps' : 'unavailable');
            $samples[$index]['vertical_speed_fpm'] = null;
            $samples[$index]['estimated_vertical_speed_fpm'] = null;
            $samples[$index]['vertical_speed_source'] = 'unavailable';
            $samples[$index]['vertical_speed_quality'] = 'unavailable';
        }

        if ($altimeter !== null) {
            $samples = $this->addEstimatedVerticalSpeed($samples, 8.0);
        }
        $samples = $this->addEstimatedSlipSkid($samples, 1.5);
        $samples = $this->addEstimatedWind($samples, $this->transcriptWindEvidence($recording));

        foreach ($samples as $index => $sample) {
            $samples[$index]['g3x_row'] = $this->buildG3XRow($recording, $samples[$index]);
        }

        return $samples;
    }

    /**
     * @param list<array<string,mixed>> $samples
     * @param array<string,mixed>|null $transcriptWind
     * @return list<array<string,mixed>>
     */
    private function addEstimatedWind(array $samples, ?array $transcriptWind): array
    {
        foreach ($samples as $index => $sample) {
            $samples[$index]['estimated_wind_speed_kt'] = null;
            $samples[$index]['estimated_wind_direction_deg_true'] = null;
            $samples[$index]['estimated_wind_quality'] = 'unavailable';
            $samples[$index]['estimated_wind_source'] = 'unavailable';
            $samples[$index]['estimated_tas_kt'] = null;
            $samples[$index]['wind_estimation_method'] = 'unavailable';
            $samples[$index]['wind_speed_kt'] = null;
            $samples[$index]['wind_direction_deg'] = null;
        }

        foreach ($this->stableTurnWindSegments($samples) as $segment) {
            $quality = ($segment['duration_seconds'] >= 25.0 && (float)$segment['heading_rate_spread_dps'] <= 1.2) ? 'fair' : 'low';
            if ($transcriptWind !== null) {
                $quality = $quality === 'fair' ? 'fair' : 'low';
            }
            for ($i = (int)$segment['start_index']; $i <= (int)$segment['end_index']; $i++) {
                $samples[$i]['estimated_wind_speed_kt'] = $segment['wind_speed_kt'];
                $samples[$i]['estimated_wind_direction_deg_true'] = $segment['wind_direction_deg_true'];
                $samples[$i]['estimated_wind_quality'] = $quality;
                $samples[$i]['estimated_wind_source'] = $transcriptWind !== null ? 'stable_turn_with_transcript_evidence' : 'stable_turn';
                $samples[$i]['estimated_tas_kt'] = $segment['estimated_tas_kt'];
                $samples[$i]['wind_estimation_method'] = 'bank_angle_heading_rate_gps_vector';
                $samples[$i]['wind_speed_kt'] = $segment['wind_speed_kt'];
                $samples[$i]['wind_direction_deg'] = $segment['wind_direction_deg_true'];
            }
        }

        return $samples;
    }

    /**
     * @param list<array<string,mixed>> $samples
     * @return list<array<string,mixed>>
     */
    private function stableTurnWindSegments(array $samples): array
    {
        $segments = array();
        $run = array();
        $previous = null;
        foreach ($samples as $index => $sample) {
            $heading = $this->sampleHeadingForWind($sample);
            $roll = isset($sample['roll_deg']) && is_numeric($sample['roll_deg']) ? (float)$sample['roll_deg'] : null;
            $gs = isset($sample['groundspeed_kt']) && is_numeric($sample['groundspeed_kt']) ? (float)$sample['groundspeed_kt'] : null;
            $track = isset($sample['true_track_deg']) && is_numeric($sample['true_track_deg']) ? (float)$sample['true_track_deg'] : null;
            $seconds = (float)($sample['seconds_since_start'] ?? 0);
            $candidate = $heading !== null && $roll !== null && $gs !== null && $track !== null && abs($roll) >= 15.0 && abs($roll) <= 50.0 && $gs >= 45.0;
            if ($candidate && $previous !== null && isset($previous['heading'], $previous['seconds'])) {
                $dt = $seconds - (float)$previous['seconds'];
                if ($dt > 0.2 && $dt <= 3.0) {
                    $rate = self::angleDelta((float)$previous['heading'], $heading) / $dt;
                    if (abs($rate) >= 1.0 && abs($rate) <= 8.0 && ($rate * $roll) >= 0.0) {
                        $run[] = array('index' => $index, 'sample' => $sample, 'heading' => $heading, 'rate_dps' => $rate);
                        $previous = array('heading' => $heading, 'seconds' => $seconds);
                        continue;
                    }
                }
            }

            $this->appendWindSegmentIfValid($segments, $run);
            $run = array();
            if ($candidate) {
                $run[] = array('index' => $index, 'sample' => $sample, 'heading' => $heading, 'rate_dps' => null);
                $previous = array('heading' => $heading, 'seconds' => $seconds);
            } else {
                $previous = null;
            }
        }
        $this->appendWindSegmentIfValid($segments, $run);
        return $segments;
    }

    /**
     * @param list<array<string,mixed>> $segments
     * @param list<array<string,mixed>> $run
     */
    private function appendWindSegmentIfValid(array &$segments, array $run): void
    {
        if (count($run) < 8) {
            return;
        }
        $first = $run[0]['sample'];
        $last = $run[count($run) - 1]['sample'];
        $duration = (float)$last['seconds_since_start'] - (float)$first['seconds_since_start'];
        if ($duration < 12.0) {
            return;
        }
        $rates = array_values(array_filter(array_map(fn(array $row): ?float => is_numeric($row['rate_dps'] ?? null) ? (float)$row['rate_dps'] : null, $run), fn($v): bool => $v !== null));
        $banks = array_map(fn(array $row): float => (float)($row['sample']['roll_deg'] ?? 0), $run);
        if (!$rates || (max($banks) - min($banks)) > 14.0) {
            return;
        }
        $rate = self::median($rates);
        $rateSpread = self::percentile(array_map(fn(float $value): float => abs($value - $rate), $rates), 0.90);
        if (abs($rate) < 1.0 || $rateSpread > 2.0) {
            return;
        }
        $bank = self::median($banks);
        $tasKt = (9.80665 * tan(deg2rad(abs($bank))) / deg2rad(abs($rate))) * 1.94384449;
        if ($tasKt < 35.0 || $tasKt > 190.0) {
            return;
        }

        $windEast = array();
        $windNorth = array();
        foreach ($run as $row) {
            $sample = $row['sample'];
            $heading = (float)$row['heading'];
            $gs = (float)$sample['groundspeed_kt'];
            $track = (float)$sample['true_track_deg'];
            $ground = self::vectorFromTrack($track, $gs);
            $air = self::vectorFromTrack($heading, $tasKt);
            $windEast[] = $ground['east'] - $air['east'];
            $windNorth[] = $ground['north'] - $air['north'];
        }
        $east = self::median($windEast);
        $north = self::median($windNorth);
        $speed = sqrt($east * $east + $north * $north);
        if ($speed > 80.0) {
            return;
        }
        $toDirection = self::normalizeDegrees(rad2deg(atan2($east, $north)));
        $fromDirection = self::normalizeDegrees($toDirection + 180.0);
        $segments[] = array(
            'start_index' => (int)$run[0]['index'],
            'end_index' => (int)$run[count($run) - 1]['index'],
            'duration_seconds' => round($duration, 2),
            'estimated_tas_kt' => round($tasKt, 1),
            'wind_speed_kt' => round($speed, 1),
            'wind_direction_deg_true' => round($fromDirection, 0),
            'heading_rate_spread_dps' => round($rateSpread, 3),
        );
    }

    private function sampleHeadingForWind(array $sample): ?float
    {
        if (isset($sample['true_heading_deg']) && is_numeric($sample['true_heading_deg'])) {
            return (float)$sample['true_heading_deg'];
        }
        if (isset($sample['magnetic_heading_deg']) && is_numeric($sample['magnetic_heading_deg'])) {
            return (float)$sample['magnetic_heading_deg'];
        }
        return null;
    }

    /**
     * @return array{east:float,north:float}
     */
    private static function vectorFromTrack(float $degreesTrue, float $speedKt): array
    {
        $rad = deg2rad($degreesTrue);
        return array('east' => $speedKt * sin($rad), 'north' => $speedKt * cos($rad));
    }

    /**
     * @return array{direction_deg:int,speed_kt:int,source:string}|null
     */
    private function transcriptWindEvidence(array $recording): ?array
    {
        $text = strtolower((string)($recording['transcript_text'] ?? ''));
        if ($text === '') {
            return null;
        }
        if (preg_match('/\bwind\s+([0-3]?\d{2})\s*(?:at|@|\/)?\s*(\d{1,2})\b/i', $text, $m) === 1) {
            $direction = (int)$m[1];
            $speed = (int)$m[2];
            if ($direction >= 0 && $direction <= 360 && $speed >= 0 && $speed <= 80) {
                return array('direction_deg' => $direction, 'speed_kt' => $speed, 'source' => 'transcript_wind_readout');
            }
        }
        return null;
    }

    /**
     * @param list<array<string,mixed>> $samples
     * @return list<array<string,mixed>>
     */
    private function addEstimatedSlipSkid(array $samples, float $windowSeconds): array
    {
        $count = count($samples);
        if ($count === 0) {
            return $samples;
        }

        $left = 0;
        $right = 0;
        $halfWindow = $windowSeconds / 2.0;
        foreach ($samples as $index => $sample) {
            if (!isset($sample['ahrs_acceleration_y_g']) || !is_numeric($sample['ahrs_acceleration_y_g'])) {
                $samples[$index]['estimated_slip_skid_g'] = null;
                $samples[$index]['estimated_slip_skid_quality'] = 'unavailable';
                $samples[$index]['estimated_slip_skid_source'] = 'unavailable';
                continue;
            }

            $seconds = (float)$sample['seconds_since_start'];
            while ($left < $count && (float)$samples[$left]['seconds_since_start'] < $seconds - $halfWindow) {
                $left++;
            }
            while ($right + 1 < $count && (float)$samples[$right + 1]['seconds_since_start'] <= $seconds + $halfWindow) {
                $right++;
            }

            $values = array();
            for ($i = $left; $i <= $right; $i++) {
                if (isset($samples[$i]['ahrs_acceleration_y_g']) && is_numeric($samples[$i]['ahrs_acceleration_y_g'])) {
                    $values[] = (float)$samples[$i]['ahrs_acceleration_y_g'];
                }
            }
            if (!$values) {
                $samples[$index]['estimated_slip_skid_g'] = null;
                $samples[$index]['estimated_slip_skid_quality'] = 'unavailable';
                $samples[$index]['estimated_slip_skid_source'] = 'unavailable';
                continue;
            }

            $groundspeed = isset($sample['groundspeed_kt']) && is_numeric($sample['groundspeed_kt']) ? (float)$sample['groundspeed_kt'] : 0.0;
            $samples[$index]['estimated_slip_skid_g'] = round(max(-1.5, min(1.5, self::median($values))), 3);
            $samples[$index]['estimated_slip_skid_quality'] = $groundspeed >= 35.0 ? 'fair' : 'low';
            $samples[$index]['estimated_slip_skid_source'] = 'bno085_linear_acceleration_y';
        }

        return $samples;
    }

    /**
     * @param list<array<string,mixed>> $samples
     * @return array{setting_inhg:float,source:string,quality:string,sample_count:int,spread_inhg:float}|null
     */
    private function selectAltimeterSetting(array $recording, array $samples, array $options): ?array
    {
        $manual = isset($options['altimeter_setting_inhg']) && is_numeric($options['altimeter_setting_inhg'])
            ? self::normalizeAltimeterSettingInhg((float)$options['altimeter_setting_inhg'])
            : null;
        if ($manual !== null) {
            return array(
                'setting_inhg' => $manual,
                'source' => 'manual_reconstruct',
                'quality' => 'fair',
                'sample_count' => 0,
                'spread_inhg' => 0.0,
            );
        }

        $recordingSetting = isset($recording['altimeter_setting_inhg']) && is_numeric($recording['altimeter_setting_inhg'])
            ? self::normalizeAltimeterSettingInhg((float)$recording['altimeter_setting_inhg'])
            : null;
        if ($recordingSetting !== null) {
            $source = trim((string)($recording['altimeter_setting_source'] ?? 'app_logged'));
            return array(
                'setting_inhg' => $recordingSetting,
                'source' => $source !== '' ? $source : 'app_logged',
                'quality' => 'fair',
                'sample_count' => 0,
                'spread_inhg' => 0.0,
            );
        }

        return $this->stableAdsbAltimeterSetting($samples);
    }

    /**
     * @param list<array<string,mixed>> $samples
     * @return array{setting_inhg:float,source:string,quality:string,sample_count:int,spread_inhg:float}|null
     */
    private function stableAdsbAltimeterSetting(array $samples): ?array
    {
        $settings = array_values(array_filter(array_map(
            fn(array $sample): ?float => isset($sample['altimeter_setting_inhg']) && is_numeric($sample['altimeter_setting_inhg']) ? (float)$sample['altimeter_setting_inhg'] : null,
            $samples
        ), fn($value): bool => $value !== null));
        if (count($settings) < 3) {
            return null;
        }

        $median = self::median($settings);
        $spread = self::percentile(array_map(fn(float $value): float => abs($value - $median), $settings), 0.90);
        if ($spread > 0.08) {
            return null;
        }

        return array(
            'setting_inhg' => round($median, 2),
            'source' => 'adsb_altimeter_setting',
            'quality' => 'fair',
            'sample_count' => count($settings),
            'spread_inhg' => round($spread, 3),
        );
    }

    private function estimatedIndicatedAltitudeFt(float $trueAltitudeFt, float $airportElevationFt, ?float $oatC): float
    {
        if ($oatC === null) {
            return $trueAltitudeFt;
        }

        $heightAboveFieldFt = max(0.0, $trueAltitudeFt - $airportElevationFt);
        $isaFieldTempC = 15.0 - (2.0 * ($airportElevationFt / 1000.0));
        $isaDeviationC = $oatC - $isaFieldTempC;
        $trueToIndicatedFactor = max(0.75, min(1.35, 1.0 + (0.004 * $isaDeviationC)));
        return $airportElevationFt + ($heightAboveFieldFt / $trueToIndicatedFactor);
    }

    /**
     * @param list<array<string,mixed>> $samples
     * @return array{airport_elevation_ft:float,source:string,field_offset_ft:float,start_gps_altitude_ft:float,start_window:array<string,mixed>}|null
     */
    private function buildFieldAltitudeCalibration(array $recording, array $samples, array $options): ?array
    {
        $airport = $this->selectAirportElevation($recording, $samples, $options);
        $start = $this->stationaryStartAltitude($samples);
        if ($airport === null || $start === null) {
            return null;
        }

        $offset = (float)$airport['elevation_ft'] - (float)$start['gps_altitude_ft'];
        return array(
            'airport_elevation_ft' => (float)$airport['elevation_ft'],
            'source' => (string)$airport['source'],
            'field_offset_ft' => round($offset, 1),
            'start_gps_altitude_ft' => (float)$start['gps_altitude_ft'],
            'start_window' => $start,
        );
    }

    /**
     * @param list<array<string,mixed>> $samples
     * @return array{elevation_ft:float,source:string,airport_icao:?string}|null
     */
    private function selectAirportElevation(array $recording, array $samples, array $options): ?array
    {
        $manual = $this->optionFloat($options, array('airport_elevation_ft', 'field_elevation_ft'), -1500.0, 30000.0);
        if ($manual !== null) {
            return array('elevation_ft' => round($manual, 1), 'source' => 'manual_reconstruct', 'airport_icao' => null);
        }

        if (isset($recording['airport_elevation_ft']) && is_numeric($recording['airport_elevation_ft'])) {
            $elevation = (float)$recording['airport_elevation_ft'];
            if ($elevation >= -1500.0 && $elevation <= 30000.0) {
                $source = trim((string)($recording['airport_elevation_source'] ?? 'app_logged'));
                return array('elevation_ft' => round($elevation, 1), 'source' => $source !== '' ? $source : 'app_logged', 'airport_icao' => null);
            }
        }

        $homeAirport = $this->recordingHomeAirport($recording);
        if ($homeAirport !== null) {
            $airports = tv_adsb_airports();
            if (isset($airports[$homeAirport]['elev_ft'])) {
                return array(
                    'elevation_ft' => (float)$airports[$homeAirport]['elev_ft'],
                    'source' => 'aircraft_home_airport_' . $homeAirport,
                    'airport_icao' => $homeAirport,
                );
            }
        }

        $nearest = $this->nearestKnownAirportAtStart($samples, 5.0);
        if ($nearest !== null) {
            return array(
                'elevation_ft' => (float)$nearest['elevation_ft'],
                'source' => 'nearest_known_airport_' . (string)$nearest['icao'],
                'airport_icao' => (string)$nearest['icao'],
            );
        }

        return null;
    }

    private function recordingHomeAirport(array $recording): ?string
    {
        $aircraftId = (int)($recording['aircraft_id'] ?? 0);
        if ($aircraftId <= 0 || !$this->tablePresent('ipca_aircraft_devices')) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT home_airport FROM ipca_aircraft_devices WHERE id = ? LIMIT 1');
        $stmt->execute(array($aircraftId));
        $home = strtoupper(trim((string)($stmt->fetchColumn() ?: '')));
        if ($home === '') {
            return null;
        }
        $airports = tv_adsb_airports();
        return isset($airports[$home]) ? $home : null;
    }

    /**
     * @param list<array<string,mixed>> $samples
     * @return array{gps_altitude_ft:float,start_seconds:float,end_seconds:float,sample_count:int}|null
     */
    private function stationaryStartAltitude(array $samples): ?array
    {
        $window = array();
        foreach ($samples as $sample) {
            $seconds = (float)($sample['seconds_since_start'] ?? 0);
            if ($seconds > 120.0) {
                break;
            }
            if (!isset($sample['gps_altitude_ft']) || !is_numeric($sample['gps_altitude_ft'])) {
                continue;
            }
            $speed = isset($sample['groundspeed_kt']) && is_numeric($sample['groundspeed_kt']) ? (float)$sample['groundspeed_kt'] : 0.0;
            if ($speed > 1.0) {
                if (count($window) >= 3 && ((float)$window[count($window) - 1]['seconds_since_start'] - (float)$window[0]['seconds_since_start']) >= 8.0) {
                    break;
                }
                $window = array();
                continue;
            }
            $window[] = $sample;
            if (count($window) >= 3 && ((float)$window[count($window) - 1]['seconds_since_start'] - (float)$window[0]['seconds_since_start']) >= 10.0) {
                break;
            }
        }
        if (count($window) < 3) {
            $window = array_values(array_filter($samples, static fn(array $sample): bool => (float)($sample['seconds_since_start'] ?? 999) <= 30.0 && isset($sample['gps_altitude_ft']) && is_numeric($sample['gps_altitude_ft'])));
        }
        if (!$window) {
            return null;
        }
        $alts = array_map(fn(array $sample): float => (float)$sample['gps_altitude_ft'], $window);
        return array(
            'gps_altitude_ft' => round(self::median($alts), 1),
            'start_seconds' => round((float)$window[0]['seconds_since_start'], 3),
            'end_seconds' => round((float)$window[count($window) - 1]['seconds_since_start'], 3),
            'sample_count' => count($window),
        );
    }

    /**
     * @param list<array<string,mixed>> $samples
     * @return array{icao:string,elevation_ft:float,distance_nm:float}|null
     */
    private function nearestKnownAirportAtStart(array $samples, float $maxNm): ?array
    {
        $first = null;
        foreach ($samples as $sample) {
            if (isset($sample['latitude'], $sample['longitude']) && is_numeric($sample['latitude']) && is_numeric($sample['longitude'])) {
                $first = $sample;
                break;
            }
        }
        if ($first === null) {
            return null;
        }

        $best = null;
        foreach (tv_adsb_airports() as $icao => $airport) {
            $distance = self::distanceNm((float)$first['latitude'], (float)$first['longitude'], (float)$airport['lat'], (float)$airport['lon']);
            if ($distance <= $maxNm && ($best === null || $distance < (float)$best['distance_nm'])) {
                $best = array('icao' => (string)$icao, 'elevation_ft' => (float)$airport['elev_ft'], 'distance_nm' => round($distance, 3));
            }
        }
        return $best;
    }

    /**
     * @return array{oat_c:float,source:string}|null
     */
    private function selectOat(array $recording, array $options): ?array
    {
        $manual = $this->optionFloat($options, array('oat_c', 'temperature_c'), -80.0, 70.0);
        if ($manual !== null) {
            return array('oat_c' => round($manual, 1), 'source' => 'manual_reconstruct');
        }
        if (isset($recording['oat_c']) && is_numeric($recording['oat_c'])) {
            $oat = (float)$recording['oat_c'];
            if ($oat >= -80.0 && $oat <= 70.0) {
                $source = trim((string)($recording['oat_source'] ?? 'app_logged'));
                return array('oat_c' => round($oat, 1), 'source' => $source !== '' ? $source : 'app_logged');
            }
        }
        return null;
    }

    /**
     * @param list<string> $keys
     */
    private function optionFloat(array $options, array $keys, float $min, float $max): ?float
    {
        foreach ($keys as $key) {
            if (isset($options[$key]) && is_numeric($options[$key])) {
                $value = (float)$options[$key];
                if ($value >= $min && $value <= $max) {
                    return $value;
                }
            }
        }
        return null;
    }

    /**
     * @param list<array<string,mixed>> $samples
     * @return list<array<string,mixed>>
     */
    private function addEstimatedVerticalSpeed(array $samples, float $windowSeconds): array
    {
        $rawRates = array();
        $count = count($samples);
        $left = 0;
        $right = 0;
        $halfWindow = $windowSeconds / 2.0;

        foreach ($samples as $index => $sample) {
            if (!isset($sample['estimated_indicated_altitude_ft']) || !is_numeric($sample['estimated_indicated_altitude_ft'])) {
                $rawRates[$index] = null;
                continue;
            }
            $seconds = (float)$sample['seconds_since_start'];
            while ($left < $count && (float)$samples[$left]['seconds_since_start'] < $seconds - $halfWindow) {
                $left++;
            }
            while ($right + 1 < $count && (float)$samples[$right + 1]['seconds_since_start'] <= $seconds + $halfWindow) {
                $right++;
            }

            $firstIndex = $this->firstEstimatedAltitudeIndex($samples, $left, $right);
            $lastIndex = $this->lastEstimatedAltitudeIndex($samples, $left, $right);
            if ($firstIndex === null || $lastIndex === null || $lastIndex <= $firstIndex) {
                $rawRates[$index] = null;
                continue;
            }
            $first = $samples[$firstIndex];
            $last = $samples[$lastIndex];
            $dt = (float)$last['seconds_since_start'] - (float)$first['seconds_since_start'];
            if ($dt < 4.0) {
                $rawRates[$index] = null;
                continue;
            }
            $rawRates[$index] = (((float)$last['estimated_indicated_altitude_ft'] - (float)$first['estimated_indicated_altitude_ft']) / $dt) * 60.0;
        }

        $smoothLeft = 0;
        $smoothRight = 0;
        foreach ($samples as $index => $sample) {
            if ($rawRates[$index] === null) {
                continue;
            }
            $seconds = (float)$sample['seconds_since_start'];
            while ($smoothLeft < $count && (float)$samples[$smoothLeft]['seconds_since_start'] < $seconds - 2.0) {
                $smoothLeft++;
            }
            while ($smoothRight + 1 < $count && (float)$samples[$smoothRight + 1]['seconds_since_start'] <= $seconds + 2.0) {
                $smoothRight++;
            }

            $nearbyRates = array();
            for ($rateIndex = $smoothLeft; $rateIndex <= $smoothRight; $rateIndex++) {
                if ($rawRates[$rateIndex] === null) {
                    continue;
                }
                $nearbyRates[] = (float)$rawRates[$rateIndex];
            }
            if (!$nearbyRates) {
                continue;
            }
            $rate = max(-4000.0, min(4000.0, self::median($nearbyRates)));
            $samples[$index]['estimated_vertical_speed_fpm'] = round($rate, 1);
            $samples[$index]['vertical_speed_fpm'] = round($rate, 1);
            $samples[$index]['vertical_speed_source'] = 'estimated_baro';
            $samples[$index]['vertical_speed_quality'] = 'fair';
        }

        return $samples;
    }

    /**
     * @param list<array<string,mixed>> $samples
     */
    private function firstEstimatedAltitudeIndex(array $samples, int $left, int $right): ?int
    {
        for ($i = $left; $i <= $right; $i++) {
            if (isset($samples[$i]['estimated_indicated_altitude_ft']) && is_numeric($samples[$i]['estimated_indicated_altitude_ft'])) {
                return $i;
            }
        }
        return null;
    }

    /**
     * @param list<array<string,mixed>> $samples
     */
    private function lastEstimatedAltitudeIndex(array $samples, int $left, int $right): ?int
    {
        for ($i = $right; $i >= $left; $i--) {
            if (isset($samples[$i]['estimated_indicated_altitude_ft']) && is_numeric($samples[$i]['estimated_indicated_altitude_ft'])) {
                return $i;
            }
        }
        return null;
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function nearestBySeconds(array $rows, float $seconds, float $maxDistance): ?array
    {
        $best = null;
        $bestDist = $maxDistance;
        foreach ($rows as $row) {
            $dist = abs((float)$row['seconds'] - $seconds);
            if ($dist <= $bestDist) {
                $best = $row;
                $bestDist = $dist;
            }
            if ((float)$row['seconds'] > $seconds + $maxDistance) {
                break;
            }
        }
        return $best;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>|null
     */
    private function interpolateAdsbBySeconds(array $rows, float $seconds, float $maxGapSeconds): ?array
    {
        if (!$rows) {
            return null;
        }

        $before = null;
        $after = null;
        foreach ($rows as $row) {
            $rowSeconds = (float)$row['seconds'];
            if ($rowSeconds <= $seconds) {
                $before = $row;
                continue;
            }
            $after = $row;
            break;
        }

        if ($before === null) {
            return isset($after['seconds']) && abs((float)$after['seconds'] - $seconds) <= $maxGapSeconds ? $after : null;
        }
        if ($after === null) {
            return abs((float)$before['seconds'] - $seconds) <= $maxGapSeconds ? $before : null;
        }

        $beforeSeconds = (float)$before['seconds'];
        $afterSeconds = (float)$after['seconds'];
        $gap = max(0.001, $afterSeconds - $beforeSeconds);
        if ($gap > $maxGapSeconds) {
            return null;
        }
        $ratio = max(0.0, min(1.0, ($seconds - $beforeSeconds) / $gap));
        $nearest = ($seconds - $beforeSeconds) <= ($afterSeconds - $seconds) ? $before : $after;

        return array(
            'seconds' => $seconds,
            'timestamp' => $nearest['timestamp'] ?? '',
            'latitude' => self::lerpNullable($before['latitude'] ?? null, $after['latitude'] ?? null, $ratio),
            'longitude' => self::lerpNullable($before['longitude'] ?? null, $after['longitude'] ?? null, $ratio),
            'baro_altitude_ft' => self::lerpNullable($before['baro_altitude_ft'] ?? null, $after['baro_altitude_ft'] ?? null, $ratio),
            'vertical_speed_fpm' => self::lerpNullable($before['vertical_speed_fpm'] ?? null, $after['vertical_speed_fpm'] ?? null, $ratio),
            'groundspeed_kt' => self::lerpNullable($before['groundspeed_kt'] ?? null, $after['groundspeed_kt'] ?? null, $ratio),
            'track_deg' => self::lerpAngleNullable($before['track_deg'] ?? null, $after['track_deg'] ?? null, $ratio),
            'heading_deg' => self::lerpAngleNullable($before['heading_deg'] ?? null, $after['heading_deg'] ?? null, $ratio),
            'on_ground' => $nearest['on_ground'] ?? null,
            'altimeter_setting_inhg' => self::lerpNullable($before['altimeter_setting_inhg'] ?? null, $after['altimeter_setting_inhg'] ?? null, $ratio),
        );
    }

    /**
     * @param array<string,mixed> $recording
     * @param list<array<string,mixed>> $gps
     * @param list<array<string,mixed>> $ahrs
     * @param list<array<string,mixed>> $adsb
     * @return array<string,mixed>
     */
    private function buildSourceAlignment(array $recording, array $gps, array $ahrs, array $adsb): array
    {
        $duration = max(0.0, (float)($recording['duration_seconds'] ?? 0));
        $sources = array(
            'audio' => array(
                'duration_seconds' => round($duration, 3),
                'coverage_percent' => $duration > 0 ? 100.0 : 0.0,
            ),
            'gps' => $this->sourceMetrics($gps, $duration, 3.0),
            'ahrs' => $this->sourceMetrics($ahrs, $duration, 1.0),
            'adsb' => $this->sourceMetrics($adsb, $duration, 15.0),
        );

        $warnings = array();
        foreach (array('gps', 'ahrs', 'adsb') as $source) {
            $coverage = (float)($sources[$source]['coverage_percent'] ?? 0);
            if ($source !== 'adsb' && $coverage > 0 && $coverage < 90.0) {
                $warnings[] = strtoupper($source) . ' coverage below 90%.';
            }
            if ((int)($sources[$source]['large_gap_count'] ?? 0) > 0) {
                $warnings[] = strtoupper($source) . ' has ' . (int)$sources[$source]['large_gap_count'] . ' large gap(s).';
            }
        }
        if ((int)($sources['adsb']['sample_count'] ?? 0) === 0) {
            $warnings[] = 'ADS-B not available for this reconstruction.';
        }

        return array(
            'generated_at' => gmdate('c'),
            'recording_duration_seconds' => round($duration, 3),
            'sources' => $sources,
            'warnings' => $warnings,
        );
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function sourceMetrics(array $rows, float $duration, float $largeGapThresholdSeconds): array
    {
        if (!$rows) {
            return array(
                'sample_count' => 0,
                'first_seconds' => null,
                'last_seconds' => null,
                'coverage_seconds' => 0.0,
                'coverage_percent' => 0.0,
                'average_rate_hz' => null,
                'max_gap_seconds' => null,
                'large_gap_count' => 0,
                'first_utc' => null,
                'last_utc' => null,
            );
        }

        $seconds = array_map(fn(array $row): float => (float)$row['seconds'], $rows);
        sort($seconds, SORT_NUMERIC);
        $first = (float)$seconds[0];
        $last = (float)$seconds[count($seconds) - 1];
        $maxGap = 0.0;
        $largeGaps = 0;
        for ($i = 1; $i < count($seconds); $i++) {
            $gap = (float)$seconds[$i] - (float)$seconds[$i - 1];
            $maxGap = max($maxGap, $gap);
            if ($gap > $largeGapThresholdSeconds) {
                $largeGaps++;
            }
        }
        $coverage = max(0.0, $last - $first);

        return array(
            'sample_count' => count($rows),
            'first_seconds' => round($first, 3),
            'last_seconds' => round($last, 3),
            'coverage_seconds' => round($coverage, 3),
            'coverage_percent' => $duration > 0 ? round(min(100.0, ($coverage / $duration) * 100.0), 1) : 0.0,
            'average_rate_hz' => $coverage > 0 ? round(max(0, count($rows) - 1) / $coverage, 3) : null,
            'max_gap_seconds' => round($maxGap, 3),
            'large_gap_count' => $largeGaps,
            'first_utc' => (string)($rows[0]['timestamp'] ?? ''),
            'last_utc' => (string)($rows[count($rows) - 1]['timestamp'] ?? ''),
        );
    }

    /**
     * @param list<array<string,mixed>> $gps
     * @param list<array<string,mixed>> $ahrs
     * @return array<string,mixed>
     */
    private function buildAhrsCalibration(array $gps, array $ahrs): array
    {
        $calibration = array(
            'status' => 'not_available',
            'pitch_offset_deg' => 0.0,
            'roll_offset_deg' => 0.0,
            'magnetic_heading_offset_deg' => 0.0,
            'magnetic_heading_quality' => 'INVALID',
            'pitch_roll_quality' => 'INVALID',
            'source' => 'derived_from_raw_ahrs',
            'notes' => array(),
        );
        if (!$ahrs) {
            $calibration['notes'][] = 'No AHRS samples available.';
            return $calibration;
        }

        $groundWindow = $this->stableGroundWindow($gps, $ahrs);
        if ($groundWindow !== null) {
            $calibration['status'] = 'ready';
            $calibration['pitch_offset_deg'] = $groundWindow['pitch_offset_deg'];
            $calibration['roll_offset_deg'] = $groundWindow['roll_offset_deg'];
            $calibration['pitch_roll_quality'] = 'GOOD';
            $calibration['ground_window'] = $groundWindow;
            $calibration['notes'][] = 'Pitch/roll offsets derived from stable initial ground window.';
        } else {
            $calibration['status'] = 'partial';
            $calibration['pitch_roll_quality'] = 'LOW';
            $calibration['notes'][] = 'No stable 10 second ground window found; pitch/roll offsets left at zero.';
        }

        $hasManualHeadingCalibration = count(array_filter($ahrs, fn(array $sample): bool => isset($sample['compass_deviation_deg']) || isset($sample['magnetic_variation_deg']))) > 0;
        if ($hasManualHeadingCalibration) {
            $calibration['magnetic_heading_offset_deg'] = 0.0;
            $calibration['magnetic_heading_quality'] = 'LOW';
            $calibration['magnetic_heading_sample_count'] = 0;
            $calibration['magnetic_heading_spread_deg'] = null;
            $calibration['notes'][] = 'Manual iPad compass deviation/Variation present; backend did not apply an additional GPS-track magnetic heading offset.';
        } else {
            $headingCalibration = $this->magneticHeadingCalibration($gps, $ahrs, $calibration);
            $calibration['magnetic_heading_offset_deg'] = $headingCalibration['offset_deg'];
            $calibration['magnetic_heading_quality'] = $headingCalibration['quality'];
            $calibration['magnetic_heading_sample_count'] = $headingCalibration['sample_count'];
            $calibration['magnetic_heading_spread_deg'] = $headingCalibration['spread_deg'];
            $calibration['notes'][] = $headingCalibration['note'];
        }

        return $calibration;
    }

    /**
     * @param list<array<string,mixed>> $gps
     * @param list<array<string,mixed>> $ahrs
     * @return array<string,mixed>|null
     */
    private function stableGroundWindow(array $gps, array $ahrs): ?array
    {
        foreach ($ahrs as $startIndex => $startSample) {
            $start = (float)($startSample['seconds'] ?? 0);
            if ($start > 120.0) {
                break;
            }
            $window = array_values(array_filter($ahrs, fn(array $sample): bool => (float)($sample['seconds'] ?? -999) >= $start && (float)($sample['seconds'] ?? -999) <= $start + 10.0));
            if (count($window) < 8) {
                continue;
            }
            $end = (float)($window[count($window) - 1]['seconds'] ?? $start);
            if ($end - $start < 9.5) {
                continue;
            }

            $gpsWindow = array_values(array_filter($gps, fn(array $sample): bool => (float)($sample['seconds'] ?? -999) >= $start && (float)($sample['seconds'] ?? -999) <= $end));
            $maxSpeed = $gpsWindow ? max(array_map(fn(array $sample): float => (float)($sample['groundspeed_kt'] ?? 999), $gpsWindow)) : 0.0;
            if ($maxSpeed >= 1.0) {
                continue;
            }

            $pitches = array_values(array_filter(array_map(fn(array $sample): ?float => isset($sample['pitch_deg']) ? (float)$sample['pitch_deg'] : null, $window), fn($value): bool => $value !== null));
            $rolls = array_values(array_filter(array_map(fn(array $sample): ?float => isset($sample['roll_deg']) ? (float)$sample['roll_deg'] : null, $window), fn($value): bool => $value !== null));
            $accels = array_values(array_filter(array_map(fn(array $sample): ?float => isset($sample['acceleration_g']) ? (float)$sample['acceleration_g'] : null, $window), fn($value): bool => $value !== null));
            if (!$pitches || !$rolls || !$accels) {
                continue;
            }
            if ((max($pitches) - min($pitches)) > 3.0 || (max($rolls) - min($rolls)) > 3.0 || (max($accels) - min($accels)) > 0.12) {
                continue;
            }

            return array(
                'start_seconds' => round($start, 3),
                'end_seconds' => round($end, 3),
                'sample_count' => count($window),
                'pitch_offset_deg' => round(self::median($pitches), 3),
                'roll_offset_deg' => round(self::median($rolls), 3),
                'max_groundspeed_kt' => round($maxSpeed, 2),
                'pitch_range_deg' => round(max($pitches) - min($pitches), 3),
                'roll_range_deg' => round(max($rolls) - min($rolls), 3),
                'acceleration_range_g' => round(max($accels) - min($accels), 4),
            );
        }
        return null;
    }

    /**
     * @param list<array<string,mixed>> $gps
     * @param list<array<string,mixed>> $ahrs
     * @param array<string,mixed> $calibration
     * @return array{offset_deg:float,quality:string,sample_count:int,spread_deg:?float,note:string}
     */
    private function magneticHeadingCalibration(array $gps, array $ahrs, array $calibration): array
    {
        $deltas = array();
        foreach ($gps as $gpsSample) {
            $seconds = (float)($gpsSample['seconds'] ?? 0);
            $speed = (float)($gpsSample['groundspeed_kt'] ?? 0);
            $track = isset($gpsSample['track_deg']) ? (float)$gpsSample['track_deg'] : -1.0;
            if ($speed < 8.0 || $speed > 45.0 || $track < 0) {
                continue;
            }
            $ahrsNear = $this->nearestBySeconds($ahrs, $seconds, 1.5);
            if ($ahrsNear === null || !isset($ahrsNear['magnetic_heading_deg'])) {
                continue;
            }
            $roll = isset($ahrsNear['roll_deg']) ? (float)$ahrsNear['roll_deg'] - (float)($calibration['roll_offset_deg'] ?? 0) : 0.0;
            $pitch = isset($ahrsNear['pitch_deg']) ? (float)$ahrsNear['pitch_deg'] - (float)($calibration['pitch_offset_deg'] ?? 0) : 0.0;
            if (abs($roll) > 8.0 || abs($pitch) > 10.0) {
                continue;
            }
            $deltas[] = self::angleDelta((float)$ahrsNear['magnetic_heading_deg'], $track);
        }

        if (count($deltas) < 8) {
            return array(
                'offset_deg' => 0.0,
                'quality' => isset($ahrs[0]['magnetic_heading_deg']) ? 'LOW' : 'INVALID',
                'sample_count' => count($deltas),
                'spread_deg' => null,
                'note' => 'Magnetic heading is low confidence: not enough straight moving GPS-track samples for calibration.',
            );
        }

        $offset = self::circularMean($deltas);
        $spread = self::angleSpread($deltas, $offset);
        $quality = $spread <= 25.0 ? 'LOW' : 'INVALID';
        return array(
            'offset_deg' => round($offset, 3),
            'quality' => $quality,
            'sample_count' => count($deltas),
            'spread_deg' => round($spread, 3),
            'note' => $quality === 'LOW'
                ? 'Magnetic heading offset derived from GPS track, but remains LOW confidence because GPS track is not true heading.'
                : 'Magnetic heading calibration rejected: spread versus GPS track is too large.',
        );
    }

    /**
     * @param array<string,mixed> $recording
     * @param array<string,mixed>|null $gps
     * @param array<string,mixed>|null $ahrs
     * @param array<string,mixed>|null $adsb
     * @param array<string,mixed> $calibration
     * @return array<string,mixed>
     */
    private function mergeSample(array $recording, float $seconds, ?array $gps, ?array $ahrs, ?array $adsb, array $calibration): array
    {
        $sampleTime = $gps['timestamp'] ?? $ahrs['timestamp'] ?? $adsb['timestamp'] ?? $this->timestampFromRecordingStart($recording, $seconds);
        $gpsAltM = isset($gps['altitude_m']) ? (float)$gps['altitude_m'] : null;
        $gpsAltFt = $gpsAltM !== null ? $gpsAltM * 3.280839895 : null;
        $groundspeed = isset($gps['groundspeed_kt']) ? (float)$gps['groundspeed_kt'] : (isset($adsb['groundspeed_kt']) ? (float)$adsb['groundspeed_kt'] : null);
        $track = isset($gps['track_deg']) && (float)$gps['track_deg'] >= 0 ? (float)$gps['track_deg'] : (isset($adsb['track_deg']) ? (float)$adsb['track_deg'] : null);
        $pitchOffset = (float)($calibration['pitch_offset_deg'] ?? 0.0);
        $rollOffset = (float)($calibration['roll_offset_deg'] ?? 0.0);
        $headingOffset = (float)($calibration['magnetic_heading_offset_deg'] ?? 0.0);
        $pitch = isset($ahrs['pitch_deg']) ? (float)$ahrs['pitch_deg'] - $pitchOffset : null;
        $roll = isset($ahrs['roll_deg']) ? (float)$ahrs['roll_deg'] - $rollOffset : null;
        $yaw = isset($ahrs['yaw_deg']) ? (float)$ahrs['yaw_deg'] : null;
        $heading = isset($ahrs['magnetic_heading_deg']) ? self::normalizeDegrees((float)$ahrs['magnetic_heading_deg'] + $headingOffset) : (isset($adsb['heading_deg']) ? (float)$adsb['heading_deg'] : null);
        $adsbBaroAlt = isset($adsb['baro_altitude_ft']) ? (float)$adsb['baro_altitude_ft'] : null;
        $adsbVerticalSpeed = isset($adsb['vertical_speed_fpm']) ? (float)$adsb['vertical_speed_fpm'] : null;

        $row = array(
            'sample_time_utc' => $sampleTime,
            'seconds_since_start' => $seconds,
            'source_mask' => trim(($gps ? 'gps ' : '') . ($ahrs ? 'ahrs ' : '') . ($adsb ? 'adsb' : '')),
            'latitude' => $gps['latitude'] ?? $adsb['latitude'] ?? null,
            'longitude' => $gps['longitude'] ?? $adsb['longitude'] ?? null,
            'gps_altitude_m' => $gpsAltM,
            'gps_altitude_ft' => $gpsAltFt,
            'baro_altitude_ft' => null,
            'vertical_speed_fpm' => null,
            'adsb_baro_altitude_ft' => $adsbBaroAlt,
            'adsb_vertical_speed_fpm' => $adsbVerticalSpeed,
            'estimated_baro_altitude_ft' => null,
            'estimated_vertical_speed_fpm' => null,
            'field_calibrated_altitude_ft' => null,
            'field_calibrated_true_altitude_ft' => null,
            'estimated_indicated_altitude_ft' => null,
            'estimated_true_altitude_from_indicated_ft' => null,
            'altimeter_setting_inhg' => $adsb['altimeter_setting_inhg'] ?? null,
            'altimeter_setting_source' => 'unavailable',
            'airport_elevation_ft' => null,
            'airport_elevation_source' => 'unavailable',
            'field_altitude_offset_ft' => null,
            'oat_c' => null,
            'oat_source' => 'unavailable',
            'altitude_source' => $gpsAltFt !== null ? 'gps' : 'unavailable',
            'altitude_quality' => $gpsAltFt !== null ? 'good' : 'unavailable',
            'vertical_speed_source' => 'unavailable',
            'vertical_speed_quality' => 'unavailable',
            'estimated_slip_skid_g' => null,
            'estimated_slip_skid_quality' => 'unavailable',
            'estimated_slip_skid_source' => 'unavailable',
            'ahrs_acceleration_x_g' => isset($ahrs['acceleration_x_g']) ? (float)$ahrs['acceleration_x_g'] : null,
            'ahrs_acceleration_y_g' => isset($ahrs['acceleration_y_g']) ? (float)$ahrs['acceleration_y_g'] : null,
            'ahrs_acceleration_z_g' => isset($ahrs['acceleration_z_g']) ? (float)$ahrs['acceleration_z_g'] : null,
            'groundspeed_kt' => $groundspeed,
            'magnetic_track_deg' => $track,
            'true_track_deg' => $track,
            'pitch_deg' => $pitch,
            'roll_deg' => $roll,
            'yaw_deg' => $yaw,
            'magnetic_heading_deg' => $heading,
            'true_heading_deg' => $ahrs['true_heading_deg'] ?? null,
            'acceleration_g' => isset($ahrs['acceleration_g']) ? (float)$ahrs['acceleration_g'] : null,
            'wind_direction_deg' => null,
            'wind_speed_kt' => null,
            'estimated_wind_speed_kt' => null,
            'estimated_wind_direction_deg_true' => null,
            'estimated_wind_quality' => 'unavailable',
            'estimated_wind_source' => 'unavailable',
            'estimated_tas_kt' => null,
            'wind_estimation_method' => 'unavailable',
            'heading_bug_deg' => null,
            'altitude_bug_ft' => null,
            'autopilot_status' => null,
        );
        $row['g3x_row'] = array();
        return $row;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalizeGPS(array $row): array
    {
        return array(
            'seconds' => isset($row['secondsSinceRecordingStart']) ? (float)$row['secondsSinceRecordingStart'] : null,
            'timestamp' => (string)($row['timestamp'] ?? ''),
            'latitude' => isset($row['latitude']) ? (float)$row['latitude'] : null,
            'longitude' => isset($row['longitude']) ? (float)$row['longitude'] : null,
            'altitude_m' => isset($row['altitude']) ? (float)$row['altitude'] : null,
            'groundspeed_kt' => isset($row['speedKnots']) ? (float)$row['speedKnots'] : null,
            'track_deg' => isset($row['course']) ? (float)$row['course'] : null,
        );
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalizeAHRS(array $row): array
    {
        $rawPitch = isset($row['pitch']) ? (float)$row['pitch'] : null;
        $rawRoll = isset($row['roll']) ? (float)$row['roll'] : null;
        $pitch = isset($row['calibratedPitch']) ? (float)$row['calibratedPitch'] : ($rawPitch !== null ? -$rawPitch : null);
        $roll = isset($row['calibratedRoll']) ? (float)$row['calibratedRoll'] : $rawRoll;
        $magneticHeading = isset($row['correctedMagneticHeading']) ? (float)$row['correctedMagneticHeading'] : (isset($row['magneticHeading']) ? (float)$row['magneticHeading'] : null);
        return array(
            'seconds' => isset($row['secondsSinceRecordingStart']) ? (float)$row['secondsSinceRecordingStart'] : null,
            'timestamp' => (string)($row['timestamp'] ?? ''),
            'pitch_deg' => $pitch,
            'roll_deg' => $roll,
            'yaw_deg' => isset($row['yaw']) ? (float)$row['yaw'] : null,
            'acceleration_g' => isset($row['acceleration']) ? (float)$row['acceleration'] : null,
            'acceleration_x_g' => isset($row['accelerationX']) ? (float)$row['accelerationX'] / 9.80665 : null,
            'acceleration_y_g' => isset($row['accelerationY']) ? (float)$row['accelerationY'] / 9.80665 : null,
            'acceleration_z_g' => isset($row['accelerationZ']) ? (float)$row['accelerationZ'] / 9.80665 : null,
            'magnetic_heading_deg' => $magneticHeading,
            'true_heading_deg' => isset($row['trueHeading']) ? (float)$row['trueHeading'] : null,
            'raw_pitch_deg' => $rawPitch,
            'raw_roll_deg' => $rawRoll,
            'compass_deviation_deg' => isset($row['compassDeviation']) ? (float)$row['compassDeviation'] : null,
            'magnetic_variation_deg' => isset($row['magneticVariation']) ? (float)$row['magneticVariation'] : null,
        );
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalizeAdsbOwnship(array $row): array
    {
        return array(
            'seconds' => isset($row['seconds_since_start']) ? (float)$row['seconds_since_start'] : null,
            'timestamp' => (string)($row['sample_time_utc'] ?? ''),
            'latitude' => isset($row['latitude']) ? (float)$row['latitude'] : null,
            'longitude' => isset($row['longitude']) ? (float)$row['longitude'] : null,
            'baro_altitude_ft' => isset($row['baro_altitude_ft']) ? (float)$row['baro_altitude_ft'] : null,
            'vertical_speed_fpm' => isset($row['vertical_speed_fpm']) ? (float)$row['vertical_speed_fpm'] : null,
            'groundspeed_kt' => isset($row['groundspeed_kt']) ? (float)$row['groundspeed_kt'] : null,
            'track_deg' => isset($row['track_deg']) ? (float)$row['track_deg'] : null,
            'heading_deg' => isset($row['heading_deg']) ? (float)$row['heading_deg'] : null,
            'on_ground' => isset($row['on_ground']) ? (bool)$row['on_ground'] : null,
            'altimeter_setting_inhg' => isset($row['altimeter_setting_inhg']) ? (float)$row['altimeter_setting_inhg'] : null,
        );
    }

    /**
     * @param list<array<string,mixed>> $samples
     * @return array{phases:list<array<string,mixed>>,events:list<array<string,mixed>>}
     */
    private function detectTimeline(array $recording, array $samples): array
    {
        $duration = max((float)($recording['duration_seconds'] ?? 0), (float)($samples[count($samples) - 1]['seconds_since_start'] ?? 0));
        $moving = array_values(array_filter($samples, fn(array $s): bool => (float)($s['groundspeed_kt'] ?? 0) >= 3.0));
        $takeoff = array_values(array_filter($samples, fn(array $s): bool => (float)($s['groundspeed_kt'] ?? 0) >= 25.0));
        $airborne = array_values(array_filter($samples, fn(array $s): bool => (float)($s['groundspeed_kt'] ?? 0) >= 35.0 || abs((float)($s['pitch_deg'] ?? 0)) >= 8.0));

        $phases = array();
        $events = array();
        $order = 0;
        $this->addPhase($phases, 'Preflight', $order++, 0.0, $moving ? (float)$moving[0]['seconds_since_start'] : min($duration, 60.0), 0.55, array('source' => 'initial idle period'));
        if ($moving) {
            $taxiStart = (float)$moving[0]['seconds_since_start'];
            $takeoffStart = $takeoff ? (float)$takeoff[0]['seconds_since_start'] : null;
            $this->addEvent($events, 'Taxi Begin', 'Taxi Out', $taxiStart, null, 0.65, array('groundspeed_kt' => $moving[0]['groundspeed_kt'] ?? null), 'Movement above 3 kt.');
            $this->addPhase($phases, 'Taxi Out', $order++, $taxiStart, $takeoffStart ?? min($duration, $taxiStart + 600.0), 0.60, array('source' => 'GPS groundspeed'));
        }
        if ($takeoff) {
            $takeoffStart = (float)$takeoff[0]['seconds_since_start'];
            $this->addEvent($events, 'Takeoff Roll', 'Takeoff', $takeoffStart, null, 0.60, array('groundspeed_kt' => $takeoff[0]['groundspeed_kt'] ?? null), 'Groundspeed crossed 25 kt.');
            $rotation = $this->firstSampleAfter($samples, $takeoffStart, fn(array $s): bool => (float)($s['pitch_deg'] ?? 0) >= 7.0);
            if ($rotation) {
                $this->addEvent($events, 'Rotation', 'Takeoff', (float)$rotation['seconds_since_start'], null, 0.55, array('pitch_deg' => $rotation['pitch_deg'] ?? null), 'Pitch crossed 7 degrees.');
            }
            $liftoff = $airborne ? $airborne[0] : null;
            if ($liftoff) {
                $this->addEvent($events, 'Liftoff', 'Initial Climb', (float)$liftoff['seconds_since_start'], null, 0.50, array('groundspeed_kt' => $liftoff['groundspeed_kt'] ?? null, 'pitch_deg' => $liftoff['pitch_deg'] ?? null), 'GPS/AHRS airborne heuristic.');
            }
            $this->addPhase($phases, 'Takeoff', $order++, $takeoffStart, min($duration, $takeoffStart + 120.0), 0.55, array('source' => 'GPS/AHRS heuristic'));
        }

        $steepLeft = $this->detectSteepTurn($samples, -45.0, 'Possible Steep Turn Left');
        if ($steepLeft) {
            $this->addEvent($events, 'Possible Steep Turn Left', 'Turn', $steepLeft['start'], $steepLeft['end'], 0.60, $steepLeft['evidence'], 'Detected only; no correctness evaluation.');
        }
        $steepRight = $this->detectSteepTurn($samples, 45.0, 'Possible Steep Turn Right');
        if ($steepRight) {
            $this->addEvent($events, 'Possible Steep Turn Right', 'Turn', $steepRight['start'], $steepRight['end'], 0.60, $steepRight['evidence'], 'Detected only; no correctness evaluation.');
        }
        $stall = $this->detectPowerOffStall($samples);
        if ($stall) {
            $this->addEvent($events, 'Possible Power-Off Stall', 'Maneuvering', $stall['start'], $stall['end'], 0.45, $stall['evidence'], 'Detected only; no correctness evaluation.');
        }

        $lastMoving = $moving ? (float)$moving[count($moving) - 1]['seconds_since_start'] : null;
        if ($lastMoving !== null && $lastMoving < $duration) {
            $this->addPhase($phases, 'Taxi Back / Stop', $order++, max(0.0, $lastMoving - 120.0), $duration, 0.45, array('source' => 'end of movement'));
            $this->addEvent($events, 'Full Stop', 'Taxi Back', $lastMoving, null, 0.50, array(), 'Last detected movement near end of recording.');
        }
        if (count($phases) === 1 && $duration > 0) {
            $this->addPhase($phases, 'On Block / Ground Recording', $order++, 0.0, $duration, 0.50, array('source' => 'no movement detected'));
        }

        return array('phases' => $phases, 'events' => $events);
    }

    /**
     * @param list<array<string,mixed>> $samples
     */
    private function firstSampleAfter(array $samples, float $after, callable $predicate): ?array
    {
        foreach ($samples as $sample) {
            if ((float)$sample['seconds_since_start'] < $after) {
                continue;
            }
            if ($predicate($sample)) {
                return $sample;
            }
        }
        return null;
    }

    /**
     * @param list<array<string,mixed>> $samples
     * @return array<string,mixed>|null
     */
    private function detectSteepTurn(array $samples, float $threshold, string $label): ?array
    {
        $run = array();
        foreach ($samples as $sample) {
            $roll = isset($sample['roll_deg']) ? (float)$sample['roll_deg'] : 0.0;
            $match = $threshold < 0 ? $roll <= $threshold : $roll >= $threshold;
            if ($match) {
                $run[] = $sample;
                continue;
            }
            if (count($run) >= 3) {
                return $this->eventRun($run, $label);
            }
            $run = array();
        }
        return count($run) >= 3 ? $this->eventRun($run, $label) : null;
    }

    /**
     * @param list<array<string,mixed>> $run
     * @return array<string,mixed>
     */
    private function eventRun(array $run, string $label): array
    {
        $rolls = array_map(fn(array $s): float => (float)($s['roll_deg'] ?? 0), $run);
        return array(
            'start' => (float)$run[0]['seconds_since_start'],
            'end' => (float)$run[count($run) - 1]['seconds_since_start'],
            'evidence' => array('label' => $label, 'max_abs_roll_deg' => max(array_map('abs', $rolls))),
        );
    }

    /**
     * @param list<array<string,mixed>> $samples
     * @return array<string,mixed>|null
     */
    private function detectPowerOffStall(array $samples): ?array
    {
        $candidates = array_values(array_filter($samples, static function (array $s): bool {
            return (float)($s['pitch_deg'] ?? 0) >= 12.0 && (float)($s['groundspeed_kt'] ?? 999) <= 55.0;
        }));
        if (count($candidates) < 3) {
            return null;
        }
        return array(
            'start' => (float)$candidates[0]['seconds_since_start'],
            'end' => (float)$candidates[min(count($candidates) - 1, 4)]['seconds_since_start'],
            'evidence' => array('pitch_deg' => $candidates[0]['pitch_deg'] ?? null, 'groundspeed_kt' => $candidates[0]['groundspeed_kt'] ?? null),
        );
    }

    /**
     * @param list<array<string,mixed>> $phases
     */
    private function addPhase(array &$phases, string $phase, int $order, float $start, float $end, float $confidence, array $summary): void
    {
        if ($end < $start) {
            $end = $start;
        }
        $phases[] = array(
            'phase' => $phase,
            'phase_order' => $order,
            'start_seconds' => round($start, 3),
            'end_seconds' => round($end, 3),
            'confidence' => $confidence,
            'summary' => $summary,
        );
    }

    /**
     * @param list<array<string,mixed>> $events
     */
    private function addEvent(array &$events, string $type, string $phase, float $start, ?float $end, float $confidence, array $evidence, string $notes): void
    {
        $events[] = array(
            'event_type' => $type,
            'phase' => $phase,
            'start_seconds' => round($start, 3),
            'end_seconds' => $end !== null ? round($end, 3) : null,
            'confidence' => $confidence,
            'evidence' => $evidence,
            'notes' => $notes,
        );
    }

    /**
     * @param list<array<string,mixed>> $samples
     * @param list<array<string,mixed>> $phases
     * @param list<array<string,mixed>> $events
     * @return array<string,mixed>
     */
    private function buildSummary(array $recording, array $samples, array $phases, array $events): array
    {
        $alts = array_values(array_filter(array_map(fn(array $s): ?float => isset($s['gps_altitude_ft']) ? (float)$s['gps_altitude_ft'] : (isset($s['baro_altitude_ft']) ? (float)$s['baro_altitude_ft'] : null), $samples), fn($v): bool => $v !== null));
        $speeds = array_values(array_filter(array_map(fn(array $s): ?float => isset($s['groundspeed_kt']) ? (float)$s['groundspeed_kt'] : null, $samples), fn($v): bool => $v !== null));
        $rolls = array_values(array_filter(array_map(fn(array $s): ?float => isset($s['roll_deg']) ? abs((float)$s['roll_deg']) : null, $samples), fn($v): bool => $v !== null));
        $pitches = array_values(array_filter(array_map(fn(array $s): ?float => isset($s['pitch_deg']) ? abs((float)$s['pitch_deg']) : null, $samples), fn($v): bool => $v !== null));

        return array(
            'recording_uid' => (string)($recording['recording_uid'] ?? ''),
            'generated_at' => gmdate('c'),
            'sample_count' => count($samples),
            'phase_count' => count($phases),
            'event_count' => count($events),
            'max_altitude_ft' => $alts ? max($alts) : null,
            'max_groundspeed_kt' => $speeds ? max($speeds) : null,
            'max_bank_deg' => $rolls ? max($rolls) : null,
            'max_pitch_deg' => $pitches ? max($pitches) : null,
            'detected_steep_turns' => count(array_filter($events, fn(array $e): bool => str_contains((string)$e['event_type'], 'Steep Turn'))),
            'detected_stall_events' => count(array_filter($events, fn(array $e): bool => str_contains((string)$e['event_type'], 'Stall'))),
            'off_block_time' => $this->eventTime($events, 'Taxi Begin'),
            'takeoff_time' => $this->eventTime($events, 'Takeoff Roll'),
            'liftoff_time' => $this->eventTime($events, 'Liftoff'),
            'touchdown_time' => $this->eventTime($events, 'Touchdown'),
            'full_stop_time' => $this->eventTime($events, 'Full Stop'),
            'adsb_status' => (string)($recording['adsb_status'] ?? 'not_started'),
            'adsb_sample_count' => $this->countRows(self::ADSB_OWNSHIP_TABLE, (int)($recording['id'] ?? 0)),
            'source_alignment' => $this->lastSourceAlignment,
            'ahrs_calibration' => $this->lastAhrsCalibration,
            'derived_replay_values' => array(
                'gps_altitude_primary' => true,
                'estimated_indicated_altitude_samples' => count(array_filter($samples, fn(array $s): bool => isset($s['estimated_indicated_altitude_ft']) && $s['estimated_indicated_altitude_ft'] !== null)),
                'estimated_vertical_speed_samples' => count(array_filter($samples, fn(array $s): bool => isset($s['estimated_vertical_speed_fpm']) && $s['estimated_vertical_speed_fpm'] !== null)),
                'field_calibrated_altitude_samples' => count(array_filter($samples, fn(array $s): bool => isset($s['field_calibrated_altitude_ft']) && $s['field_calibrated_altitude_ft'] !== null)),
                'airport_elevation_ft' => $samples && isset($samples[0]['airport_elevation_ft']) ? $samples[0]['airport_elevation_ft'] : null,
                'airport_elevation_source' => (string)($samples[0]['airport_elevation_source'] ?? 'unavailable'),
                'field_altitude_offset_ft' => $samples && isset($samples[0]['field_altitude_offset_ft']) ? $samples[0]['field_altitude_offset_ft'] : null,
                'altimeter_setting_source' => (string)($samples[0]['altimeter_setting_source'] ?? 'unavailable'),
                'altimeter_setting_inhg' => $samples && isset($samples[0]['altimeter_setting_inhg']) ? $samples[0]['altimeter_setting_inhg'] : null,
                'oat_c' => $samples && isset($samples[0]['oat_c']) ? $samples[0]['oat_c'] : null,
                'oat_source' => (string)($samples[0]['oat_source'] ?? 'unavailable'),
                'estimated_slip_skid_samples' => count(array_filter($samples, fn(array $s): bool => isset($s['estimated_slip_skid_g']) && $s['estimated_slip_skid_g'] !== null)),
                'slip_skid_status' => count(array_filter($samples, fn(array $s): bool => isset($s['estimated_slip_skid_g']) && $s['estimated_slip_skid_g'] !== null)) > 0 ? 'estimated_from_bno085_lateral_acceleration' : 'unavailable_until_lateral_acceleration_is_recorded',
                'estimated_wind_samples' => count(array_filter($samples, fn(array $s): bool => isset($s['estimated_wind_speed_kt']) && $s['estimated_wind_speed_kt'] !== null)),
                'wind_status' => count(array_filter($samples, fn(array $s): bool => isset($s['estimated_wind_speed_kt']) && $s['estimated_wind_speed_kt'] !== null)) > 0 ? 'experimental_stable_turn_estimate' : 'unavailable_no_stable_turn_segment',
                'notes' => 'GPS altitude remains raw geometric altitude. Field-calibrated true altitude is GPS shifted to airport elevation. Estimated indicated altitude applies the hot/cold day approximation from OAT.',
            ),
            'heading_replay_policy' => array(
                'primary_source' => 'gps_track_when_groundspeed_at_least_5kt',
                'fallback_source' => 'calibrated_magnetic_heading_low_confidence',
                'notes' => 'AHRS magnetic heading remains low confidence until BNO085 calibration and cockpit interference are validated.',
            ),
        );
    }

    /**
     * @param list<array<string,mixed>> $events
     */
    private function eventTime(array $events, string $type): ?float
    {
        foreach ($events as $event) {
            if ((string)$event['event_type'] === $type) {
                return (float)$event['start_seconds'];
            }
        }
        return null;
    }

    /**
     * @param list<array<string,mixed>> $samples
     */
    private function storeSamples(int $recordingId, array $samples): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO ' . self::SAMPLE_TABLE . ' (
                recording_id, sample_index, sample_time_utc, seconds_since_start, source_mask,
                latitude, longitude, gps_altitude_m, gps_altitude_ft, baro_altitude_ft, vertical_speed_fpm,
                adsb_baro_altitude_ft, adsb_vertical_speed_fpm, estimated_baro_altitude_ft,
                estimated_vertical_speed_fpm, field_calibrated_altitude_ft, altimeter_setting_inhg,
                field_calibrated_true_altitude_ft, estimated_indicated_altitude_ft,
                estimated_true_altitude_from_indicated_ft, altimeter_setting_source,
                airport_elevation_ft, airport_elevation_source,
                field_altitude_offset_ft, oat_c, oat_source,
                altitude_source, altitude_quality, vertical_speed_source, vertical_speed_quality,
                estimated_slip_skid_g, estimated_slip_skid_quality, estimated_slip_skid_source,
                ahrs_acceleration_x_g, ahrs_acceleration_y_g, ahrs_acceleration_z_g,
                groundspeed_kt, magnetic_track_deg, true_track_deg, pitch_deg, roll_deg, yaw_deg,
                magnetic_heading_deg, true_heading_deg, acceleration_g, wind_direction_deg, wind_speed_kt,
                estimated_wind_speed_kt, estimated_wind_direction_deg_true, estimated_wind_quality,
                estimated_wind_source, estimated_tas_kt, wind_estimation_method,
                heading_bug_deg, altitude_bug_ft, autopilot_status, g3x_row_json, created_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP
            )
        ');
        foreach ($samples as $sample) {
            $stmt->execute(array(
                $recordingId,
                (int)$sample['sample_index'],
                self::mysqlDateTimeMillis((string)($sample['sample_time_utc'] ?? '')),
                (float)$sample['seconds_since_start'],
                (string)$sample['source_mask'],
                $sample['latitude'],
                $sample['longitude'],
                $sample['gps_altitude_m'],
                $sample['gps_altitude_ft'],
                $sample['baro_altitude_ft'],
                $sample['vertical_speed_fpm'],
                $sample['adsb_baro_altitude_ft'],
                $sample['adsb_vertical_speed_fpm'],
                $sample['estimated_baro_altitude_ft'],
                $sample['estimated_vertical_speed_fpm'],
                $sample['field_calibrated_altitude_ft'],
                $sample['altimeter_setting_inhg'],
                $sample['field_calibrated_true_altitude_ft'],
                $sample['estimated_indicated_altitude_ft'],
                $sample['estimated_true_altitude_from_indicated_ft'],
                $sample['altimeter_setting_source'],
                $sample['airport_elevation_ft'],
                $sample['airport_elevation_source'],
                $sample['field_altitude_offset_ft'],
                $sample['oat_c'],
                $sample['oat_source'],
                $sample['altitude_source'],
                $sample['altitude_quality'],
                $sample['vertical_speed_source'],
                $sample['vertical_speed_quality'],
                $sample['estimated_slip_skid_g'],
                $sample['estimated_slip_skid_quality'],
                $sample['estimated_slip_skid_source'],
                $sample['ahrs_acceleration_x_g'],
                $sample['ahrs_acceleration_y_g'],
                $sample['ahrs_acceleration_z_g'],
                $sample['groundspeed_kt'],
                $sample['magnetic_track_deg'],
                $sample['true_track_deg'],
                $sample['pitch_deg'],
                $sample['roll_deg'],
                $sample['yaw_deg'],
                $sample['magnetic_heading_deg'],
                $sample['true_heading_deg'],
                $sample['acceleration_g'],
                $sample['wind_direction_deg'],
                $sample['wind_speed_kt'],
                $sample['estimated_wind_speed_kt'],
                $sample['estimated_wind_direction_deg_true'],
                $sample['estimated_wind_quality'],
                $sample['estimated_wind_source'],
                $sample['estimated_tas_kt'],
                $sample['wind_estimation_method'],
                $sample['heading_bug_deg'],
                $sample['altitude_bug_ft'],
                $sample['autopilot_status'],
                json_encode($sample['g3x_row'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ));
        }
    }

    /**
     * @param list<array<string,mixed>> $phases
     */
    private function storePhases(int $recordingId, array $phases): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO ' . self::PHASE_TABLE . ' (recording_id, phase, phase_order, start_seconds, end_seconds, confidence, summary_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
        foreach ($phases as $phase) {
            $stmt->execute(array($recordingId, $phase['phase'], $phase['phase_order'], $phase['start_seconds'], $phase['end_seconds'], $phase['confidence'], json_encode($phase['summary'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
        }
    }

    /**
     * @param list<array<string,mixed>> $events
     */
    private function storeEvents(int $recordingId, array $events): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO ' . self::EVENT_TABLE . ' (recording_id, event_type, phase, start_seconds, end_seconds, confidence, evidence_json, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
        foreach ($events as $event) {
            $stmt->execute(array($recordingId, $event['event_type'], $event['phase'], $event['start_seconds'], $event['end_seconds'], $event['confidence'], json_encode($event['evidence'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $event['notes']));
        }
    }

    private function ensureAdsbScaffold(array $recording): void
    {
        $recordingId = (int)($recording['id'] ?? 0);
        if ($recordingId <= 0 || !$this->tablePresent(self::ADSB_TABLE)) {
            return;
        }
        $hex = strtolower(trim((string)($recording['aircraft_adsb_hex'] ?? '')));
        $status = $hex !== '' ? 'not_started' : 'not_available';
        $stmt = $this->pdo->prepare('INSERT INTO ' . self::ADSB_TABLE . ' (recording_id, status, aircraft_hex, created_at, updated_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE status = IF(status = \'not_started\', VALUES(status), status), aircraft_hex = VALUES(aircraft_hex), updated_at = CURRENT_TIMESTAMP');
        $stmt->execute(array($recordingId, $status, $hex));
        $actual = $this->pdo->prepare('SELECT status FROM ' . self::ADSB_TABLE . ' WHERE recording_id = ? LIMIT 1');
        $actual->execute(array($recordingId));
        $actualStatus = (string)($actual->fetchColumn() ?: $status);
        $this->pdo->prepare('UPDATE ' . self::RECORDINGS_TABLE . ' SET adsb_status = ? WHERE id = ?')->execute(array($actualStatus, $recordingId));
    }

    private function tablePresent(string $table): bool
    {
        $stmt = $this->pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute(array($table));
        return $stmt->fetchColumn() !== false;
    }

    private function columnPresent(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare('
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ');
        $stmt->execute(array($table, $column));
        return (int)$stmt->fetchColumn() > 0;
    }

    private function countRows(string $table, int $recordingId): int
    {
        if (!$this->tablePresent($table)) {
            return 0;
        }
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE recording_id = ?');
        $stmt->execute(array($recordingId));
        return (int)$stmt->fetchColumn();
    }

    /**
     * @param array<string,mixed> $sample
     * @return array<string,string>
     */
    private function buildG3XRow(array $recording, array $sample): array
    {
        $row = array_fill_keys(self::G3X_COLUMNS, '');
        $timestamp = (string)($sample['sample_time_utc'] ?? '');
        $dt = self::dateTime($timestamp);
        if ($dt !== null) {
            $row['Date (yyyy-mm-dd)'] = $dt->format('Y-m-d');
            $row['Time (hh:mm:ss)'] = $dt->format('H:i:s');
            $row['UTC Time (hh:mm:ss)'] = $dt->format('H:i:s');
            $row['UTC Offset (hh:mm)'] = '+00:00';
        }
        $row['Latitude (deg)'] = self::fmt($sample['latitude'] ?? null, 7, true);
        $row['Longitude'] = self::fmt($sample['longitude'] ?? null, 7, true);
        $row['GPS Altitude (ft)'] = self::fmt($sample['gps_altitude_ft'] ?? null, 0);
        $row['GPS Fix Status'] = ($sample['latitude'] ?? null) !== null ? '3D' : '';
        $row['GPS Ground Speed (kt)'] = self::fmt($sample['groundspeed_kt'] ?? null, 1);
        $row['GPS Ground Track (deg)'] = self::fmt($sample['magnetic_track_deg'] ?? null, 0);
        $row['Magnetic Heading (deg)'] = self::fmt($sample['magnetic_heading_deg'] ?? null, 0);
        $row['Pressure Altitude (ft)'] = self::fmt($sample['estimated_indicated_altitude_ft'] ?? null, 0);
        $row['Baro Altitude (ft)'] = self::fmt($sample['estimated_indicated_altitude_ft'] ?? null, 0);
        $row['Vertical Speed (ft/min)'] = self::fmt($sample['estimated_vertical_speed_fpm'] ?? null, 0);
        $row['Pitch (deg)'] = self::fmt($sample['pitch_deg'] ?? null, 1);
        $row['Roll (deg)'] = self::fmt($sample['roll_deg'] ?? null, 1);
        $row['Lateral Acceleration (G)'] = self::fmt($sample['estimated_slip_skid_g'] ?? null, 3);
        $row['Normal Acceleration (G)'] = self::fmt($sample['acceleration_g'] ?? null, 3);
        $row['AHRS/Mag 1 Status'] = ($sample['pitch_deg'] ?? null) !== null ? 'OK' : '';
        $row['Baro Setting (inch Hg)'] = self::fmt($sample['altimeter_setting_inhg'] ?? null, 2);
        $row['Outside Air Temp (deg C)'] = self::fmt($sample['oat_c'] ?? null, 1);
        $row['Wind Speed (kt)'] = self::fmt($sample['wind_speed_kt'] ?? null, 1);
        $row['Wind Direction (deg)'] = self::fmt($sample['wind_direction_deg'] ?? null, 0);
        return $row;
    }

    private static function fmt(mixed $value, int $decimals, bool $signed = false): string
    {
        if (!is_numeric($value)) {
            return '';
        }
        $prefix = $signed && (float)$value >= 0 ? '+' : '';
        return $prefix . number_format((float)$value, $decimals, '.', '');
    }

    private static function lerpNullable(mixed $a, mixed $b, float $ratio): ?float
    {
        if (!is_numeric($a) && !is_numeric($b)) {
            return null;
        }
        if (!is_numeric($a)) {
            return (float)$b;
        }
        if (!is_numeric($b)) {
            return (float)$a;
        }
        return (float)$a + ((float)$b - (float)$a) * $ratio;
    }

    private static function lerpAngleNullable(mixed $a, mixed $b, float $ratio): ?float
    {
        if (!is_numeric($a) && !is_numeric($b)) {
            return null;
        }
        if (!is_numeric($a)) {
            return self::normalizeDegrees((float)$b);
        }
        if (!is_numeric($b)) {
            return self::normalizeDegrees((float)$a);
        }
        return self::normalizeDegrees((float)$a + self::angleDelta((float)$a, (float)$b) * $ratio);
    }

    /**
     * @param list<float> $values
     */
    private static function median(array $values): float
    {
        sort($values, SORT_NUMERIC);
        $count = count($values);
        if ($count === 0) {
            return 0.0;
        }
        $mid = intdiv($count, 2);
        return $count % 2 === 1 ? (float)$values[$mid] : ((float)$values[$mid - 1] + (float)$values[$mid]) / 2.0;
    }

    /**
     * @param list<float> $values
     */
    private static function percentile(array $values, float $percentile): float
    {
        if (!$values) {
            return 0.0;
        }
        sort($values, SORT_NUMERIC);
        $index = (int)floor((count($values) - 1) * max(0.0, min(1.0, $percentile)));
        return (float)$values[$index];
    }

    private static function normalizeAltimeterSettingInhg(float $value): ?float
    {
        if ($value >= 25.0 && $value <= 33.5) {
            return round($value, 2);
        }
        if ($value >= 800.0 && $value <= 1100.0) {
            return round($value / 33.8638866667, 2);
        }
        return null;
    }

    private static function distanceNm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadiusNm = 3440.065;
        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos($lat1Rad) * cos($lat2Rad) * sin($dLon / 2) ** 2;
        return $earthRadiusNm * 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
    }

    private static function normalizeDegrees(float $value): float
    {
        $value = fmod($value, 360.0);
        return $value < 0 ? $value + 360.0 : $value;
    }

    private static function angleDelta(float $from, float $to): float
    {
        $delta = fmod(($to - $from + 540.0), 360.0) - 180.0;
        return $delta <= -180.0 ? $delta + 360.0 : $delta;
    }

    /**
     * @param list<float> $angles
     */
    private static function circularMean(array $angles): float
    {
        if (!$angles) {
            return 0.0;
        }
        $sin = 0.0;
        $cos = 0.0;
        foreach ($angles as $angle) {
            $sin += sin(deg2rad($angle));
            $cos += cos(deg2rad($angle));
        }
        return self::angleDelta(0.0, rad2deg(atan2($sin / count($angles), $cos / count($angles))));
    }

    /**
     * @param list<float> $angles
     */
    private static function angleSpread(array $angles, float $center): float
    {
        if (!$angles) {
            return 0.0;
        }
        $deltas = array_map(fn(float $angle): float => abs(self::angleDelta($center, $angle)), $angles);
        sort($deltas, SORT_NUMERIC);
        return (float)$deltas[(int)floor((count($deltas) - 1) * 0.90)];
    }

    private function timestampFromRecordingStart(array $recording, float $seconds): string
    {
        $start = self::dateTime((string)($recording['started_at'] ?? ''));
        return $start ? $start->modify('+' . (string)max(0, (int)round($seconds)) . ' seconds')->format('Y-m-d\TH:i:s.v\Z') : '';
    }

    private static function dateTime(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    private static function mysqlDateTimeMillis(string $value): ?string
    {
        $dt = self::dateTime($value);
        return $dt ? $dt->format('Y-m-d H:i:s.v') : null;
    }

    /**
     * @return array<string,mixed>
     */
    private function publicSample(array $row): array
    {
        $groundspeed = $row['groundspeed_kt'] !== null ? (float)$row['groundspeed_kt'] : null;
        $track = $row['magnetic_track_deg'] !== null ? (float)$row['magnetic_track_deg'] : null;
        $heading = $row['magnetic_heading_deg'] !== null ? (float)$row['magnetic_heading_deg'] : null;
        $headingSource = $groundspeed !== null && $groundspeed >= 5.0 && $track !== null ? 'gps_track' : ($heading !== null ? 'calibrated_magnetic_heading' : 'none');
        $headingQuality = $headingSource === 'gps_track' ? 'GOOD' : ($headingSource === 'calibrated_magnetic_heading' ? 'LOW' : 'INVALID');
        return array(
            't' => (float)$row['seconds_since_start'],
            'time_utc' => $row['sample_time_utc'],
            'lat' => $row['latitude'] !== null ? (float)$row['latitude'] : null,
            'lon' => $row['longitude'] !== null ? (float)$row['longitude'] : null,
            'gps_altitude_ft' => $row['gps_altitude_ft'] !== null ? (float)$row['gps_altitude_ft'] : null,
            'baro_altitude_ft' => $row['baro_altitude_ft'] !== null ? (float)$row['baro_altitude_ft'] : null,
            'vertical_speed_fpm' => $row['vertical_speed_fpm'] !== null ? (float)$row['vertical_speed_fpm'] : null,
            'adsb_baro_altitude_ft' => isset($row['adsb_baro_altitude_ft']) && $row['adsb_baro_altitude_ft'] !== null ? (float)$row['adsb_baro_altitude_ft'] : null,
            'adsb_vertical_speed_fpm' => isset($row['adsb_vertical_speed_fpm']) && $row['adsb_vertical_speed_fpm'] !== null ? (float)$row['adsb_vertical_speed_fpm'] : null,
            'estimated_baro_altitude_ft' => isset($row['estimated_baro_altitude_ft']) && $row['estimated_baro_altitude_ft'] !== null ? (float)$row['estimated_baro_altitude_ft'] : null,
            'estimated_vertical_speed_fpm' => isset($row['estimated_vertical_speed_fpm']) && $row['estimated_vertical_speed_fpm'] !== null ? (float)$row['estimated_vertical_speed_fpm'] : null,
            'field_calibrated_altitude_ft' => isset($row['field_calibrated_altitude_ft']) && $row['field_calibrated_altitude_ft'] !== null ? (float)$row['field_calibrated_altitude_ft'] : null,
            'field_calibrated_true_altitude_ft' => isset($row['field_calibrated_true_altitude_ft']) && $row['field_calibrated_true_altitude_ft'] !== null ? (float)$row['field_calibrated_true_altitude_ft'] : null,
            'estimated_indicated_altitude_ft' => isset($row['estimated_indicated_altitude_ft']) && $row['estimated_indicated_altitude_ft'] !== null ? (float)$row['estimated_indicated_altitude_ft'] : null,
            'estimated_true_altitude_from_indicated_ft' => isset($row['estimated_true_altitude_from_indicated_ft']) && $row['estimated_true_altitude_from_indicated_ft'] !== null ? (float)$row['estimated_true_altitude_from_indicated_ft'] : null,
            'altimeter_setting_inhg' => isset($row['altimeter_setting_inhg']) && $row['altimeter_setting_inhg'] !== null ? (float)$row['altimeter_setting_inhg'] : null,
            'altimeter_setting_source' => (string)($row['altimeter_setting_source'] ?? 'unavailable'),
            'airport_elevation_ft' => isset($row['airport_elevation_ft']) && $row['airport_elevation_ft'] !== null ? (float)$row['airport_elevation_ft'] : null,
            'airport_elevation_source' => (string)($row['airport_elevation_source'] ?? 'unavailable'),
            'field_altitude_offset_ft' => isset($row['field_altitude_offset_ft']) && $row['field_altitude_offset_ft'] !== null ? (float)$row['field_altitude_offset_ft'] : null,
            'oat_c' => isset($row['oat_c']) && $row['oat_c'] !== null ? (float)$row['oat_c'] : null,
            'oat_source' => (string)($row['oat_source'] ?? 'unavailable'),
            'altitude_source' => (string)($row['altitude_source'] ?? 'unavailable'),
            'altitude_quality' => (string)($row['altitude_quality'] ?? 'unavailable'),
            'vertical_speed_source' => (string)($row['vertical_speed_source'] ?? 'unavailable'),
            'vertical_speed_quality' => (string)($row['vertical_speed_quality'] ?? 'unavailable'),
            'estimated_slip_skid_g' => isset($row['estimated_slip_skid_g']) && $row['estimated_slip_skid_g'] !== null ? (float)$row['estimated_slip_skid_g'] : null,
            'estimated_slip_skid_quality' => (string)($row['estimated_slip_skid_quality'] ?? 'unavailable'),
            'estimated_slip_skid_source' => (string)($row['estimated_slip_skid_source'] ?? 'unavailable'),
            'ahrs_acceleration_x_g' => isset($row['ahrs_acceleration_x_g']) && $row['ahrs_acceleration_x_g'] !== null ? (float)$row['ahrs_acceleration_x_g'] : null,
            'ahrs_acceleration_y_g' => isset($row['ahrs_acceleration_y_g']) && $row['ahrs_acceleration_y_g'] !== null ? (float)$row['ahrs_acceleration_y_g'] : null,
            'ahrs_acceleration_z_g' => isset($row['ahrs_acceleration_z_g']) && $row['ahrs_acceleration_z_g'] !== null ? (float)$row['ahrs_acceleration_z_g'] : null,
            'estimated_wind_speed_kt' => isset($row['estimated_wind_speed_kt']) && $row['estimated_wind_speed_kt'] !== null ? (float)$row['estimated_wind_speed_kt'] : null,
            'estimated_wind_direction_deg_true' => isset($row['estimated_wind_direction_deg_true']) && $row['estimated_wind_direction_deg_true'] !== null ? (float)$row['estimated_wind_direction_deg_true'] : null,
            'estimated_wind_quality' => (string)($row['estimated_wind_quality'] ?? 'unavailable'),
            'estimated_wind_source' => (string)($row['estimated_wind_source'] ?? 'unavailable'),
            'estimated_tas_kt' => isset($row['estimated_tas_kt']) && $row['estimated_tas_kt'] !== null ? (float)$row['estimated_tas_kt'] : null,
            'wind_estimation_method' => (string)($row['wind_estimation_method'] ?? 'unavailable'),
            'groundspeed_kt' => $row['groundspeed_kt'] !== null ? (float)$row['groundspeed_kt'] : null,
            'pitch_deg' => $row['pitch_deg'] !== null ? (float)$row['pitch_deg'] : null,
            'bank_deg' => $row['roll_deg'] !== null ? (float)$row['roll_deg'] : null,
            'heading_deg' => $heading,
            'track_deg' => $track,
            'true_heading_deg' => $row['true_heading_deg'] !== null ? (float)$row['true_heading_deg'] : null,
            'heading_source' => $headingSource,
            'heading_quality' => $headingQuality,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function publicPhase(array $row): array
    {
        return array(
            'phase' => (string)$row['phase'],
            'start' => (float)$row['start_seconds'],
            'end' => (float)$row['end_seconds'],
            'duration' => max(0.0, (float)$row['end_seconds'] - (float)$row['start_seconds']),
            'confidence' => (float)$row['confidence'],
            'summary' => self::decodeJson((string)($row['summary_json'] ?? '')),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function publicEvent(array $row): array
    {
        return array(
            'event_type' => (string)$row['event_type'],
            'phase' => (string)$row['phase'],
            'start' => (float)$row['start_seconds'],
            'end' => $row['end_seconds'] !== null ? (float)$row['end_seconds'] : null,
            'confidence' => (float)$row['confidence'],
            'evidence' => self::decodeJson((string)($row['evidence_json'] ?? '')),
            'notes' => (string)($row['notes'] ?? ''),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private static function decodeJson(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return array();
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : array();
    }
}
