<?php
declare(strict_types=1);

require_once __DIR__ . '/G3XFlightStreamParser.php';
require_once __DIR__ . '/HobbsCalculationService.php';
require_once __DIR__ . '/AirportDetectionService.php';

final class GarminCsvFlightSummaryService
{
    /** @var array<int,array<string,mixed>> */
    private array $cache = array();

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

        $summary = $this->emptySummary($csvFile);
        $path = $this->resolveStoragePath((string)($csvFile['storage_path'] ?? ''));
        if ($path === null) {
            $summary['status'] = 'missing_csv_file';
            $summary['exceptions'][] = 'Stored Garmin CSV file is not available on disk.';
            return $this->remember($csvFileId, $summary);
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

        return $this->remember($csvFileId, $summary);
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
