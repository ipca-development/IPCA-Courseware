<?php
declare(strict_types=1);

require_once __DIR__ . '/SafetySupport.php';
require_once __DIR__ . '/SafetyFeatureConfigService.php';

final class SafetyOccurrenceIntakeContextService
{
    public function __construct(
        private PDO $pdo,
        private SafetyFeatureConfigService $config
    ) {
    }

    /** @param array<string,mixed> $session @return list<array<string,mixed>> */
    public function flightCandidates(array $session, ?string $eventAtUtc = null): array
    {
        $organizationId = SafetySupport::organizationId($session);
        $this->config->requireEnabled($organizationId);
        $userId = (int)$session['user']['id'];
        $event = $eventAtUtc === null || trim($eventAtUtc) === ''
            ? new DateTimeImmutable('now', new DateTimeZone('UTC'))
            : new DateTimeImmutable(SafetySupport::nullableUtc($eventAtUtc) ?? 'now', new DateTimeZone('UTC'));
        $timezoneName = (string)$this->config->get(
            $organizationId,
            'flight_schedule_timezone_iana',
            'America/Los_Angeles'
        );
        try {
            $scheduleTimezone = new DateTimeZone($timezoneName);
        } catch (Throwable) {
            throw new SafetyException('schedule_timezone_invalid', 'The flight schedule timezone is not valid.', 409);
        }
        $local = $event->setTimezone($scheduleTimezone);
        $windowStart = $local->modify('-18 hours')->format('Y-m-d H:i:s.v');
        $windowEnd = $local->modify('+18 hours')->format('Y-m-d H:i:s.v');
        $eventLocal = $local->format('Y-m-d H:i:s.v');
        $stmt = $this->pdo->prepare(
            "SELECT s.id AS schedule_slot_id, s.scheduler_record_id, s.reservation_type,
                    s.scheduled_start_time, s.scheduled_end_time, s.status,
                    s.aircraft_id, a.registration AS aircraft_registration,
                    a.display_name AS aircraft_display_name, a.aircraft_type,
                    s.mission_id, s.mission_code, m.name AS mission_name, s.cohort_id,
                    s.planned_departure_airport, s.planned_destination_airport,
                    d.id AS dispatch_id, d.dispatch_uuid, d.workflow_flight_record_uuid,
                    d.operational_session_uuid,
                    fs.id AS flight_session_id, fs.session_uuid,
                    fs.avionics_on_utc, fs.avionics_off_utc,
                    ABS(TIMESTAMPDIFF(SECOND, s.scheduled_start_time, ?)) AS distance_seconds
             FROM ipca_flight_schedule_slots s
             INNER JOIN ipca_aircraft_devices a ON a.id = s.aircraft_id
             LEFT JOIN ipca_missions m ON m.id = s.mission_id
             LEFT JOIN ipca_cvr_dispatches d ON d.scheduler_record_id = s.scheduler_record_id
             LEFT JOIN ipca_flight_sessions fs
               ON fs.dispatch_uuid = d.dispatch_uuid
               OR (d.workflow_flight_record_uuid IS NOT NULL
                   AND fs.workflow_flight_record_uuid = d.workflow_flight_record_uuid)
             WHERE s.organization_id = ?
               AND EXISTS (
                 SELECT 1 FROM ipca_flight_schedule_crew own_crew
                 WHERE own_crew.schedule_slot_id = s.id AND own_crew.user_id = ?
               )
               AND LOWER(TRIM(s.status)) NOT IN ('cancelled','canceled','superseded')
               AND s.scheduled_end_time >= ? AND s.scheduled_start_time <= ?
             ORDER BY
               CASE
                 WHEN fs.avionics_on_utc IS NOT NULL AND fs.avionics_off_utc IS NOT NULL
                   AND ? BETWEEN fs.avionics_on_utc AND fs.avionics_off_utc THEN 0
                 WHEN ? BETWEEN s.scheduled_start_time AND s.scheduled_end_time THEN 1
                 WHEN s.claimed_dispatch_uuid IS NOT NULL THEN 2
                 ELSE 3
               END,
               distance_seconds, s.id
             LIMIT 20"
        );
        $stmt->execute(array(
            $eventLocal,
            $organizationId,
            $userId,
            $windowStart,
            $windowEnd,
            $event->format('Y-m-d H:i:s.v'),
            $eventLocal,
        ));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $crewStatement = $this->pdo->prepare(
            'SELECT user_id, person_name_snapshot, crew_role, pilot_function, is_pic
             FROM ipca_flight_schedule_crew WHERE schedule_slot_id = ? ORDER BY id'
        );
        $out = array();
        foreach ($rows as $row) {
            $crewStatement->execute(array((int)$row['schedule_slot_id']));
            $crew = array_map(static fn(array $member): array => array(
                'user_id' => $member['user_id'] === null ? null : (int)$member['user_id'],
                'name' => (string)$member['person_name_snapshot'],
                'role' => (string)$member['crew_role'],
                'pilot_function' => (string)$member['pilot_function'],
                'is_pic' => (bool)$member['is_pic'],
            ), $crewStatement->fetchAll(PDO::FETCH_ASSOC));
            $out[] = $this->presentCandidate($row, $crew, $timezoneName);
        }
        return $out;
    }

