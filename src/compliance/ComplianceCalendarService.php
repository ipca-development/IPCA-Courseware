<?php
declare(strict_types=1);

require_once __DIR__ . '/ComplianceApprovalEngine.php';
require_once __DIR__ . '/ComplianceCalendarRepository.php';
require_once __DIR__ . '/ComplianceMeetingEngine.php';

final class ComplianceCalendarService
{
    /** @var list<string> */
    private const EVENT_TYPES = array(
        'internal_audit',
        'authority_audit',
        'audit_window',
        'rca_cap_deadline',
        'corrective_action_deadline',
        'effectiveness_review',
        'meeting',
        'regulatory_review',
        'manual_change',
        'cyber_part_is',
        'other',
    );

    /** @return array<string,bool> */
    public static function tableStatus(PDO $pdo): array
    {
        return array(
            'events' => self::tablePresent($pdo, 'ipca_compliance_calendar_events'),
            'change_requests' => self::tablePresent($pdo, 'ipca_compliance_calendar_change_requests'),
        );
    }

    /** @param array<string,mixed> $data */
    public static function createManualEvent(PDO $pdo, array $data, int $userId): int
    {
        self::assertTable($pdo, 'ipca_compliance_calendar_events');

        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('Event title is required.');
        }

        $type = self::normalizeEventType((string)($data['event_type'] ?? 'other'));
        $allDay = !empty($data['is_all_day']);
        $startsAt = self::normalizePostedDateTime(
            (string)($data['date'] ?? ''),
            (string)($data['start_time'] ?? ''),
            $allDay,
            true
        );
        $endsAt = self::normalizePostedDateTime(
            (string)($data['date'] ?? ''),
            (string)($data['end_time'] ?? ''),
            $allDay,
            false
        );
        if (strtotime($endsAt) < strtotime($startsAt)) {
            $endsAt = $startsAt;
        }

        $linkedType = self::normalizeLinkedType((string)($data['linked_object_type'] ?? ''));
        $linkedId = isset($data['linked_object_id']) && (int)$data['linked_object_id'] > 0 ? (int)$data['linked_object_id'] : null;

