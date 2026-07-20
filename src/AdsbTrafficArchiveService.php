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
    private const HOME_LIVE_RESOLUTION_SECONDS = 10;
    private const STANDARD_LIVE_RESOLUTION_SECONDS = 60;
    private const LIVE_SNAPSHOT_GRACE_SECONDS = 900;
    private const SOURCE_MODE_LIVE = 'LIVE';

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
        $minutes = max(5, min(1440, $lookbackMinutes));
        if (!$this->historicalProviderConfigured()) {
            // With the existing TV/radar ADS-B variables we can archive live KTRM snapshots going forward.
            // Older backfill needs a verified historical snapshot provider to avoid fabricating history.
            $minutes = max(1, (int)ceil($bucketSeconds / 60));
        }
        $start = $end->modify('-' . $minutes . ' minutes');
        return $this->scheduleKtrmCoverage($start->format(DateTimeInterface::ATOM), $end->format(DateTimeInterface::ATOM), $bucketSeconds);
    }

    /**
     * @return array<string,mixed>
     */
    public function scheduleRecentLivePointCoverage(float $lat, float $lon, float $radiusNm, int $lookbackMinutes = 1, int $bucketSeconds = 60, string $scope = 'live_point'): array
    {
        $this->ensureTables();
        $radiusNm = max(0.5, min(25.0, $radiusNm));
        $bucketSeconds = max(60, min(900, $bucketSeconds));
        $minutes = max(1, min(60, $lookbackMinutes));
        $end = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $start = $end->modify('-' . $minutes . ' minutes');
        $scope = substr(preg_replace('/[^a-zA-Z0-9_-]/', '_', $scope) ?: 'live_point', 0, 32);
        $jobUuid = AuditEventService::uuid();
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_adsb_coverage_jobs
              (job_uuid, scope, status, center_latitude, center_longitude, radius_nm, query_start_utc, query_end_utc, bucket_seconds, source_ref_type)
            VALUES (?, ?, 'pending', ?, ?, ?, ?, ?, ?, 'live_adsb_recorder')
        ");
        $stmt->execute(array(
            $jobUuid,
            $scope,
            $lat,
            $lon,
            $radiusNm,
            $this->mysqlDate($start),
            $this->mysqlDate($end),
            $bucketSeconds,
        ));
        $jobId = (int)$this->pdo->lastInsertId();
        $created = $this->createTilesForJob($jobId, $scope, $lat, $lon, $radiusNm, $start, $end, $bucketSeconds);
        $this->pdo->prepare('UPDATE ipca_adsb_coverage_jobs SET tile_count = ?, updated_at = CURRENT_TIMESTAMP(3) WHERE id = ?')
            ->execute(array($created, $jobId));
        return array(
            'ok' => true,
            'job_id' => $jobId,
            'job_uuid' => $jobUuid,
            'scope' => $scope,
            'center_latitude' => $lat,
            'center_longitude' => $lon,
            'radius_nm' => $radiusNm,
            'bucket_seconds' => $bucketSeconds,
            'tiles_created' => $created,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function scheduleLivePointSnapshotCoverage(float $lat, float $lon, float $radiusNm, int $bucketSeconds = self::STANDARD_LIVE_RESOLUTION_SECONDS, string $scope = 'live_point'): array
    {
        $this->ensureTables();
        $radiusNm = max(0.5, min(25.0, $radiusNm));
        $bucketSeconds = max(10, min(900, $bucketSeconds));
        $end = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $start = $end->modify('-' . $bucketSeconds . ' seconds');
        $scope = substr(preg_replace('/[^a-zA-Z0-9_-]/', '_', $scope) ?: 'live_point', 0, 32);
        $jobUuid = AuditEventService::uuid();
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_adsb_coverage_jobs
              (job_uuid, scope, status, center_latitude, center_longitude, radius_nm, query_start_utc, query_end_utc, bucket_seconds, source_ref_type)
            VALUES (?, ?, 'pending', ?, ?, ?, ?, ?, ?, 'live_adsb_recorder')
        ");
        $stmt->execute(array(
            $jobUuid,
            $scope,
            $lat,
            $lon,
            $radiusNm,
            $this->mysqlDate($start),
            $this->mysqlDate($end),
            $bucketSeconds,
        ));
        $jobId = (int)$this->pdo->lastInsertId();
        $created = $this->createTilesForJob($jobId, $scope, $lat, $lon, $radiusNm, $start, $end, $bucketSeconds);
        $this->pdo->prepare('UPDATE ipca_adsb_coverage_jobs SET tile_count = ?, updated_at = CURRENT_TIMESTAMP(3) WHERE id = ?')
            ->execute(array($created, $jobId));
        return array(
            'ok' => true,
            'job_id' => $jobId,
            'job_uuid' => $jobUuid,
            'scope' => $scope,
            'center_latitude' => $lat,
            'center_longitude' => $lon,
            'radius_nm' => $radiusNm,
            'bucket_seconds' => $bucketSeconds,
            'tiles_created' => $created,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function scheduleLiveTargetSnapshotCoverage(bool $highResolutionOnly = false): array
    {
        $targets = array_values(array_filter($this->archiveTargets(), function (array $target) use ($highResolutionOnly): bool {
            $enabled = ($target['source'] ?? '') === 'default' || !empty($target['live_monitoring_enabled']);
            return $enabled && (!$highResolutionOnly || $this->targetIsHighResolution($target));
        }));
        $results = array();
        $tiles = 0;
        foreach ($targets as $target) {
            $scope = (string)($target['id'] ?? 'live_point');
            $resolutionSeconds = $this->targetLiveResolutionSeconds($target);
            $result = $this->scheduleLivePointSnapshotCoverage(
                (float)$target['lat'],
                (float)$target['lon'],
                (float)$target['radius_nm'],
                $resolutionSeconds,
                $scope
            );
            $tiles += (int)($result['tiles_created'] ?? 0);
            $results[] = $result + array(
                'target_label' => (string)($target['label'] ?? $scope),
                'priority' => (string)($target['priority'] ?? 'standard'),
                'high_resolution' => $this->targetIsHighResolution($target),
            );
        }
        return array('ok' => true, 'target_count' => count($targets), 'tiles_created' => $tiles, 'results' => $results);
    }

    /**
     * @return array<string,mixed>
     */
    public function scheduleRecentLiveTargetCoverage(int $lookbackMinutes = 1, int $bucketSeconds = 60): array
    {
        $targets = array_values(array_filter($this->archiveTargets(), static fn(array $target): bool => ($target['source'] ?? '') === 'default' || !empty($target['live_monitoring_enabled'])));
        $results = array();
        $tiles = 0;
        foreach ($targets as $target) {
            $scope = (string)($target['id'] ?? 'live_point');
            $result = $this->scheduleRecentLivePointCoverage(
                (float)$target['lat'],
                (float)$target['lon'],
                (float)$target['radius_nm'],
                $lookbackMinutes,
                $bucketSeconds,
                $scope
            );
            $tiles += (int)($result['tiles_created'] ?? 0);
            $results[] = $result + array('target_label' => (string)($target['label'] ?? $scope));
        }
        return array('ok' => true, 'target_count' => count($targets), 'tiles_created' => $tiles, 'results' => $results);
    }

    /**
     * @param list<array{lat:float,lon:float}> $points
     * @return array<string,mixed>
     */
    public function schedulePathCoverage(string $startUtc, string $endUtc, array $points, string $sourceRefType, int $sourceRefId, float $radiusNm = 25.0, int $bucketSeconds = self::DEFAULT_BUCKET_SECONDS): array
    {
        $this->ensureTables();
        $points = $this->representativePoints($points, 8);
        if ($points === array()) {
            return array('ok' => false, 'tiles_created' => 0, 'message' => 'No valid path points for ADS-B corridor coverage.');
        }
        $start = $this->utcDate($startUtc);
        $end = $this->utcDate($endUtc);
        if ($end <= $start) {
            throw new RuntimeException('ADS-B coverage end time must be after start time.');
        }
        $radiusNm = max(1.0, min(25.0, $radiusNm));
        $bucketSeconds = max(60, min(900, $bucketSeconds));
        $jobUuid = AuditEventService::uuid();
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_adsb_coverage_jobs
              (job_uuid, scope, status, center_latitude, center_longitude, radius_nm, query_start_utc, query_end_utc, bucket_seconds, source_ref_type, source_ref_id)
            VALUES (?, 'flight_corridor', 'pending', ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $first = $points[0];
        $stmt->execute(array(
            $jobUuid,
            $first['lat'],
            $first['lon'],
            $radiusNm,
            $this->mysqlDate($start),
            $this->mysqlDate($end),
            $bucketSeconds,
            substr($sourceRefType, 0, 64),
            $sourceRefId > 0 ? $sourceRefId : null,
        ));
        $jobId = (int)$this->pdo->lastInsertId();
        $created = 0;
        foreach ($points as $point) {
            $created += $this->createTilesForJob($jobId, 'flight_corridor', (float)$point['lat'], (float)$point['lon'], $radiusNm, $start, $end, $bucketSeconds);
        }
        $this->pdo->prepare('UPDATE ipca_adsb_coverage_jobs SET tile_count = ?, updated_at = CURRENT_TIMESTAMP(3) WHERE id = ?')
            ->execute(array($created, $jobId));
        return array('ok' => true, 'job_id' => $jobId, 'job_uuid' => $jobUuid, 'tiles_created' => $created, 'path_points' => count($points));
    }

    /**
     * @return array<string,mixed>
     */
    public function fetchNextPendingTile(): array
    {
        $this->ensureTables();
        if (!$this->historicalProviderConfigured()) {
            $this->markHistoricalTilesProviderNotConfigured();
        }
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
            $this->markTileReady((int)$row['id'], $rawPayloadId, count($samples), $inserted);
            $this->refreshJobStats((int)($row['job_id'] ?? 0));
            return array('ok' => true, 'status' => 'ready', 'tile_id' => (int)$row['id'], 'samples' => count($samples), 'inserted' => $inserted);
        } catch (DomainException $e) {
            $this->pdo->prepare("
                UPDATE ipca_adsb_coverage_tiles
                SET status = 'provider_not_configured', last_error = ?, updated_at = CURRENT_TIMESTAMP(3)
                WHERE id = ?
            ")->execute(array($e->getMessage(), (int)$row['id']));
            $this->refreshJobStats((int)($row['job_id'] ?? 0));
            return array('ok' => false, 'status' => 'provider_not_configured', 'tile_id' => (int)$row['id'], 'samples' => 0, 'message' => $e->getMessage());
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
        $coverage = array('pending' => 0, 'fetching' => 0, 'ready' => 0, 'failed' => 0, 'provider_not_configured' => 0, 'samples' => 0);
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
            'historical_provider' => $this->historicalProviderConfigured()
                ? array('configured' => true, 'base_url' => rtrim((string)getenv('CW_ADSB_EXCHANGE_BASE_URL'), '/'))
                : array(
                    'configured' => false,
                    'live_configured' => $this->liveProviderConfigured(),
                    'live_env' => array('CW_ADSBEXCHANGE_API_KEY', 'CW_RAPIDAPI_KEY', 'RAPIDAPI_KEY', 'ADSBEXCHANGE_API_KEY'),
                    'historical_env' => array('CW_ADSB_EXCHANGE_BASE_URL', 'CW_ADSB_EXCHANGE_API_KEY or CW_ADSBEXCHANGE_API_KEY'),
                    'message' => 'Live ADS-B archiving can use the existing TV/radar ADSBExchange variables. Older historical backfill still needs a historical area/corridor provider.',
                ),
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
     * @return list<array<string,mixed>>
     */
    public function recentTrafficSamples(int $limit = 200): array
    {
        $this->ensureTables();
        $limit = max(1, min(1000, $limit));
        $stmt = $this->pdo->prepare("
            SELECT
              sample_time_utc,
              aircraft_hex,
              callsign,
              latitude,
              longitude,
              altitude_ft,
              groundspeed_kt,
              track_deg,
              vertical_speed_fpm,
              source_distance_nm,
              (3440.065 * 2 * ASIN(SQRT(
                POWER(SIN(RADIANS(latitude - ?) / 2), 2)
                + COS(RADIANS(?)) * COS(RADIANS(latitude))
                * POWER(SIN(RADIANS(longitude - ?) / 2), 2)
              ))) AS distance_nm
            FROM ipca_adsb_traffic_samples
            WHERE sample_time_utc >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)
              AND latitude BETWEEN ? AND ?
              AND longitude BETWEEN ? AND ?
            HAVING distance_nm <= ?
            ORDER BY sample_time_utc DESC, distance_nm ASC
            LIMIT {$limit}
        ");
        $radiusNm = self::KTRM_RADIUS_NM;
        $latDelta = $radiusNm / 60.0;
        $lonDelta = $radiusNm / max(1.0, 60.0 * cos(deg2rad(self::KTRM_LAT)));
        $stmt->execute(array(
            self::KTRM_LAT,
            self::KTRM_LAT,
            self::KTRM_LON,
            self::KTRM_LAT - $latDelta,
            self::KTRM_LAT + $latDelta,
            self::KTRM_LON - $lonDelta,
            self::KTRM_LON + $lonDelta,
            $radiusNm,
        ));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    }

    /**
     * @return array<string,mixed>
     */
    public function dashboardData(string $targetId = 'ktrm_live', int $hours = 6): array
    {
        $this->ensureTables();
        $hours = max(1, min(168, $hours));
        $targets = $this->archiveTargets();
        $target = $targets[0] ?? array('id' => 'ktrm_live', 'label' => 'KTRM Live', 'lat' => self::KTRM_LAT, 'lon' => self::KTRM_LON, 'radius_nm' => 25.0);
        foreach ($targets as $candidate) {
            if ((string)$candidate['id'] === $targetId) {
                $target = $candidate;
                break;
            }
        }
        return array(
            'ok' => true,
            'generated_at_utc' => gmdate('Y-m-d H:i:s'),
            'status' => $this->status(),
            'growth' => $this->archiveGrowthStats($hours),
            'targets' => $targets,
            'selected_target' => $target,
            'target_timeline' => $this->targetTimeline($target, $hours),
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function archiveTargets(): array
    {
        $targets = array(array(
            'id' => 'ktrm_live',
            'label' => 'KTRM Live Archive',
            'type' => 'point_radius',
            'lat' => self::KTRM_LAT,
            'lon' => self::KTRM_LON,
            'radius_nm' => 25.0,
            'priority' => 'home',
            'resolution_seconds' => self::HOME_LIVE_RESOLUTION_SECONDS,
            'enabled' => true,
            'source' => 'default',
        ));
        if (!$this->tablePresent('ipca_adsb_geographic_definitions')) {
            return $targets;
        }
        $rows = $this->pdo->query("
            SELECT id, definition_uuid, name, definition_type, configuration_json, enabled, live_monitoring_enabled, replay_query_enabled
            FROM ipca_adsb_geographic_definitions
            WHERE enabled = 1
            ORDER BY name ASC, id ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: array();
        foreach ($rows as $row) {
            $config = json_decode((string)($row['configuration_json'] ?? ''), true);
            $config = is_array($config) ? $config : array();
            $lat = $config['lat'] ?? $config['latitude'] ?? $config['center_latitude'] ?? null;
            $lon = $config['lon'] ?? $config['longitude'] ?? $config['center_longitude'] ?? null;
            $radius = $config['radius_nm'] ?? $config['radius'] ?? null;
            if (!is_numeric($lat) || !is_numeric($lon) || !is_numeric($radius)) {
                continue;
            }
            $resolutionSeconds = $config['resolution_seconds'] ?? $config['live_resolution_seconds'] ?? null;
            $priority = strtolower(trim((string)($config['priority'] ?? 'standard')));
            $targets[] = array(
                'id' => 'geo_' . (int)$row['id'],
                'definition_uuid' => (string)($row['definition_uuid'] ?? ''),
                'label' => (string)($row['name'] ?? ('Target ' . (int)$row['id'])),
                'type' => (string)($row['definition_type'] ?? 'point_radius'),
                'lat' => (float)$lat,
                'lon' => (float)$lon,
                'radius_nm' => max(0.5, min(100.0, (float)$radius)),
                'priority' => $priority === 'home' ? 'home' : 'standard',
                'resolution_seconds' => is_numeric($resolutionSeconds) ? max(10, min(900, (int)$resolutionSeconds)) : null,
                'enabled' => !empty($row['enabled']),
                'live_monitoring_enabled' => !empty($row['live_monitoring_enabled']),
                'replay_query_enabled' => !empty($row['replay_query_enabled']),
                'source' => 'ipca_adsb_geographic_definitions',
            );
        }
        return $targets;
    }

    /**
     * @param array<string,mixed> $target
     */
    private function targetIsHighResolution(array $target): bool
    {
        return (string)($target['priority'] ?? '') === 'home'
            || (isset($target['resolution_seconds']) && is_numeric($target['resolution_seconds']) && (int)$target['resolution_seconds'] <= 15);
    }

    /**
     * @param array<string,mixed> $target
     */
    private function targetLiveResolutionSeconds(array $target, ?int $fallback = null): int
    {
        $configured = $target['resolution_seconds'] ?? null;
        if (is_numeric($configured)) {
            return max(10, min(900, (int)$configured));
        }
        if ((string)($target['priority'] ?? '') === 'home') {
            return self::HOME_LIVE_RESOLUTION_SECONDS;
        }
        return max(10, min(900, $fallback ?? self::STANDARD_LIVE_RESOLUTION_SECONDS));
    }

    /**
     * @return array<string,mixed>
     */
    public function createLivePointTarget(string $name, float $lat, float $lon, float $radiusNm = 25.0): array
    {
        $this->ensureTables();
        if (!$this->tablePresent('ipca_adsb_geographic_definitions')) {
            throw new RuntimeException('Apply the ADS-B archive Phase 1 migration before adding target definitions.');
        }
        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException('Target name is required.');
        }
        if ($lat < -90.0 || $lat > 90.0 || $lon < -180.0 || $lon > 180.0) {
            throw new RuntimeException('Target latitude/longitude is invalid.');
        }
        $radiusNm = max(0.5, min(25.0, $radiusNm));
        $configuration = AuditEventService::jsonEncode(array(
            'lat' => $lat,
            'lon' => $lon,
            'radius_nm' => $radiusNm,
            'resolution_seconds' => self::STANDARD_LIVE_RESOLUTION_SECONDS,
        ));
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_adsb_geographic_definitions
              (definition_uuid, name, definition_type, configuration_json, enabled, live_monitoring_enabled, replay_query_enabled)
            VALUES
              (?, ?, 'point_radius', ?, 1, 1, 1)
        ");
        $uuid = AuditEventService::uuid();
        $stmt->execute(array($uuid, substr($name, 0, 160), $configuration));
        return array(
            'ok' => true,
            'id' => (int)$this->pdo->lastInsertId(),
            'definition_uuid' => $uuid,
            'name' => $name,
            'lat' => $lat,
            'lon' => $lon,
            'radius_nm' => $radiusNm,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function archiveGrowthStats(int $hours): array
    {
        $sampleSchema = $this->columnsForTable('ipca_adsb_traffic_samples');
        $liveExpr = !empty($sampleSchema['source_mode']) ? "SUM(source_mode = 'LIVE')" : '0';
        $historicalExpr = !empty($sampleSchema['source_mode']) ? "SUM(source_mode = 'HISTORICAL')" : '0';
        $summary = $this->pdo->query("
            SELECT
              COUNT(*) AS total_samples,
              COUNT(DISTINCT aircraft_hex) AS unique_aircraft,
              MIN(sample_time_utc) AS first_sample_utc,
              MAX(sample_time_utc) AS newest_sample_utc
            FROM ipca_adsb_traffic_samples
        ")->fetch(PDO::FETCH_ASSOC) ?: array();
        $recentStmt = $this->pdo->prepare("
            SELECT
              COUNT(*) AS recent_samples,
              COUNT(DISTINCT aircraft_hex) AS recent_unique_aircraft,
              {$liveExpr} AS live_samples,
              {$historicalExpr} AS historical_samples
            FROM ipca_adsb_traffic_samples
            WHERE sample_time_utc >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? HOUR)
        ");
        $recentStmt->execute(array($hours));
        $recent = $recentStmt->fetch(PDO::FETCH_ASSOC) ?: array();
        $bucketStmt = $this->pdo->prepare("
            SELECT DATE_FORMAT(sample_time_utc, '%Y-%m-%d %H:%i:00') AS bucket_utc,
                   COUNT(*) AS samples,
                   COUNT(DISTINCT aircraft_hex) AS aircraft
            FROM ipca_adsb_traffic_samples
            WHERE sample_time_utc >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? HOUR)
            GROUP BY DATE_FORMAT(sample_time_utc, '%Y-%m-%d %H:%i:00')
            ORDER BY bucket_utc ASC
        ");
        $bucketStmt->execute(array($hours));
        $rawPayloads = $this->tablePresent('ipca_adsb_raw_payloads')
            ? (int)$this->pdo->query('SELECT COUNT(*) FROM ipca_adsb_raw_payloads')->fetchColumn()
            : 0;
        return array(
            'hours' => $hours,
            'total_samples' => (int)($summary['total_samples'] ?? 0),
            'unique_aircraft' => (int)($summary['unique_aircraft'] ?? 0),
            'first_sample_utc' => $summary['first_sample_utc'] ?? null,
            'newest_sample_utc' => $summary['newest_sample_utc'] ?? null,
            'recent_samples' => (int)($recent['recent_samples'] ?? 0),
            'recent_unique_aircraft' => (int)($recent['recent_unique_aircraft'] ?? 0),
            'live_samples' => (int)($recent['live_samples'] ?? 0),
            'historical_samples' => (int)($recent['historical_samples'] ?? 0),
            'raw_payloads' => $rawPayloads,
            'buckets' => $bucketStmt->fetchAll(PDO::FETCH_ASSOC) ?: array(),
        );
    }

    /**
     * @param array<string,mixed> $target
     * @return array<string,mixed>
     */
    private function targetTimeline(array $target, int $hours): array
    {
        $sampleSchema = $this->columnsForTable('ipca_adsb_traffic_samples');
        $sourceModeSelect = !empty($sampleSchema['source_mode']) ? 'source_mode' : "'UNKNOWN' AS source_mode";
        $providerSelect = !empty($sampleSchema['provider']) ? 'provider' : "'' AS provider";
        $categorySelect = !empty($sampleSchema['category']) ? 'category' : "NULL AS category";
        $lat = (float)($target['lat'] ?? self::KTRM_LAT);
        $lon = (float)($target['lon'] ?? self::KTRM_LON);
        $radiusNm = max(0.5, min(100.0, (float)($target['radius_nm'] ?? 25.0)));
        $latDelta = $radiusNm / 60.0;
        $lonDelta = $radiusNm / max(1.0, 60.0 * cos(deg2rad($lat)));
        $stmt = $this->pdo->prepare("
            SELECT sample_time_utc,
                   aircraft_hex,
                   callsign,
                   latitude,
                   longitude,
                   altitude_ft,
                   groundspeed_kt,
                   track_deg,
                   {$categorySelect},
                   {$sourceModeSelect},
                   {$providerSelect},
                   (3440.065 * 2 * ASIN(SQRT(
                     POWER(SIN(RADIANS(latitude - ?) / 2), 2)
                     + COS(RADIANS(?)) * COS(RADIANS(latitude))
                     * POWER(SIN(RADIANS(longitude - ?) / 2), 2)
                   ))) AS distance_nm
            FROM ipca_adsb_traffic_samples
            WHERE sample_time_utc >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? HOUR)
              AND latitude BETWEEN ? AND ?
              AND longitude BETWEEN ? AND ?
            HAVING distance_nm <= ?
            ORDER BY sample_time_utc ASC, aircraft_hex ASC
            LIMIT 25000
        ");
        $stmt->execute(array($lat, $lat, $lon, $hours, $lat - $latDelta, $lat + $latDelta, $lon - $lonDelta, $lon + $lonDelta, $radiusNm));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
        $aircraft = array();
        $startEpoch = null;
        $endEpoch = null;
        foreach ($rows as $row) {
            $hex = strtolower(trim((string)($row['aircraft_hex'] ?? '')));
            if ($hex === '') {
                continue;
            }
            $epoch = strtotime((string)($row['sample_time_utc'] ?? '')) ?: null;
            if ($epoch === null) {
                continue;
            }
            $startEpoch = $startEpoch === null ? $epoch : min($startEpoch, $epoch);
            $endEpoch = $endEpoch === null ? $epoch : max($endEpoch, $epoch);
            if (!isset($aircraft[$hex])) {
                $aircraft[$hex] = array(
                    'hex' => $hex,
                    'callsign' => trim((string)($row['callsign'] ?? '')),
                    'provider' => (string)($row['provider'] ?? ''),
                    'source_modes' => array(),
                    'samples' => array(),
                );
            }
            $mode = trim((string)($row['source_mode'] ?? 'UNKNOWN'));
            if ($mode !== '' && !in_array($mode, $aircraft[$hex]['source_modes'], true)) {
                $aircraft[$hex]['source_modes'][] = $mode;
            }
            if ($aircraft[$hex]['callsign'] === '' && trim((string)($row['callsign'] ?? '')) !== '') {
                $aircraft[$hex]['callsign'] = trim((string)$row['callsign']);
            }
            $aircraft[$hex]['samples'][] = array(
                'utc' => (string)$row['sample_time_utc'],
                'epoch' => $epoch,
                'lat' => (float)$row['latitude'],
                'lon' => (float)$row['longitude'],
                'altitude_ft' => is_numeric($row['altitude_ft'] ?? null) ? (float)$row['altitude_ft'] : null,
                'groundspeed_kt' => is_numeric($row['groundspeed_kt'] ?? null) ? (float)$row['groundspeed_kt'] : null,
                'track_deg' => is_numeric($row['track_deg'] ?? null) ? (float)$row['track_deg'] : null,
                'category' => isset($row['category']) ? (string)$row['category'] : null,
                'distance_nm' => is_numeric($row['distance_nm'] ?? null) ? (float)$row['distance_nm'] : null,
            );
        }
        return array(
            'target' => $target,
            'hours' => $hours,
            'start_epoch' => $startEpoch,
            'end_epoch' => $endEpoch,
            'sample_count' => count($rows),
            'aircraft_count' => count($aircraft),
            'aircraft' => array_values($aircraft),
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
     * @param list<array{lat:float,lon:float}> $points
     * @return array{traffic:list<array<string,mixed>>,meta:array<string,mixed>}
     */
    public function trafficForReplayPath(string $startUtc, string $endUtc, array $points, float $radiusNm = 25.0): array
    {
        $this->ensureTables();
        $points = $this->representativePoints($points, 20);
        if ($points === array()) {
            return array('traffic' => array(), 'meta' => array('available' => false, 'sample_count' => 0, 'range_nm' => $radiusNm, 'provider' => tv_adsb_provider(), 'source' => 'adsb_archive_flight_corridor', 'coverage_status' => 'missing'));
        }
        $start = $this->mysqlDate($this->utcDate($startUtc));
        $end = $this->mysqlDate($this->utcDate($endUtc));
        $radiusNm = max(1.0, min(25.0, $radiusNm));
        $lats = array_map(static fn(array $p): float => (float)$p['lat'], $points);
        $lons = array_map(static fn(array $p): float => (float)$p['lon'], $points);
        $centerLat = array_sum($lats) / max(1, count($lats));
        $latDelta = $radiusNm / 60.0;
        $lonDelta = $radiusNm / max(1.0, 60.0 * cos(deg2rad($centerLat)));
        $stmt = $this->pdo->prepare("
            SELECT id, sample_time_utc, aircraft_hex, callsign, latitude, longitude, altitude_ft, groundspeed_kt, track_deg, vertical_speed_fpm
            FROM ipca_adsb_traffic_samples
            WHERE sample_time_utc BETWEEN ? AND ?
              AND latitude BETWEEN ? AND ?
              AND longitude BETWEEN ? AND ?
            ORDER BY sample_time_utc ASC
            LIMIT 30000
        ");
        $stmt->execute(array($start, $end, min($lats) - $latDelta, max($lats) + $latDelta, min($lons) - $lonDelta, max($lons) + $lonDelta));
        $traffic = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row) {
            $distance = $this->nearestPathDistanceNm((float)$row['latitude'], (float)$row['longitude'], $points);
            if ($distance <= $radiusNm) {
                $row['distance_nm'] = $distance;
                $traffic[] = $this->compactTrafficSample($row, strtotime($start) ?: null);
            }
        }
        return array(
            'traffic' => $traffic,
            'meta' => array(
                'available' => $traffic !== array(),
                'sample_count' => count($traffic),
                'range_nm' => $radiusNm,
                'provider' => tv_adsb_provider(),
                'source' => 'adsb_archive_flight_corridor',
                'coverage_status' => $this->pathCoverageStatus($start, $end, $points, $radiusNm),
                'path_point_count' => count($points),
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
        $bucketStart = strtotime((string)($tile['bucket_start_utc'] ?? '')) ?: 0;
        if ($bucketStart > 0 && $bucketStart < time() - self::LIVE_SNAPSHOT_GRACE_SECONDS) {
            throw new DomainException('ADS-B historical provider is not configured; refusing to fill historical archive bucket with a live snapshot.');
        }
        $result = tv_adsb_fetch_near_point_result($lat, $lon, $radiusNm);
        return array(
            'provider' => tv_adsb_provider(),
            'fetch_mode' => 'near_point_snapshot',
            'fetched_at_utc' => gmdate('Y-m-d H:i:s'),
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
        return null;
    }

    private function historicalProviderConfigured(): bool
    {
        return rtrim((string)getenv('CW_ADSB_EXCHANGE_BASE_URL'), '/') !== ''
            && trim((string)(getenv('CW_ADSB_EXCHANGE_API_KEY') ?: getenv('CW_ADSBEXCHANGE_API_KEY') ?: '')) !== '';
    }

    private function liveProviderConfigured(): bool
    {
        return trim((string)(
            getenv('CW_ADSBEXCHANGE_API_KEY')
            ?: getenv('CW_RAPIDAPI_KEY')
            ?: getenv('RAPIDAPI_KEY')
            ?: getenv('ADSBEXCHANGE_API_KEY')
            ?: ''
        )) !== '';
    }

    private function markHistoricalTilesProviderNotConfigured(): void
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::LIVE_SNAPSHOT_GRACE_SECONDS);
        $this->pdo->prepare("
            UPDATE ipca_adsb_coverage_tiles
            SET status = 'provider_not_configured',
                last_error = 'Historical ADS-B provider is not configured; this tile is too old for live ADS-B snapshot archiving.',
                updated_at = CURRENT_TIMESTAMP(3)
            WHERE status IN ('pending', 'failed')
              AND bucket_start_utc < ?
        ")->execute(array($cutoff));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function normalizeProviderPayload(array $payload, array $tile, int $rawPayloadId): array
    {
        $items = is_array($payload['aircraft'] ?? null) ? $payload['aircraft'] : (is_array($payload['samples'] ?? null) ? $payload['samples'] : array());
        $fallbackSampleTime = $this->mysqlDate($this->utcDate((string)$tile['bucket_start_utc']));
        $payloadFetchedAt = (string)($payload['fetched_at_utc'] ?? $fallbackSampleTime);
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
            $baroAltitudeFt = is_numeric($item['alt_baro'] ?? null) ? (float)$item['alt_baro'] : null;
            $geoAltitudeFt = is_numeric($item['alt_geom'] ?? null) ? (float)$item['alt_geom'] : null;
            $callsign = substr(trim((string)($item['flight'] ?? $item['r'] ?? '')), 0, 32);
            $time = $item['time'] ?? $item['timestamp'] ?? $item['seen_utc'] ?? $item['sample_time_utc'] ?? null;
            $seenAgeSeconds = $this->providerSeenAgeSeconds($item);
            $sampleTime = $time !== null
                ? $this->providerSampleTime($time, $fallbackSampleTime)
                : $this->providerObservedTime($payloadFetchedAt, $seenAgeSeconds, $fallbackSampleTime);
            $providerRecordKey = hash('sha256', implode('|', array(
                tv_adsb_provider(),
                self::SOURCE_MODE_LIVE,
                (string)($tile['id'] ?? ''),
                $hex,
                $sampleTime,
                round($lat, 5),
                round($lon, 5),
            )));
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
                'provider_record_key' => $providerRecordKey,
                'source_mode' => self::SOURCE_MODE_LIVE,
                'icao24' => $hex,
                'raw_payload_id' => $rawPayloadId,
                'sample_time_utc' => $sampleTime,
                'aircraft_hex' => $hex,
                'callsign' => $callsign,
                'latitude' => $lat,
                'longitude' => $lon,
                'altitude_ft' => $altitudeFt,
                'baro_altitude_ft' => $baroAltitudeFt,
                'geo_altitude_ft' => $geoAltitudeFt,
                'on_ground' => isset($item['ground']) ? (bool)$item['ground'] : (isset($item['onground']) ? (bool)$item['onground'] : null),
                'groundspeed_kt' => is_numeric($item['gs'] ?? null) ? (float)$item['gs'] : null,
                'track_deg' => is_numeric($item['track'] ?? null) ? (float)$item['track'] : null,
                'true_heading_deg' => is_numeric($item['true_heading'] ?? null) ? (float)$item['true_heading'] : null,
                'vertical_speed_fpm' => is_numeric($item['baro_rate'] ?? null) ? (float)$item['baro_rate'] : null,
                'squawk' => isset($item['squawk']) ? substr(trim((string)$item['squawk']), 0, 16) : null,
                'category' => isset($item['category']) ? substr(trim((string)$item['category']), 0, 32) : null,
                'position_source' => isset($item['type']) ? substr(trim((string)$item['type']), 0, 64) : null,
                'nic' => is_numeric($item['nic'] ?? null) ? (int)$item['nic'] : null,
                'nac_p' => is_numeric($item['nac_p'] ?? null) ? (int)$item['nac_p'] : null,
                'sil' => is_numeric($item['sil'] ?? null) ? (int)$item['sil'] : null,
                'emergency_status' => isset($item['emergency']) ? substr(trim((string)$item['emergency']), 0, 64) : null,
                'source_distance_nm' => $distanceNm,
                'raw_json' => AuditEventService::jsonEncode($item),
                'sample_hash' => $sampleHash,
                'normalization_version' => 'adsbexchange_live_v2',
                'quality_flags_json' => AuditEventService::jsonEncode(array(
                    'source_mode' => self::SOURCE_MODE_LIVE,
                    'altitude_source' => $baroAltitudeFt !== null ? 'baro' : ($geoAltitudeFt !== null ? 'geo' : 'unknown'),
                    'position_source' => isset($item['type']) ? (string)$item['type'] : 'unknown',
                    'provider_seen_age_seconds' => $seenAgeSeconds,
                )),
                'observation_fingerprint' => $sampleHash,
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
     * @param array<string,mixed> $item
     */
    private function providerSeenAgeSeconds(array $item): ?float
    {
        foreach (array('seen_pos', 'seen') as $field) {
            if (isset($item[$field]) && is_numeric($item[$field])) {
                return max(0.0, (float)$item[$field]);
            }
        }
        return null;
    }

    private function providerObservedTime(string $fetchedAtUtc, ?float $seenAgeSeconds, string $fallback): string
    {
        $fetchedAt = strtotime($fetchedAtUtc);
        if ($fetchedAt === false) {
            return $fallback;
        }
        $age = $seenAgeSeconds !== null ? min(3600, max(0, (int)round($seenAgeSeconds))) : 0;
        return gmdate('Y-m-d H:i:s', $fetchedAt - $age);
    }

    /**
     * @param list<array<string,mixed>> $samples
     */
    private function storeTrafficSamples(array $samples): int
    {
        if ($samples === array()) {
            return 0;
        }
        $schema = $this->columnsForTable('ipca_adsb_traffic_samples');
        $columns = array_values(array_filter(array(
            'provider',
            !empty($schema['provider_record_key']) ? 'provider_record_key' : null,
            !empty($schema['source_mode']) ? 'source_mode' : null,
            !empty($schema['icao24']) ? 'icao24' : null,
            'raw_payload_id',
            !empty($schema['ingestion_batch_id']) ? 'ingestion_batch_id' : null,
            'sample_time_utc',
            'aircraft_hex',
            'callsign',
            'latitude',
            'longitude',
            'altitude_ft',
            !empty($schema['baro_altitude_ft']) ? 'baro_altitude_ft' : null,
            !empty($schema['geo_altitude_ft']) ? 'geo_altitude_ft' : null,
            !empty($schema['on_ground']) ? 'on_ground' : null,
            'groundspeed_kt',
            'track_deg',
            !empty($schema['true_heading_deg']) ? 'true_heading_deg' : null,
            'vertical_speed_fpm',
            !empty($schema['squawk']) ? 'squawk' : null,
            !empty($schema['category']) ? 'category' : null,
            !empty($schema['position_source']) ? 'position_source' : null,
            !empty($schema['receiver_id']) ? 'receiver_id' : null,
            !empty($schema['signal_quality']) ? 'signal_quality' : null,
            !empty($schema['nic']) ? 'nic' : null,
            !empty($schema['nac_p']) ? 'nac_p' : null,
            !empty($schema['sil']) ? 'sil' : null,
            !empty($schema['emergency_status']) ? 'emergency_status' : null,
            'source_distance_nm',
            'raw_json',
            'sample_hash',
            !empty($schema['normalization_version']) ? 'normalization_version' : null,
            !empty($schema['quality_flags_json']) ? 'quality_flags_json' : null,
            !empty($schema['observation_fingerprint']) ? 'observation_fingerprint' : null,
        )));
        $stmt = $this->pdo->prepare("
            INSERT IGNORE INTO ipca_adsb_traffic_samples
              (" . implode(', ', $columns) . ")
            VALUES
              (:" . implode(', :', $columns) . ")
        ");
        $inserted = 0;
        foreach ($samples as $sample) {
            $params = array();
            foreach ($columns as $column) {
                $params[':' . $column] = $sample[$column] ?? null;
            }
            $stmt->execute($params);
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
        $schema = $this->columnsForTable('ipca_adsb_raw_payloads');
        $values = array(
            'payload_uuid' => AuditEventService::uuid(),
            'provider' => tv_adsb_provider(),
            'source_mode' => self::SOURCE_MODE_LIVE,
            'request_url' => (string)($payload['path'] ?? ''),
            'request_json' => AuditEventService::jsonEncode(array('tile_id' => (int)$tile['id'], 'scope' => (string)$tile['scope'])),
            'http_status' => null,
            'content_type' => 'application/json',
            'compression' => null,
            'sha256' => $sha256,
            'storage_path' => $path,
            'byte_size' => strlen($json),
            'metadata_json' => AuditEventService::jsonEncode(array(
                'source_mode' => self::SOURCE_MODE_LIVE,
                'tile_id' => (int)($tile['id'] ?? 0),
                'scope' => (string)($tile['scope'] ?? ''),
            )),
        );
        $columns = array_values(array_filter(array(
            'payload_uuid',
            'provider',
            !empty($schema['source_mode']) ? 'source_mode' : null,
            'request_url',
            'request_json',
            'http_status',
            !empty($schema['content_type']) ? 'content_type' : null,
            !empty($schema['compression']) ? 'compression' : null,
            'sha256',
            'storage_path',
            'byte_size',
            !empty($schema['metadata_json']) ? 'metadata_json' : null,
        )));
        $stmt = $this->pdo->prepare("
            INSERT IGNORE INTO ipca_adsb_raw_payloads
              (" . implode(', ', $columns) . ")
            VALUES
              (:" . implode(', :', $columns) . ")
        ");
        $params = array();
        foreach ($columns as $column) {
            $params[':' . $column] = $values[$column] ?? null;
        }
        $stmt->execute($params);
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
              SUM(status IN ('pending', 'fetching', 'failed', 'provider_not_configured')) AS remaining
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

    private function markTileReady(int $tileId, int $rawPayloadId, int $returnedCount, int $insertedCount): void
    {
        $schema = $this->columnsForTable('ipca_adsb_coverage_tiles');
        $sets = array(
            "status = 'ready'",
            'raw_payload_id = :raw_payload_id',
            'sample_count = :sample_count',
            'empty_result = :empty_result',
            'last_error = NULL',
            'fetched_at = CURRENT_TIMESTAMP(3)',
            'updated_at = CURRENT_TIMESTAMP(3)',
        );
        $params = array(
            ':raw_payload_id' => $rawPayloadId,
            ':sample_count' => $returnedCount,
            ':empty_result' => $returnedCount === 0 ? 1 : 0,
            ':id' => $tileId,
        );
        $optional = array(
            'result_status' => 'READY',
            'returned_count' => $returnedCount,
            'inserted_count' => $insertedCount,
            'duplicate_count' => max(0, $returnedCount - $insertedCount),
            'invalid_count' => 0,
            'coverage_metrics_json' => AuditEventService::jsonEncode(array(
                'returned_count' => $returnedCount,
                'inserted_count' => $insertedCount,
                'duplicate_count' => max(0, $returnedCount - $insertedCount),
            )),
        );
        foreach ($optional as $column => $value) {
            if (!empty($schema[$column])) {
                $sets[] = $column . ' = :' . $column;
                $params[':' . $column] = $value;
            }
        }
        $this->pdo->prepare('UPDATE ipca_adsb_coverage_tiles SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
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
     * @param list<array{lat:float,lon:float}> $points
     */
    private function pathCoverageStatus(string $start, string $end, array $points, float $radiusNm): string
    {
        $points = $this->representativePoints($points, 8);
        if ($points === array()) {
            return 'missing';
        }
        $total = 0;
        $ready = 0;
        foreach ($points as $point) {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) AS total, SUM(status = 'ready') AS ready
                FROM ipca_adsb_coverage_tiles
                WHERE scope = 'flight_corridor'
                  AND bucket_start_utc < ?
                  AND bucket_end_utc > ?
                  AND ABS(center_latitude - ?) < 0.01
                  AND ABS(center_longitude - ?) < 0.01
                  AND radius_nm >= ?
            ");
            $stmt->execute(array($end, $start, (float)$point['lat'], (float)$point['lon'], $radiusNm));
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: array();
            $total += (int)($row['total'] ?? 0);
            $ready += (int)($row['ready'] ?? 0);
        }
        if ($total === 0) {
            return 'missing';
        }
        if ($ready >= $total) {
            return 'complete';
        }
        return $ready > 0 ? 'partial' : 'queued';
    }

    /**
     * @param list<array{lat:float,lon:float}> $points
     * @return list<array{lat:float,lon:float}>
     */
    private function representativePoints(array $points, int $maxPoints): array
    {
        $valid = array_values(array_filter(array_map(static function (array $point): ?array {
            $lat = $point['lat'] ?? null;
            $lon = $point['lon'] ?? null;
            if (!is_numeric($lat) || !is_numeric($lon)) {
                return null;
            }
            $lat = (float)$lat;
            $lon = (float)$lon;
            if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
                return null;
            }
            return array('lat' => $lat, 'lon' => $lon);
        }, $points)));
        $count = count($valid);
        if ($count <= $maxPoints) {
            return $valid;
        }
        $out = array();
        for ($i = 0; $i < $maxPoints; $i++) {
            $index = (int)round($i * ($count - 1) / max(1, $maxPoints - 1));
            $out[] = $valid[$index];
        }
        $deduped = array();
        foreach ($out as $point) {
            $key = round($point['lat'], 3) . ',' . round($point['lon'], 3);
            $deduped[$key] = $point;
        }
        return array_values($deduped);
    }

    /**
     * @param list<array{lat:float,lon:float}> $points
     */
    private function nearestPathDistanceNm(float $lat, float $lon, array $points): float
    {
        $best = INF;
        foreach ($points as $point) {
            $best = min($best, tv_adsb_haversine_nm($lat, $lon, (float)$point['lat'], (float)$point['lon']));
        }
        return is_finite($best) ? $best : INF;
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

    /**
     * @return array<string,bool>
     */
    private function columnsForTable(string $table): array
    {
        $stmt = $this->pdo->prepare('
            SELECT COLUMN_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ');
        $stmt->execute(array($table));
        $columns = array();
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: array() as $column) {
            $columns[(string)$column] = true;
        }
        return $columns;
    }

    private function tablePresent(string $table): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute(array($table));
        return (int)$stmt->fetchColumn() > 0;
    }
}
