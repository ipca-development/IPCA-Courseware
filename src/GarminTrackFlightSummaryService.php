<?php
declare(strict_types=1);

require_once __DIR__ . '/AirportDetectionService.php';
require_once __DIR__ . '/AuditEventService.php';
require_once __DIR__ . '/G3XFlightStreamParser.php';
require_once __DIR__ . '/HobbsCalculationService.php';
require_once __DIR__ . '/TachoCalculationService.php';
require_once __DIR__ . '/AircraftOperationalConfigService.php';

final class GarminTrackFlightSummaryService
{
    /** @var array<int,array<string,mixed>> */
    private array $cache = array();
    private ?bool $tableReady = null;

    public function __construct(private ?PDO $pdo = null)
    {
    }

    /**
     * @param array<string,mixed> $track
     * @return array<string,mixed>
     */
    public function summaryForTrackArtifact(array $track): array
    {
        $trackId = (int)($track['id'] ?? 0);
        if ($trackId > 0 && isset($this->cache[$trackId])) {
            return $this->cache[$trackId];
        }
        if ($trackId > 0 && $this->pdo !== null) {
            $stored = $this->storedSummaryForTrackId($trackId);
            if ($stored !== null) {
                return $this->remember($trackId, $stored);
            }
        }
        return $this->remember($trackId, $this->emptySummary($track));
    }

    /**
     * @return array<string,mixed>
     */
    public function deriveAndStore(int $trackArtifactId): array
    {
        if ($this->pdo === null) {
            throw new RuntimeException('Database connection is required to store Garmin track summaries.');
        }
        $track = $this->trackArtifact($trackArtifactId);
        if ($track === null) {
            throw new RuntimeException('Garmin normalized track artifact not found.');
        }
        $summary = $this->deriveSummaryForTrack($track);
        $this->storeSummary($trackArtifactId, $summary);
        return $this->remember($trackArtifactId, $summary);
    }

    /**
     * @return list<int>
     */
    public function missingTrackArtifactIds(int $limit = 200): array
    {
        if ($this->pdo === null) {
            return array();
        }
        $this->ensureTable();
        $stmt = $this->pdo->prepare("
            SELECT t.id
            FROM ipca_garmin_normalized_track_artifacts t
            LEFT JOIN ipca_garmin_track_flight_summaries s ON s.track_artifact_id = t.id
            WHERE (
                s.track_artifact_id IS NULL
                OR JSON_EXTRACT(s.summary_json, '$.hobbs_exact') IS NULL
                OR JSON_EXTRACT(s.summary_json, '$.tacho_exact') IS NULL
                OR CAST(JSON_UNQUOTE(JSON_EXTRACT(s.summary_json, '$.hobbs_exact.counter_start_exact')) AS DECIMAL(12,4)) < 0
                OR CAST(JSON_UNQUOTE(JSON_EXTRACT(s.summary_json, '$.tacho_exact.counter_start_exact')) AS DECIMAL(12,4)) < 0
                OR (
                    s.tail_number IN ('', 'Unknown tail', 'Unknown')
                    AND JSON_SEARCH(t.source_descriptors_json, 'one', 'Flight Data Log System ID:%') IS NOT NULL
                )
              )
              AND t.artifact_type = 'GARMIN_TRACK_NORMALIZED_JSON'
              AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(t.raw_metadata_json, '$.trackClassification')), '') <> 'GARMIN_GPS_ONLY'
            ORDER BY t.last_seen_at DESC, t.id DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, max(1, min(1000, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: array());
    }

    /**
     * @return array<string,mixed>
     */
    public function deriveMissingNow(int $limit = 50): array
    {
        $ids = $this->missingTrackArtifactIds($limit);
        $processed = 0;
        $failed = 0;
        $errors = array();
        foreach ($ids as $id) {
            try {
                $this->deriveAndStore($id);
                $processed++;
            } catch (Throwable $e) {
                $failed++;
                $errors[] = 'Track artifact ' . $id . ': ' . $e->getMessage();
            }
        }
        return array(
            'ok' => true,
            'processed' => $processed,
            'failed' => $failed,
            'remaining_sample' => count($this->missingTrackArtifactIds(25)),
            'errors' => array_slice($errors, 0, 10),
        );
    }

