<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';
require_once __DIR__ . '/CvrOperationalBlockTimeService.php';
require_once __DIR__ . '/CvrOperationalLegTimelineService.php';

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

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
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
            $oilUnit = substr(trim((string)($input['oil_unit'] ?? '')), 0, 16);
            $oilValueRaw = $input['oil_value'] ?? null;
            $oilPercentage = null;
            $oilQuantity = null;
            if ($oilUnit === '%' || strcasecmp($oilUnit, 'percent') === 0 || strcasecmp($oilUnit, 'percentage') === 0) {
                $oilUnit = '%';
                if ($oilValueRaw !== null && $oilValueRaw !== '') {
                    $oilPercentage = (int)round((float)$oilValueRaw);
                } elseif (isset($input['oil_percentage']) && $input['oil_percentage'] !== '') {
                    $oilPercentage = (int)$input['oil_percentage'];
                }
            } else {
                if ($oilValueRaw !== null && $oilValueRaw !== '') {
                    $oilQuantity = $this->oneDecimal($oilValueRaw);
                } else {
                    $oilQuantity = $this->oneDecimal($input['oil_quantity'] ?? null);
                }
                if ($oilQuantity === null) {
                    $oilUnit = null;
                }
            }
            $startingHobbs = $this->meterTenth($input['starting_hobbs'] ?? null);
            $endingHobbs = $this->meterTenth($input['ending_hobbs'] ?? null);
            $startingTacho = $this->meterTenth($input['starting_tacho'] ?? null);
            $endingTacho = $this->meterTenth($input['ending_tacho'] ?? null);
            if ($startingHobbs !== null && $endingHobbs !== null && $endingHobbs < $startingHobbs) {
                throw new InvalidArgumentException('Hobbs End cannot be lower than Hobbs Start.');
            }
            if ($startingTacho !== null && $endingTacho !== null && $endingTacho < $startingTacho) {
                throw new InvalidArgumentException('Tacho End cannot be lower than Tacho Start.');
            }
            $fuelOnboard = $this->formatFuel($input['fuel_onboard'] ?? $input['fuel_departure'] ?? '');
            $fuelRemaining = $this->formatFuel($input['fuel_remaining'] ?? $input['fuel_landing'] ?? '');
            if ($fuelOnboard !== '' && $fuelRemaining !== ''
                && is_numeric($fuelOnboard) && is_numeric($fuelRemaining)
                && (float)$fuelRemaining > (float)$fuelOnboard) {
                throw new InvalidArgumentException('Landing fuel cannot exceed departure fuel.');
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

                // iOS Flight Log prefers this adjustment row over planned dispatch airports.
                $this->upsertFlightLogAdjustment(
                    $beforeDispatch,
                    $flightUuid,
                    $departure,
                    $arrival,
                    $crew,
                    $startingHobbs,
                    $startingTacho,
                    $fuelOnboard,
                    $endingHobbs,
                    $endingTacho,
                    $fuelRemaining
                );
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

            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            if ($flightUuid !== '') {
                try {
                    require_once __DIR__ . '/MasterLogbookLogbookProposalService.php';
                    (new MasterLogbookLogbookProposalService($this->pdo))
                        ->createProposalsForFlightRecord($flightUuid);
                } catch (Throwable $e) {
                    error_log('[CvrAdminLegCorrection] logbook proposal create failed: ' . $e->getMessage());
                }
            }

            return array(
                'dispatch_id' => $dispatchId,
                'workflow_flight_record_uuid' => $flightUuid,
                'off_block_utc' => $offBlockUtc,
                'on_block_utc' => $onBlockUtc,
            );
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Persist per-leg meters, fuel, and TO/LDG for one sibling Dispatch.
     *
     * @param array{
     *   starting_hobbs?:mixed,
     *   ending_hobbs?:mixed,
     *   starting_tacho?:mixed,
     *   ending_tacho?:mixed,
     *   fuel_onboard?:mixed,
     *   fuel_remaining?:mixed,
     *   takeoff_count?:mixed,
     *   landing_count?:mixed
     * } $fields
     */
    public function saveSegmentOperationalValues(
        int $dispatchId,
        array $fields,
        ?int $actorUserId = null
    ): void {
        if ($dispatchId <= 0) {
            throw new InvalidArgumentException('dispatch_id is required.');
        }
        $startingHobbs = array_key_exists('starting_hobbs', $fields)
            ? $this->meterTenth($fields['starting_hobbs'] ?? null)
            : null;
        $endingHobbs = array_key_exists('ending_hobbs', $fields)
            ? $this->meterTenth($fields['ending_hobbs'] ?? null)
            : null;
        $startingTacho = array_key_exists('starting_tacho', $fields)
            ? $this->meterTenth($fields['starting_tacho'] ?? null)
            : null;
        $endingTacho = array_key_exists('ending_tacho', $fields)
            ? $this->meterTenth($fields['ending_tacho'] ?? null)
            : null;
        $fuelOnboard = array_key_exists('fuel_onboard', $fields)
            ? $this->formatFuel($fields['fuel_onboard'] ?? '')
            : null;
        $fuelRemaining = array_key_exists('fuel_remaining', $fields)
            ? $this->formatFuel($fields['fuel_remaining'] ?? '')
            : null;
        $takeoffs = array_key_exists('takeoff_count', $fields)
            ? max(0, (int)$fields['takeoff_count'])
            : null;
        $landings = array_key_exists('landing_count', $fields)
            ? max(0, (int)$fields['landing_count'])
            : null;

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $dispatchStmt = $this->pdo->prepare(
                'SELECT * FROM ipca_cvr_dispatches WHERE id = ? LIMIT 1 FOR UPDATE'
            );
            $dispatchStmt->execute(array($dispatchId));
            $dispatch = $dispatchStmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($dispatch)) {
                throw new RuntimeException('Dispatch leg not found.');
            }
            $flightUuid = strtolower(trim((string)($dispatch['workflow_flight_record_uuid'] ?? '')));
            if ($flightUuid === '') {
                throw new RuntimeException('Dispatch is missing a Flight Record UUID.');
            }

            $closureStmt = $this->pdo->prepare(
                'SELECT * FROM ipca_cvr_flight_closures
                 WHERE LOWER(workflow_flight_record_uuid) = ?
                 ORDER BY id DESC LIMIT 1 FOR UPDATE'
            );
            $closureStmt->execute(array($flightUuid));
            $closure = $closureStmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($closure)) {
                throw new RuntimeException('Meter and fuel edits require a completed Check-In for this leg.');
            }

            if ($startingHobbs === null) {
                $startingHobbs = $this->meterTenth($dispatch['starting_hobbs'] ?? null);
            }
            if ($startingTacho === null) {
                $startingTacho = $this->meterTenth($dispatch['starting_tacho'] ?? null);
            }
            if ($fuelOnboard === null) {
                $fuelOnboard = $this->formatFuel($dispatch['fuel_onboard'] ?? '');
            }
            if ($endingHobbs === null) {
                $endingHobbs = $this->meterTenth($closure['ending_hobbs'] ?? null);
            }
            if ($endingTacho === null) {
                $endingTacho = $this->meterTenth($closure['ending_tacho'] ?? null);
            }
            if ($fuelRemaining === null) {
                $fuelRemaining = $this->formatFuel($closure['fuel_remaining'] ?? '');
            }
            if ($takeoffs === null || $landings === null) {
                $closurePayload = json_decode((string)($closure['payload_json'] ?? '{}'), true);
                $evidence = is_array($closurePayload['evidence'] ?? null) ? $closurePayload['evidence'] : array();
                if ($takeoffs === null) {
                    $takeoffs = max(0, (int)($evidence['verified_takeoff_count'] ?? 0));
                }
                if ($landings === null) {
                    $landings = max(0, (int)($evidence['verified_landing_count'] ?? 0));
                }
            }
            if ($startingHobbs !== null && $endingHobbs !== null && $endingHobbs < $startingHobbs) {
                throw new InvalidArgumentException('Hobbs End cannot be lower than Hobbs Start.');
            }
            if ($startingTacho !== null && $endingTacho !== null && $endingTacho < $startingTacho) {
                throw new InvalidArgumentException('Tacho End cannot be lower than Tacho Start.');
            }
            if ($fuelOnboard !== '' && $fuelRemaining !== ''
                && is_numeric($fuelOnboard) && is_numeric($fuelRemaining)
                && (float)$fuelRemaining > (float)$fuelOnboard) {
                throw new InvalidArgumentException('Landing fuel cannot exceed departure fuel.');
            }

            $updateDispatch = $this->pdo->prepare(
                'UPDATE ipca_cvr_dispatches
                 SET starting_hobbs = ?,
                     starting_tacho = ?,
                     fuel_onboard = ?,
                     updated_at = CURRENT_TIMESTAMP(3)
                 WHERE id = ?'
            );
            $updateDispatch->execute(array(
                $startingHobbs,
                $startingTacho,
                $fuelOnboard !== '' ? $fuelOnboard : null,
                $dispatchId,
            ));

            $payload = json_decode((string)($closure['payload_json'] ?? '{}'), true);
            if (!is_array($payload)) {
                $payload = array();
            }
            if (!isset($payload['evidence']) || !is_array($payload['evidence'])) {
                $payload['evidence'] = array();
            }
            $payload['evidence']['verified_takeoff_count'] = $takeoffs;
            $payload['evidence']['verified_landing_count'] = $landings;
            $json = AuditEventService::jsonEncode($payload);
            $hash = hash('sha256', $json);
            $updateClosure = $this->pdo->prepare(
                'UPDATE ipca_cvr_flight_closures
                 SET ending_hobbs = ?, ending_tacho = ?, fuel_remaining = ?,
                     payload_sha256 = ?, payload_json = ?
                 WHERE id = ?'
            );
            $updateClosure->execute(array(
                $endingHobbs,
                $endingTacho,
                $fuelRemaining !== '' ? $fuelRemaining : null,
                $hash,
                $json,
                (int)$closure['id'],
            ));

            $departure = strtoupper(trim((string)($fields['departure_airport'] ?? '')));
            $arrival = strtoupper(trim((string)($fields['arrival_airport'] ?? '')));
            if ($departure !== '' && $arrival !== '') {
                $this->upsertFlightLogAdjustment(
                    $dispatch,
                    $flightUuid,
                    $departure,
                    $arrival,
                    array(),
                    $startingHobbs,
                    $startingTacho,
                    $fuelOnboard,
                    $endingHobbs,
                    $endingTacho,
                    $fuelRemaining
                );
            }

            try {
                (new AuditEventService($this->pdo))->record(
                    'admin_operational_leg_segment_values',
                    'ipca_cvr_dispatches',
                    (string)$dispatchId,
                    null,
                    array(
                        'starting_hobbs' => $startingHobbs,
                        'ending_hobbs' => $endingHobbs,
                        'starting_tacho' => $startingTacho,
                        'ending_tacho' => $endingTacho,
                        'fuel_onboard' => $fuelOnboard,
                        'fuel_remaining' => $fuelRemaining,
                        'takeoff_count' => $takeoffs,
                        'landing_count' => $landings,
                        'workflow_flight_record_uuid' => $flightUuid,
                    ),
                    'Master Logbook per-leg meter/fuel/ops correction',
                    'user',
                    $actorUserId
                );
            } catch (Throwable) {
            }

            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Update verified takeoff/landing counts on one Dispatch's flight closure only.
     * @deprecated prefer saveSegmentOperationalValues for full per-leg edits
     */
    public function saveOperationCounts(
        int $dispatchId,
        int $takeoffs,
        int $landings,
        ?int $actorUserId = null
    ): void {
        $this->saveSegmentOperationalValues($dispatchId, array(
            'takeoff_count' => $takeoffs,
            'landing_count' => $landings,
        ), $actorUserId);
    }

    /**
     * Keep annotated payload leg_segments operational fields in sync.
     *
     * @param list<array<string,mixed>> $segments
     */
    public function patchLegSegmentOperationalFields(int $dispatchId, array $segments): void
    {
        if ($dispatchId <= 0 || $segments === array()) {
            return;
        }
        $dispatchStmt = $this->pdo->prepare(
            'SELECT id, current_version FROM ipca_cvr_dispatches WHERE id = ? LIMIT 1'
        );
        $dispatchStmt->execute(array($dispatchId));
        $dispatch = $dispatchStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($dispatch)) {
            return;
        }
        $version = (int)($dispatch['current_version'] ?? 1);
        $stmt = $this->pdo->prepare(
            'SELECT id, payload_json FROM ipca_cvr_dispatch_versions
             WHERE dispatch_id = ? AND dispatch_version = ? LIMIT 1'
        );
        $stmt->execute(array($dispatchId, $version));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return;
        }
        $payload = json_decode((string)($row['payload_json'] ?? '{}'), true);
        if (!is_array($payload) || !is_array($payload['leg_segments'] ?? null) || $payload['leg_segments'] === array()) {
            return;
        }
        $bySequence = array();
        foreach ($segments as $segment) {
            if (!is_array($segment)) {
                continue;
            }
            $seq = (int)($segment['sequence_number'] ?? 0);
            if ($seq <= 0) {
                continue;
            }
            $startHobbs = $this->meterTenth($segment['starting_hobbs'] ?? null);
            $endHobbs = $this->meterTenth($segment['ending_hobbs'] ?? null);
            $startTacho = $this->meterTenth($segment['starting_tacho'] ?? null);
            $endTacho = $this->meterTenth($segment['ending_tacho'] ?? null);
            $fuelOn = $this->formatFuel($segment['fuel_onboard'] ?? '');
            $fuelRem = $this->formatFuel($segment['fuel_remaining'] ?? '');
            $bySequence[$seq] = array(
                'takeoff_count' => max(0, (int)($segment['takeoff_count'] ?? 0)),
                'landing_count' => max(0, (int)($segment['landing_count'] ?? 0)),
                'starting_hobbs' => $startHobbs,
                'ending_hobbs' => $endHobbs,
                'hobbs_delta' => ($startHobbs !== null && $endHobbs !== null)
                    ? round(max(0.0, $endHobbs - $startHobbs), 1)
                    : null,
                'starting_tacho' => $startTacho,
                'ending_tacho' => $endTacho,
                'tacho_delta' => ($startTacho !== null && $endTacho !== null)
                    ? round(max(0.0, $endTacho - $startTacho), 1)
                    : null,
                'fuel_onboard' => $fuelOn !== '' ? $fuelOn : null,
                'fuel_remaining' => $fuelRem !== '' ? $fuelRem : null,
                'fuel_burn' => (is_numeric($fuelOn) && is_numeric($fuelRem))
                    ? round((float)$fuelOn - (float)$fuelRem, 1)
                    : null,
            );
        }
        $changed = false;
        foreach ($payload['leg_segments'] as $index => $existing) {
            if (!is_array($existing)) {
                continue;
            }
            $seq = (int)($existing['sequence_number'] ?? ($index + 1));
            if (!isset($bySequence[$seq])) {
                continue;
            }
            foreach ($bySequence[$seq] as $field => $value) {
                $payload['leg_segments'][$index][$field] = $value;
            }
            $changed = true;
        }
        if (!$changed) {
            return;
        }
        $payload['leg_segments'] = CvrOperationalLegTimelineService::apply(
            array_values(array_filter(
                $payload['leg_segments'],
                static fn(mixed $segment): bool => is_array($segment)
            ))
        );
        $update = $this->pdo->prepare('UPDATE ipca_cvr_dispatch_versions SET payload_json = ? WHERE id = ?');
        $update->execute(array(AuditEventService::jsonEncode($payload), (int)$row['id']));
    }

    /**
     * @deprecated use patchLegSegmentOperationalFields
     * @param list<array<string,mixed>> $segments
     */
    public function patchLegSegmentOperationCounts(int $dispatchId, array $segments): void
    {
        $this->patchLegSegmentOperationalFields($dispatchId, $segments);
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

    /**
     * Append a Flight Log adjustment so the iOS Log API projects the same airports/meters
     * that Master Logbook just saved (it prefers adjustment rows over planned dispatch airports).
     *
     * @param array<string,mixed> $dispatch
     * @param list<array{role:string,personName:string}> $crew
     */
    private function upsertFlightLogAdjustment(
        array $dispatch,
        string $flightUuid,
        string $departure,
        string $arrival,
        array $crew,
        ?float $startingHobbs,
        ?float $startingTacho,
        string $fuelOnboard,
        ?float $endingHobbs,
        ?float $endingTacho,
        string $fuelRemaining
    ): void {
        $deviceId = (int)($dispatch['device_id'] ?? 0);
        $organizationId = max(1, (int)($dispatch['organization_id'] ?? 1));
        $dispatchUuid = strtolower(trim((string)($dispatch['dispatch_uuid'] ?? '')));
        if ($deviceId <= 0 || $dispatchUuid === '' || $departure === '' || $arrival === '') {
            return;
        }

        $latest = null;
        try {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM ipca_cvr_flight_log_adjustments
                 WHERE LOWER(workflow_flight_record_uuid) = ?
                 ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute(array($flightUuid));
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $latest = is_array($row) ? $row : null;
        } catch (Throwable) {
            return;
        }

        $closure = null;
        try {
            $closureStmt = $this->pdo->prepare(
                'SELECT ending_hobbs, ending_tacho, fuel_remaining
                 FROM ipca_cvr_flight_closures
                 WHERE LOWER(workflow_flight_record_uuid) = ?
                 ORDER BY id DESC LIMIT 1'
            );
            $closureStmt->execute(array($flightUuid));
            $closureRow = $closureStmt->fetch(PDO::FETCH_ASSOC);
            $closure = is_array($closureRow) ? $closureRow : null;
        } catch (Throwable) {
            $closure = null;
        }

        $startHobbs = $startingHobbs
            ?? (isset($latest['starting_hobbs']) && is_numeric($latest['starting_hobbs']) ? (float)$latest['starting_hobbs'] : null)
            ?? (isset($dispatch['starting_hobbs']) && is_numeric($dispatch['starting_hobbs']) ? (float)$dispatch['starting_hobbs'] : null);
        $startTacho = $startingTacho
            ?? (isset($latest['starting_tacho']) && is_numeric($latest['starting_tacho']) ? (float)$latest['starting_tacho'] : null)
            ?? (isset($dispatch['starting_tacho']) && is_numeric($dispatch['starting_tacho']) ? (float)$dispatch['starting_tacho'] : null);
        $endHobbs = $endingHobbs
            ?? (isset($latest['ending_hobbs']) && is_numeric($latest['ending_hobbs']) ? (float)$latest['ending_hobbs'] : null)
            ?? (isset($closure['ending_hobbs']) && is_numeric($closure['ending_hobbs']) ? (float)$closure['ending_hobbs'] : null);
        $endTacho = $endingTacho
            ?? (isset($latest['ending_tacho']) && is_numeric($latest['ending_tacho']) ? (float)$latest['ending_tacho'] : null)
            ?? (isset($closure['ending_tacho']) && is_numeric($closure['ending_tacho']) ? (float)$closure['ending_tacho'] : null);
        $fuelStart = $fuelOnboard !== ''
            ? $fuelOnboard
            : trim((string)($latest['fuel_onboard'] ?? $dispatch['fuel_onboard'] ?? ''));
        $fuel = $fuelRemaining !== ''
            ? $fuelRemaining
            : trim((string)($latest['fuel_remaining'] ?? $closure['fuel_remaining'] ?? ''));

        if ($startHobbs === null || $startTacho === null || $endHobbs === null || $endTacho === null
            || $fuelStart === '' || $fuel === '') {
            return;
        }

        $crewNames = array();
        foreach ($crew as $member) {
            $name = trim((string)($member['personName'] ?? ''));
            $role = trim((string)($member['role'] ?? ''));
            if ($name === '') {
                continue;
            }
            $crewNames[] = $role !== '' ? ($name . ' (' . $role . ')') : $name;
        }
        if ($crewNames === array() && is_array($latest) && trim((string)($latest['crew_json'] ?? '')) !== '') {
            $decoded = json_decode((string)$latest['crew_json'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $name) {
                    $name = trim((string)$name);
                    if ($name !== '') {
                        $crewNames[] = $name;
                    }
                }
            }
        }
        if ($crewNames === array()) {
            $crewNames[] = 'Crew';
        }

        try {
            $this->pdo->prepare(
                'INSERT INTO ipca_cvr_flight_log_adjustments
                 (adjustment_uuid, organization_id, device_id, workflow_flight_record_uuid, dispatch_uuid,
                  departure_airport, arrival_airport, crew_json, starting_hobbs, starting_tacho,
                  fuel_onboard, ending_hobbs, ending_tacho, fuel_remaining, reason)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute(array(
                AuditEventService::uuid(),
                $organizationId,
                $deviceId,
                $flightUuid,
                $dispatchUuid,
                $departure,
                $arrival,
                AuditEventService::jsonEncode(array_values($crewNames)),
                $startHobbs,
                $startTacho,
                $fuelStart,
                $endHobbs,
                $endTacho,
                $fuel,
                'Master Logbook admin operational leg correction',
            ));
        } catch (Throwable) {
            // Older schemas may omit reason; retry without it.
            try {
                $this->pdo->prepare(
                    'INSERT INTO ipca_cvr_flight_log_adjustments
                     (adjustment_uuid, organization_id, device_id, workflow_flight_record_uuid, dispatch_uuid,
                      departure_airport, arrival_airport, crew_json, starting_hobbs, starting_tacho,
                      fuel_onboard, ending_hobbs, ending_tacho, fuel_remaining)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute(array(
                    AuditEventService::uuid(),
                    $organizationId,
                    $deviceId,
                    $flightUuid,
                    $dispatchUuid,
                    $departure,
                    $arrival,
                    AuditEventService::jsonEncode(array_values($crewNames)),
                    $startHobbs,
                    $startTacho,
                    $fuelStart,
                    $endHobbs,
                    $endTacho,
                    $fuel,
                ));
            } catch (Throwable) {
                // Adjustment projection is best-effort relative to dispatch/closure writes.
            }
        }
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

        $batchId = $this->createAdminClosureEvidenceBatch($flightUuid, $hash, $json);
        $insert = $this->pdo->prepare(
            'INSERT INTO ipca_cvr_flight_closures
             (closure_uuid, batch_id, workflow_flight_record_uuid, ending_hobbs, ending_tacho,
              fuel_remaining, oil_percentage, oil_quantity, oil_unit, maintenance_remark, payload_sha256, payload_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute(array(
            AuditEventService::uuid(),
            $batchId,
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

    /**
     * Closures require a non-null evidence batch FK. Admin corrections mint a synthetic batch.
     */
    private function createAdminClosureEvidenceBatch(string $flightUuid, string $hash, string $json): int
    {
        $dispatchStmt = $this->pdo->prepare(
            'SELECT dispatch_uuid, device_id
             FROM ipca_cvr_dispatches
             WHERE LOWER(workflow_flight_record_uuid) = ?
             ORDER BY id DESC LIMIT 1'
        );
        $dispatchStmt->execute(array($flightUuid));
        $dispatch = $dispatchStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($dispatch)) {
            throw new RuntimeException('Unable to create closure evidence: Dispatch linkage was not found.');
        }
        $dispatchUuid = strtolower(trim((string)($dispatch['dispatch_uuid'] ?? '')));
        $deviceId = (int)($dispatch['device_id'] ?? 0);
        if ($dispatchUuid === '' || $deviceId <= 0) {
            throw new RuntimeException('Unable to create closure evidence: Dispatch device linkage is incomplete.');
        }

        $componentUuid = 'admin-closure-' . AuditEventService::uuid();
        $this->pdo->prepare(
            'INSERT INTO ipca_cvr_workflow_evidence_batches
             (batch_uuid, component_uuid, workflow_flight_record_uuid, dispatch_uuid, device_id,
              component_type, payload_sha256, payload_json, receipt_uuid)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute(array(
            AuditEventService::uuid(),
            $componentUuid,
            $flightUuid,
            $dispatchUuid,
            $deviceId,
            'flight_record_closure',
            $hash,
            $json,
            AuditEventService::uuid(),
        ));
        return (int)$this->pdo->lastInsertId();
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
                'person_id' => isset($member['person_id']) && (int)$member['person_id'] > 0
                    ? (int)$member['person_id']
                    : (isset($member['id']) && (int)$member['id'] > 0 ? (int)$member['id'] : null),
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

    private function oneDecimal(mixed $value): ?float
    {
        $n = $this->nullableFloat($value);
        return $n === null ? null : round($n, 1);
    }

    private function meterTenth(mixed $value): ?float
    {
        $n = $this->nullableFloat($value);
        return $n === null ? null : CvrOperationalLegTimelineService::roundUpToTenth($n);
    }

    private function formatFuel(mixed $value): string
    {
        $text = trim((string)$value);
        if ($text === '') {
            return '';
        }
        if (!is_numeric($text) || (float)$text < 0) {
            throw new InvalidArgumentException('Fuel values must be non-negative numbers.');
        }
        return number_format(round((float)$text, 1), 1, '.', '');
    }

    private function localToUtc(string $local, string $timezone): ?string
    {
        $local = trim($local);
        if ($local === '') {
            return null;
        }
        // Reject AM/PM strings — operational UI is 24-hour only.
        if (preg_match('/\b(am|pm)\b/i', $local) === 1) {
            throw new InvalidArgumentException('Enter Off Block time using the 24-hour clock.');
        }
        try {
            $tz = new DateTimeZone($timezone);
            $normalized = str_replace('T', ' ', $local);
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $normalized)) {
                $normalized .= ':00';
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $normalized)) {
                throw new InvalidArgumentException('Off Block local time is invalid.');
            }
            $dt = new DateTimeImmutable($normalized, $tz);
            return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new InvalidArgumentException('Off Block local time is invalid.');
        }
    }
}
