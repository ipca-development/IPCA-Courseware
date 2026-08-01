<?php
declare(strict_types=1);

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
                DATE_FORMAT(d.scheduled_date, '%Y-%m-%d') AS scheduled_date,
                COALESCE(
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(v.payload_json, '$.planned_departure_airport')), 'null'),
                    ''
                ) AS departure_airport,
                DATE_FORMAT(departure_event.timestamp_local, '%Y-%m-%dT%H:%i:%s') AS departure_time,
                COALESCE(
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(v.payload_json, '$.planned_destination_airport')), 'null'),
                    ''
                ) AS arrival_airport,
                DATE_FORMAT(arrival_event.timestamp_local, '%Y-%m-%dT%H:%i:%s') AS arrival_time,
                CAST(d.starting_hobbs AS DECIMAL(12,2)) AS starting_hobbs,
                CAST(closure.ending_hobbs AS DECIMAL(12,2)) AS ending_hobbs,
                CASE
                    WHEN closure.ending_hobbs IS NULL OR d.starting_hobbs IS NULL THEN NULL
                    ELSE ROUND(closure.ending_hobbs - d.starting_hobbs, 2)
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
            $logs[] = array(
                'flight_record_uuid' => (string)$row['workflow_flight_record_uuid'],
                'dispatch_uuid' => (string)$row['dispatch_uuid'],
                'aircraft_registration' => (string)$row['aircraft_registration'],
                'scheduled_date' => (string)$row['scheduled_date'],
                'departure_airport' => (string)$row['departure_airport'],
                'departure_time' => $row['departure_time'] !== null ? (string)$row['departure_time'] : null,
                'arrival_airport' => (string)$row['arrival_airport'],
                'arrival_time' => $row['arrival_time'] !== null ? (string)$row['arrival_time'] : null,
                'starting_hobbs' => $row['starting_hobbs'] !== null ? (float)$row['starting_hobbs'] : null,
                'ending_hobbs' => $row['ending_hobbs'] !== null ? (float)$row['ending_hobbs'] : null,
                'total_hobbs_time' => $row['total_hobbs_time'] !== null ? (float)$row['total_hobbs_time'] : null,
                'has_garmin_csv' => (bool)$row['has_garmin_csv'],
            );
        }
        return $logs;
    }
}