    /**
     * @param array<string,mixed> $track
     * @return array<string,mixed>
     */
    private function deriveSummaryForTrack(array $track): array
    {
        $summary = $this->emptySummary($track);
        $path = $this->resolveStoragePath((string)($track['storage_path'] ?? ''));
        if ($path === null) {
            $summary['status'] = 'missing_track_json';
            $summary['exceptions'][] = 'Stored Garmin normalized track JSON is not available on disk.';
            return $summary;
        }
        $decoded = json_decode((string)file_get_contents($path), true);
        if (!is_array($decoded)) {
            $summary['status'] = 'parse_failed';
            $summary['exceptions'][] = 'Stored Garmin normalized track JSON could not be decoded.';
            return $summary;
        }

        $rows = $this->pseudoCsvRows($decoded);
        if (!$rows) {
            $summary['status'] = 'no_usable_rows';
            $summary['exceptions'][] = 'No normalized rows with Garmin timestamps were found.';
            return $summary;
        }

        $firstUtc = G3XFlightStreamParser::firstUtcTimestamp($rows);
        $lastUtc = G3XFlightStreamParser::lastUtcTimestamp($rows);
        $airports = (new AirportDetectionService())->detect($rows);
        $tail = $this->tailFromTrack($track);
        $config = $this->calculationConfigForTail($tail);
        $hobbs = (new HobbsCalculationService())->calculate($rows, $config);
        $tacho = (new TachoCalculationService())->calculate($rows, $config);
        $hobbsCounterStart = $this->counterStartFromTrack($track, 'airframe');
        $tachoCounterStart = $this->counterStartFromTrack($track, 'engine');
        $summary['status'] = 'ok';
        $summary['tail'] = $tail;
        $summary['date_label'] = $this->dateLabel($firstUtc);
        $summary['dep_airport'] = (string)($airports['departure_airport_code'] ?? '') ?: '--';
        $summary['arr_airport'] = (string)($airports['arrival_airport_code'] ?? '') ?: '--';
        $summary['dep_time_lt'] = $this->localTimeLabel($firstUtc);
        $summary['arr_time_lt'] = $this->localTimeLabel($lastUtc);
        $summary['elapsed_time'] = $this->elapsedTimeLabel($firstUtc, $lastUtc);
        $summary['start_utc'] = $firstUtc?->format('Y-m-d H:i:s.v');
        $summary['end_utc'] = $lastUtc?->format('Y-m-d H:i:s.v');
        $summary['row_count'] = count($rows);
        $summary['hobbs_out'] = $this->counterDisplay($hobbsCounterStart);
        $summary['tacho_out'] = $this->counterDisplay($tachoCounterStart);
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
        $summary['system_id'] = $this->systemIdentifierFromTrack($track);
        $systemMapping = $this->systemIdentifierMapping((string)$summary['system_id']);
        $summary['avionics_family'] = (string)($systemMapping['avionics_family'] ?? '');
        $summary['default_quality'] = (string)($systemMapping['default_quality'] ?? '');
        $summary['provides_full_avionics'] = !empty($systemMapping['provides_full_avionics']);
        $summary['provides_counter_headers'] = !empty($systemMapping['provides_counter_headers']);
        $summary['tail_source'] = $this->tailSource($tail, $track, $summary['system_id']);
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
        return $summary;
    }

    /**
     * @param array<string,mixed> $decoded
     * @return list<array<string,string>>
     */
    private function pseudoCsvRows(array $decoded): array
    {
        $rows = array();
        $sessions = isset($decoded['sessions']) && is_array($decoded['sessions']) ? $decoded['sessions'] : array();
        foreach ($sessions as $session) {
            if (!is_array($session)) {
                continue;
            }
            $fields = isset($session['fields']) && is_array($session['fields']) ? $session['fields'] : array();
            $dataRows = isset($session['data']) && is_array($session['data']) ? $session['data'] : array();
            $indexes = $this->fieldIndexes($fields);
            if (!isset($indexes['time'])) {
                continue;
            }
            foreach ($dataRows as $dataRow) {
                if (!is_array($dataRow)) {
                    continue;
                }
                $time = $this->timestampFromValue($dataRow[$indexes['time']] ?? null);
                if ($time === null) {
                    continue;
                }
                $row = array(
                    'Date (yyyy-mm-dd)' => $time->format('Y-m-d'),
                    'UTC Time (hh:mm:ss)' => $time->format('H:i:s'),
                    'Time (hh:mm:ss)' => $time->format('H:i:s'),
                    'UTC Offset (hh:mm)' => '+00:00',
                );
                if (isset($indexes['lat'])) {
                    $row['Latitude (deg)'] = (string)($dataRow[$indexes['lat']] ?? '');
                }
                if (isset($indexes['lon'])) {
                    $row['Longitude (deg)'] = (string)($dataRow[$indexes['lon']] ?? '');
                }
                if (isset($indexes['rpm'])) {
                    $row['RPM'] = (string)($dataRow[$indexes['rpm']] ?? '');
                }
                $rows[] = $row;
            }
        }
        usort($rows, static function (array $a, array $b): int {
            return strcmp(($a['Date (yyyy-mm-dd)'] ?? '') . ' ' . ($a['UTC Time (hh:mm:ss)'] ?? ''), ($b['Date (yyyy-mm-dd)'] ?? '') . ' ' . ($b['UTC Time (hh:mm:ss)'] ?? ''));
        });
        return $rows;
    }

