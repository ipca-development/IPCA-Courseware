<?php
declare(strict_types=1);

require_once __DIR__ . '/ComplianceAutomationDispatch.php';

/**
 * Phase 7 — Meeting lifecycle CRUD.
 *
 * Handles ipca_compliance_meetings + child tables:
 *   - ipca_compliance_meeting_attendees
 *   - ipca_compliance_meeting_decisions
 *   - ipca_compliance_meeting_actions
 *
 * Recordings, transcripts and AI summaries have their own service surfaces
 * and are not driven from this engine.
 */
final class ComplianceMeetingEngine
{
    /** @var list<string> */
    private const TYPES = array(
        'AUDIT_OPENING', 'AUDIT_CLOSING', 'AUDIT_REVIEW',
        'MGMT_REVIEW', 'SAFETY_REVIEW', 'OTHER',
    );

    /** @var list<string> */
    private const STATUSES = array('SCHEDULED', 'LIVE', 'COMPLETED', 'CANCELLED');

    /** @var list<string> */
    private const ATTENDEE_ROLES = array(
        'CHAIR', 'SCRIBE', 'AUDITOR', 'AUDITEE', 'AUTHORITY', 'OBSERVER', 'EXPERT',
    );

    /** @var list<string> */
    private const DECISION_KINDS = array('POLICY', 'APPROVAL', 'DIRECTION', 'ACCEPTANCE', 'OTHER');

    /** @var list<string> */
    private const ACTION_STATUSES = array('OPEN', 'IN_PROGRESS', 'DONE', 'CANCELLED');

    public static function meetingTypes(): array
    {
        return self::TYPES;
    }

    public static function statuses(): array
    {
        return self::STATUSES;
    }

    public static function attendeeRoles(): array
    {
        return self::ATTENDEE_ROLES;
    }

    public static function decisionKinds(): array
    {
        return self::DECISION_KINDS;
    }

    public static function actionStatuses(): array
    {
        return self::ACTION_STATUSES;
    }

    public static function normalizeType(string $v): string
    {
        $u = strtoupper(trim($v));

        return in_array($u, self::TYPES, true) ? $u : 'OTHER';
    }

    public static function normalizeStatus(string $v): string
    {
        $u = strtoupper(trim($v));

        return in_array($u, self::STATUSES, true) ? $u : 'SCHEDULED';
    }

    public static function generateMeetingCode(PDO $pdo): string
    {
        $year = (int)date('Y');
        $prefix = 'MTG-' . $year . '-';
        $st = $pdo->prepare('SELECT meeting_code FROM ipca_compliance_meetings WHERE meeting_code LIKE ? ORDER BY id DESC LIMIT 80');
        $st->execute(array($prefix . '%'));
        $max = 0;
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $code = (string)($row['meeting_code'] ?? '');
            if (!str_starts_with($code, $prefix)) {
                continue;
            }
            $n = (int)substr($code, strlen($prefix));
            if ($n > $max) {
                $max = $n;
            }
        }

