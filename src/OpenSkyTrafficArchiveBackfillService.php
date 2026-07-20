<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';
require_once __DIR__ . '/CockpitRecorderService.php';
require_once __DIR__ . '/OpenSkyTrinoClient.php';
require_once __DIR__ . '/tv_adsb_status.php';

final class OpenSkyTrafficArchiveBackfillService
{
    private const PROVIDER = 'opensky';
    private const SOURCE_MODE = 'HISTORICAL';
    private const DEFAULT_RADIUS_NM = 25.0;

    public function __construct(private PDO $pdo, private ?OpenSkyTrinoClient $client = null)
    {
        $this->client ??= new OpenSkyTrinoClient();
    }

    /**
     * @return array<string,mixed>
     */
    public function status(): array
    {
        return array('ok' => true, 'provider' => self::PROVIDER, 'source_mode' => self::SOURCE_MODE, 'trino' => $this->client->configurationSummary());
    }

    /**
     * @return array<string,mixed>
     */
    public function backfillRecording(string $recordingIdOrUid, float $radiusNm = self::DEFAULT_RADIUS_NM, int $chunkMinutes = 60, bool $dryRun = false): array
    {
        $recording = (new CockpitRecorderService($this->pdo))->recordingByAnyId($recordingIdOrUid);
        if (!$recording) {
            throw new RuntimeException('Recording not found.');
        }
        $startedAt = trim((string)($recording['started_at'] ?? ''));
        $durationSeconds = max(0, (int)round((float)($recording['duration_seconds'] ?? 0)));
        if ($startedAt === '' || $durationSeconds <= 0) {
            throw new RuntimeException('Recording has no usable UTC time window.');
        }
        $start = (new DateTimeImmutable($startedAt, new DateTimeZone('UTC')))->modify('-30 seconds');
        $end = $start->modify('+' . ($durationSeconds + 60) . ' seconds');
        $points = $this->replayPathPoints((int)$recording['id']);
        if ($points === array()) {
            throw new RuntimeException('Recording has no replay path points for OpenSky corridor backfill.');
        }
        return $this->backfillPath($start, $end, $points, $radiusNm, $chunkMinutes, 'cockpit_recording', (int)$recording['id'], $dryRun);
    }