    /**
     * @param list<mixed> $fields
     * @return array<string,int>
     */
    private function fieldIndexes(array $fields): array
    {
        $indexes = array();
        foreach ($fields as $index => $field) {
            if (!is_array($field)) {
                continue;
            }
            $typeParts = array();
            foreach (array('fieldType', 'name', 'label', 'displayName', 'title', 'id') as $fieldKey) {
                $candidate = strtolower(trim((string)($field[$fieldKey] ?? '')));
                if ($candidate !== '') {
                    $typeParts[] = $candidate;
                }
            }
            $type = implode(' ', $typeParts);
            if ($type === '') {
                continue;
            }
            if (!isset($indexes['time']) && $type === 'time') {
                $indexes['time'] = (int)$index;
            } elseif (!isset($indexes['lat']) && (str_contains($type, 'lat') || $type === 'latitude')) {
                $indexes['lat'] = (int)$index;
            } elseif (!isset($indexes['lon']) && (str_contains($type, 'lon') || str_contains($type, 'lng') || $type === 'longitude')) {
                $indexes['lon'] = (int)$index;
            } elseif (!isset($indexes['rpm']) && str_contains($type, 'rpm')) {
                $indexes['rpm'] = (int)$index;
            }
        }
        return $indexes;
    }

    private function timestampFromValue(mixed $value): ?DateTimeImmutable
    {
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            $number = (float)$value;
            if ($number > 100000000000) {
                $number /= 1000;
            }
            try {
                return (new DateTimeImmutable('@' . (string)((int)round($number))))->setTimezone(new DateTimeZone('UTC'));
            } catch (Throwable) {
                return null;
            }
        }
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<string,mixed> $track
     * @return array<string,mixed>
     */
    private function emptySummary(array $track): array
    {
        $firstUtc = $this->dateTimeOrNull((string)($track['first_seen_at'] ?? ''));
        return array(
            'status' => 'not_analyzed',
            'tail' => $this->tailFromTrack($track),
            'date_label' => $this->dateLabel($firstUtc),
            'dep_airport' => '--',
            'arr_airport' => '--',
            'dep_time_lt' => '--',
            'arr_time_lt' => '--',
            'elapsed_time' => '--',
            'start_utc' => '',
            'end_utc' => '',
            'row_count' => 0,
            'hobbs_out' => '--',
            'tacho_out' => '--',
            'hobbs_in' => '--',
            'tacho_in' => '--',
            'hobbs_time' => '--',
            'tacho_time' => '--',
            'system_id' => '',
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
        return sprintf('%s - %s %s %s LT - %s %s LT (%s)', (string)$summary['date_label'], (string)$summary['tail'], (string)$summary['dep_airport'], (string)$summary['dep_time_lt'], (string)$summary['arr_airport'], (string)$summary['arr_time_lt'], (string)$summary['elapsed_time']);
    }

    /**
     * @param array<string,mixed> $track
     */
    private function tailFromTrack(array $track): string
    {
        foreach (array('entry_aircraft_registration', 'csv_aircraft_registration', 'csv_aircraft_ident') as $key) {
            $value = strtoupper(trim((string)($track[$key] ?? '')));
            if ($this->looksLikeTailNumber($value)) {
                return $value;
            }
        }
        $metadata = json_decode((string)($track['raw_metadata_json'] ?? '{}'), true);
        if (is_array($metadata)) {
            foreach (array('aircraftRegistration', 'aircraft_registration', 'tail') as $key) {
                $value = strtoupper(trim((string)($metadata[$key] ?? '')));
                if ($this->looksLikeTailNumber($value)) {
                    return $value;
                }
            }
        }
        $systemTail = $this->tailForSystemIdentifier($this->systemIdentifierFromTrack($track));
        if ($systemTail !== '') {
            return $systemTail;
        }
        return 'Unknown tail';
    }

    private function resolveStoragePath(string $storagePath): ?string
    {
        $storagePath = trim($storagePath);
        if ($storagePath === '') {
            return null;
        }
        $candidates = str_starts_with($storagePath, '/')
            ? array($storagePath)
            : array(dirname(__DIR__) . '/' . ltrim($storagePath, '/'), dirname(__DIR__) . '/storage/cvr/' . ltrim($storagePath, '/'));
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function trackArtifact(int $trackArtifactId): ?array
    {
        if ($this->pdo === null) {
            return null;
        }
        if ($this->tableExists('ipca_garmin_flight_data_track_links') && $this->tableExists('ipca_garmin_csv_files')) {
            $stmt = $this->pdo->prepare("
                SELECT
                  t.*,
                  f.aircraft_registration AS csv_aircraft_registration,
                  f.aircraft_ident AS csv_aircraft_ident,
                  f.system_identifier AS csv_system_identifier,
                  f.airframe_hours_start AS csv_airframe_hours_start,
                  f.engine_hours_start AS csv_engine_hours_start
                FROM ipca_garmin_normalized_track_artifacts t
                LEFT JOIN ipca_garmin_flight_data_track_links l
                  ON l.provider_name = t.provider_name
                 AND l.garmin_entry_uuid = t.garmin_entry_uuid
                 AND l.canonical_track_uuid = t.track_uuid
                LEFT JOIN ipca_garmin_csv_files f
                  ON f.id = l.garmin_csv_file_id
                WHERE t.id = ?
                LIMIT 1
            ");
            $stmt->execute(array($trackArtifactId));
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                return $row;
            }
        }
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_garmin_normalized_track_artifacts WHERE id = ? LIMIT 1');
        $stmt->execute(array($trackArtifactId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function storedSummaryForTrackId(int $trackArtifactId): ?array
    {
        if ($this->pdo === null || !$this->summaryTableReady()) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_garmin_track_flight_summaries WHERE track_artifact_id = ? LIMIT 1');
        $stmt->execute(array($trackArtifactId));
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
        return is_array($stored) ? array_merge($summary, array_intersect_key($stored, array_flip(array('hobbs_out', 'hobbs_in', 'hobbs_time', 'tacho_out', 'tacho_in', 'tacho_time', 'hobbs_exact', 'tacho_exact', 'tacho_status', 'tacho_calculation_version', 'system_id', 'avionics_family', 'default_quality', 'provides_full_avionics', 'provides_counter_headers', 'tail_source', 'calculation_config')))) : $summary;
    }

    /**
     * @param array<string,mixed> $summary
     */
    private function storeSummary(int $trackArtifactId, array $summary): void
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
        $displayLabel = trim((string)($summary['label'] ?? '')) ?: $this->label($summary);
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_garmin_track_flight_summaries
              (track_artifact_id, derivation_status, tail_number, departure_airport_code, arrival_airport_code,
               departure_time_utc, arrival_time_utc, elapsed_seconds, hobbs_start_utc, hobbs_end_utc,
               hobbs_duration_seconds, hobbs_status, row_count, calculation_version, display_label, summary_json,
               exception_json, derived_at)
            VALUES
              (:track_artifact_id, :derivation_status, :tail_number, :departure_airport_code, :arrival_airport_code,
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
            ':track_artifact_id' => $trackArtifactId,
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
        $stmt->execute(array('ipca_garmin_track_flight_summaries'));
        return $this->tableReady = (int)$stmt->fetchColumn() > 0;
    }

    private function ensureTable(): void
    {
        if ($this->pdo === null || $this->summaryTableReady()) {
            return;
        }
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS ipca_garmin_track_flight_summaries (
              track_artifact_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
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
              KEY idx_garmin_track_summary_departure (departure_time_utc),
              KEY idx_garmin_track_summary_tail (tail_number)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $this->tableReady = true;
    }

    private function remember(int $trackId, array $summary): array
    {
        if (trim((string)($summary['label'] ?? '')) === '') {
            $summary['label'] = $this->label($summary);
        }
        if ($trackId > 0) {
            $this->cache[$trackId] = $summary;
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
        return number_format(((float)$end->format('U.u') - (float)$start->format('U.u')) / 3600, 1) . ' h';
    }

    private function displayTimeZone(): DateTimeZone
    {
        $timezone = date_default_timezone_get();
        if ($timezone === '' || strtoupper($timezone) === 'UTC') {
            $timezone = 'America/Los_Angeles';
        }
        return new DateTimeZone($timezone);
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
        $duration = is_numeric($calculation['duration_hours_exact'] ?? null) ? (float)$calculation['duration_hours_exact'] : null;
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

    /**
     * @param array<string,mixed> $track
     */
    private function counterStartFromTrack(array $track, string $kind): ?float
    {
        $metadata = json_decode((string)($track['raw_metadata_json'] ?? '{}'), true);
        $metadata = is_array($metadata) ? $metadata : array();
        $keys = $kind === 'airframe'
            ? array('csv_airframe_hours_start', 'airframe_hours_start', 'airframe_hours', 'airframeHours')
            : array('csv_engine_hours_start', 'engine_hours_start', 'engine_hours', 'engineHours');
        foreach ($keys as $key) {
            $value = array_key_exists($key, $track) ? $track[$key] : ($metadata[$key] ?? null);
            if (is_numeric($value)) {
                return (float)$value;
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $track
     */
    private function systemIdentifierFromTrack(array $track): string
    {
        foreach (array('csv_system_identifier', 'system_identifier', 'system_id') as $key) {
            $value = trim((string)($track[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        $metadata = json_decode((string)($track['raw_metadata_json'] ?? '{}'), true);
        if (is_array($metadata)) {
            foreach (array('system_identifier', 'system_id', 'systemId') as $key) {
                $value = trim((string)($metadata[$key] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
            foreach (array('sourceNames') as $key) {
                if (is_array($metadata[$key] ?? null)) {
                    $systemId = $this->systemIdentifierFromSourceNames($metadata[$key]);
                    if ($systemId !== '') {
                        return $systemId;
                    }
                }
            }
        }
        $sourceDescriptors = json_decode((string)($track['source_descriptors_json'] ?? '[]'), true);
        if (is_array($sourceDescriptors)) {
            $names = array();
            foreach ($sourceDescriptors as $descriptor) {
                if (is_array($descriptor)) {
                    $names[] = (string)($descriptor['name'] ?? '');
                }
            }
            $systemId = $this->systemIdentifierFromSourceNames($names);
            if ($systemId !== '') {
                return $systemId;
            }
        }
        return '';
    }

    /**
     * @param list<mixed> $names
     */
    private function systemIdentifierFromSourceNames(array $names): string
    {
        foreach ($names as $name) {
            if (preg_match('/Flight Data Log System ID:\\s*([A-Z0-9]+)/i', (string)$name, $matches) === 1) {
                return strtoupper((string)$matches[1]);
            }
        }
        return '';
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
     * @param array<string,mixed> $track
     */
    private function tailSource(string $tail, array $track, string $systemId): string
    {
        if ($tail === '' || stripos($tail, 'unknown') !== false) {
            return 'unresolved';
        }
        foreach (array('entry_aircraft_registration', 'csv_aircraft_registration', 'csv_aircraft_ident') as $key) {
            if ($this->looksLikeTailNumber((string)($track[$key] ?? '')) && strtoupper(trim((string)($track[$key] ?? ''))) === $tail) {
                return $key;
            }
        }
        $metadata = json_decode((string)($track['raw_metadata_json'] ?? '{}'), true);
        if (is_array($metadata)) {
            foreach (array('aircraftRegistration', 'aircraft_registration', 'tail') as $key) {
                if ($this->looksLikeTailNumber((string)($metadata[$key] ?? '')) && strtoupper(trim((string)($metadata[$key] ?? ''))) === $tail) {
                    return 'metadata_' . $key;
                }
            }
        }
        if ($systemId !== '' && $this->tailForSystemIdentifier($systemId) === $tail) {
            return 'system_identifier_mapping';
        }
        return 'derived';
    }

    private function looksLikeTailNumber(string $value): bool
    {
        return preg_match('/^N[0-9A-Z]{2,6}$/', strtoupper(trim($value))) === 1;
    }
}
