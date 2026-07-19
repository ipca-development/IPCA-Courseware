<?php
declare(strict_types=1);

require_once __DIR__ . '/CockpitRecorderService.php';
require_once __DIR__ . '/CockpitAircraftService.php';
require_once __DIR__ . '/tv_adsb_status.php';

/**
 * ADS-B enrichment for Cockpit Recorder flight reconstruction.
 *
 * Phase 1 fetches ownship historical/recent trace data by ICAO hex.
 */
final class CockpitAdsbTraceNotAvailable extends RuntimeException
{
}

final class CockpitAdsbEnrichmentService
{
    private const RECORDINGS_TABLE = 'ipca_cockpit_recordings';
    private const ADSB_TABLE = 'ipca_cockpit_adsb_enrichments';
    private const OWNSHIP_TABLE = 'ipca_cockpit_adsb_ownship_samples';
    private const TRAFFIC_TABLE = 'ipca_cockpit_adsb_traffic_samples';
    private const CANDIDATE_TABLE = 'ipca_cockpit_adsb_candidate_observations';
    private const RAW_TRACE_TABLE = 'ipca_cockpit_adsb_raw_traces';
    private const AIRCRAFT_SAMPLE_TABLE = 'ipca_cockpit_adsb_traffic_aircraft_samples';
    private const MAX_ADSB_GPS_DISTANCE_NM = 1.0;
    private const MAX_ADSB_GPS_SPEED_DELTA_KT = 45.0;
    private const MAX_ADSB_GPS_TRACK_DELTA_DEG = 60.0;
    private const TRAFFIC_RANGE_NM = 10.0;
    private const TRAFFIC_DISPLAY_RANGE_NM = 15.0;
    private const TRAFFIC_DISCOVERY_RANGE_NM = 50.0;
    private const TRAFFIC_ANCHOR_INTERVAL_S = 5.0;
    private const TRAFFIC_DISCOVERY_INTERVAL_S = 10.0;
    private const TRAFFIC_WINDOW_MARGIN_S = 120.0;
    private const TRAFFIC_PREFERRED_SAMPLE_GAP_S = 15.0;
    private const TRAFFIC_HARD_SAMPLE_GAP_S = 30.0;
    private const TRAFFIC_MAX_JUMP_NM = 8.0;
    private const TRAFFIC_MAX_SPEED_KT = 320.0;
    private const TRAFFIC_NEARBY_AIRPORT_NM = 30.0;
    private const MAX_CANDIDATE_HEXES = 80;

    public function __construct(private PDO $pdo)
    {
    }

    public static function storageRoot(): string
    {
        return CockpitRecorderService::projectRoot() . '/storage/cockpit_recorder/adsb';
    }