        return $prefix . str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT);
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function getById(PDO $pdo, int $id): ?array
    {
        $st = $pdo->prepare('SELECT * FROM ipca_compliance_meetings WHERE id = ? LIMIT 1');
        $st->execute(array($id));
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listRecent(PDO $pdo, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT * FROM ipca_compliance_meetings ORDER BY COALESCE(scheduled_start, created_at) DESC, id DESC LIMIT ' . (int)$limit;
        $st = $pdo->query($sql);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : array();
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function create(PDO $pdo, array $data, int $userId): int
    {
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('Meeting title is required.');
        }
        $code = trim((string)($data['meeting_code'] ?? ''));
        if ($code === '') {
            $code = self::generateMeetingCode($pdo);
        }
        $type = self::normalizeType((string)($data['meeting_type'] ?? 'AUDIT_REVIEW'));
        $status = self::normalizeStatus((string)($data['status'] ?? 'SCHEDULED'));
        $auditId = isset($data['audit_id']) && (int)$data['audit_id'] > 0 ? (int)$data['audit_id'] : null;
        $caseId = isset($data['case_id']) && (int)$data['case_id'] > 0 ? (int)$data['case_id'] : null;

        $ins = $pdo->prepare(
            'INSERT INTO ipca_compliance_meetings (
                case_id, audit_id, meeting_code, title, meeting_type, status,
                scheduled_start, scheduled_end, location, agenda,
                chair_user_id, scribe_user_id, created_by, updated_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute(array(
            $caseId,
            $auditId,
            substr($code, 0, 64),
            substr($title, 0, 255),
            $type,
            $status,
            self::nullableDt((string)($data['scheduled_start'] ?? '')),
            self::nullableDt((string)($data['scheduled_end'] ?? '')),
            self::nullableStr((string)($data['location'] ?? ''), 255),
            self::nullableStr((string)($data['agenda'] ?? ''), null),
            isset($data['chair_user_id']) && (int)$data['chair_user_id'] > 0 ? (int)$data['chair_user_id'] : null,
            isset($data['scribe_user_id']) && (int)$data['scribe_user_id'] > 0 ? (int)$data['scribe_user_id'] : null,
            $userId > 0 ? $userId : null,
            $userId > 0 ? $userId : null,
        ));
        $newId = (int)$pdo->lastInsertId();

        ComplianceAutomationDispatch::fire($pdo, 'compliance.meeting.created', array(
            'meeting_id' => $newId,
            'meeting_code' => substr($code, 0, 64),
            'meeting_type' => $type,
            'status' => $status,
            'audit_id' => $auditId,
            'case_id' => $caseId,
            'created_by_user_id' => $userId,
        ));

        return $newId;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function update(PDO $pdo, int $id, array $data, int $userId): void
    {
        $row = self::getById($pdo, $id);
        if ($row === null) {
            throw new RuntimeException('Meeting not found.');
        }
        if (!empty($row['locked_at'])) {
            throw new RuntimeException('Meeting is locked.');
        }
        $title = array_key_exists('title', $data) ? trim((string)$data['title']) : (string)$row['title'];
        if ($title === '') {
            throw new InvalidArgumentException('Meeting title is required.');
        }
        $type = array_key_exists('meeting_type', $data) ? self::normalizeType((string)$data['meeting_type']) : (string)$row['meeting_type'];
        $status = array_key_exists('status', $data) ? self::normalizeStatus((string)$data['status']) : (string)$row['status'];
        $start = array_key_exists('scheduled_start', $data)
            ? self::nullableDt((string)$data['scheduled_start'])
            : ($row['scheduled_start'] ?? null);
        $end = array_key_exists('scheduled_end', $data)
            ? self::nullableDt((string)$data['scheduled_end'])
            : ($row['scheduled_end'] ?? null);
        $loc = array_key_exists('location', $data)
            ? self::nullableStr((string)$data['location'], 255)
            : ($row['location'] ?? null);
        $agenda = array_key_exists('agenda', $data)
            ? self::nullableStr((string)$data['agenda'], null)
            : ($row['agenda'] ?? null);

        $pdo->prepare(
            'UPDATE ipca_compliance_meetings SET
                title = ?, meeting_type = ?, status = ?,
                scheduled_start = ?, scheduled_end = ?,
                location = ?, agenda = ?,
                updated_by = ?
             WHERE id = ?'
        )->execute(array(
            substr($title, 0, 255),
            $type,
            $status,
            $start,
            $end,
            $loc,
            $agenda,
            $userId > 0 ? $userId : null,
            $id,
        ));
    }

    public static function start(PDO $pdo, int $id, int $userId): void
    {
        $row = self::getById($pdo, $id);
        if ($row === null) {
            throw new RuntimeException('Meeting not found.');
        }
        if (!empty($row['locked_at'])) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        $pdo->prepare(
            'UPDATE ipca_compliance_meetings SET
                status = \'LIVE\',
                actual_start = COALESCE(actual_start, ?),
                updated_by = ?
             WHERE id = ?'
        )->execute(array($now, $userId > 0 ? $userId : null, $id));
    }

    public static function complete(PDO $pdo, int $id, int $userId): void
    {
        $row = self::getById($pdo, $id);
        if ($row === null) {
            throw new RuntimeException('Meeting not found.');
        }
        if (!empty($row['locked_at'])) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        $pdo->prepare(
            'UPDATE ipca_compliance_meetings SET
                status = \'COMPLETED\',
                actual_end = COALESCE(actual_end, ?),
                locked_at = ?, locked_by = ?,
                updated_by = ?
             WHERE id = ?'
        )->execute(array($now, $now, $userId > 0 ? $userId : null, $userId > 0 ? $userId : null, $id));
    }

    public static function cancel(PDO $pdo, int $id, int $userId): void
    {
        $row = self::getById($pdo, $id);
        if ($row === null) {
            throw new RuntimeException('Meeting not found.');
        }
        if (!empty($row['locked_at'])) {
            return;
        }
        $pdo->prepare(
            'UPDATE ipca_compliance_meetings SET
                status = \'CANCELLED\',
                updated_by = ?
             WHERE id = ?'
        )->execute(array($userId > 0 ? $userId : null, $id));
    }

    // ------------------------------------------------------------------
    // Attendees
    // ------------------------------------------------------------------

    /**
     * @return list<array<string,mixed>>
     */
    public static function listAttendees(PDO $pdo, int $meetingId): array
    {
        $st = $pdo->prepare(
            'SELECT * FROM ipca_compliance_meeting_attendees
              WHERE meeting_id = ?
              ORDER BY id ASC'
        );
        $st->execute(array($meetingId));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : array();
    }

    public static function addAttendee(PDO $pdo, int $meetingId, array $data): int
    {
        $name = trim((string)($data['display_name'] ?? ''));
        $userId = isset($data['user_id']) && (int)$data['user_id'] > 0 ? (int)$data['user_id'] : null;
        if ($name === '' && $userId === null) {
            throw new InvalidArgumentException('Attendee needs a display name or a user.');
        }
        $role = strtoupper(trim((string)($data['attendee_role'] ?? '')));
        if (!in_array($role, self::ATTENDEE_ROLES, true)) {
            $role = null;
        }

        $st = $pdo->prepare(
            'INSERT INTO ipca_compliance_meeting_attendees (
                meeting_id, user_id, display_name, email, organisation, attendee_role, rsvp_state
            ) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute(array(
            $meetingId,
            $userId,
            $name !== '' ? substr($name, 0, 255) : null,
            self::nullableStr((string)($data['email'] ?? ''), 255),
            self::nullableStr((string)($data['organisation'] ?? ''), 255),
            $role,
            'INVITED',
        ));

        return (int)$pdo->lastInsertId();
    }

    public static function markAttendance(PDO $pdo, int $attendeeId, bool $attended): void
    {
        $pdo->prepare(
            'UPDATE ipca_compliance_meeting_attendees
                SET attended = ?, attended_at = ?
              WHERE id = ?'
        )->execute(array(
            $attended ? 1 : 0,
            $attended ? date('Y-m-d H:i:s') : null,
            $attendeeId,
        ));
    }

    public static function removeAttendee(PDO $pdo, int $attendeeId): void
    {
        $pdo->prepare('DELETE FROM ipca_compliance_meeting_attendees WHERE id = ?')
            ->execute(array($attendeeId));
    }

    // ------------------------------------------------------------------
    // Decisions
    // ------------------------------------------------------------------

    /**
     * @return list<array<string,mixed>>
     */
    public static function listDecisions(PDO $pdo, int $meetingId): array
    {
        $st = $pdo->prepare(
            'SELECT * FROM ipca_compliance_meeting_decisions
              WHERE meeting_id = ?
              ORDER BY decided_at ASC, id ASC'
        );
        $st->execute(array($meetingId));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : array();
    }

    public static function addDecision(PDO $pdo, int $meetingId, array $data, int $userId): int
    {
        $text = trim((string)($data['decision_text'] ?? ''));
        if ($text === '') {
            throw new InvalidArgumentException('Decision text is required.');
        }
        $kind = strtoupper(trim((string)($data['decision_kind'] ?? '')));
        if (!in_array($kind, self::DECISION_KINDS, true)) {
            $kind = null;
        }

        $st = $pdo->prepare(
            'INSERT INTO ipca_compliance_meeting_decisions (
                meeting_id, decision_text, decision_kind, decided_by, rationale
            ) VALUES (?, ?, ?, ?, ?)'
        );
        $st->execute(array(
            $meetingId,
            $text,
            $kind,
            $userId > 0 ? $userId : null,
            self::nullableStr((string)($data['rationale'] ?? ''), null),
        ));

        return (int)$pdo->lastInsertId();
    }

    public static function deleteDecision(PDO $pdo, int $decisionId): void
    {
        $pdo->prepare('DELETE FROM ipca_compliance_meeting_decisions WHERE id = ?')
            ->execute(array($decisionId));
    }

    // ------------------------------------------------------------------
    // Action items
    // ------------------------------------------------------------------

    /**
     * @return list<array<string,mixed>>
     */
    public static function listActions(PDO $pdo, int $meetingId): array
    {
        $st = $pdo->prepare(
            'SELECT * FROM ipca_compliance_meeting_actions
              WHERE meeting_id = ?
              ORDER BY status ASC, due_date ASC, id ASC'
        );
        $st->execute(array($meetingId));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : array();
    }

    public static function addAction(PDO $pdo, int $meetingId, array $data, int $userId): int
    {
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('Action title is required.');
        }
        $status = strtoupper(trim((string)($data['status'] ?? 'OPEN')));
        if (!in_array($status, self::ACTION_STATUSES, true)) {
            $status = 'OPEN';
        }
        $due = trim((string)($data['due_date'] ?? ''));

        $st = $pdo->prepare(
            'INSERT INTO ipca_compliance_meeting_actions (
                meeting_id, title, description, status,
                responsible_user_id, responsible_name, due_date, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute(array(
            $meetingId,
            substr($title, 0, 255),
            self::nullableStr((string)($data['description'] ?? ''), null),
            $status,
            isset($data['responsible_user_id']) && (int)$data['responsible_user_id'] > 0 ? (int)$data['responsible_user_id'] : null,
            self::nullableStr((string)($data['responsible_name'] ?? ''), 255),
            $due !== '' ? substr($due, 0, 10) : null,
            $userId > 0 ? $userId : null,
        ));

        return (int)$pdo->lastInsertId();
    }

    public static function updateActionStatus(PDO $pdo, int $actionId, string $status): void
    {
        $u = strtoupper(trim($status));
        if (!in_array($u, self::ACTION_STATUSES, true)) {
            return;
        }
        $completed = ($u === 'DONE') ? date('Y-m-d H:i:s') : null;
        $pdo->prepare(
            'UPDATE ipca_compliance_meeting_actions
                SET status = ?,
                    completed_at = COALESCE(?, completed_at)
              WHERE id = ?'
        )->execute(array($u, $completed, $actionId));
    }

    public static function deleteAction(PDO $pdo, int $actionId): void
    {
        $pdo->prepare('DELETE FROM ipca_compliance_meeting_actions WHERE id = ?')
            ->execute(array($actionId));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private static function nullableStr(string $v, ?int $max): ?string
    {
        $t = trim($v);
        if ($t === '') {
            return null;
        }

        return $max !== null ? substr($t, 0, $max) : $t;
    }

    private static function nullableDt(string $v): ?string
    {
        $t = trim($v);
        if ($t === '') {
            return null;
        }
        // Accept either YYYY-MM-DD or YYYY-MM-DDTHH:MM (HTML5 datetime-local).
        $t = str_replace('T', ' ', $t);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $t) === 1) {
            $t .= ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $t) === 1) {
            $t .= ':00';
        }

        return $t;
    }
}
