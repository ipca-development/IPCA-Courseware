<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';

final class FlightScheduleService
{
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
                   COALESCE(NULLIF(m.name, ''), NULLIF(s.mission_code, ''), '') AS mission_name
            FROM ipca_flight_schedule_slots s
            INNER JOIN ipca_aircraft_devices a ON a.id = s.aircraft_id
            LEFT JOIN ipca_missions m ON m.id = s.mission_id
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
            if (is_array($row)) {
                if (trim((string)($row['claimed_dispatch_uuid'] ?? '')) !== '') {
                    throw new RuntimeException('A claimed schedule slot cannot be edited.');
                }
                $slotId = (int)$row['id'];
                $this->pdo->prepare(
                    'UPDATE ipca_flight_schedule_slots
                     SET scheduled_date=?, scheduled_start_time=?, scheduled_end_time=?, aircraft_id=?,
                         mission_id=?, mission_code=?, planned_departure_airport=?,
                         planned_destination_airport=?, status=?, notes=?, updated_by=?
                     WHERE id=?'
                )->execute(array(
                    $scheduledDate, $start, $end, $aircraftId, $missionId, $missionCode,
                    $departure, $destination, $status, substr(trim((string)($values['notes'] ?? '')), 0, 1000),
                    $actorUserId, $slotId,
                ));
                $this->pdo->prepare('DELETE FROM ipca_flight_schedule_crew WHERE schedule_slot_id = ?')->execute(array($slotId));
            } else {
                $this->pdo->prepare(
                    'INSERT INTO ipca_flight_schedule_slots
                     (scheduler_record_id, scheduled_date, scheduled_start_time, scheduled_end_time,
                      aircraft_id, mission_id, mission_code, planned_departure_airport,
                      planned_destination_airport, status, notes, created_by, updated_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute(array(
                    $recordId, $scheduledDate, $start, $end, $aircraftId, $missionId, $missionCode,
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
        return array(
            'scheduler_record_id' => (string)$row['scheduler_record_id'],
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
            'planned_departure_airport' => (string)$row['planned_departure_airport'],
            'planned_destination_airport' => (string)$row['planned_destination_airport'],
            'crew' => $crew,
            'status' => (string)$row['status'],
            'notes' => (string)$row['notes'],
        );
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

    private function airport(mixed $value): string
    {
        return substr((string)preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string)$value))), 0, 8);
    }

    private function isUuid(string $value): bool
    {
        return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $value) === 1;
    }
}