    public static function tablesPresent(PDO $pdo): bool
    {
        foreach (array(self::ADSB_TABLE, self::OWNSHIP_TABLE, self::TRAFFIC_TABLE) as $table) {
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
        if (!$this->columnPresent(self::OWNSHIP_TABLE, 'altimeter_setting_inhg')) {
            throw new RuntimeException('Apply scripts/sql/2026_06_24_cockpit_recorder_derived_replay_values.sql before fetching ADS-B.');
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function enrich(string $id): array
    {
        $this->requireTables();
        $recording = (new CockpitRecorderService($this->pdo))->recordingByAnyId($id);
        if (!$recording) {
            throw new RuntimeException('Recording not found.');
        }

        $recordingId = (int)$recording['id'];
        $hex = tv_adsb_normalize_hex((string)($recording['aircraft_adsb_hex'] ?? ''));
        $window = $this->recordingWindow($recording);
        $this->setStatus($recordingId, 'fetching', $hex, $window['start_mysql'], $window['end_mysql'], 0, 0, null);

        if ($hex === '') {
            $this->clearOwnshipSamples($recordingId);
            $traffic = $this->enrichTrafficFromGps($recordingId, '', $recording, $window);
            $diagnosticsPath = $this->storeJson($recording, 'normalized', array(
                'provider' => tv_adsb_provider() === 'gateway' ? 'adsbexchange_gateway' : 'adsbexchange_trace',
                'recording_id' => (string)$recording['recording_uid'],
                'query_start_utc' => $window['start_iso'],
                'query_end_utc' => $window['end_iso'],
                'alignment' => 'gps_or_garmin_ownship_corridor_only',
                'traffic' => $traffic,
                'note' => 'Recording has no ownship ADS-B hex; traffic discovery used the authoritative GPS/Garmin corridor only.',
            ));
            $status = (int)($traffic['sample_count'] ?? 0) > 0 ? 'ready' : 'not_available';
            $message = (int)($traffic['sample_count'] ?? 0) > 0 ? null : 'No traffic candidates produced usable historical traces.';
            $this->setStatus($recordingId, $status, '', $window['start_mysql'], $window['end_mysql'], 0, (int)($traffic['sample_count'] ?? 0), $message, null, $diagnosticsPath);
            return array('ok' => $status === 'ready', 'status' => $status, 'ownship_sample_count' => 0, 'traffic_sample_count' => (int)($traffic['sample_count'] ?? 0), 'normalized_storage_path' => $diagnosticsPath);
        }

        try {
            $raw = $this->fetchTrace($hex, $window);
            $rawPath = $this->storeJson($recording, 'raw', $raw);
            $samples = $this->normalizeTrace($raw, $window['start_epoch'], $window['end_epoch']);
            $validation = $this->validateTraceAlignment($samples, $recording);
            $samples = $validation['samples'];
            if (!$samples) {
                $this->clearOwnshipSamples($recordingId);
                $traffic = $this->enrichTrafficFromGps($recordingId, $hex, $recording, $window);
                $note = (int)($traffic['sample_count'] ?? 0) > 0
                    ? 'Ownship ADS-B trace did not pass UTC plus GPS same-time validation. Nearby traffic was still derived from the iPhone GPS corridor.'
                    : 'No ADS-B trace samples passed UTC plus GPS same-time validation. Spatial fallback is disabled for altitude/vertical-speed because it can misalign repeated or nearby track segments.';
                $diagnostics = $this->traceDiagnostics($raw, $recording, $window, 0, 0, $note, $validation['stats']);
                $diagnostics['traffic'] = $traffic;
                $diagnosticsPath = $this->storeJson($recording, 'normalized', $diagnostics);
                $message = $this->diagnosticMessage($diagnostics);
                if ((int)($traffic['sample_count'] ?? 0) > 0) {
                    $this->setStatus($recordingId, 'ready', $hex, $window['start_mysql'], $window['end_mysql'], 0, (int)$traffic['sample_count'], $message, $rawPath, $diagnosticsPath);
                    return array('ok' => true, 'status' => 'ready', 'ownship_sample_count' => 0, 'traffic_sample_count' => (int)$traffic['sample_count'], 'warning' => $message, 'raw_storage_path' => $rawPath, 'normalized_storage_path' => $diagnosticsPath);
                }
                $this->setStatus($recordingId, 'not_available', $hex, $window['start_mysql'], $window['end_mysql'], 0, 0, $message, $rawPath, $diagnosticsPath);
                return array('ok' => false, 'status' => 'not_available', 'error' => $message, 'raw_storage_path' => $rawPath, 'normalized_storage_path' => $diagnosticsPath);
            }

            $this->setStatus($recordingId, 'processing', $hex, $window['start_mysql'], $window['end_mysql'], 0, 0, null, $rawPath, null);
            $normalized = array(
                'provider' => tv_adsb_provider() === 'gateway' ? 'adsbexchange_gateway' : 'adsbexchange_trace',
                'hex' => $hex,
                'recording_id' => (string)$recording['recording_uid'],
                'query_start_utc' => $window['start_iso'],
                'query_end_utc' => $window['end_iso'],
                'alignment' => 'timestamp_window_plus_gps_validation',
                'validation' => $validation['stats'],
                'diagnostics' => $this->traceDiagnostics($raw, $recording, $window, count($samples), 0, null, $validation['stats']),
                'samples' => $samples,
            );
            $normalizedPath = $this->storeJson($recording, 'normalized', $normalized);
            $this->storeOwnshipSamples($recordingId, $samples);
            $traffic = $this->enrichTrafficFromGps($recordingId, $hex, $recording, $window);
            $normalized['traffic'] = $traffic;
            $normalizedPath = $this->storeJson($recording, 'normalized', $normalized);
            $this->setStatus($recordingId, 'ready', $hex, $window['start_mysql'], $window['end_mysql'], count($samples), (int)($traffic['sample_count'] ?? 0), null, $rawPath, $normalizedPath);

            return array(
                'ok' => true,
                'status' => 'ready',
                'recording_id' => $recordingId,
                'ownship_sample_count' => count($samples),
                'traffic_sample_count' => (int)($traffic['sample_count'] ?? 0),
                'raw_storage_path' => $rawPath,
                'normalized_storage_path' => $normalizedPath,
            );
        } catch (CockpitAdsbTraceNotAvailable $e) {
            $this->clearOwnshipSamples($recordingId);
            $traffic = $this->enrichTrafficFromGps($recordingId, $hex, $recording, $window);
            $diagnosticsPath = $this->storeJson($recording, 'normalized', array(
                'provider' => tv_adsb_provider() === 'gateway' ? 'adsbexchange_gateway' : 'adsbexchange_trace',
                'hex' => $hex,
                'recording_id' => (string)$recording['recording_uid'],
                'query_start_utc' => $window['start_iso'],
                'query_end_utc' => $window['end_iso'],
                'status' => (int)($traffic['sample_count'] ?? 0) > 0 ? 'ready' : 'not_available',
                'error' => $e->getMessage(),
                'traffic' => $traffic,
            ));
            if ((int)($traffic['sample_count'] ?? 0) > 0) {
                $this->setStatus($recordingId, 'ready', $hex, $window['start_mysql'], $window['end_mysql'], 0, (int)$traffic['sample_count'], $e->getMessage(), null, $diagnosticsPath);
                return array('ok' => true, 'status' => 'ready', 'ownship_sample_count' => 0, 'traffic_sample_count' => (int)$traffic['sample_count'], 'warning' => $e->getMessage(), 'normalized_storage_path' => $diagnosticsPath);
            }
            $this->setStatus($recordingId, 'not_available', $hex, $window['start_mysql'], $window['end_mysql'], 0, 0, $e->getMessage(), null, $diagnosticsPath);
            return array('ok' => false, 'status' => 'not_available', 'error' => $e->getMessage(), 'normalized_storage_path' => $diagnosticsPath);
        } catch (Throwable $e) {
            $this->setStatus($recordingId, 'failed', $hex, $window['start_mysql'], $window['end_mysql'], 0, 0, $e->getMessage());
            throw $e;
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function statusForRecording(int $recordingId): array
    {
        if ($recordingId <= 0 || !self::tablesPresent($this->pdo)) {
            return array();
        }
        $stmt = $this->pdo->prepare('SELECT * FROM ' . self::ADSB_TABLE . ' WHERE recording_id = ? LIMIT 1');
        $stmt->execute(array($recordingId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : array();
    }

    /**
     * @return array{path:string,mime:string,filename:string}|null
     */
    public function fileForRecording(string $id, string $type): ?array
    {
        $recording = (new CockpitRecorderService($this->pdo))->recordingByAnyId($id);
        if (!$recording) {
            return null;
        }
        $adsb = $this->statusForRecording((int)$recording['id']);
        $column = $type === 'raw' ? 'raw_storage_path' : 'normalized_storage_path';
        $relativePath = trim((string)($adsb[$column] ?? ''));
        if ($relativePath === '') {
            return null;
        }
        $path = CockpitRecorderService::projectRoot() . '/' . ltrim($relativePath, '/');
        $realPath = realpath($path);
        $root = realpath(self::storageRoot());
        if ($realPath === false || $root === false || !str_starts_with($realPath, $root) || !is_file($realPath)) {
            return null;
        }
        return array(
            'path' => $realPath,
            'mime' => 'application/json',
            'filename' => (string)($recording['recording_uid'] ?? 'recording') . '.adsb.' . $type . '.json',
        );
    }

    /**
     * @param array<string,mixed> $recording
     * @return array{start_epoch:float,end_epoch:float,start_iso:string,end_iso:string,start_mysql:string,end_mysql:string}
     */
    private function recordingWindow(array $recording): array
    {
        $startRaw = trim((string)($recording['started_at'] ?? ''));
        if ($startRaw === '') {
            throw new RuntimeException('Recording start time is missing.');
        }
        $actualStart = (new DateTimeImmutable($startRaw, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        $start = $actualStart->modify('-' . (int)self::TRAFFIC_WINDOW_MARGIN_S . ' seconds');
        $duration = max(0.0, (float)($recording['duration_seconds'] ?? 0));
        $end = $actualStart->modify('+' . (string)max(1, (int)ceil($duration)) . ' seconds')->modify('+' . (int)self::TRAFFIC_WINDOW_MARGIN_S . ' seconds');

        $window = array(
            'start_epoch' => (float)$start->format('U.u'),
            'end_epoch' => (float)$end->format('U.u'),
            'start_iso' => $start->format('c'),
            'end_iso' => $end->format('c'),
            'start_mysql' => $start->format('Y-m-d H:i:s'),
            'end_mysql' => $end->format('Y-m-d H:i:s'),
        );

        return $this->gpsTimestampWindow($recording, $window) ?? $window;
    }

    /**
     * @param array<string,mixed> $recording
     * @param array{start_epoch:float,end_epoch:float,start_iso:string,end_iso:string,start_mysql:string,end_mysql:string} $fallback
     * @return array{start_epoch:float,end_epoch:float,start_iso:string,end_iso:string,start_mysql:string,end_mysql:string}|null
     */
    private function gpsTimestampWindow(array $recording, array $fallback): ?array
    {
        $path = $this->safeStoredPath((string)($recording['gps_storage_path'] ?? ''), CockpitRecorderService::gpsRoot());
        if ($path === null) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return null;
        }
        $rows = json_decode($raw, true);
        if (!is_array($rows) || array_keys($rows) !== range(0, count($rows) - 1)) {
            return null;
        }

        $times = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $timestamp = trim((string)($row['timestamp'] ?? ''));
            if ($timestamp === '') {
                continue;
            }
            try {
                $times[] = (new DateTimeImmutable($timestamp))->setTimezone(new DateTimeZone('UTC'));
            } catch (Throwable) {
                continue;
            }
        }
        if (!$times) {
            return null;
        }
        usort($times, fn(DateTimeImmutable $a, DateTimeImmutable $b): int => ((float)$a->format('U.u')) <=> ((float)$b->format('U.u')));
        $start = $times[0]->modify('-' . (int)self::TRAFFIC_WINDOW_MARGIN_S . ' seconds');
        $end = $times[count($times) - 1]->modify('+' . (int)self::TRAFFIC_WINDOW_MARGIN_S . ' seconds');
        if ((float)$end->format('U.u') <= (float)$start->format('U.u')) {
            return null;
        }

        return array(
            'start_epoch' => (float)$start->format('U.u'),
            'end_epoch' => (float)$end->format('U.u'),
            'start_iso' => $start->format('c'),
            'end_iso' => $end->format('c'),
            'start_mysql' => $start->format('Y-m-d H:i:s'),
            'end_mysql' => $end->format('Y-m-d H:i:s'),
        );
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

    private function tablePresent(string $table): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute(array($table));
        return (int)$stmt->fetchColumn() > 0;
    }

    private static function normalizeAircraftIdentifier(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }
        if (str_starts_with($value, '~')) {
            $suffix = preg_replace('/[^a-z0-9]/', '', substr($value, 1)) ?? '';
            return $suffix !== '' ? '~' . substr($suffix, 0, 24) : '';
        }
        $hex = preg_replace('/[^a-f0-9]/', '', $value) ?? '';
        return $hex !== '' ? substr($hex, 0, 6) : '';
    }

    /**
     * @param array<string,mixed> $aircraft
     */
    private static function aircraftIdentifierFromPayload(array $aircraft): string
    {
        foreach (array('hex', 'icao', 'id', 'aircraft_id') as $key) {
            if (isset($aircraft[$key]) && is_scalar($aircraft[$key])) {
                $id = self::normalizeAircraftIdentifier((string)$aircraft[$key]);
                if ($id !== '') {
                    return $id;
                }
            }
        }
        return '';
    }

    /**
     * @param array<string,mixed> $window
     * @return array<string,mixed>
     */
    private function fetchTrace(string $hex, array $window): array
    {
        $historical = $this->fetchHistoricalTraces($hex, $window);
        if (isset($historical['trace']) && is_array($historical['trace']) && $historical['trace'] !== array()) {
            return $historical;
        }

        $folder = substr($hex, -2);
        $paths = array(
            '/traces/' . rawurlencode($folder) . '/trace_full_' . rawurlencode($hex) . '.json',
            '/traces/' . rawurlencode($folder) . '/trace_recent_' . rawurlencode($hex) . '.json',
        );

        $errors = array();
        if (isset($historical['errors']) && is_array($historical['errors'])) {
            foreach ($historical['errors'] as $historyError) {
                $errors[] = 'history ' . (string)$historyError;
            }
        }
        foreach ($paths as $path) {
            try {
                $payload = tv_adsb_request($path);
                if (isset($payload['trace']) && is_array($payload['trace'])) {
                    return $payload;
                }
                $errors[] = $path . ': response did not contain a trace array';
            } catch (Throwable $e) {
                $errors[] = $path . ': ' . $e->getMessage();
            }
        }

        throw new CockpitAdsbTraceNotAvailable('ADS-B trace not available from configured providers. ' . implode(' | ', $errors));
    }

    /**
     * @param array<string,mixed> $window
     * @return array<string,mixed>
     */
    private function fetchHistoricalTraces(string $hex, array $window): array
    {
        $start = new DateTimeImmutable((string)$window['start_iso']);
        $end = new DateTimeImmutable((string)$window['end_iso']);
        $datesByKey = array();
        for ($day = $start->setTime(0, 0)->modify('-1 day'); $day <= $end->setTime(0, 0)->modify('+1 day'); $day = $day->modify('+1 day')) {
            $datesByKey[$day->format('Y-m-d')] = $day;
        }

        $trace = array();
        $sources = array();
        $errors = array();
        foreach (array_values($datesByKey) as $day) {
            try {
                $payload = $this->fetchHistoricalTraceForDate($hex, $day);
            } catch (Throwable $e) {
                $errors[] = $day->format('Y-m-d') . ': ' . $e->getMessage();
                continue;
            }
            if (!isset($payload['trace']) || !is_array($payload['trace']) || !isset($payload['timestamp']) || !is_numeric($payload['timestamp'])) {
                continue;
            }
            $base = (float)$payload['timestamp'];
            foreach ($payload['trace'] as $row) {
                if (!is_array($row) || !isset($row[0]) || !is_numeric($row[0])) {
                    continue;
                }
                $row[0] = $base + (float)$row[0];
                $trace[] = $row;
            }
            $sources[] = $day->format('Y-m-d');
        }

        usort($trace, fn(array $a, array $b): int => ((float)($a[0] ?? 0)) <=> ((float)($b[0] ?? 0)));
        return array(
            'icao' => $hex,
            'timestamp' => 0,
            'source' => tv_adsb_provider() === 'gateway' ? 'adsbexchange_gateway_traces_hist' : 'adsbexchange_globe_history',
            'source_dates' => $sources,
            'errors' => $errors,
            'trace' => $trace,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function fetchHistoricalTraceForDate(string $hex, DateTimeImmutable $day): array
    {
        $identifier = self::normalizeAircraftIdentifier($hex);
        if ($identifier === '') {
            return array();
        }
        if (tv_adsb_provider() === 'gateway' && !str_starts_with($identifier, '~')) {
            try {
                return tv_adsb_fetch_historical_trace($identifier, $day);
            } catch (Throwable $e) {
                if (!str_contains($e->getMessage(), 'HTTP 403')) {
                    throw $e;
                }
            }
        }

        $folder = substr(preg_replace('/[^a-z0-9]/', '', $identifier) ?: $identifier, -2);
        $base = rtrim((string)(getenv('CW_ADSBEXCHANGE_HISTORY_BASE') ?: 'https://globe.adsbexchange.com/globe_history'), '/');
        $url = $base . '/' . $day->format('Y/m/d') . '/traces/' . rawurlencode($folder) . '/trace_full_' . rawurlencode($identifier) . '.json';
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Could not initialize ADS-B history request.');
        }
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_ENCODING => 'gzip',
            CURLOPT_HTTPHEADER => array(
                'Accept: application/json',
                'Accept-Encoding: gzip',
                'Referer: https://globe.adsbexchange.com/',
                'User-Agent: Mozilla/5.0 (compatible; IPCA-CockpitRecorder/1.0)',
            ),
        ));
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('ADS-B history request failed: ' . $err);
        }
        if ($code === 404) {
            return array();
        }
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException('ADS-B history returned HTTP ' . $code);
        }
        $decoded = json_decode((string)$body, true);
        return is_array($decoded) ? $decoded : array();
    }

    /**
     * @param array<string,mixed> $trace
     * @return list<array<string,mixed>>
     */
    private function normalizeTrace(array $trace, float $startEpoch, float $endEpoch): array
    {
        $base = isset($trace['timestamp']) && is_numeric($trace['timestamp']) ? (float)$trace['timestamp'] : null;
        $rows = isset($trace['trace']) && is_array($trace['trace']) ? $trace['trace'] : array();
        if ($base === null || !$rows) {
            return array();
        }

        $samples = array();
        foreach ($rows as $row) {
            if (!is_array($row) || count($row) < 3 || !isset($row[0], $row[1], $row[2]) || !is_numeric($row[0]) || !is_numeric($row[1]) || !is_numeric($row[2])) {
                continue;
            }
            $epoch = $base + (float)$row[0];
            if ($epoch < $startEpoch - 30 || $epoch > $endEpoch + 30) {
                continue;
            }
            $alt = $row[3] ?? null;
            $onGround = is_string($alt) && strtolower($alt) === 'ground';
            $baroAlt = is_numeric($alt) ? (float)$alt : null;
            $dt = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $epoch), new DateTimeZone('UTC'));
            if (!$dt) {
                continue;
            }
            $samples[] = array(
                'sample_time_utc' => $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'),
                'seconds_since_start' => max(0.0, $epoch - $startEpoch),
                'latitude' => (float)$row[1],
                'longitude' => (float)$row[2],
                'baro_altitude_ft' => $baroAlt,
                'vertical_speed_fpm' => $this->traceVerticalSpeedFpm($row),
                'groundspeed_kt' => isset($row[4]) && is_numeric($row[4]) ? (float)$row[4] : null,
                'track_deg' => isset($row[5]) && is_numeric($row[5]) ? (float)$row[5] : null,
                'heading_deg' => isset($row[8]) && is_array($row[8]) && isset($row[8]['mag_heading']) && is_numeric($row[8]['mag_heading']) ? (float)$row[8]['mag_heading'] : null,
                'on_ground' => $onGround,
                'altimeter_setting_inhg' => $this->traceAltimeterSettingInhg($row),
                'raw' => $row,
            );
        }

        usort($samples, fn(array $a, array $b): int => ((float)$a['seconds_since_start']) <=> ((float)$b['seconds_since_start']));
        return $samples;
    }

    /**
     * @param list<array<string,mixed>> $samples
     * @param array<string,mixed> $recording
     * @return array{samples:list<array<string,mixed>>,stats:array<string,mixed>}
     */
    private function validateTraceAlignment(array $samples, array $recording): array
    {
        $gpsRows = $this->gpsAlignmentRows($recording);
        $stats = array(
            'status' => 'not_checked',
            'input_sample_count' => count($samples),
            'accepted_sample_count' => count($samples),
            'rejected_sample_count' => 0,
            'missing_gps_match_count' => 0,
            'position_reject_count' => 0,
            'speed_reject_count' => 0,
            'track_reject_count' => 0,
            'max_allowed_distance_nm' => self::MAX_ADSB_GPS_DISTANCE_NM,
            'median_distance_nm' => null,
            'max_distance_nm' => null,
            'median_groundspeed_delta_kt' => null,
            'median_track_delta_deg' => null,
            'median_gps_to_baro_altitude_delta_ft' => null,
        );

        if (!$samples || !$gpsRows) {
            $stats['status'] = $gpsRows ? 'no_adsb_samples' : 'no_gps_reference';
            return array('samples' => $samples, 'stats' => $stats);
        }

        $accepted = array();
        $distances = array();
        $speedDeltas = array();
        $trackDeltas = array();
        $altitudeDeltas = array();
        foreach ($samples as $sample) {
            $gps = $this->gpsAtSeconds($gpsRows, (float)$sample['seconds_since_start']);
            if ($gps === null) {
                $stats['missing_gps_match_count']++;
                continue;
            }

            $distanceNm = self::distanceNm((float)$sample['latitude'], (float)$sample['longitude'], (float)$gps['latitude'], (float)$gps['longitude']);
            $distances[] = $distanceNm;
            if ($distanceNm > self::MAX_ADSB_GPS_DISTANCE_NM) {
                $stats['position_reject_count']++;
                continue;
            }

            $speedDelta = null;
            if (isset($sample['groundspeed_kt'], $gps['groundspeed_kt']) && is_numeric($sample['groundspeed_kt']) && is_numeric($gps['groundspeed_kt'])) {
                $speedDelta = abs((float)$sample['groundspeed_kt'] - (float)$gps['groundspeed_kt']);
                $speedDeltas[] = $speedDelta;
                if ((float)$gps['groundspeed_kt'] >= 10.0 && $speedDelta > self::MAX_ADSB_GPS_SPEED_DELTA_KT) {
                    $stats['speed_reject_count']++;
                    continue;
                }
            }

            $trackDelta = null;
            if (isset($sample['track_deg'], $gps['track_deg'], $sample['groundspeed_kt'], $gps['groundspeed_kt'])
                && is_numeric($sample['track_deg']) && is_numeric($gps['track_deg'])
                && (float)$sample['groundspeed_kt'] >= 20.0 && (float)$gps['groundspeed_kt'] >= 20.0
            ) {
                $trackDelta = abs(self::angleDelta((float)$sample['track_deg'], (float)$gps['track_deg']));
                $trackDeltas[] = $trackDelta;
                if ($trackDelta > self::MAX_ADSB_GPS_TRACK_DELTA_DEG) {
                    $stats['track_reject_count']++;
                    continue;
                }
            }

            if (isset($sample['baro_altitude_ft'], $gps['altitude_ft']) && is_numeric($sample['baro_altitude_ft']) && is_numeric($gps['altitude_ft'])) {
                $altitudeDeltas[] = (float)$gps['altitude_ft'] - (float)$sample['baro_altitude_ft'];
            }

            $sample['validation'] = array(
                'gps_distance_nm' => round($distanceNm, 4),
                'gps_groundspeed_delta_kt' => $speedDelta !== null ? round($speedDelta, 2) : null,
                'gps_track_delta_deg' => $trackDelta !== null ? round($trackDelta, 2) : null,
            );
            $accepted[] = $sample;
        }

        $stats['status'] = $accepted ? 'passed' : 'failed';
        $stats['accepted_sample_count'] = count($accepted);
        $stats['rejected_sample_count'] = count($samples) - count($accepted);
        $stats['median_distance_nm'] = $distances ? round(self::median($distances), 4) : null;
        $stats['max_distance_nm'] = $distances ? round(max($distances), 4) : null;
        $stats['median_groundspeed_delta_kt'] = $speedDeltas ? round(self::median($speedDeltas), 2) : null;
        $stats['median_track_delta_deg'] = $trackDeltas ? round(self::median($trackDeltas), 2) : null;
        $stats['median_gps_to_baro_altitude_delta_ft'] = $altitudeDeltas ? round(self::median($altitudeDeltas), 1) : null;

        return array('samples' => $accepted, 'stats' => $stats);
    }

    /**
     * @param list<array<string,mixed>> $gpsRows
     * @return array<string,mixed>|null
     */
    private function gpsAtSeconds(array $gpsRows, float $seconds): ?array
    {
        $before = null;
        $after = null;
        foreach ($gpsRows as $row) {
            $rowSeconds = (float)$row['seconds'];
            if ($rowSeconds <= $seconds) {
                $before = $row;
                continue;
            }
            $after = $row;
            break;
        }

        if ($before === null) {
            return $after !== null && abs((float)$after['seconds'] - $seconds) <= 45.0 ? $after : null;
        }
        if ($after === null) {
            return abs((float)$before['seconds'] - $seconds) <= 45.0 ? $before : null;
        }

        $beforeSeconds = (float)$before['seconds'];
        $afterSeconds = (float)$after['seconds'];
        $gap = $afterSeconds - $beforeSeconds;
        if ($gap <= 0.0 || $gap > 60.0) {
            $nearest = abs($seconds - $beforeSeconds) <= abs($afterSeconds - $seconds) ? $before : $after;
            return abs((float)$nearest['seconds'] - $seconds) <= 45.0 ? $nearest : null;
        }

        $ratio = max(0.0, min(1.0, ($seconds - $beforeSeconds) / $gap));
        return array(
            'seconds' => $seconds,
            'timestamp' => $before['timestamp'] ?? '',
            'latitude' => self::lerpNullable($before['latitude'] ?? null, $after['latitude'] ?? null, $ratio),
            'longitude' => self::lerpNullable($before['longitude'] ?? null, $after['longitude'] ?? null, $ratio),
            'altitude_ft' => self::lerpNullable($before['altitude_ft'] ?? null, $after['altitude_ft'] ?? null, $ratio),
            'groundspeed_kt' => self::lerpNullable($before['groundspeed_kt'] ?? null, $after['groundspeed_kt'] ?? null, $ratio),
            'track_deg' => self::lerpAngleNullable($before['track_deg'] ?? null, $after['track_deg'] ?? null, $ratio),
        );
    }

    /**
     * Fallback for recordings where device timestamps and ADS-B trace timestamps do not overlap.
     * Aligns ADS-B rows to the closest recorded GPS point by position, then uses the GPS
     * secondsSinceRecordingStart value for replay synchronization.
     *
     * @param array<string,mixed> $trace
     * @param array<string,mixed> $recording
     * @return list<array<string,mixed>>
     */
    private function normalizeTraceByGpsPath(array $trace, array $recording): array
    {
        $gpsRows = $this->gpsAlignmentRows($recording);
        $base = isset($trace['timestamp']) && is_numeric($trace['timestamp']) ? (float)$trace['timestamp'] : null;
        $rows = isset($trace['trace']) && is_array($trace['trace']) ? $trace['trace'] : array();
        if (!$gpsRows || $base === null || !$rows) {
            return array();
        }

        $samples = array();
        foreach ($rows as $row) {
            if (!is_array($row) || count($row) < 3 || !isset($row[0], $row[1], $row[2]) || !is_numeric($row[0]) || !is_numeric($row[1]) || !is_numeric($row[2])) {
                continue;
            }

            $bestGps = null;
            $bestDistance = 0.35;
            $lat = (float)$row[1];
            $lon = (float)$row[2];
            foreach ($gpsRows as $gps) {
                $distance = self::distanceNm($lat, $lon, (float)$gps['latitude'], (float)$gps['longitude']);
                if ($distance <= $bestDistance) {
                    $bestDistance = $distance;
                    $bestGps = $gps;
                }
            }
            if ($bestGps === null) {
                continue;
            }

            $epoch = $base + (float)$row[0];
            $dt = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $epoch), new DateTimeZone('UTC'));
            if (!$dt) {
                continue;
            }
            $alt = $row[3] ?? null;
            $samples[] = array(
                'sample_time_utc' => $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'),
                'seconds_since_start' => max(0.0, (float)$bestGps['seconds']),
                'latitude' => $lat,
                'longitude' => $lon,
                'baro_altitude_ft' => is_numeric($alt) ? (float)$alt : null,
                'vertical_speed_fpm' => $this->traceVerticalSpeedFpm($row),
                'groundspeed_kt' => isset($row[4]) && is_numeric($row[4]) ? (float)$row[4] : null,
                'track_deg' => isset($row[5]) && is_numeric($row[5]) ? (float)$row[5] : null,
                'heading_deg' => isset($row[8]) && is_array($row[8]) && isset($row[8]['mag_heading']) && is_numeric($row[8]['mag_heading']) ? (float)$row[8]['mag_heading'] : null,
                'on_ground' => is_string($alt) && strtolower($alt) === 'ground',
                'altimeter_setting_inhg' => $this->traceAltimeterSettingInhg($row),
                'alignment_distance_nm' => $bestDistance,
                'raw' => $row,
            );
        }

