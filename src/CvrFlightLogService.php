<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';

final class CvrFlightLogService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<string,mixed> $device
     * @return array<int,array<string,mixed>>
     */
    public function forDeviceAircraft(array $device): array
    {
        $organizationId = max(1, (int)($device['organization_id'] ?? 1));
        $aircraftId = (int)($device['aircraft_id'] ?? 0);
        $registration = strtoupper(trim((string)($device['aircraft_registration'] ?? '')));
        if ($aircraftId <= 0 && $registration === '') {
            throw new RuntimeException('The CVR Unit is not assigned to an aircraft.');
        }

        $aircraftPredicate = $aircraftId > 0
            ? 'd.aircraft_id = :aircraft_id'
            : 'UPPER(d.aircraft_registration) = :registration';
        $sql = "
            SELECT
                d.workflow_flight_record_uuid,
                d.dispatch_uuid,
                d.aircraft_registration,
                d.crew_json AS dispatch_crew_json,
                adjustment.crew_json AS adjustment_crew_json,
                DATE_FORMAT(d.scheduled_date, '%Y-%m-%d') AS scheduled_date,
                COALESCE(
                    NULLIF(adjustment.departure_airport, ''),
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(v.payload_json, '$.planned_departure_airport')), 'null'),
                    ''
                ) AS departure_airport,
                DATE_FORMAT(departure_event.timestamp_local, '%Y-%m-%dT%H:%i:%s') AS departure_time,
                COALESCE(
                    NULLIF(adjustment.arrival_airport, ''),
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(v.payload_json, '$.planned_destination_airport')), 'null'),
                    ''
                ) AS arrival_airport,
                DATE_FORMAT(arrival_event.timestamp_local, '%Y-%m-%dT%H:%i:%s') AS arrival_event_time,
                CAST(d.starting_hobbs AS DECIMAL(12,2)) AS starting_hobbs,
                CAST(d.starting_tacho AS DECIMAL(12,2)) AS starting_tacho,
                CAST(COALESCE(adjustment.ending_hobbs, closure.ending_hobbs) AS DECIMAL(12,2)) AS ending_hobbs,
                CAST(COALESCE(adjustment.ending_tacho, closure.ending_tacho) AS DECIMAL(12,2)) AS ending_tacho,
                COALESCE(adjustment.fuel_remaining, closure.fuel_remaining) AS fuel_remaining,
                closure.oil_percentage,
                closure.oil_quantity,
                closure.oil_unit,
                CASE
                    WHEN COALESCE(adjustment.ending_hobbs, closure.ending_hobbs) IS NULL OR d.starting_hobbs IS NULL THEN NULL
                    ELSE ROUND(COALESCE(adjustment.ending_hobbs, closure.ending_hobbs) - d.starting_hobbs, 2)
                END AS total_hobbs_time,
                EXISTS(
                    SELECT 1
                    FROM ipca_garmin_csv_files csv
                    WHERE csv.workflow_flight_record_uuid = d.workflow_flight_record_uuid
                    LIMIT 1
                ) AS has_garmin_csv
            FROM ipca_cvr_dispatches d
            LEFT JOIN ipca_cvr_dispatch_versions v
              ON v.dispatch_id = d.id
             AND v.dispatch_version = d.current_version
            LEFT JOIN ipca_cvr_flight_closures closure
              ON closure.id = (
                  SELECT c2.id
                  FROM ipca_cvr_flight_closures c2
                  WHERE c2.workflow_flight_record_uuid = d.workflow_flight_record_uuid
                  ORDER BY c2.received_at DESC, c2.id DESC
                  LIMIT 1
              )
            LEFT JOIN ipca_cvr_flight_log_adjustments adjustment
              ON adjustment.id = (
                  SELECT a2.id
                  FROM ipca_cvr_flight_log_adjustments a2
                  WHERE a2.workflow_flight_record_uuid = d.workflow_flight_record_uuid
                  ORDER BY a2.created_at DESC, a2.id DESC
                  LIMIT 1
              )
            LEFT JOIN ipca_cvr_flight_events departure_event
              ON departure_event.id = (
                  SELECT e1.id
                  FROM ipca_cvr_flight_events e1
                  WHERE e1.workflow_flight_record_uuid = d.workflow_flight_record_uuid
                    AND e1.event_type = 'engine_start_off_block'
                  ORDER BY e1.timestamp_utc ASC, e1.id ASC
                  LIMIT 1
              )
            LEFT JOIN ipca_cvr_flight_events arrival_event
              ON arrival_event.id = (
                  SELECT e2.id
                  FROM ipca_cvr_flight_events e2
                  WHERE e2.workflow_flight_record_uuid = d.workflow_flight_record_uuid
                    AND e2.event_type = 'engine_shutdown_on_block'
                  ORDER BY e2.timestamp_utc DESC, e2.id DESC
                  LIMIT 1
              )
            WHERE d.organization_id = :organization_id
              AND {$aircraftPredicate}
            ORDER BY d.scheduled_date DESC, COALESCE(departure_event.timestamp_local, d.first_received_at) DESC
            LIMIT 500
        ";
        $statement = $this->pdo->prepare($sql);
        $parameters = array(':organization_id' => $organizationId);
        if ($aircraftId > 0) {
            $parameters[':aircraft_id'] = $aircraftId;
        } else {
            $parameters[':registration'] = $registration;
        }
        $statement->execute($parameters);

        $logs = array();
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $crewJson = $row['adjustment_crew_json'] !== null
                ? (string)$row['adjustment_crew_json']
                : (string)($row['dispatch_crew_json'] ?? '[]');
            $crew = json_decode($crewJson, true);
            $crewNames = array();
            foreach (is_array($crew) ? $crew : array() as $member) {
                $name = is_array($member)
                    ? trim((string)($member['person_name'] ?? ''))
                    : trim((string)$member);
                if ($name !== '') {
                    $crewNames[] = $name;
                }
            }
            $arrivalTime = $row['arrival_event_time'] !== null ? (string)$row['arrival_event_time'] : null;
            if ($row['departure_time'] !== null && $row['total_hobbs_time'] !== null) {
                try {
                    $departureTime = new DateTimeImmutable((string)$row['departure_time']);
                    $elapsedSeconds = (int)round((float)$row['total_hobbs_time'] * 3600);
                    $arrivalTime = $departureTime
                        ->modify(sprintf('+%d seconds', $elapsedSeconds))
                        ->format('Y-m-d\TH:i:s');
                } catch (Throwable) {
                    // Preserve the recorded shutdown time if the local departure timestamp is invalid.
                }
            }
            $logs[] = array(
                'flight_record_uuid' => (string)$row['workflow_flight_record_uuid'],
                'dispatch_uuid' => (string)$row['dispatch_uuid'],
                'aircraft_registration' => (string)$row['aircraft_registration'],
                'scheduled_date' => (string)$row['scheduled_date'],
                'crew_names' => array_values(array_unique($crewNames)),
                'departure_airport' => (string)$row['departure_airport'],
                'departure_time' => $row['departure_time'] !== null ? (string)$row['departure_time'] : null,
                'arrival_airport' => (string)$row['arrival_airport'],
                'arrival_time' => $arrivalTime,
                'starting_hobbs' => $row['starting_hobbs'] !== null ? (float)$row['starting_hobbs'] : null,
                'starting_tacho' => $row['starting_tacho'] !== null ? (float)$row['starting_tacho'] : null,
                'ending_hobbs' => $row['ending_hobbs'] !== null ? (float)$row['ending_hobbs'] : null,
                'ending_tacho' => $row['ending_tacho'] !== null ? (float)$row['ending_tacho'] : null,
                'fuel_remaining' => $row['fuel_remaining'] !== null ? (string)$row['fuel_remaining'] : null,
                'ending_oil_percentage' => $row['oil_percentage'] !== null ? (int)$row['oil_percentage'] : null,
                'ending_oil_quantity' => $row['oil_quantity'] !== null ? (float)$row['oil_quantity'] : null,
                'ending_oil_unit' => $row['oil_unit'] !== null ? (string)$row['oil_unit'] : null,
                'total_hobbs_time' => $row['total_hobbs_time'] !== null ? (float)$row['total_hobbs_time'] : null,
                'has_garmin_csv' => (bool)$row['has_garmin_csv'],
            );
        }
        return $logs;
    }

    /**
     * @param array<string,mixed> $device
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function adjustForDeviceAircraft(array $device, array $payload): array
    {
        $flightUuid = strtolower(trim((string)($payload['flight_record_uuid'] ?? '')));
        $dispatchUuid = strtolower(trim((string)($payload['dispatch_uuid'] ?? '')));
        if (!$this->isUuid($flightUuid) || !$this->isUuid($dispatchUuid)) {
            throw new RuntimeException('Valid Flight Record and Dispatch UUIDs are required.');
        }
        $organizationId = max(1, (int)($device['organization_id'] ?? 1));
        $aircraftId = (int)($device['aircraft_id'] ?? 0);
        $registration = strtoupper(trim((string)($device['aircraft_registration'] ?? '')));
        $aircraftOwnershipPredicate = $aircraftId > 0
            ? 'aircraft_id = :aircraft_id'
            : 'UPPER(aircraft_registration) = :registration';
        $ownership = $this->pdo->prepare(
            'SELECT id, starting_hobbs, starting_tacho
             FROM ipca_cvr_dispatches
             WHERE workflow_flight_record_uuid = :flight_uuid
               AND dispatch_uuid = :dispatch_uuid
               AND organization_id = :organization_id
               AND ' . $aircraftOwnershipPredicate . '
             LIMIT 1'
        );
        $ownershipParameters = array(
            ':flight_uuid' => $flightUuid,
            ':dispatch_uuid' => $dispatchUuid,
            ':organization_id' => $organizationId,
        );
        if ($aircraftId > 0) {
            $ownershipParameters[':aircraft_id'] = $aircraftId;
        } else {
            $ownershipParameters[':registration'] = $registration;
        }
        $ownership->execute($ownershipParameters);
        $dispatch = $ownership->fetch(PDO::FETCH_ASSOC);
        if (!is_array($dispatch)) {
            throw new RuntimeException('The selected Flight Record does not belong to this CVR Unit aircraft.');
        }

        $departure = $this->airport($payload['departure_airport'] ?? null, 'departure_airport');
        $arrival = $this->airport($payload['arrival_airport'] ?? null, 'arrival_airport');
        $crew = array();
        foreach (is_array($payload['crew_names'] ?? null) ? $payload['crew_names'] : array() as $name) {
            $name = trim((string)$name);
            if ($name !== '') {
                $crew[] = substr($name, 0, 255);
            }
        }
        $crew = array_values(array_unique($crew));
        if ($crew === array()) {
            throw new RuntimeException('At least one crew member is required.');
        }
        $endingHobbs = isset($payload['ending_hobbs']) && is_numeric($payload['ending_hobbs'])
            ? (float)$payload['ending_hobbs']
            : null;
        $endingTacho = isset($payload['ending_tacho']) && is_numeric($payload['ending_tacho'])
            ? (float)$payload['ending_tacho']
            : null;
        $fuel = trim((string)($payload['fuel_remaining'] ?? ''));
        if ($endingHobbs === null || $endingHobbs < (float)$dispatch['starting_hobbs']) {
            throw new RuntimeException('Ending Hobbs cannot be lower than Starting Hobbs.');
        }
        if ($endingTacho === null || $endingTacho < (float)$dispatch['starting_tacho']) {
            throw new RuntimeException('Ending Tacho cannot be lower than Starting Tacho.');
        }
        if ($fuel === '' || !is_numeric($fuel) || (float)$fuel < 0) {
            throw new RuntimeException('Fuel remaining must be a valid non-negative quantity.');
        }

        $adjustmentUuid = AuditEventService::uuid();
        $this->pdo->prepare(
            'INSERT INTO ipca_cvr_flight_log_adjustments
             (adjustment_uuid, organization_id, device_id, workflow_flight_record_uuid, dispatch_uuid,
              departure_airport, arrival_airport, crew_json, ending_hobbs, ending_tacho, fuel_remaining)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute(array(
            $adjustmentUuid,
            $organizationId,
            (int)$device['id'],
            $flightUuid,
            $dispatchUuid,
            $departure,
            $arrival,
            AuditEventService::jsonEncode($crew),
            $endingHobbs,
            $endingTacho,
            $fuel,
        ));
        (new AuditEventService($this->pdo))->record(
            'cvr_flight_log_adjusted',
            'ipca_cvr_flight_log_adjustments',
            $adjustmentUuid,
            null,
            array(
                'flight_record_uuid' => $flightUuid,
                'dispatch_uuid' => $dispatchUuid,
                'departure_airport' => $departure,
                'arrival_airport' => $arrival,
                'crew_names' => $crew,
                'ending_hobbs' => $endingHobbs,
                'ending_tacho' => $endingTacho,
                'fuel_remaining' => $fuel,
            ),
            'PIN-protected iOS adjustment of a locked Flight Log.',
            'device',
            null,
            (int)$device['id'],
            null,
            $organizationId,
            'cvr_app'
        );
        return array('ok' => true, 'adjustment_uuid' => $adjustmentUuid);
    }

    private function airport(mixed $value, string $field): string
    {
        $airport = strtoupper(trim((string)$value));
        if ($airport === '' || preg_match('/^[A-Z0-9]{3,8}$/', $airport) !== 1) {
            throw new RuntimeException($field . ' must contain a valid airport identifier.');
        }
        return $airport;
    }

    private function isUuid(string $value): bool
    {
        return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $value) === 1;
    }
}
