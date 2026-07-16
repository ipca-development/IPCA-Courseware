<?php
declare(strict_types=1);

require_once __DIR__ . '/G3XFlightStreamParser.php';
require_once __DIR__ . '/HobbsCalculationService.php';
require_once __DIR__ . '/AirportDetectionService.php';
require_once __DIR__ . '/AuditEventService.php';

final class GarminCsvFlightSummaryService
{
    /** @var array<int,array<string,mixed>> */
    private array $cache = array();
    private ?bool $tableReady = null;

    public function __construct(private ?PDO $pdo = null)
    {
    }

    /**
     * @param array<string,mixed> $csvFile
     * @return array<string,mixed>
     */
    public function summaryForCsvFile(array $csvFile): array
    {
        $csvFileId = (int)($csvFile['id'] ?? 0);
        if ($csvFileId > 0 && isset($this->cache[$csvFileId])) {
            return $this->cache[$csvFileId];
        }
        if ($csvFileId > 0 && $this->pdo !== null) {
            $stored = $this->storedSummaryForCsvFileId($csvFileId);
            if ($stored !== null) {
                return $this->remember($csvFileId, $stored);
            }
        }

        return $this->remember($csvFileId, $this->emptySummary($csvFile));
    }

    /**
     * @return array<string,mixed>
     */
    public function deriveAndStore(int $csvFileId): array
    {
        if ($this->pdo === null) {
            throw new RuntimeException('Database connection is required to store Garmin CSV summaries.');
        }
        $csvFile = $this->csvFile($csvFileId);
        if ($csvFile === null) {
            throw new RuntimeException('Garmin CSV file not found.');
        }
        $summary = $this->deriveSummaryForCsvFile($csvFile);
        $this->storeSummary($csvFileId, $summary);
        return $this->remember($csvFileId, $summary);
    }