        usort($samples, fn(array $a, array $b): int => ((float)$a['seconds_since_start']) <=> ((float)$b['seconds_since_start']));
        return $samples;
    }

    /**
     * @param array<string,mixed> $recording
     * @return list<array<string,mixed>>
     */
    private function gpsAlignmentRows(array $recording): array
    {
        $path = $this->safeStoredPath((string)($recording['gps_storage_path'] ?? ''), CockpitRecorderService::gpsRoot());
        if ($path === null) {
            return array();
        }
        $raw = file_get_contents($path);
        $decoded = $raw !== false ? json_decode($raw, true) : null;
        if (!is_array($decoded) || array_keys($decoded) !== range(0, count($decoded) - 1)) {
            return array();
        }

        $rows = array();
        foreach ($decoded as $row) {
            if (!is_array($row) || !isset($row['secondsSinceRecordingStart'], $row['latitude'], $row['longitude']) || !is_numeric($row['secondsSinceRecordingStart']) || !is_numeric($row['latitude']) || !is_numeric($row['longitude'])) {
                continue;
            }
            $rows[] = array(
                'seconds' => (float)$row['secondsSinceRecordingStart'],
                'timestamp' => (string)($row['timestamp'] ?? ''),
                'latitude' => (float)$row['latitude'],
                'longitude' => (float)$row['longitude'],
                'altitude_ft' => isset($row['altitude']) && is_numeric($row['altitude']) ? (float)$row['altitude'] * 3.280839895 : null,
                'groundspeed_kt' => isset($row['speedKnots']) && is_numeric($row['speedKnots']) ? (float)$row['speedKnots'] : null,
                'track_deg' => isset($row['course']) && is_numeric($row['course']) ? (float)$row['course'] : null,
            );
        }
        usort($rows, fn(array $a, array $b): int => ((float)$a['seconds']) <=> ((float)$b['seconds']));
        return $rows;
    }

    /**
     * @param array<string,mixed> $trace
     * @param array<string,mixed> $recording
     * @param array<string,mixed> $window
     * @return array<string,mixed>
     */
    private function traceDiagnostics(array $trace, array $recording, array $window, int $filteredCount, int $spatialCount, ?string $note, ?array $validation = null): array
    {
        $base = isset($trace['timestamp']) && is_numeric($trace['timestamp']) ? (float)$trace['timestamp'] : null;
        $rows = isset($trace['trace']) && is_array($trace['trace']) ? $trace['trace'] : array();
        $firstEpoch = null;
        $lastEpoch = null;
        $numericAltitudes = 0;
        $numericRates = 0;
        $timeWindowRows = 0;
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row[0]) || !is_numeric($row[0]) || $base === null) {
                continue;
            }
            $epoch = $base + (float)$row[0];
            $firstEpoch = $firstEpoch === null ? $epoch : min($firstEpoch, $epoch);
            $lastEpoch = $lastEpoch === null ? $epoch : max($lastEpoch, $epoch);
            if ($epoch >= (float)$window['start_epoch'] - 30 && $epoch <= (float)$window['end_epoch'] + 30) {
                $timeWindowRows++;
            }
            if (isset($row[3]) && is_numeric($row[3])) {
                $numericAltitudes++;
            }
            if (isset($row[7]) && is_numeric($row[7])) {
                $numericRates++;
            }
        }

        $gpsRows = $this->gpsAlignmentRows($recording);
        $gpsFirst = $gpsRows ? (string)$gpsRows[0]['timestamp'] : null;
        $gpsLast = $gpsRows ? (string)$gpsRows[count($gpsRows) - 1]['timestamp'] : null;

        return array(
            'status' => $filteredCount > 0 ? 'matched' : 'not_matched',
            'note' => $note,
            'recording' => array(
                'id' => (int)($recording['id'] ?? 0),
                'recording_uid' => (string)($recording['recording_uid'] ?? ''),
                'started_at' => $recording['started_at'] ?? null,
                'duration_seconds' => (float)($recording['duration_seconds'] ?? 0),
            ),
            'filter_window_utc' => array(
                'start' => $window['start_iso'] ?? null,
                'end' => $window['end_iso'] ?? null,
            ),
            'gps' => array(
                'sample_count' => count($gpsRows),
                'first_timestamp' => $gpsFirst,
                'last_timestamp' => $gpsLast,
                'first_seconds' => $gpsRows ? (float)$gpsRows[0]['seconds'] : null,
                'last_seconds' => $gpsRows ? (float)$gpsRows[count($gpsRows) - 1]['seconds'] : null,
            ),
            'adsb_trace' => array(
                'source' => (string)($trace['source'] ?? 'configured_provider'),
                'source_dates' => $trace['source_dates'] ?? array(),
                'raw_row_count' => count($rows),
                'first_timestamp' => self::epochIso($firstEpoch),
                'last_timestamp' => self::epochIso($lastEpoch),
                'rows_inside_time_window' => $timeWindowRows,
                'rows_matched_by_spatial_fallback' => $spatialCount,
                'numeric_baro_altitude_rows' => $numericAltitudes,
                'numeric_vertical_speed_rows' => $numericRates,
            ),
            'validation' => $validation ?? array(),
        );
    }

    /**
     * @param array<string,mixed> $diagnostics
     */
    private function diagnosticMessage(array $diagnostics): string
    {
        $window = $diagnostics['filter_window_utc'] ?? array();
        $gps = $diagnostics['gps'] ?? array();
        $adsb = $diagnostics['adsb_trace'] ?? array();
        $validation = isset($diagnostics['validation']) && is_array($diagnostics['validation']) ? $diagnostics['validation'] : array();
        $validationText = $validation
            ? '; GPS validation accepted ' . (string)($validation['accepted_sample_count'] ?? 0)
                . ' of ' . (string)($validation['input_sample_count'] ?? 0)
                . ' samples'
                . '; position rejects ' . (string)($validation['position_reject_count'] ?? 0)
                . '; speed rejects ' . (string)($validation['speed_reject_count'] ?? 0)
                . '; track rejects ' . (string)($validation['track_reject_count'] ?? 0)
            : '';
        return 'No ADS-B samples matched. Filter UTC '
            . (string)($window['start'] ?? '--') . ' to ' . (string)($window['end'] ?? '--')
            . '; GPS UTC ' . (string)($gps['first_timestamp'] ?? '--') . ' to ' . (string)($gps['last_timestamp'] ?? '--')
            . '; ADS-B UTC ' . (string)($adsb['first_timestamp'] ?? '--') . ' to ' . (string)($adsb['last_timestamp'] ?? '--')
            . '; raw rows ' . (string)($adsb['raw_row_count'] ?? 0)
            . '; rows inside window ' . (string)($adsb['rows_inside_time_window'] ?? 0)
            . $validationText . '.';
    }

    private static function epochIso(?float $epoch): ?string
    {
        if ($epoch === null) {
            return null;
        }
        $dt = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $epoch), new DateTimeZone('UTC'));
        return $dt ? $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z') : null;
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

    /**
     * @param array<int,mixed> $row
     */
    private function traceVerticalSpeedFpm(array $row): ?float
    {
        if (isset($row[6]) && is_numeric($row[6])) {
            return (float)$row[6];
        }
        if (isset($row[7]) && is_numeric($row[7])) {
            return (float)$row[7];
        }
        return null;
    }

    /**
     * @param array<int,mixed> $row
     */
    private function traceAltimeterSettingInhg(array $row): ?float
    {
        $keys = array('nav_qnh', 'qnh', 'altimeter', 'altimeter_inhg', 'altimeter_in_hg');
        foreach ($row as $value) {
            if (!is_array($value)) {
                continue;
            }
            foreach ($keys as $key) {
                if (isset($value[$key]) && is_numeric($value[$key])) {
                    return self::normalizeAltimeterSetting((float)$value[$key]);
                }
            }
        }
        return null;
    }

    private static function normalizeAltimeterSetting(float $value): ?float
    {
        if ($value >= 25.0 && $value <= 33.5) {
            return round($value, 2);
        }
        if ($value >= 800.0 && $value <= 1100.0) {
            return round($value / 33.8638866667, 2);
        }
        return null;
    }

    private static function lerpNullable(mixed $a, mixed $b, float $ratio): ?float
    {
        if (!is_numeric($a) || !is_numeric($b)) {
            return is_numeric($a) ? (float)$a : (is_numeric($b) ? (float)$b : null);
        }
        return (float)$a + ((float)$b - (float)$a) * $ratio;
    }

    private static function lerpAngleNullable(mixed $a, mixed $b, float $ratio): ?float
    {
        if (!is_numeric($a) || !is_numeric($b)) {
            return is_numeric($a) ? self::normalizeDegrees((float)$a) : (is_numeric($b) ? self::normalizeDegrees((float)$b) : null);
        }
        return self::normalizeDegrees((float)$a + self::angleDelta((float)$a, (float)$b) * $ratio);
    }

    private static function normalizeDegrees(float $degrees): float
    {
        $normalized = fmod($degrees, 360.0);
        if ($normalized < 0) {
            $normalized += 360.0;
        }
        return $normalized;
    }

    private static function angleDelta(float $from, float $to): float
    {
        $delta = self::normalizeDegrees($to) - self::normalizeDegrees($from);
        while ($delta > 180.0) {
            $delta -= 360.0;
        }
        while ($delta < -180.0) {
            $delta += 360.0;
        }
        return $delta;
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
        $middle = intdiv($count, 2);
        if ($count % 2 === 1) {
            return (float)$values[$middle];
        }
        return ((float)$values[$middle - 1] + (float)$values[$middle]) / 2.0;
    }

    /**
     * @param array<string,mixed> $recording
     * @param array<string,mixed> $payload
     */
    private function storeJson(array $recording, string $type, array $payload): string
    {
        $uid = self::safeStorageName((string)($recording['recording_uid'] ?? 'recording')) ?: 'recording';
        $relativePath = 'storage/cockpit_recorder/adsb/' . $uid . '.' . $type . '.json';
        $absolutePath = CockpitRecorderService::projectRoot() . '/' . $relativePath;
        if (!is_dir(dirname($absolutePath)) && !mkdir(dirname($absolutePath), 0775, true) && !is_dir(dirname($absolutePath))) {
            throw new RuntimeException('Could not create ADS-B storage directory.');
        }
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || file_put_contents($absolutePath, $json) === false) {
            throw new RuntimeException('Could not store ADS-B ' . $type . ' JSON.');
        }
        return $relativePath;
    }

    private static function safeStorageName(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $value) ?? '';
        return trim($value, '._-');
    }

    /**
     * @param list<array<string,mixed>> $samples
     */
    private function storeOwnshipSamples(int $recordingId, array $samples): void
    {
        $this->clearOwnshipSamples($recordingId);
        $stmt = $this->pdo->prepare('
            INSERT INTO ' . self::OWNSHIP_TABLE . ' (
                recording_id, sample_time_utc, seconds_since_start, latitude, longitude, baro_altitude_ft,
                vertical_speed_fpm, groundspeed_kt, track_deg, heading_deg, on_ground, altimeter_setting_inhg,
                raw_json, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ');
        foreach ($samples as $sample) {
            $stmt->execute(array(
                $recordingId,
                self::mysqlDateTimeMillis((string)$sample['sample_time_utc']),
                (float)$sample['seconds_since_start'],
                $sample['latitude'],
                $sample['longitude'],
                $sample['baro_altitude_ft'],
                $sample['vertical_speed_fpm'],
                $sample['groundspeed_kt'],
                $sample['track_deg'],
                $sample['heading_deg'],
                !empty($sample['on_ground']) ? 1 : 0,
                $sample['altimeter_setting_inhg'] ?? null,
                json_encode($sample['raw'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ));
        }
    }

    private function clearOwnshipSamples(int $recordingId): void
    {
        $this->pdo->prepare('DELETE FROM ' . self::OWNSHIP_TABLE . ' WHERE recording_id = ?')->execute(array($recordingId));
    }

    /**
     * @param list<array<string,mixed>> $ownshipSamples
     * @param array<string,mixed> $recording
     * @param array<string,mixed> $window
     * @return array<string,mixed>
     */
    private function enrichTraffic(
        int $recordingId,
        string $ownshipHex,
        array $ownshipSamples,
        array $recording,
        array $window
    ): array {
        return $this->enrichTrafficWithAnchors($recordingId, $ownshipHex, $ownshipSamples, $recording, $window, 'ownship_adsb');
    }

    /**
     * @param array<string,mixed> $recording
     * @param array<string,mixed> $window
     * @return array<string,mixed>
     */
    private function enrichTrafficFromGps(int $recordingId, string $ownshipHex, array $recording, array $window): array
    {
        $gpsRows = $this->gpsAlignmentRows($recording);
        if ($gpsRows === array()) {
            $replayAnchors = $this->replayOwnshipAnchorSamples($recordingId);
            if ($replayAnchors === array()) {
                $this->clearTrafficSamples($recordingId);
                $this->clearTrafficAircraftSamples($recordingId);
                return array('sample_count' => 0, 'provider' => tv_adsb_provider(), 'anchor_source' => 'phone_gps', 'note' => 'No phone GPS or Garmin/replay anchors for traffic correlation.');
            }
            return $this->enrichTrafficWithAnchors($recordingId, $ownshipHex, $replayAnchors, $recording, $window, 'garmin_or_replay_ownship');
        }

        $anchorSamples = array();
        foreach ($gpsRows as $row) {
            $timestamp = trim((string)($row['timestamp'] ?? ''));
            if ($timestamp === '') {
                continue;
            }
            $anchorSamples[] = array(
                'seconds_since_start' => (float)($row['seconds'] ?? 0),
                'sample_time_utc' => $timestamp,
                'latitude' => $row['latitude'] ?? null,
                'longitude' => $row['longitude'] ?? null,
                'baro_altitude_ft' => $row['altitude_ft'] ?? null,
            );
        }

        return $this->enrichTrafficWithAnchors($recordingId, $ownshipHex, $anchorSamples, $recording, $window, 'phone_gps');
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function replayOwnshipAnchorSamples(int $recordingId): array
    {
        if ($recordingId <= 0 || !$this->tablePresent('ipca_cockpit_replay_samples')) {
            return array();
        }
        $stmt = $this->pdo->prepare('
            SELECT sample_time_utc, seconds_since_start, latitude, longitude, baro_altitude_ft, altitude_ft_msl, track_deg, heading_deg
            FROM ipca_cockpit_replay_samples
            WHERE recording_id = ?
              AND sample_time_utc IS NOT NULL
              AND latitude IS NOT NULL
              AND longitude IS NOT NULL
            ORDER BY seconds_since_start ASC
        ');
        $stmt->execute(array($recordingId));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    }

    /**
     * @param list<array<string,mixed>> $anchorSamples
     * @param array<string,mixed> $recording
     * @param array<string,mixed> $window
     * @return array<string,mixed>
     */
    private function enrichTrafficWithAnchors(
        int $recordingId,
        string $ownshipHex,
        array $anchorSamples,
        array $recording,
        array $window,
        string $anchorSource
    ): array {
        $this->clearTrafficSamples($recordingId);

        $anchors = $this->trafficAnchors($anchorSamples);
        if ($anchors === array()) {
            return array('sample_count' => 0, 'provider' => tv_adsb_provider(), 'anchor_source' => $anchorSource, 'note' => 'No anchors for traffic correlation.');
        }

        $candidates = $this->discoverTrafficCandidates($recordingId, $ownshipHex, $anchors, $recording, $window);
        if ($candidates === array()) {
            return array('sample_count' => 0, 'provider' => tv_adsb_provider(), 'anchor_source' => $anchorSource, 'anchor_count' => count($anchors), 'candidate_count' => 0, 'note' => 'No traffic candidate hexes discovered.');
        }

        $this->clearTrafficAircraftSamples($recordingId);
        $candidateTraces = array();
        $traceSources = array();
        $diagnosticsByAircraft = array();
        foreach (array_keys($candidates) as $candidateId) {
            $traceResult = $this->trafficCandidateEpochSamples($candidateId, $window);
            if (($traceResult['samples'] ?? array()) !== array()) {
                $segmented = $this->segmentTrafficSamples((array)$traceResult['samples']);
                $candidateTraces[$candidateId] = $segmented['samples'];
                $traceSources[$candidateId] = $traceResult['source'] ?? 'unknown';
                $diagnosticsByAircraft[$candidateId] = $this->trafficAircraftDiagnostics($candidateId, $candidates[$candidateId], (array)$traceResult['raw_samples'], $segmented);
            }
        }

        $rows = array();
        $enteredRange = array();
        foreach ($anchors as $anchor) {
            foreach ($candidateTraces as $candidateId => $epochSamples) {
                $position = $this->interpolateTrafficAtEpoch($epochSamples, (float)$anchor['epoch'], self::TRAFFIC_HARD_SAMPLE_GAP_S);
                if ($position === null || !isset($position['latitude'], $position['longitude']) || !is_numeric($position['latitude']) || !is_numeric($position['longitude'])) {
                    continue;
                }

                $lat = (float)$position['latitude'];
                $lon = (float)$position['longitude'];
                $distanceNm = self::distanceNm((float)$anchor['latitude'], (float)$anchor['longitude'], $lat, $lon);
                if ($distanceNm > self::TRAFFIC_DISPLAY_RANGE_NM) {
                    continue;
                }
                $enteredRange[$candidateId] = true;

                $ownshipAlt = isset($anchor['baro_altitude_ft']) && is_numeric($anchor['baro_altitude_ft']) ? (float)$anchor['baro_altitude_ft'] : null;
                $trafficAlt = isset($position['altitude_baro_ft']) && is_numeric($position['altitude_baro_ft']) ? (float)$position['altitude_baro_ft'] : null;
                $rows[] = array(
                    'sample_time_utc' => self::epochIso((float)$anchor['epoch']),
                    'seconds_since_start' => (float)$anchor['seconds_since_start'],
                    'aircraft_hex' => str_starts_with($candidateId, '~') ? '' : substr($candidateId, 0, 6),
                    'callsign' => tv_adsb_normalize_label((string)($position['callsign'] ?? '')),
                    'latitude' => $lat,
                    'longitude' => $lon,
                    'altitude_ft' => $trafficAlt,
                    'groundspeed_kt' => isset($position['groundspeed_kt']) && is_numeric($position['groundspeed_kt']) ? (float)$position['groundspeed_kt'] : null,
                    'track_deg' => isset($position['track_true_deg']) && is_numeric($position['track_true_deg']) ? (float)$position['track_true_deg'] : null,
                    'distance_nm' => round($distanceNm, 2),
                    'bearing_deg' => round(tv_adsb_bearing((float)$anchor['latitude'], (float)$anchor['longitude'], $lat, $lon), 1),
                    'relative_altitude_ft' => $ownshipAlt !== null && $trafficAlt !== null ? round($trafficAlt - $ownshipAlt, 1) : null,
                    'raw_json' => json_encode($position, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                );
            }
        }

        $this->storeTrafficSamples($recordingId, $rows);
        foreach ($candidateTraces as $candidateId => $samples) {
            $this->storeTrafficAircraftSamples($recordingId, $candidateId, $samples, (bool)($enteredRange[$candidateId] ?? false));
        }

        return array(
            'sample_count' => count($rows),
            'provider' => tv_adsb_provider(),
            'anchor_source' => $anchorSource,
            'anchor_count' => count($anchors),
            'candidate_count' => count($candidates),
            'trace_count' => count($candidateTraces),
            'trace_sources' => $traceSources,
            'range_nm' => self::TRAFFIC_DISPLAY_RANGE_NM,
            'discovery_range_nm' => self::TRAFFIC_DISCOVERY_RANGE_NM,
            'aircraft_entered_range_count' => count($enteredRange),
            'aircraft' => $diagnosticsByAircraft,
        );
    }

    /**
     * @param array<string,mixed> $window
     * @return array{samples:list<array<string,mixed>>,source:string}
     */
    private function trafficCandidateEpochSamples(string $candidateHex, array $window): array
    {
        $dates = $this->traceDatesForWindow($window);
        $epochSamples = array();
        $rawSamples = array();
        foreach ($dates as $day) {
            try {
                $payload = $this->fetchTraceForAircraftDate($candidateHex, $day);
            } catch (Throwable) {
                continue;
            }
            if (!isset($payload['trace']) || !is_array($payload['trace'])) {
                continue;
            }
            $normalized = $this->normalizeTraceEpochSamples($payload, (float)$window['start_epoch'], (float)$window['end_epoch']);
            $epochSamples = array_merge($epochSamples, $normalized);
            $rawSamples = array_merge($rawSamples, $normalized);
        }
        if ($epochSamples !== array()) {
            return array('samples' => $epochSamples, 'raw_samples' => $rawSamples, 'source' => 'historical');
        }

        $folder = substr($candidateHex, -2);
        foreach (array(
            '/traces/' . rawurlencode($folder) . '/trace_full_' . rawurlencode($candidateHex) . '.json',
            '/traces/' . rawurlencode($folder) . '/trace_recent_' . rawurlencode($candidateHex) . '.json',
        ) as $path) {
            try {
                $payload = tv_adsb_request($path);
            } catch (Throwable) {
                continue;
            }
            if (!isset($payload['trace']) || !is_array($payload['trace'])) {
                continue;
            }
            $samples = $this->normalizeTraceEpochSamples($payload, (float)$window['start_epoch'], (float)$window['end_epoch']);
            if ($samples !== array()) {
                return array('samples' => $samples, 'raw_samples' => $samples, 'source' => str_contains($path, 'trace_recent') ? 'recent' : 'current_full');
            }
        }

        return array('samples' => array(), 'raw_samples' => array(), 'source' => 'none');
    }

    /**
     * @param list<array<string,mixed>> $anchors
     * @param array<string,mixed> $recording
     * @param array<string,mixed> $window
     * @return array<string,array<string,mixed>>
     */
    private function discoverTrafficCandidates(int $recordingId, string $ownshipHex, array $anchors, array $recording, array $window): array
    {
        $this->clearCandidateObservations($recordingId);
        $seen = array();
        $candidates = array();
        $addCandidate = function (string $id, array $evidence) use (&$seen, &$candidates, $ownshipHex, $recordingId): void {
            $id = self::normalizeAircraftIdentifier($id);
            if ($id === '' || $id === $ownshipHex || isset($seen[$id])) {
                return;
            }
            $seen[$id] = true;
            $candidates[$id] = $evidence + array('aircraft_identifier' => $id);
            $this->storeCandidateObservation($recordingId, $id, $evidence);
        };

        if (CockpitAircraftService::tablesPresent($this->pdo)) {
            foreach ((new CockpitAircraftService($this->pdo))->activeAircraft() as $aircraft) {
                $hex = self::normalizeAircraftIdentifier((string)($aircraft['adsb_hex'] ?? ''));
                if ($hex === '' || $hex === $ownshipHex) {
                    continue;
                }
                $addCandidate($hex, array(
                    'callsign' => (string)($aircraft['registration'] ?? ''),
                    'source_type' => 'fleet',
                    'discovery_utc' => $window['start_iso'] ?? gmdate('c'),
                ));
            }
        }

        $nextDiscoveryAt = -self::TRAFFIC_DISCOVERY_INTERVAL_S;
        foreach ($anchors as $anchor) {
            $seconds = (float)($anchor['seconds_since_start'] ?? 0);
            if ($seconds < $nextDiscoveryAt || !isset($anchor['latitude'], $anchor['longitude'], $anchor['epoch'])) {
                continue;
            }
            $nextDiscoveryAt = $seconds + self::TRAFFIC_DISCOVERY_INTERVAL_S;
            foreach ($this->fetchHistoricalSnapshotNearAnchor($anchor) as $aircraft) {
                if (!is_array($aircraft)) {
                    continue;
                }
                $id = self::aircraftIdentifierFromPayload($aircraft);
                if ($id === '' || $id === $ownshipHex) {
                    continue;
                }
                $position = tv_adsb_position($aircraft);
                if ($position === null) {
                    continue;
                }
                $distance = self::distanceNm((float)$anchor['latitude'], (float)$anchor['longitude'], (float)$position['lat'], (float)$position['lon']);
                if ($distance > self::TRAFFIC_DISCOVERY_RANGE_NM) {
                    continue;
                }
                $addCandidate($id, array(
                    'callsign' => tv_adsb_normalize_label((string)($aircraft['flight'] ?? $aircraft['callsign'] ?? '')),
                    'source_type' => (string)($aircraft['type'] ?? $aircraft['source'] ?? 'historical_snapshot'),
                    'discovery_utc' => self::epochIso((float)$anchor['epoch']) ?? gmdate('c'),
                    'discovery_latitude' => (float)$position['lat'],
                    'discovery_longitude' => (float)$position['lon'],
                    'ownship_latitude' => (float)$anchor['latitude'],
                    'ownship_longitude' => (float)$anchor['longitude'],
                    'discovery_distance_nm' => round($distance, 2),
                    'raw' => $aircraft,
                ));
            }
            if (count($candidates) >= self::MAX_CANDIDATE_HEXES) {
                break;
            }
        }

        return array_slice($candidates, 0, self::MAX_CANDIDATE_HEXES, true);
    }

    /**
     * @param array<string,mixed> $anchor
     * @return list<array<string,mixed>>
     */
    private function fetchHistoricalSnapshotNearAnchor(array $anchor): array
    {
        $baseUrl = rtrim((string)getenv('CW_ADSB_EXCHANGE_BASE_URL'), '/');
        $apiKey = trim((string)(getenv('CW_ADSB_EXCHANGE_API_KEY') ?: getenv('CW_ADSBEXCHANGE_API_KEY') ?: ''));
        if ($baseUrl === '' || $apiKey === '') {
            return array();
        }
        $epoch = (float)($anchor['epoch'] ?? 0);
        $query = http_build_query(array(
            'start' => gmdate('Y-m-d\TH:i:s\Z', (int)floor($epoch - 2)),
            'end' => gmdate('Y-m-d\TH:i:s\Z', (int)ceil($epoch + 2)),
            'lat' => (string)$anchor['latitude'],
            'lon' => (string)$anchor['longitude'],
            'radius_nm' => (string)self::TRAFFIC_DISCOVERY_RANGE_NM,
        ));
        $url = $baseUrl . '/historical/corridor?' . $query;
        $context = stream_context_create(array('http' => array(
            'method' => 'GET',
            'header' => "Authorization: Bearer {$apiKey}\r\nAccept: application/json\r\n",
            'timeout' => 20,
        )));
        $raw = @file_get_contents($url, false, $context);
        if ($raw === false || trim($raw) === '') {
            return array();
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return array();
        }
        return is_array($decoded['aircraft'] ?? null) ? $decoded['aircraft'] : (is_array($decoded['samples'] ?? null) ? $decoded['samples'] : array());
    }

    /**
     * @param list<array<string,mixed>> $ownshipSamples
     * @return list<array<string,mixed>>
     */
    private function trafficAnchors(array $ownshipSamples): array
    {
        if ($ownshipSamples === array()) {
            return array();
        }

        $anchors = array();
        $nextAnchorAt = -self::TRAFFIC_ANCHOR_INTERVAL_S;
        foreach ($ownshipSamples as $sample) {
            $seconds = (float)($sample['seconds_since_start'] ?? -1);
            if ($seconds < 0 || $seconds < $nextAnchorAt) {
                continue;
            }
            if (!isset($sample['latitude'], $sample['longitude']) || !is_numeric($sample['latitude']) || !is_numeric($sample['longitude'])) {
                continue;
            }
            $sampleTime = trim((string)($sample['sample_time_utc'] ?? ''));
            $epoch = null;
            if ($sampleTime !== '') {
                try {
                    $epoch = (float)(new DateTimeImmutable($sampleTime))->setTimezone(new DateTimeZone('UTC'))->format('U.u');
                } catch (Throwable) {
                    $epoch = null;
                }
            }
            if ($epoch === null) {
                continue;
            }
            $anchors[] = array(
                'seconds_since_start' => $seconds,
                'epoch' => $epoch,
                'latitude' => (float)$sample['latitude'],
                'longitude' => (float)$sample['longitude'],
                'baro_altitude_ft' => $sample['baro_altitude_ft'] ?? null,
            );
            $nextAnchorAt = $seconds + self::TRAFFIC_ANCHOR_INTERVAL_S;
        }

        $last = $ownshipSamples[count($ownshipSamples) - 1];
        if ($anchors !== array()) {
            $lastSeconds = (float)($last['seconds_since_start'] ?? -1);
            $lastAnchorSeconds = (float)$anchors[count($anchors) - 1]['seconds_since_start'];
            if ($lastSeconds >= 0 && abs($lastSeconds - $lastAnchorSeconds) >= 5.0
                && isset($last['latitude'], $last['longitude']) && is_numeric($last['latitude']) && is_numeric($last['longitude'])
            ) {
                $sampleTime = trim((string)($last['sample_time_utc'] ?? ''));
                try {
                    $epoch = (float)(new DateTimeImmutable($sampleTime))->setTimezone(new DateTimeZone('UTC'))->format('U.u');
                    $anchors[] = array(
                        'seconds_since_start' => $lastSeconds,
                        'epoch' => $epoch,
                        'latitude' => (float)$last['latitude'],
                        'longitude' => (float)$last['longitude'],
                        'baro_altitude_ft' => $last['baro_altitude_ft'] ?? null,
                    );
                } catch (Throwable) {
                }
            }
        }

        return $anchors;
    }

    /**
     * @param list<array<string,mixed>> $anchors
     * @param array<string,mixed> $recording
     * @param array<string,mixed> $window
     * @return list<string>
     */
    private function discoverTrafficCandidateHexes(string $ownshipHex, array $anchors, array $recording, array $window): array
    {
        $seen = array();
        $candidates = array();

        $addHex = static function (string $hex) use (&$seen, &$candidates, $ownshipHex): void {
            $hex = tv_adsb_normalize_hex($hex);
            if ($hex === '' || $hex === $ownshipHex || isset($seen[$hex])) {
                return;
            }
            $seen[$hex] = true;
            $candidates[] = $hex;
        };

        if (CockpitAircraftService::tablesPresent($this->pdo)) {
            foreach ((new CockpitAircraftService($this->pdo))->activeAircraft() as $aircraft) {
                $addHex((string)($aircraft['adsb_hex'] ?? ''));
                if (count($candidates) >= self::MAX_CANDIDATE_HEXES) {
                    return $candidates;
                }
            }
        }

        $timeFrom = (int)floor((float)$window['start_epoch']);
        $timeTo = (int)ceil((float)$window['end_epoch']);
        foreach ($this->nearbyAirportIcaos($anchors) as $icao) {
            foreach (tv_adsb_fetch_airport_operations($icao, $timeFrom, $timeTo) as $operation) {
                $addHex((string)($operation['icao'] ?? ''));
                if (count($candidates) >= self::MAX_CANDIDATE_HEXES) {
                    return $candidates;
                }
            }
        }

        return $candidates;
    }

    /**
     * @param list<array<string,mixed>> $anchors
     * @return list<string>
     */
    private function nearbyAirportIcaos(array $anchors): array
    {
        if ($anchors === array()) {
            return array();
        }

        $minLat = $maxLat = (float)$anchors[0]['latitude'];
        $minLon = $maxLon = (float)$anchors[0]['longitude'];
        foreach ($anchors as $anchor) {
            $minLat = min($minLat, (float)$anchor['latitude']);
            $maxLat = max($maxLat, (float)$anchor['latitude']);
            $minLon = min($minLon, (float)$anchor['longitude']);
            $maxLon = max($maxLon, (float)$anchor['longitude']);
        }
        $centerLat = ($minLat + $maxLat) / 2;
        $centerLon = ($minLon + $maxLon) / 2;

        $icaos = array();
        foreach (tv_adsb_airports() as $icao => $airport) {
            if (!isset($airport['lat'], $airport['lon'])) {
                continue;
            }
            $distanceNm = self::distanceNm($centerLat, $centerLon, (float)$airport['lat'], (float)$airport['lon']);
            if ($distanceNm <= self::TRAFFIC_NEARBY_AIRPORT_NM) {
                $icaos[] = (string)$icao;
            }
        }

        return $icaos;
    }

    /**
     * @param array<string,mixed> $window
     * @return list<DateTimeImmutable>
     */
    private function traceDatesForWindow(array $window): array
    {
        $start = new DateTimeImmutable((string)$window['start_iso']);
        $end = new DateTimeImmutable((string)$window['end_iso']);
        $dates = array();
        for ($day = $start->setTime(0, 0)->modify('-1 day'); $day <= $end->setTime(0, 0)->modify('+1 day'); $day = $day->modify('+1 day')) {
            $dates[] = $day;
        }
        return $dates;
    }

    /**
     * @return array<string,mixed>
     */
    private function fetchTraceForAircraftDate(string $aircraftId, DateTimeImmutable $day): array
    {
        $payload = $this->fetchHistoricalTraceForDate($aircraftId, $day);
        if ($payload !== array()) {
            $this->storeRawTraceEvidence($aircraftId, $day, '/traces-hist/' . $day->format('Y/m/d') . '/trace_full_' . rawurlencode($aircraftId), $payload);
        }
        return $payload;
    }

    private function storeRawTraceEvidence(string $aircraftId, DateTimeImmutable $day, string $route, array $payload): ?int
    {
        if (!$this->tablePresent(self::RAW_TRACE_TABLE)) {
            return null;
        }
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            return null;
        }
        $sha = hash('sha256', $body);
        $dir = CockpitRecorderService::projectRoot() . '/storage/cockpit_recorder/adsb_traces/' . $day->format('Y/m/d');
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        $relativePath = 'storage/cockpit_recorder/adsb_traces/' . $day->format('Y/m/d') . '/' . self::safeStorageName($aircraftId) . '-' . $sha . '.json';
        $absolutePath = CockpitRecorderService::projectRoot() . '/' . $relativePath;
        if (!is_file($absolutePath)) {
            file_put_contents($absolutePath, $body);
        }
        $stmt = $this->pdo->prepare('
            INSERT IGNORE INTO ' . self::RAW_TRACE_TABLE . '
              (provider, aircraft_identifier, trace_date_utc, request_route, http_status, content_type, sha256, storage_path, byte_size)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute(array(tv_adsb_provider(), $aircraftId, $day->format('Y-m-d'), $route, 200, 'application/json', $sha, $relativePath, strlen($body)));
        $idStmt = $this->pdo->prepare('SELECT id FROM ' . self::RAW_TRACE_TABLE . ' WHERE sha256 = ? LIMIT 1');
        $idStmt->execute(array($sha));
        $id = (int)$idStmt->fetchColumn();
        return $id > 0 ? $id : null;
    }

    /**
     * @param array<string,mixed> $trace
     * @return list<array<string,mixed>>
     */
    private function normalizeTraceEpochSamples(array $trace, float $startEpoch, float $endEpoch): array
    {
        $base = isset($trace['timestamp']) && is_numeric($trace['timestamp']) ? (float)$trace['timestamp'] : null;
        $rows = isset($trace['trace']) && is_array($trace['trace']) ? $trace['trace'] : array();
        if ($base === null || !$rows) {
            return array();
        }

        $callsign = tv_adsb_normalize_label((string)($trace['r'] ?? ($trace['flight'] ?? '')));
        $samples = array();
        foreach ($rows as $row) {
            if (!is_array($row) || count($row) < 3 || !isset($row[0], $row[1], $row[2]) || !is_numeric($row[0]) || !is_numeric($row[1]) || !is_numeric($row[2])) {
                continue;
            }
            $epoch = $base + (float)$row[0];
            if ($epoch < $startEpoch - 60 || $epoch > $endEpoch + 60) {
                continue;
            }
            $flags = $this->decodeTraceFlags($row);
            $alt = $row[3] ?? null;
            $geomAlt = null;
            foreach ($row as $value) {
                if (is_array($value)) {
                    foreach (array('alt_geom', 'geom_altitude', 'altitude_geom', 'geom_altitude_ft') as $key) {
                        if (isset($value[$key]) && is_numeric($value[$key])) {
                            $geomAlt = (float)$value[$key];
                            break 2;
                        }
                    }
                }
            }
            $samples[] = array(
                'epoch' => $epoch,
                'latitude' => (float)$row[1],
                'longitude' => (float)$row[2],
                'altitude_baro_ft' => is_numeric($alt) ? (float)$alt : null,
                'altitude_geom_ft' => $geomAlt,
                'groundspeed_kt' => isset($row[4]) && is_numeric($row[4]) ? (float)$row[4] : null,
                'track_true_deg' => isset($row[5]) && is_numeric($row[5]) ? (float)$row[5] : null,
                'vertical_rate_baro_fpm' => isset($row[6]) && is_numeric($row[6]) ? (float)$row[6] : (isset($row[7]) && is_numeric($row[7]) && !$flags['geometric_vertical_rate'] ? (float)$row[7] : null),
                'vertical_rate_geom_fpm' => isset($row[7]) && is_numeric($row[7]) && $flags['geometric_vertical_rate'] ? (float)$row[7] : null,
                'callsign' => $callsign,
                'on_ground' => is_string($alt) && strtolower($alt) === 'ground',
                'stale' => $flags['stale_position'],
                'new_leg' => $flags['new_leg'],
                'altitude_source' => $flags['geometric_altitude'] ? 'geometric' : (is_numeric($alt) ? 'baro' : ''),
                'vertical_rate_source' => $flags['geometric_vertical_rate'] ? 'geometric' : (isset($row[6]) || isset($row[7]) ? 'baro' : ''),
                'raw' => $row,
            );
        }

        usort($samples, fn(array $a, array $b): int => ((float)$a['epoch']) <=> ((float)$b['epoch']));
        return $samples;
    }

    /**
     * @param array<int,mixed> $row
     * @return array{stale_position:bool,new_leg:bool,geometric_vertical_rate:bool,geometric_altitude:bool}
     */
    private function decodeTraceFlags(array $row): array
    {
        $flagValue = null;
        foreach ($row as $value) {
            if (is_array($value)) {
                foreach (array('flags', 'trace_flags', 'status') as $key) {
                    if (isset($value[$key]) && is_numeric($value[$key])) {
                        $flagValue = (int)$value[$key];
                        break 2;
                    }
                }
                if (!empty($value['stale']) || !empty($value['stale_position'])) {
                    $flagValue = ($flagValue ?? 0) | 1;
                }
                if (!empty($value['new_leg'])) {
                    $flagValue = ($flagValue ?? 0) | 2;
                }
            }
        }
        foreach (array(8, 9) as $index) {
            if ($flagValue === null && isset($row[$index]) && is_numeric($row[$index]) && (int)$row[$index] >= 0 && (int)$row[$index] <= 255) {
                $flagValue = (int)$row[$index];
            }
        }
        $flagValue = $flagValue ?? 0;
        return array(
            'stale_position' => (bool)($flagValue & 1),
            'new_leg' => (bool)($flagValue & 2),
            'geometric_vertical_rate' => (bool)($flagValue & 4),
            'geometric_altitude' => (bool)($flagValue & 8),
        );
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function storeTrafficSamples(int $recordingId, array $rows): void
    {
        if ($rows === array()) {
            return;
        }

        $stmt = $this->pdo->prepare('
            INSERT INTO ' . self::TRAFFIC_TABLE . ' (
                recording_id, sample_time_utc, seconds_since_start, aircraft_hex, callsign,
                latitude, longitude, altitude_ft, groundspeed_kt, track_deg,
                distance_nm, bearing_deg, relative_altitude_ft, raw_json, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ');
        foreach ($rows as $row) {
            $stmt->execute(array(
                $recordingId,
                self::mysqlDateTimeMillis((string)($row['sample_time_utc'] ?? '')),
                (float)($row['seconds_since_start'] ?? 0),
                (string)($row['aircraft_hex'] ?? ''),
                (string)($row['callsign'] ?? ''),
                $row['latitude'] ?? null,
                $row['longitude'] ?? null,
                $row['altitude_ft'] ?? null,
                $row['groundspeed_kt'] ?? null,
                $row['track_deg'] ?? null,
                $row['distance_nm'] ?? null,
                $row['bearing_deg'] ?? null,
                $row['relative_altitude_ft'] ?? null,
                $row['raw_json'] ?? null,
            ));
        }
    }

    private function clearTrafficSamples(int $recordingId): void
    {
        $this->pdo->prepare('DELETE FROM ' . self::TRAFFIC_TABLE . ' WHERE recording_id = ?')->execute(array($recordingId));
    }

    private function clearTrafficAircraftSamples(int $recordingId): void
    {
        if ($this->tablePresent(self::AIRCRAFT_SAMPLE_TABLE)) {
            $this->pdo->prepare('DELETE FROM ' . self::AIRCRAFT_SAMPLE_TABLE . ' WHERE recording_id = ?')->execute(array($recordingId));
        }
    }

    private function clearCandidateObservations(int $recordingId): void
    {
        if ($this->tablePresent(self::CANDIDATE_TABLE)) {
            $this->pdo->prepare('DELETE FROM ' . self::CANDIDATE_TABLE . ' WHERE recording_id = ?')->execute(array($recordingId));
        }
    }

    /**
     * @param array<string,mixed> $evidence
     */
    private function storeCandidateObservation(int $recordingId, string $aircraftId, array $evidence): void
    {
        if (!$this->tablePresent(self::CANDIDATE_TABLE)) {
            return;
        }
        $stmt = $this->pdo->prepare('
            INSERT INTO ' . self::CANDIDATE_TABLE . '
              (recording_id, aircraft_identifier, callsign, discovery_utc, discovery_latitude, discovery_longitude,
               ownship_latitude, ownship_longitude, discovery_distance_nm, source_type, raw_json)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute(array(
            $recordingId,
            $aircraftId,
            substr((string)($evidence['callsign'] ?? ''), 0, 32),
            self::mysqlDateTimeMillis((string)($evidence['discovery_utc'] ?? gmdate('c'))),
            $evidence['discovery_latitude'] ?? null,
            $evidence['discovery_longitude'] ?? null,
            $evidence['ownship_latitude'] ?? null,
            $evidence['ownship_longitude'] ?? null,
            $evidence['discovery_distance_nm'] ?? null,
            substr((string)($evidence['source_type'] ?? ''), 0, 64),
            isset($evidence['raw']) ? json_encode($evidence['raw'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
        ));
    }

    /**
     * @param list<array<string,mixed>> $samples
     * @return array{samples:list<array<string,mixed>>,leg_count:int,rejected:int,discontinuities:int}
     */
    private function segmentTrafficSamples(array $samples): array
    {
        usort($samples, fn(array $a, array $b): int => ((float)($a['epoch'] ?? 0)) <=> ((float)($b['epoch'] ?? 0)));
        $result = array();
        $last = null;
        $leg = 1;
        $rejected = 0;
        $discontinuities = 0;
        $seen = array();
        foreach ($samples as $sample) {
            if (!isset($sample['epoch'], $sample['latitude'], $sample['longitude']) || !is_numeric($sample['epoch']) || !is_numeric($sample['latitude']) || !is_numeric($sample['longitude'])) {
                $rejected++;
                continue;
            }
            if (!empty($sample['stale'])) {
                $rejected++;
                continue;
            }
            $key = sprintf('%.3f|%.7f|%.7f', (float)$sample['epoch'], (float)$sample['latitude'], (float)$sample['longitude']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if ($last !== null) {
                $dt = (float)$sample['epoch'] - (float)$last['epoch'];
                $jumpNm = self::distanceNm((float)$last['latitude'], (float)$last['longitude'], (float)$sample['latitude'], (float)$sample['longitude']);
                $speedKt = $dt > 0 ? ($jumpNm / ($dt / 3600.0)) : PHP_FLOAT_MAX;
                if (!empty($sample['new_leg']) || $dt <= 0 || $dt > self::TRAFFIC_HARD_SAMPLE_GAP_S || $jumpNm > self::TRAFFIC_MAX_JUMP_NM || $speedKt > self::TRAFFIC_MAX_SPEED_KT) {
                    $leg++;
                    $discontinuities++;
                }
            }
            $sample['leg_id'] = $leg;
            $result[] = $sample;
            $last = $sample;
        }
        return array('samples' => $result, 'leg_count' => $leg, 'rejected' => $rejected, 'discontinuities' => $discontinuities);
    }

    /**
     * @param list<array<string,mixed>> $samples
     * @return array<string,mixed>|null
     */
    private function interpolateTrafficAtEpoch(array $samples, float $epoch, float $maxGap): ?array
    {
        $before = null;
        $after = null;
        foreach ($samples as $sample) {
            $sampleEpoch = (float)($sample['epoch'] ?? 0);
            if ($sampleEpoch <= $epoch) {
                $before = $sample;
                continue;
            }
            $after = $sample;
            break;
        }
        if ($before === null || $after === null) {
            $nearest = $before ?? $after;
            return $nearest !== null && abs((float)$nearest['epoch'] - $epoch) <= 2.0 ? $nearest : null;
        }
        $gap = (float)$after['epoch'] - (float)$before['epoch'];
        if ($gap <= 0 || $gap > $maxGap || (int)($before['leg_id'] ?? 0) !== (int)($after['leg_id'] ?? -1)) {
            return null;
        }
        $ratio = max(0.0, min(1.0, ($epoch - (float)$before['epoch']) / $gap));
        return array(
            'epoch' => $epoch,
            'latitude' => self::lerpNullable($before['latitude'] ?? null, $after['latitude'] ?? null, $ratio),
            'longitude' => self::lerpNullable($before['longitude'] ?? null, $after['longitude'] ?? null, $ratio),
            'altitude_baro_ft' => self::lerpNullable($before['altitude_baro_ft'] ?? null, $after['altitude_baro_ft'] ?? null, $ratio),
            'altitude_geom_ft' => self::lerpNullable($before['altitude_geom_ft'] ?? null, $after['altitude_geom_ft'] ?? null, $ratio),
            'groundspeed_kt' => self::lerpNullable($before['groundspeed_kt'] ?? null, $after['groundspeed_kt'] ?? null, $ratio),
            'track_true_deg' => self::lerpAngleNullable($before['track_true_deg'] ?? null, $after['track_true_deg'] ?? null, $ratio),
            'vertical_rate_baro_fpm' => self::lerpNullable($before['vertical_rate_baro_fpm'] ?? null, $after['vertical_rate_baro_fpm'] ?? null, $ratio),
            'vertical_rate_geom_fpm' => self::lerpNullable($before['vertical_rate_geom_fpm'] ?? null, $after['vertical_rate_geom_fpm'] ?? null, $ratio),
            'callsign' => (string)($before['callsign'] ?? $after['callsign'] ?? ''),
            'leg_id' => (int)($before['leg_id'] ?? 1),
        );
    }

    /**
     * @param list<array<string,mixed>> $samples
     */
    private function storeTrafficAircraftSamples(int $recordingId, string $aircraftId, array $samples, bool $enteredRange): void
    {
        if (!$this->tablePresent(self::AIRCRAFT_SAMPLE_TABLE) || $samples === array()) {
            return;
        }
        $stmt = $this->pdo->prepare('
            INSERT IGNORE INTO ' . self::AIRCRAFT_SAMPLE_TABLE . '
              (recording_id, aircraft_identifier, sample_time_utc, seconds_since_start, latitude, longitude,
               altitude_baro_ft, altitude_geom_ft, groundspeed_kt, track_true_deg, vertical_rate_baro_fpm,
               vertical_rate_geom_fpm, callsign, source_type, on_ground, stale_position, new_leg, leg_id,
               altitude_source, vertical_rate_source, raw_json)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $startStmt = $this->pdo->prepare('SELECT started_at FROM ipca_cockpit_recordings WHERE id = ? LIMIT 1');
        $startStmt->execute(array($recordingId));
        $startEpoch = strtotime((string)$startStmt->fetchColumn());
        foreach ($samples as $sample) {
            $epoch = (float)($sample['epoch'] ?? 0);
            $stmt->execute(array(
                $recordingId,
                $aircraftId,
                self::mysqlDateTimeMillis(self::epochIso($epoch) ?? gmdate('c', (int)$epoch)),
                $startEpoch !== false ? max(0, $epoch - (float)$startEpoch) : 0,
                $sample['latitude'] ?? null,
                $sample['longitude'] ?? null,
                $sample['altitude_baro_ft'] ?? null,
                $sample['altitude_geom_ft'] ?? null,
                $sample['groundspeed_kt'] ?? null,
                $sample['track_true_deg'] ?? null,
                $sample['vertical_rate_baro_fpm'] ?? null,
                $sample['vertical_rate_geom_fpm'] ?? null,
                substr((string)($sample['callsign'] ?? ''), 0, 32),
                'adsbexchange_trace',
                !empty($sample['on_ground']) ? 1 : 0,
                !empty($sample['stale']) ? 1 : 0,
                !empty($sample['new_leg']) ? 1 : 0,
                (int)($sample['leg_id'] ?? 1),
                (string)($sample['altitude_source'] ?? ''),
                (string)($sample['vertical_rate_source'] ?? ''),
                isset($sample['raw']) ? json_encode($sample['raw'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            ));
        }
    }

    /**
     * @param array<string,mixed> $candidate
     * @param list<array<string,mixed>> $rawSamples
     * @param array{samples:list<array<string,mixed>>,leg_count:int,rejected:int,discontinuities:int} $segmented
     * @return array<string,mixed>
     */
    private function trafficAircraftDiagnostics(string $aircraftId, array $candidate, array $rawSamples, array $segmented): array
    {
        $samples = $segmented['samples'];
        $epochs = array_values(array_map(static fn(array $s): float => (float)($s['epoch'] ?? 0), $samples));
        $gaps = array();
        for ($i = 1; $i < count($epochs); $i++) {
            $gaps[] = max(0.0, $epochs[$i] - $epochs[$i - 1]);
        }
        sort($gaps);
        return array(
            'aircraft_identifier' => $aircraftId,
            'callsigns_observed' => array_values(array_unique(array_filter(array_map(static fn(array $s): string => trim((string)($s['callsign'] ?? '')), $samples)))),
            'source_types_observed' => array_values(array_unique(array_filter(array((string)($candidate['source_type'] ?? ''))))),
            'first_sample_utc' => $epochs !== array() ? self::epochIso(min($epochs)) : null,
            'last_sample_utc' => $epochs !== array() ? self::epochIso(max($epochs)) : null,
            'raw_sample_count' => count($rawSamples),
            'normalized_sample_count' => count($samples),
            'usable_sample_count' => count($samples),
            'rejected_sample_count' => (int)$segmented['rejected'],
            'leg_count' => (int)$segmented['leg_count'],
            'minimum_sample_gap_s' => $gaps !== array() ? min($gaps) : null,
            'median_sample_gap_s' => $gaps !== array() ? $gaps[(int)floor(count($gaps) / 2)] : null,
            'maximum_sample_gap_s' => $gaps !== array() ? max($gaps) : null,
            'discovery_distance_nm' => $candidate['discovery_distance_nm'] ?? null,
            'renderable' => count($samples) >= 2,
            'not_renderable_reason' => count($samples) >= 2 ? null : (count($samples) === 1 ? 'only_one_usable_sample' : 'no_usable_trace'),
            'discontinuities_detected' => (int)$segmented['discontinuities'],
        );
    }

    private function setStatus(
        int $recordingId,
        string $status,
        string $hex,
        ?string $start,
        ?string $end,
        int $ownshipCount,
        int $trafficCount,
        ?string $error,
        ?string $rawPath = null,
        ?string $normalizedPath = null
    ): void {
        $stmt = $this->pdo->prepare('
            INSERT INTO ' . self::ADSB_TABLE . ' (
                recording_id, status, provider, aircraft_hex, query_start_utc, query_end_utc,
                raw_storage_path, normalized_storage_path, ownship_sample_count, traffic_sample_count,
                error_message, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                provider = VALUES(provider),
                aircraft_hex = VALUES(aircraft_hex),
                query_start_utc = VALUES(query_start_utc),
                query_end_utc = VALUES(query_end_utc),
                raw_storage_path = COALESCE(VALUES(raw_storage_path), raw_storage_path),
                normalized_storage_path = COALESCE(VALUES(normalized_storage_path), normalized_storage_path),
                ownship_sample_count = VALUES(ownship_sample_count),
                traffic_sample_count = VALUES(traffic_sample_count),
                error_message = VALUES(error_message),
                updated_at = CURRENT_TIMESTAMP
        ');
        $stmt->execute(array($recordingId, $status, tv_adsb_provider() === 'gateway' ? 'adsbexchange_gateway' : 'adsbexchange_trace', $hex, $start, $end, $rawPath, $normalizedPath, $ownshipCount, $trafficCount, $error));
        $this->pdo->prepare('UPDATE ' . self::RECORDINGS_TABLE . ' SET adsb_status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute(array($status, $recordingId));
    }

    private static function mysqlDateTimeMillis(string $value): ?string
    {
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
        } catch (Throwable) {
            return null;
        }
    }
}
