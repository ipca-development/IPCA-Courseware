<?php
declare(strict_types=1);

require_once __DIR__ . '/G3XFlightStreamParser.php';
require_once __DIR__ . '/GarminSyncFileClassificationService.php';

/**
 * Conservative, non-operational Power-up versus Flight evidence analysis.
 *
 * A Flight label requires sustained engine RPM and sustained flight-speed
 * evidence. Position movement is recorded as supporting evidence but is not
 * trusted alone because stationary GPS noise can create false displacement.
 */
final class GarminSyncPowerUpAnalysisService
{
    public const VERSION = 'power-up-flight-v1';
    public const POWER_UP = 'POWER_UP';
    public const FLIGHT = 'FLIGHT';

    private const ENGINE_ON_RPM = 1000.0;
    private const FLIGHT_SPEED_KT = 45.0;
    private const MINIMUM_ENGINE_SAMPLES = 5;
    private const MINIMUM_AIRBORNE_SAMPLES = 5;
    private const MINIMUM_FLIGHT_DURATION_SECONDS = 120;

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
             INNER JOIN ipca_garmin_sync_file_classifications c ON c.archive_file_id = a.id
             WHERE a.id = ? AND c.source_kind = 'GARMIN_FLIGHT_CSV'
             LIMIT 1"
        );
        $stmt->execute(array($archiveFileId));
        $archive = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($archive)) {
            throw new RuntimeException('Archive is not a classified Garmin flight CSV.');
        }
        return $this->analyzeArchiveRow($archive);
    }

    /**
     * @return array{processed:int,power_up:int,flight:int}
     */
    public function analyzePending(int $limit = 500, bool $reanalyze = false): array
    {
        $limit = max(1, min(5000, $limit));
        $analysisJoin = $reanalyze
            ? ''
            : 'LEFT JOIN ipca_garmin_sync_file_activity_analyses activity ON activity.archive_file_id = a.id';
        $analysisWhere = $reanalyze ? '' : 'AND activity.id IS NULL';
        $stmt = $this->pdo->query(
            "SELECT a.*
             FROM ipca_garmin_sync_archive_files a
             INNER JOIN ipca_garmin_sync_file_classifications c ON c.archive_file_id = a.id
             {$analysisJoin}
             WHERE c.source_kind = 'GARMIN_FLIGHT_CSV'
             {$analysisWhere}
             ORDER BY a.id ASC
             LIMIT {$limit}"
        );
        $archives = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();
        $summary = array('processed' => 0, 'power_up' => 0, 'flight' => 0);
        foreach (is_array($archives) ? $archives : array() as $archive) {
            $analysis = $this->analyzeArchiveRow($archive);
            $summary['processed']++;
            if (($analysis['activity_kind'] ?? '') === self::FLIGHT) {
                $summary['flight']++;
            } else {
                $summary['power_up']++;
            }
        }
        return $summary;
    }

    /**
     * @return array<string,mixed>
     */
    public function analyzePath(string $path): array
    {
        $parsed = G3XFlightStreamParser::parseFile($path);
        $rows = is_array($parsed['rows'] ?? null) ? $parsed['rows'] : array();
        $firstUtc = G3XFlightStreamParser::firstUtcTimestamp($rows);
        $lastUtc = G3XFlightStreamParser::lastUtcTimestamp($rows);
        $durationSeconds = 0;
        if ($firstUtc !== null && $lastUtc !== null) {
            $durationSeconds = max(
                0,
                (int)round((float)$lastUtc->format('U.u') - (float)$firstUtc->format('U.u'))
            );
        }

        $maximumRpm = null;
        $maximumGroundSpeed = null;
        $maximumAirspeed = null;
        $maximumPositionRadius = null;
        $origin = null;
        $engineSampleCount = 0;
        $airborneSampleCount = 0;

        foreach ($rows as $row) {
            $rpm = G3XFlightStreamParser::numericValue($row, 'RPM', 'E1 RPM', 'Engine RPM');
            $groundSpeed = G3XFlightStreamParser::numericValue(
                $row,
                'GPS Ground Speed (kt)',
                'GndSpd',
                'Ground Speed (kt)'
            );
            $airspeed = G3XFlightStreamParser::numericValue(
                $row,
                'Indicated Airspeed (kt)',
                'IAS'
            );
            if ($rpm !== null) {
                $maximumRpm = $maximumRpm === null ? $rpm : max($maximumRpm, $rpm);
                if ($rpm > self::ENGINE_ON_RPM) {
                    $engineSampleCount++;
                }
            }
            if ($groundSpeed !== null) {
                $maximumGroundSpeed = $maximumGroundSpeed === null
                    ? $groundSpeed
                    : max($maximumGroundSpeed, $groundSpeed);
            }
            if ($airspeed !== null) {
                $maximumAirspeed = $maximumAirspeed === null ? $airspeed : max($maximumAirspeed, $airspeed);
            }
            if (
                ($groundSpeed !== null && $groundSpeed >= self::FLIGHT_SPEED_KT)
                || ($airspeed !== null && $airspeed >= self::FLIGHT_SPEED_KT)
            ) {
                $airborneSampleCount++;
            }

            $latitude = G3XFlightStreamParser::numericValue($row, 'Latitude (deg)', 'Latitude');
            $longitude = G3XFlightStreamParser::numericValue($row, 'Longitude (deg)', 'Longitude');
            if (
                $latitude !== null && $longitude !== null
                && abs($latitude) <= 90.0 && abs($longitude) <= 180.0
            ) {
                $origin ??= array($latitude, $longitude);
                $radius = $this->distanceNm($origin[0], $origin[1], $latitude, $longitude);
                $maximumPositionRadius = $maximumPositionRadius === null
                    ? $radius
                    : max($maximumPositionRadius, $radius);
            }
        }

        $hasFlightEvidence = $durationSeconds >= self::MINIMUM_FLIGHT_DURATION_SECONDS
            && $engineSampleCount >= self::MINIMUM_ENGINE_SAMPLES
            && $airborneSampleCount >= self::MINIMUM_AIRBORNE_SAMPLES;
        $activityKind = $hasFlightEvidence ? self::FLIGHT : self::POWER_UP;
        $reason = $hasFlightEvidence
            ? 'Sustained engine RPM and sustained flight-speed evidence are present.'
            : $this->powerUpReason($durationSeconds, $engineSampleCount, $airborneSampleCount);

        return array(
            'activity_kind' => $activityKind,
            'duration_seconds' => $durationSeconds,
            'sample_count' => count($rows),
            'engine_sample_count' => $engineSampleCount,
            'airborne_sample_count' => $airborneSampleCount,
            'maximum_rpm' => $maximumRpm,
            'maximum_ground_speed_kt' => $maximumGroundSpeed,
            'maximum_airspeed_kt' => $maximumAirspeed,
            'maximum_position_radius_nm' => $maximumPositionRadius,
            'analysis_reason' => $reason,
            'evidence' => array(
                'thresholds' => array(
                    'engine_on_rpm' => self::ENGINE_ON_RPM,
                    'flight_speed_kt' => self::FLIGHT_SPEED_KT,
                    'minimum_engine_samples' => self::MINIMUM_ENGINE_SAMPLES,
                    'minimum_airborne_samples' => self::MINIMUM_AIRBORNE_SAMPLES,
                    'minimum_flight_duration_seconds' => self::MINIMUM_FLIGHT_DURATION_SECONDS,
                ),
                'gps_position_is_supporting_only' => true,
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
            'INSERT INTO ipca_garmin_sync_file_activity_analyses
              (archive_file_id, activity_kind, duration_seconds, sample_count,
               engine_sample_count, airborne_sample_count, maximum_rpm,
               maximum_ground_speed_kt, maximum_airspeed_kt,
               maximum_position_radius_nm, analysis_reason, evidence_json,
               analyzer_version, analyzed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP(3))
             ON DUPLICATE KEY UPDATE
               activity_kind = VALUES(activity_kind),
               duration_seconds = VALUES(duration_seconds),
               sample_count = VALUES(sample_count),
               engine_sample_count = VALUES(engine_sample_count),
               airborne_sample_count = VALUES(airborne_sample_count),
               maximum_rpm = VALUES(maximum_rpm),
               maximum_ground_speed_kt = VALUES(maximum_ground_speed_kt),
               maximum_airspeed_kt = VALUES(maximum_airspeed_kt),
               maximum_position_radius_nm = VALUES(maximum_position_radius_nm),
               analysis_reason = VALUES(analysis_reason),
               evidence_json = VALUES(evidence_json),
               analyzer_version = VALUES(analyzer_version),
               analyzed_at = VALUES(analyzed_at)'
        );
        $stmt->execute(array(
            $archiveId,
            $analysis['activity_kind'],
            $analysis['duration_seconds'],
            $analysis['sample_count'],
            $analysis['engine_sample_count'],
            $analysis['airborne_sample_count'],
            $analysis['maximum_rpm'],
            $analysis['maximum_ground_speed_kt'],
            $analysis['maximum_airspeed_kt'],
            $analysis['maximum_position_radius_nm'],
            $analysis['analysis_reason'],
            $evidenceJson,
            self::VERSION,
        ));
        $analysis['archive_file_id'] = $archiveId;
        return $analysis;
    }

    private function powerUpReason(
        int $durationSeconds,
        int $engineSampleCount,
        int $airborneSampleCount
    ): string {
        $reasons = array();
        if ($durationSeconds < self::MINIMUM_FLIGHT_DURATION_SECONDS) {
            $reasons[] = 'short recording';
        }
        if ($engineSampleCount < self::MINIMUM_ENGINE_SAMPLES) {
            $reasons[] = 'no sustained engine RPM';
        }
        if ($airborneSampleCount < self::MINIMUM_AIRBORNE_SAMPLES) {
            $reasons[] = 'no sustained flight-speed evidence';
        }
        return 'No actual flight is evidenced: ' . implode(', ', $reasons) . '.';
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
        $lat1 = deg2rad($latitude1);
        $lat2 = deg2rad($latitude2);
        $latDelta = deg2rad($latitude2 - $latitude1);
        $lonDelta = deg2rad($longitude2 - $longitude1);
        $a = sin($latDelta / 2) ** 2
            + cos($lat1) * cos($lat2) * sin($lonDelta / 2) ** 2;
        return 2 * $earthRadiusNm * asin(min(1.0, sqrt($a)));
    }
}
