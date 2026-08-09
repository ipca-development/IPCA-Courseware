<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';
require_once __DIR__ . '/CvrOperationalIdentityReadService.php';
require_once __DIR__ . '/CvrOperationalIdentityService.php';
require_once __DIR__ . '/CvrDutyAssignmentIdentityService.php';
require_once __DIR__ . '/CvrOperationalBlockTimeService.php';

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
    public function listSlots(?string $fromDate = null, ?string $toDate = null, ?int $aircraftId = null): array
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
                   d.current_version AS dispatch_version,
                   d.last_received_at AS dispatch_received_at,
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
                       AND sd.status IN ('approved', 'released')
                       AND sd.approved_at IS NOT NULL
                   ) AS has_completed_briefing
            FROM ipca_flight_schedule_slots s
            INNER JOIN ipca_aircraft_devices a ON a.id = s.aircraft_id
            LEFT JOIN ipca_missions m ON m.id = s.mission_id
            LEFT JOIN cohorts c ON c.id = s.cohort_id
            LEFT JOIN ipca_cvr_dispatches d
              ON (
                   d.scheduler_record_id = s.scheduler_record_id
                   OR (s.claimed_dispatch_uuid IS NOT NULL AND d.dispatch_uuid = s.claimed_dispatch_uuid)
                 )
              AND LOWER(TRIM(COALESCE(d.status, ''))) <> 'released'
            WHERE s.scheduled_date BETWEEN ? AND ?
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
            fn(array $row): array => $this->payload($row, $crewBySlot[(int)$row['id']] ?? array()),
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
            $this->listSlots($fromDate, $toDate, $aircraftId),
            static fn(array $slot): bool => (string)$slot['status'] === 'scheduled'
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
        $legs = is_array($slot['legs'] ?? null) ? $slot['legs'] : array();
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
    public function saveSlot(array $values, array $crew, ?int $actorUserId = null): string
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
        if ($missionId !== null && $missionCode === '') {
            $missionStatement = $this->pdo->prepare('SELECT code FROM ipca_missions WHERE id = ? LIMIT 1');
            $missionStatement->execute(array($missionId));
            $missionCode = substr(strtoupper(trim((string)$missionStatement->fetchColumn())), 0, 64);
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
            $this->assertNoResourceConflicts(
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
            $hasDutyColumns = $this->scheduleCrewDutyColumnsAvailable();
            $insertCrew = $this->pdo->prepare($hasDutyColumns
                ? 'INSERT INTO ipca_flight_schedule_crew
                   (schedule_slot_id, user_id, person_name_snapshot, crew_role, pilot_function, is_pic)
                   VALUES (?, ?, ?, ?, ?, ?)'
                : 'INSERT INTO ipca_flight_schedule_crew
                   (schedule_slot_id, user_id, person_name_snapshot, crew_role) VALUES (?, ?, ?, ?)');
            foreach ($crew as $member) {
                $name = substr(trim((string)($member['person_name'] ?? '')), 0, 255);
                $role = substr(strtolower(trim((string)($member['role'] ?? ''))), 0, 64);
                if ($name === '' || $role === '') {
                    continue;
                }
                $userId = (int)($member['user_id'] ?? 0) ?: null;
                $params = array($slotId, $userId, $name, $role);
                if ($hasDutyColumns) {
                    $params[] = CvrDutyAssignmentIdentityService::normalizePilotFunction(
                        (string)($member['pilot_function'] ?? 'NONE')
                    );
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
        return $recordId;
    }

    public function rescheduleSlot(
        string $schedulerRecordId,
        string $scheduledStartTime,
        string $scheduledEndTime,
        ?int $actorUserId = null,
        ?string $expectedUpdatedAt = null,
        ?int $aircraftId = null
    ): void {
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
            $this->assertNoResourceConflicts(
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
        $pilotColumn = $this->scheduleCrewDutyColumnsAvailable()
            ? 'pilot_function, is_pic'
            : "'NONE' AS pilot_function, 0 AS is_pic";
        $statement = $this->pdo->prepare(
            "SELECT schedule_slot_id, user_id, person_name_snapshot, crew_role, $pilotColumn
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
    private function payload(array $row, array $crew): array
    {
        $hasDispatch = (int)($row['dispatch_id'] ?? 0) > 0
            || trim((string)($row['claimed_dispatch_uuid'] ?? '')) !== '';
        $hasFlightData = (bool)($row['has_flight_data'] ?? false);
        $hasClosure = (bool)($row['has_closure'] ?? false);
        $status = $hasClosure ? 'completed' : (string)$row['status'];
        $editable = $status === 'scheduled' && !$hasDispatch;
        $canUndispatch = $status === 'claimed'
            && $hasDispatch
            && !$hasClosure
            && !$hasFlightData
            && empty($row['has_audio']);
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
            'lock_reason' => $editable ? null : ($hasClosure ? 'completed' : 'dispatch_claimed'),
            'claimed_dispatch_uuid' => trim((string)($row['claimed_dispatch_uuid'] ?? '')) ?: null,
            'claimed_at' => isset($row['claimed_at'])
                ? $this->isoPrecise((string)$row['claimed_at'])
                : null,
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
            $isPic = (bool)($member['is_pic'] ?? false) || $role === 'pic';
            if ($personId <= 0 && $name === '') {
                continue;
            }
            $parts[] = $personId . ':' . $name . ':' . $role . ':' . $pilotFunction . ':' . (int)$isPic;
        }
        sort($parts);
        return implode('|', $parts);
    }

    private function scheduleCrewDutyColumnsAvailable(): bool
    {
        try {
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

    /** @param list<int> $crewUserIds */
    private function assertNoResourceConflicts(
        string $recordId,
        int $aircraftId,
        ?int $cohortId,
        array $crewUserIds,
        string $start,
        string $end
    ): void {
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
            throw new RuntimeException('The selected aircraft is already reserved during this time.');
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
                throw new RuntimeException('The selected cohort already has a reservation during this time.');
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
                throw new RuntimeException('A selected crew member is already reserved during this time.');
            }
        }
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

    private function airport(mixed $value): string
    {
        return substr((string)preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string)$value))), 0, 8);
    }

    private function isUuid(string $value): bool
    {
        return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $value) === 1;
    }
}