    /** @param array<string,mixed> $input @return array<string,mixed>|null */
    public function selectedFlight(int $organizationId, int $userId, array $input): ?array
    {
        $choice = strtolower(trim((string)($input['flight_link_choice'] ?? '')));
        if ($choice === '') {
            return null; // Compatibility for reports created by older deployed clients.
        }
        if ($choice === 'no_reservation') {
            return array(
                'link_choice' => 'no_reservation',
                'schedule_slot_id' => null,
                'resolution_state' => 'not_applicable',
                'snapshot' => array('selection_method' => 'reporter_confirmed'),
            );
        }
        if ($choice !== 'scheduled_flight') {
            throw new SafetyException('flight_link_choice_invalid', 'Select a flight or choose no reservation.', 400);
        }
        $slotId = (int)($input['schedule_slot_id'] ?? 0);
        if ($slotId <= 0) {
            throw new SafetyException('schedule_slot_required', 'Select the scheduled flight.', 400);
        }
        $stmt = $this->pdo->prepare(
            "SELECT s.*, a.registration AS aircraft_registration, a.display_name AS aircraft_display_name,
                    a.aircraft_type, m.name AS mission_name
             FROM ipca_flight_schedule_slots s
             INNER JOIN ipca_flight_schedule_crew c
               ON c.schedule_slot_id = s.id AND c.user_id = ?
             INNER JOIN ipca_aircraft_devices a ON a.id = s.aircraft_id
             LEFT JOIN ipca_missions m ON m.id = s.mission_id
             WHERE s.id = ? AND s.organization_id = ?
               AND LOWER(TRIM(s.status)) NOT IN ('cancelled','canceled','superseded')
             LIMIT 1"
        );
        $stmt->execute(array($userId, $slotId, $organizationId));
        $slot = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($slot)) {
            throw new SafetyException('flight_not_available', 'That flight is not available to this reporter.', 403);
        }
        $timezone = (string)$this->config->get(
            $organizationId,
            'flight_schedule_timezone_iana',
            'America/Los_Angeles'
        );
        return array(
            'link_choice' => 'scheduled_flight',
            'schedule_slot_id' => $slotId,
            'resolution_state' => 'pending',
            'aircraft_registration' => (string)$slot['aircraft_registration'],
            'location_text' => $this->route((string)$slot['planned_departure_airport'], (string)$slot['planned_destination_airport']),
            'snapshot' => array(
                'scheduler_record_id' => (string)$slot['scheduler_record_id'],
                'schedule_timezone_iana' => $timezone,
                'scheduled_start_time' => (string)$slot['scheduled_start_time'],
                'scheduled_end_time' => (string)$slot['scheduled_end_time'],
                'aircraft_id' => (int)$slot['aircraft_id'],
                'aircraft_registration' => (string)$slot['aircraft_registration'],
                'aircraft_type' => (string)$slot['aircraft_type'],
                'mission_id' => $slot['mission_id'] === null ? null : (int)$slot['mission_id'],
                'mission_code' => (string)$slot['mission_code'],
                'mission_name' => (string)($slot['mission_name'] ?? ''),
                'cohort_id' => $slot['cohort_id'] === null ? null : (int)$slot['cohort_id'],
                'departure_airport' => (string)$slot['planned_departure_airport'],
                'destination_airport' => (string)$slot['planned_destination_airport'],
                'selection_method' => 'reporter_confirmed',
            ),
        );
    }

    /** @param array<string,mixed> $selection */
    public function persistFlightLink(
        int $organizationId,
        int $reportId,
        int $userId,
        array $selection
    ): void {
        $this->pdo->prepare(
            'INSERT INTO ipca_safety_report_flight_links
             (organization_id, report_id, link_choice, schedule_slot_id, resolution_state,
              selection_method, selected_by_user_id, context_snapshot_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute(array(
            $organizationId,
            $reportId,
            $selection['link_choice'],
            $selection['schedule_slot_id'],
            $selection['resolution_state'],
            'reporter_confirmed',
            $userId,
            SafetySupport::json($selection['snapshot']),
        ));
        if ($selection['link_choice'] === 'scheduled_flight') {
            $this->reconcileReport($organizationId, $reportId);
        }
    }

    public function reconcileReport(int $organizationId, int $reportId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT l.id, l.schedule_slot_id, s.scheduler_record_id
             FROM ipca_safety_report_flight_links l
             INNER JOIN ipca_flight_schedule_slots s ON s.id = l.schedule_slot_id
             WHERE l.organization_id = ? AND l.report_id = ? AND l.link_choice = \'scheduled_flight\'
             LIMIT 1'
        );
        $stmt->execute(array($organizationId, $reportId));
        $link = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($link)) {
            return;
        }
        $dispatchStatement = $this->pdo->prepare(
            'SELECT id, dispatch_uuid, workflow_flight_record_uuid
             FROM ipca_cvr_dispatches WHERE scheduler_record_id = ? ORDER BY id DESC LIMIT 1'
        );
        $dispatchStatement->execute(array($link['scheduler_record_id']));
        $dispatch = $dispatchStatement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($dispatch)) {
            return;
        }
        $sessionStatement = $this->pdo->prepare(
            'SELECT id FROM ipca_flight_sessions
             WHERE dispatch_uuid = ? OR workflow_flight_record_uuid = ?
             ORDER BY id DESC LIMIT 2'
        );
        $sessionStatement->execute(array($dispatch['dispatch_uuid'], $dispatch['workflow_flight_record_uuid']));
        $sessions = $sessionStatement->fetchAll(PDO::FETCH_ASSOC);
        if (count($sessions) > 1) {
            $this->pdo->prepare(
                "UPDATE ipca_safety_report_flight_links
                 SET dispatch_id = ?, resolution_state = 'review_required'
                 WHERE id = ?"
            )->execute(array((int)$dispatch['id'], (int)$link['id']));
            return;
        }
        $sessionId = $sessions === array() ? null : (int)$sessions[0]['id'];
        $recordId = null;
        if ($sessionId !== null) {
            $recordStatement = $this->pdo->prepare(
                'SELECT id FROM ipca_operational_flight_records
                 WHERE session_id = ? ORDER BY id DESC LIMIT 1'
            );
            $recordStatement->execute(array($sessionId));
            $value = $recordStatement->fetchColumn();
            $recordId = $value === false ? null : (int)$value;
        }
        $resolved = $sessionId !== null;
        $this->pdo->prepare(
            'UPDATE ipca_safety_report_flight_links
             SET dispatch_id = ?, flight_session_id = ?, operational_flight_record_id = ?,
                 resolution_state = ?, resolved_at_utc = ?
             WHERE id = ?'
        )->execute(array(
            (int)$dispatch['id'],
            $sessionId,
            $recordId,
            $resolved ? 'resolved' : 'pending',
            $resolved ? SafetySupport::nowUtc() : null,
            (int)$link['id'],
        ));
    }

    /** @return array<string,mixed>|null */
    public function flightLinkForReport(int $organizationId, int $reportId): ?array
    {
        $this->reconcileReport($organizationId, $reportId);
        $stmt = $this->pdo->prepare(
            'SELECT l.link_choice, l.resolution_state, l.schedule_slot_id, l.dispatch_id,
                    l.flight_session_id, l.operational_flight_record_id, l.context_snapshot_json,
                    d.workflow_flight_record_uuid, fs.session_uuid, ofr.flight_record_uuid
             FROM ipca_safety_report_flight_links l
             LEFT JOIN ipca_cvr_dispatches d ON d.id = l.dispatch_id
             LEFT JOIN ipca_flight_sessions fs ON fs.id = l.flight_session_id
             LEFT JOIN ipca_operational_flight_records ofr ON ofr.id = l.operational_flight_record_id
             WHERE l.organization_id = ? AND l.report_id = ? LIMIT 1'
        );
        $stmt->execute(array($organizationId, $reportId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $row['context'] = json_decode((string)($row['context_snapshot_json'] ?? ''), true) ?: null;
        unset($row['context_snapshot_json']);
        $workflowUuid = trim((string)($row['workflow_flight_record_uuid'] ?? ''));
        $sessionUuid = trim((string)($row['session_uuid'] ?? ''));
        $sessionId = (int)($row['flight_session_id'] ?? 0);
        $row['recordings'] = array();
        if ($workflowUuid !== '' || $sessionUuid !== '') {
            $recordingStatement = $this->pdo->prepare(
                'SELECT id, recording_uid, published_transcript_version_id
                 FROM ipca_cockpit_recordings
                 WHERE flight_session_uid = ? OR operational_session_uuid = ?
                 ORDER BY id'
            );
            $recordingStatement->execute(array($workflowUuid, $sessionUuid));
            $row['recordings'] = $recordingStatement->fetchAll(PDO::FETCH_ASSOC);
        }
        $row['garmin_csv_files'] = array();
        if ($workflowUuid !== '' || $sessionId > 0) {
            $garminStatement = $this->pdo->prepare(
                'SELECT id, csv_file_uuid, sha256, evidence_status
                 FROM ipca_garmin_csv_files
                 WHERE workflow_flight_record_uuid = ? OR session_id = ?
                 ORDER BY active_for_session DESC, id'
            );
            $garminStatement->execute(array($workflowUuid, $sessionId));
            $row['garmin_csv_files'] = $garminStatement->fetchAll(PDO::FETCH_ASSOC);
        }
        return $row;
    }

    /** @param array<string,mixed> $row @param list<array<string,mixed>> $crew */
    private function presentCandidate(array $row, array $crew, string $timezone): array
    {
        return array(
            'schedule_slot_id' => (int)$row['schedule_slot_id'],
            'scheduler_record_id' => (string)$row['scheduler_record_id'],
            'reservation_type' => (string)$row['reservation_type'],
            'status' => (string)$row['status'],
            'scheduled_start_time' => (string)$row['scheduled_start_time'],
            'scheduled_end_time' => (string)$row['scheduled_end_time'],
            'schedule_timezone_iana' => $timezone,
            'aircraft_id' => (int)$row['aircraft_id'],
            'aircraft_registration' => (string)$row['aircraft_registration'],
            'aircraft_display_name' => (string)$row['aircraft_display_name'],
            'aircraft_type' => (string)$row['aircraft_type'],
            'mission_id' => $row['mission_id'] === null ? null : (int)$row['mission_id'],
            'mission_code' => (string)$row['mission_code'],
            'mission_name' => (string)($row['mission_name'] ?? ''),
            'cohort_id' => $row['cohort_id'] === null ? null : (int)$row['cohort_id'],
            'departure_airport' => (string)$row['planned_departure_airport'],
            'destination_airport' => (string)$row['planned_destination_airport'],
            'crew' => $crew,
            'dispatch_id' => $row['dispatch_id'] === null ? null : (int)$row['dispatch_id'],
            'workflow_flight_record_uuid' => $row['workflow_flight_record_uuid'],
            'operational_session_uuid' => $row['operational_session_uuid'],
            'flight_session_id' => $row['flight_session_id'] === null ? null : (int)$row['flight_session_id'],
            'actual_start_utc' => $row['avionics_on_utc'],
            'actual_end_utc' => $row['avionics_off_utc'],
        );
    }

    private function route(string $departure, string $destination): ?string
    {
        $departure = trim($departure);
        $destination = trim($destination);
        if ($departure !== '' && $destination !== '') {
            return $departure . ' → ' . $destination;
        }
        return $departure !== '' ? $departure : ($destination !== '' ? $destination : null);
    }
}
