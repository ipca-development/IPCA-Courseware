<?php
declare(strict_types=1);

require_once __DIR__ . '/CockpitRecorderService.php';
require_once __DIR__ . '/tv_adsb_status.php';

/**
 * ADS-B enrichment for Cockpit Recorder flight reconstruction.
 *
 * Phase 1 fetches ownship historical/recent trace data by ICAO hex.
 */
final class CockpitAdsbEnrichmentService
{
    private const RECORDINGS_TABLE = 'ipca_cockpit_recordings';
    private const ADSB_TABLE = 'ipca_cockpit_adsb_enrichments';
    private const OWNSHIP_TABLE = 'ipca_cockpit_adsb_ownship_samples';
    private const TRAFFIC_TABLE = 'ipca_cockpit_adsb_traffic_samples';

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
        if ($hex === '') {
            $this->setStatus($recordingId, 'not_available', '', null, null, 0, 0, 'Recording has no aircraft ADS-B hex.');
            return array('ok' => false, 'status' => 'not_available', 'error' => 'Recording has no aircraft ADS-B hex.');
        }

        $window = $this->recordingWindow($recording);
        $this->setStatus($recordingId, 'fetching', $hex, $window['start_mysql'], $window['end_mysql'], 0, 0, null);

        try {
            $raw = $this->fetchTrace($hex, $window);
            $rawPath = $this->storeJson($recording, 'raw', $raw);
            $samples = $this->normalizeTrace($raw, $window['start_epoch'], $window['end_epoch']);
            if (!$samples) {
                $this->setStatus($recordingId, 'not_available', $hex, $window['start_mysql'], $window['end_mysql'], 0, 0, 'No ADS-B trace samples found inside recording time window.');
                return array('ok' => false, 'status' => 'not_available', 'error' => 'No ADS-B trace samples found inside recording time window.', 'raw_storage_path' => $rawPath);
            }

            $this->setStatus($recordingId, 'processing', $hex, $window['start_mysql'], $window['end_mysql'], 0, 0, null, $rawPath, null);
            $normalized = array(
                'provider' => 'adsbexchange_trace',
                'hex' => $hex,
                'recording_id' => (string)$recording['recording_uid'],
                'query_start_utc' => $window['start_iso'],
                'query_end_utc' => $window['end_iso'],
                'samples' => $samples,
            );
            $normalizedPath = $this->storeJson($recording, 'normalized', $normalized);
            $this->storeOwnshipSamples($recordingId, $samples);
            $this->setStatus($recordingId, 'ready', $hex, $window['start_mysql'], $window['end_mysql'], count($samples), 0, null, $rawPath, $normalizedPath);

            return array(
                'ok' => true,
                'status' => 'ready',
                'recording_id' => $recordingId,
                'ownship_sample_count' => count($samples),
                'raw_storage_path' => $rawPath,
                'normalized_storage_path' => $normalizedPath,
            );
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
        $start = (new DateTimeImmutable($startRaw, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        $duration = max(0.0, (float)($recording['duration_seconds'] ?? 0));
        $end = $start->modify('+' . (string)max(1, (int)ceil($duration)) . ' seconds');

        return array(
            'start_epoch' => (float)$start->format('U.u'),
            'end_epoch' => (float)$end->format('U.u'),
            'start_iso' => $start->format('c'),
            'end_iso' => $end->format('c'),
            'start_mysql' => $start->format('Y-m-d H:i:s'),
            'end_mysql' => $end->format('Y-m-d H:i:s'),
        );
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

        throw new RuntimeException('ADS-B trace fetch failed. ' . implode(' | ', $errors));
    }

    /**
     * @param array<string,mixed> $window
     * @return array<string,mixed>
     */
    private function fetchHistoricalTraces(string $hex, array $window): array
    {
        $start = new DateTimeImmutable((string)$window['start_iso']);
        $end = new DateTimeImmutable((string)$window['end_iso']);
        $dates = array();
        for ($day = $start->setTime(0, 0); $day <= $end->setTime(0, 0); $day = $day->modify('+1 day')) {
            $dates[] = $day;
        }

        $trace = array();
        $sources = array();
        $errors = array();
        foreach ($dates as $day) {
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
            'source' => 'adsbexchange_globe_history',
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
        $folder = substr($hex, -2);
        $base = rtrim((string)(getenv('CW_ADSBEXCHANGE_HISTORY_BASE') ?: 'https://globe.adsbexchange.com/globe_history'), '/');
        $url = $base . '/' . $day->format('Y/m/d') . '/traces/' . rawurlencode($folder) . '/trace_full_' . rawurlencode($hex) . '.json';
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
                'vertical_speed_fpm' => isset($row[7]) && is_numeric($row[7]) ? (float)$row[7] : null,
                'groundspeed_kt' => isset($row[4]) && is_numeric($row[4]) ? (float)$row[4] : null,
                'track_deg' => isset($row[5]) && is_numeric($row[5]) ? (float)$row[5] : null,
                'heading_deg' => isset($row[8]) && is_array($row[8]) && isset($row[8]['mag_heading']) && is_numeric($row[8]['mag_heading']) ? (float)$row[8]['mag_heading'] : null,
                'on_ground' => $onGround,
                'raw' => $row,
            );
        }

        usort($samples, fn(array $a, array $b): int => ((float)$a['seconds_since_start']) <=> ((float)$b['seconds_since_start']));
        return $samples;
    }

    /**
     * @param array<string,mixed> $recording
     * @param array<string,mixed> $payload
     */
    private function storeJson(array $recording, string $type, array $payload): string
    {
        $uid = CockpitRecorderService::normalizeRecordingUid((string)($recording['recording_uid'] ?? 'recording')) ?: 'recording';
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

    /**
     * @param list<array<string,mixed>> $samples
     */
    private function storeOwnshipSamples(int $recordingId, array $samples): void
    {
        $this->pdo->prepare('DELETE FROM ' . self::OWNSHIP_TABLE . ' WHERE recording_id = ?')->execute(array($recordingId));
        $stmt = $this->pdo->prepare('
            INSERT INTO ' . self::OWNSHIP_TABLE . ' (
                recording_id, sample_time_utc, seconds_since_start, latitude, longitude, baro_altitude_ft,
                vertical_speed_fpm, groundspeed_kt, track_deg, heading_deg, on_ground, raw_json, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
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
                json_encode($sample['raw'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ));
        }
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
        $stmt->execute(array($recordingId, $status, 'adsbexchange_trace', $hex, $start, $end, $rawPath, $normalizedPath, $ownshipCount, $trafficCount, $error));
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
