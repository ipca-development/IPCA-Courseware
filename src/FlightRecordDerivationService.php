<?php
declare(strict_types=1);

require_once __DIR__ . '/AircraftOperationalConfigService.php';
require_once __DIR__ . '/AirportDetectionService.php';
require_once __DIR__ . '/AuditEventService.php';
require_once __DIR__ . '/CrossCountryCalculationService.php';
require_once __DIR__ . '/DayNightCalculationService.php';
require_once __DIR__ . '/FlightEventDetectionService.php';
require_once __DIR__ . '/G3XFlightStreamParser.php';
require_once __DIR__ . '/GarminCsvValidationService.php';
require_once __DIR__ . '/HobbsCalculationService.php';
require_once __DIR__ . '/OperationalLegAllocationService.php';
require_once __DIR__ . '/OperationalFlightRecordVersionService.php';
require_once __DIR__ . '/TachoCalculationService.php';

final class FlightRecordDerivationService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function previewFromCsvFile(int $csvFileId): array
    {
        $csv = $this->csvFile($csvFileId);
        if ($csv === null) {
            throw new RuntimeException('CSV file not found.');
        }

        $parsed = G3XFlightStreamParser::parseFile((string)$csv['storage_path'], (string)$csv['import_profile']);
        $config = (new AircraftOperationalConfigService($this->pdo))->configForAircraft(isset($csv['aircraft_id']) ? (int)$csv['aircraft_id'] : null);
        $validation = $this->latestValidation($csvFileId);
        if ($validation === null) {
            $validation = (new GarminCsvValidationService($this->pdo))->validateFile($csvFileId, (string)$csv['storage_path']);
        }

        $hobbs = (new HobbsCalculationService())->calculate($parsed['rows'], $config);
        $tacho = (new TachoCalculationService())->calculate($parsed['rows'], $config);
        $airport = (new AirportDetectionService())->detect($parsed['rows']);
        $events = (new FlightEventDetectionService())->detectEvents($parsed['rows'], $config);

        if (($hobbs['start_utc'] ?? null) !== null) {
            array_unshift($events, array(
                'event_type' => 'ENGINE_START',
                'event_time_utc' => $hobbs['start_utc'],
                'detection_method' => 'hobbs_rpm_threshold',
                'confidence' => 0.85,
                'latitude' => null,
                'longitude' => null,
            ));
        }
        if (($hobbs['end_utc'] ?? null) !== null) {
            $events[] = array(
                'event_type' => 'ENGINE_STOP',
                'event_time_utc' => $hobbs['end_utc'],
                'detection_method' => 'hobbs_rpm_threshold',
                'confidence' => 0.85,
                'latitude' => null,
                'longitude' => null,
            );
        }

        $legs = array();
        $legExceptions = array();
        if (($hobbs['status'] ?? '') === 'ok') {
            try {
                $legs = (new OperationalLegAllocationService())->allocateLegs(
                    (string)$hobbs['start_utc'],
                    (string)$hobbs['end_utc'],
                    $events,
                    $airport['departure_airport_code'] ?? null,
                    $airport['arrival_airport_code'] ?? null
                );
            } catch (Throwable $e) {
                $legExceptions[] = $e->getMessage();
            }
        } else {
            $legExceptions[] = 'Leg allocation requires a valid Hobbs interval.';
        }

        $dayNight = (new DayNightCalculationService())->calculate(
            $legs,
            is_array($airport['departure'] ?? null) ? $airport['departure'] : (is_array($airport['arrival'] ?? null) ? $airport['arrival'] : null),
            (string)($config['timezone_identifier'] ?? 'UTC')
        );
        $legs = is_array($dayNight['legs'] ?? null) ? $dayNight['legs'] : $legs;
        $crossCountry = (new CrossCountryCalculationService())->calculate($legs, $airport['departure_airport_code'] ?? null);
        $legs = is_array($crossCountry['legs'] ?? null) ? $crossCountry['legs'] : $legs;
        $continuity = $this->previousFlightContinuity($csv, $hobbs, $tacho);

        $exceptions = array_merge(
            $this->exceptionsFromCalculation($hobbs),
            $this->exceptionsFromCalculation($tacho),
            $this->exceptionsFromCalculation($airport),
            $legExceptions,
            $this->exceptionsFromCalculation($dayNight),
            $this->exceptionsFromCalculation($crossCountry),
            $this->exceptionsFromContinuity($continuity)
        );

        $readiness = $exceptions ? 'needs_review' : 'ready';
        return array(
            'ok' => true,
            'preview' => true,
            'csv_file' => array(
                'id' => (int)$csv['id'],
                'csv_file_uuid' => (string)$csv['csv_file_uuid'],
                'session_id' => isset($csv['session_id']) ? (int)$csv['session_id'] : null,
                'aircraft_id' => isset($csv['aircraft_id']) ? (int)$csv['aircraft_id'] : null,
                'aircraft_registration' => (string)($csv['aircraft_registration'] ?? ''),
                'source' => (string)($csv['source'] ?? ''),
                'import_profile' => (string)($csv['import_profile'] ?? ''),
            ),
            'validation' => $validation,
            'config_version_uuid' => (string)($config['config_version_uuid'] ?? 'phase1-default'),
            'calculation_version' => 'phase3-v1',
            'readiness_status' => $readiness,
            'exceptions' => array_values(array_unique(array_filter($exceptions))),
            'calculations' => array(
                'hobbs' => $hobbs,
                'tacho' => $tacho,
                'airport_detection' => $airport,
                'day_night' => $dayNight,
                'cross_country' => $crossCountry,
                'previous_flight_continuity' => $continuity,
            ),
            'events' => $events,
            'legs' => $legs,
            'route' => $this->routePreview($parsed['rows']),
            'summary' => array(
                'exact_hobbs_duration_ms' => $hobbs['duration_ms'] ?? null,
                'display_hobbs_hours' => $hobbs['duration_hours_display'] ?? null,
                'exact_tacho_duration_ms' => $tacho['duration_ms'] ?? null,
                'display_tacho_hours' => $tacho['duration_hours_display'] ?? null,
                'total_night_duration_ms' => $dayNight['session_night_duration_ms'] ?? null,
                'display_night_hours' => $dayNight['session_night_hours_display'] ?? null,
                'landing_event_count' => count(array_filter($events, static fn(array $event): bool => ($event['event_type'] ?? '') === 'LANDING')),
                'cross_country_easa_qualified' => (bool)($crossCountry['easa_qualified'] ?? false),
                'cross_country_faa_qualified' => (bool)($crossCountry['faa_qualified'] ?? false),
            ),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function deriveFromCsvFile(int $csvFileId): array
    {
        $preview = $this->previewFromCsvFile($csvFileId);
        $csv = $this->csvFile($csvFileId);
        if (empty($csv['session_id'])) {
            throw new RuntimeException('CSV file is not matched to a flight session.');
        }

        $recordService = new OperationalFlightRecordVersionService($this->pdo);
        $record = $recordService->ensureRecordForSession((int)$csv['session_id']);
        $summary = array_merge($preview['summary'], array(
            'csv_file_id' => $csvFileId,
            'source' => 'garmin_csv',
            'calculation_version' => 'phase3-v1',
            'config_version_uuid' => $preview['config_version_uuid'],
            'readiness_status' => $preview['readiness_status'],
            'exceptions' => $preview['exceptions'],
            'preview' => $preview,
        ));
        $version = $recordService->createVersion((int)$record['id'], array(
            'csv_file_id' => $csvFileId,
            'exact_hobbs_duration_ms' => $preview['summary']['exact_hobbs_duration_ms'] ?? null,
            'exact_tacho_duration_ms' => $preview['summary']['exact_tacho_duration_ms'] ?? null,
            'total_night_duration_ms' => $preview['summary']['total_night_duration_ms'] ?? null,
            'cross_country_easa_qualified' => $preview['summary']['cross_country_easa_qualified'] ?? false,
            'cross_country_faa_qualified' => $preview['summary']['cross_country_faa_qualified'] ?? false,
            'landing_event_count' => $preview['summary']['landing_event_count'] ?? 0,
            'readiness_status' => $preview['readiness_status'],
            'source' => 'garmin_csv',
            'calculation_version' => 'phase3-v1',
            'summary' => $summary,
        ), 'garmin_csv');

        foreach ($preview['legs'] as $leg) {
            $recordService->addLegVersion((int)$version['id'], $leg);
        }
        $this->storeEvents((int)$version['id'], $preview['events']);
        foreach ($preview['calculations'] as $type => $calculation) {
            $this->storeCalculation((int)$version['id'], (string)$type, $calculation, (string)$preview['config_version_uuid']);
        }
        $this->pdo->prepare('UPDATE ipca_flight_sessions SET current_flight_record_id = ?, exact_hobbs_duration_ms = ?, exact_tacho_duration_ms = ?, updated_at = CURRENT_TIMESTAMP(3) WHERE id = ?')
            ->execute(array((int)$record['id'], $preview['summary']['exact_hobbs_duration_ms'] ?? null, $preview['summary']['exact_tacho_duration_ms'] ?? null, (int)$csv['session_id']));

        return array(
            'ok' => true,
            'flight_record_uuid' => $record['flight_record_uuid'],
            'version_uuid' => $version['version_uuid'],
            'readiness_status' => $preview['readiness_status'],
            'leg_count' => count($preview['legs']),
            'hobbs_duration_ms' => $preview['summary']['exact_hobbs_duration_ms'] ?? null,
            'tacho_duration_ms' => $preview['summary']['exact_tacho_duration_ms'] ?? null,
            'exceptions' => $preview['exceptions'],
        );
    }

    /**
     * @param list<array<string,mixed>> $events
     */
    private function storeEvents(int $flightRecordVersionId, array $events): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_flight_airport_event_versions
              (event_version_uuid, flight_record_version_id, event_type, event_time_utc, source, detection_method, confidence, latitude, longitude)
            VALUES
              (:event_version_uuid, :flight_record_version_id, :event_type, :event_time_utc, 'system', :detection_method, :confidence, :latitude, :longitude)
        ");
        foreach ($events as $event) {
            $stmt->execute(array(
                ':event_version_uuid' => AuditEventService::uuid(),
                ':flight_record_version_id' => $flightRecordVersionId,
                ':event_type' => (string)$event['event_type'],
                ':event_time_utc' => (string)$event['event_time_utc'],
                ':detection_method' => (string)($event['detection_method'] ?? ''),
                ':confidence' => (float)($event['confidence'] ?? 0),
                ':latitude' => $event['latitude'] ?? null,
                ':longitude' => $event['longitude'] ?? null,
            ));
        }
    }

    /**
     * @param array<string,mixed> $value
     */
    private function storeCalculation(int $flightRecordVersionId, string $type, array $value, string $configVersionUuid): void
    {
        $columns = array('calculation_uuid', 'flight_record_version_id', 'calculation_type', 'method', 'version', 'exact_value_json', 'display_value_json', 'source_json', 'confidence');
        $values = array('?', '?', '?', '?', '?', '?', '?', '?', '?');
        $params = array(
            AuditEventService::uuid(),
            $flightRecordVersionId,
            $type,
            (string)($value['method'] ?? 'not_configured'),
            (string)($value['calculation_version'] ?? 'phase3-v1'),
            AuditEventService::jsonEncode($this->exactValue($value)),
            AuditEventService::jsonEncode($this->displayValue($value)),
            AuditEventService::jsonEncode(array('config_version_uuid' => $configVersionUuid)),
            (float)($value['confidence'] ?? (($value['status'] ?? '') === 'ok' ? 0.85 : 0.0)),
        );
        if ($this->columnPresent('ipca_operational_calculation_versions', 'verification_status')) {
            $columns[] = 'verification_status';
            $values[] = '?';
            $params[] = (string)($value['verification_status'] ?? (($value['status'] ?? '') === 'ok' ? 'system_verified' : 'needs_review'));
        }
        if ($this->columnPresent('ipca_operational_calculation_versions', 'exception_json')) {
            $columns[] = 'exception_json';
            $values[] = '?';
            $params[] = AuditEventService::jsonEncode(array('exceptions' => $value['exceptions'] ?? array()));
        }
        $sql = 'INSERT INTO ipca_operational_calculation_versions (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')';
        $this->pdo->prepare($sql)->execute($params);
    }

    /**
     * @param array<string,mixed> $value
     * @return array<string,mixed>
     */
    private function exactValue(array $value): array
    {
        return array(
            'status' => $value['status'] ?? null,
            'start_utc' => $value['start_utc'] ?? null,
            'end_utc' => $value['end_utc'] ?? null,
            'duration_ms' => $value['duration_ms'] ?? ($value['session_night_duration_ms'] ?? null),
            'duration_hours_exact' => $value['duration_hours_exact'] ?? ($value['session_night_hours_exact'] ?? null),
            'easa_qualified' => $value['easa_qualified'] ?? null,
            'faa_qualified' => $value['faa_qualified'] ?? null,
            'departure_airport_code' => $value['departure_airport_code'] ?? null,
            'arrival_airport_code' => $value['arrival_airport_code'] ?? null,
            'continuity_checks' => $value['checks'] ?? null,
        );
    }

    /**
     * @param array<string,mixed> $value
     * @return array<string,mixed>
     */
    private function displayValue(array $value): array
    {
        return array(
            'duration_hours' => $value['duration_hours_display'] ?? ($value['session_night_hours_display'] ?? null),
            'status_label' => $this->statusLabel((string)($value['verification_status'] ?? $value['status'] ?? '')),
            'departure_airport' => $value['departure_airport_code'] ?? null,
            'arrival_airport' => $value['arrival_airport_code'] ?? null,
            'cross_country' => isset($value['easa_qualified'], $value['faa_qualified'])
                ? array('easa' => (bool)$value['easa_qualified'], 'faa' => (bool)$value['faa_qualified'])
                : null,
        );
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'system_verified', 'ok' => 'System verified',
            'needs_review', 'review_required' => 'Needs review',
            'not_configured' => 'Not configured',
            'not_running' => 'Not running',
            default => $status !== '' ? ucwords(str_replace('_', ' ', $status)) : 'Unknown',
        };
    }

    /**
     * @return array<string,mixed>|null
     */
    private function latestValidation(int $csvFileId): ?array
    {
        if (!$this->tablePresent('ipca_garmin_csv_validation_results')) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_garmin_csv_validation_results WHERE csv_file_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute(array($csvFileId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $details = json_decode((string)($row['details_json'] ?? '{}'), true);
        $row['details'] = is_array($details) ? $details : array();
        return $row;
    }

    /**
     * @param array<string,mixed> $csv
     * @param array<string,mixed> $hobbs
     * @param array<string,mixed> $tacho
     * @return array<string,mixed>
     */
    private function previousFlightContinuity(array $csv, array $hobbs, array $tacho): array
    {
        $previous = $this->previousCsvForAircraft($csv);
        $checks = array();
        if ($previous === null) {
            return array(
                'type' => 'previous_flight_continuity',
                'status' => 'no_previous_flight',
                'verification_status' => 'system_verified',
                'method' => 'same_aircraft_previous_garmin_csv',
                'calculation_version' => 'continuity_v1',
                'source' => array('type' => 'garmin_csv_sequence'),
                'confidence' => 0.65,
                'checks' => array(),
                'exceptions' => array(),
            );
        }

        $checks[] = $this->counterContinuityCheck('hobbs', $previous, $csv, $hobbs, 'airframe_hours_start', 0.2);
        $checks[] = $this->counterContinuityCheck('tacho', $previous, $csv, $tacho, 'engine_hours_start', 0.2);
        $exceptions = array_values(array_filter(array_map(static fn(array $check): ?string => ($check['status'] ?? '') === 'ok' ? null : (string)$check['message'], $checks)));
        return array(
            'type' => 'previous_flight_continuity',
            'status' => $exceptions ? 'needs_review' : 'ok',
            'verification_status' => $exceptions ? 'needs_review' : 'system_verified',
            'method' => 'same_aircraft_previous_garmin_csv',
            'calculation_version' => 'continuity_v1',
            'source' => array(
                'type' => 'garmin_csv_sequence',
                'previous_csv_file_id' => (int)$previous['id'],
                'current_csv_file_id' => (int)$csv['id'],
            ),
            'confidence' => $exceptions ? 0.50 : 0.80,
            'checks' => $checks,
            'exceptions' => $exceptions,
        );
    }

    /**
     * @param array<string,mixed> $previous
     * @param array<string,mixed> $current
     * @param array<string,mixed> $counter
     * @return array<string,mixed>
     */
    private function counterContinuityCheck(string $type, array $previous, array $current, array $counter, string $startColumn, float $toleranceHours): array
    {
        $previousStart = is_numeric($previous[$startColumn] ?? null) ? (float)$previous[$startColumn] : null;
        $currentStart = is_numeric($current[$startColumn] ?? null) ? (float)$current[$startColumn] : null;
        $durationMs = $this->previousDurationMs((int)$previous['id'], $type);
        if ($previousStart === null || $currentStart === null || $durationMs === null) {
            return array(
                'type' => $type,
                'status' => 'needs_review',
                'message' => strtoupper($type) . ' continuity cannot be verified because prior/current counter evidence is incomplete.',
            );
        }
        $expected = $previousStart + ($durationMs / 3600000);
        $delta = $currentStart - $expected;
        $ok = abs($delta) <= $toleranceHours;
        return array(
            'type' => $type,
            'status' => $ok ? 'ok' : 'needs_review',
            'previous_start_hours' => $previousStart,
            'previous_duration_hours' => $durationMs / 3600000,
            'expected_current_start_hours' => $expected,
            'actual_current_start_hours' => $currentStart,
            'delta_hours' => $delta,
            'tolerance_hours' => $toleranceHours,
            'message' => $ok ? 'Continuity check passed.' : strtoupper($type) . ' continuity gap exceeds tolerance.',
        );
    }

    private function previousDurationMs(int $csvFileId, string $type): ?int
    {
        $stmt = $this->pdo->prepare('
            SELECT v.exact_hobbs_duration_ms, v.exact_tacho_duration_ms
            FROM ipca_operational_flight_record_versions v
            WHERE CAST(JSON_UNQUOTE(JSON_EXTRACT(v.summary_json, "$.csv_file_id")) AS UNSIGNED) = ?
            ORDER BY v.id DESC
            LIMIT 1
        ');
        $stmt->execute(array($csvFileId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $column = $type === 'tacho' ? 'exact_tacho_duration_ms' : 'exact_hobbs_duration_ms';
        return is_numeric($row[$column] ?? null) ? (int)$row[$column] : null;
    }

    /**
     * @param array<string,mixed> $csv
     * @return array<string,mixed>|null
     */
    private function previousCsvForAircraft(array $csv): ?array
    {
        $aircraftId = (int)($csv['aircraft_id'] ?? 0);
        $firstSample = trim((string)($csv['first_valid_sample_utc'] ?? ''));
        if ($aircraftId <= 0 || $firstSample === '') {
            return null;
        }
        $stmt = $this->pdo->prepare('
            SELECT *
            FROM ipca_garmin_csv_files
            WHERE aircraft_id = ?
              AND first_valid_sample_utc IS NOT NULL
              AND first_valid_sample_utc < ?
              AND id <> ?
            ORDER BY first_valid_sample_utc DESC, id DESC
            LIMIT 1
        ');
        $stmt->execute(array($aircraftId, $firstSample, (int)$csv['id']));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $calculation
     * @return list<string>
     */
    private function exceptionsFromCalculation(array $calculation): array
    {
        $exceptions = $calculation['exceptions'] ?? array();
        return is_array($exceptions) ? array_values(array_map('strval', $exceptions)) : array();
    }

    /**
     * @param array<string,mixed> $continuity
     * @return list<string>
     */
    private function exceptionsFromContinuity(array $continuity): array
    {
        $exceptions = $continuity['exceptions'] ?? array();
        return is_array($exceptions) ? array_values(array_map('strval', $exceptions)) : array();
    }

    /**
     * @param list<array<string,string>> $rows
     * @return array{points:list<array{lat:float,lon:float,t:string|null}>,bounds:array{min_lat:float,max_lat:float,min_lon:float,max_lon:float}|null}
     */
    private function routePreview(array $rows, int $maxPoints = 120): array
    {
        $points = array();
        foreach ($rows as $row) {
            $lat = G3XFlightStreamParser::numericValue($row, 'Latitude (deg)', 'Latitude', 'Lat');
            $lon = G3XFlightStreamParser::numericValue($row, 'Longitude (deg)', 'Longitude', 'Lon');
            if ($lat === null || $lon === null) {
                continue;
            }
            $time = G3XFlightStreamParser::rowUtcTimestamp($row);
            $points[] = array('lat' => (float)$lat, 'lon' => (float)$lon, 't' => $time?->format('Y-m-d H:i:s.v'));
        }
        if (!$points) {
            return array('points' => array(), 'bounds' => null);
        }
        $stride = max(1, (int)ceil(count($points) / max(2, $maxPoints)));
        $sampled = array_values(array_filter($points, static fn(array $point, int $index): bool => $index === 0 || $index === count($points) - 1 || $index % $stride === 0, ARRAY_FILTER_USE_BOTH));
        $lats = array_map(static fn(array $point): float => (float)$point['lat'], $sampled);
        $lons = array_map(static fn(array $point): float => (float)$point['lon'], $sampled);
        return array(
            'points' => $sampled,
            'bounds' => array(
                'min_lat' => min($lats),
                'max_lat' => max($lats),
                'min_lon' => min($lons),
                'max_lon' => max($lons),
            ),
        );
    }

    private function tablePresent(string $table): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute(array($table));
        return (int)$stmt->fetchColumn() > 0;
    }

    private function columnPresent(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute(array($table, $column));
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function csvFile(int $csvFileId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_garmin_csv_files WHERE id = ? LIMIT 1');
        $stmt->execute(array($csvFileId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}