    /**
     * @param list<array{lat:float,lon:float}> $points
     * @return array<string,mixed>
     */
    public function backfillPath(DateTimeImmutable $start, DateTimeImmutable $end, array $points, float $radiusNm = self::DEFAULT_RADIUS_NM, int $chunkMinutes = 60, string $sourceRefType = 'manual', int $sourceRefId = 0, bool $dryRun = false): array
    {
        if ($end <= $start) {
            throw new RuntimeException('OpenSky backfill end time must be after start time.');
        }
        if (!$this->client->configured()) {
            throw new RuntimeException('OpenSky Trino is not configured.');
        }
        $points = $this->representativePoints($points, 24);
        if ($points === array()) {
            throw new RuntimeException('No valid path points for OpenSky backfill.');
        }
        $radiusNm = max(1.0, min(100.0, $radiusNm));
        $chunkMinutes = max(5, min(180, $chunkMinutes));
        $chunks = $this->chunks($start, $end, $chunkMinutes);
        if ($dryRun) {
            return array(
                'ok' => true,
                'dry_run' => true,
                'provider' => self::PROVIDER,
                'source_mode' => self::SOURCE_MODE,
                'start_utc' => $this->mysqlDate($start),
                'end_utc' => $this->mysqlDate($end),
                'radius_nm' => $radiusNm,
                'path_point_count' => count($points),
                'chunk_count' => count($chunks),
                'queries' => array_map(fn(array $chunk): array => array('start_utc' => $this->mysqlDate($chunk['start']), 'end_utc' => $this->mysqlDate($chunk['end']), 'sql' => $this->queryForChunk($chunk['start'], $chunk['end'], $points, $radiusNm)), $chunks),
            );
        }

        $jobId = $this->createCoverageJob($start, $end, $points[0], $radiusNm, $sourceRefType, $sourceRefId, count($chunks));
        $totalReturned = 0;
        $totalInserted = 0;
        $tileResults = array();
        foreach ($chunks as $chunk) {
            $tileId = $this->createCoverageTile($jobId, $chunk['start'], $chunk['end'], $points[0], $radiusNm);
            try {
                $sql = $this->queryForChunk($chunk['start'], $chunk['end'], $points, $radiusNm);
                $rows = $this->client->queryRows($sql, 180);
                $samples = $this->normalizeRows($rows, $points, $radiusNm);
                $rawPayloadId = $this->storeRawPayload($rows, $tileId, $sql, $chunk['start'], $chunk['end']);
                foreach ($samples as &$sample) {
                    $sample['raw_payload_id'] = $rawPayloadId;
                    $sample['ingestion_batch_id'] = $jobId;
                }
                unset($sample);
                $inserted = $this->storeTrafficSamples($samples);
                $this->markTileReady($tileId, $rawPayloadId, count($samples), $inserted);
                $totalReturned += count($samples);
                $totalInserted += $inserted;
                $tileResults[] = array('tile_id' => $tileId, 'returned' => count($samples), 'inserted' => $inserted);
            } catch (Throwable $e) {
                $this->markTileFailed($tileId, $e->getMessage());
                $this->refreshJobStats($jobId);
                throw $e;
            }
            $this->refreshJobStats($jobId);
        }
        $this->refreshJobStats($jobId);
        return array(
            'ok' => true,
            'provider' => self::PROVIDER,
            'source_mode' => self::SOURCE_MODE,
            'job_id' => $jobId,
            'chunks' => count($chunks),
            'returned_count' => $totalReturned,
            'inserted_count' => $totalInserted,
            'duplicate_count' => max(0, $totalReturned - $totalInserted),
            'tiles' => $tileResults,
        );
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param list<array{lat:float,lon:float}> $points
     * @return list<array<string,mixed>>
     */
    private function normalizeRows(array $rows, array $points, float $radiusNm): array
    {
        $samples = array();
        foreach ($rows as $row) {
            $lat = isset($row['lat']) && is_numeric($row['lat']) ? (float)$row['lat'] : null;
            $lon = isset($row['lon']) && is_numeric($row['lon']) ? (float)$row['lon'] : null;
            $hex = tv_adsb_normalize_hex((string)($row['icao24'] ?? ''));
            $time = isset($row['sample_time']) && is_numeric($row['sample_time']) ? (int)$row['sample_time'] : null;
            if ($lat === null || $lon === null || $hex === '' || $time === null) {
                continue;
            }
            $distanceNm = $this->nearestPathDistanceNm($lat, $lon, $points);
            if ($distanceNm > $radiusNm) {
                continue;
            }
            $sampleTime = gmdate('Y-m-d H:i:s', $time);
            $baroAltitudeFt = isset($row['baroaltitude_m']) && is_numeric($row['baroaltitude_m']) ? (float)$row['baroaltitude_m'] * 3.280839895 : null;
            $geoAltitudeFt = isset($row['geoaltitude_m']) && is_numeric($row['geoaltitude_m']) ? (float)$row['geoaltitude_m'] * 3.280839895 : null;
            $altitudeFt = $baroAltitudeFt ?? $geoAltitudeFt;
            $groundspeedKt = isset($row['velocity_ms']) && is_numeric($row['velocity_ms']) ? (float)$row['velocity_ms'] * 1.943844492 : null;
            $verticalSpeedFpm = isset($row['vertrate_ms']) && is_numeric($row['vertrate_ms']) ? (float)$row['vertrate_ms'] * 196.850394 : null;
            $sampleHash = hash('sha256', implode('|', array(self::PROVIDER, $hex, $sampleTime, round($lat, 5), round($lon, 5), $altitudeFt !== null ? round($altitudeFt, 0) : '')));
            $samples[] = array(
                'provider' => self::PROVIDER,
                'provider_record_key' => hash('sha256', self::PROVIDER . '|state_vectors_data4|' . $hex . '|' . $sampleTime . '|' . round($lat, 6) . '|' . round($lon, 6)),
                'source_mode' => self::SOURCE_MODE,
                'icao24' => $hex,
                'raw_payload_id' => null,
                'ingestion_batch_id' => null,
                'sample_time_utc' => $sampleTime,
                'aircraft_hex' => $hex,
                'callsign' => substr(trim((string)($row['callsign'] ?? '')), 0, 32),
                'latitude' => $lat,
                'longitude' => $lon,
                'altitude_ft' => $altitudeFt,
                'baro_altitude_ft' => $baroAltitudeFt,
                'geo_altitude_ft' => $geoAltitudeFt,
                'on_ground' => isset($row['onground']) ? (bool)$row['onground'] : null,
                'groundspeed_kt' => $groundspeedKt,
                'track_deg' => isset($row['heading_deg']) && is_numeric($row['heading_deg']) ? (float)$row['heading_deg'] : null,
                'true_heading_deg' => isset($row['heading_deg']) && is_numeric($row['heading_deg']) ? (float)$row['heading_deg'] : null,
                'vertical_speed_fpm' => $verticalSpeedFpm,
                'squawk' => isset($row['squawk']) ? substr(trim((string)$row['squawk']), 0, 16) : null,
                'category' => null,
                'position_source' => 'opensky_state_vectors_data4',
                'receiver_id' => null,
                'signal_quality' => null,
                'nic' => null,
                'nac_p' => null,
                'sil' => null,
                'emergency_status' => null,
                'source_distance_nm' => $distanceNm,
                'raw_json' => AuditEventService::jsonEncode($row),
                'sample_hash' => $sampleHash,
                'normalization_version' => 'opensky_state_vectors_data4_v1',
                'quality_flags_json' => AuditEventService::jsonEncode(array(
                    'source_mode' => self::SOURCE_MODE,
                    'altitude_source' => $baroAltitudeFt !== null ? 'baro' : ($geoAltitudeFt !== null ? 'geo' : 'unknown'),
                    'provider_record_identity' => 'derived_from_state_vector',
                )),
                'observation_fingerprint' => $sampleHash,
            );
        }
        return $samples;
    }

    /**
     * @param list<array{lat:float,lon:float}> $points
     */
    private function queryForChunk(DateTimeImmutable $start, DateTimeImmutable $end, array $points, float $radiusNm): string
    {
        $bounds = $this->boundsForPoints($points, $radiusNm);
        $startEpoch = $start->getTimestamp();
        $endEpoch = $end->getTimestamp();
        $startHour = (int)(floor($startEpoch / 3600) * 3600);
        $endHour = (int)(floor(($endEpoch + 3599) / 3600) * 3600);
        return implode("\n", array(
            'SELECT',
            '  time AS sample_time,',
            '  LOWER(TRIM(icao24)) AS icao24,',
            '  callsign,',
            '  lat,',
            '  lon,',
            '  baroaltitude AS baroaltitude_m,',
            '  geoaltitude AS geoaltitude_m,',
            '  velocity AS velocity_ms,',
            '  heading AS heading_deg,',
            '  vertrate AS vertrate_ms,',
            '  onground,',
            '  squawk',
            'FROM ' . $this->client->qualifiedTable(),
            'WHERE time >= ' . $startEpoch,
            '  AND time < ' . $endEpoch,
            '  AND hour >= ' . $startHour,
            '  AND hour <= ' . $endHour,
            '  AND lat BETWEEN ' . $this->sqlFloat($bounds['min_lat']) . ' AND ' . $this->sqlFloat($bounds['max_lat']),
            '  AND lon BETWEEN ' . $this->sqlFloat($bounds['min_lon']) . ' AND ' . $this->sqlFloat($bounds['max_lon']),
            '  AND lat IS NOT NULL',
            '  AND lon IS NOT NULL',
            '  AND icao24 IS NOT NULL',
            'ORDER BY time ASC',
        ));
    }

    /**
     * @param list<array{lat:float,lon:float}> $points
     * @return array{min_lat:float,max_lat:float,min_lon:float,max_lon:float}
     */
    private function boundsForPoints(array $points, float $radiusNm): array
    {
        $lats = array_map(static fn(array $p): float => (float)$p['lat'], $points);
        $lons = array_map(static fn(array $p): float => (float)$p['lon'], $points);
        $centerLat = array_sum($lats) / max(1, count($lats));
        $latDelta = $radiusNm / 60.0;
        $lonDelta = $radiusNm / max(1.0, 60.0 * cos(deg2rad($centerLat)));
        return array('min_lat' => min($lats) - $latDelta, 'max_lat' => max($lats) + $latDelta, 'min_lon' => min($lons) - $lonDelta, 'max_lon' => max($lons) + $lonDelta);
    }

    /**
     * @return list<array{start:DateTimeImmutable,end:DateTimeImmutable}>
     */
    private function chunks(DateTimeImmutable $start, DateTimeImmutable $end, int $chunkMinutes): array
    {
        $chunks = array();
        for ($cursor = $start; $cursor < $end; $cursor = $cursor->modify('+' . $chunkMinutes . ' minutes')) {
            $chunkEnd = $cursor->modify('+' . $chunkMinutes . ' minutes');
            if ($chunkEnd > $end) {
                $chunkEnd = $end;
            }
            $chunks[] = array('start' => $cursor, 'end' => $chunkEnd);
        }
        return $chunks;
    }

    /**
     * @return list<array{lat:float,lon:float}>
     */
    private function replayPathPoints(int $recordingId): array
    {
        if ($recordingId <= 0 || !$this->tablePresent('ipca_cockpit_replay_samples')) {
            return array();
        }
        $stmt = $this->pdo->prepare('
            SELECT latitude, longitude
            FROM ipca_cockpit_replay_samples
            WHERE recording_id = ?
              AND latitude IS NOT NULL
              AND longitude IS NOT NULL
            ORDER BY sample_index ASC
        ');
        $stmt->execute(array($recordingId));
        return $this->representativePoints(array_map(static fn(array $row): array => array('lat' => (float)$row['latitude'], 'lon' => (float)$row['longitude']), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array()), 24);
    }

    /**
     * @param list<array{lat:float,lon:float}> $points
     * @return list<array{lat:float,lon:float}>
     */
    private function representativePoints(array $points, int $maxPoints): array
    {
        $clean = array_values(array_filter($points, static fn(array $p): bool => isset($p['lat'], $p['lon']) && is_numeric($p['lat']) && is_numeric($p['lon'])));
        $count = count($clean);
        if ($count <= $maxPoints) {
            return $clean;
        }
        $out = array();
        for ($i = 0; $i < $maxPoints; $i++) {
            $out[] = $clean[(int)round($i * ($count - 1) / max(1, $maxPoints - 1))];
        }
        return $out;
    }

    private function nearestPathDistanceNm(float $lat, float $lon, array $points): float
    {
        $best = INF;
        foreach ($points as $point) {
            $best = min($best, $this->distanceNm($lat, $lon, (float)$point['lat'], (float)$point['lon']));
        }
        return $best;
    }

    private function distanceNm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthNm = 3440.065;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return $earthNm * 2 * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a)));
    }

    private function createCoverageJob(DateTimeImmutable $start, DateTimeImmutable $end, array $point, float $radiusNm, string $sourceRefType, int $sourceRefId, int $tileCount): int
    {
        $schema = $this->columnsForTable('ipca_adsb_coverage_jobs');
        $values = array(
            'job_uuid' => AuditEventService::uuid(),
            'organization_id' => 1,
            'scope' => 'flight_corridor',
            'provider' => self::PROVIDER,
            'source_mode' => self::SOURCE_MODE,
            'status' => 'processing',
            'center_latitude' => (float)$point['lat'],
            'center_longitude' => (float)$point['lon'],
            'radius_nm' => $radiusNm,
            'query_start_utc' => $this->mysqlDate($start),
            'query_end_utc' => $this->mysqlDate($end),
            'bucket_seconds' => 3600,
            'source_ref_type' => substr($sourceRefType, 0, 64),
            'source_ref_id' => $sourceRefId > 0 ? $sourceRefId : null,
            'tile_count' => $tileCount,
            'request_parameters_json' => AuditEventService::jsonEncode(array('provider' => self::PROVIDER, 'source_mode' => self::SOURCE_MODE)),
            'started_at' => $this->mysqlDate(new DateTimeImmutable('now', new DateTimeZone('UTC'))),
            'coverage_status' => 'PROCESSING',
        );
        $columns = $this->existingColumns($schema, array_keys($values));
        $stmt = $this->pdo->prepare('INSERT INTO ipca_adsb_coverage_jobs (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')');
        $this->executeInsert($stmt, $columns, $values);
        return (int)$this->pdo->lastInsertId();
    }

    private function createCoverageTile(int $jobId, DateTimeImmutable $start, DateTimeImmutable $end, array $point, float $radiusNm): int
    {
        $schema = $this->columnsForTable('ipca_adsb_coverage_tiles');
        $values = array(
            'tile_uuid' => AuditEventService::uuid(),
            'job_id' => $jobId,
            'scope' => 'flight_corridor',
            'provider' => self::PROVIDER,
            'source_mode' => self::SOURCE_MODE,
            'status' => 'fetching',
            'result_status' => 'FETCHING',
            'center_latitude' => (float)$point['lat'],
            'center_longitude' => (float)$point['lon'],
            'radius_nm' => $radiusNm,
            'bucket_start_utc' => $this->mysqlDate($start),
            'bucket_end_utc' => $this->mysqlDate($end),
            'attempt_count' => 1,
            'request_parameters_json' => AuditEventService::jsonEncode(array('provider' => self::PROVIDER, 'source_mode' => self::SOURCE_MODE)),
        );
        $columns = $this->existingColumns($schema, array_keys($values));
        $stmt = $this->pdo->prepare('INSERT INTO ipca_adsb_coverage_tiles (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')');
        $this->executeInsert($stmt, $columns, $values);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function storeRawPayload(array $rows, int $tileId, string $sql, DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        $json = AuditEventService::jsonEncode(array('provider' => self::PROVIDER, 'source_mode' => self::SOURCE_MODE, 'query_start_utc' => $this->mysqlDate($start), 'query_end_utc' => $this->mysqlDate($end), 'row_count' => count($rows), 'rows' => $rows));
        $sha256 = hash('sha256', $json);
        $path = $this->storeJsonPayload($sha256, $json);
        $schema = $this->columnsForTable('ipca_adsb_raw_payloads');
        $values = array(
            'payload_uuid' => AuditEventService::uuid(),
            'provider' => self::PROVIDER,
            'source_mode' => self::SOURCE_MODE,
            'request_url' => 'trino://' . $this->client->qualifiedTable(),
            'request_json' => AuditEventService::jsonEncode(array('tile_id' => $tileId, 'sql' => $sql)),
            'http_status' => 200,
            'content_type' => 'application/json',
            'compression' => null,
            'sha256' => $sha256,
            'storage_path' => $path,
            'byte_size' => strlen($json),
            'metadata_json' => AuditEventService::jsonEncode(array('provider' => self::PROVIDER, 'source_mode' => self::SOURCE_MODE, 'row_count' => count($rows))),
        );
        $columns = $this->existingColumns($schema, array_keys($values));
        $stmt = $this->pdo->prepare('INSERT IGNORE INTO ipca_adsb_raw_payloads (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')');
        $this->executeInsert($stmt, $columns, $values);
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

    /**
     * @param list<array<string,mixed>> $samples
     */
    private function storeTrafficSamples(array $samples): int
    {
        if ($samples === array()) {
            return 0;
        }
        $schema = $this->columnsForTable('ipca_adsb_traffic_samples');
        $columns = $this->existingColumns($schema, array('provider', 'provider_record_key', 'source_mode', 'icao24', 'raw_payload_id', 'ingestion_batch_id', 'sample_time_utc', 'aircraft_hex', 'callsign', 'latitude', 'longitude', 'altitude_ft', 'baro_altitude_ft', 'geo_altitude_ft', 'on_ground', 'groundspeed_kt', 'track_deg', 'true_heading_deg', 'vertical_speed_fpm', 'squawk', 'category', 'position_source', 'receiver_id', 'signal_quality', 'nic', 'nac_p', 'sil', 'emergency_status', 'source_distance_nm', 'raw_json', 'sample_hash', 'normalization_version', 'quality_flags_json', 'observation_fingerprint'));
        $stmt = $this->pdo->prepare('INSERT IGNORE INTO ipca_adsb_traffic_samples (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')');
        $inserted = 0;
        foreach ($samples as $sample) {
            $this->executeInsert($stmt, $columns, $sample);
            $inserted += $stmt->rowCount() > 0 ? 1 : 0;
        }
        return $inserted;
    }

    private function markTileReady(int $tileId, int $rawPayloadId, int $returnedCount, int $insertedCount): void
    {
        $schema = $this->columnsForTable('ipca_adsb_coverage_tiles');
        $sets = array("status = 'ready'", 'raw_payload_id = :raw_payload_id', 'sample_count = :sample_count', 'empty_result = :empty_result', 'last_error = NULL', 'fetched_at = CURRENT_TIMESTAMP(3)', 'updated_at = CURRENT_TIMESTAMP(3)');
        $params = array(':raw_payload_id' => $rawPayloadId, ':sample_count' => $returnedCount, ':empty_result' => $returnedCount === 0 ? 1 : 0, ':id' => $tileId);
        foreach (array('result_status' => 'READY', 'returned_count' => $returnedCount, 'inserted_count' => $insertedCount, 'duplicate_count' => max(0, $returnedCount - $insertedCount), 'invalid_count' => 0, 'coverage_metrics_json' => AuditEventService::jsonEncode(array('returned_count' => $returnedCount, 'inserted_count' => $insertedCount))) as $column => $value) {
            if (!empty($schema[$column])) {
                $sets[] = $column . ' = :' . $column;
                $params[':' . $column] = $value;
            }
        }
        $this->pdo->prepare('UPDATE ipca_adsb_coverage_tiles SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
    }

    private function markTileFailed(int $tileId, string $error): void
    {
        $schema = $this->columnsForTable('ipca_adsb_coverage_tiles');
        $sets = array("status = 'failed'", 'last_error = :last_error', 'updated_at = CURRENT_TIMESTAMP(3)');
        $params = array(':last_error' => $error, ':id' => $tileId);
        if (!empty($schema['result_status'])) {
            $sets[] = "result_status = 'FAILED'";
        }
        $this->pdo->prepare('UPDATE ipca_adsb_coverage_tiles SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
    }

    private function refreshJobStats(int $jobId): void
    {
        $schema = $this->columnsForTable('ipca_adsb_coverage_jobs');
        $tileSchema = $this->columnsForTable('ipca_adsb_coverage_tiles');
        $returnedExpr = !empty($tileSchema['returned_count']) ? 'COALESCE(SUM(returned_count), 0)' : 'COALESCE(SUM(sample_count), 0)';
        $insertedExpr = !empty($tileSchema['inserted_count']) ? 'COALESCE(SUM(inserted_count), 0)' : 'COALESCE(SUM(sample_count), 0)';
        $duplicateExpr = !empty($tileSchema['duplicate_count']) ? 'COALESCE(SUM(duplicate_count), 0)' : '0';
        $invalidExpr = !empty($tileSchema['invalid_count']) ? 'COALESCE(SUM(invalid_count), 0)' : '0';
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) AS total,
                   SUM(status = 'ready') AS ready,
                   COALESCE(SUM(sample_count), 0) AS samples,
                   {$returnedExpr} AS returned_count,
                   {$insertedExpr} AS inserted_count,
                   {$duplicateExpr} AS duplicate_count,
                   {$invalidExpr} AS invalid_count,
                   SUM(status IN ('pending', 'fetching', 'failed', 'provider_not_configured')) AS remaining
            FROM ipca_adsb_coverage_tiles
            WHERE job_id = ?
        ");
        $stmt->execute(array($jobId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: array();
        $remaining = (int)($row['remaining'] ?? 0);
        $sets = array('tile_count = :tile_count', 'completed_tile_count = :completed_tile_count', 'sample_count = :sample_count', 'status = :status', 'updated_at = CURRENT_TIMESTAMP(3)');
        $params = array(':tile_count' => (int)($row['total'] ?? 0), ':completed_tile_count' => (int)($row['ready'] ?? 0), ':sample_count' => (int)($row['samples'] ?? 0), ':status' => $remaining === 0 ? 'ready' : 'processing', ':id' => $jobId);
        foreach (array('returned_count', 'inserted_count', 'duplicate_count', 'invalid_count') as $column) {
            if (!empty($schema[$column])) {
                $sets[] = $column . ' = :' . $column;
                $params[':' . $column] = (int)($row[$column] ?? 0);
            }
        }
        if (!empty($schema['completed_at']) && $remaining === 0) {
            $sets[] = 'completed_at = CURRENT_TIMESTAMP(3)';
        }
        if (!empty($schema['coverage_status'])) {
            $sets[] = 'coverage_status = :coverage_status';
            $params[':coverage_status'] = $remaining === 0 ? 'READY' : 'PROCESSING';
        }
        $this->pdo->prepare('UPDATE ipca_adsb_coverage_jobs SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
    }

    /**
     * @param list<string> $desired
     * @return list<string>
     */
    private function existingColumns(array $schema, array $desired): array
    {
        return array_values(array_filter($desired, static fn(string $column): bool => !empty($schema[$column])));
    }

    /**
     * @param list<string> $columns
     * @param array<string,mixed> $values
     */
    private function executeInsert(PDOStatement $stmt, array $columns, array $values): void
    {
        $params = array();
        foreach ($columns as $column) {
            $params[':' . $column] = $values[$column] ?? null;
        }
        $stmt->execute($params);
    }

    /**
     * @return array<string,bool>
     */
    private function columnsForTable(string $table): array
    {
        $stmt = $this->pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
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

    private function mysqlDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function sqlFloat(float $value): string
    {
        return rtrim(rtrim(sprintf('%.8F', $value), '0'), '.');
    }
}
