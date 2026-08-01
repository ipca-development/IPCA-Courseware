<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';

final class FlightScheduleService
{
    private const RESERVATION_TYPES = array(
        'flight_training' => 'Flight Training',
        'briefing' => 'Briefing',
        'simulator_training' => 'Simulator Training',
        'ground_training' => 'Ground Training',
        'other' => 'Other',
    );

    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> */
    public function listSlots(?string $fromDate = null, ?string $toDate = null, ?int $aircraftId = null): array
    {
        $fromDate = $this->date($fromDate ?: gmdate('Y-m-d'));
        $toDate = $this->date($toDate ?: gmdate('Y-m-d', time() + 14 * 86400));
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
              ON d.scheduler_record_id = s.scheduler_record_id
              OR (s.claimed_dispatch_uuid IS NOT NULL AND d.dispatch_uuid = s.claimed_dispatch_uuid)
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
        return array_map(
            fn(array $row): array => $this->payload($row, $crewBySlot[(int)$row['id']] ?? array()),
            $rows
        );
    }

    /** @return list<array<string,mixed>> */
    public function scheduledSessionsForDevice(array $device, ?string $fromDate = null, ?string $toDate = null): array
    {
        $aircraftId = (int)($device['aircraft_id'] ?? 0);
        if ($aircraftId <= 0) {
            throw new RuntimeException('The authenticated CVR device is not assigned to an aircraft.');
        }
        return array_values(array_filter(
            $this->listSlots($fromDate, $toDate, $aircraftId),
            static fn(array $slot): bool => (string)$slot['status'] === 'scheduled'
        ));
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
        $departure = $this->airport($values['planned_departure_airport'] ?? '');
        $destination = $this->airport($values['planned_destination_airport'] ?? '');
        $status = strtolower(trim((string)($values['status'] ?? 'scheduled')));
        if (!in_array($status, array('scheduled', 'cancelled', 'completed'), true)) {
            $status = 'scheduled';
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
            if (is_array($row)) {
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
                $this->pdo->prepare(
                    'INSERT INTO ipca_flight_schedule_slots
                     (scheduler_record_id, reservation_type, scheduled_date, scheduled_start_time, scheduled_end_time,
                      aircraft_id, mission_id, cohort_id, mission_code, planned_departure_airport,
                      planned_destination_airport, status, notes, created_by, updated_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute(array(
                    $recordId, $reservationType, $scheduledDate, $start, $end, $aircraftId, $missionId, $cohortId, $missionCode,
                    $departure, $destination, $status, substr(trim((string)($values['notes'] ?? '')), 0, 1000),
                    $actorUserId, $actorUserId,
                ));
                $slotId = (int)$this->pdo->lastInsertId();
            }
            $insertCrew = $this->pdo->prepare(
                'INSERT INTO ipca_flight_schedule_crew
                 (schedule_slot_id, user_id, person_name_snapshot, crew_role) VALUES (?, ?, ?, ?)'
            );
            foreach ($crew as $member) {
                $name = substr(trim((string)($member['person_name'] ?? '')), 0, 255);
                $role = substr(strtolower(trim((string)($member['role'] ?? ''))), 0, 64);
                if ($name === '' || $role === '') {
                    continue;
                }
                $userId = (int)($member['user_id'] ?? 0) ?: null;
                $insertCrew->execute(array($slotId, $userId, $name, $role));
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return $recordId;
    }

    public function rescheduleSlot(
        string $schedulerRecordId,
        string $scheduledStartTime,
        string $scheduledEndTime,
        ?int $actorUserId = null,
        ?string $expectedUpdatedAt = null
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
            $crewStatement = $this->pdo->prepare(
                'SELECT user_id FROM ipca_flight_schedule_crew'
                . ' WHERE schedule_slot_id = ? AND user_id IS NOT NULL'
            );
            $crewStatement->execute(array((int)$slot['id']));
            $this->assertNoResourceConflicts(
                $schedulerRecordId,
                (int)$slot['aircraft_id'],
                (int)($slot['cohort_id'] ?? 0) ?: null,
                array_map('intval', $crewStatement->fetchAll(PDO::FETCH_COLUMN) ?: array()),
                $start,
                $end
            );
            $this->pdo->prepare(
                'UPDATE ipca_flight_schedule_slots'
                . ' SET scheduled_date = ?, scheduled_start_time = ?, scheduled_end_time = ?, updated_by = ?'
                . ' WHERE id = ?'
            )->execute(array(substr($start, 0, 10), $start, $end, $actorUserId, (int)$slot['id']));
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

    /** @param list<int> $slotIds @return array<int,list<array<string,mixed>>> */
    private function crewBySlotIds(array $slotIds): array
    {
        if ($slotIds === array()) {
            return array();
        }
        $placeholders = implode(',', array_fill(0, count($slotIds), '?'));
        $statement = $this->pdo->prepare(
            "SELECT schedule_slot_id, user_id, person_name_snapshot, crew_role
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
        return array(
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
            'crew' => $crew,
            'status' => $status,
            'editable' => $editable,
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
