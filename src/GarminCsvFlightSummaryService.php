<?php
declare(strict_types=1);

require_once __DIR__ . '/G3XFlightStreamParser.php';
require_once __DIR__ . '/HobbsCalculationService.php';
require_once __DIR__ . '/TachoCalculationService.php';
require_once __DIR__ . '/AirportDetectionService.php';
require_once __DIR__ . '/AircraftOperationalConfigService.php';
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
               OR JSON_EXTRACT(s.summary_json, '$.hobbs_exact') IS NULL
               OR JSON_EXTRACT(s.summary_json, '$.tacho_exact') IS NULL
               OR JSON_EXTRACT(s.summary_json, '$.hobbs_in') IS NULL
               OR JSON_EXTRACT(s.summary_json, '$.tacho_in') IS NULL
               OR CAST(JSON_UNQUOTE(JSON_EXTRACT(s.summary_json, '$.hobbs_exact.counter_start_exact')) AS DECIMAL(12,4)) < 0
               OR CAST(JSON_UNQUOTE(JSON_EXTRACT(s.summary_json, '$.tacho_exact.counter_start_exact')) AS DECIMAL(12,4)) < 0
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
            $meta = is_array($parsed['metadata'] ?? null) ? $parsed['metadata'] : $this->metaFromFirstLine($path);
            $rows = $parsed['rows'];
            $firstUtc = G3XFlightStreamParser::firstUtcTimestamp($rows);
            $lastUtc = G3XFlightStreamParser::lastUtcTimestamp($rows);
            $airports = (new AirportDetectionService())->detect($rows);
            $systemId = $this->firstNonBlank(
                (string)($csvFile['system_identifier'] ?? ''),
                (string)($meta['system_id'] ?? ''),
                (string)($meta['system_identifier'] ?? '')
            );
            $tail = $this->firstTailNumber(
                (string)($csvFile['aircraft_registration'] ?? ''),
                (string)($csvFile['aircraft_ident'] ?? ''),
                (string)($parsed['aircraft_ident'] ?? ''),
                (string)($meta['aircraft_ident'] ?? ''),
                $this->tailForSystemIdentifier($systemId)
            );
            $systemMapping = $this->systemIdentifierMapping($systemId);
            $config = $this->calculationConfigForTail($tail);
            $hobbs = (new HobbsCalculationService())->calculate($rows, $config);
            $tacho = (new TachoCalculationService())->calculate($rows, $config);
            $hobbsCounterStart = $this->numericOrNull($csvFile['airframe_hours_start'] ?? null) ?? $this->numericOrNull($meta['airframe_hours'] ?? null);
            $tachoCounterStart = $this->numericOrNull($csvFile['engine_hours_start'] ?? null) ?? $this->numericOrNull($meta['engine_hours'] ?? null);

            $summary['status'] = 'ok';
            $summary['tail'] = $tail !== '' ? $tail : 'Unknown tail';
            $summary['date_label'] = $this->dateLabel($firstUtc);
            $summary['dep_airport'] = (string)($airports['departure_airport_code'] ?? '') ?: '--';
            $summary['arr_airport'] = (string)($airports['arrival_airport_code'] ?? '') ?: '--';
            $summary['dep_time_lt'] = $this->localTimeLabel($firstUtc);
            $summary['arr_time_lt'] = $this->localTimeLabel($lastUtc);
            $summary['elapsed_time'] = $this->elapsedTimeLabel($firstUtc, $lastUtc);
            $summary['start_utc'] = $firstUtc?->format('Y-m-d H:i:s.v');
            $summary['end_utc'] = $lastUtc?->format('Y-m-d H:i:s.v');
            $summary['row_count'] = (int)($parsed['row_count'] ?? 0);
            $summary['hobbs_out'] = $this->counterDisplay($hobbsCounterStart);
            $summary['tacho_out'] = $this->counterDisplay($tachoCounterStart);
            $summary['system_id'] = $systemId;
            $summary['aircraft_ident_raw'] = (string)($meta['aircraft_ident'] ?? '');
            $summary['avionics_family'] = (string)($systemMapping['avionics_family'] ?? '');
            $summary['default_quality'] = (string)($systemMapping['default_quality'] ?? '');
            $summary['provides_full_avionics'] = !empty($systemMapping['provides_full_avionics']);
            $summary['provides_counter_headers'] = !empty($systemMapping['provides_counter_headers']);
            $summary['hobbs_start_utc'] = (string)($hobbs['start_utc'] ?? '');
            $summary['hobbs_end_utc'] = (string)($hobbs['end_utc'] ?? '');
            $summary['hobbs_start_lt'] = $this->localTimeLabel($this->dateTimeOrNull($summary['hobbs_start_utc']));
            $summary['hobbs_end_lt'] = $this->localTimeLabel($this->dateTimeOrNull($summary['hobbs_end_utc']));
            $summary['hobbs_hours'] = is_numeric($hobbs['duration_hours_display'] ?? null) ? number_format((float)$hobbs['duration_hours_display'], 1) . ' h' : '--';
            $summary['hobbs_status'] = (string)($hobbs['status'] ?? '');
            $summary['hobbs_calculation_version'] = (string)($hobbs['calculation_version'] ?? HobbsCalculationService::VERSION);
            $summary['hobbs_exact'] = $this->counterCalculationSummary($hobbsCounterStart, $hobbs);
            $summary['tacho_exact'] = $this->counterCalculationSummary($tachoCounterStart, $tacho);
            $summary['hobbs_in'] = (string)($summary['hobbs_exact']['counter_end_display'] ?? '--');
            $summary['tacho_in'] = (string)($summary['tacho_exact']['counter_end_display'] ?? '--');
            $summary['hobbs_time'] = $this->durationDisplay($summary['hobbs_exact'] ?? array());
            $summary['tacho_time'] = $this->durationDisplay($summary['tacho_exact'] ?? array());
            $summary['tail_source'] = $this->tailSource($tail, $csvFile, $parsed, $meta, $systemId);
            $summary['tacho_status'] = (string)($tacho['status'] ?? '');
            $summary['tacho_calculation_version'] = (string)($tacho['calculation_version'] ?? TachoCalculationService::VERSION);
            $summary['calculation_config'] = array(
                'hobbs_engine_on_rpm_threshold' => $config['hobbs_engine_on_rpm_threshold'],
                'hobbs_start_confirm_ms' => $config['hobbs_start_confirm_ms'],
                'hobbs_stop_confirm_ms' => $config['hobbs_stop_confirm_ms'],
                'tacho_rpm_threshold' => $config['tacho_rpm_threshold'],
            );
            $summary['exceptions'] = array_values(array_merge(
                $summary['exceptions'] ?? array(),
                $this->calculationExceptions('hobbs', $hobbs),
                $this->calculationExceptions('tacho', $tacho)
            ));
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
        $tail = $this->firstTailNumber((string)($csvFile['aircraft_registration'] ?? ''), (string)($csvFile['aircraft_ident'] ?? ''));
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
            'hobbs_out' => '--',
            'tacho_out' => '--',
            'hobbs_in' => '--',
            'tacho_in' => '--',
            'hobbs_time' => '--',
            'tacho_time' => '--',
            'system_id' => '',
            'aircraft_ident_raw' => '',
            'avionics_family' => '',
            'default_quality' => '',
            'provides_full_avionics' => false,
            'provides_counter_headers' => false,
            'tail_source' => '',
            'hobbs_start_utc' => '',
            'hobbs_end_utc' => '',
            'hobbs_start_lt' => '--',
            'hobbs_end_lt' => '--',
            'hobbs_hours' => '--',
            'hobbs_status' => '',
            'hobbs_calculation_version' => HobbsCalculationService::VERSION,
            'tacho_status' => '',
            'tacho_calculation_version' => TachoCalculationService::VERSION,
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

    /**
     * @return array<string,mixed>
     */
    private function metaFromFirstLine(string $path): array
    {
        $line = '';
        $handle = fopen($path, 'rb');
        if ($handle !== false) {
            $line = (string)fgets($handle);
            fclose($handle);
        }
        $meta = array();
        foreach (str_getcsv($line) as $part) {
            if (!is_string($part) || !str_contains($part, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $part, 2);
            $key = trim($key);
            $value = trim(trim($value), '"');
            if ($key !== '') {
                $meta[$key] = is_numeric($value) ? (float)$value : $value;
            }
        }
        return $meta;
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
        $stored = json_decode((string)($row['summary_json'] ?? '{}'), true);
        $summary = array(
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
        return is_array($stored) ? array_merge($summary, array_intersect_key($stored, array_flip(array('hobbs_out', 'hobbs_in', 'hobbs_time', 'tacho_out', 'tacho_in', 'tacho_time', 'hobbs_exact', 'tacho_exact', 'tacho_status', 'tacho_calculation_version', 'system_id', 'aircraft_ident_raw', 'avionics_family', 'default_quality', 'provides_full_avionics', 'provides_counter_headers', 'tail_source', 'calculation_config')))) : $summary;
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
        $timezone = date_default_timezone_get();
        if ($timezone === '' || strtoupper($timezone) === 'UTC') {
            $timezone = 'America/Los_Angeles';
        }
        return new DateTimeZone($timezone);
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

    private function firstTailNumber(string ...$values): string
    {
        foreach ($values as $value) {
            $value = strtoupper(trim($value));
            if ($this->looksLikeTailNumber($value)) {
                return $value;
            }
        }
        return '';
    }

    private function looksLikeTailNumber(string $value): bool
    {
        return preg_match('/^N[0-9A-Z]{2,6}$/', strtoupper(trim($value))) === 1;
    }

    /**
     * @return array<string,mixed>
     */
    private function calculationConfigForTail(string $tail): array
    {
        $tail = strtoupper(trim($tail));
        $config = array(
            'hobbs_engine_on_rpm_threshold' => 1000.0,
            'hobbs_start_confirm_ms' => 1000,
            'hobbs_stop_confirm_ms' => 5000,
            'tacho_rpm_threshold' => null,
        );
        $aircraftId = $this->aircraftIdForTail($tail);
        if ($aircraftId !== null && $this->pdo !== null) {
            $stored = (new AircraftOperationalConfigService($this->pdo))->configForAircraft($aircraftId);
            $config = array_merge($config, array_intersect_key($stored, $config));
        }
        if ($config['tacho_rpm_threshold'] === null && in_array($tail, array('N392EA', 'N397EA', 'N428EA'), true)) {
            $config['tacho_rpm_threshold'] = 4000.0;
        }
        return $config;
    }

    private function aircraftIdForTail(string $tail): ?int
    {
        if ($this->pdo === null || $tail === '' || !$this->tableExists('ipca_aircraft_devices')) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT id FROM ipca_aircraft_devices WHERE UPPER(registration) = ? LIMIT 1');
        $stmt->execute(array($tail));
        $id = $stmt->fetchColumn();
        return is_numeric($id) ? (int)$id : null;
    }

    private function tableExists(string $table): bool
    {
        if ($this->pdo === null) {
            return false;
        }
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute(array($table));
        return (int)$stmt->fetchColumn() > 0;
    }

    private function numericOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float)$value : null;
    }

    private function counterDisplay(?float $value): string
    {
        return $value !== null ? number_format($value, 1, '.', '') : '--';
    }

    /**
     * @param array<string,mixed> $counter
     */
    private function durationDisplay(array $counter): string
    {
        return is_numeric($counter['duration_hours_display'] ?? null)
            ? number_format((float)$counter['duration_hours_display'], 1, '.', '')
            : '--';
    }

    /**
     * @param array<string,mixed> $calculation
     * @return array<string,mixed>
     */
    private function counterCalculationSummary(?float $startCounter, array $calculation): array
    {
        $duration = $this->numericOrNull($calculation['duration_hours_exact'] ?? null);
        $end = $startCounter !== null && $duration !== null ? $startCounter + $duration : null;
        return array(
            'counter_start_exact' => $startCounter,
            'counter_start_display' => $this->counterDisplay($startCounter),
            'duration_hours_exact' => $duration,
            'duration_hours_display' => $duration !== null ? round($duration, 1) : null,
            'counter_end_exact' => $end,
            'counter_end_display' => $this->counterDisplay($end),
            'start_utc' => $calculation['start_utc'] ?? null,
            'end_utc' => $calculation['end_utc'] ?? null,
            'method' => $calculation['method'] ?? '',
            'threshold_rpm' => $calculation['threshold_rpm'] ?? null,
            'calculation_version' => $calculation['calculation_version'] ?? '',
            'verification_status' => $calculation['verification_status'] ?? '',
            'uncertainty_ms' => $calculation['uncertainty_ms'] ?? null,
        );
    }

    /**
     * @param array<string,mixed> $calculation
     * @return list<string>
     */
    private function calculationExceptions(string $label, array $calculation): array
    {
        $exceptions = array();
        foreach (($calculation['exceptions'] ?? array()) as $exception) {
            $exceptions[] = strtoupper($label) . ': ' . (string)$exception;
        }
        return $exceptions;
    }

    private function tailForSystemIdentifier(string $systemIdentifier): string
    {
        $mapping = $this->systemIdentifierMapping($systemIdentifier);
        return trim((string)($mapping['tail_number'] ?? ''));
    }

    /**
     * @return array<string,mixed>
     */
    private function systemIdentifierMapping(string $systemIdentifier): array
    {
        $systemIdentifier = strtoupper(trim($systemIdentifier));
        if ($this->pdo === null || $systemIdentifier === '' || !$this->tableExists('ipca_garmin_system_identifier_mappings')) {
            return array();
        }
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM ipca_garmin_system_identifier_mappings
            WHERE system_identifier = ?
              AND (effective_to_utc IS NULL OR effective_to_utc > CURRENT_TIMESTAMP(3))
            ORDER BY effective_from_utc DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute(array($systemIdentifier));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : array();
    }

    /**
     * @param array<string,mixed> $csvFile
     * @param array<string,mixed> $parsed
     * @param array<string,mixed> $meta
     */
    private function tailSource(string $tail, array $csvFile, array $parsed, array $meta, string $systemId): string
    {
        if ($tail === '' || stripos($tail, 'unknown') !== false) {
            return 'unresolved';
        }
        if ($this->looksLikeTailNumber((string)($csvFile['aircraft_registration'] ?? '')) && strtoupper(trim((string)($csvFile['aircraft_registration'] ?? ''))) === $tail) {
            return 'csv_file_aircraft_registration';
        }
        if ($this->looksLikeTailNumber((string)($csvFile['aircraft_ident'] ?? '')) && strtoupper(trim((string)($csvFile['aircraft_ident'] ?? ''))) === $tail) {
            return 'csv_file_aircraft_ident';
        }
        if (($this->looksLikeTailNumber((string)($parsed['aircraft_ident'] ?? '')) && strtoupper(trim((string)($parsed['aircraft_ident'] ?? ''))) === $tail)
            || ($this->looksLikeTailNumber((string)($meta['aircraft_ident'] ?? '')) && strtoupper(trim((string)($meta['aircraft_ident'] ?? ''))) === $tail)) {
            return 'g3x_aircraft_ident';
        }
        if ($systemId !== '' && $this->tailForSystemIdentifier($systemId) === $tail) {
            return 'system_identifier_mapping';
        }
        return 'derived';
    }
}
