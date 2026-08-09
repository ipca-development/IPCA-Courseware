<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';
require_once __DIR__ . '/CvrSyncException.php';
require_once __DIR__ . '/CvrOperationalIdentityService.php';
require_once __DIR__ . '/CvrDutyAssignmentIdentityService.php';
require_once __DIR__ . '/FlightSessionService.php';

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
        $canonicalPayload = $this->canonicalPayload($payload, $device);
        $normalized = $canonicalPayload['normalized'];
        $continuityWarnings = $canonicalPayload['continuity_warnings'];
        $canonicalJson = $canonicalPayload['payload_json'];
        $payloadSha256 = $canonicalPayload['payload_sha256'];
        $verifiedPayloadSha256 = $payloadSha256;
        $deviceId = (int)$device['id'];
        $organizationId = max(1, (int)($device['organization_id'] ?? 1));

        $this->pdo->beginTransaction();
        try {
            $dispatch = $this->lockDispatch($normalized['dispatch_uuid']);
            if (is_array($dispatch)) {
                if (strtolower(trim((string)($dispatch['status'] ?? ''))) === 'released') {
                    throw new CvrUserCorrectionRequired('This Dispatch was undispatched. Create a new Dispatch on the device.');
                }
                if ((int)$dispatch['device_id'] !== $deviceId) {
                    throw new CvrTechnicalReviewRequired('Dispatch identity requires technical review.');
                }
                if (strtolower((string)$dispatch['workflow_flight_record_uuid']) !== $normalized['flight_record_uuid']) {
                    throw new CvrTechnicalReviewRequired('Dispatch identity requires technical review.');
                }
                $dispatchId = (int)$dispatch['id'];
            } else {
                $dispatchId = $this->insertDispatch($normalized, $deviceId, $organizationId);
                $dispatch = $this->lockDispatch($normalized['dispatch_uuid']);
            }
            if (is_array($dispatch)) {
                $existingSchedulerId = trim((string)($dispatch['scheduler_record_id'] ?? ''));
                if ($existingSchedulerId !== '' && $existingSchedulerId !== $normalized['scheduler_record_id']) {
                    throw new CvrTechnicalReviewRequired('Dispatch schedule linkage requires technical review.');
                }
                $this->claimScheduledSlot($normalized, $device);
                $this->ingestCanonicalDutyIdentity($normalized, $organizationId);
                $this->ingestOperationalSession($normalized, $device);
            }

            $existingVersion = $this->dispatchVersion($dispatchId, $normalized['dispatch_version']);
            $alreadyPresent = is_array($existingVersion);
            if ($alreadyPresent) {
                if (!hash_equals((string)$existingVersion['payload_sha256'], $payloadSha256)) {
                    if (!$this->isRetryEquivalent(
                        (string)($existingVersion['payload_json'] ?? ''),
                        $canonicalJson
                    )) {
                        throw new CvrTechnicalReviewRequired('Dispatch version conflict requires technical review.');
                    }
                }
                $receiptUuid = (string)$existingVersion['receipt_uuid'];
                $verifiedPayloadSha256 = (string)$existingVersion['payload_sha256'];
                $receivedAt = (string)$existingVersion['received_at'];
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
                $insertedVersion = $this->dispatchVersion($dispatchId, $normalized['dispatch_version']);
                if (!is_array($insertedVersion)) {
                    throw new CvrTemporaryTechnicalFailure('Dispatch receipt metadata is temporarily unavailable.');
                }
                $receivedAt = (string)$insertedVersion['received_at'];
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
                        'continuity_warnings' => $continuityWarnings,
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

        $fuelUplift = null;
        if (!$alreadyPresent) {
            try {
                require_once __DIR__ . '/AircraftFuelStateService.php';
                $normalizedForFuel = $normalized;
                if ((int)($normalizedForFuel['aircraft_id'] ?? 0) <= 0) {
                    $normalizedForFuel['aircraft_id'] = (int)($device['aircraft_id'] ?? 0);
                }
                $rawDispatch = is_array($payload['dispatch'] ?? null) ? $payload['dispatch'] : array();
                $fuelUplift = (new AircraftFuelStateService($this->pdo))
                    ->createUpliftFromDispatchIfNeeded($normalizedForFuel, $rawDispatch);
            } catch (Throwable $e) {
                error_log('[CvrDispatchIntake] fuel uplift auto-create failed: ' . $e->getMessage());
            }
        }

        require_once __DIR__ . '/CvrAutoReconstructionOrchestrator.php';
        CvrAutoReconstructionOrchestrator::safeConsider(
            $this->pdo,
            $normalized['flight_record_uuid'] ?? null,
            null,
            $dispatchId,
            null
        );

        $response = array(
            'ok' => true,
            'error_code' => $alreadyPresent ? 'DUPLICATE_ALREADY_VERIFIED' : null,
            'error' => null,
            'retryable' => false,
            'user_action_required' => false,
            'already_present' => $alreadyPresent,
            'receipt_id' => $receiptUuid,
            'server_dispatch_id' => $dispatchId,
            'dispatch_uuid' => $normalized['dispatch_uuid'],
            'dispatch_version' => $normalized['dispatch_version'],
            'flight_record_uuid' => $normalized['flight_record_uuid'],
            'operational_session_uuid' => $normalized['operational_session_uuid'] ?: null,
            'payload_sha256' => $verifiedPayloadSha256,
            'received_at' => $receivedAt,
            'canonical_identifiers' => array(
                'server_dispatch_id' => (string)$dispatchId,
                'dispatch_uuid' => $normalized['dispatch_uuid'],
                'dispatch_version' => (string)$normalized['dispatch_version'],
                'flight_record_uuid' => $normalized['flight_record_uuid'],
                'operational_session_uuid' => $normalized['operational_session_uuid'] ?: null,
            ),
            'continuity_warnings' => $continuityWarnings,
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
                'server_verified_at' => $receivedAt,
            ),
        );
        if (is_array($fuelUplift)) {
            $response['fuel_uplift'] = array(
                'created' => true,
                'uplift_uuid' => (string)($fuelUplift['uplift_uuid'] ?? ''),
                'fuel_after_usg' => isset($fuelUplift['fuel_after_usg']) ? (float)$fuelUplift['fuel_after_usg'] : null,
            );
        }
        return $response;
    }

    /**
     * The single authoritative Dispatch canonicalization path used by intake and reconciliation.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $device
     * @return array{
     *   normalized:array<string,mixed>,
     *   continuity_warnings:list<mixed>,
     *   payload_json:string,
     *   payload_sha256:string
     * }
     */
    public function canonicalPayload(array $payload, array $device): array
    {
        $normalized = $this->normalizeAndValidate($payload, $device);
        $continuityWarnings = is_array($normalized['continuity_warnings'] ?? null)
            ? array_values($normalized['continuity_warnings'])
            : array();
        unset($normalized['continuity_warnings']);
        if ($normalized['scheduler_record_id'] === '') {
            $normalized['scheduler_record_id'] = $this->resolveUnambiguousScheduledRecordId($normalized);
        }
        $canonicalJson = AuditEventService::jsonEncode($this->canonicalize($normalized));
        return array(
            'normalized' => $normalized,
            'continuity_warnings' => $continuityWarnings,
            'payload_json' => $canonicalJson,
            'payload_sha256' => hash('sha256', $canonicalJson),
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
        $operationalIdentity = is_array($dispatch['operational_identity'] ?? null)
            ? $dispatch['operational_identity']
            : array();
        $reservationUuid = strtolower(trim((string)(
            $dispatch['reservation_uuid'] ?? $operationalIdentity['reservation_uuid'] ?? ''
        )));
        $legUuid = strtolower(trim((string)(
            $dispatch['leg_uuid'] ?? $operationalIdentity['leg_uuid'] ?? ''
        )));
        $operationalSessionUuid = strtolower(trim((string)(
            $dispatch['operational_session_uuid'] ?? $payload['operational_session_uuid'] ?? ''
        )));
        $sessionModelVersion = strtolower(trim((string)(
            $dispatch['session_model_version'] ?? $payload['session_model_version'] ?? ''
        )));
        $isOperationalSession = $sessionModelVersion === FlightSessionService::MODEL_OPERATIONAL_V1;
        $startingHobbs = isset($dispatch['starting_hobbs']) ? (float)$dispatch['starting_hobbs'] : null;
        $startingTacho = isset($dispatch['starting_tacho']) ? (float)$dispatch['starting_tacho'] : null;
        $fuelOnboard = trim((string)($dispatch['fuel_onboard'] ?? ''));

        if (!$this->isUuid($dispatchUuid) || !$this->isUuid($flightRecordUuid)) {
            throw new CvrUserCorrectionRequired('Valid Dispatch and Flight Record UUIDs are required.');
        }
        if ($dispatchVersion <= 0) {
            throw new CvrUserCorrectionRequired('Dispatch version is required.');
        }
        if ($scheduledDate === '' || DateTimeImmutable::createFromFormat('!Y-m-d', $scheduledDate) === false) {
            throw new CvrUserCorrectionRequired('Valid Dispatch scheduled date is required.');
        }
        if ($missionCode === '' || $crew === array() || $startingHobbs === null || $startingTacho === null
            || $fuelOnboard === '' || ($oilPercentage === null && $oilQuantity === null)) {
            throw new CvrUserCorrectionRequired('Dispatch is incomplete and cannot be synchronized.');
        }
        if (($oilPercentage !== null && ($oilPercentage < 0 || $oilPercentage > 100))
            || $oilQuantity !== null && ($oilQuantity < 0 || $oilUnit === '')
            || $startingHobbs < 0 || $startingTacho < 0) {
            throw new CvrUserCorrectionRequired('Dispatch meter or oil values are invalid.');
        }
        if ($oilQuantity === null && $oilUnit !== '') {
            throw new CvrUserCorrectionRequired('Oil quantity is required when an oil unit is provided.');
        }
        if ($schedulerRecordId !== '' && !$this->isUuid($schedulerRecordId)) {
            throw new CvrUserCorrectionRequired('scheduler_record_id must be a valid UUID.');
        }
        if ($isOperationalSession) {
            if (!$this->isUuid($reservationUuid) || !$this->isUuid($operationalSessionUuid)) {
                throw new CvrUserCorrectionRequired(
                    'Operational Session Dispatch requires valid reservation_uuid and operational_session_uuid values.'
                );
            }
            if ($legUuid !== '') {
                throw new CvrUserCorrectionRequired('Operational Session Dispatch must not create an actual leg identity.');
            }
        } elseif (($reservationUuid === '') !== ($legUuid === '')
            || ($reservationUuid !== '' && (!$this->isUuid($reservationUuid) || !$this->isUuid($legUuid)))) {
            throw new CvrUserCorrectionRequired(
                'Dispatch operational identity requires valid reservation_uuid and leg_uuid values.'
            );
        }

        $deviceTail = self::normalizeTailRegistration((string)($device['aircraft_registration'] ?? ''));
        $tailNumber = self::normalizeTailRegistration($tailNumber);
        if ($deviceTail === '' || $tailNumber === '' || $deviceTail !== $tailNumber) {
            throw new CvrUserCorrectionRequired('Dispatch tail number does not match the enrolled CVR device.');
        }
        $deviceAircraftId = isset($device['aircraft_id']) ? (int)$device['aircraft_id'] : 0;
        if ($deviceAircraftId > 0 && ($aircraftId ?? 0) !== $deviceAircraftId) {
            throw new CvrUserCorrectionRequired('Dispatch aircraft does not match the enrolled CVR device.');
        }

        $normalizedCrew = array();
        foreach ($crew as $member) {
            if (!is_array($member)) {
                throw new CvrUserCorrectionRequired('Dispatch crew entry is invalid.');
            }
            $name = trim((string)($member['person_name'] ?? ''));
            $role = trim((string)($member['role'] ?? ''));
            try {
                $pilotFunction = CvrDutyAssignmentIdentityService::normalizePilotFunction(
                    (string)($member['pilot_function'] ?? $member['pilotFunction'] ?? 'NONE')
                );
            } catch (InvalidArgumentException) {
                throw new CvrUserCorrectionRequired('Dispatch crew pilot function is invalid.');
            }
            if ($name === '' || $role === '' || $role === 'unknown') {
                throw new CvrUserCorrectionRequired('Dispatch crew name and role are required.');
            }
            $normalizedCrew[] = array(
                'id' => strtolower(trim((string)($member['id'] ?? ''))),
                'person_id' => isset($member['person_id']) ? (int)$member['person_id'] : null,
                'person_name' => substr($name, 0, 255),
                'role' => substr($role, 0, 64),
                'pilot_function' => $pilotFunction,
                'is_pic' => (bool)($member['is_pic'] ?? $member['isPIC'] ?? false)
                    || strtolower($role) === 'pic',
                'is_primary_customer' => (bool)($member['is_primary_customer'] ?? false),
            );
        }

        $normalizedConsents = array();
        foreach ($consents as $consent) {
            if (!is_array($consent)) {
                throw new CvrUserCorrectionRequired('Dispatch consent entry is invalid.');
            }
            $consentUuid = strtolower(trim((string)($consent['id'] ?? '')));
            $consentDispatchUuid = strtolower(trim((string)($consent['dispatch_id'] ?? '')));
            $consentVersion = (int)($consent['dispatch_version'] ?? 0);
            $consentResult = filter_var($consent['consent_result'] ?? false, FILTER_VALIDATE_BOOL);
            if (!$this->isUuid($consentUuid) || $consentDispatchUuid !== $dispatchUuid || $consentVersion !== $dispatchVersion || !$consentResult) {
                throw new CvrUserCorrectionRequired('Dispatch consent is invalid, declined, or stale.');
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
                throw new CvrUserCorrectionRequired('Accepted current-version consent is required for every crew member.');
            }
        }

        $refueled = filter_var($dispatch['refueled_since_previous_flight'] ?? false, FILTER_VALIDATE_BOOL);
        $oilServiced = filter_var($dispatch['oil_serviced_since_previous_flight'] ?? false, FILTER_VALIDATE_BOOL);
        $continuityWarnings = $this->previousFlightContinuityWarnings(
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
            'reservation_uuid' => $reservationUuid,
            'leg_uuid' => $legUuid,
            'operational_session_uuid' => $operationalSessionUuid,
            'session_model_version' => $sessionModelVersion,
            'operational_identity' => $operationalIdentity,
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
            'continuity_warnings' => $continuityWarnings,
            'consents' => $normalizedConsents,
        );
    }

    /** @return list<string> */
    private function previousFlightContinuityWarnings(
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
    ): array {
        $warnings = array();
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
            return $warnings;
        }
        if (abs($startingHobbs - (float)$previous['ending_hobbs']) > 0.1) {
            $warnings[] = sprintf(
                'Hobbs discrepancy: previous crew-provided ending value was %.1f; verify the new starting value.',
                (float)$previous['ending_hobbs']
            );
        }
        if (abs($startingTacho - (float)$previous['ending_tacho']) > 0.1) {
            $warnings[] = sprintf(
                'Tacho discrepancy: previous crew-provided ending value was %.1f; verify the new starting value.',
                (float)$previous['ending_tacho']
            );
        }
        $fuel = $this->numericQuantity($fuelOnboard);
        $previousFuel = $this->numericQuantity((string)$previous['fuel_remaining']);
        if ($fuel !== null && $previousFuel !== null && $this->relativeDifference($fuel, $previousFuel) > 0.20) {
            if ($fuel <= $previousFuel || !$refueled) {
                $warnings[] =
                    $fuel > $previousFuel
                        ? 'Fuel differs by more than 20%; confirm that the aircraft was refueled.'
                        : 'Fuel is more than 20% below the previous ending quantity; refueling does not explain the discrepancy.';
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
                $warnings[] =
                    $incomingOil > $previousOil
                        ? 'Oil differs by more than 20%; confirm that oil was serviced.'
                        : 'Oil is more than 20% below the previous ending quantity; servicing does not explain the discrepancy.';
            }
        }
        return $warnings;
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
            throw new CvrDependencyNotReady('Scheduled session linkage is not available yet.');
        }
        $claimedDispatch = strtolower(trim((string)($slot['claimed_dispatch_uuid'] ?? '')));
        $incomingDispatch = strtolower(trim((string)$normalized['dispatch_uuid']));
        $siblingClaim = $claimedDispatch !== ''
            && $claimedDispatch !== $incomingDispatch
            && $this->isSiblingLegDispatchForSlot($slot, $claimedDispatch, $incomingDispatch);

        if ((string)$slot['status'] === 'completed' && $claimedDispatch === $incomingDispatch) {
            return;
        }
        if ((string)$slot['status'] === 'cancelled') {
            throw new CvrUserCorrectionRequired('Scheduled session is not available for Dispatch.');
        }
        // Multi-leg: first leg may mark the slot completed while later legs still upload.
        if ((string)$slot['status'] === 'completed' && !$siblingClaim) {
            throw new CvrUserCorrectionRequired('Scheduled session is not available for Dispatch.');
        }
        if ($claimedDispatch !== '' && $claimedDispatch !== $incomingDispatch && !$siblingClaim) {
            throw new CvrTechnicalReviewRequired('Scheduled session linkage requires technical review.');
        }
        $deviceAircraftId = (int)($device['aircraft_id'] ?? 0);
        if ((int)$slot['aircraft_id'] !== $deviceAircraftId
            || (int)$slot['aircraft_id'] !== (int)($normalized['aircraft_id'] ?? 0)
            || self::normalizeTailRegistration((string)$slot['aircraft_registration']) !== $normalized['aircraft_registration']) {
            throw new CvrUserCorrectionRequired('Scheduled session aircraft does not match the authenticated Dispatch aircraft.');
        }
        if ((string)$slot['scheduled_date'] !== $normalized['scheduled_date']) {
            throw new CvrUserCorrectionRequired('Scheduled session date does not match the Dispatch.');
        }
        $slotMission = trim((string)($slot['mission_code'] ?? ''));
        if ($slotMission !== '' && strcasecmp($slotMission, (string)$normalized['mission_code']) !== 0) {
            throw new CvrUserCorrectionRequired('Scheduled session mission does not match the Dispatch.');
        }
        if (($normalized['session_model_version'] ?? '') !== FlightSessionService::MODEL_OPERATIONAL_V1
            && !$this->dispatchAirportsMatchScheduledPlan($slot, $normalized)) {
            throw new CvrUserCorrectionRequired('Scheduled session airports do not match the Dispatch.');
        }
        $hasDutyColumns = $this->scheduleCrewDutyColumnsAvailable();
        $crewStatement = $this->pdo->prepare($hasDutyColumns
            ? 'SELECT user_id, person_name_snapshot, crew_role, pilot_function, is_pic,
                      1 AS duty_columns_present
               FROM ipca_flight_schedule_crew WHERE schedule_slot_id = ?'
            : 'SELECT user_id, person_name_snapshot, crew_role, \'NONE\' AS pilot_function,
                      0 AS is_pic, 0 AS duty_columns_present
             FROM ipca_flight_schedule_crew WHERE schedule_slot_id = ?'
        );
        $crewStatement->execute(array((int)$slot['id']));
        foreach ($crewStatement->fetchAll(PDO::FETCH_ASSOC) ?: array() as $scheduledCrew) {
            if (!$this->dispatchCrewMatchesScheduledMember($normalized['crew'], $scheduledCrew)) {
                throw new CvrUserCorrectionRequired('Scheduled session crew does not match the Dispatch.');
            }
        }
        if ($siblingClaim) {
            // Keep the original claim owner; later legs share the same scheduled reservation.
            return;
        }
        $this->pdo->prepare(
            "UPDATE ipca_flight_schedule_slots
             SET status = CASE
                   WHEN EXISTS(
                     SELECT 1 FROM ipca_cvr_flight_closures closure_record
                     WHERE closure_record.workflow_flight_record_uuid = ?
                   ) THEN 'completed'
                   ELSE 'claimed'
                 END,
                 claimed_dispatch_uuid = ?,
                 claimed_at = COALESCE(claimed_at, CURRENT_TIMESTAMP(3))
             WHERE id = ?"
        )->execute(array(
            $normalized['flight_record_uuid'],
            $normalized['dispatch_uuid'],
            (int)$slot['id'],
        ));
    }

    /**
     * True when another Dispatch already claimed this slot for a different leg of the same reservation.
     *
     * @param array<string,mixed> $slot
     */
    private function isSiblingLegDispatchForSlot(array $slot, string $claimedDispatchUuid, string $incomingDispatchUuid): bool
    {
        $schedulerId = strtolower(trim((string)($slot['scheduler_record_id'] ?? '')));
        if ($schedulerId === '' || $claimedDispatchUuid === '' || $incomingDispatchUuid === '') {
            return false;
        }
        $statement = $this->pdo->prepare(
            "SELECT LOWER(TRIM(dispatch_uuid)) AS dispatch_uuid,
                    LOWER(TRIM(COALESCE(scheduler_record_id, ''))) AS scheduler_record_id
             FROM ipca_cvr_dispatches
             WHERE LOWER(TRIM(dispatch_uuid)) IN (?, ?)"
        );
        $statement->execute(array($claimedDispatchUuid, $incomingDispatchUuid));
        $byUuid = array();
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row) {
            $byUuid[(string)$row['dispatch_uuid']] = (string)$row['scheduler_record_id'];
        }
        $claimedScheduler = $byUuid[$claimedDispatchUuid] ?? '';
        $incomingScheduler = $byUuid[$incomingDispatchUuid] ?? '';
        // Incoming may not be inserted yet when claim runs during first insert — fall back to slot id.
        if ($incomingScheduler === '') {
            $incomingScheduler = $schedulerId;
        }
        return $claimedScheduler !== ''
            && $claimedScheduler === $schedulerId
            && $incomingScheduler === $schedulerId;
    }

    /**
     * Accept either the slot first→last route or any planned hop from canonical legs.
     * Device sessions expand multi-leg slots per hop, so A→B and B→C must both claim A→C slots.
     *
     * @param array<string,mixed> $slot
     * @param array<string,mixed> $normalized
     */
    private function dispatchAirportsMatchScheduledPlan(array $slot, array $normalized): bool
    {
        $dispatchDeparture = strtoupper(trim((string)($normalized['planned_departure_airport'] ?? '')));
        $dispatchDestination = strtoupper(trim((string)($normalized['planned_destination_airport'] ?? '')));
        $hops = $this->scheduledAirportHops($slot);
        if ($hops === array()) {
            return true;
        }
        foreach ($hops as $hop) {
            $scheduledDeparture = (string)($hop['departure'] ?? '');
            $scheduledDestination = (string)($hop['destination'] ?? '');
            $departureOk = $scheduledDeparture === '' || $scheduledDeparture === $dispatchDeparture;
            $destinationOk = $scheduledDestination === '' || $scheduledDestination === $dispatchDestination;
            if ($departureOk && $destinationOk) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,mixed> $slot
     * @return list<array{departure:string,destination:string}>
     */
    private function scheduledAirportHops(array $slot): array
    {
        $hops = array();
        $seen = array();
        $append = static function (string $departure, string $destination) use (&$hops, &$seen): void {
            $departure = strtoupper(trim($departure));
            $destination = strtoupper(trim($destination));
            if ($departure === '' && $destination === '') {
                return;
            }
            $key = $departure . '>' . $destination;
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $hops[] = array(
                'departure' => $departure,
                'destination' => $destination,
            );
        };

        try {
            require_once __DIR__ . '/CvrOperationalIdentityService.php';
            $identity = new CvrOperationalIdentityService($this->pdo);
            $organizationId = max(1, (int)($slot['organization_id'] ?? 1));
            $schedulerId = strtolower(trim((string)($slot['scheduler_record_id'] ?? '')));
            $alias = null;
            if ($schedulerId !== '') {
                $alias = $identity->findAlias($organizationId, 'schedule', 'scheduler_record_id', $schedulerId, null);
            }
            if (!is_array($alias) && (int)($slot['id'] ?? 0) > 0) {
                $alias = $identity->findAlias(
                    $organizationId,
                    'schedule',
                    'schedule_slot_id',
                    (string)(int)$slot['id'],
                    null
                );
            }
            $reservationUuid = is_array($alias) ? strtolower(trim((string)($alias['reservation_uuid'] ?? ''))) : '';
            if ($reservationUuid !== '' && strtolower(trim((string)($alias['target_type'] ?? ''))) === 'reservation') {
                foreach ($identity->listLegsForReservation($reservationUuid) as $leg) {
                    if (!is_array($leg)) {
                        continue;
                    }
                    $append(
                        (string)($leg['origin_airport'] ?? ''),
                        (string)($leg['destination_airport'] ?? '')
                    );
                }
            }
        } catch (Throwable) {
            // Identity is additive; fall through to slot first→last airports.
        }

        $append(
            (string)($slot['planned_departure_airport'] ?? ''),
            (string)($slot['planned_destination_airport'] ?? '')
        );

        return $hops;
    }

    /**
     * Match scheduled crew to Dispatch crew by person id when both are present,
     * otherwise by normalized name. Role must still match.
     *
     * @param list<array<string,mixed>> $dispatchCrew
     * @param array<string,mixed> $scheduledCrew
     */
    private function dispatchCrewMatchesScheduledMember(array $dispatchCrew, array $scheduledCrew): bool
    {
        $scheduledUserId = isset($scheduledCrew['user_id']) && $scheduledCrew['user_id'] !== '' && $scheduledCrew['user_id'] !== null
            ? (int)$scheduledCrew['user_id']
            : 0;
        $scheduledName = strtolower(trim((string)($scheduledCrew['person_name_snapshot'] ?? '')));
        $scheduledRole = $this->normalizedRoleToken((string)($scheduledCrew['crew_role'] ?? ''));
        $scheduledPilotFunction = CvrDutyAssignmentIdentityService::normalizePilotFunction(
            (string)($scheduledCrew['pilot_function'] ?? 'NONE')
        );
        $scheduledIsPic = (bool)($scheduledCrew['is_pic'] ?? false);
        $compareDutyFunctions = (bool)($scheduledCrew['duty_columns_present'] ?? false);
        foreach ($dispatchCrew as $member) {
            if (!is_array($member)) {
                continue;
            }
            $dispatchRole = $this->normalizedRoleToken((string)($member['role'] ?? ''));
            if ($scheduledRole !== '' && $dispatchRole !== $scheduledRole) {
                continue;
            }
            if ($compareDutyFunctions
                && (
                    CvrDutyAssignmentIdentityService::normalizePilotFunction(
                        (string)($member['pilot_function'] ?? 'NONE')
                    ) !== $scheduledPilotFunction
                    || (bool)($member['is_pic'] ?? false) !== $scheduledIsPic
                )) {
                continue;
            }
            $dispatchPersonId = isset($member['person_id']) ? (int)$member['person_id'] : 0;
            if ($scheduledUserId > 0 && $dispatchPersonId > 0 && $scheduledUserId === $dispatchPersonId) {
                return true;
            }
            $dispatchName = strtolower(trim((string)($member['person_name'] ?? '')));
            if ($scheduledName !== '' && $dispatchName !== '' && $scheduledName === $dispatchName) {
                return true;
            }
        }
        return false;
    }

    private function normalizedRoleToken(string $value): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '', trim($value)) ?? '');
    }

    private function scheduleCrewDutyColumnsAvailable(): bool
    {
        try {
            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $names = array();
                $stmt = $this->pdo->query("PRAGMA table_info('ipca_flight_schedule_crew')");
                foreach ($stmt?->fetchAll(PDO::FETCH_ASSOC) ?: array() as $column) {
                    $names[] = strtolower((string)($column['name'] ?? ''));
                }
                return in_array('pilot_function', $names, true) && in_array('is_pic', $names, true);
            }
            foreach (array('pilot_function', 'is_pic') as $column) {
                $stmt = $this->pdo->query(
                    'SHOW COLUMNS FROM ipca_flight_schedule_crew LIKE ' . $this->pdo->quote($column)
                );
                if ($stmt === false || $stmt->fetchColumn() === false) {
                    return false;
                }
            }
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string,mixed> $normalized */
    private function resolveUnambiguousScheduledRecordId(array $normalized): string
    {
        $statement = $this->pdo->prepare(
            "SELECT s.scheduler_record_id
             FROM ipca_flight_schedule_slots s
             WHERE s.aircraft_id = ?
               AND s.scheduled_date = ?
               AND s.status = 'scheduled'
               AND (s.claimed_dispatch_uuid IS NULL OR s.claimed_dispatch_uuid = '')
               AND (s.mission_code = '' OR UPPER(s.mission_code) = UPPER(?))
               AND (s.planned_departure_airport = '' OR UPPER(s.planned_departure_airport) = UPPER(?))
               AND (s.planned_destination_airport = '' OR UPPER(s.planned_destination_airport) = UPPER(?))
             ORDER BY s.scheduled_start_time, s.id
             LIMIT 2"
        );
        $statement->execute(array(
            (int)$normalized['aircraft_id'],
            (string)$normalized['scheduled_date'],
            (string)$normalized['mission_code'],
            (string)$normalized['planned_departure_airport'],
            (string)$normalized['planned_destination_airport'],
        ));
        $matches = $statement->fetchAll(PDO::FETCH_COLUMN) ?: array();
        return count($matches) === 1 ? (string)$matches[0] : '';
    }

    /**
     * @param array<string,mixed> $normalized
     */
    private function insertDispatch(array $normalized, int $deviceId, int $organizationId): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_cvr_dispatches
              (dispatch_uuid, organization_id, device_id, workflow_flight_record_uuid, operational_session_uuid, scheduler_record_id, current_version,
               aircraft_id, aircraft_registration, scheduled_date, mission_code, crew_json,
               starting_hobbs, starting_tacho, fuel_onboard, oil_percentage, oil_quantity, oil_unit, dispatch_source,
               consent_status, status, cvr_unit_identifier, beacon_identifier)
            VALUES
              (:dispatch_uuid, :organization_id, :device_id, :workflow_flight_record_uuid, :operational_session_uuid, :scheduler_record_id, :current_version,
               :aircraft_id, :aircraft_registration, :scheduled_date, :mission_code, :crew_json,
               :starting_hobbs, :starting_tacho, :fuel_onboard, :oil_percentage, :oil_quantity, :oil_unit, :dispatch_source,
               :consent_status, :status, :cvr_unit_identifier, :beacon_identifier)
        ");
        $stmt->execute(array(
            ':dispatch_uuid' => $normalized['dispatch_uuid'],
            ':organization_id' => $organizationId,
            ':device_id' => $deviceId,
            ':workflow_flight_record_uuid' => $normalized['flight_record_uuid'],
            ':operational_session_uuid' => $normalized['operational_session_uuid'] ?: null,
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
                operational_session_uuid = COALESCE(operational_session_uuid, :operational_session_uuid),
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
            ':operational_session_uuid' => $normalized['operational_session_uuid'] ?: null,
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
                    throw new CvrTechnicalReviewRequired('Consent identity conflict requires technical review.');
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

    public function isRetryEquivalent(string $existingJson, string $incomingJson): bool
    {
        $existing = json_decode($existingJson, true);
        $incoming = json_decode($incomingJson, true);
        if (!is_array($existing) || !is_array($incoming)) {
            return false;
        }

        // modified_at is device bookkeeping, not a material Dispatch change. Older
        // app builds rewrote it before every retry, including after the server had
        // accepted the request but the response was lost. scheduler_record_id may
        // be added by safe server reconciliation when an offline archive lost the
        // original scheduled-session link.
        unset(
            $existing['modified_at'],
            $incoming['modified_at'],
            $existing['scheduler_record_id'],
            $incoming['scheduler_record_id']
        );
        $existing = $this->withDutyRetryDefaults($existing);
        $incoming = $this->withDutyRetryDefaults($incoming);
        $existingCanonical = AuditEventService::jsonEncode($this->canonicalize($existing));
        $incomingCanonical = AuditEventService::jsonEncode($this->canonicalize($incoming));
        return hash_equals(
            hash('sha256', $existingCanonical),
            hash('sha256', $incomingCanonical)
        );
    }

    /** @param array<string,mixed> $candidate @return array<string,mixed> */
    private function withDutyRetryDefaults(array $candidate): array
    {
        $candidate['reservation_uuid'] = (string)($candidate['reservation_uuid'] ?? '');
        $candidate['leg_uuid'] = (string)($candidate['leg_uuid'] ?? '');
        $candidate['operational_identity'] = is_array($candidate['operational_identity'] ?? null)
            ? $candidate['operational_identity']
            : array();
        if (is_array($candidate['crew'] ?? null)) {
            foreach ($candidate['crew'] as &$member) {
                if (is_array($member)) {
                    $member['pilot_function'] = (string)($member['pilot_function'] ?? 'NONE');
                        $member['is_pic'] = (bool)($member['is_pic'] ?? false);
                    $member['is_primary_customer'] = (bool)($member['is_primary_customer'] ?? false);
                }
            }
            unset($member);
        }
        return $candidate;
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

    /**
     * Persist device-supplied canonical identity and enforce the immutable duty snapshot.
     *
     * @param array<string,mixed> $normalized
     */
    private function ingestCanonicalDutyIdentity(array $normalized, int $organizationId): void
    {
        $reservationUuid = strtolower(trim((string)($normalized['reservation_uuid'] ?? '')));
        $legUuid = strtolower(trim((string)($normalized['leg_uuid'] ?? '')));
        $isOperationalSession = (string)($normalized['session_model_version'] ?? '')
            === FlightSessionService::MODEL_OPERATIONAL_V1;
        $duty = new CvrDutyAssignmentIdentityService($this->pdo);
        if ($reservationUuid === '' || (!$isOperationalSession && $legUuid === '')) {
            $schedulerRecordId = strtolower(trim((string)($normalized['scheduler_record_id'] ?? '')));
            if ($duty->isEnforcementEnabled()
                && $schedulerRecordId !== ''
                && $duty->snapshotForReservation($schedulerRecordId) !== null) {
                throw new CvrUserCorrectionRequired(
                    'This scheduled Dispatch requires canonical reservation and segment identity.'
                );
            }
            return;
        }

        $identity = new CvrOperationalIdentityService($this->pdo);
        if (!$identity->isFlagEnabled(CvrOperationalIdentityService::FLAG_CANONICAL_WRITE)) {
            if ($duty->isEnforcementEnabled()) {
                throw new CvrTechnicalReviewRequired(
                    'Canonical Duty Assignment intake is not enabled on the server.'
                );
            }
            return;
        }

        $operational = is_array($normalized['operational_identity'] ?? null)
            ? $normalized['operational_identity']
            : array();
        $reservation = $identity->findReservationByUuid($reservationUuid);
        $createdReservation = false;
        if ($reservation === null) {
            $reservation = $identity->createReservation(array(
                'reservation_uuid' => $reservationUuid,
                'organization_id' => $organizationId,
                'organization_timezone_iana' => (string)($operational['organization_timezone_iana'] ?? 'UTC'),
                'reservation_type' => (string)($operational['reservation_type'] ?? 'flight_training'),
                'activity_domain' => (string)($operational['activity_domain'] ?? 'flight'),
                    'status' => $isOperationalSession ? 'scheduled' : 'active',
                'source' => 'ios_offline',
                'adoption_source_system' => 'ios_cvr',
                'adoption_provenance' => array(
                    'linkage_method' => 'offline_create',
                    'dispatch_uuid' => (string)$normalized['dispatch_uuid'],
                ),
            ), true);
            $createdReservation = true;
        }
        if ((int)($reservation['organization_id'] ?? 0) !== $organizationId) {
            throw new CvrTechnicalReviewRequired('Reservation organization identity requires review.');
        }

        if (!$isOperationalSession) {
            $leg = $identity->findLegByUuid($legUuid);
            if ($leg === null) {
                $existingLegs = $identity->listLegsForReservation($reservationUuid);
                $identity->createFlightLeg(array(
                    'leg_uuid' => $legUuid,
                    'reservation_uuid' => $reservationUuid,
                    'organization_id' => $organizationId,
                    'sequence_number' => count($existingLegs) + 1,
                    'origin_airport' => (string)($operational['origin_airport'] ?? $normalized['planned_departure_airport']),
                    'destination_airport' => (string)($operational['destination_airport'] ?? $normalized['planned_destination_airport']),
                    'organization_timezone_iana' => (string)($operational['organization_timezone_iana'] ?? $reservation['organization_timezone_iana']),
                    'status' => 'dispatched',
                    'source' => 'ios_offline',
                ), true);
            } elseif ((string)($leg['reservation_uuid'] ?? '') !== $reservationUuid) {
                throw new CvrTechnicalReviewRequired('Segment identity belongs to another reservation.');
            }
        }

        foreach (array(
            array('dispatch_uuid', (string)$normalized['dispatch_uuid'], (string)$normalized['dispatch_version']),
            array('workflow_flight_record_uuid', (string)$normalized['flight_record_uuid'], null),
        ) as [$aliasType, $aliasValue, $aliasVersion]) {
            $identity->createAlias(array(
                'organization_id' => $organizationId,
                'source_system' => 'ios_cvr',
                'alias_type' => $aliasType,
                'alias_value' => $aliasValue,
                'alias_version' => $aliasVersion,
                'target_type' => $isOperationalSession ? 'reservation' : 'leg',
                'reservation_uuid' => $isOperationalSession ? $reservationUuid : null,
                'leg_uuid' => $isOperationalSession ? null : $legUuid,
                'confidence_state' => 'VERIFIED',
                'linkage_method' => 'offline_create',
            ), true);
        }

        $dutyInput = array(
            'organization_id' => $organizationId,
            'aircraft_device_id' => (int)($normalized['aircraft_id'] ?? 0),
            'aircraft_registration' => (string)($normalized['aircraft_registration'] ?? ''),
            'reservation_type' => (string)($reservation['reservation_type'] ?? 'flight_training'),
            'activity_domain' => (string)($reservation['activity_domain'] ?? 'flight'),
            'training_assignment_category' => (string)($reservation['reservation_type'] ?? 'flight_training'),
            'mission_code' => (string)($normalized['mission_code'] ?? ''),
            'crew' => is_array($normalized['crew'] ?? null) ? $normalized['crew'] : array(),
            'source' => 'ios_offline',
        );
        $existingDuty = $duty->snapshotForReservation($reservationUuid);
        if ($createdReservation && $existingDuty === null && $duty->isSnapshotWriteEnabled()) {
            $duty->writeSnapshot($reservationUuid, $dutyInput);
            $existingDuty = $duty->snapshotForReservation($reservationUuid);
        }
        if ($existingDuty !== null) {
            $duty->assertDispatchMatches($reservationUuid, $normalized);
        }
    }

    /** @param array<string,mixed> $normalized @param array<string,mixed> $device */
    private function ingestOperationalSession(array $normalized, array $device): void
    {
        if ((string)($normalized['session_model_version'] ?? '')
            !== FlightSessionService::MODEL_OPERATIONAL_V1) {
            return;
        }
        $fuelText = trim((string)($normalized['fuel_onboard'] ?? ''));
        $fuelQuantity = is_numeric($fuelText)
            ? (float)$fuelText
            : (preg_match('/^-?[0-9]+(?:\.[0-9]+)?/', $fuelText, $match) === 1
                ? (float)$match[0]
                : null);
        (new FlightSessionService($this->pdo))->createOperationalSession($device, array(
            'operational_session_uuid' => (string)$normalized['operational_session_uuid'],
            'reservation_uuid' => (string)$normalized['reservation_uuid'],
            'dispatch_uuid' => (string)$normalized['dispatch_uuid'],
            'workflow_flight_record_uuid' => (string)$normalized['flight_record_uuid'],
            'dispatch_confirmed_at_utc' => (string)$normalized['modified_at'],
            'starting_hobbs' => $normalized['starting_hobbs'],
            'starting_tacho' => $normalized['starting_tacho'],
            'starting_fuel_quantity' => $fuelQuantity,
            'starting_fuel_unit' => 'USG',
            'starting_oil_quantity' => $normalized['oil_quantity'] ?? $normalized['oil_percentage'],
            'starting_oil_unit' => $normalized['oil_quantity'] !== null
                ? ($normalized['oil_unit'] ?? null)
                : ($normalized['oil_percentage'] !== null ? 'PERCENT' : null),
        ));
    }

    private function requireSchema(): void
    {
        foreach (array('ipca_cvr_dispatches', 'ipca_cvr_dispatch_versions', 'ipca_cvr_dispatch_consents') as $table) {
            $stmt = $this->pdo->query("SHOW TABLES LIKE " . $this->pdo->quote($table));
            if ($stmt === false || $stmt->fetchColumn() === false) {
                throw new CvrTechnicalReviewRequired('Dispatch synchronization requires a server deployment review.');
            }
        }
    }

    private function normalizeTimestamp(string $value): string
    {
        $timestamp = strtotime(trim($value));
        if ($timestamp === false) {
            throw new CvrUserCorrectionRequired('Dispatch contains an invalid timestamp.');
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
