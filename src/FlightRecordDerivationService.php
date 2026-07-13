<?php
declare(strict_types=1);

require_once __DIR__ . '/AircraftOperationalConfigService.php';
require_once __DIR__ . '/AuditEventService.php';
require_once __DIR__ . '/FlightEventDetectionService.php';
require_once __DIR__ . '/FlightNightCrossCountryService.php';
require_once __DIR__ . '/FlightOperationalCalculationService.php';
require_once __DIR__ . '/G3XFlightStreamParser.php';
require_once __DIR__ . '/OperationalLegAllocationService.php';
require_once __DIR__ . '/OperationalFlightRecordVersionService.php';

final class FlightRecordDerivationService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function deriveFromCsvFile(int $csvFileId): array
    {
        $csv = $this->csvFile($csvFileId);
        if ($csv === null) {
            throw new RuntimeException('CSV file not found.');
        }
        if (empty($csv['session_id'])) {
            throw new RuntimeException('CSV file is not matched to a flight session.');
        }
        $parsed = G3XFlightStreamParser::parseFile((string)$csv['storage_path'], (string)$csv['import_profile']);
        $config = (new AircraftOperationalConfigService($this->pdo))->configForAircraft(isset($csv['aircraft_id']) ? (int)$csv['aircraft_id'] : null);
        $counterService = new FlightOperationalCalculationService();
        $counters = $counterService->calculateSessionCounters($parsed['rows'], $config);
        if (($counters['hobbs']['status'] ?? '') !== 'ok') {
            throw new RuntimeException('Cannot derive Flight Record without a valid Hobbs engine interval.');
        }

        $events = (new FlightEventDetectionService())->detectEvents($parsed['rows'], $config);
        array_unshift($events, array(
            'event_type' => 'ENGINE_START',
            'event_time_utc' => $counters['hobbs']['start_utc'],
            'detection_method' => 'hobbs_rpm_threshold',
            'confidence' => 0.85,
            'latitude' => null,
            'longitude' => null,
        ));
        $events[] = array(
            'event_type' => 'ENGINE_STOP',
            'event_time_utc' => $counters['hobbs']['end_utc'],
            'detection_method' => 'hobbs_rpm_threshold',
            'confidence' => 0.85,
            'latitude' => null,
            'longitude' => null,
        );

        $recordService = new OperationalFlightRecordVersionService($this->pdo);
        $record = $recordService->ensureRecordForSession((int)$csv['session_id']);
        $version = $recordService->createVersion((int)$record['id'], array(
            'csv_file_id' => $csvFileId,
            'exact_hobbs_duration_ms' => $counters['hobbs']['duration_ms'],
            'exact_tacho_duration_ms' => $counters['tacho']['duration_ms'] ?? null,
            'source' => 'garmin_csv',
            'calculation_version' => 'phase3-v1',
        ), 'garmin_csv');

        $legs = (new OperationalLegAllocationService())->allocateLegs(
            (string)$counters['hobbs']['start_utc'],
            (string)$counters['hobbs']['end_utc'],
            $events
        );
        foreach ($legs as $leg) {
            $recordService->addLegVersion((int)$version['id'], $leg);
        }
        $this->storeEvents((int)$version['id'], $events);
        $this->storeCalculation((int)$version['id'], 'hobbs', $counters['hobbs'], (string)($counters['config_version_uuid'] ?? 'phase1-default'));
        $this->storeCalculation((int)$version['id'], 'tacho', $counters['tacho'], (string)($counters['config_version_uuid'] ?? 'phase1-default'));
        $this->pdo->prepare('UPDATE ipca_flight_sessions SET current_flight_record_id = ?, exact_hobbs_duration_ms = ?, exact_tacho_duration_ms = ?, updated_at = CURRENT_TIMESTAMP(3) WHERE id = ?')
            ->execute(array((int)$record['id'], $counters['hobbs']['duration_ms'], $counters['tacho']['duration_ms'] ?? null, (int)$csv['session_id']));

        return array(
            'ok' => true,
            'flight_record_uuid' => $record['flight_record_uuid'],
            'version_uuid' => $version['version_uuid'],
            'leg_count' => count($legs),
            'hobbs_duration_ms' => $counters['hobbs']['duration_ms'],
            'tacho_duration_ms' => $counters['tacho']['duration_ms'] ?? null,
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
        $this->pdo->prepare("
            INSERT INTO ipca_operational_calculation_versions
              (calculation_uuid, flight_record_version_id, calculation_type, method, version, exact_value_json, source_json, confidence)
            VALUES
              (?, ?, ?, ?, 'phase3-v1', ?, ?, ?)
        ")->execute(array(
            AuditEventService::uuid(),
            $flightRecordVersionId,
            $type,
            (string)($value['method'] ?? 'not_configured'),
            AuditEventService::jsonEncode($value),
            AuditEventService::jsonEncode(array('config_version_uuid' => $configVersionUuid)),
            ($value['status'] ?? '') === 'ok' ? 0.85 : 0.0,
        ));
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
