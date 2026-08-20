<?php
declare(strict_types=1);

require_once __DIR__ . '/G3XFlightStreamParser.php';
require_once __DIR__ . '/GarminSyncPowerUpAnalysisService.php';

/**
 * Matches the median start/end positions of Flight files to the seeded
 * ipca_airports coordinate catalog. No operational flight record is created.
 */
final class GarminSyncAirportAnalysisService
{
    public const VERSION = 'seeded-airport-endpoints-v1';
    private const MAXIMUM_DISTANCE_NM = 12.0;
    private const ENDPOINT_WINDOW_SAMPLES = 60;

    private string $projectRoot;

    public function __construct(private PDO $pdo, ?string $projectRoot = null)
    {
        $this->projectRoot = rtrim($projectRoot ?? dirname(__DIR__), DIRECTORY_SEPARATOR);
    }

    /**
     * @return array<string,mixed>
     */
    public function analyzeArchiveId(int $archiveFileId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT a.*
             FROM ipca_garmin_sync_archive_files a
             INNER JOIN ipca_garmin_sync_file_activity_analyses activity
               ON activity.archive_file_id = a.id
             WHERE a.id = ? AND activity.activity_kind = 'FLIGHT'
             LIMIT 1"
        );
        $stmt->execute(array($archiveFileId));
        $archive = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($archive)) {
            throw new RuntimeException('Archive is not labeled Flight.');
        }
        return $this->analyzeArchiveRow($archive);
    }

    /**
     * @return array{processed:int,complete:int,partial:int,unknown:int}
     */
    public function analyzePending(int $limit = 500, bool $reanalyze = false): array
    {
        $limit = max(1, min(5000, $limit));
        $airportJoin = $reanalyze
            ? ''
            : 'LEFT JOIN ipca_garmin_sync_file_airport_analyses airports ON airports.archive_file_id = a.id';
        $airportWhere = $reanalyze ? '' : 'AND airports.id IS NULL';
        $stmt = $this->pdo->query(
            "SELECT a.*
             FROM ipca_garmin_sync_archive_files a
             INNER JOIN ipca_garmin_sync_file_activity_analyses activity
               ON activity.archive_file_id = a.id
             {$airportJoin}
             WHERE activity.activity_kind = 'FLIGHT'
             {$airportWhere}
             ORDER BY a.id ASC
             LIMIT {$limit}"
        );
        $archives = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();
        $summary = array('processed' => 0, 'complete' => 0, 'partial' => 0, 'unknown' => 0);
        foreach (is_array($archives) ? $archives : array() as $archive) {
            $analysis = $this->analyzeArchiveRow($archive);
            $summary['processed']++;
            $status = strtolower((string)($analysis['derivation_status'] ?? 'unknown'));
            $summary[array_key_exists($status, $summary) ? $status : 'unknown']++;
        }
        return $summary;
    }

    /**
     * @return array<string,mixed>
     */
    public function analyzePath(string $path): array
    {
        $parsed = G3XFlightStreamParser::parseFile($path);
        $positions = array();
        foreach ($parsed['rows'] as $row) {
            $time = G3XFlightStreamParser::rowUtcTimestamp($row);
            $latitude = G3XFlightStreamParser::numericValue($row, 'Latitude (deg)', 'Latitude', 'Lat');
            $longitude = G3XFlightStreamParser::numericValue($row, 'Longitude (deg)', 'Longitude', 'Lon');
            if (
                $time !== null && $latitude !== null && $longitude !== null
                && abs($latitude) <= 90.0 && abs($longitude) <= 180.0
            ) {
                $positions[] = array(
                    'time' => $time,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                );
            }
        }
        usort($positions, static fn(array $left, array $right): int => $left['time'] <=> $right['time']);

        $departurePoint = $this->endpointPoint(array_slice(
            $positions,
            0,
            self::ENDPOINT_WINDOW_SAMPLES
        ));
        $arrivalPoint = $this->endpointPoint(array_slice(
            $positions,
            -self::ENDPOINT_WINDOW_SAMPLES
        ));
        $departure = $departurePoint === null
            ? null
            : $this->nearestSeededAirport($departurePoint['latitude'], $departurePoint['longitude']);
        $arrival = $arrivalPoint === null
            ? null
            : $this->nearestSeededAirport($arrivalPoint['latitude'], $arrivalPoint['longitude']);

        $status = $departure !== null && $arrival !== null
            ? 'COMPLETE'
            : (($departure !== null || $arrival !== null) ? 'PARTIAL' : 'UNKNOWN');
        $confidence = $this->confidence($departure, $arrival);
        $exceptions = array();
        if ($departure === null) {
            $exceptions[] = 'Departure airport was not found within 12 NM of the median starting position.';
        }
        if ($arrival === null) {
            $exceptions[] = 'Arrival airport was not found within 12 NM of the median ending position.';
        }
        $reason = $status === 'COMPLETE'
            ? 'Departure and arrival are the nearest seeded airports to the median Garmin endpoint positions.'
            : implode(' ', $exceptions);

        return array(
            'departure_airport_code' => $departure['code'] ?? null,
            'departure_airport_name' => $departure['name'] ?? null,
            'departure_distance_nm' => $departure['distance_nm'] ?? null,
            'arrival_airport_code' => $arrival['code'] ?? null,
            'arrival_airport_name' => $arrival['name'] ?? null,
            'arrival_distance_nm' => $arrival['distance_nm'] ?? null,
            'derivation_status' => $status,
            'confidence' => $confidence,
            'analysis_reason' => $reason,
            'evidence' => array(
                'method' => 'nearest_seeded_airport_to_median_endpoint',
                'airport_table' => 'ipca_airports',
                'maximum_distance_nm' => self::MAXIMUM_DISTANCE_NM,
                'endpoint_window_samples' => self::ENDPOINT_WINDOW_SAMPLES,
                'position_sample_count' => count($positions),
                'departure_point' => $departurePoint,
                'arrival_point' => $arrivalPoint,
                'exceptions' => $exceptions,
            ),
            'analyzer_version' => self::VERSION,
        );
    }

    /**
     * @param array<string,mixed> $archive
     * @return array<string,mixed>
     */
    private function analyzeArchiveRow(array $archive): array
    {
        $archiveId = (int)($archive['id'] ?? 0);
        if ($archiveId <= 0) {
            throw new RuntimeException('Garmin Sync archive identity is invalid.');
        }
        $analysis = $this->analyzePath($this->archivePath((string)($archive['storage_path'] ?? '')));
        $evidenceJson = json_encode(
            $analysis['evidence'],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $stmt = $this->pdo->prepare(
            'INSERT INTO ipca_garmin_sync_file_airport_analyses
              (archive_file_id, departure_airport_code, departure_airport_name,
               departure_distance_nm, arrival_airport_code, arrival_airport_name,
               arrival_distance_nm, derivation_status, confidence, analysis_reason,
               evidence_json, analyzer_version, analyzed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP(3))
             ON DUPLICATE KEY UPDATE
               departure_airport_code = VALUES(departure_airport_code),
               departure_airport_name = VALUES(departure_airport_name),
               departure_distance_nm = VALUES(departure_distance_nm),
               arrival_airport_code = VALUES(arrival_airport_code),
               arrival_airport_name = VALUES(arrival_airport_name),
               arrival_distance_nm = VALUES(arrival_distance_nm),
               derivation_status = VALUES(derivation_status),
               confidence = VALUES(confidence),
               analysis_reason = VALUES(analysis_reason),
               evidence_json = VALUES(evidence_json),
               analyzer_version = VALUES(analyzer_version),
               analyzed_at = VALUES(analyzed_at)'
        );
        $stmt->execute(array(
            $archiveId,
            $analysis['departure_airport_code'],
            $analysis['departure_airport_name'],
            $analysis['departure_distance_nm'],
            $analysis['arrival_airport_code'],
            $analysis['arrival_airport_name'],
            $analysis['arrival_distance_nm'],
            $analysis['derivation_status'],
            $analysis['confidence'],
            $analysis['analysis_reason'],
            $evidenceJson,
            self::VERSION,
        ));
        $analysis['archive_file_id'] = $archiveId;
        return $analysis;
    }

    /**
     * @param list<array{time:DateTimeImmutable,latitude:float,longitude:float}> $positions
     * @return array{latitude:float,longitude:float}|null
     */
    private function endpointPoint(array $positions): ?array
    {
        if ($positions === array()) {
            return null;
        }
        return array(
            'latitude' => $this->median(array_column($positions, 'latitude')),
            'longitude' => $this->median(array_column($positions, 'longitude')),
        );
    }

    /**
     * @return array{code:string,name:string,distance_nm:float}|null
     */
    private function nearestSeededAirport(float $latitude, float $longitude): ?array
    {
        $latitudeDelta = self::MAXIMUM_DISTANCE_NM / 60.0;
        $longitudeDelta = self::MAXIMUM_DISTANCE_NM
            / max(10.0, abs(60.0 * cos(deg2rad($latitude))));
        $stmt = $this->pdo->prepare(
            'SELECT icao_identifier, full_name, latitude_deg, longitude_deg
             FROM ipca_airports
             WHERE latitude_deg BETWEEN ? AND ?
               AND longitude_deg BETWEEN ? AND ?
             LIMIT 250'
        );
        $stmt->execute(array(
            $latitude - $latitudeDelta,
            $latitude + $latitudeDelta,
            $longitude - $longitudeDelta,
            $longitude + $longitudeDelta,
        ));
        $best = null;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $airport) {
            $distance = $this->distanceNm(
                $latitude,
                $longitude,
                (float)$airport['latitude_deg'],
                (float)$airport['longitude_deg']
            );
            if ($distance > self::MAXIMUM_DISTANCE_NM) {
                continue;
            }
            if ($best === null || $distance < $best['distance_nm']) {
                $best = array(
                    'code' => strtoupper(trim((string)$airport['icao_identifier'])),
                    'name' => (string)$airport['full_name'],
                    'distance_nm' => round($distance, 3),
                );
            }
        }
        return $best;
    }

    /**
     * @param array<string,mixed>|null $departure
     * @param array<string,mixed>|null $arrival
     */
    private function confidence(?array $departure, ?array $arrival): float
    {
        $scores = array();
        foreach (array($departure, $arrival) as $airport) {
            if ($airport === null) {
                $scores[] = 0.0;
                continue;
            }
            $distance = (float)$airport['distance_nm'];
            $scores[] = $distance <= 3.0 ? 0.90 : ($distance <= 8.0 ? 0.75 : 0.55);
        }
        return round(min($scores ?: array(0.0)), 3);
    }

    /**
     * @param list<float|int|string> $values
     */
    private function median(array $values): float
    {
        $numbers = array_map('floatval', $values);
        sort($numbers, SORT_NUMERIC);
        $count = count($numbers);
        $middle = intdiv($count, 2);
        return $count % 2 === 1
            ? $numbers[$middle]
            : ($numbers[$middle - 1] + $numbers[$middle]) / 2.0;
    }

    private function archivePath(string $storagePath): string
    {
        if ($storagePath === '') {
            throw new RuntimeException('Archived Garmin file path is missing.');
        }
        if (str_starts_with($storagePath, DIRECTORY_SEPARATOR)) {
            return $storagePath;
        }
        return $this->projectRoot . DIRECTORY_SEPARATOR . ltrim($storagePath, DIRECTORY_SEPARATOR);
    }

    private function distanceNm(
        float $latitude1,
        float $longitude1,
        float $latitude2,
        float $longitude2
    ): float {
        $earthRadiusNm = 3440.065;
        $latDelta = deg2rad($latitude2 - $latitude1);
        $lonDelta = deg2rad($longitude2 - $longitude1);
        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($latitude1)) * cos(deg2rad($latitude2)) * sin($lonDelta / 2) ** 2;
        return 2 * $earthRadiusNm * asin(min(1.0, sqrt($a)));
    }
}
