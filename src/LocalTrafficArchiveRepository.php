<?php
declare(strict_types=1);

final class LocalTrafficArchiveRepository
{
    private const KTRM_LAT = 33.626701;
    private const KTRM_LON = -116.160156;
    private const KTRM_RADIUS_NM = 15.0;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{traffic:list<array<string,mixed>>,meta:array<string,mixed>}
     */
    public function trafficForReplay(string $startUtc, string $endUtc, float $centerLat = self::KTRM_LAT, float $centerLon = self::KTRM_LON, float $radiusNm = self::KTRM_RADIUS_NM, ?float $minimumAltitudeFt = null, ?float $maximumAltitudeFt = 10000.0): array
    {
        if (!$this->tablePresent('ipca_adsb_traffic_samples')) {
            return $this->emptyResult($radiusNm, 'local_archive_point', 'missing_table');
        }
        $start = $this->mysqlDate($this->utcDate($startUtc));
        $end = $this->mysqlDate($this->utcDate($endUtc));
        $radiusNm = max(1.0, min(100.0, $radiusNm));
        $schema = $this->trafficSampleSchema();
        $select = $this->trafficSampleSelectList($schema);
        $latDelta = $radiusNm / 60.0;
        $lonDelta = $radiusNm / max(1.0, 60.0 * cos(deg2rad($centerLat)));
        $altitudeSql = $this->altitudeFilterSql($minimumAltitudeFt, $maximumAltitudeFt, $schema);
        $stmt = $this->pdo->prepare("
            SELECT
              id,
              {$select},
              (3440.065 * 2 * ASIN(SQRT(
                POWER(SIN(RADIANS(latitude - ?) / 2), 2)
                + COS(RADIANS(?)) * COS(RADIANS(latitude))
                * POWER(SIN(RADIANS(longitude - ?) / 2), 2)
              ))) AS distance_nm
            FROM ipca_adsb_traffic_samples
            WHERE sample_time_utc BETWEEN ? AND ?
              AND latitude BETWEEN ? AND ?
              AND longitude BETWEEN ? AND ?
              {$altitudeSql['sql']}
            HAVING distance_nm <= ?
            ORDER BY sample_time_utc ASC, distance_nm ASC
            LIMIT 30000
        ");
        $params = array($centerLat, $centerLat, $centerLon, $start, $end, $centerLat - $latDelta, $centerLat + $latDelta, $centerLon - $lonDelta, $centerLon + $lonDelta);
        $params = array_merge($params, $altitudeSql['params'], array($radiusNm));
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $traffic = array_map(fn(array $row): array => $this->compactTrafficSample($row, strtotime($start) ?: null), is_array($rows) ? $rows : array());
        return array('traffic' => $traffic, 'meta' => $this->meta($traffic, $start, $end, $radiusNm, 'local_archive_point', $this->coverageStatus($start, $end, $centerLat, $centerLon, $radiusNm)));
    }

    /**
     * @param list<array{lat:float,lon:float}> $points
     * @return array{traffic:list<array<string,mixed>>,meta:array<string,mixed>}
     */
    public function trafficForReplayPath(string $startUtc, string $endUtc, array $points, float $radiusNm = 25.0, ?float $minimumAltitudeFt = null, ?float $maximumAltitudeFt = 10000.0): array
    {
        if (!$this->tablePresent('ipca_adsb_traffic_samples')) {
            return $this->emptyResult($radiusNm, 'local_archive_flight_corridor', 'missing_table');
        }
        $points = $this->representativePoints($points, 20);
        if ($points === array()) {
            return $this->emptyResult($radiusNm, 'local_archive_flight_corridor', 'missing_path');
        }
        $start = $this->mysqlDate($this->utcDate($startUtc));
        $end = $this->mysqlDate($this->utcDate($endUtc));
        $radiusNm = max(1.0, min(100.0, $radiusNm));
        $schema = $this->trafficSampleSchema();
        $select = $this->trafficSampleSelectList($schema);
        $lats = array_map(static fn(array $p): float => (float)$p['lat'], $points);
        $lons = array_map(static fn(array $p): float => (float)$p['lon'], $points);
        $centerLat = array_sum($lats) / max(1, count($lats));
        $latDelta = $radiusNm / 60.0;
        $lonDelta = $radiusNm / max(1.0, 60.0 * cos(deg2rad($centerLat)));
        $altitudeSql = $this->altitudeFilterSql($minimumAltitudeFt, $maximumAltitudeFt, $schema);
        $stmt = $this->pdo->prepare("
            SELECT id, {$select}
            FROM ipca_adsb_traffic_samples
            WHERE sample_time_utc BETWEEN ? AND ?
              AND latitude BETWEEN ? AND ?
              AND longitude BETWEEN ? AND ?
              {$altitudeSql['sql']}
            ORDER BY sample_time_utc ASC
            LIMIT 50000
        ");
        $params = array($start, $end, min($lats) - $latDelta, max($lats) + $latDelta, min($lons) - $lonDelta, max($lons) + $lonDelta);
        $stmt->execute(array_merge($params, $altitudeSql['params']));
        $traffic = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row) {
            $distance = $this->nearestPathDistanceNm((float)$row['latitude'], (float)$row['longitude'], $points);
            if ($distance <= $radiusNm) {
                $row['distance_nm'] = $distance;
                $traffic[] = $this->compactTrafficSample($row, strtotime($start) ?: null);
            }
        }
        return array('traffic' => $traffic, 'meta' => $this->meta($traffic, $start, $end, $radiusNm, 'local_archive_flight_corridor', $this->pathCoverageStatus($start, $end, $points, $radiusNm) + array('path_point_count' => count($points))));
    }

    /**
     * @return array<string,mixed>
     */
    private function compactTrafficSample(array $row, ?int $startEpoch): array
    {
        $sampleEpoch = strtotime((string)($row['sample_time_utc'] ?? '')) ?: null;
        return array(
            't' => $sampleEpoch !== null && $startEpoch !== null ? max(0.0, (float)($sampleEpoch - $startEpoch)) : null,
            'utc' => (string)($row['sample_time_utc'] ?? ''),
            'hex' => strtolower(trim((string)($row['aircraft_hex'] ?? ''))),
            'cs' => trim((string)($row['callsign'] ?? '')),
            'lat' => isset($row['latitude']) && is_numeric($row['latitude']) ? (float)$row['latitude'] : null,
            'lon' => isset($row['longitude']) && is_numeric($row['longitude']) ? (float)$row['longitude'] : null,
            'trk' => isset($row['track_deg']) && is_numeric($row['track_deg']) ? (float)$row['track_deg'] : null,
            'hdg' => isset($row['true_heading_deg']) && is_numeric($row['true_heading_deg']) ? (float)$row['true_heading_deg'] : null,
            'alt' => isset($row['altitude_ft']) && is_numeric($row['altitude_ft']) ? (float)$row['altitude_ft'] : null,
            'alt_geom' => isset($row['geo_altitude_ft']) && is_numeric($row['geo_altitude_ft']) ? (float)$row['geo_altitude_ft'] : null,
            'gs' => isset($row['groundspeed_kt']) && is_numeric($row['groundspeed_kt']) ? (float)$row['groundspeed_kt'] : null,
            'dist' => isset($row['distance_nm']) && is_numeric($row['distance_nm']) ? round((float)$row['distance_nm'], 2) : null,
            'on_ground' => isset($row['on_ground']) ? (bool)$row['on_ground'] : null,
            'provider' => (string)($row['provider'] ?? ''),
            'source_mode' => (string)($row['source_mode'] ?? 'UNKNOWN'),
        );
    }

    /**
     * @param list<array<string,mixed>> $traffic
     * @param array<string,mixed> $coverage
     * @return array<string,mixed>
     */
    private function meta(array $traffic, string $start, string $end, float $radiusNm, string $source, array $coverage): array
    {
        $providers = array_values(array_unique(array_filter(array_map(static fn(array $row): string => (string)($row['provider'] ?? ''), $traffic))));
        $sourceModes = array_values(array_unique(array_filter(array_map(static fn(array $row): string => (string)($row['source_mode'] ?? ''), $traffic))));
        return array(
            'available' => $traffic !== array(),
            'sample_count' => count($traffic),
            'range_nm' => $radiusNm,
            'provider' => $providers !== array() ? implode(',', $providers) : 'local_archive',
            'providers' => $providers,
            'source_modes' => $sourceModes,
            'source' => $source,
            'query_start_utc' => $start,
            'query_end_utc' => $end,
        ) + $coverage;
    }

    /**
     * @return array{sql:string,params:list<mixed>}
     */
    /**
     * @param array<string,bool> $schema
     * @return array{sql:string,params:list<mixed>}
     */
    private function altitudeFilterSql(?float $minimumAltitudeFt, ?float $maximumAltitudeFt, array $schema): array
    {
        $clauses = array();
        $params = array();
        $altColumns = array();
        foreach (array('baro_altitude_ft', 'altitude_ft', 'geo_altitude_ft') as $column) {
            if (!empty($schema[$column])) {
                $altColumns[] = $column;
            }
        }
        $altExpr = $altColumns !== array() ? 'COALESCE(' . implode(', ', $altColumns) . ')' : 'NULL';
        $groundClause = !empty($schema['on_ground']) ? ' OR on_ground = 1' : '';
        if ($minimumAltitudeFt !== null) {
            $clauses[] = '(' . $altExpr . ' IS NULL OR ' . $altExpr . ' >= ?' . $groundClause . ')';
            $params[] = $minimumAltitudeFt;
        }
        if ($maximumAltitudeFt !== null) {
            $clauses[] = '(' . $altExpr . ' IS NULL OR ' . $altExpr . ' <= ?' . $groundClause . ')';
            $params[] = $maximumAltitudeFt;
        }
        return array('sql' => $clauses !== array() ? ' AND ' . implode(' AND ', $clauses) : '', 'params' => $params);
    }

    /**
     * @param array<string,bool> $schema
     */
    private function trafficSampleSelectList(array $schema): string
    {
        $hexExpr = !empty($schema['icao24']) ? "COALESCE(NULLIF(icao24, ''), aircraft_hex)" : 'aircraft_hex';
        $altColumns = array();
        foreach (array('baro_altitude_ft', 'altitude_ft') as $column) {
            if (!empty($schema[$column])) {
                $altColumns[] = $column;
            }
        }
        $altExpr = $altColumns !== array() ? 'COALESCE(' . implode(', ', $altColumns) . ')' : 'NULL';
        return implode(",\n              ", array(
            !empty($schema['provider']) ? 'provider' : "'' AS provider",
            !empty($schema['source_mode']) ? 'source_mode' : "'UNKNOWN' AS source_mode",
            !empty($schema['raw_payload_id']) ? 'raw_payload_id' : 'NULL AS raw_payload_id',
            'sample_time_utc',
            $hexExpr . ' AS aircraft_hex',
            !empty($schema['callsign']) ? 'callsign' : "'' AS callsign",
            'latitude',
            'longitude',
            $altExpr . ' AS altitude_ft',
            !empty($schema['geo_altitude_ft']) ? 'geo_altitude_ft' : 'NULL AS geo_altitude_ft',
            !empty($schema['on_ground']) ? 'on_ground' : 'NULL AS on_ground',
            !empty($schema['groundspeed_kt']) ? 'groundspeed_kt' : 'NULL AS groundspeed_kt',
            !empty($schema['track_deg']) ? 'track_deg' : 'NULL AS track_deg',
            !empty($schema['true_heading_deg']) ? 'true_heading_deg' : 'NULL AS true_heading_deg',
            !empty($schema['vertical_speed_fpm']) ? 'vertical_speed_fpm' : 'NULL AS vertical_speed_fpm',
            !empty($schema['source_distance_nm']) ? 'source_distance_nm' : 'NULL AS source_distance_nm',
            !empty($schema['observation_fingerprint']) ? 'observation_fingerprint' : (!empty($schema['sample_hash']) ? 'sample_hash AS observation_fingerprint' : 'NULL AS observation_fingerprint'),
            !empty($schema['quality_flags_json']) ? 'quality_flags_json' : 'NULL AS quality_flags_json',
        ));
    }

    /**
     * @return array<string,mixed>
     */
    private function coverageStatus(string $start, string $end, float $lat, float $lon, float $radiusNm): array
    {
        if (!$this->tablePresent('ipca_adsb_coverage_tiles')) {
            return array('coverage_status' => 'UNKNOWN', 'missing_intervals' => array());
        }
        $stmt = $this->pdo->prepare("
            SELECT status, COUNT(*) AS total
            FROM ipca_adsb_coverage_tiles
            WHERE bucket_start_utc < ?
              AND bucket_end_utc > ?
              AND center_latitude BETWEEN ? AND ?
              AND center_longitude BETWEEN ? AND ?
              AND radius_nm >= ?
            GROUP BY status
        ");
        $latDelta = max(0.25, $radiusNm / 60.0);
        $lonDelta = max(0.25, $radiusNm / max(1.0, 60.0 * cos(deg2rad($lat))));
        $stmt->execute(array($end, $start, $lat - $latDelta, $lat + $latDelta, $lon - $lonDelta, $lon + $lonDelta, max(1.0, $radiusNm * 0.75)));
        return $this->coverageSummary($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array());
    }

    /**
     * @param list<array{lat:float,lon:float}> $points
     * @return array<string,mixed>
     */
    private function pathCoverageStatus(string $start, string $end, array $points, float $radiusNm): array
    {
        if (!$this->tablePresent('ipca_adsb_coverage_tiles') || $points === array()) {
            return array('coverage_status' => 'UNKNOWN', 'missing_intervals' => array());
        }
        $lats = array_map(static fn(array $p): float => (float)$p['lat'], $points);
        $lons = array_map(static fn(array $p): float => (float)$p['lon'], $points);
        $centerLat = array_sum($lats) / max(1, count($lats));
        $latDelta = $radiusNm / 60.0;
        $lonDelta = $radiusNm / max(1.0, 60.0 * cos(deg2rad($centerLat)));
        $stmt = $this->pdo->prepare("
            SELECT status, COUNT(*) AS total
            FROM ipca_adsb_coverage_tiles
            WHERE bucket_start_utc < ?
              AND bucket_end_utc > ?
              AND center_latitude BETWEEN ? AND ?
              AND center_longitude BETWEEN ? AND ?
            GROUP BY status
        ");
        $stmt->execute(array($end, $start, min($lats) - $latDelta, max($lats) + $latDelta, min($lons) - $lonDelta, max($lons) + $lonDelta));
        return $this->coverageSummary($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array());
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function coverageSummary(array $rows): array
    {
        if ($rows === array()) {
            return array('coverage_status' => 'UNAVAILABLE', 'coverage_counts' => array(), 'missing_intervals' => array());
        }
        $counts = array();
        foreach ($rows as $row) {
            $counts[(string)($row['status'] ?? 'unknown')] = (int)($row['total'] ?? 0);
        }
        $ready = (int)($counts['ready'] ?? $counts['COMPLETED'] ?? 0);
        $failed = (int)($counts['failed'] ?? $counts['provider_not_configured'] ?? $counts['FAILED'] ?? 0);
        $pending = array_sum($counts) - $ready - $failed;
        $status = $ready > 0 && $failed === 0 && $pending === 0 ? 'COMPLETE' : ($ready > 0 ? 'USABLE_WITH_GAPS' : ($failed > 0 ? 'PARTIAL' : 'UNKNOWN'));
        return array('coverage_status' => $status, 'coverage_counts' => $counts, 'missing_intervals' => array());
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

    /**
     * @param list<array{lat:float,lon:float}> $points
     */
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

    private function emptyResult(float $radiusNm, string $source, string $coverageStatus): array
    {
        return array(
            'traffic' => array(),
            'meta' => array(
                'available' => false,
                'sample_count' => 0,
                'range_nm' => $radiusNm,
                'provider' => 'local_archive',
                'providers' => array(),
                'source_modes' => array(),
                'source' => $source,
                'coverage_status' => $coverageStatus,
                'missing_intervals' => array(),
            ),
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
    private function trafficSampleSchema(): array
    {
        $stmt = $this->pdo->prepare('
            SELECT COLUMN_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ');
        $stmt->execute(array('ipca_adsb_traffic_samples'));
        $schema = array();
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: array() as $column) {
            $schema[(string)$column] = true;
        }
        return $schema;
    }

    private function tablePresent(string $table): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute(array($table));
        return (int)$stmt->fetchColumn() > 0;
    }
}
