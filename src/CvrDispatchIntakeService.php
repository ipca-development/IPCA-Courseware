<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';

final class CvrDispatchIntakeService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $device
     * @return array<string,mixed>
     */
    public function receive(array $payload, array $device): array
    {
        $this->requireSchema();
        $normalized = $this->normalizeAndValidate($payload, $device);
        $canonicalJson = AuditEventService::jsonEncode($this->canonicalize($normalized));
        $payloadSha256 = hash('sha256', $canonicalJson);
        $verifiedPayloadSha256 = $payloadSha256;
        $deviceId = (int)$device['id'];
        $organizationId = max(1, (int)($device['organization_id'] ?? 1));

        $this->pdo->beginTransaction();
        try {
            $dispatch = $this->lockDispatch($normalized['dispatch_uuid']);
            if (is_array($dispatch)) {
                if ((int)$dispatch['device_id'] !== $deviceId) {
                    throw new RuntimeException('Dispatch UUID is already owned by another CVR device.');
                }
                if (strtolower((string)$dispatch['workflow_flight_record_uuid']) !== $normalized['flight_record_uuid']) {
                    throw new RuntimeException('Dispatch UUID is already linked to another Flight Record.');
                }
                $dispatchId = (int)$dispatch['id'];
            } else {
                $dispatchId = $this->insertDispatch($normalized, $deviceId, $organizationId);
                $dispatch = $this->lockDispatch($normalized['dispatch_uuid']);
            }
            if (is_array($dispatch)) {
                $existingSchedulerId = trim((string)($dispatch['scheduler_record_id'] ?? ''));
                if ($existingSchedulerId !== '' && $existingSchedulerId !== $normalized['scheduler_record_id']) {
                    throw new RuntimeException('Dispatch UUID is already linked to another schedule slot.');
                }
                $this->claimScheduledSlot($normalized, $device);
            }

            $existingVersion = $this->dispatchVersion($dispatchId, $normalized['dispatch_version']);
            $alreadyPresent = is_array($existingVersion);
            if ($alreadyPresent) {
                if (!hash_equals((string)$existingVersion['payload_sha256'], $payloadSha256)) {
                    if (!$this->isRetryEquivalent(
                        (string)($existingVersion['payload_json'] ?? ''),
                        $canonicalJson
                    )) {
                        throw new RuntimeException('Dispatch version conflict: this version was already received with different content.');
                    }
                }
                $receiptUuid = (string)$existingVersion['receipt_uuid'];
                $verifiedPayloadSha256 = (string)$existingVersion['payload_sha256'];
            } else {
                $receiptUuid = AuditEventService::uuid();
                $this->insertVersion(
                    $dispatchId,
                    $normalized['dispatch_version'],
                    $receiptUuid,
                    $deviceId,
                    $payloadSha256,
                    $canonicalJson
                );
                $this->insertConsents($dispatchId, $normalized);
            }

            if ($normalized['dispatch_version'] >= (int)($dispatch['current_version'] ?? 0)) {
                $this->updateDispatchProjection($dispatchId, $normalized);
            } else {
                $this->pdo->prepare('UPDATE ipca_cvr_dispatches SET last_received_at = CURRENT_TIMESTAMP(3) WHERE id = ?')
                    ->execute(array($dispatchId));
            }

            if (!$alreadyPresent) {
                (new AuditEventService($this->pdo))->record(
                    'cvr_dispatch_received',
                    'ipca_cvr_dispatches',
                    $normalized['dispatch_uuid'],
                    null,
                    array(
                        'dispatch_version' => $normalized['dispatch_version'],
                        'flight_record_uuid' => $normalized['flight_record_uuid'],
                        'receipt_uuid' => $receiptUuid,
                        'payload_sha256' => $payloadSha256,
                    ),
                    'Authenticated CVR Dispatch received.',
                    'device',
                    null,
                    $deviceId,
                    null,
                    $organizationId,
                    'cvr_app'
                );
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return array(
            'ok' => true,
            'already_present' => $alreadyPresent,
            'dispatch' => array(
                'id' => $dispatchId,
                'dispatch_uuid' => $normalized['dispatch_uuid'],
                'dispatch_version' => $normalized['dispatch_version'],
                'flight_record_uuid' => $normalized['flight_record_uuid'],
                'status' => 'server_verified',
            ),
            'receipt' => array(
                'receipt_id' => $receiptUuid,
                'component_type' => 'dispatch_metadata',
                'payload_sha256' => $verifiedPayloadSha256,
                'server_verified_at' => gmdate('c'),
            ),
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $device
     * @return array<string,mixed>
     */
    private function normalizeAndValidate(array $payload, array $device): array
    {
        $dispatch = is_array($payload['dispatch'] ?? null) ? $payload['dispatch'] : array();
        $consents = is_array($payload['consents'] ?? null) ? array_values($payload['consents']) : array();
        $crew = is_array($dispatch['crew'] ?? null) ? array_values($dispatch['crew']) : array();

        $dispatchUuid = strtolower(trim((string)($dispatch['id'] ?? $payload['dispatch_uuid'] ?? '')));
        $flightRecordUuid = strtolower(trim((string)($payload['flight_record_uuid'] ?? '')));
        $dispatchVersion = (int)($dispatch['version'] ?? 0);
        $tailNumber = strtoupper(trim((string)($dispatch['tail_number'] ?? '')));
        $scheduledDate = substr(trim((string)($dispatch['scheduled_date'] ?? '')), 0, 10);
        $missionCode = trim((string)($dispatch['mission_code'] ?? ''));
        $aircraftId = isset($dispatch['aircraft_id']) ? (int)$dispatch['aircraft_id'] : null;
        $oilPercentage = isset($dispatch['oil_percentage']) ? (int)$dispatch['oil_percentage'] : null;
        $oilQuantity = isset($dispatch['oil_quantity']) && $dispatch['oil_quantity'] !== ''
            ? (float)$dispatch['oil_quantity']
            : null;
        $oilUnit = substr(trim((string)($dispatch['oil_unit'] ?? '')), 0, 16);
        $schedulerRecordId = strtolower(trim((string)($dispatch['scheduler_record_id'] ?? '')));
        $startingHobbs = isset($dispatch['starting_hobbs']) ? (float)$dispatch['starting_hobbs'] : null;
        $startingTacho = isset($dispatch['starting_tacho']) ? (float)$dispatch['starting_tacho'] : null;
        $fuelOnboard = trim((string)($dispatch['fuel_onboard'] ?? ''));

        if (!$this->isUuid($dispatchUuid) || !$this->isUuid($flightRecordUuid)) {
            throw new RuntimeException('Valid Dispatch and Flight Record UUIDs are required.');
        }
        if ($dispatchVersion <= 0) {
            throw new RuntimeException('Dispatch version is required.');
        }
        if ($scheduledDate === '' || DateTimeImmutable::createFromFormat('!Y-m-d', $scheduledDate) === false) {
            throw new RuntimeException('Valid Dispatch scheduled date is required.');
        }
        if ($missionCode === '' || $crew === array() || $startingHobbs === null || $startingTacho === null
            || $fuelOnboard === '' || ($oilPercentage === null && $oilQuantity === null)) {
            throw new RuntimeException('Dispatch is incomplete and cannot be synchronized.');
        }
        if (($oilPercentage !== null && ($oilPercentage < 0 || $oilPercentage > 100))
            || $oilQuantity !== null && ($oilQuantity < 0 || $oilUnit === '')
            || $startingHobbs < 0 || $startingTacho < 0) {
            throw new RuntimeException('Dispatch meter or oil values are invalid.');
        }
        if ($oilQuantity === null && $oilUnit !== '') {
            throw new RuntimeException('Oil quantity is required when an oil unit is provided.');
        }
        if ($schedulerRecordId !== '' && !$this->isUuid($schedulerRecordId)) {
            throw new RuntimeException('scheduler_record_id must be a valid UUID.');
        }

        $deviceTail = self::normalizeTailRegistration((string)($device['aircraft_registration'] ?? ''));
        $tailNumber = self::normalizeTailRegistration($tailNumber);
        if ($deviceTail === '' || $tailNumber === '' || $deviceTail !== $tailNumber) {
            throw new RuntimeException('Dispatch tail number does not match the enrolled CVR device.');
        }
        $deviceAircraftId = isset($device['aircraft_id']) ? (int)$device['aircraft_id'] : 0;
        if ($deviceAircraftId > 0 && ($aircraftId ?? 0) !== $deviceAircraftId) {
            throw new RuntimeException('Dispatch aircraft does not match the enrolled CVR device.');
        }

        $normalizedCrew = array();
        foreach ($crew as $member) {
            if (!is_array($member)) {
                throw new RuntimeException('Dispatch crew entry is invalid.');
            }
            $name = trim((string)($member['person_name'] ?? ''));
            $role = trim((string)($member['role'] ?? ''));
            if ($name === '' || $role === '' || $role === 'unknown') {
                throw new RuntimeException('Dispatch crew name and role are required.');
            }
            $normalizedCrew[] = array(
                'id' => strtolower(trim((string)($member['id'] ?? ''))),
                'person_id' => isset($member['person_id']) ? (int)$member['person_id'] : null,
                'person_name' => substr($name, 0, 255),
                'role' => substr($role, 0, 64),
            );
        }

        $normalizedConsents = array();
        foreach ($consents as $consent) {
            if (!is_array($consent)) {
                throw new RuntimeException('Dispatch consent entry is invalid.');
            }
            $consentUuid = strtolower(trim((string)($consent['id'] ?? '')));
            $consentDispatchUuid = strtolower(trim((string)($consent['dispatch_id'] ?? '')));
            $consentVersion = (int)($consent['dispatch_version'] ?? 0);
            $consentResult = filter_var($consent['consent_result'] ?? false, FILTER_VALIDATE_BOOL);
            if (!$this->isUuid($consentUuid) || $consentDispatchUuid !== $dispatchUuid || $consentVersion !== $dispatchVersion || !$consentResult) {
                throw new RuntimeException('Dispatch consent is invalid, declined, or stale.');
            }
            $normalizedConsents[] = array(
                'id' => $consentUuid,
                'person_id' => isset($consent['person_id']) ? (int)$consent['person_id'] : null,
                'person_name' => substr(trim((string)($consent['person_name'] ?? '')), 0, 255),
                'crew_role' => substr(trim((string)($consent['crew_role'] ?? '')), 0, 64),
                'consent_result' => true,
                'timestamp' => $this->normalizeTimestamp((string)($consent['timestamp'] ?? '')),
                'device_id' => substr(trim((string)($consent['device_id'] ?? '')), 0, 96),
                'dispatch_id' => $dispatchUuid,
                'dispatch_version' => $dispatchVersion,
                'consent_text_version' => substr(trim((string)($consent['consent_text_version'] ?? '')), 0, 96),
                'app_version' => substr(trim((string)($consent['app_version'] ?? '')), 0, 64),
            );
        }
        foreach ($normalizedCrew as $member) {
            $hasConsent = false;
            foreach ($normalizedConsents as $consent) {
                $samePerson = ($member['person_id'] !== null && $consent['person_id'] === $member['person_id'])
                    || ($member['person_id'] === null && strcasecmp($consent['person_name'], $member['person_name']) === 0);
                if ($samePerson && $consent['crew_role'] === $member['role']) {
                    $hasConsent = true;
                    break;
                }
            }
            if (!$hasConsent) {
                throw new RuntimeException('Accepted current-version consent is required for every crew member.');
            }
        }

        $refueled = filter_var($dispatch['refueled_since_previous_flight'] ?? false, FILTER_VALIDATE_BOOL);
        $oilServiced = filter_var($dispatch['oil_serviced_since_previous_flight'] ?? false, FILTER_VALIDATE_BOOL);
        $this->assertPreviousFlightContinuity(
            $dispatchUuid,
            $tailNumber,
            $startingHobbs,
            $startingTacho,
            $fuelOnboard,
            $oilPercentage,
            $oilQuantity,
            $oilUnit,
            $refueled,
            $oilServiced
        );

        return array(
            'dispatch_uuid' => $dispatchUuid,
            'flight_record_uuid' => $flightRecordUuid,
            'dispatch_version' => $dispatchVersion,
            'scheduled_date' => $scheduledDate,
            'scheduled_start_time' => $this->nullableTimestamp($dispatch['scheduled_start_time'] ?? null),
            'scheduled_end_time' => $this->nullableTimestamp($dispatch['scheduled_end_time'] ?? null),
            'aircraft_registration' => $tailNumber,
            'aircraft_id' => $aircraftId,
            'mission_code' => substr($missionCode, 0, 64),
            'planned_departure_airport' => substr(strtoupper(trim((string)($dispatch['planned_departure_airport'] ?? ''))), 0, 8),
            'planned_destination_airport' => substr(strtoupper(trim((string)($dispatch['planned_destination_airport'] ?? ''))), 0, 8),
            'crew' => $normalizedCrew,
            'starting_hobbs' => $startingHobbs,
            'starting_tacho' => $startingTacho,
            'fuel_onboard' => substr($fuelOnboard, 0, 64),
            'oil_percentage' => $oilPercentage,
            'oil_quantity' => $oilQuantity,
            'oil_unit' => $oilQuantity !== null ? $oilUnit : null,
            'dispatch_source' => substr(trim((string)($dispatch['dispatch_source'] ?? 'iphone_offline_local')), 0, 64),
            'scheduler_record_id' => $schedulerRecordId,
            'creator_identity' => substr(trim((string)($dispatch['creator_identity'] ?? '')), 0, 128),
            'created_at' => $this->normalizeTimestamp((string)($dispatch['created_at'] ?? '')),
            'modified_at' => $this->normalizeTimestamp((string)($dispatch['modified_at'] ?? '')),
            'consent_status' => substr(trim((string)($dispatch['consent_status'] ?? '')), 0, 64),
            'status' => substr(trim((string)($dispatch['status'] ?? '')), 0, 64),
            'cvr_unit_identifier' => substr(trim((string)($dispatch['configured_cvr_unit_id'] ?? '')), 0, 32),
            'beacon_identifier' => substr(trim((string)($dispatch['configured_beacon_id'] ?? '')), 0, 64),
            'previous_flight_record_id' => substr(strtolower(trim((string)($dispatch['previous_flight_record_id'] ?? ''))), 0, 36),
            'refueled_since_previous_flight' => $refueled,
            'oil_serviced_since_previous_flight' => $oilServiced,
            'consents' => $normalizedConsents,
        );
    }

    private function assertPreviousFlightContinuity(
        string $dispatchUuid,
        string $tailNumber,
        float $startingHobbs,
        float $startingTacho,
        string $fuelOnboard,
        ?int $oilPercentage,
        ?float $oilQuantity,
        string $oilUnit,
        bool $refueled,
        bool $oilServiced
    ): void {
        $statement = $this->pdo->prepare(
            'SELECT d.workflow_flight_record_uuid, c.ending_hobbs, c.ending_tacho,
                    c.fuel_remaining, c.oil_percentage, c.oil_quantity, c.oil_unit
             FROM ipca_cvr_dispatches d
             INNER JOIN ipca_cvr_flight_closures c ON c.id = (
               SELECT fc.id FROM ipca_cvr_flight_closures fc
               WHERE fc.workflow_flight_record_uuid = d.workflow_flight_record_uuid
               ORDER BY fc.received_at DESC, fc.id DESC LIMIT 1
             )
             WHERE d.aircraft_registration = ? AND d.dispatch_uuid <> ?
             ORDER BY c.received_at DESC, c.id DESC LIMIT 1'
        );
        $statement->execute(array($tailNumber, $dispatchUuid));
        $previous = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($previous)) {
            return;
        }
        if (abs($startingHobbs - (float)$previous['ending_hobbs']) > 0.1) {
            throw new RuntimeException(sprintf(
                'Hobbs discrepancy: previous crew-provided ending value was %.1f; verify the new starting value.',
                (float)$previous['ending_hobbs']
            ));
        }
        if (abs($startingTacho - (float)$previous['ending_tacho']) > 0.1) {
            throw new RuntimeException(sprintf(
                'Tacho discrepancy: previous crew-provided ending value was %.1f; verify the new starting value.',
                (float)$previous['ending_tacho']
            ));
        }
        $fuel = $this->numericQuantity($fuelOnboard);
        $previousFuel = $this->numericQuantity((string)$previous['fuel_remaining']);
        if ($fuel !== null && $previousFuel !== null && $this->relativeDifference($fuel, $previousFuel) > 0.20) {
            if ($fuel <= $previousFuel || !$refueled) {
                throw new RuntimeException(
                    $fuel > $previousFuel
                        ? 'Fuel differs by more than 20%; confirm that the aircraft was refueled.'
                        : 'Fuel is more than 20% below the previous ending quantity; refueling does not explain the discrepancy.'
                );
            }
        }
        $incomingOil = $oilQuantity;
        $previousOil = $previous['oil_quantity'] !== null ? (float)$previous['oil_quantity'] : null;
        $unitsMatch = $incomingOil !== null && $previousOil !== null
            && strcasecmp($oilUnit, trim((string)($previous['oil_unit'] ?? ''))) === 0;
        if (!$unitsMatch && $oilPercentage !== null && $previous['oil_percentage'] !== null) {
            $incomingOil = (float)$oilPercentage;
            $previousOil = (float)$previous['oil_percentage'];
            $unitsMatch = true;
        }
        if ($unitsMatch && $incomingOil !== null && $previousOil !== null
            && $this->relativeDifference($incomingOil, $previousOil) > 0.20) {
            if ($incomingOil <= $previousOil || !$oilServiced) {
                throw new RuntimeException(
                    $incomingOil > $previousOil
                        ? 'Oil differs by more than 20%; confirm that oil was serviced.'
                        : 'Oil is more than 20% below the previous ending quantity; servicing does not explain the discrepancy.'
                );
            }
        }
    }

    private function numericQuantity(string $value): ?float
    {
        $normalized = trim(str_ireplace('USG', '', $value));
        return $normalized !== '' && is_numeric($normalized) ? (float)$normalized : null;
    }

    private function relativeDifference(float $value, float $baseline): float
    {
        return abs($value - $baseline) / max(abs($baseline), 0.1);
    }

    /** @param array<string,mixed> $normalized @param array<string,mixed> $device */
    private function claimScheduledSlot(array $normalized, array $device): void
    {
        $schedulerRecordId = (string)$normalized['scheduler_record_id'];
        if ($schedulerRecordId === '') {
            return;
        }
        $statement = $this->pdo->prepare(
            'SELECT s.*, a.registration AS aircraft_registration
             FROM ipca_flight_schedule_slots s
             INNER JOIN ipca_aircraft_devices a ON a.id = s.aircraft_id
             WHERE s.scheduler_record_id = ? LIMIT 1 FOR UPDATE'
        );
        $statement->execute(array($schedulerRecordId));
        $slot = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($slot)) {
            throw new RuntimeException('Scheduled session does not exist.');
        }
        if (in_array((string)$slot['status'], array('cancelled', 'completed'), true)) {
            throw new RuntimeException('Scheduled session is not available for Dispatch.');
        }
        $claimedDispatch = strtolower(trim((string)($slot['claimed_dispatch_uuid'] ?? '')));
        if ($claimedDispatch !== '' && $claimedDispatch !== $normalized['dispatch_uuid']) {
            throw new RuntimeException('Scheduled session has already been claimed by another Dispatch.');
        }
        $deviceAircraftId = (int)($device['aircraft_id'] ?? 0);
        if ((int)$slot['aircraft_id'] !== $deviceAircraftId
            || (int)$slot['aircraft_id'] !== (int)($normalized['aircraft_id'] ?? 0)
            || self::normalizeTailRegistration((string)$slot['aircraft_registration']) !== $normalized['aircraft_registration']) {
            throw new RuntimeException('Scheduled session aircraft does not match the authenticated Dispatch aircraft.');
        }
        if ((string)$slot['scheduled_date'] !== $normalized['scheduled_date']) {
            throw new RuntimeException('Scheduled session date does not match the Dispatch.');
        }
        $slotMission = trim((string)($slot['mission_code'] ?? ''));
        if ($slotMission !== '' && strcasecmp($slotMission, (string)$normalized['mission_code']) !== 0) {
            throw new RuntimeException('Scheduled session mission does not match the Dispatch.');
        }
        foreach (array('scheduled_start_time', 'scheduled_end_time') as $field) {
            if ($normalized[$field] === null
                || strtotime((string)$normalized[$field]) !== strtotime((string)$slot[$field])) {
                throw new RuntimeException('Scheduled session times do not match the Dispatch.');
            }
        }
        foreach (array('planned_departure_airport', 'planned_destination_airport') as $field) {
            $scheduledAirport = strtoupper(trim((string)($slot[$field] ?? '')));
            if ($scheduledAirport !== '' && $scheduledAirport !== (string)$normalized[$field]) {
                throw new RuntimeException('Scheduled session airports do not match the Dispatch.');
            }
        }
        $crewStatement = $this->pdo->prepare(
            'SELECT user_id, person_name_snapshot, crew_role
             FROM ipca_flight_schedule_crew WHERE schedule_slot_id = ?'
        );
        $crewStatement->execute(array((int)$slot['id']));
        foreach ($crewStatement->fetchAll(PDO::FETCH_ASSOC) ?: array() as $scheduledCrew) {
            $matched = false;
            foreach ($normalized['crew'] as $dispatchCrew) {
                $samePerson = ($scheduledCrew['user_id'] !== null
                        && (int)$scheduledCrew['user_id'] === (int)($dispatchCrew['person_id'] ?? 0))
                    || ($scheduledCrew['user_id'] === null
                        && strcasecmp((string)$scheduledCrew['person_name_snapshot'], (string)$dispatchCrew['person_name']) === 0);
                if ($samePerson && strcasecmp((string)$scheduledCrew['crew_role'], (string)$dispatchCrew['role']) === 0) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                throw new RuntimeException('Scheduled session crew does not match the Dispatch.');
            }
        }
        $this->pdo->prepare(
            "UPDATE ipca_flight_schedule_slots
             SET status = 'claimed', claimed_dispatch_uuid = ?, claimed_at = COALESCE(claimed_at, CURRENT_TIMESTAMP(3))
             WHERE id = ?"
        )->execute(array($normalized['dispatch_uuid'], (int)$slot['id']));
    }

    /**
     * @param array<string,mixed> $normalized
     */
    private function insertDispatch(array $normalized, int $deviceId, int $organizationId): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_cvr_dispatches
              (dispatch_uuid, organization_id, device_id, workflow_flight_record_uuid, scheduler_record_id, current_version,
               aircraft_id, aircraft_registration, scheduled_date, mission_code, crew_json,
               starting_hobbs, starting_tacho, fuel_onboard, oil_percentage, oil_quantity, oil_unit, dispatch_source,
               consent_status, status, cvr_unit_identifier, beacon_identifier)
            VALUES
              (:dispatch_uuid, :organization_id, :device_id, :workflow_flight_record_uuid, :scheduler_record_id, :current_version,
               :aircraft_id, :aircraft_registration, :scheduled_date, :mission_code, :crew_json,
               :starting_hobbs, :starting_tacho, :fuel_onboard, :oil_percentage, :oil_quantity, :oil_unit, :dispatch_source,
               :consent_status, :status, :cvr_unit_identifier, :beacon_identifier)
        ");
        $stmt->execute(array(
            ':dispatch_uuid' => $normalized['dispatch_uuid'],
            ':organization_id' => $organizationId,
            ':device_id' => $deviceId,
            ':workflow_flight_record_uuid' => $normalized['flight_record_uuid'],
            ':scheduler_record_id' => $normalized['scheduler_record_id'] ?: null,
            ':current_version' => $normalized['dispatch_version'],
            ':aircraft_id' => $normalized['aircraft_id'],
            ':aircraft_registration' => $normalized['aircraft_registration'],
            ':scheduled_date' => $normalized['scheduled_date'],
            ':mission_code' => $normalized['mission_code'],
            ':crew_json' => AuditEventService::jsonEncode($normalized['crew']),
            ':starting_hobbs' => $normalized['starting_hobbs'],
            ':starting_tacho' => $normalized['starting_tacho'],
            ':fuel_onboard' => $normalized['fuel_onboard'],
            ':oil_percentage' => $normalized['oil_percentage'],
            ':oil_quantity' => $normalized['oil_quantity'],
            ':oil_unit' => $normalized['oil_unit'],
            ':dispatch_source' => $normalized['dispatch_source'],
            ':consent_status' => $normalized['consent_status'],
            ':status' => $normalized['status'],
            ':cvr_unit_identifier' => $normalized['cvr_unit_identifier'],
            ':beacon_identifier' => $normalized['beacon_identifier'],
        ));
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $normalized
     */
    private function updateDispatchProjection(int $dispatchId, array $normalized): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE ipca_cvr_dispatches
            SET workflow_flight_record_uuid = :workflow_flight_record_uuid,
                scheduler_record_id = :scheduler_record_id,
                current_version = :current_version,
                aircraft_id = :aircraft_id,
                aircraft_registration = :aircraft_registration,
                scheduled_date = :scheduled_date,
                mission_code = :mission_code,
                crew_json = :crew_json,
                starting_hobbs = :starting_hobbs,
                starting_tacho = :starting_tacho,
                fuel_onboard = :fuel_onboard,
                oil_percentage = :oil_percentage,
                oil_quantity = :oil_quantity,
                oil_unit = :oil_unit,
                dispatch_source = :dispatch_source,
                consent_status = :consent_status,
                status = :status,
                cvr_unit_identifier = :cvr_unit_identifier,
                beacon_identifier = :beacon_identifier,
                last_received_at = CURRENT_TIMESTAMP(3)
            WHERE id = :id
        ");
        $stmt->execute(array(
            ':workflow_flight_record_uuid' => $normalized['flight_record_uuid'],
            ':scheduler_record_id' => $normalized['scheduler_record_id'] ?: null,
            ':current_version' => $normalized['dispatch_version'],
            ':aircraft_id' => $normalized['aircraft_id'],
            ':aircraft_registration' => $normalized['aircraft_registration'],
            ':scheduled_date' => $normalized['scheduled_date'],
            ':mission_code' => $normalized['mission_code'],
            ':crew_json' => AuditEventService::jsonEncode($normalized['crew']),
            ':starting_hobbs' => $normalized['starting_hobbs'],
            ':starting_tacho' => $normalized['starting_tacho'],
            ':fuel_onboard' => $normalized['fuel_onboard'],
            ':oil_percentage' => $normalized['oil_percentage'],
            ':oil_quantity' => $normalized['oil_quantity'],
            ':oil_unit' => $normalized['oil_unit'],
            ':dispatch_source' => $normalized['dispatch_source'],
            ':consent_status' => $normalized['consent_status'],
            ':status' => $normalized['status'],
            ':cvr_unit_identifier' => $normalized['cvr_unit_identifier'],
            ':beacon_identifier' => $normalized['beacon_identifier'],
            ':id' => $dispatchId,
        ));
    }

    private function insertVersion(
        int $dispatchId,
        int $version,
        string $receiptUuid,
        int $deviceId,
        string $payloadSha256,
        string $payloadJson
    ): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_cvr_dispatch_versions
              (dispatch_id, dispatch_version, receipt_uuid, device_id, payload_sha256, payload_json)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute(array($dispatchId, $version, $receiptUuid, $deviceId, $payloadSha256, $payloadJson));
    }

    /**
     * @param array<string,mixed> $normalized
     */
    private function insertConsents(int $dispatchId, array $normalized): void
    {
        $select = $this->pdo->prepare('SELECT payload_json FROM ipca_cvr_dispatch_consents WHERE consent_uuid = ? LIMIT 1');
        $insert = $this->pdo->prepare("
            INSERT INTO ipca_cvr_dispatch_consents
              (consent_uuid, dispatch_id, dispatch_version, person_id, person_name, crew_role,
               consent_result, consented_at, source_device_uuid, consent_text_version, app_version, payload_json)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($normalized['consents'] as $consent) {
            $consentJson = AuditEventService::jsonEncode($this->canonicalize($consent));
            $select->execute(array($consent['id']));
            $existing = $select->fetchColumn();
            if ($existing !== false) {
                if (!hash_equals(hash('sha256', (string)$existing), hash('sha256', $consentJson))) {
                    throw new RuntimeException('Consent UUID conflict detected.');
                }
                continue;
            }
            $insert->execute(array(
                $consent['id'],
                $dispatchId,
                $normalized['dispatch_version'],
                $consent['person_id'],
                $consent['person_name'],
                $consent['crew_role'],
                1,
                $consent['timestamp'],
                $consent['device_id'],
                $consent['consent_text_version'],
                $consent['app_version'],
                $consentJson,
            ));
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function lockDispatch(string $dispatchUuid): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_cvr_dispatches WHERE dispatch_uuid = ? LIMIT 1 FOR UPDATE');
        $stmt->execute(array($dispatchUuid));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function isRetryEquivalent(string $existingJson, string $incomingJson): bool
    {
        $existing = json_decode($existingJson, true);
        $incoming = json_decode($incomingJson, true);
        if (!is_array($existing) || !is_array($incoming)) {
            return false;
        }

        // modified_at is device bookkeeping, not a material Dispatch change. Older
        // app builds rewrote it before every retry, including after the server had
        // accepted the request but the response was lost.
        unset($existing['modified_at'], $incoming['modified_at']);
        $existingCanonical = AuditEventService::jsonEncode($this->canonicalize($existing));
        $incomingCanonical = AuditEventService::jsonEncode($this->canonicalize($incoming));
        return hash_equals(
            hash('sha256', $existingCanonical),
            hash('sha256', $incomingCanonical)
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function dispatchVersion(int $dispatchId, int $version): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM ipca_cvr_dispatch_versions
            WHERE dispatch_id = ? AND dispatch_version = ?
            LIMIT 1
        ");
        $stmt->execute(array($dispatchId, $version));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function requireSchema(): void
    {
        foreach (array('ipca_cvr_dispatches', 'ipca_cvr_dispatch_versions', 'ipca_cvr_dispatch_consents') as $table) {
            $stmt = $this->pdo->query("SHOW TABLES LIKE " . $this->pdo->quote($table));
            if ($stmt === false || $stmt->fetchColumn() === false) {
                throw new RuntimeException('Dispatch intake schema is not installed.');
            }
        }
    }

    private function normalizeTimestamp(string $value): string
    {
        $timestamp = strtotime(trim($value));
        if ($timestamp === false) {
            throw new RuntimeException('Dispatch contains an invalid timestamp.');
        }
        return gmdate('Y-m-d H:i:s.v', $timestamp);
    }

    private function nullableTimestamp(mixed $value): ?string
    {
        $text = trim((string)$value);
        return $text === '' ? null : $this->normalizeTimestamp($text);
    }

    private function isUuid(string $value): bool
    {
        return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $value) === 1;
    }

    private static function normalizeTailRegistration(string $registration): string
    {
        return strtoupper((string)preg_replace('/[^A-Z0-9]/', '', trim($registration)));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }
}
