<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';
require_once __DIR__ . '/CvrOperationalIdentityReadService.php';
require_once __DIR__ . '/CvrOperationalIdentityService.php';
require_once __DIR__ . '/CvrDutyAssignmentIdentityService.php';
require_once __DIR__ . '/CvrOperationalBlockTimeService.php';
require_once __DIR__ . '/MissionCatalogService.php';

final class FlightScheduleService
{
    private const RESERVATION_TYPES = array(
        'flight_training' => 'Flight Training',
        'briefing' => 'Briefing',
        'simulator_training' => 'Simulator Training',
        'ground_training' => 'Ground Training',
        'other' => 'Other',
    );

    private ?CvrOperationalIdentityReadService $identityRead = null;
    private ?CvrOperationalIdentityService $identityWrite = null;
    private ?CvrDutyAssignmentIdentityService $dutyIdentity = null;

    public function __construct(private PDO $pdo)
    {
    }

    private function identityRead(): CvrOperationalIdentityReadService
    {
        return $this->identityRead ??= new CvrOperationalIdentityReadService($this->pdo);
    }

    private function identityWrite(): CvrOperationalIdentityService
    {
        return $this->identityWrite ??= new CvrOperationalIdentityService($this->pdo);
    }

    private function dutyIdentity(): CvrDutyAssignmentIdentityService
    {
        return $this->dutyIdentity ??= new CvrDutyAssignmentIdentityService($this->pdo);
    }

