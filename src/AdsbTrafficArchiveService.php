<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';
require_once __DIR__ . '/CockpitRecorderService.php';
require_once __DIR__ . '/tv_adsb_status.php';

final class AdsbTrafficArchiveService
{
    private const KTRM_LAT = 33.626701;
    private const KTRM_LON = -116.160156;
    private const KTRM_RADIUS_NM = 15.0;
    private const DEFAULT_BUCKET_SECONDS = 300;

    public function __construct(private PDO $pdo)
    {
    }

    public function ensureTables(): void
    {
        $sql = file_get_contents(__DIR__ . '/../scripts/sql/2026_07_17_adsb_traffic_archive.sql');
        if ($sql === false) {
            throw new RuntimeException('ADS-B archive migration file is missing.');
        }
        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: array() as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $this->pdo->exec($statement);
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function scheduleKtrmCoverage(string $startUtc, string $endUtc, int $bucketSeconds = self::DEFAULT_BUCKET_SECONDS): array
    {
        $this->ensureTables();
        $start = $this->utcDate($startUtc);
        $end = $this->utcDate($endUtc);
        if ($end <= $start) {
            throw new RuntimeException('ADS-B coverage end time must be after start time.');
        }
        $bucketSeconds = max(60, min(900, $bucketSeconds));
        $jobUuid = AuditEventService::uuid();
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_adsb_coverage_jobs
              (job_uuid, scope, status, center_latitude, center_longitude, radius_nm, query_start_utc, query_end_utc, bucket_seconds)
            VALUES (?, 'ktrm_baseline', 'pending', ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute(array(
            $jobUuid,
            self::KTRM_LAT,
            self::KTRM_LON,
            self::KTRM_RADIUS_NM,
            $this->mysqlDate($start),
            $this->mysqlDate($end),
            $bucketSeconds,
        ));
        $jobId = (int)$this->pdo->lastInsertId();
        $created = $this->createTilesForJob($jobId, 'ktrm_baseline', self::KTRM_LAT, self::KTRM_LON, self::KTRM_RADIUS_NM, $start, $end, $bucketSeconds);
        $this->pdo->prepare('UPDATE ipca_adsb_coverage_jobs SET tile_count = ?, updated_at = CURRENT_TIMESTAMP(3) WHERE id = ?')
            ->execute(array($created, $jobId));
        return array('ok' => true, 'job_id' => $jobId, 'job_uuid' => $jobUuid, 'tiles_created' => $created);
    }

    /**
     * @return array<string,mixed>
     */
    public function scheduleRecentKtrmCoverage(int $lookbackMinutes = 180, int $bucketSeconds = self::DEFAULT_BUCKET_SECONDS): array
    {
        $end = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $start = $end->modify('-' . max(5, min(1440, $lookbackMinutes)) . ' minutes');
        return $this->scheduleKtrmCoverage($start->format(DateTimeInterface::ATOM), $end->format(DateTimeInterface::ATOM), $bucketSeconds);
    }

    /**
     * @return array<string,mixed>
     */
    public function fetchNextPendingTile(): array
    {
        $this->ensureTables();
        $this->pdo->beginTransaction();
        try {
            $row = $this->pdo->query("
                SELECT *
                FROM ipca_adsb_coverage_tiles
                WHERE status IN ('pending', 'failed')
                  AND attempt_count < 5
                ORDER BY bucket_start_utc ASC, id ASC
                LIMIT 1
                FOR UPDATE
            ")->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $this->pdo->commit();
                return array('ok' => true, 'status' => 'idle', 'message' => 'No pending ADS-B archive tiles.');
            }
            $tileId = (int)$row['id'];
            $this->pdo->prepare("UPDATE ipca_adsb_coverage_tiles SET status = 'fetching', attempt_count = attempt_count + 1, updated_at = CURRENT_TIMESTAMP(3) WHERE id = ?")
                ->execute(array($tileId));
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        try {
            $payload = $this->fetchProviderTile($row);
            $rawPayloadId = $this->storeRawPayload($payload, $row);
            $samples = $this->normalizeProviderPayload($payload, $row, $rawPayloadId);
            $inserted = $this->storeTrafficSamples($samples);
            $this->pdo->prepare("
                UPDATE ipca_adsb_coverage_tiles
                SET status = 'ready',
                    raw_payload_id = ?,
                    sample_count = ?,
                    empty_result = ?,
                    last_error = NULL,
                    fetched_at = CURRENT_TIMESTAMP(3),
                    updated_at = CURRENT_TIMESTAMP(3)
                WHERE id = ?
            ")->execute(array($rawPayloadId, count($samples), count($samples) === 0 ? 1 : 0, (int)$row['id']));
            $this->refreshJobStats((int)($row['job_id'] ?? 0));
            return array('ok' => true, 'status' => 'ready', 'tile_id' => (int)$row['id'], 'samples' => count($samples), 'inserted' => $inserted);
        } catch (Throwable $e) {
            $this->pdo->prepare("
                UPDATE ipca_adsb_coverage_tiles
                SET status = 'failed', last_error = ?, updated_at = CURRENT_TIMESTAMP(3)
                WHERE id = ?
            ")->execute(array($e->getMessage(), (int)$row['id']));
            $this->refreshJobStats((int)($row['job_id'] ?? 0));
            throw $e;
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function status(): array
    {
        $this->ensureTables();
        $tileRows = $this->pdo->query("
            SELECT status, COUNT(*) AS total, COALESCE(SUM(sample_count), 0) AS samples
            FROM ipca_adsb_coverage_tiles
            GROUP BY status
        ")->fetchAll(PDO::FETCH_ASSOC) ?: array();
        $coverage = array('pending' => 0, 'fetching' => 0, 'ready' => 0, 'failed' => 0, 'samples' => 0);
        foreach ($tileRows as $row) {
            $status = (string)($row['status'] ?? '');
            if ($status !== '') {
                $coverage[$status] = (int)($row['total'] ?? 0);
            }
            $coverage['samples'] += (int)($row['samples'] ?? 0);
        }
        $last = $this->pdo->query("SELECT MAX(bucket_end_utc) FROM ipca_adsb_coverage_tiles WHERE scope = 'ktrm_baseline' AND status = 'ready'")->fetchColumn();
        return array(
            'ok' => true,
            'provider' => tv_adsb_provider(),
            'ktrm' => array(
                'center_latitude' => self::KTRM_LAT,
                'center_longitude' => self::KTRM_LON,
                'radius_nm' => self::KTRM_RADIUS_NM,
                'latest_ready_bucket_end_utc' => $last ?: null,
            ),
            'coverage' => $coverage,
        );
    }

    /**
     * @return array{traffic:list<array<string,mixed>>,meta:array<string,mixed>}
     */
    public function trafficForReplay(string $startUtc, string $endUtc, float $centerLat = self::KTRM_LAT, float $centerLon = self::KTRM_LON, float $radiusNm = self::KTRM_RADIUS_NM): array
    {
        $this->ensureTables();
        $start = $this->mysqlDate($this->utcDate($startUtc));
        $end = $this->mysqlDate($this->utcDate($endUtc));
        $radiusNm = max(1.0, min(25.0, $radiusNm));
        $stmt = $this->pdo->prepare("
            SELECT
              id,
              sample_time_utc,
              aircraft_hex,
              callsign,
              latitude,
              longitude,
              altitude_ft,
              groundspeed_kt,
              track_deg,
              vertical_speed_fpm,
              (3440.065 * 2 * ASIN(SQRT(
                POWER(SIN(RADIANS(latitude - ?) / 2), 2)
                + COS(RADIANS(?)) * COS(RADIANS(latitude))
                * POWER(SIN(RADIANS(longitude - ?) / 2), 2)
              ))) AS distance_nm
            FROM ipca_adsb_traffic_samples
            WHERE sample_time_utc BETWEEN ? AND ?
              AND latitude BETWEEN ? AND ?
              AND longitude BETWEEN ? AND ?
            HAVING distance_nm <= ?
            ORDER BY sample_time_utc ASC, distance_nm ASC
            LIMIT 20000
        ");
        $latDelta = $radiusNm / 60.0;
        $lonDelta = $radiusNm / max(1.0, 60.0 * cos(deg2rad($centerLat)));
        $stmt->execute(array(
            $centerLat,
            $centerLat,
            $centerLon,
            $start,
            $end,
            $centerLat - $latDelta,
            $centerLat + $latDelta,
            $centerLon - $lonDelta,
            $centerLon + $lonDelta,
            $radiusNm,
        ));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $traffic = array_map(fn(array $row): array => $this->compactTrafficSample($row, strtotime($start) ?: null), is_array($rows) ? $rows : array());
        return array(
            'traffic' => $traffic,
            'meta' => array(
                'available' => $traffic !== array(),
                'sample_count' => count($traffic),
                'range_nm' => $radiusNm,
                'provider' => tv_adsb_provider(),
                'source' => 'adsb_archive',
                'coverage_status' => $this->coverageStatus($start, $end, $centerLat, $centerLon, $radiusNm),
            ),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function fetchProviderTile(array $tile): array
    {
        $lat = (float)$tile['center_latitude'];
        $lon = (float)$tile['center_longitude'];
        $radiusNm = (float)$tile['radius_nm'];
        $historical = $this->fetchHistoricalProviderTile($tile);
        if ($historical !== null) {
            return $historical;
        }
        $bucketEnd = strtotime((string)($tile['bucket_end_utc'] ?? '')) ?: 0;
        if ($bucketEnd > 0 && $bucketEnd < time() - 120) {
            throw new RuntimeException('ADS-B historical provider is not configured; refusing to fill historical archive bucket with a live snapshot.');
        }
        $result = tv_adsb_fetch_near_point_result($lat, $lon, $radiusNm);
        return array(
            'provider' => tv_adsb_provider(),
            'fetch_mode' => 'near_point_snapshot',
            'requested_bucket_start_utc' => (string)$tile['bucket_start_utc'],
            'requested_bucket_end_utc' => (string)$tile['bucket_end_utc'],
            'center_latitude' => $lat,
            'center_longitude' => $lon,
            'radius_nm' => $radiusNm,
            'path' => $result['path'] ?? null,
            'error' => $result['error'] ?? null,
            'aircraft' => $result['aircraft'] ?? array(),
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fetchHistoricalProviderTile(array $tile): ?array
    {
        $baseUrl = rtrim((string)getenv('CW_ADSB_EXCHANGE_BASE_URL'), '/');
        $apiKey = trim((string)(getenv('CW_ADSB_EXCHANGE_API_KEY') ?: getenv('CW_ADSBEXCHANGE_API_KEY') ?: ''));
        if ($baseUrl === '' || $apiKey === '') {
            return null;
        }
        $query = http_build_query(array(
            'start' => (string)$tile['bucket_start_utc'],
            'end' => (string)$tile['bucket_end_utc'],
            'lat' => (string)$tile['center_latitude'],
            'lon' => (string)$tile['center_longitude'],
            'radius_nm' => (string)$tile['radius_nm'],
        ));
        $url = $baseUrl . '/historical/corridor?' . $query;
        $context = stream_context_create(array('http' => array(
            'method' => 'GET',
            'header' => "Authorization: Bearer {$apiKey}\r\nAccept: application/json\r\n",
            'timeout' => 30,
        )));
        $raw = file_get_contents($url, false, $context);
        if ($raw === false || trim($raw) === '') {
            throw new RuntimeException('ADS-B historical provider returned no data.');
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('ADS-B historical provider returned invalid JSON.');
        }
        $decoded['provider'] = tv_adsb_provider();
        $decoded['fetch_mode'] = 'historical_corridor';
        $decoded['path'] = $url;
        return $decoded;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function normalizeProviderPayload(array $payload, array $tile, int $rawPayloadId): array
    {
        $items = is_array($payload['aircraft'] ?? null) ? $payload['aircraft'] : (is_array($payload['samples'] ?? null) ? $payload['samples'] : array());
        $fallbackSampleTime = $this->mysqlDate($this->utcDate((string)$tile['bucket_start_utc']));
        $samples = array();
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $position = tv_adsb_position($item);
            if ($position === null) {
                continue;
            }
            $hex = tv_adsb_normalize_hex((string)($item['hex'] ?? ''));
            $lat = (float)$position['lat'];
            $lon = (float)$position['lon'];
            $distanceNm = tv_adsb_haversine_nm((float)$tile['center_latitude'], (float)$tile['center_longitude'], $lat, $lon);
            if ($distanceNm > (float)$tile['radius_nm']) {
                continue;
            }
            $alt = $item['alt_baro'] ?? $item['alt_geom'] ?? null;
            $altitudeFt = is_numeric($alt) ? (float)$alt : null;
            $callsign = substr(trim((string)($item['flight'] ?? $item['r'] ?? '')), 0, 32);
            $time = $item['time'] ?? $item['timestamp'] ?? $item['seen_utc'] ?? $item['sample_time_utc'] ?? null;
            $sampleTime = $time !== null ? $this->providerSampleTime($time, $fallbackSampleTime) : $fallbackSampleTime;
            $sampleHash = hash('sha256', implode('|', array(
                tv_adsb_provider(),
                $hex,
                $sampleTime,
                round($lat, 5),
                round($lon, 5),
                $altitudeFt !== null ? round($altitudeFt, 0) : '',
            )));
            $samples[] = array(
                'provider' => tv_adsb_provider(),
                'raw_payload_id' => $rawPayloadId,
                'sample_time_utc' => $sampleTime,
                'aircraft_hex' => $hex,
                'callsign' => $callsign,
                'latitude' => $lat,
                'longitude' => $lon,
                'altitude_ft' => $altitudeFt,
                'groundspeed_kt' => is_numeric($item['gs'] ?? null) ? (float)$item['gs'] : null,
                'track_deg' => is_numeric($item['track'] ?? null) ? (float)$item['track'] : null,
                'vertical_speed_fpm' => is_numeric($item['baro_rate'] ?? null) ? (float)$item['baro_rate'] : null,
                'source_distance_nm' => $distanceNm,
                'raw_json' => AuditEventService::jsonEncode($item),
                'sample_hash' => $sampleHash,
            );
        }
        return $samples;
    }

    private function providerSampleTime(mixed $time, string $fallback): string
    {
        if (is_numeric($time)) {
            return gmdate('Y-m-d H:i:s', (int)$time);
        }
        $ts = strtotime((string)$time);
        return $ts !== false ? gmdate('Y-m-d H:i:s', $ts) : $fallback;
    }

    /**
     * @param list<array<string,mixed>> $samples
     */
    private function storeTrafficSamples(array $samples): int
    {
        if ($samples === array()) {
            return 0;
        }
        $stmt = $this->pdo->prepare("
            INSERT IGNORE INTO ipca_adsb_traffic_samples
              (provider, raw_payload_id, sample_time_utc, aircraft_hex, callsign, latitude, longitude, altitude_ft,
               groundspeed_kt, track_deg, vertical_speed_fpm, source_distance_nm, raw_json, sample_hash)
            VALUES
              (:provider, :raw_payload_id, :sample_time_utc, :aircraft_hex, :callsign, :latitude, :longitude, :altitude_ft,
               :groundspeed_kt, :track_deg, :vertical_speed_fpm, :source_distance_nm, :raw_json, :sample_hash)
        ");
        $inserted = 0;
        foreach ($samples as $sample) {
            $stmt->execute(array(
                ':provider' => $sample['provider'],
                ':raw_payload_id' => $sample['raw_payload_id'],
                ':sample_time_utc' => $sample['sample_time_utc'],
                ':aircraft_hex' => $sample['aircraft_hex'],
                ':callsign' => $sample['callsign'],
                ':latitude' => $sample['latitude'],
                ':longitude' => $sample['longitude'],
                ':altitude_ft' => $sample['altitude_ft'],
                ':groundspeed_kt' => $sample['groundspeed_kt'],
                ':track_deg' => $sample['track_deg'],
                ':vertical_speed_fpm' => $sample['vertical_speed_fpm'],
                ':source_distance_nm' => $sample['source_distance_nm'],
                ':raw_json' => $sample['raw_json'],
                ':sample_hash' => $sample['sample_hash'],
            ));
            $inserted += $stmt->rowCount() > 0 ? 1 : 0;
        }
        return $inserted;
    }

    private function createTilesForJob(int $jobId, string $scope, float $lat, float $lon, float $radiusNm, DateTimeImmutable $start, DateTimeImmutable $end, int $bucketSeconds): int
    {
        $stmt = $this->pdo->prepare("
            INSERT IGNORE INTO ipca_adsb_coverage_tiles
              (tile_uuid, job_id, scope, provider, status, center_latitude, center_longitude, radius_nm, bucket_start_utc, bucket_end_utc)
            VALUES (?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?)
        ");
        $created = 0;
        for ($cursor = $start; $cursor < $end; $cursor = $cursor->modify('+' . $bucketSeconds . ' seconds')) {
            $bucketEnd = min($end->getTimestamp(), $cursor->getTimestamp() + $bucketSeconds);
            $stmt->execute(array(
                AuditEventService::uuid(),
                $jobId,
                $scope,
                tv_adsb_provider(),
                $lat,
                $lon,
                $radiusNm,
                $this->mysqlDate($cursor),
                gmdate('Y-m-d H:i:s', $bucketEnd),
            ));
            $created += $stmt->rowCount() > 0 ? 1 : 0;
        }
        return $created;
    }

    private function storeRawPayload(array $payload, array $tile): int
    {
        $json = AuditEventService::jsonEncode($payload);
        $sha256 = hash('sha256', $json);
        $path = $this->storeJsonPayload($sha256, $json);
        $stmt = $this->pdo->prepare("
            INSERT IGNORE INTO ipca_adsb_raw_payloads
              (payload_uuid, provider, request_url, request_json, http_status, sha256, storage_path, byte_size)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute(array(
            AuditEventService::uuid(),
            tv_adsb_provider(),
            (string)($payload['path'] ?? ''),
            AuditEventService::jsonEncode(array('tile_id' => (int)$tile['id'], 'scope' => (string)$tile['scope'])),
            null,
            $sha256,
            $path,
            strlen($json),
        ));
        $idStmt = $this->pdo->prepare('SELECT id FROM ipca_adsb_raw_payloads WHERE sha256 = ? LIMIT 1');
        $idStmt->execute(array($sha256));
        return (int)$idStmt->fetchColumn();
    }

    private function storeJsonPayload(string $sha256, string $json): string
    {
        $dir = CockpitRecorderService::projectRoot() . '/storage/adsb_archive/' . gmdate('Y/m/d');
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create ADS-B archive storage directory.');
        }
        $path = $dir . '/' . $sha256 . '.json';
        if (!is_file($path) && file_put_contents($path, $json) === false) {
            throw new RuntimeException('Could not store ADS-B archive payload.');
        }
        return 'storage/adsb_archive/' . gmdate('Y/m/d') . '/' . $sha256 . '.json';
    }

    private function refreshJobStats(int $jobId): void
    {
        if ($jobId <= 0) {
            return;
        }
        $stmt = $this->pdo->prepare("
            SELECT
              COUNT(*) AS total,
              SUM(status = 'ready') AS ready,
              COALESCE(SUM(sample_count), 0) AS samples,
              SUM(status IN ('pending', 'fetching', 'failed')) AS remaining
            FROM ipca_adsb_coverage_tiles
            WHERE job_id = ?
        ");
        $stmt->execute(array($jobId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: array();
        $remaining = (int)($row['remaining'] ?? 0);
        $this->pdo->prepare("
            UPDATE ipca_adsb_coverage_jobs
            SET tile_count = ?,
                completed_tile_count = ?,
                sample_count = ?,
                status = ?,
                updated_at = CURRENT_TIMESTAMP(3)
            WHERE id = ?
        ")->execute(array((int)($row['total'] ?? 0), (int)($row['ready'] ?? 0), (int)($row['samples'] ?? 0), $remaining === 0 ? 'ready' : 'processing', $jobId));
    }

    private function coverageStatus(string $start, string $end, float $lat, float $lon, float $radiusNm): string
    {
        $stmt = $this->pdo->prepare("
            SELECT
              COUNT(*) AS total,
              SUM(status = 'ready') AS ready
            FROM ipca_adsb_coverage_tiles
            WHERE bucket_start_utc < ?
              AND bucket_end_utc > ?
              AND ABS(center_latitude - ?) < 0.001
              AND ABS(center_longitude - ?) < 0.001
              AND radius_nm >= ?
        ");
        $stmt->execute(array($end, $start, $lat, $lon, $radiusNm));
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: array();
        $total = (int)($row['total'] ?? 0);
        $ready = (int)($row['ready'] ?? 0);
        if ($total === 0) {
            return 'missing';
        }
        if ($ready === $total) {
            return 'complete';
        }
        return $ready > 0 ? 'partial' : 'queued';
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function compactTrafficSample(array $row, ?int $startEpoch): array
    {
        $sampleEpoch = strtotime((string)($row['sample_time_utc'] ?? '')) ?: null;
        return array(
            't' => $startEpoch !== null && $sampleEpoch !== null ? max(0.0, (float)($sampleEpoch - $startEpoch)) : null,
            'hex' => strtolower(trim((string)($row['aircraft_hex'] ?? ''))),
            'cs' => trim((string)($row['callsign'] ?? '')),
            'lat' => isset($row['latitude']) ? (float)$row['latitude'] : null,
            'lon' => isset($row['longitude']) ? (float)$row['longitude'] : null,
            'trk' => isset($row['track_deg']) && is_numeric($row['track_deg']) ? (float)$row['track_deg'] : null,
            'alt' => isset($row['altitude_ft']) && is_numeric($row['altitude_ft']) ? (float)$row['altitude_ft'] : null,
            'dist' => isset($row['distance_nm']) && is_numeric($row['distance_nm']) ? (float)$row['distance_nm'] : null,
            'gs' => isset($row['groundspeed_kt']) && is_numeric($row['groundspeed_kt']) ? (float)$row['groundspeed_kt'] : null,
        );
    }

    private function utcDate(string $value): DateTimeImmutable
    {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
    }

    private function mysqlDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