    /**
     * @return list<int>
     */
    public function missingCsvFileIds(int $limit = 200): array
    {
        if ($this->pdo === null) {
            return array();
        }
        $this->ensureTable();
        $stmt = $this->pdo->prepare("
            SELECT f.id
            FROM ipca_garmin_csv_files f
            LEFT JOIN ipca_garmin_csv_flight_summaries s ON s.csv_file_id = f.id
            WHERE s.csv_file_id IS NULL
            ORDER BY COALESCE(f.first_valid_sample_utc, f.created_at) DESC, f.id DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, max(1, min(1000, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        $ids = array();
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: array() as $id) {
            $ids[] = (int)$id;
        }
        return $ids;
    }

    /**
     * @return array<string,mixed>
     */
    public function deriveMissingNow(int $limit = 50): array
    {
        $ids = $this->missingCsvFileIds($limit);
        $processed = 0;
        $failed = 0;
        $errors = array();
        foreach ($ids as $id) {
            try {
                $this->deriveAndStore($id);
                $processed++;
            } catch (Throwable $e) {
                $failed++;
                $errors[] = 'CSV ' . $id . ': ' . $e->getMessage();
            }
        }
        return array(
            'ok' => true,
            'processed' => $processed,
            'failed' => $failed,
            'remaining_sample' => max(0, count($this->missingCsvFileIds(25))),
            'errors' => array_slice($errors, 0, 10),
        );
    }

    /**
     * @param array<string,mixed> $csvFile
     * @return array<string,mixed>
     */
    private function deriveSummaryForCsvFile(array $csvFile): array
    {
        $csvFileId = (int)($csvFile['id'] ?? 0);

        $summary = $this->emptySummary($csvFile);
        $path = $this->resolveStoragePath((string)($csvFile['storage_path'] ?? ''));
        if ($path === null) {
            $summary['status'] = 'missing_csv_file';
            $summary['exceptions'][] = 'Stored Garmin CSV file is not available on disk.';
            return $summary;
        }

        try {
            $expectedProfile = $this->expectedImportProfile((string)($csvFile['import_profile'] ?? ''));
            $parsed = G3XFlightStreamParser::parseFile($path, $expectedProfile);
            $rows = $parsed['rows'];
            $firstUtc = G3XFlightStreamParser::firstUtcTimestamp($rows);
            $lastUtc = G3XFlightStreamParser::lastUtcTimestamp($rows);
            $airports = (new AirportDetectionService())->detect($rows);
            $hobbs = (new HobbsCalculationService())->calculate($rows, array());

            $summary['status'] = 'ok';
            $summary['tail'] = $this->firstNonBlank(
                (string)($csvFile['aircraft_registration'] ?? ''),
                (string)($csvFile['aircraft_ident'] ?? ''),
                (string)($parsed['aircraft_ident'] ?? '')
            );
            $summary['date_label'] = $this->dateLabel($firstUtc);
            $summary['dep_airport'] = (string)($airports['departure_airport_code'] ?? '') ?: '--';
            $summary['arr_airport'] = (string)($airports['arrival_airport_code'] ?? '') ?: '--';
            $summary['dep_time_lt'] = $this->localTimeLabel($firstUtc);
            $summary['arr_time_lt'] = $this->localTimeLabel($lastUtc);
            $summary['elapsed_time'] = $this->elapsedTimeLabel($firstUtc, $lastUtc);
            $summary['start_utc'] = $firstUtc?->format('Y-m-d H:i:s.v');
            $summary['end_utc'] = $lastUtc?->format('Y-m-d H:i:s.v');
            $summary['row_count'] = (int)($parsed['row_count'] ?? 0);
            $summary['hobbs_start_utc'] = (string)($hobbs['start_utc'] ?? '');
            $summary['hobbs_end_utc'] = (string)($hobbs['end_utc'] ?? '');
            $summary['hobbs_start_lt'] = $this->localTimeLabel($this->dateTimeOrNull($summary['hobbs_start_utc']));
            $summary['hobbs_end_lt'] = $this->localTimeLabel($this->dateTimeOrNull($summary['hobbs_end_utc']));
            $summary['hobbs_hours'] = is_numeric($hobbs['duration_hours_display'] ?? null) ? number_format((float)$hobbs['duration_hours_display'], 1) . ' h' : '--';
            $summary['hobbs_status'] = (string)($hobbs['status'] ?? '');
            $summary['hobbs_calculation_version'] = (string)($hobbs['calculation_version'] ?? HobbsCalculationService::VERSION);
            $summary['label'] = $this->label($summary);
        } catch (Throwable $e) {
            $summary['status'] = 'parse_failed';
            $summary['exceptions'][] = $e->getMessage();
        }

        return $summary;
    }

    /**
     * @param array<string,mixed> $csvFile
     * @return array<string,mixed>
     */
    private function emptySummary(array $csvFile): array
    {
        $tail = $this->firstNonBlank((string)($csvFile['aircraft_registration'] ?? ''), (string)($csvFile['aircraft_ident'] ?? ''));
        $firstUtc = $this->dateTimeOrNull((string)($csvFile['first_valid_sample_utc'] ?? ''));
        $lastUtc = $this->dateTimeOrNull((string)($csvFile['last_valid_sample_utc'] ?? ''));
        return array(
            'status' => 'not_analyzed',
            'tail' => $tail !== '' ? $tail : 'Unknown tail',
            'date_label' => $this->dateLabel($firstUtc),
            'dep_airport' => '--',
            'arr_airport' => '--',
            'dep_time_lt' => $this->localTimeLabel($firstUtc),
            'arr_time_lt' => $this->localTimeLabel($lastUtc),
            'elapsed_time' => $this->elapsedTimeLabel($firstUtc, $lastUtc),
            'start_utc' => $firstUtc?->format('Y-m-d H:i:s.v'),
            'end_utc' => $lastUtc?->format('Y-m-d H:i:s.v'),
            'row_count' => (int)($csvFile['valid_row_count'] ?? 0),
            'hobbs_start_utc' => '',
            'hobbs_end_utc' => '',
            'hobbs_start_lt' => '--',
            'hobbs_end_lt' => '--',
            'hobbs_hours' => '--',
            'hobbs_status' => '',
            'hobbs_calculation_version' => HobbsCalculationService::VERSION,
            'label' => '',
            'exceptions' => array(),
        );
    }

    /**
     * @param array<string,mixed> $summary
     */
    private function label(array $summary): string
    {
        return sprintf(
            '%s - %s %s %s LT - %s %s LT (%s)',
            (string)$summary['date_label'],
            (string)$summary['tail'],
            (string)$summary['dep_airport'],
            (string)$summary['dep_time_lt'],
            (string)$summary['arr_airport'],
            (string)$summary['arr_time_lt'],
            (string)$summary['elapsed_time']
        );
    }

    private function resolveStoragePath(string $storagePath): ?string
    {
        $storagePath = trim($storagePath);
        if ($storagePath === '') {
            return null;
        }
        $candidates = array();
        if (str_starts_with($storagePath, '/')) {
            $candidates[] = $storagePath;
        } else {
            $candidates[] = dirname(__DIR__) . '/' . ltrim($storagePath, '/');
            $candidates[] = dirname(__DIR__) . '/storage/cvr/' . ltrim($storagePath, '/');
        }
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    private function expectedImportProfile(string $value): ?string
    {
        $value = strtolower(trim($value));
        return in_array($value, array('garmin_g3x', 'garmin_g1000nxi'), true) ? $value : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function csvFile(int $csvFileId): ?array
    {
        if ($this->pdo === null) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_garmin_csv_files WHERE id = ? LIMIT 1');
        $stmt->execute(array($csvFileId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function storedSummaryForCsvFileId(int $csvFileId): ?array
    {
        if ($this->pdo === null || !$this->summaryTableReady()) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_garmin_csv_flight_summaries WHERE csv_file_id = ? LIMIT 1');
        $stmt->execute(array($csvFileId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $exceptions = json_decode((string)($row['exception_json'] ?? '[]'), true);
        return array(
            'status' => (string)($row['derivation_status'] ?? 'stored'),
            'tail' => (string)($row['tail_number'] ?? 'Unknown tail'),
            'date_label' => $this->dateLabel($this->dateTimeOrNull((string)($row['departure_time_utc'] ?? ''))),
            'dep_airport' => (string)($row['departure_airport_code'] ?? '') ?: '--',
            'arr_airport' => (string)($row['arrival_airport_code'] ?? '') ?: '--',
            'dep_time_lt' => $this->localTimeLabel($this->dateTimeOrNull((string)($row['departure_time_utc'] ?? ''))),
            'arr_time_lt' => $this->localTimeLabel($this->dateTimeOrNull((string)($row['arrival_time_utc'] ?? ''))),
            'elapsed_time' => is_numeric($row['elapsed_seconds'] ?? null) ? number_format(((float)$row['elapsed_seconds']) / 3600, 1) . ' h' : '--',
            'start_utc' => (string)($row['departure_time_utc'] ?? ''),
            'end_utc' => (string)($row['arrival_time_utc'] ?? ''),
            'row_count' => (int)($row['row_count'] ?? 0),
            'hobbs_start_utc' => (string)($row['hobbs_start_utc'] ?? ''),
            'hobbs_end_utc' => (string)($row['hobbs_end_utc'] ?? ''),
            'hobbs_start_lt' => $this->localTimeLabel($this->dateTimeOrNull((string)($row['hobbs_start_utc'] ?? ''))),
            'hobbs_end_lt' => $this->localTimeLabel($this->dateTimeOrNull((string)($row['hobbs_end_utc'] ?? ''))),
            'hobbs_hours' => is_numeric($row['hobbs_duration_seconds'] ?? null) ? number_format(((float)$row['hobbs_duration_seconds']) / 3600, 1) . ' h' : '--',
            'hobbs_status' => (string)($row['hobbs_status'] ?? ''),
            'hobbs_calculation_version' => (string)($row['calculation_version'] ?? HobbsCalculationService::VERSION),
            'label' => (string)($row['display_label'] ?? ''),
            'exceptions' => is_array($exceptions) ? $exceptions : array(),
        );
    }

    /**
     * @param array<string,mixed> $summary
     */
    private function storeSummary(int $csvFileId, array $summary): void
    {
        if ($this->pdo === null) {
            return;
        }
        $this->ensureTable();
        $start = $this->dateTimeOrNull((string)($summary['start_utc'] ?? ''));
        $end = $this->dateTimeOrNull((string)($summary['end_utc'] ?? ''));
        $hobbsStart = $this->dateTimeOrNull((string)($summary['hobbs_start_utc'] ?? ''));
        $hobbsEnd = $this->dateTimeOrNull((string)($summary['hobbs_end_utc'] ?? ''));
        $elapsed = ($start !== null && $end !== null && $end > $start) ? max(0, (int)round((float)$end->format('U.u') - (float)$start->format('U.u'))) : null;
        $hobbsElapsed = ($hobbsStart !== null && $hobbsEnd !== null && $hobbsEnd > $hobbsStart) ? max(0, (int)round((float)$hobbsEnd->format('U.u') - (float)$hobbsStart->format('U.u'))) : null;
        $displayLabel = trim((string)($summary['label'] ?? ''));
        if ($displayLabel === '') {
            $displayLabel = $this->label($summary);
            $summary['label'] = $displayLabel;
        }
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_garmin_csv_flight_summaries
              (csv_file_id, derivation_status, tail_number, departure_airport_code, arrival_airport_code,
               departure_time_utc, arrival_time_utc, elapsed_seconds, hobbs_start_utc, hobbs_end_utc,
               hobbs_duration_seconds, hobbs_status, row_count, calculation_version, display_label, summary_json,
               exception_json, derived_at)
            VALUES
              (:csv_file_id, :derivation_status, :tail_number, :departure_airport_code, :arrival_airport_code,
               :departure_time_utc, :arrival_time_utc, :elapsed_seconds, :hobbs_start_utc, :hobbs_end_utc,
               :hobbs_duration_seconds, :hobbs_status, :row_count, :calculation_version, :display_label, :summary_json,
               :exception_json, CURRENT_TIMESTAMP(3))
            ON DUPLICATE KEY UPDATE
              derivation_status = VALUES(derivation_status),
              tail_number = VALUES(tail_number),
              departure_airport_code = VALUES(departure_airport_code),
              arrival_airport_code = VALUES(arrival_airport_code),
              departure_time_utc = VALUES(departure_time_utc),
              arrival_time_utc = VALUES(arrival_time_utc),
              elapsed_seconds = VALUES(elapsed_seconds),
              hobbs_start_utc = VALUES(hobbs_start_utc),
              hobbs_end_utc = VALUES(hobbs_end_utc),
              hobbs_duration_seconds = VALUES(hobbs_duration_seconds),
              hobbs_status = VALUES(hobbs_status),
              row_count = VALUES(row_count),
              calculation_version = VALUES(calculation_version),
              display_label = VALUES(display_label),
              summary_json = VALUES(summary_json),
              exception_json = VALUES(exception_json),
              derived_at = CURRENT_TIMESTAMP(3),
              updated_at = CURRENT_TIMESTAMP(3)
        ");
        $stmt->execute(array(
            ':csv_file_id' => $csvFileId,
            ':derivation_status' => (string)($summary['status'] ?? 'unknown'),
            ':tail_number' => (string)($summary['tail'] ?? ''),
            ':departure_airport_code' => (string)($summary['dep_airport'] ?? ''),
            ':arrival_airport_code' => (string)($summary['arr_airport'] ?? ''),
            ':departure_time_utc' => $start?->format('Y-m-d H:i:s.v'),
            ':arrival_time_utc' => $end?->format('Y-m-d H:i:s.v'),
            ':elapsed_seconds' => $elapsed,
            ':hobbs_start_utc' => $hobbsStart?->format('Y-m-d H:i:s.v'),
            ':hobbs_end_utc' => $hobbsEnd?->format('Y-m-d H:i:s.v'),
            ':hobbs_duration_seconds' => $hobbsElapsed,
            ':hobbs_status' => (string)($summary['hobbs_status'] ?? ''),
            ':row_count' => (int)($summary['row_count'] ?? 0),
            ':calculation_version' => (string)($summary['hobbs_calculation_version'] ?? HobbsCalculationService::VERSION),
            ':display_label' => $displayLabel,
            ':summary_json' => AuditEventService::jsonEncode($summary),
            ':exception_json' => AuditEventService::jsonEncode($summary['exceptions'] ?? array()),
        ));
    }

    private function summaryTableReady(): bool
    {
        if ($this->tableReady !== null) {
            return $this->tableReady;
        }
        if ($this->pdo === null) {
            return $this->tableReady = false;
        }
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute(array('ipca_garmin_csv_flight_summaries'));
        return $this->tableReady = (int)$stmt->fetchColumn() > 0;
    }

    private function ensureTable(): void
    {
        if ($this->pdo === null || $this->summaryTableReady()) {
            return;
        }
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS ipca_garmin_csv_flight_summaries (
              csv_file_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
              derivation_status VARCHAR(32) NOT NULL DEFAULT 'pending',
              tail_number VARCHAR(32) NOT NULL DEFAULT '',
              departure_airport_code VARCHAR(16) NULL,
              arrival_airport_code VARCHAR(16) NULL,
              departure_time_utc DATETIME(3) NULL,
              arrival_time_utc DATETIME(3) NULL,
              elapsed_seconds INT UNSIGNED NULL,
              hobbs_start_utc DATETIME(3) NULL,
              hobbs_end_utc DATETIME(3) NULL,
              hobbs_duration_seconds INT UNSIGNED NULL,
              hobbs_status VARCHAR(32) NOT NULL DEFAULT '',
              row_count INT UNSIGNED NOT NULL DEFAULT 0,
              calculation_version VARCHAR(64) NOT NULL DEFAULT '',
              display_label VARCHAR(255) NOT NULL DEFAULT '',
              summary_json JSON NULL,
              exception_json JSON NULL,
              derived_at DATETIME(3) NULL,
              created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
              updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
              KEY idx_garmin_csv_summary_departure (departure_time_utc),
              KEY idx_garmin_csv_summary_tail (tail_number)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $this->tableReady = true;
    }

    /**
     * @param array<string,mixed> $summary
     * @return array<string,mixed>
     */
    private function remember(int $csvFileId, array $summary): array
    {
        if ($summary['label'] === '') {
            $summary['label'] = $this->label($summary);
        }
        if ($csvFileId > 0) {
            $this->cache[$csvFileId] = $summary;
        }
        return $summary;
    }

    private function dateTimeOrNull(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    private function dateLabel(?DateTimeImmutable $value): string
    {
        return $value !== null ? $value->setTimezone($this->displayTimeZone())->format('D M j, Y') : '--';
    }

    private function localTimeLabel(?DateTimeImmutable $value): string
    {
        return $value !== null ? $value->setTimezone($this->displayTimeZone())->format('H:i') : '--';
    }

    private function elapsedTimeLabel(?DateTimeImmutable $start, ?DateTimeImmutable $end): string
    {
        if ($start === null || $end === null || $end <= $start) {
            return '--';
        }
        $hours = ((float)$end->format('U.u') - (float)$start->format('U.u')) / 3600;
        return number_format($hours, 1) . ' h';
    }

    private function displayTimeZone(): DateTimeZone
    {
        return new DateTimeZone(date_default_timezone_get() ?: 'UTC');
    }

    private function firstNonBlank(string ...$values): string
    {
        foreach ($values as $value) {
            $value = trim($value);
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }
}
