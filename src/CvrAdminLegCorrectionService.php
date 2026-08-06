<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';
require_once __DIR__ . '/CvrOperationalBlockTimeService.php';

/**
 * Admin corrections for a checked-in Operational Leg (dispatch + latest closure).
 */
final class CvrAdminLegCorrectionService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function save(int $dispatchId, array $input, ?int $actorUserId = null): array
    {
        if ($dispatchId <= 0) {
            throw new InvalidArgumentException('dispatch_id is required.');
        }

        $this->pdo->beginTransaction();
        try {
            $dispatchStmt = $this->pdo->prepare(
                'SELECT * FROM ipca_cvr_dispatches WHERE id = ? LIMIT 1 FOR UPDATE'
            );
            $dispatchStmt->execute(array($dispatchId));
            $beforeDispatch = $dispatchStmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($beforeDispatch)) {
                throw new RuntimeException('Dispatch leg not found.');
            }

            $flightUuid = strtolower(trim((string)($beforeDispatch['workflow_flight_record_uuid'] ?? '')));
            $mission = substr(trim((string)($input['mission_code'] ?? $beforeDispatch['mission_code'] ?? '')), 0, 64);
            $aircraft = strtoupper(substr(trim((string)($input['aircraft_registration'] ?? $beforeDispatch['aircraft_registration'] ?? '')), 0, 16));
            $departure = strtoupper(substr(trim((string)($input['departure_airport'] ?? '')), 0, 8));
            $arrival = strtoupper(substr(trim((string)($input['arrival_airport'] ?? '')), 0, 8));
            $startingHobbs = $this->nullableFloat($input['starting_hobbs'] ?? null);
            $endingHobbs = $this->nullableFloat($input['ending_hobbs'] ?? null);
            $startingTacho = $this->nullableFloat($input['starting_tacho'] ?? null);
            $endingTacho = $this->nullableFloat($input['ending_tacho'] ?? null);
            $fuelOnboard = substr(trim((string)($input['fuel_onboard'] ?? $input['fuel_departure'] ?? '')), 0, 64);
            $fuelRemaining = substr(trim((string)($input['fuel_remaining'] ?? $input['fuel_landing'] ?? '')), 0, 64);
            $oilPercentage = isset($input['oil_percentage']) && $input['oil_percentage'] !== ''
                ? (int)$input['oil_percentage']
                : null;
            $oilQuantity = $this->nullableFloat($input['oil_quantity'] ?? null);
            $oilUnit = substr(trim((string)($input['oil_unit'] ?? '')), 0, 16);
            if ($oilQuantity === null) {
                $oilUnit = null;
            }
            $takeoffs = max(0, (int)($input['takeoff_count'] ?? 0));
            $landings = max(0, (int)($input['landing_count'] ?? 0));
            $crew = $this->normalizeCrew($input['crew'] ?? ($input['crew_json'] ?? null));
            $offBlockLocal = trim((string)($input['off_block_local'] ?? ''));
            $timezone = trim((string)($input['timezone'] ?? 'America/Los_Angeles'));
            if ($timezone === '') {
                $timezone = 'America/Los_Angeles';
            }
            $offBlockUtc = $this->localToUtc($offBlockLocal, $timezone);

            $update = $this->pdo->prepare(
                'UPDATE ipca_cvr_dispatches
                 SET aircraft_registration = ?,
                     mission_code = ?,
                     crew_json = ?,
                     starting_hobbs = ?,
                     starting_tacho = ?,
                     fuel_onboard = ?,
                     oil_percentage = ?,
                     oil_quantity = ?,
                     oil_unit = ?,
                     updated_at = CURRENT_TIMESTAMP(3)
                 WHERE id = ?'
            );
            $update->execute(array(
                $aircraft,
                $mission,
                AuditEventService::jsonEncode($crew),
                $startingHobbs,
                $startingTacho,
                $fuelOnboard,
                $oilPercentage,
                $oilQuantity,
                $oilUnit,
                $dispatchId,
            ));

            $this->patchDispatchVersionAirports(
                $dispatchId,
                (int)($beforeDispatch['current_version'] ?? 1),
                $departure,
                $arrival
            );

            $onBlockUtc = null;
            if ($offBlockUtc !== null && $startingHobbs !== null && $endingHobbs !== null) {
                $onBlockUtc = (new CvrOperationalBlockTimeService())->derivedOnBlockUtc(array(
                    'off_block_utc' => $offBlockUtc,
                    'starting_hobbs' => $startingHobbs,
                    'ending_hobbs' => $endingHobbs,
                ));
            }

            if ($flightUuid !== '') {
                $this->upsertClosureEvidence(
                    $flightUuid,
                    $endingHobbs,
                    $endingTacho,
                    $fuelRemaining,
                    $takeoffs,
                    $landings,
                    $offBlockUtc,
                    $onBlockUtc,
                    $oilPercentage,
                    $oilQuantity,
                    $oilUnit,
                    trim((string)($input['maintenance_remark'] ?? ''))
                );

                if ($offBlockUtc !== null) {
                    $this->ensureEngineStartEvent($flightUuid, $offBlockUtc);
                }
            }

            $afterStmt = $this->pdo->prepare('SELECT * FROM ipca_cvr_dispatches WHERE id = ? LIMIT 1');
            $afterStmt->execute(array($dispatchId));
            $afterDispatch = $afterStmt->fetch(PDO::FETCH_ASSOC) ?: array();

            try {
                (new AuditEventService($this->pdo))->record(
                    'admin_operational_leg_correction',
                    'ipca_cvr_dispatches',
                    (string)$dispatchId,
                    $beforeDispatch,
                    is_array($afterDispatch) ? $afterDispatch : null,
                    'Master Logbook admin correction',
                    'user',
                    $actorUserId
                );
            } catch (Throwable) {
                // Audit table may be unavailable in some environments.
            }

            $this->pdo->commit();
            return array(
                'dispatch_id' => $dispatchId,
                'workflow_flight_record_uuid' => $flightUuid,
                'off_block_utc' => $offBlockUtc,
                'on_block_utc' => $onBlockUtc,
            );
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function patchDispatchVersionAirports(int $dispatchId, int $version, string $departure, string $arrival): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, payload_json FROM ipca_cvr_dispatch_versions
             WHERE dispatch_id = ? AND dispatch_version = ? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(array($dispatchId, $version));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return;
        }
        $payload = json_decode((string)($row['payload_json'] ?? '{}'), true);
        if (!is_array($payload)) {
            $payload = array();
        }
        $payload['planned_departure_airport'] = $departure;
        $payload['planned_destination_airport'] = $arrival;
        $update = $this->pdo->prepare('UPDATE ipca_cvr_dispatch_versions SET payload_json = ? WHERE id = ?');
        $update->execute(array(AuditEventService::jsonEncode($payload), (int)$row['id']));
    }

    private function upsertClosureEvidence(
        string $flightUuid,
        ?float $endingHobbs,
        ?float $endingTacho,
        string $fuelRemaining,
        int $takeoffs,
        int $landings,
        ?string $offBlockUtc,
        ?string $onBlockUtc,
        ?int $oilPercentage,
        ?float $oilQuantity,
        ?string $oilUnit,
        string $maintenanceRemark
    ): void {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ipca_cvr_flight_closures
             WHERE LOWER(workflow_flight_record_uuid) = ?
             ORDER BY id DESC LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(array($flightUuid));
        $closure = $stmt->fetch(PDO::FETCH_ASSOC);
        $payload = array();
        if (is_array($closure)) {
            $decoded = json_decode((string)($closure['payload_json'] ?? '{}'), true);
            $payload = is_array($decoded) ? $decoded : array();
        }
        if (!isset($payload['evidence']) || !is_array($payload['evidence'])) {
            $payload['evidence'] = array();
        }
        $payload['evidence']['verified_takeoff_count'] = $takeoffs;
        $payload['evidence']['verified_landing_count'] = $landings;
        if ($offBlockUtc !== null) {
            $payload['evidence']['off_block_utc'] = $offBlockUtc;
            $payload['off_block_utc'] = $offBlockUtc;
        }
        if ($onBlockUtc !== null) {
            $payload['evidence']['on_block_utc'] = $onBlockUtc;
            $payload['evidence']['on_block_source'] = 'off_block_plus_hobbs_increment';
            $payload['on_block_utc'] = $onBlockUtc;
            $payload['on_block_source'] = 'off_block_plus_hobbs_increment';
        }
        $json = AuditEventService::jsonEncode($payload);
        $hash = hash('sha256', $json);

        if (is_array($closure)) {
            $update = $this->pdo->prepare(
                'UPDATE ipca_cvr_flight_closures
                 SET ending_hobbs = ?, ending_tacho = ?, fuel_remaining = ?,
                     oil_percentage = ?, oil_quantity = ?, oil_unit = ?,
                     maintenance_remark = ?, payload_sha256 = ?, payload_json = ?
                 WHERE id = ?'
            );
            $update->execute(array(
                $endingHobbs,
                $endingTacho,
                $fuelRemaining !== '' ? $fuelRemaining : null,
                $oilPercentage,
                $oilQuantity,
                $oilUnit,
                $maintenanceRemark !== '' ? $maintenanceRemark : null,
                $hash,
                $json,
                (int)$closure['id'],
            ));
            return;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO ipca_cvr_flight_closures
             (closure_uuid, batch_id, workflow_flight_record_uuid, ending_hobbs, ending_tacho,
              fuel_remaining, oil_percentage, oil_quantity, oil_unit, maintenance_remark, payload_sha256, payload_json)
             VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute(array(
            AuditEventService::uuid(),
            $flightUuid,
            $endingHobbs,
            $endingTacho,
            $fuelRemaining !== '' ? $fuelRemaining : null,
            $oilPercentage,
            $oilQuantity,
            $oilUnit,
            $maintenanceRemark !== '' ? $maintenanceRemark : null,
            $hash,
            $json,
        ));
    }

    private function ensureEngineStartEvent(string $flightUuid, string $offBlockUtc): void
    {
        $exists = $this->pdo->prepare(
            "SELECT id FROM ipca_cvr_flight_events
             WHERE LOWER(workflow_flight_record_uuid) = ?
               AND event_type = 'engine_start_off_block'
             ORDER BY id ASC LIMIT 1"
        );
        $exists->execute(array($flightUuid));
        $row = $exists->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $this->pdo->prepare('UPDATE ipca_cvr_flight_events SET timestamp_utc = ? WHERE id = ?')
                ->execute(array($offBlockUtc, (int)$row['id']));
            return;
        }
        try {
            $this->pdo->prepare(
                "INSERT INTO ipca_cvr_flight_events
                 (event_uuid, workflow_flight_record_uuid, event_type, timestamp_utc, creation_method, payload_json)
                 VALUES (?, ?, 'engine_start_off_block', ?, 'admin_correction', ?)"
            )->execute(array(
                AuditEventService::uuid(),
                $flightUuid,
                $offBlockUtc,
                AuditEventService::jsonEncode(array('source' => 'master_logbook_admin')),
            ));
        } catch (Throwable) {
            // Schema variants may differ; Off Block still lives on closure evidence.
        }
    }

    /**
     * @param mixed $raw
     * @return list<array{role:string,personName:string}>
     */
    private function normalizeCrew(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : array();
        }
        if (!is_array($raw)) {
            return array();
        }
        $out = array();
        foreach ($raw as $member) {
            if (!is_array($member)) {
                continue;
            }
            $name = trim((string)($member['personName'] ?? $member['person_name'] ?? $member['name'] ?? ''));
            $role = strtolower(trim((string)($member['role'] ?? $member['crew_role'] ?? '')));
            if ($name === '') {
                continue;
            }
            $out[] = array(
                'role' => $role,
                'personName' => $name,
            );
        }
        return $out;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            throw new InvalidArgumentException('Numeric meter/fuel values are required.');
        }
        return (float)$value;
    }

    private function localToUtc(string $local, string $timezone): ?string
    {
        $local = trim($local);
        if ($local === '') {
            return null;
        }
        try {
            $tz = new DateTimeZone($timezone);
            // Accept "Y-m-d H:i" or "Y-m-d\TH:i"
            $normalized = str_replace('T', ' ', $local);
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $normalized)) {
                $normalized .= ':00';
            }
            $dt = new DateTimeImmutable($normalized, $tz);
            return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
        } catch (Throwable $e) {
            throw new InvalidArgumentException('Off Block local time is invalid.');
        }
    }
}