    /** @return list<array<string,mixed>> */
    public function listSlots(
        ?string $fromDate = null,
        ?string $toDate = null,
        ?int $aircraftId = null,
        bool $deriveOperationalCompletion = true
    ): array
    {
        $operationalToday = new DateTimeImmutable('today', new DateTimeZone('America/Los_Angeles'));
        $fromDate = $this->date($fromDate ?: $operationalToday->modify('-1 day')->format('Y-m-d'));
        $toDate = $this->date($toDate ?: $operationalToday->modify('+15 days')->format('Y-m-d'));
        $this->reconcileUnlinkedCompletedDispatches($fromDate, $toDate);
        $sql = "
            SELECT s.*, a.registration AS aircraft_registration,
                   COALESCE(NULLIF(s.mission_code, ''), m.code, '') AS resolved_mission_code,
                   COALESCE(NULLIF(m.name, ''), NULLIF(s.mission_code, ''), '') AS mission_name,
                   COALESCE(NULLIF(c.name, ''), '') AS cohort_name,
                   d.id AS dispatch_id,
                   d.dispatch_uuid AS linked_dispatch_uuid,
                   d.workflow_flight_record_uuid,
                   d.operational_session_uuid AS linked_operational_session_uuid,
                   d.current_version AS dispatch_version,
                   d.last_received_at AS dispatch_received_at,
                   d.starting_hobbs AS dispatch_starting_hobbs,
                   d.starting_tacho AS dispatch_starting_tacho,
                   d.fuel_onboard AS dispatch_fuel_onboard,
                   d.aircraft_registration AS dispatch_aircraft_registration,
                   EXISTS(
                     SELECT 1 FROM ipca_cvr_flight_closures fc
                     WHERE fc.workflow_flight_record_uuid = d.workflow_flight_record_uuid
                   ) AS has_closure,
                   (
                     EXISTS(
                       SELECT 1 FROM ipca_cvr_flight_closures fc
                       WHERE fc.workflow_flight_record_uuid = d.workflow_flight_record_uuid
                     )
                     OR EXISTS(
                       SELECT 1 FROM ipca_cvr_flight_events fe
                       WHERE fe.workflow_flight_record_uuid = d.workflow_flight_record_uuid
                     )
                   ) AS has_flight_data,
                   EXISTS(
                     SELECT 1 FROM ipca_cockpit_recordings cr
                     WHERE cr.flight_session_uid = d.workflow_flight_record_uuid
                       AND cr.upload_status = 'uploaded'
                   ) AS has_audio,
                   EXISTS(
                     SELECT 1
                     FROM ipca_structured_debriefs sd
                     INNER JOIN ipca_manual_intake_bundles mib ON mib.id = sd.bundle_id
                     WHERE mib.dispatch_id = d.id
                       AND LOWER(TRIM(COALESCE(sd.status, ''))) NOT IN ('', 'rejected')
                   ) AS has_completed_briefing
            FROM ipca_flight_schedule_slots s
            INNER JOIN ipca_aircraft_devices a ON a.id = s.aircraft_id
            LEFT JOIN ipca_missions m ON m.id = s.mission_id
            LEFT JOIN cohorts c ON c.id = s.cohort_id
            LEFT JOIN ipca_cvr_dispatches d
              ON (
                   (s.claimed_dispatch_uuid IS NOT NULL AND d.dispatch_uuid = s.claimed_dispatch_uuid)
                   OR (
                     s.claimed_dispatch_uuid IS NULL
                     AND d.id = (
                       SELECT d2.id
                       FROM ipca_cvr_dispatches d2
                       WHERE d2.scheduler_record_id = s.scheduler_record_id
                         AND LOWER(TRIM(COALESCE(d2.status, ''))) <> 'released'
                       ORDER BY d2.id DESC
                       LIMIT 1
                     )
                   )
                 )
              AND LOWER(TRIM(COALESCE(d.status, ''))) <> 'released'
            WHERE s.scheduled_date BETWEEN ? AND ?
              AND LOWER(TRIM(COALESCE(s.status, ''))) <> 'superseded'
        ";
        $params = array($fromDate, $toDate);
        if (($aircraftId ?? 0) > 0) {
            $sql .= ' AND s.aircraft_id = ?';
            $params[] = $aircraftId;
        }
        $sql .= ' ORDER BY s.scheduled_start_time ASC, s.id ASC';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows) || $rows === array()) {
            return array();
        }
        $crewBySlot = $this->crewBySlotIds(array_map(static fn(array $row): int => (int)$row['id'], $rows));
        $payloads = array_map(
            fn(array $row): array => $this->payload(
                $row,
                $crewBySlot[(int)$row['id']] ?? array(),
                $deriveOperationalCompletion
            ),
            $rows
        );
        return $this->attachOperationalLegDetails($payloads);
    }

    /** @return list<array<string,mixed>> */
    public function scheduledSessionsForDevice(array $device, ?string $fromDate = null, ?string $toDate = null): array
    {
        $aircraftId = (int)($device['aircraft_id'] ?? 0);
        if ($aircraftId <= 0) {
            throw new RuntimeException('The authenticated CVR device is not assigned to an aircraft.');
        }
        $slots = array_values(array_filter(
            $this->listSlots($fromDate, $toDate, $aircraftId, false),
            // The aircraft schedule remains a schedule after Dispatch. The device
            // needs claimed rows so it can display them as DISPATCHED rather than
            // making the reservation disappear.
            static fn(array $slot): bool => in_array((string)$slot['status'], array('scheduled', 'claimed'), true)
        ));
        $sessions = array();
        foreach ($slots as $slot) {
            foreach ($this->expandSlotToDeviceSessions($slot) as $session) {
                $sessions[] = $session;
            }
        }
        return $sessions;
    }

    /**
     * One schedule slot may mint N flight legs under one reservation / one crew.
     * Device Schedule shows one row per leg, sharing reservation_uuid.
     *
     * @param array<string,mixed> $slot
     * @return list<array<string,mixed>>
     */
    private function expandSlotToDeviceSessions(array $slot): array
    {
        $legs = is_array($slot['legs'] ?? null)
            ? array_values(array_filter(
                $slot['legs'],
                static fn($leg): bool => is_array($leg)
                    && strtolower((string)($leg['status'] ?? 'scheduled')) !== 'cancelled'
            ))
            : array();
        if (count($legs) <= 1) {
            return array($slot);
        }
        $sessions = array();
        foreach ($legs as $leg) {
            if (!is_array($leg)) {
                continue;
            }
            $session = $slot;
            $origin = strtoupper(trim((string)($leg['origin_airport'] ?? '')));
            $destination = strtoupper(trim((string)($leg['destination_airport'] ?? '')));
            if ($origin !== '') {
                $session['planned_departure_airport'] = $origin;
            }
            if ($destination !== '') {
                $session['planned_destination_airport'] = $destination;
            }
            $legUuid = strtolower(trim((string)($leg['leg_uuid'] ?? '')));
            if ($legUuid !== '') {
                $session['leg_uuid'] = $legUuid;
            }
            $session['leg_sequence_number'] = (int)($leg['sequence_number'] ?? 0) ?: null;
            $sessions[] = $session;
        }
        return $sessions !== array() ? $sessions : array($slot);
    }

    /** @return array<string,string> */
    public function reservationTypes(): array
    {
        return self::RESERVATION_TYPES;
    }

    /**
     * @param array<string,mixed> $values
     * @param list<array<string,mixed>> $crew
     */
    /** @return array{scheduler_record_id:string,warnings:list<string>} */
    public function saveSlot(array $values, array $crew, ?int $actorUserId = null): array
    {
        $recordId = strtolower(trim((string)($values['scheduler_record_id'] ?? '')));
        if ($recordId === '') {
            $recordId = AuditEventService::uuid();
        }
        if (!$this->isUuid($recordId)) {
            throw new RuntimeException('Schedule record id must be a valid UUID.');
        }
        $aircraftId = (int)($values['aircraft_id'] ?? 0);
        $reservationType = strtolower(trim((string)($values['reservation_type'] ?? 'flight_training')));
        if (!isset(self::RESERVATION_TYPES[$reservationType])) {
            throw new RuntimeException('Select a valid reservation type.');
        }
        $scheduledDate = $this->date((string)($values['scheduled_date'] ?? ''));
        $start = $this->timestamp((string)($values['scheduled_start_time'] ?? ''), 'scheduled start');
        $end = $this->timestamp((string)($values['scheduled_end_time'] ?? ''), 'scheduled end');
        if ($aircraftId <= 0 || strtotime($end) <= strtotime($start)) {
            throw new RuntimeException('Aircraft and a valid start/end time are required.');
        }
        $aircraftStatement = $this->pdo->prepare(
            'SELECT registration FROM ipca_aircraft_devices WHERE id = ? LIMIT 1'
        );
        $aircraftStatement->execute(array($aircraftId));
        $aircraftRegistration = strtoupper(trim((string)$aircraftStatement->fetchColumn()));
        if ($aircraftRegistration === '') {
            throw new RuntimeException('Selected aircraft/device was not found.');
        }
        if (substr($start, 0, 10) !== $scheduledDate) {
            throw new RuntimeException('Scheduled date must match the start time.');
        }
        $missionId = (int)($values['mission_id'] ?? 0) ?: null;
        $cohortId = (int)($values['cohort_id'] ?? 0) ?: null;
        $missionCode = substr(strtoupper(trim((string)($values['mission_code'] ?? ''))), 0, 64);
        $missionRequired = MissionCatalogService::reservationTypeRequiresMission($reservationType);
        if (!$missionRequired) {
            $missionId = null;
            $missionCode = '';
        } else {
            if ($missionId === null) {
                throw new RuntimeException('Select a mission for this reservation type.');
            }
            $missionStatement = $this->pdo->prepare('SELECT code, name FROM ipca_missions WHERE id = ? LIMIT 1');
            $missionStatement->execute(array($missionId));
            $missionRow = $missionStatement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($missionRow)) {
                throw new RuntimeException('Selected mission was not found.');
            }
            $resolvedCode = substr(strtoupper(trim((string)($missionRow['code'] ?? ''))), 0, 64);
            $missionCategory = MissionCatalogService::scheduleCategoryForMission(
                $resolvedCode,
                (string)($missionRow['name'] ?? '')
            );
            if ($missionCategory !== $reservationType) {
                throw new RuntimeException('Selected mission does not match the reservation type.');
            }
            if ($missionCode === '') {
                $missionCode = $resolvedCode;
            }
        }
        $airportChain = $this->normalizeAirportChain($values);
        $this->assertReservationScopedCrew($values, $crew, count($airportChain) - 1);
        $departure = $airportChain[0];
        $destination = $airportChain[count($airportChain) - 1];
        $status = strtolower(trim((string)($values['status'] ?? 'scheduled')));
        if (!in_array($status, array('scheduled', 'cancelled', 'completed'), true)) {
            $status = 'scheduled';
        }
        $legCount = count($airportChain) - 1;
        $canonicalWrite = $this->identityWrite()->isFlagEnabled(CvrOperationalIdentityService::FLAG_CANONICAL_WRITE);
        if ($legCount > 1 && !$canonicalWrite) {
            throw new RuntimeException(
                'Multi-leg reservations require operational identity. Create separate single-leg reservations, or enable canonical schedule write.'
            );
        }

        $this->pdo->beginTransaction();
        try {
            $existing = $this->pdo->prepare('SELECT id, claimed_dispatch_uuid FROM ipca_flight_schedule_slots WHERE scheduler_record_id = ? LIMIT 1 FOR UPDATE');
            $existing->execute(array($recordId));
            $row = $existing->fetch(PDO::FETCH_ASSOC);
            if (is_array($row) && trim((string)($row['claimed_dispatch_uuid'] ?? '')) !== '') {
                throw new RuntimeException('A claimed schedule slot cannot be edited.');
            }
            $overlapWarnings = $this->resourceConflictWarnings(
                $recordId,
                $aircraftId,
                $cohortId,
                array_values(array_unique(array_filter(array_map(
                    static fn(array $member): int => (int)($member['user_id'] ?? 0),
                    $crew
                )))),
                $start,
                $end
            );
            $isCreate = !is_array($row);
            $dutyInput = array(
                'organization_id' => $this->requireOrganizationIdForCreate($values, $missionId),
                'aircraft_device_id' => $aircraftId,
                'aircraft_registration' => $aircraftRegistration,
                'reservation_type' => $reservationType,
                'activity_domain' => CvrOperationalIdentityService::defaultActivityDomainForReservationType($reservationType) ?? 'administrative',
                'training_assignment_category' => $reservationType,
                'mission_id' => $missionId,
                'mission_code' => $missionCode,
                'crew' => $crew,
                'source' => 'server_create',
                'created_by_user_id' => $actorUserId,
            );
            if (!$isCreate
                && $this->dutyIdentity()->isEnforcementEnabled()
                && $this->dutyIdentity()->snapshotForReservation($recordId) !== null) {
                $this->dutyIdentity()->assertReservationMatches($recordId, $dutyInput);
            }
            if (!$isCreate) {
                $slotId = (int)$row['id'];
                $this->pdo->prepare(
                    'UPDATE ipca_flight_schedule_slots
                     SET reservation_type=?, scheduled_date=?, scheduled_start_time=?, scheduled_end_time=?, aircraft_id=?,
                         mission_id=?, cohort_id=?, mission_code=?, planned_departure_airport=?,
                         planned_destination_airport=?, status=?, notes=?, updated_by=?
                     WHERE id=?'
                )->execute(array(
                    $reservationType, $scheduledDate, $start, $end, $aircraftId, $missionId, $cohortId, $missionCode,
                    $departure, $destination, $status, substr(trim((string)($values['notes'] ?? '')), 0, 1000),
                    $actorUserId, $slotId,
                ));
                $this->pdo->prepare('DELETE FROM ipca_flight_schedule_crew WHERE schedule_slot_id = ?')->execute(array($slotId));
            } else {
                $organizationId = $this->requireOrganizationIdForCreate($values, $missionId);
                $this->pdo->prepare(
                    'INSERT INTO ipca_flight_schedule_slots
                     (scheduler_record_id, organization_id, reservation_type, scheduled_date, scheduled_start_time, scheduled_end_time,
                      aircraft_id, mission_id, cohort_id, mission_code, planned_departure_airport,
                      planned_destination_airport, status, notes, created_by, updated_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute(array(
                    $recordId, $organizationId, $reservationType, $scheduledDate, $start, $end, $aircraftId, $missionId, $cohortId, $missionCode,
                    $departure, $destination, $status, substr(trim((string)($values['notes'] ?? '')), 0, 1000),
                    $actorUserId, $actorUserId,
                ));
                $slotId = (int)$this->pdo->lastInsertId();

                // Phase 2C: canonical identity for NEW online creates only, same transaction.
                if ($canonicalWrite) {
                    try {
                        $this->identityWrite()->createOnlineScheduleReservationIdentity(array(
                            'organization_id' => $organizationId,
                            'scheduler_record_id' => $recordId,
                            'schedule_slot_id' => $slotId,
                            'reservation_type' => $reservationType,
                            'status' => $status,
                            'planned_departure_airport' => $departure,
                            'planned_destination_airport' => $destination,
                            'airport_chain' => $airportChain,
                            'scheduled_start_time' => $start,
                            'scheduled_end_time' => $end,
                        ));
                    } catch (Throwable $canonicalError) {
                        $this->identityWrite()->logTechnicalDiagnostic('online_schedule_canonical_write_failed', array(
                            'organization_id' => $organizationId,
                            'scheduler_record_id' => $recordId,
                            'schedule_slot_id' => $slotId,
                            'reservation_type' => $reservationType,
                            'error_class' => $canonicalError::class,
                        ));
                        throw new RuntimeException(
                            'Unable to create the schedule reservation because operational identity could not be recorded. Please try again.',
                            0,
                            $canonicalError
                        );
                    }
                }
            }
            $crewHasDutyFunctions = $this->scheduleCrewHasPilotFunction() && $this->scheduleCrewHasIsPic();
            $insertCrew = $this->pdo->prepare($crewHasDutyFunctions
                ? 'INSERT INTO ipca_flight_schedule_crew
                   (schedule_slot_id, user_id, person_name_snapshot, crew_role, pilot_function, is_pic)
                   VALUES (?, ?, ?, ?, ?, ?)'
                : 'INSERT INTO ipca_flight_schedule_crew
                   (schedule_slot_id, user_id, person_name_snapshot, crew_role)
                   VALUES (?, ?, ?, ?)');
            foreach ($crew as $member) {
                $name = substr(trim((string)($member['person_name'] ?? '')), 0, 255);
                $role = substr(strtolower(trim((string)($member['role'] ?? ''))), 0, 64);
                if ($name === '' || $role === '') {
                    continue;
                }
                $userId = (int)($member['user_id'] ?? 0) ?: null;
                $pilotFunction = CvrDutyAssignmentIdentityService::normalizePilotFunction(
                    (string)($member['pilot_function'] ?? 'NONE')
                );
                $params = array($slotId, $userId, $name, $role);
                if ($crewHasDutyFunctions) {
                    $params[] = $pilotFunction;
                    $params[] = (bool)($member['is_pic'] ?? false) ? 1 : 0;
                }
                $insertCrew->execute($params);
            }
            if ($isCreate && $canonicalWrite && $this->dutyIdentity()->isSnapshotWriteEnabled()) {
                $this->dutyIdentity()->writeSnapshot($recordId, $dutyInput);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($this->isSafeScheduleUserError($e)) {
                throw $e;
            }
            $this->identityWrite()->logTechnicalDiagnostic('schedule_create_technical_failure', array(
                'error_class' => $e::class,
                'scheduler_record_id' => $recordId,
            ));
            throw new RuntimeException(
                'Unable to save the schedule reservation. Please try again.',
                0,
                $e
            );
        }
        return array(
            'scheduler_record_id' => $recordId,
            'warnings' => $overlapWarnings,
        );
    }

    /**
     * Idempotently create a route-free or informatively routed schedule Duty
     * Assignment from an enrolled CVR device.
     *
     * @param array<string,mixed> $device
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function createScheduledDutyFromDevice(array $device, array $payload): array
    {
        $recordId = strtolower(trim((string)($payload['scheduler_record_id'] ?? '')));
        $reservationUuid = strtolower(trim((string)($payload['reservation_uuid'] ?? $recordId)));
        if (!$this->isUuid($recordId) || $reservationUuid !== $recordId) {
            throw new RuntimeException('A valid matching reservation UUID and schedule record UUID are required.');
        }
        $deviceAircraftId = (int)($device['aircraft_id'] ?? 0);
        $aircraftId = (int)($payload['aircraft_id'] ?? 0);
        if ($deviceAircraftId <= 0 || $aircraftId !== $deviceAircraftId) {
            throw new RuntimeException('The reservation must use the enrolled device aircraft.');
        }
        $organizationId = max(1, (int)($device['organization_id'] ?? 0));
        $reservationType = strtolower(trim((string)($payload['reservation_type'] ?? 'flight_training')));
        if (!isset(self::RESERVATION_TYPES[$reservationType])) {
            throw new RuntimeException('Select a valid reservation type.');
        }
        $scheduledDate = $this->date((string)($payload['scheduled_date'] ?? ''));
        $start = $this->timestamp((string)($payload['scheduled_start_time'] ?? ''), 'scheduled start');
        $end = $this->timestamp((string)($payload['scheduled_end_time'] ?? ''), 'scheduled end');
        if (substr($start, 0, 10) !== $scheduledDate || strtotime($end) <= strtotime($start)) {
            throw new RuntimeException('A valid same-date schedule start and end are required.');
        }
        $routeSupplied = array_key_exists('legs', $payload);
        $routeLegs = $routeSupplied && is_array($payload['legs'])
            ? array_values(array_filter($payload['legs'], static fn($leg): bool => is_array($leg)))
            : array();
        usort($routeLegs, static fn(array $a, array $b): int =>
            ((int)($a['sequence_number'] ?? 0)) <=> ((int)($b['sequence_number'] ?? 0))
        );
        $airportChain = array();
        foreach ($routeLegs as $index => $leg) {
            $origin = $this->airport($leg['origin_airport'] ?? '');
            $destination = $this->airport($leg['destination_airport'] ?? '');
            if ($origin === '' || $destination === ''
                || ($index > 0 && end($airportChain) !== $origin)) {
                throw new RuntimeException('Informative route legs must form one continuous airport chain.');
            }
            if ($index === 0) {
                $airportChain[] = $origin;
            }
            $airportChain[] = $destination;
        }
        $missionCode = substr(strtoupper(trim((string)($payload['mission_code'] ?? ''))), 0, 64);
        if ($missionCode === '') {
            throw new RuntimeException('A mission is required.');
        }
        $missionStatement = $this->pdo->prepare(
            'SELECT id, name, organization_id FROM ipca_missions
             WHERE UPPER(TRIM(code)) = ? AND organization_id = ? LIMIT 1'
        );
        $missionStatement->execute(array($missionCode, $organizationId));
        $mission = $missionStatement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($mission)) {
            throw new RuntimeException('The selected mission was not found for this organization.');
        }
        if (MissionCatalogService::scheduleCategoryForMission($missionCode, (string)($mission['name'] ?? ''))
            !== $reservationType) {
            throw new RuntimeException('Selected mission does not match the reservation type.');
        }
        $missionId = (int)$mission['id'];

        $aircraftStatement = $this->pdo->prepare(
            'SELECT registration FROM ipca_aircraft_devices WHERE id = ? LIMIT 1'
        );
        $aircraftStatement->execute(array($aircraftId));
        $registration = strtoupper(trim((string)$aircraftStatement->fetchColumn()));
        if ($registration === '') {
            throw new RuntimeException('The enrolled aircraft was not found.');
        }
        $postedRegistration = strtoupper(trim((string)($payload['aircraft_registration'] ?? '')));
        if ($postedRegistration !== '' && $postedRegistration !== $registration) {
            throw new RuntimeException('The reservation aircraft registration does not match this device.');
        }

        $rawCrew = is_array($payload['crew'] ?? null) ? array_values(array_filter(
            $payload['crew'],
            static fn($member): bool => is_array($member)
        )) : array();
        $crew = array_map(static function (array $member): array {
            return array(
                'user_id' => (int)($member['user_id'] ?? $member['person_id'] ?? 0) ?: null,
                'person_name' => trim((string)($member['person_name'] ?? '')),
                'role' => strtolower(trim((string)($member['role'] ?? ''))),
                'pilot_function' => CvrDutyAssignmentIdentityService::normalizePilotFunction(
                    (string)($member['pilot_function'] ?? 'NONE')
                ),
                'is_pic' => (bool)($member['is_pic'] ?? false),
                'is_primary_customer' => (bool)($member['is_primary_customer'] ?? false),
            );
        }, $rawCrew);
        if ($crew === array()) {
            throw new RuntimeException('Reservation crew is required.');
        }
        $crewUserIds = array();
        $primaryCustomers = 0;
        $picCount = 0;
        foreach ($crew as $member) {
            $userId = (int)($member['user_id'] ?? 0);
            if ($userId <= 0 || (string)$member['person_name'] === '' || (string)$member['role'] === '') {
                throw new RuntimeException('Every crew position must use a valid user account and role.');
            }
            if (isset($crewUserIds[$userId])) {
                throw new RuntimeException('Each crew position must use a different user account.');
            }
            $crewUserIds[$userId] = true;
            $primaryCustomers += (bool)$member['is_primary_customer'] ? 1 : 0;
            $picCount += (bool)$member['is_pic'] ? 1 : 0;
        }
        if ($primaryCustomers !== 1 || $picCount < 1 || $picCount > 2) {
            throw new RuntimeException('Select one primary customer and one or two pilots logging PIC.');
        }

        $legs = is_array($payload['legs'] ?? null) ? array_values(array_filter(
            $payload['legs'],
            static fn($leg): bool => is_array($leg)
        )) : array();
        usort($legs, static fn(array $a, array $b): int =>
            ((int)($a['sequence_number'] ?? 0)) <=> ((int)($b['sequence_number'] ?? 0))
        );
        $airportChain = array();
        foreach ($legs as $index => $leg) {
            $origin = $this->airport($leg['origin_airport'] ?? '');
            $destination = $this->airport($leg['destination_airport'] ?? '');
            if ($origin === '' || $destination === '' || ($index > 0 && end($airportChain) !== $origin)) {
                throw new RuntimeException('Informative route legs must form one continuous airport chain.');
            }
            if ($index === 0) {
                $airportChain[] = $origin;
            }
            $airportChain[] = $destination;
        }
        $this->assertReservationScopedCrew($payload, $crew, count($legs));
        $departure = $airportChain[0] ?? '';
        $destination = $airportChain === array() ? '' : $airportChain[count($airportChain) - 1];

        $this->pdo->beginTransaction();
        try {
            $existingStatement = $this->pdo->prepare(
                'SELECT * FROM ipca_flight_schedule_slots
                 WHERE scheduler_record_id = ? LIMIT 1 FOR UPDATE'
            );
            $existingStatement->execute(array($recordId));
            $existing = $existingStatement->fetch(PDO::FETCH_ASSOC);
            $overlapWarnings = $this->resourceConflictWarnings(
                $recordId,
                $aircraftId,
                null,
                array_keys($crewUserIds),
                $start,
                $end
            );
            if (is_array($existing)) {
                $this->assertDeviceCreateRetryEquivalent(
                    $existing,
                    $payload,
                    $crew,
                    $start,
                    $end,
                    $airportChain
                );
                $this->pdo->commit();
                return array(
                    'ok' => true,
                    'already_present' => true,
                    'scheduler_record_id' => $recordId,
                    'reservation_uuid' => $recordId,
                    'warnings' => $overlapWarnings,
                );
            }

            $this->pdo->prepare(
                'INSERT INTO ipca_flight_schedule_slots
                 (scheduler_record_id, organization_id, reservation_type, scheduled_date,
                  scheduled_start_time, scheduled_end_time, aircraft_id, mission_id, cohort_id,
                  mission_code, planned_departure_airport, planned_destination_airport,
                  status, notes, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, NULL, NULL)'
            )->execute(array(
                $recordId, $organizationId, $reservationType, $scheduledDate, $start, $end,
                $aircraftId, $missionId, $missionCode, $departure, $destination,
                'scheduled', 'Created offline by enrolled CVR Unit.',
            ));
            $slotId = (int)$this->pdo->lastInsertId();
            $crewHasDutyFunctions = $this->scheduleCrewHasPilotFunction() && $this->scheduleCrewHasIsPic();
            $insertCrew = $this->pdo->prepare($crewHasDutyFunctions
                ? 'INSERT INTO ipca_flight_schedule_crew
                   (schedule_slot_id, user_id, person_name_snapshot, crew_role, pilot_function, is_pic)
                   VALUES (?, ?, ?, ?, ?, ?)'
                : 'INSERT INTO ipca_flight_schedule_crew
                   (schedule_slot_id, user_id, person_name_snapshot, crew_role) VALUES (?, ?, ?, ?)');
            foreach ($crew as $member) {
                $params = array(
                    $slotId,
                    $member['user_id'],
                    substr((string)$member['person_name'], 0, 255),
                    substr((string)$member['role'], 0, 64),
                );
                if ($crewHasDutyFunctions) {
                    $params[] = (string)$member['pilot_function'];
                    $params[] = (bool)$member['is_pic'] ? 1 : 0;
                }
                $insertCrew->execute($params);
            }
            $this->identityWrite()->createOnlineScheduleReservationIdentity(array(
                'organization_id' => $organizationId,
                'scheduler_record_id' => $recordId,
                'schedule_slot_id' => $slotId,
                'reservation_type' => $reservationType,
                'status' => 'scheduled',
                'planned_departure_airport' => $departure,
                'planned_destination_airport' => $destination,
                'airport_chain' => $airportChain,
                'allow_route_free_flight' => $airportChain === array(),
                'leg_uuids' => array_map(
                    static fn(array $leg): string => strtolower(trim((string)($leg['leg_uuid'] ?? ''))),
                    $legs
                ),
                'scheduled_start_time' => $start,
                'scheduled_end_time' => $end,
            ));
            if ($this->dutyIdentity()->isSnapshotWriteEnabled()) {
                $this->dutyIdentity()->writeSnapshot($recordId, array(
                    'organization_id' => $organizationId,
                    'aircraft_device_id' => $aircraftId,
                    'aircraft_registration' => $registration,
                    'reservation_type' => $reservationType,
                    'activity_domain' => CvrOperationalIdentityService::defaultActivityDomainForReservationType(
                        $reservationType
                    ) ?? 'administrative',
                    'training_assignment_category' => $reservationType,
                    'mission_id' => $missionId,
                    'mission_code' => $missionCode,
                    'crew' => $crew,
                    'source' => 'ios_schedule_create',
                    'source_device_id' => (int)($device['id'] ?? 0) ?: null,
                ));
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
            'already_present' => false,
            'scheduler_record_id' => $recordId,
            'reservation_uuid' => $recordId,
            'warnings' => $overlapWarnings,
        );
    }

    /**
     * Update only the planning window of an unclaimed reservation. Schedule time
     * is mutable planning data and does not create a new Duty Assignment identity.
     *
     * @param array<string,mixed> $device
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function updateScheduledDutyWindowFromDevice(array $device, array $payload): array
    {
        $recordId = strtolower(trim((string)($payload['scheduler_record_id'] ?? '')));
        $reservationUuid = strtolower(trim((string)($payload['reservation_uuid'] ?? $recordId)));
        if (!$this->isUuid($recordId) || $reservationUuid !== $recordId) {
            throw new RuntimeException('A valid matching reservation UUID and schedule record UUID are required.');
        }
        $deviceAircraftId = (int)($device['aircraft_id'] ?? 0);
        $aircraftId = (int)($payload['aircraft_id'] ?? 0);
        if ($deviceAircraftId <= 0 || $aircraftId !== $deviceAircraftId) {
            throw new RuntimeException('The schedule window must use the enrolled device aircraft.');
        }
        $scheduledDate = $this->date((string)($payload['scheduled_date'] ?? ''));
        $start = $this->timestamp((string)($payload['scheduled_start_time'] ?? ''), 'scheduled start');
        $end = $this->timestamp((string)($payload['scheduled_end_time'] ?? ''), 'scheduled end');
        if (substr($start, 0, 10) !== $scheduledDate || strtotime($end) <= strtotime($start)) {
            throw new RuntimeException('A valid same-date schedule start and end are required.');
        }

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'SELECT * FROM ipca_flight_schedule_slots
                 WHERE scheduler_record_id = ? LIMIT 1 FOR UPDATE'
            );
            $statement->execute(array($recordId));
            $slot = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($slot)) {
                throw new RuntimeException('The reservation schedule window was not found.');
            }
            if ((int)($slot['aircraft_id'] ?? 0) !== $aircraftId) {
                throw new RuntimeException('The reservation aircraft does not match this device.');
            }
            if ((string)($slot['status'] ?? '') !== 'scheduled'
                || trim((string)($slot['claimed_dispatch_uuid'] ?? '')) !== '') {
                throw new RuntimeException('Only an unclaimed scheduled reservation may change its schedule window.');
            }
            $crewStatement = $this->pdo->prepare(
                'SELECT user_id FROM ipca_flight_schedule_crew
                 WHERE schedule_slot_id = ? AND user_id IS NOT NULL'
            );
            $crewStatement->execute(array((int)$slot['id']));
            $warnings = $this->resourceConflictWarnings(
                $recordId,
                $aircraftId,
                isset($slot['cohort_id']) ? ((int)$slot['cohort_id'] ?: null) : null,
                array_map('intval', $crewStatement->fetchAll(PDO::FETCH_COLUMN) ?: array()),
                $start,
                $end
            );
            if ($routeSupplied) {
                $departure = $airportChain[0] ?? '';
                $destination = $airportChain !== array() ? $airportChain[count($airportChain) - 1] : '';
                $this->pdo->prepare(
                    'UPDATE ipca_flight_schedule_slots
                     SET scheduled_date = ?, scheduled_start_time = ?, scheduled_end_time = ?,
                         planned_departure_airport = ?, planned_destination_airport = ?, updated_by = NULL
                     WHERE id = ?'
                )->execute(array(
                    $scheduledDate,
                    $start,
                    $end,
                    $departure,
                    $destination,
                    (int)$slot['id'],
                ));
                $this->replaceInformativeReservationRoute(
                    $recordId,
                    max(1, (int)($slot['organization_id'] ?? 1)),
                    $airportChain,
                    $start,
                    $end
                );
            } else {
                $this->pdo->prepare(
                    'UPDATE ipca_flight_schedule_slots
                     SET scheduled_date = ?, scheduled_start_time = ?, scheduled_end_time = ?, updated_by = NULL
                     WHERE id = ?'
                )->execute(array($scheduledDate, $start, $end, (int)$slot['id']));
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
            'already_present' => false,
            'scheduler_record_id' => $recordId,
            'reservation_uuid' => $recordId,
            'warnings' => $warnings,
        );
    }

    /**
     * Informative route is mutable planning context, not actual flown-leg evidence.
     *
     * @param list<string> $airportChain
     */
    private function replaceInformativeReservationRoute(
        string $reservationUuid,
        int $organizationId,
        array $airportChain,
        string $start,
        string $end
    ): array {
        $statement = $this->pdo->prepare(
            'SELECT * FROM ipca_operational_reservation_legs
             WHERE reservation_uuid = ? ORDER BY sequence_number ASC FOR UPDATE'
        );
        $statement->execute(array($reservationUuid));
        $existing = array();
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: array() as $leg) {
            $existing[(int)$leg['sequence_number']] = $leg;
        }
        $legCount = max(0, count($airportChain) - 1);
        for ($index = 0; $index < $legCount; $index++) {
            $sequence = $index + 1;
            $origin = $airportChain[$index];
            $destination = $airportChain[$index + 1];
            if (isset($existing[$sequence])) {
                $this->pdo->prepare(
                    "UPDATE ipca_operational_reservation_legs
                     SET origin_airport = ?, destination_airport = ?,
                         planned_start_local = ?, planned_end_local = ?, status = 'scheduled'
                     WHERE id = ?"
                )->execute(array(
                    $origin,
                    $destination,
                    $start,
                    $end,
                    (int)$existing[$sequence]['id'],
                ));
                continue;
            }
            $this->identityWrite()->createFlightLeg(array(
                'reservation_uuid' => $reservationUuid,
                'organization_id' => $organizationId,
                'sequence_number' => $sequence,
                'origin_airport' => $origin,
                'destination_airport' => $destination,
                'planned_start_local' => $start,
                'planned_end_local' => $end,
                'organization_timezone_iana' => 'America/Los_Angeles',
                'status' => 'scheduled',
                'source' => 'server_create',
            ), true);
        }
        foreach ($existing as $sequence => $leg) {
            if ($sequence <= $legCount) {
                continue;
            }
            $this->pdo->prepare(
                "UPDATE ipca_operational_reservation_legs SET status = 'cancelled' WHERE id = ?"
            )->execute(array((int)$leg['id']));
        }
    }

    /**
     * Atomically replace an unclaimed scheduled Duty Assignment from an enrolled
     * CVR device. The device may queue/retry this operation while offline.
     *
     * @param array<string,mixed> $device
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function supersedeScheduledDutyFromDevice(array $device, array $payload): array
    {
        $oldId = strtolower(trim((string)($payload['supersedes_scheduler_record_id'] ?? '')));
        $newId = strtolower(trim((string)($payload['scheduler_record_id'] ?? '')));
        $reservationUuid = strtolower(trim((string)($payload['reservation_uuid'] ?? $newId)));
        if (!$this->isUuid($oldId) || !$this->isUuid($newId) || $reservationUuid !== $newId || $oldId === $newId) {
            throw new RuntimeException('Valid distinct old and new reservation UUIDs are required.');
        }
        $deviceAircraftId = (int)($device['aircraft_id'] ?? 0);
        $aircraftId = (int)($payload['aircraft_id'] ?? 0);
        if ($deviceAircraftId <= 0 || $aircraftId !== $deviceAircraftId) {
            throw new RuntimeException('The replacement reservation must use the enrolled device aircraft.');
        }
        $crew = is_array($payload['crew'] ?? null) ? array_values(array_filter(
            $payload['crew'],
            static fn($member): bool => is_array($member)
        )) : array();
        $legs = is_array($payload['legs'] ?? null) ? array_values(array_filter(
            $payload['legs'],
            static fn($leg): bool => is_array($leg)
        )) : array();
        if ($crew === array()) {
            throw new RuntimeException('Replacement crew is required.');
        }
        usort($legs, static fn(array $a, array $b): int =>
            ((int)($a['sequence_number'] ?? 0)) <=> ((int)($b['sequence_number'] ?? 0))
        );
        $airportChain = array();
        foreach ($legs as $index => $leg) {
            $origin = strtoupper(trim((string)($leg['origin_airport'] ?? '')));
            $destination = strtoupper(trim((string)($leg['destination_airport'] ?? '')));
            if ($origin === '' || $destination === '' || ($index > 0 && end($airportChain) !== $origin)) {
                throw new RuntimeException('Replacement route legs must form one continuous airport chain.');
            }
            if ($index === 0) {
                $airportChain[] = $origin;
            }
            $airportChain[] = $destination;
        }

        $this->pdo->beginTransaction();
        try {
            $oldStatement = $this->pdo->prepare(
                'SELECT * FROM ipca_flight_schedule_slots WHERE scheduler_record_id = ? LIMIT 1 FOR UPDATE'
            );
            $oldStatement->execute(array($oldId));
            $old = $oldStatement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($old)) {
                throw new RuntimeException('The reservation being replaced was not found.');
            }
            $replacementDate = array_key_exists('scheduled_date', $payload)
                ? $this->date((string)$payload['scheduled_date'])
                : (string)$old['scheduled_date'];
            $replacementStart = array_key_exists('scheduled_start_time', $payload)
                ? $this->timestamp((string)$payload['scheduled_start_time'], 'scheduled start')
                : (string)$old['scheduled_start_time'];
            $replacementEnd = array_key_exists('scheduled_end_time', $payload)
                ? $this->timestamp((string)$payload['scheduled_end_time'], 'scheduled end')
                : (string)$old['scheduled_end_time'];
            if (substr($replacementStart, 0, 10) !== $replacementDate
                || strtotime($replacementEnd) <= strtotime($replacementStart)) {
                throw new RuntimeException('A valid same-date schedule start and end are required.');
            }

            $newStatement = $this->pdo->prepare(
                'SELECT * FROM ipca_flight_schedule_slots WHERE scheduler_record_id = ? LIMIT 1 FOR UPDATE'
            );
            $newStatement->execute(array($newId));
            $existingNew = $newStatement->fetch(PDO::FETCH_ASSOC);
            if (is_array($existingNew)) {
                if (strtolower(trim((string)($existingNew['supersedes_scheduler_record_id'] ?? ''))) !== $oldId
                    || strtolower(trim((string)($old['superseded_by_scheduler_record_id'] ?? ''))) !== $newId) {
                    throw new RuntimeException('The replacement reservation UUID is already in use.');
                }
                $this->assertReplacementRetryEquivalent($existingNew, $payload, $crew);
                $retryWarnings = $this->resourceConflictWarnings(
                    $newId,
                    $aircraftId,
                    isset($old['cohort_id']) ? ((int)$old['cohort_id'] ?: null) : null,
                    array_values(array_unique(array_filter(array_map(
                        static fn(array $member): int => (int)($member['user_id'] ?? $member['person_id'] ?? 0),
                        $crew
                    )))),
                    $replacementStart,
                    $replacementEnd
                );
                $this->pdo->commit();
                return array(
                    'ok' => true,
                    'already_present' => true,
                    'scheduler_record_id' => $newId,
                    'reservation_uuid' => $newId,
                    'supersedes_scheduler_record_id' => $oldId,
                    'warnings' => $retryWarnings,
                );
            }
            if ((string)($old['status'] ?? '') !== 'scheduled'
                || trim((string)($old['claimed_dispatch_uuid'] ?? '')) !== '') {
                throw new RuntimeException('Only an unclaimed scheduled reservation may be replaced.');
            }

            $organizationId = (int)($old['organization_id'] ?? 0);
            $reservationType = (string)($old['reservation_type'] ?? 'flight_training');
            $missionCode = strtoupper(trim((string)($payload['mission_code'] ?? $old['mission_code'] ?? '')));
            $missionId = null;
            if ($missionCode !== '') {
                $missionStatement = $this->pdo->prepare(
                    'SELECT id FROM ipca_missions WHERE UPPER(TRIM(code)) = ? LIMIT 1'
                );
                $missionStatement->execute(array($missionCode));
                $missionId = (int)($missionStatement->fetchColumn() ?: 0) ?: null;
                if ($missionId === null) {
                    throw new RuntimeException('The replacement mission was not found.');
                }
            }
            $aircraftStatement = $this->pdo->prepare(
                'SELECT registration FROM ipca_aircraft_devices WHERE id = ? LIMIT 1'
            );
            $aircraftStatement->execute(array($aircraftId));
            $registration = strtoupper(trim((string)$aircraftStatement->fetchColumn()));
            if ($registration === '') {
                throw new RuntimeException('The replacement aircraft was not found.');
            }
            $plannedDeparture = $airportChain[0]
                ?? strtoupper(trim((string)($old['planned_departure_airport'] ?? '')));
            $plannedDestination = $airportChain !== array()
                ? $airportChain[count($airportChain) - 1]
                : strtoupper(trim((string)($old['planned_destination_airport'] ?? '')));

            $normalizedCrew = array_map(static function (array $member): array {
                return array(
                    'user_id' => (int)($member['user_id'] ?? $member['person_id'] ?? 0) ?: null,
                    'person_name' => trim((string)($member['person_name'] ?? '')),
                    'role' => strtolower(trim((string)($member['role'] ?? ''))),
                    'pilot_function' => strtoupper(trim((string)($member['pilot_function'] ?? 'NONE'))),
                    'is_pic' => (bool)($member['is_pic'] ?? false),
                    'is_primary_customer' => (bool)($member['is_primary_customer'] ?? false),
                );
            }, $crew);
            $this->assertReservationScopedCrew(
                array('reservation_type' => $reservationType),
                $normalizedCrew,
                count($legs)
            );

            $this->pdo->prepare(
                "UPDATE ipca_flight_schedule_slots
                 SET status = 'superseded', superseded_by_scheduler_record_id = ?, updated_by = NULL
                 WHERE id = ?"
            )->execute(array($newId, (int)$old['id']));

            $participantIds = array_values(array_unique(array_filter(array_map(
                static fn(array $member): int => (int)($member['user_id'] ?? 0),
                $normalizedCrew
            ))));
            $overlapWarnings = $this->resourceConflictWarnings(
                $newId,
                $aircraftId,
                isset($old['cohort_id']) ? ((int)$old['cohort_id'] ?: null) : null,
                $participantIds,
                $replacementStart,
                $replacementEnd
            );

            $this->pdo->prepare(
                'INSERT INTO ipca_flight_schedule_slots
                 (scheduler_record_id, supersedes_scheduler_record_id, organization_id, reservation_type,
                  scheduled_date, scheduled_start_time, scheduled_end_time, aircraft_id, mission_id,
                  cohort_id, mission_code, planned_departure_airport, planned_destination_airport,
                  status, notes, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL)'
            )->execute(array(
                $newId, $oldId, $organizationId, $reservationType,
                $replacementDate, $replacementStart,
                $replacementEnd, $aircraftId, $missionId,
                isset($old['cohort_id']) ? ((int)$old['cohort_id'] ?: null) : null,
                $missionCode, $plannedDeparture, $plannedDestination,
                'scheduled', substr((string)($old['notes'] ?? ''), 0, 1000),
            ));
            $slotId = (int)$this->pdo->lastInsertId();

            $crewHasDutyFunctions = $this->scheduleCrewHasPilotFunction() && $this->scheduleCrewHasIsPic();
            $insertCrew = $this->pdo->prepare($crewHasDutyFunctions
                ? 'INSERT INTO ipca_flight_schedule_crew
                   (schedule_slot_id, user_id, person_name_snapshot, crew_role, pilot_function, is_pic)
                   VALUES (?, ?, ?, ?, ?, ?)'
                : 'INSERT INTO ipca_flight_schedule_crew
                   (schedule_slot_id, user_id, person_name_snapshot, crew_role) VALUES (?, ?, ?, ?)');
            foreach ($normalizedCrew as $member) {
                $params = array(
                    $slotId,
                    $member['user_id'],
                    substr((string)$member['person_name'], 0, 255),
                    substr((string)$member['role'], 0, 64),
                );
                if ($crewHasDutyFunctions) {
                    $params[] = CvrDutyAssignmentIdentityService::normalizePilotFunction(
                        (string)$member['pilot_function']
                    );
                    $params[] = $member['is_pic'] ? 1 : 0;
                }
                $insertCrew->execute($params);
            }

            $identity = $this->identityWrite()->createOnlineScheduleReservationIdentity(array(
                'organization_id' => $organizationId,
                'scheduler_record_id' => $newId,
                'schedule_slot_id' => $slotId,
                'reservation_type' => $reservationType,
                'status' => 'scheduled',
                'planned_departure_airport' => $plannedDeparture,
                'planned_destination_airport' => $plannedDestination,
                'airport_chain' => $airportChain,
                'allow_route_free_flight' => $airportChain === array(),
                'leg_uuids' => array_map(
                    static fn(array $leg): string => strtolower(trim((string)($leg['leg_uuid'] ?? ''))),
                    $legs
                ),
                'scheduled_start_time' => $replacementStart,
                'scheduled_end_time' => $replacementEnd,
            ));
            $dutyInput = array(
                'organization_id' => $organizationId,
                'aircraft_device_id' => $aircraftId,
                'aircraft_registration' => $registration,
                'reservation_type' => $reservationType,
                'activity_domain' => CvrOperationalIdentityService::defaultActivityDomainForReservationType($reservationType) ?? 'administrative',
                'training_assignment_category' => $reservationType,
                'mission_id' => $missionId,
                'mission_code' => $missionCode,
                'crew' => $normalizedCrew,
                'source' => 'ios_schedule_supersession',
                'source_device_id' => (int)($device['id'] ?? 0) ?: null,
            );
            if ($this->dutyIdentity()->isSnapshotWriteEnabled()) {
                $this->dutyIdentity()->writeSnapshot($newId, $dutyInput);
            }
            $this->pdo->prepare(
                "UPDATE ipca_operational_reservations
                 SET status = 'superseded', superseded_by_reservation_uuid = ?
                 WHERE reservation_uuid = ?"
            )->execute(array($newId, $oldId));
            $this->pdo->prepare(
                'UPDATE ipca_operational_reservations
                 SET supersedes_reservation_uuid = ?
                 WHERE reservation_uuid = ?'
            )->execute(array($oldId, $newId));

            $this->pdo->commit();
            return array(
                'ok' => true,
                'already_present' => false,
                'scheduler_record_id' => $newId,
                'reservation_uuid' => $newId,
                'leg_uuid' => $identity['leg_uuid'] ?? null,
                'supersedes_scheduler_record_id' => $oldId,
                'duty_fingerprint_sha256' => $this->dutyIdentity()->canonicalize($dutyInput)['fingerprint'],
                'warnings' => $overlapWarnings,
            );
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function rescheduleSlot(
        string $schedulerRecordId,
        string $scheduledStartTime,
        string $scheduledEndTime,
        ?int $actorUserId = null,
        ?string $expectedUpdatedAt = null,
        ?int $aircraftId = null
    ): array {
        $schedulerRecordId = strtolower(trim($schedulerRecordId));
        if (!$this->isUuid($schedulerRecordId)) {
            throw new RuntimeException('Schedule record id must be a valid UUID.');
        }
        $start = $this->timestamp($scheduledStartTime, 'scheduled start');
        $end = $this->timestamp($scheduledEndTime, 'scheduled end');
        if (strtotime($end) <= strtotime($start)) {
            throw new RuntimeException('The reservation end must be after its start.');
        }

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'SELECT id, aircraft_id, cohort_id, status, claimed_dispatch_uuid, updated_at'
                . ' FROM ipca_flight_schedule_slots'
                . ' WHERE scheduler_record_id = ? LIMIT 1 FOR UPDATE'
            );
            $statement->execute(array($schedulerRecordId));
            $slot = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($slot)) {
                throw new RuntimeException('Reservation was not found.');
            }
            if ((string)($slot['status'] ?? '') !== 'scheduled'
                || trim((string)($slot['claimed_dispatch_uuid'] ?? '')) !== '') {
                throw new RuntimeException('A reservation cannot move after Dispatch is activated.');
            }
            if ($expectedUpdatedAt !== null && trim($expectedUpdatedAt) !== '') {
                $expected = str_replace('T', ' ', trim($expectedUpdatedAt));
                if (substr((string)($slot['updated_at'] ?? ''), 0, 19) !== substr($expected, 0, 19)) {
                    throw new RuntimeException('This reservation changed in another session. Reload the schedule and try again.');
                }
            }
            $targetAircraftId = $aircraftId !== null && $aircraftId > 0
                ? $aircraftId
                : (int)$slot['aircraft_id'];
            if ($targetAircraftId <= 0) {
                throw new RuntimeException('Aircraft is required.');
            }
            if ($targetAircraftId !== (int)$slot['aircraft_id']
                && $this->dutyIdentity()->isEnforcementEnabled()
                && $this->dutyIdentity()->snapshotForReservation($schedulerRecordId) !== null) {
                throw new RuntimeException(
                    'Changing aircraft/device is a material Duty Assignment change. Create a new reservation.'
                );
            }
            if ($targetAircraftId !== (int)$slot['aircraft_id']) {
                $aircraftExists = $this->pdo->prepare(
                    'SELECT id FROM ipca_aircraft_devices WHERE id = ? AND active = 1 LIMIT 1'
                );
                $aircraftExists->execute(array($targetAircraftId));
                if ($aircraftExists->fetchColumn() === false) {
                    throw new RuntimeException('The selected aircraft was not found.');
                }
            }
            $crewStatement = $this->pdo->prepare(
                'SELECT user_id FROM ipca_flight_schedule_crew'
                . ' WHERE schedule_slot_id = ? AND user_id IS NOT NULL'
            );
            $crewStatement->execute(array((int)$slot['id']));
            $overlapWarnings = $this->resourceConflictWarnings(
                $schedulerRecordId,
                $targetAircraftId,
                (int)($slot['cohort_id'] ?? 0) ?: null,
                array_map('intval', $crewStatement->fetchAll(PDO::FETCH_COLUMN) ?: array()),
                $start,
                $end
            );
            $this->pdo->prepare(
                'UPDATE ipca_flight_schedule_slots'
                . ' SET scheduled_date = ?, scheduled_start_time = ?, scheduled_end_time = ?, aircraft_id = ?, updated_by = ?'
                . ' WHERE id = ?'
            )->execute(array(
                substr($start, 0, 10),
                $start,
                $end,
                $targetAircraftId,
                $actorUserId,
                (int)$slot['id'],
            ));
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return array(
            'scheduler_record_id' => $schedulerRecordId,
            'warnings' => $overlapWarnings,
        );
    }

    public function cancelSlot(string $schedulerRecordId, ?int $actorUserId = null): void
    {
        $schedulerRecordId = strtolower(trim($schedulerRecordId));
        if (!$this->isUuid($schedulerRecordId)) {
            throw new RuntimeException('Schedule record id must be a valid UUID.');
        }
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'SELECT id, claimed_dispatch_uuid FROM ipca_flight_schedule_slots'
                . ' WHERE scheduler_record_id = ? LIMIT 1 FOR UPDATE'
            );
            $statement->execute(array($schedulerRecordId));
            $slot = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($slot)) {
                throw new RuntimeException('Schedule slot was not found.');
            }
            if (trim((string)($slot['claimed_dispatch_uuid'] ?? '')) !== '') {
                throw new RuntimeException('A claimed schedule slot cannot be cancelled.');
            }
            $this->pdo->prepare(
                "UPDATE ipca_flight_schedule_slots SET status = 'cancelled', updated_by = ? WHERE id = ?"
            )->execute(array($actorUserId, (int)$slot['id']));
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function reconcileUnlinkedCompletedDispatches(string $fromDate, string $toDate): void
    {
        if ($this->pdo->inTransaction()) {
            return;
        }
        $dispatches = $this->pdo->prepare(
            "SELECT d.id, d.dispatch_uuid, d.aircraft_id, d.scheduled_date, d.mission_code,
                    COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(v.payload_json, '$.planned_departure_airport')), 'null'), '') AS departure_airport,
                    COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(v.payload_json, '$.planned_destination_airport')), 'null'), '') AS destination_airport
             FROM ipca_cvr_dispatches d
             LEFT JOIN ipca_cvr_dispatch_versions v
               ON v.dispatch_id = d.id AND v.dispatch_version = d.current_version
             WHERE d.scheduled_date BETWEEN ? AND ?
               AND d.operational_session_uuid IS NULL
               AND (d.scheduler_record_id IS NULL OR d.scheduler_record_id = '')
               AND EXISTS(
                 SELECT 1 FROM ipca_cvr_flight_closures closure_record
                 WHERE closure_record.workflow_flight_record_uuid = d.workflow_flight_record_uuid
               )
             ORDER BY d.first_received_at"
        );
        $dispatches->execute(array($fromDate, $toDate));
        $rows = $dispatches->fetchAll(PDO::FETCH_ASSOC) ?: array();
        if ($rows === array()) {
            return;
        }

        $match = $this->pdo->prepare(
            "SELECT id, scheduler_record_id
             FROM ipca_flight_schedule_slots
             WHERE aircraft_id = ?
               AND scheduled_date = ?
               AND status = 'scheduled'
               AND (claimed_dispatch_uuid IS NULL OR claimed_dispatch_uuid = '')
               AND (mission_code = '' OR UPPER(mission_code) = UPPER(?))
               AND (planned_departure_airport = '' OR UPPER(planned_departure_airport) = UPPER(?))
               AND (planned_destination_airport = '' OR UPPER(planned_destination_airport) = UPPER(?))
             ORDER BY scheduled_start_time, id
             LIMIT 2"
        );
        $updateSlot = $this->pdo->prepare(
            "UPDATE ipca_flight_schedule_slots
             SET status = 'completed', claimed_dispatch_uuid = ?,
                 claimed_at = COALESCE(claimed_at, CURRENT_TIMESTAMP(3))
             WHERE id = ? AND status = 'scheduled'
               AND (claimed_dispatch_uuid IS NULL OR claimed_dispatch_uuid = '')"
        );
        $updateDispatch = $this->pdo->prepare(
            "UPDATE ipca_cvr_dispatches SET scheduler_record_id = ?
             WHERE id = ? AND (scheduler_record_id IS NULL OR scheduler_record_id = '')"
        );

        $this->pdo->beginTransaction();
        try {
            foreach ($rows as $dispatch) {
                $match->execute(array(
                    (int)$dispatch['aircraft_id'],
                    (string)$dispatch['scheduled_date'],
                    (string)$dispatch['mission_code'],
                    strtoupper(trim((string)$dispatch['departure_airport'])),
                    strtoupper(trim((string)$dispatch['destination_airport'])),
                ));
                $slots = $match->fetchAll(PDO::FETCH_ASSOC) ?: array();
                if (count($slots) !== 1) {
                    continue;
                }
                $slot = $slots[0];
                $updateSlot->execute(array((string)$dispatch['dispatch_uuid'], (int)$slot['id']));
                if ($updateSlot->rowCount() !== 1) {
                    continue;
                }
                $updateDispatch->execute(array((string)$slot['scheduler_record_id'], (int)$dispatch['id']));
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param list<int> $slotIds @return array<int,list<array<string,mixed>>> */
    private function crewBySlotIds(array $slotIds): array
    {
        if ($slotIds === array()) {
            return array();
        }
        $placeholders = implode(',', array_fill(0, count($slotIds), '?'));
        $pilotColumn = $this->scheduleCrewHasPilotFunction()
            ? 'pilot_function'
            : "'NONE' AS pilot_function";
        $picColumn = $this->scheduleCrewHasIsPic()
            ? 'is_pic'
            : '0 AS is_pic';
        $statement = $this->pdo->prepare(
            "SELECT schedule_slot_id, user_id, person_name_snapshot, crew_role, $pilotColumn, $picColumn
             FROM ipca_flight_schedule_crew WHERE schedule_slot_id IN ($placeholders)
             ORDER BY id ASC"
        );
        $statement->execute($slotIds);
        $result = array();
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row) {
            $result[(int)$row['schedule_slot_id']][] = array(
                'person_id' => $row['user_id'] !== null ? (int)$row['user_id'] : null,
                'person_name' => (string)$row['person_name_snapshot'],
                'role' => (string)$row['crew_role'],
                'pilot_function' => CvrDutyAssignmentIdentityService::normalizePilotFunction(
                    (string)($row['pilot_function'] ?? 'NONE')
                ),
                'is_pic' => (bool)($row['is_pic'] ?? false),
            );
        }
        return $result;
    }

    /** @param list<array<string,mixed>> $crew @return array<string,mixed> */
    private function payload(
        array $row,
        array $crew,
        bool $deriveOperationalCompletion = true
    ): array
    {
        $hasDispatch = (int)($row['dispatch_id'] ?? 0) > 0
            || trim((string)($row['claimed_dispatch_uuid'] ?? '')) !== '';
        $hasFlightData = (bool)($row['has_flight_data'] ?? false);
        $hasClosure = (bool)($row['has_closure'] ?? false);
        $isOperationalSession = trim((string)($row['linked_operational_session_uuid'] ?? '')) !== '';
        $hasDisplayClosure = $hasClosure && ($deriveOperationalCompletion || !$isOperationalSession);
        $status = $hasDisplayClosure ? 'completed' : (string)$row['status'];
        $editable = $status === 'scheduled' && !$hasDispatch;
        $canUndispatch = $status === 'claimed'
            && $hasDispatch
            && !$hasDisplayClosure
            && !$hasFlightData
            && empty($row['has_audio']);
        $canAdminUndispatch = $status === 'claimed'
            && $hasDispatch
            && !$hasDisplayClosure;
        $canAdminCheckIn = $status === 'claimed'
            && $hasDispatch
            && !$hasDisplayClosure
            && $isOperationalSession;
        $payload = array(
            'scheduler_record_id' => (string)$row['scheduler_record_id'],
            'reservation_type' => (string)($row['reservation_type'] ?? 'flight_training'),
            'reservation_type_label' => self::RESERVATION_TYPES[(string)($row['reservation_type'] ?? '')] ?? 'Other',
            'scheduled_date' => (string)$row['scheduled_date'],
            'scheduled_start_time' => $this->iso((string)$row['scheduled_start_time']),
            'scheduled_end_time' => $this->iso((string)$row['scheduled_end_time']),
            'aircraft' => array(
                'id' => (int)$row['aircraft_id'],
                'registration' => (string)$row['aircraft_registration'],
            ),
            'mission' => array(
                'id' => $row['mission_id'] !== null ? (int)$row['mission_id'] : null,
                'code' => (string)$row['resolved_mission_code'],
                'name' => (string)$row['mission_name'],
            ),
            'cohort' => array(
                'id' => ($row['cohort_id'] ?? null) !== null ? (int)$row['cohort_id'] : null,
                'name' => (string)($row['cohort_name'] ?? ''),
            ),
            'planned_departure_airport' => (string)$row['planned_departure_airport'],
            'planned_destination_airport' => (string)$row['planned_destination_airport'],
            'airport_chain' => array(
                (string)$row['planned_departure_airport'],
                (string)$row['planned_destination_airport'],
            ),
            'legs' => array(),
            'crew' => $crew,
            'status' => $status,
            'editable' => $editable,
            'can_undispatch' => $canUndispatch,
            'can_admin_undispatch' => $canAdminUndispatch,
            'can_admin_check_in' => $canAdminCheckIn,
            'lock_reason' => $editable ? null : ($hasDisplayClosure ? 'completed' : 'dispatch_claimed'),
            'claimed_dispatch_uuid' => trim((string)($row['claimed_dispatch_uuid'] ?? '')) ?: null,
            'claimed_at' => isset($row['claimed_at'])
                ? $this->isoPrecise((string)$row['claimed_at'])
                : null,
            'dispatch_context' => array(
                'dispatch_id' => isset($row['dispatch_id']) ? (int)$row['dispatch_id'] : null,
                'dispatch_uuid' => trim((string)($row['linked_dispatch_uuid'] ?? '')) ?: null,
                'workflow_flight_record_uuid' => trim((string)($row['workflow_flight_record_uuid'] ?? '')) ?: null,
                'operational_session_uuid' => trim((string)($row['linked_operational_session_uuid'] ?? '')) ?: null,
                'starting_hobbs' => isset($row['dispatch_starting_hobbs'])
                    ? (float)$row['dispatch_starting_hobbs']
                    : null,
                'starting_tacho' => isset($row['dispatch_starting_tacho'])
                    ? (float)$row['dispatch_starting_tacho']
                    : null,
                'fuel_onboard' => isset($row['dispatch_fuel_onboard'])
                    ? (string)$row['dispatch_fuel_onboard']
                    : null,
            ),
            'evidence' => array(
                'dispatch' => array(
                    'present' => $hasDispatch,
                    'version' => isset($row['dispatch_version']) ? (int)$row['dispatch_version'] : null,
                ),
                'flight' => array('present' => $hasFlightData),
                'audio' => array('present' => (bool)($row['has_audio'] ?? false)),
                'briefing' => array('present' => (bool)($row['has_completed_briefing'] ?? false)),
            ),
            'notes' => (string)$row['notes'],
            'updated_at' => $this->isoPrecise((string)($row['updated_at'] ?? '')),
        );

        // Phase 2B dual-read: additive only when flag enabled; never mutates legacy rows.
        $organizationId = (int)($row['organization_id'] ?? 0);
        $slotId = isset($row['id']) ? (string)$row['id'] : null;
        try {
            $projection = $this->identityRead()->projectScheduleIdentity(
                $organizationId,
                (string)$row['scheduler_record_id'],
                $slotId
            );
            $payload = $this->identityRead()->mergeProjection($payload, $projection);
            $legs = $this->scheduleLegsForPayload($organizationId, (string)($payload['reservation_uuid'] ?? ''));
            if ($legs !== array()) {
                $payload['legs'] = $legs;
                $chain = array();
                foreach ($legs as $index => $leg) {
                    $origin = strtoupper(trim((string)($leg['origin_airport'] ?? '')));
                    $destination = strtoupper(trim((string)($leg['destination_airport'] ?? '')));
                    if ($index === 0 && $origin !== '') {
                        $chain[] = $origin;
                    }
                    if ($destination !== '') {
                        $chain[] = $destination;
                    }
                }
                if (count($chain) >= 2) {
                    $payload['airport_chain'] = $chain;
                    $payload['planned_departure_airport'] = $chain[0];
                    $payload['planned_destination_airport'] = $chain[count($chain) - 1];
                }
            } else {
                $dep = strtoupper(trim((string)$payload['planned_departure_airport']));
                $arr = strtoupper(trim((string)$payload['planned_destination_airport']));
                $payload['airport_chain'] = array_values(array_filter(array($dep, $arr), static fn(string $code): bool => $code !== ''));
                if ($dep !== '' && $arr !== '') {
                    $payload['legs'] = array(array(
                        'sequence_number' => 1,
                        'leg_uuid' => $payload['leg_uuid'] ?? null,
                        'origin_airport' => $dep,
                        'destination_airport' => $arr,
                    ));
                }
            }
        } catch (Throwable) {
            $dep = strtoupper(trim((string)$payload['planned_departure_airport']));
            $arr = strtoupper(trim((string)$payload['planned_destination_airport']));
            $payload['airport_chain'] = array_values(array_filter(array($dep, $arr), static fn(string $code): bool => $code !== ''));
            if ($dep !== '' && $arr !== '') {
                $payload['legs'] = array(array(
                    'sequence_number' => 1,
                    'leg_uuid' => null,
                    'origin_airport' => $dep,
                    'destination_airport' => $arr,
                ));
            }
        }
        return $payload;
    }

    /**
     * Attach completed-leg operational meters/times for hover + locked completed modal.
     *
     * @param list<array<string,mixed>> $payloads
     * @return list<array<string,mixed>>
     */
    private function attachOperationalLegDetails(array $payloads): array
    {
        $schedulerIds = array();
        foreach ($payloads as $payload) {
            $id = strtolower(trim((string)($payload['scheduler_record_id'] ?? '')));
            if ($id !== '') {
                $schedulerIds[$id] = true;
            }
        }
        if ($schedulerIds === array()) {
            return $payloads;
        }
        $opsByScheduler = $this->operationalLegsBySchedulerRecordIds(array_keys($schedulerIds));
        foreach ($payloads as &$payload) {
            $id = strtolower(trim((string)($payload['scheduler_record_id'] ?? '')));
            $ops = $opsByScheduler[$id] ?? array();
            $legs = is_array($payload['legs'] ?? null) ? $payload['legs'] : array();
            if ($legs === array() && $ops !== array()) {
                $legs = array_map(static function (array $op): array {
                    return array(
                        'sequence_number' => (int)($op['sequence_number'] ?? 1),
                        'origin_airport' => (string)($op['origin_airport'] ?? ''),
                        'destination_airport' => (string)($op['destination_airport'] ?? ''),
                    );
                }, $ops);
            }
            $payload['legs'] = $this->mergeLegOperationalDetails($legs, $ops);
        }
        unset($payload);
        return $payloads;
    }

    /**
     * @param list<string> $schedulerRecordIds
     * @return array<string,list<array<string,mixed>>>
     */
    private function operationalLegsBySchedulerRecordIds(array $schedulerRecordIds): array
    {
        $schedulerRecordIds = array_values(array_filter(array_map(
            static fn(string $id): string => strtolower(trim($id)),
            $schedulerRecordIds
        )));
        if ($schedulerRecordIds === array()) {
            return array();
        }
        $placeholders = implode(',', array_fill(0, count($schedulerRecordIds), '?'));
        $sql = "
            SELECT
                LOWER(d.scheduler_record_id) AS scheduler_record_id,
                d.workflow_flight_record_uuid,
                CAST(d.starting_hobbs AS DECIMAL(12,2)) AS starting_hobbs,
                CAST(d.starting_tacho AS DECIMAL(12,2)) AS starting_tacho,
                d.fuel_onboard,
                CAST(COALESCE(adj.ending_hobbs, fc.ending_hobbs) AS DECIMAL(12,2)) AS ending_hobbs,
                CAST(COALESCE(adj.ending_tacho, fc.ending_tacho) AS DECIMAL(12,2)) AS ending_tacho,
                COALESCE(adj.fuel_remaining, fc.fuel_remaining) AS fuel_remaining,
                dv.payload_json,
                COALESCE(
                    NULLIF(adj.departure_airport, ''),
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(fc.payload_json, '$.evidence.verified_departure_airport')), 'null'),
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(dv.payload_json, '$.dispatch.planned_departure_airport')), 'null')
                ) AS departure_airport,
                COALESCE(
                    NULLIF(adj.arrival_airport, ''),
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(fc.payload_json, '$.evidence.verified_destination_airport')), 'null'),
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(dv.payload_json, '$.dispatch.planned_destination_airport')), 'null')
                ) AS arrival_airport,
                (
                    SELECT e.timestamp_utc
                    FROM ipca_cvr_flight_events e
                    WHERE e.workflow_flight_record_uuid = d.workflow_flight_record_uuid
                      AND e.event_type = 'engine_start_off_block'
                    ORDER BY e.timestamp_utc ASC
                    LIMIT 1
                ) AS off_block_utc,
                CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(fc.payload_json, '$.evidence.on_block_utc')), 'null') AS DATETIME) AS closure_on_block_utc
            FROM ipca_cvr_dispatches d
            LEFT JOIN ipca_cvr_dispatch_versions dv
              ON dv.dispatch_id = d.id AND dv.dispatch_version = d.current_version
            LEFT JOIN ipca_cvr_flight_closures fc
              ON fc.id = (
                   SELECT fc2.id FROM ipca_cvr_flight_closures fc2
                   WHERE fc2.workflow_flight_record_uuid = d.workflow_flight_record_uuid
                   ORDER BY fc2.id DESC LIMIT 1
                 )
            LEFT JOIN ipca_cvr_flight_log_adjustments adj
              ON adj.id = (
                   SELECT a2.id FROM ipca_cvr_flight_log_adjustments a2
                   WHERE a2.workflow_flight_record_uuid = d.workflow_flight_record_uuid
                   ORDER BY a2.id DESC LIMIT 1
                 )
            WHERE LOWER(d.scheduler_record_id) IN ({$placeholders})
              AND LOWER(TRIM(COALESCE(d.status, ''))) <> 'released'
            ORDER BY off_block_utc ASC, d.id ASC
        ";
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($schedulerRecordIds);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return array();
        }
        if (!is_array($rows) || $rows === array()) {
            return array();
        }

        $blockTimes = new CvrOperationalBlockTimeService();
        $grouped = array();
        foreach ($rows as $row) {
            $schedulerId = strtolower(trim((string)($row['scheduler_record_id'] ?? '')));
            if ($schedulerId === '') {
                continue;
            }
            $segments = $this->legSegmentsFromDispatchRow($row);
            if ($segments !== array()) {
                foreach ($segments as $segment) {
                    $offUtc = trim((string)($segment['off_block_utc'] ?? ''));
                    $onUtc = trim((string)($segment['on_block_utc'] ?? ''));
                    if ($onUtc === '') {
                        $onUtc = (string)($blockTimes->derivedOnBlockUtc(array(
                            'off_block_utc' => $offUtc !== '' ? $offUtc : null,
                            'starting_hobbs' => $segment['starting_hobbs'] ?? null,
                            'ending_hobbs' => $segment['ending_hobbs'] ?? null,
                        )) ?? '');
                    }
                    $hobbsHours = $blockTimes->engineTimeHours(
                        $segment['starting_hobbs'] ?? null,
                        $segment['ending_hobbs'] ?? null
                    );
                    $grouped[$schedulerId][] = array(
                        'sequence_number' => (int)($segment['sequence_number'] ?? (count($grouped[$schedulerId] ?? array()) + 1)),
                        'workflow_flight_record_uuid' => (string)($row['workflow_flight_record_uuid'] ?? ''),
                        'origin_airport' => strtoupper(trim((string)($segment['departure_airport'] ?? ''))),
                        'destination_airport' => strtoupper(trim((string)($segment['arrival_airport'] ?? ''))),
                        'off_block_local' => $this->californiaClock($offUtc !== '' ? $offUtc : null),
                        'on_block_local' => $this->californiaClock($onUtc !== '' ? $onUtc : null),
                        'starting_hobbs' => is_numeric($segment['starting_hobbs'] ?? null) ? (float)$segment['starting_hobbs'] : null,
                        'ending_hobbs' => is_numeric($segment['ending_hobbs'] ?? null) ? (float)$segment['ending_hobbs'] : null,
                        'starting_tacho' => is_numeric($segment['starting_tacho'] ?? null) ? (float)$segment['starting_tacho'] : null,
                        'ending_tacho' => is_numeric($segment['ending_tacho'] ?? null) ? (float)$segment['ending_tacho'] : null,
                        'fuel_onboard' => isset($segment['fuel_onboard']) && $segment['fuel_onboard'] !== null && $segment['fuel_onboard'] !== ''
                            ? (string)$segment['fuel_onboard']
                            : null,
                        'fuel_remaining' => isset($segment['fuel_remaining']) && $segment['fuel_remaining'] !== null && $segment['fuel_remaining'] !== ''
                            ? (string)$segment['fuel_remaining']
                            : null,
                        'hobbs_hours' => $hobbsHours,
                    );
                }
                continue;
            }
            $offUtc = trim((string)($row['off_block_utc'] ?? ''));
            $onUtc = $blockTimes->derivedOnBlockUtc(array(
                'off_block_utc' => $offUtc !== '' ? $offUtc : null,
                'starting_hobbs' => $row['starting_hobbs'] ?? null,
                'ending_hobbs' => $row['ending_hobbs'] ?? null,
                'closure_on_block_utc' => $row['closure_on_block_utc'] ?? null,
            ));
            $hobbsHours = $blockTimes->engineTimeHours($row['starting_hobbs'] ?? null, $row['ending_hobbs'] ?? null);
            $grouped[$schedulerId][] = array(
                'sequence_number' => count($grouped[$schedulerId] ?? array()) + 1,
                'workflow_flight_record_uuid' => (string)($row['workflow_flight_record_uuid'] ?? ''),
                'origin_airport' => strtoupper(trim((string)($row['departure_airport'] ?? ''))),
                'destination_airport' => strtoupper(trim((string)($row['arrival_airport'] ?? ''))),
                'off_block_local' => $this->californiaClock($offUtc !== '' ? $offUtc : null),
                'on_block_local' => $this->californiaClock($onUtc),
                'starting_hobbs' => is_numeric($row['starting_hobbs'] ?? null) ? (float)$row['starting_hobbs'] : null,
                'ending_hobbs' => is_numeric($row['ending_hobbs'] ?? null) ? (float)$row['ending_hobbs'] : null,
                'starting_tacho' => is_numeric($row['starting_tacho'] ?? null) ? (float)$row['starting_tacho'] : null,
                'ending_tacho' => is_numeric($row['ending_tacho'] ?? null) ? (float)$row['ending_tacho'] : null,
                'fuel_onboard' => $row['fuel_onboard'] !== null && $row['fuel_onboard'] !== ''
                    ? (string)$row['fuel_onboard']
                    : null,
                'fuel_remaining' => $row['fuel_remaining'] !== null && $row['fuel_remaining'] !== ''
                    ? (string)$row['fuel_remaining']
                    : null,
                'hobbs_hours' => $hobbsHours,
            );
        }
        return $grouped;
    }

    /**
     * @param array<string,mixed> $row
     * @return list<array<string,mixed>>
     */
    private function legSegmentsFromDispatchRow(array $row): array
    {
        $payloadRaw = (string)($row['payload_json'] ?? '');
        if ($payloadRaw === '') {
            return array();
        }
        $payload = json_decode($payloadRaw, true);
        if (!is_array($payload) || !is_array($payload['leg_segments'] ?? null)) {
            return array();
        }
        $segments = array();
        foreach ($payload['leg_segments'] as $segment) {
            if (is_array($segment)) {
                $segments[] = $segment;
            }
        }
        return $segments;
    }

    /**
     * @param list<array<string,mixed>> $legs
     * @param list<array<string,mixed>> $ops
     * @return list<array<string,mixed>>
     */
    private function mergeLegOperationalDetails(array $legs, array $ops): array
    {
        if ($legs === array()) {
            return $ops;
        }
        $used = array();
        foreach ($legs as $index => $leg) {
            if (!is_array($leg)) {
                continue;
            }
            $match = null;
            $origin = strtoupper(trim((string)($leg['origin_airport'] ?? '')));
            $destination = strtoupper(trim((string)($leg['destination_airport'] ?? '')));
            foreach ($ops as $opIndex => $op) {
                if (isset($used[$opIndex])) {
                    continue;
                }
                $opOrigin = strtoupper(trim((string)($op['origin_airport'] ?? '')));
                $opDestination = strtoupper(trim((string)($op['destination_airport'] ?? '')));
                if ($origin !== '' && $destination !== ''
                    && $opOrigin === $origin && $opDestination === $destination) {
                    $match = $op;
                    $used[$opIndex] = true;
                    break;
                }
            }
            if ($match === null && isset($ops[$index]) && !isset($used[$index])) {
                $match = $ops[$index];
                $used[$index] = true;
            }
            if (is_array($match)) {
                foreach (array(
                    'workflow_flight_record_uuid',
                    'off_block_local',
                    'on_block_local',
                    'starting_hobbs',
                    'ending_hobbs',
                    'starting_tacho',
                    'ending_tacho',
                    'fuel_onboard',
                    'fuel_remaining',
                    'hobbs_hours',
                ) as $key) {
                    if (array_key_exists($key, $match) && $match[$key] !== null && $match[$key] !== '') {
                        $leg[$key] = $match[$key];
                    }
                }
                if ($origin === '' && !empty($match['origin_airport'])) {
                    $leg['origin_airport'] = $match['origin_airport'];
                }
                if ($destination === '' && !empty($match['destination_airport'])) {
                    $leg['destination_airport'] = $match['destination_airport'];
                }
            }
            $legs[$index] = $leg;
        }
        return array_values($legs);
    }

    private function californiaClock(?string $utcDateTime): ?string
    {
        $raw = trim((string)$utcDateTime);
        if ($raw === '') {
            return null;
        }
        try {
            $utc = new DateTimeImmutable($raw, new DateTimeZone('UTC'));
            return $utc->setTimezone(new DateTimeZone('America/Los_Angeles'))->format('H:i');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function scheduleLegsForPayload(int $organizationId, string $reservationUuid): array
    {
        $reservationUuid = strtolower(trim($reservationUuid));
        if ($organizationId < 1 || !$this->isUuid($reservationUuid)) {
            return array();
        }
        if (!$this->identityRead()->isDualReadEnabled()) {
            return array();
        }
        try {
            $legs = $this->identityWrite()->listLegsForReservation($reservationUuid);
        } catch (Throwable) {
            return array();
        }
        $payloadLegs = array();
        foreach ($legs as $leg) {
            if ((int)($leg['organization_id'] ?? 0) !== $organizationId) {
                continue;
            }
            $payloadLegs[] = array(
                'sequence_number' => (int)($leg['sequence_number'] ?? 0),
                'leg_uuid' => (string)($leg['leg_uuid'] ?? ''),
                'origin_airport' => strtoupper(trim((string)($leg['origin_airport'] ?? ''))),
                'destination_airport' => strtoupper(trim((string)($leg['destination_airport'] ?? ''))),
                'status' => (string)($leg['status'] ?? 'scheduled'),
            );
        }
        usort(
            $payloadLegs,
            static fn(array $a, array $b): int => ((int)$a['sequence_number']) <=> ((int)$b['sequence_number'])
        );
        return $payloadLegs;
    }

    /**
     * Normalize an airport chain for one reservation.
     * Accepts airport_chain[], legs[][{origin,destination}], or legacy departure/destination.
     *
     * @param array<string,mixed> $values
     * @return list<string>
     */
    public function normalizeAirportChain(array $values): array
    {
        $chain = array();
        if (isset($values['airport_chain']) && is_array($values['airport_chain'])) {
            foreach ($values['airport_chain'] as $airport) {
                $code = $this->airport($airport);
                if ($code !== '') {
                    $chain[] = $code;
                }
            }
        } elseif (isset($values['legs']) && is_array($values['legs'])) {
            foreach ($values['legs'] as $index => $leg) {
                if (!is_array($leg)) {
                    continue;
                }
                $origin = $this->airport($leg['origin'] ?? $leg['origin_airport'] ?? $leg['departure'] ?? '');
                $destination = $this->airport(
                    $leg['destination'] ?? $leg['destination_airport'] ?? $leg['arrival'] ?? ''
                );
                if ($index === 0 && $origin !== '') {
                    $chain[] = $origin;
                } elseif ($index > 0 && $origin !== '' && ($chain === array() || $chain[count($chain) - 1] !== $origin)) {
                    throw new RuntimeException(
                        'Multi-leg airports must form a continuous chain (arrival of leg N = departure of leg N+1).'
                    );
                }
                if ($destination !== '') {
                    $chain[] = $destination;
                }
            }
        } else {
            $departure = $this->airport($values['planned_departure_airport'] ?? '');
            $destination = $this->airport($values['planned_destination_airport'] ?? '');
            if ($departure !== '') {
                $chain[] = $departure;
            }
            if ($destination !== '') {
                $chain[] = $destination;
            }
        }
        $chain = array_values($chain);
        if (count($chain) < 2) {
            throw new RuntimeException('Departure and destination airports are required.');
        }
        for ($i = 0; $i < count($chain) - 1; $i++) {
            // Allow same-airport legs (e.g. KPSP → KPSP training patterns).
            if ($chain[$i] === '') {
                throw new RuntimeException('Airport codes cannot be blank.');
            }
        }
        return $chain;
    }

    /**
     * Crew is reservation-scoped. Reject per-leg crew payloads that diverge (PIC swap / different people).
     *
     * @param array<string,mixed> $values
     * @param list<array<string,mixed>> $crew
     */
    public function assertReservationScopedCrew(array $values, array $crew, int $legCount): void
    {
        if (isset($values['crew_per_leg']) && is_array($values['crew_per_leg']) && $values['crew_per_leg'] !== array()) {
            $normalizedReservation = $this->normalizeCrewFingerprint($crew);
            foreach ($values['crew_per_leg'] as $legCrew) {
                if (!is_array($legCrew)) {
                    continue;
                }
                if ($this->normalizeCrewFingerprint($legCrew) !== $normalizedReservation) {
                    throw new RuntimeException(
                        'Different crew or a PIC role swap requires a separate reservation. One multi-leg reservation must keep the same crew and roles.'
                    );
                }
            }
        }
        if ($legCount > 1 && isset($values['allow_crew_swap']) && (string)$values['allow_crew_swap'] === '1') {
            throw new RuntimeException(
                'Different crew or a PIC role swap requires a separate reservation. One multi-leg reservation must keep the same crew and roles.'
            );
        }
    }

    /**
     * @param list<array<string,mixed>> $crew
     */
    private function normalizeCrewFingerprint(array $crew): string
    {
        $parts = array();
        foreach ($crew as $member) {
            if (!is_array($member)) {
                continue;
            }
            $personId = (int)($member['user_id'] ?? $member['person_id'] ?? 0);
            $name = strtolower(trim((string)($member['person_name'] ?? $member['name'] ?? '')));
            $role = strtolower(trim((string)($member['role'] ?? $member['crew_role'] ?? '')));
            $pilotFunction = CvrDutyAssignmentIdentityService::normalizePilotFunction(
                (string)($member['pilot_function'] ?? 'NONE')
            );
            $isPic = (bool)($member['is_pic'] ?? false)
                || strtolower($role) === 'pic';
            if ($personId <= 0 && $name === '') {
                continue;
            }
            $parts[] = $personId . ':' . $name . ':' . $role . ':' . $pilotFunction . ':' . (int)$isPic;
        }
        sort($parts);
        return implode('|', $parts);
    }

    private function scheduleCrewHasPilotFunction(): bool
    {
        try {
            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $columns = $this->pdo->query("PRAGMA table_info('ipca_flight_schedule_crew')");
                foreach ($columns?->fetchAll(PDO::FETCH_ASSOC) ?: array() as $column) {
                    if (strcasecmp((string)($column['name'] ?? ''), 'pilot_function') === 0) {
                        return true;
                    }
                }
                return false;
            }
            $stmt = $this->pdo->query(
                "SHOW COLUMNS FROM ipca_flight_schedule_crew LIKE 'pilot_function'"
            );
            return $stmt !== false && $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            return false;
        }
    }

    private function scheduleCrewHasIsPic(): bool
    {
        try {
            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $columns = $this->pdo->query("PRAGMA table_info('ipca_flight_schedule_crew')");
                foreach ($columns?->fetchAll(PDO::FETCH_ASSOC) ?: array() as $column) {
                    if (strcasecmp((string)($column['name'] ?? ''), 'is_pic') === 0) {
                        return true;
                    }
                }
                return false;
            }
            $stmt = $this->pdo->query("SHOW COLUMNS FROM ipca_flight_schedule_crew LIKE 'is_pic'");
            return $stmt !== false && $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param list<int> $crewUserIds */
    private function assertNoResourceConflicts(
        string $recordId,
        int $aircraftId,
        ?int $cohortId,
        array $crewUserIds,
        string $start,
        string $end
    ): void {
        $warnings = $this->resourceConflictWarnings(
            $recordId,
            $aircraftId,
            $cohortId,
            $crewUserIds,
            $start,
            $end
        );
        if ($warnings !== array()) {
            throw new RuntimeException($warnings[0]);
        }
    }

    /**
     * @param list<int> $crewUserIds
     * @return list<string>
     */
    private function resourceConflictWarnings(
        string $recordId,
        int $aircraftId,
        ?int $cohortId,
        array $crewUserIds,
        string $start,
        string $end
    ): array {
        $warnings = array();
        $aircraftConflict = $this->pdo->prepare(
            "SELECT scheduler_record_id FROM ipca_flight_schedule_slots
             WHERE scheduler_record_id <> ?
               AND aircraft_id = ?
               AND status IN ('scheduled', 'claimed')
               AND scheduled_start_time < ?
               AND scheduled_end_time > ?
             LIMIT 1 FOR UPDATE"
        );
        $aircraftConflict->execute(array($recordId, $aircraftId, $end, $start));
        if ($aircraftConflict->fetchColumn() !== false) {
            $warnings[] = 'The selected aircraft is already reserved during this time.';
        }

        if ($cohortId !== null) {
            $cohortConflict = $this->pdo->prepare(
                "SELECT scheduler_record_id FROM ipca_flight_schedule_slots
                 WHERE scheduler_record_id <> ?
                   AND cohort_id = ?
                   AND status IN ('scheduled', 'claimed')
                   AND scheduled_start_time < ?
                   AND scheduled_end_time > ?
                 LIMIT 1 FOR UPDATE"
            );
            $cohortConflict->execute(array($recordId, $cohortId, $end, $start));
            if ($cohortConflict->fetchColumn() !== false) {
                $warnings[] = 'The selected cohort already has a reservation during this time.';
            }
        }

        if ($crewUserIds !== array()) {
            $placeholders = implode(',', array_fill(0, count($crewUserIds), '?'));
            $crewConflict = $this->pdo->prepare(
                "SELECT c.user_id
                 FROM ipca_flight_schedule_crew c
                 INNER JOIN ipca_flight_schedule_slots s ON s.id = c.schedule_slot_id
                 WHERE s.scheduler_record_id <> ?
                   AND c.user_id IN ($placeholders)
                   AND s.status IN ('scheduled', 'claimed')
                   AND s.scheduled_start_time < ?
                   AND s.scheduled_end_time > ?
                 LIMIT 1 FOR UPDATE"
            );
            $crewConflict->execute(array_merge(array($recordId), $crewUserIds, array($end, $start)));
            if ($crewConflict->fetchColumn() !== false) {
                $warnings[] = 'A selected crew member is already reserved during this time.';
            }
        }
        return $warnings;
    }

    /**
     * Resolve organization ownership for a new schedule row from trusted server-side context.
     * Posted organization_id is an optional consistency assertion only — never authoritative.
     * Never relies on the database DEFAULT for organization_id.
     *
     * @param array<string,mixed> $values
     */
    private function requireOrganizationIdForCreate(array $values, ?int $missionId): int
    {
        $trusted = $this->resolveTrustedOrganizationId($missionId);
        if ($trusted < 1) {
            throw new RuntimeException('Organization context is required to create a schedule reservation.');
        }

        if (array_key_exists('organization_id', $values) && $values['organization_id'] !== null && $values['organization_id'] !== '') {
            if (!is_numeric($values['organization_id'])) {
                throw new RuntimeException('Organization context does not match this schedule reservation.');
            }
            $posted = (int)$values['organization_id'];
            if ($posted < 1 || $posted !== $trusted) {
                throw new RuntimeException('Organization context does not match this schedule reservation.');
            }
        }

        return $trusted;
    }

    /**
     * Trusted organization resolution for online schedule creates.
     * Order: authenticated/session org → explicit mission ownership → unambiguous catalog org.
     */
    private function resolveTrustedOrganizationId(?int $missionId): int
    {
        $sessionOrg = $this->authenticatedOrganizationId();
        if ($sessionOrg >= 1) {
            return $sessionOrg;
        }

        if ($missionId !== null && $missionId > 0) {
            $stmt = $this->pdo->prepare('SELECT organization_id FROM ipca_missions WHERE id = ? LIMIT 1');
            $stmt->execute(array($missionId));
            $missionOrg = (int)$stmt->fetchColumn();
            if ($missionOrg >= 1) {
                return $missionOrg;
            }
        }

        try {
            $statement = $this->pdo->query(
                'SELECT DISTINCT organization_id
                   FROM ipca_missions
                  WHERE organization_id IS NOT NULL
                    AND organization_id > 0'
            );
            $rows = $statement ? $statement->fetchAll(PDO::FETCH_COLUMN) : array();
            if (is_array($rows) && count($rows) === 1 && (int)$rows[0] >= 1) {
                return (int)$rows[0];
            }
        } catch (Throwable) {
        }

        return 0;
    }

    private function authenticatedOrganizationId(): int
    {
        if (function_exists('cw_current_organization_id')) {
            try {
                $resolved = cw_current_organization_id($this->pdo);
                if (is_numeric($resolved) && (int)$resolved >= 1) {
                    return (int)$resolved;
                }
            } catch (Throwable) {
            }
        }
        foreach (array('organization_id', 'cw_organization_id') as $sessionKey) {
            if (!isset($_SESSION[$sessionKey])) {
                continue;
            }
            if (!is_numeric($_SESSION[$sessionKey])) {
                continue;
            }
            $id = (int)$_SESSION[$sessionKey];
            if ($id >= 1) {
                return $id;
            }
        }
        return 0;
    }

    private function isSafeScheduleUserError(Throwable $e): bool
    {
        // Preserve intentional scheduling/validation RuntimeExceptions.
        // PDOException and other technical failures are sanitized for the UI.
        return $e instanceof RuntimeException && !($e instanceof PDOException);
    }

    private function date(string $value): string
    {
        $value = substr(trim($value), 0, 10);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new RuntimeException('A valid scheduled date is required.');
        }
        return $value;
    }

    private function timestamp(string $value, string $field): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new RuntimeException('A valid ' . $field . ' time is required.');
        }
        try {
            return (new DateTimeImmutable($value))->format('Y-m-d H:i:s.v');
        } catch (Throwable) {
            throw new RuntimeException('A valid ' . $field . ' time is required.');
        }
    }

    private function iso(string $value): string
    {
        // Schedule slots are entered and displayed in the aircraft's local time.
        // Return a timezone-free local timestamp so the enrolled device interprets
        // 10:00 as 10:00 local instead of shifting it through the web server zone.
        return str_replace(' ', 'T', substr(trim($value), 0, 19));
    }

    private function isoPrecise(string $value): string
    {
        return str_replace(' ', 'T', substr(trim($value), 0, 23));
    }

    /** @param array<string,mixed> $existing @param array<string,mixed> $payload @param list<array<string,mixed>> $crew */
    private function assertReplacementRetryEquivalent(array $existing, array $payload, array $crew): void
    {
        $incomingMission = strtoupper(trim((string)($payload['mission_code'] ?? '')));
        $storedMission = strtoupper(trim((string)($existing['mission_code'] ?? '')));
        $incomingStart = trim((string)($payload['scheduled_start_time'] ?? ''));
        $incomingEnd = trim((string)($payload['scheduled_end_time'] ?? ''));
        if ((int)($payload['aircraft_id'] ?? 0) !== (int)($existing['aircraft_id'] ?? 0)
            || $incomingMission !== $storedMission
            || ($incomingStart !== ''
                && substr($incomingStart, 0, 19) !== substr((string)$existing['scheduled_start_time'], 0, 19))
            || ($incomingEnd !== ''
                && substr($incomingEnd, 0, 19) !== substr((string)$existing['scheduled_end_time'], 0, 19))
            || $this->replacementCrewSignature($crew)
                !== $this->storedReplacementCrewSignature((int)$existing['id'])) {
            throw new RuntimeException(
                'The replacement reservation was already synchronized. A later material change requires a new replacement UUID.'
            );
        }
    }

    /**
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $payload
     * @param list<array<string,mixed>> $crew
     * @param list<string> $airportChain
     */
    private function assertDeviceCreateRetryEquivalent(
        array $existing,
        array $payload,
        array $crew,
        string $start,
        string $end,
        array $airportChain
    ): void {
        $storedRoute = array();
        $routeStatement = $this->pdo->prepare(
            'SELECT origin_airport, destination_airport
             FROM ipca_operational_reservation_legs
             WHERE reservation_uuid = ? ORDER BY sequence_number ASC'
        );
        $routeStatement->execute(array(strtolower(trim((string)$existing['scheduler_record_id']))));
        foreach ($routeStatement->fetchAll(PDO::FETCH_ASSOC) ?: array() as $index => $leg) {
            $origin = strtoupper(trim((string)($leg['origin_airport'] ?? '')));
            $destination = strtoupper(trim((string)($leg['destination_airport'] ?? '')));
            if ($index === 0 && $origin !== '') {
                $storedRoute[] = $origin;
            }
            if ($destination !== '') {
                $storedRoute[] = $destination;
            }
        }
        $same = (int)($payload['aircraft_id'] ?? 0) === (int)($existing['aircraft_id'] ?? 0)
            && strtolower(trim((string)($payload['reservation_type'] ?? 'flight_training')))
                === strtolower(trim((string)($existing['reservation_type'] ?? '')))
            && strtoupper(trim((string)($payload['mission_code'] ?? '')))
                === strtoupper(trim((string)($existing['mission_code'] ?? '')))
            && substr($start, 0, 19) === substr((string)($existing['scheduled_start_time'] ?? ''), 0, 19)
            && substr($end, 0, 19) === substr((string)($existing['scheduled_end_time'] ?? ''), 0, 19)
            && $airportChain === $storedRoute
            && $this->replacementCrewSignature($crew)
                === $this->storedReplacementCrewSignature((int)$existing['id']);
        if (!$same) {
            throw new RuntimeException(
                'This reservation UUID is already synchronized with different material data. Create a new Local Dispatch.'
            );
        }
    }

    /** @param list<array<string,mixed>> $crew */
    private function replacementCrewSignature(array $crew): string
    {
        $values = array_map(static function (array $member): string {
            $userId = (int)($member['user_id'] ?? $member['person_id'] ?? 0);
            $identity = $userId > 0
                ? 'user:' . $userId
                : 'name:' . strtolower(trim((string)($member['person_name'] ?? '')));
            return implode(':', array(
                $identity,
                strtolower(trim((string)($member['role'] ?? ''))),
                CvrDutyAssignmentIdentityService::normalizePilotFunction(
                    (string)($member['pilot_function'] ?? 'NONE')
                ),
                (bool)($member['is_pic'] ?? false) ? '1' : '0',
            ));
        }, $crew);
        sort($values, SORT_STRING);
        return implode('|', $values);
    }

    private function storedReplacementCrewSignature(int $slotId): string
    {
        $hasFunctions = $this->scheduleCrewHasPilotFunction() && $this->scheduleCrewHasIsPic();
        $statement = $this->pdo->prepare($hasFunctions
            ? 'SELECT user_id, person_name_snapshot, crew_role, pilot_function, is_pic
               FROM ipca_flight_schedule_crew WHERE schedule_slot_id = ?'
            : "SELECT user_id, person_name_snapshot, crew_role, 'NONE' AS pilot_function, 0 AS is_pic
               FROM ipca_flight_schedule_crew WHERE schedule_slot_id = ?");
        $statement->execute(array($slotId));
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return $this->replacementCrewSignature(array_map(static fn(array $row): array => array(
            'user_id' => $row['user_id'] ?? null,
            'person_name' => $row['person_name_snapshot'] ?? '',
            'role' => $row['crew_role'] ?? '',
            'pilot_function' => $row['pilot_function'] ?? 'NONE',
            'is_pic' => (bool)($row['is_pic'] ?? false),
        ), is_array($rows) ? $rows : array()));
    }

    private function airport(mixed $value): string
    {
        return substr((string)preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string)$value))), 0, 8);
    }

    private function isUuid(string $value): bool
    {
        return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $value) === 1;
    }
}