        $st = $pdo->prepare(
            'INSERT INTO ipca_compliance_calendar_events (
                title, event_type, status, governance_state, starts_at, ends_at, is_all_day,
                timezone, description, linked_object_type, linked_object_id, color_key, icon_key,
                is_locked, requires_approval_to_move, created_by, updated_by
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?)'
        );
        $st->execute(array(
            substr($title, 0, 255),
            $type,
            'SCHEDULED',
            'approved',
            $startsAt,
            $endsAt,
            $allDay ? 1 : 0,
            self::normalizeTimezone((string)($data['timezone'] ?? 'UTC')),
            self::nullableText((string)($data['description'] ?? '')),
            $linkedType,
            $linkedId,
            $type,
            self::iconForType($type),
            $userId > 0 ? $userId : null,
            $userId > 0 ? $userId : null,
        ));

        return (int)$pdo->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function updateManualEvent(PDO $pdo, int $id, array $data, int $userId): void
    {
        self::assertTable($pdo, 'ipca_compliance_calendar_events');
        $row = self::manualEvent($pdo, $id);
        if ($row === null) {
            throw new RuntimeException('Calendar event not found.');
        }
        if (!empty($row['is_locked']) || !empty($row['locked_at'])) {
            throw new RuntimeException('This calendar event is locked.');
        }

        $title = trim((string)($data['title'] ?? $row['title']));
        if ($title === '') {
            throw new InvalidArgumentException('Event title is required.');
        }
        $type = self::normalizeEventType((string)($data['event_type'] ?? $row['event_type']));
        $allDay = array_key_exists('is_all_day', $data) ? !empty($data['is_all_day']) : ((int)$row['is_all_day'] === 1);
        $date = (string)($data['date'] ?? substr((string)$row['starts_at'], 0, 10));
        $startsAt = self::normalizePostedDateTime($date, (string)($data['start_time'] ?? substr((string)$row['starts_at'], 11, 5)), $allDay, true);
        $endsAt = self::normalizePostedDateTime($date, (string)($data['end_time'] ?? substr((string)($row['ends_at'] ?: $row['starts_at']), 11, 5)), $allDay, false);
        if (strtotime($endsAt) < strtotime($startsAt)) {
            $endsAt = $startsAt;
        }

        $pdo->prepare(
            'UPDATE ipca_compliance_calendar_events SET
                title = ?, event_type = ?, starts_at = ?, ends_at = ?, is_all_day = ?,
                timezone = ?, description = ?, linked_object_type = ?, linked_object_id = ?,
                color_key = ?, icon_key = ?, updated_by = ?
              WHERE id = ?'
        )->execute(array(
            substr($title, 0, 255),
            $type,
            $startsAt,
            $endsAt,
            $allDay ? 1 : 0,
            self::normalizeTimezone((string)($data['timezone'] ?? $row['timezone'] ?? 'UTC')),
            self::nullableText((string)($data['description'] ?? $row['description'] ?? '')),
            self::normalizeLinkedType((string)($data['linked_object_type'] ?? $row['linked_object_type'] ?? '')),
            isset($data['linked_object_id']) && (int)$data['linked_object_id'] > 0 ? (int)$data['linked_object_id'] : ($row['linked_object_id'] ?? null),
            $type,
            self::iconForType($type),
            $userId > 0 ? $userId : null,
            $id,
        ));
    }

    public static function deleteManualEvent(PDO $pdo, int $id, int $userId): void
    {
        self::assertTable($pdo, 'ipca_compliance_calendar_events');
        $row = self::manualEvent($pdo, $id);
        if ($row === null) {
            throw new RuntimeException('Calendar event not found.');
        }
        if (!empty($row['is_locked']) || !empty($row['locked_at'])) {
            throw new RuntimeException('This calendar event is locked.');
        }
        $pdo->prepare('DELETE FROM ipca_compliance_calendar_events WHERE id = ?')->execute(array($id));
    }

    /**
     * @param array<string,mixed> $data
     * @return array{mode:string,id:int|null}
     */
    public static function requestOrApplyChange(PDO $pdo, array $data, int $userId): array
    {
        $eventId = trim((string)($data['event_id'] ?? ''));
        if ($eventId === '') {
            throw new InvalidArgumentException('Event reference is required.');
        }
        $event = self::findProjectedEvent($pdo, $eventId);
        if ($event === null) {
            throw new RuntimeException('Calendar event could not be resolved.');
        }
        if (str_starts_with($eventId, 'change-request:')) {
            throw new RuntimeException('Pending calendar requests cannot be moved directly.');
        }

        $proposedStart = self::normalizeDateTimeValue((string)($data['proposed_starts_at'] ?? ''));
        $proposedEnd = self::normalizeDateTimeValue((string)($data['proposed_ends_at'] ?? ''));
        if ($proposedEnd === '') {
            $proposedEnd = $proposedStart;
        }
        if (strtotime($proposedEnd) < strtotime($proposedStart)) {
            $proposedEnd = $proposedStart;
        }

        if (str_starts_with($eventId, 'manual:')) {
            $manualId = (int)substr($eventId, strlen('manual:'));
            self::updateManualByMove($pdo, $manualId, $proposedStart, $proposedEnd, $userId);
            return array('mode' => 'manual_updated', 'id' => $manualId);
        }

        if ((string)($event['source_type'] ?? '') === 'meeting' && empty($event['is_locked']) && empty($event['requires_approval_to_move'])) {
            self::rescheduleMeeting($pdo, (int)$event['source_id'], $proposedStart, $proposedEnd, $userId);
            return array('mode' => 'meeting_updated', 'id' => (int)$event['source_id']);
        }

        $id = self::createChangeRequest($pdo, $event, $proposedStart, $proposedEnd, (string)($data['reason'] ?? ''), $userId);
        return array('mode' => 'request_created', 'id' => $id);
    }

    /** @return list<array<string,mixed>> */
    public static function listChangeRequests(PDO $pdo, string $status = 'pending', int $limit = 50): array
    {
        if (!self::tablePresent($pdo, 'ipca_compliance_calendar_change_requests')) {
            return array();
        }
        $limit = max(1, min(200, $limit));
        $where = '';
        $args = array();
        if ($status !== '') {
            $where = 'WHERE status = ?';
            $args[] = $status;
        }
        $st = $pdo->prepare(
            'SELECT * FROM ipca_compliance_calendar_change_requests
             ' . $where . '
             ORDER BY requested_at DESC, id DESC
             LIMIT ' . (int)$limit
        );
        $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    }

    public static function reviewChangeRequest(PDO $pdo, int $requestId, string $decision, string $notes, int $userId): ?string
    {
        self::assertTable($pdo, 'ipca_compliance_calendar_change_requests');
        $decision = strtolower(trim($decision));
        if (!in_array($decision, array('approved', 'rejected', 'cancelled'), true)) {
            throw new InvalidArgumentException('Unsupported calendar request decision.');
        }
        $row = self::changeRequest($pdo, $requestId);
        if ($row === null) {
            throw new RuntimeException('Calendar change request not found.');
        }
        if ((string)$row['status'] !== 'pending') {
            throw new RuntimeException('Calendar change request is no longer pending.');
        }

        $appliedAt = null;
        $applyError = null;
        if ($decision === 'approved') {
            try {
                if ((string)$row['source_type'] === 'meeting') {
                    self::rescheduleMeeting($pdo, (int)$row['source_id'], (string)$row['proposed_starts_at'], (string)($row['proposed_ends_at'] ?: $row['proposed_starts_at']), $userId);
                    $appliedAt = date('Y-m-d H:i:s');
                } else {
                    $applyError = 'Approved for governance record only; no safe source-specific calendar mutation is connected for this source type yet.';
                }
            } catch (Throwable $e) {
                $applyError = $e->getMessage();
            }
        }

        $pdo->prepare(
            'UPDATE ipca_compliance_calendar_change_requests SET
                status = ?, reviewer_notes = ?, reviewed_by = ?, reviewed_at = ?,
                applied_at = ?, apply_error = ?
              WHERE id = ?'
        )->execute(array(
            $decision,
            trim($notes) !== '' ? trim($notes) : null,
            $userId > 0 ? $userId : null,
            date('Y-m-d H:i:s'),
            $appliedAt,
            $applyError,
            $requestId,
        ));

        if ($decision === 'approved' || $decision === 'rejected') {
            ComplianceApprovalEngine::record($pdo, array(
                'object_type' => 'calendar_change_request',
                'object_id' => $requestId,
                'approval_type' => 'deadline',
                'decision' => $decision,
                'reviewed_by' => $userId,
                'notes' => trim($notes) !== '' ? trim($notes) : ((string)($row['reason'] ?? '')),
            ));
        }

        return $applyError;
    }

    public static function isManualEventId(string $eventId): bool
    {
        return str_starts_with($eventId, 'manual:');
    }

    /** @return array<string,mixed>|null */
    private static function manualEvent(PDO $pdo, int $id): ?array
    {
        $st = $pdo->prepare('SELECT * FROM ipca_compliance_calendar_events WHERE id = ? LIMIT 1');
        $st->execute(array($id));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private static function changeRequest(PDO $pdo, int $id): ?array
    {
        $st = $pdo->prepare('SELECT * FROM ipca_compliance_calendar_change_requests WHERE id = ? LIMIT 1');
        $st->execute(array($id));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private static function findProjectedEvent(PDO $pdo, string $eventId): ?array
    {
        foreach (ComplianceCalendarRepository::listEvents($pdo) as $event) {
            if ((string)($event['id'] ?? '') === $eventId) {
                return $event;
            }
        }
        return null;
    }

    private static function updateManualByMove(PDO $pdo, int $id, string $startsAt, string $endsAt, int $userId): void
    {
        self::assertTable($pdo, 'ipca_compliance_calendar_events');
        $row = self::manualEvent($pdo, $id);
        if ($row === null) {
            throw new RuntimeException('Calendar event not found.');
        }
        if (!empty($row['is_locked']) || !empty($row['locked_at'])) {
            throw new RuntimeException('This calendar event is locked.');
        }
        $pdo->prepare(
            'UPDATE ipca_compliance_calendar_events
                SET starts_at = ?, ends_at = ?, updated_by = ?
              WHERE id = ?'
        )->execute(array($startsAt, $endsAt, $userId > 0 ? $userId : null, $id));
    }

    private static function rescheduleMeeting(PDO $pdo, int $meetingId, string $startsAt, string $endsAt, int $userId): void
    {
        $row = ComplianceMeetingEngine::getById($pdo, $meetingId);
        if ($row === null) {
            throw new RuntimeException('Meeting not found.');
        }
        if (!empty($row['locked_at'])) {
            throw new RuntimeException('Meeting is locked.');
        }
        ComplianceMeetingEngine::update($pdo, $meetingId, array(
            'title' => (string)$row['title'],
            'meeting_type' => (string)$row['meeting_type'],
            'status' => (string)$row['status'],
            'scheduled_start' => $startsAt,
            'scheduled_end' => $endsAt,
            'location' => (string)($row['location'] ?? ''),
            'agenda' => (string)($row['agenda'] ?? ''),
        ), $userId);
    }

    /** @param array<string,mixed> $event */
    private static function createChangeRequest(PDO $pdo, array $event, string $proposedStart, string $proposedEnd, string $reason, int $userId): int
    {
        self::assertTable($pdo, 'ipca_compliance_calendar_change_requests');
        $payload = json_encode(array(
            'event' => $event,
            'proposed_starts_at' => $proposedStart,
            'proposed_ends_at' => $proposedEnd,
        ), JSON_UNESCAPED_SLASHES);

        $st = $pdo->prepare(
            'INSERT INTO ipca_compliance_calendar_change_requests (
                source_event_id, source_type, source_table, source_id,
                linked_object_type, linked_object_id, change_kind, title, event_type,
                current_starts_at, current_ends_at, proposed_starts_at, proposed_ends_at,
                timezone, governance_state, status, reason, requested_by, payload_json
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CAST(? AS JSON))'
        );
        $st->execute(array(
            (string)$event['id'],
            (string)($event['source_type'] ?? ''),
            (string)($event['source_table'] ?? ''),
            (int)($event['source_id'] ?? 0),
            (string)($event['linked_object_type'] ?? ''),
            (int)($event['linked_object_id'] ?? 0),
            self::changeKind((string)($event['starts_at'] ?? ''), (string)($event['ends_at'] ?? ''), $proposedStart, $proposedEnd),
            substr((string)($event['title'] ?? 'Calendar change'), 0, 255),
            self::normalizeEventType((string)($event['event_type'] ?? 'other')),
            self::normalizeDateTimeValue((string)($event['starts_at'] ?? '')),
            self::normalizeDateTimeValue((string)($event['ends_at'] ?? $event['starts_at'] ?? '')),
            $proposedStart,
            $proposedEnd,
            self::normalizeTimezone((string)($event['timezone'] ?? 'UTC')),
            'pending_approval',
            'pending',
            self::nullableText($reason),
            $userId > 0 ? $userId : null,
            $payload !== false ? $payload : '{}',
        ));

        return (int)$pdo->lastInsertId();
    }

    private static function changeKind(string $oldStart, string $oldEnd, string $newStart, string $newEnd): string
    {
        $startMoved = substr($oldStart, 0, 10) !== substr($newStart, 0, 10) || substr($oldStart, 11, 5) !== substr($newStart, 11, 5);
        $endMoved = substr($oldEnd, 0, 10) !== substr($newEnd, 0, 10) || substr($oldEnd, 11, 5) !== substr($newEnd, 11, 5);
        if ($startMoved && $endMoved) {
            return 'move_resize';
        }
        return $endMoved ? 'resize' : 'move';
    }

    private static function normalizePostedDateTime(string $date, string $time, bool $allDay, bool $start): string
    {
        $date = substr(trim($date), 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidArgumentException('A valid event date is required.');
        }
        if ($allDay) {
            return $date . ($start ? ' 00:00:00' : ' 23:59:59');
        }
        $time = substr(trim($time), 0, 5);
        if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
            throw new InvalidArgumentException('A valid event time is required.');
        }
        return $date . ' ' . $time . ':00';
    }

    private static function normalizeDateTimeValue(string $value): string
    {
        $value = trim(str_replace('T', ' ', $value));
        if ($value === '') {
            throw new InvalidArgumentException('A proposed date/time is required.');
        }
        $value = substr($value, 0, 19);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
            return $value . ':00';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
            throw new InvalidArgumentException('A valid proposed date/time is required.');
        }
        return $value;
    }

    private static function normalizeEventType(string $type): string
    {
        $type = strtolower(trim($type));
        return in_array($type, self::EVENT_TYPES, true) ? $type : 'other';
    }

    private static function normalizeTimezone(string $timezone): string
    {
        $timezone = trim($timezone);
        if ($timezone === '') {
            return 'UTC';
        }
        return substr($timezone, 0, 64);
    }

    private static function normalizeLinkedType(string $type): ?string
    {
        $type = strtolower(trim(str_replace(' ', '_', $type)));
        if ($type === '' || $type === 'other') {
            return $type === 'other' ? 'other' : null;
        }
        $allowed = array('audit', 'finding', 'corrective_action', 'meeting', 'manual_change_request', 'regulatory_review', 'other');
        return in_array($type, $allowed, true) ? $type : 'other';
    }

    private static function iconForType(string $type): string
    {
        switch ($type) {
            case 'internal_audit':
            case 'effectiveness_review':
                return 'clipboard-check';
            case 'authority_audit':
            case 'cyber_part_is':
                return 'shield';
            case 'rca_cap_deadline':
                return 'clock';
            case 'corrective_action_deadline':
                return 'wrench';
            case 'meeting':
                return 'users';
            case 'regulatory_review':
                return 'scale';
            case 'manual_change':
                return 'book';
            default:
                return 'calendar';
        }
    }

    private static function nullableText(string $value): ?string
    {
        $value = trim($value);
        return $value !== '' ? $value : null;
    }

    private static function assertTable(PDO $pdo, string $table): void
    {
        if (!self::tablePresent($pdo, $table)) {
            throw new RuntimeException('Calendar wiring tables are not installed yet. Apply scripts/sql/compliance_os_calendar_wiring.sql first.');
        }
    }

    private static function tablePresent(PDO $pdo, string $table): bool
    {
        try {
            $pdo->query('SELECT 1 FROM ' . $table . ' LIMIT 0');
            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
