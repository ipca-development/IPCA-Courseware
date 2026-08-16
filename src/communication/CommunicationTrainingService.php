<?php
declare(strict_types=1);

require_once __DIR__ . '/CommunicationConfigService.php';
require_once __DIR__ . '/CommunicationSupport.php';

/**
 * Read-only Training companion. Does not write training records, invent
 * flight phase/hours, or share a queue with messaging sync.
 */
final class CommunicationTrainingService
{
    private const RESERVATION_LABELS = array(
        'flight_training' => 'Flight Training',
        'briefing' => 'Briefing',
        'simulator_training' => 'Simulator Training',
        'ground_training' => 'Ground Training',
        'other' => 'Other',
    );

    private const ACTION_LABELS = array(
        'awaiting_summary_review' => 'Awaiting Summary Review',
        'awaiting_test_completion' => 'Awaiting Test Completion',
        'summary_required' => 'Summary Required',
        'remediation_required' => 'Remediation Required',
        'instructor_required' => 'Instructor Action Required',
        'deadline_blocked' => 'Deadline Blocked',
        'training_suspended' => 'Training Suspended',
    );

    private const HONESTY_NOTE = 'Theory progress is from IPCA.training. Flight phase and logged hours are not shown here.';

    /** @var array<string,bool> */
    private array $tableCache = array();

    public function __construct(
        private PDO $pdo,
        private CommunicationConfigService $config
    ) {
    }

    /**
     * @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    public function summary(array $session): array
    {
        $this->config->requireTraining();
        $userId = (int)$session['user']['id'];
        return array(
            'next_flight' => $this->nextFlight($userId),
            'theory' => $this->theory($userId),
            'actions' => $this->actions($userId),
            'deadlines' => $this->deadlines($userId),
            'notes' => array(self::HONESTY_NOTE),
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function nextFlight(int $userId): ?array
    {
        if (!$this->tableExists('ipca_flight_schedule_slots') || !$this->tableExists('ipca_flight_schedule_crew')) {
            return null;
        }
        $now = (new DateTimeImmutable('now', new DateTimeZone('America/Los_Angeles')))->format('Y-m-d H:i:s');
        $aircraftJoin = $this->tableExists('ipca_aircraft_devices')
            ? 'LEFT JOIN ipca_aircraft_devices a ON a.id = s.aircraft_id'
            : '';
        $aircraftSelect = $this->tableExists('ipca_aircraft_devices')
            ? 'COALESCE(a.registration, \'\') AS aircraft_registration'
            : '\'\' AS aircraft_registration';
        $missionJoin = $this->tableExists('ipca_missions')
            ? 'LEFT JOIN ipca_missions m ON m.id = s.mission_id'
            : '';
        $missionSelect = $this->tableExists('ipca_missions')
            ? 'COALESCE(NULLIF(m.name, \'\'), NULLIF(s.mission_code, \'\'), \'\') AS mission_name'
            : 'COALESCE(s.mission_code, \'\') AS mission_name';
        try {
            $stmt = $this->pdo->prepare("
                SELECT s.id AS slot_id,
                       s.scheduled_start_time,
                       s.scheduled_end_time,
                       s.reservation_type,
                       s.mission_code,
                       c.crew_role,
                       {$aircraftSelect},
                       {$missionSelect}
                FROM ipca_flight_schedule_crew c
                INNER JOIN ipca_flight_schedule_slots s ON s.id = c.schedule_slot_id
                {$aircraftJoin}
                {$missionJoin}
                WHERE c.user_id = ?
                  AND LOWER(TRIM(COALESCE(s.status, ''))) NOT IN ('cancelled', 'canceled', 'superseded', 'completed')
                  AND s.scheduled_end_time >= ?
                ORDER BY s.scheduled_start_time ASC
                LIMIT 1
            ");
            $stmt->execute(array($userId, $now));
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return null;
        }
        if (!is_array($row)) {
            return null;
        }
        $reservationType = (string)($row['reservation_type'] ?? 'flight_training');
        return array(
            'starts_at' => $this->localTimestamp((string)$row['scheduled_start_time']),
            'ends_at' => $this->localTimestamp((string)$row['scheduled_end_time']),
            'when_label' => $this->whenLabel((string)$row['scheduled_start_time'], (string)$row['scheduled_end_time']),
            'aircraft_registration' => (string)($row['aircraft_registration'] ?? ''),
            'reservation_type' => $reservationType,
            'reservation_label' => self::RESERVATION_LABELS[$reservationType] ?? 'Scheduled',
            'mission_name' => (string)($row['mission_name'] ?? $row['mission_code'] ?? ''),
            'role' => $this->prettyRole((string)($row['crew_role'] ?? '')),
            'with_names' => $this->otherCrewNames((int)$row['slot_id'], $userId),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function theory(int $userId): array
    {
        $empty = array(
            'enrolled' => false,
            'program_title' => '',
            'cohort_name' => '',
            'completed_lessons' => 0,
            'total_lessons' => 0,
            'percent' => 0,
            'honesty_note' => self::HONESTY_NOTE,
        );
        if (!$this->tableExists('cohort_students') || !$this->tableExists('cohorts')) {
            return $empty;
        }
        $courseJoin = $this->tableExists('courses') ? 'LEFT JOIN courses c ON c.id = co.course_id' : '';
        $programJoin = $this->tableExists('courses') && $this->tableExists('programs')
            ? 'LEFT JOIN programs p ON p.id = c.program_id'
            : '';
        $courseSelect = $this->tableExists('courses') ? "COALESCE(c.title, '') AS course_title" : "'' AS course_title";
        $programSelect = $this->tableExists('courses') && $this->tableExists('programs')
            ? "COALESCE(p.program_key, '') AS program_key"
            : "'' AS program_key";
        try {
            $stmt = $this->pdo->prepare("
                SELECT co.id, co.name, {$courseSelect}, {$programSelect}
                FROM cohort_students cs
                INNER JOIN cohorts co ON co.id = cs.cohort_id
                {$courseJoin}
                {$programJoin}
                WHERE cs.user_id = ?
                ORDER BY co.id DESC
                LIMIT 1
            ");
            $stmt->execute(array($userId));
            $cohort = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return $empty;
        }
        if (!is_array($cohort)) {
            return $empty;
        }
        $cohortId = (int)$cohort['id'];
        $total = 0;
        $completed = 0;
        if ($this->tableExists('cohort_lesson_deadlines')) {
            try {
                $totalStmt = $this->pdo->prepare('SELECT COUNT(*) FROM cohort_lesson_deadlines WHERE cohort_id = ?');
                $totalStmt->execute(array($cohortId));
                $total = (int)$totalStmt->fetchColumn();
            } catch (Throwable) {
                $total = 0;
            }
        }
        if ($total > 0 && $this->tableExists('lesson_activity')) {
            try {
                $passedStmt = $this->pdo->prepare("
                    SELECT COUNT(DISTINCT la.lesson_id)
                    FROM lesson_activity la
                    WHERE la.user_id = ?
                      AND la.cohort_id = ?
                      AND la.lesson_id IN (SELECT cld.lesson_id FROM cohort_lesson_deadlines cld WHERE cld.cohort_id = ?)
                      AND (la.completion_status = 'completed' OR la.test_pass_status = 'passed')
                ");
                $passedStmt->execute(array($userId, $cohortId, $cohortId));
                $completed = (int)$passedStmt->fetchColumn();
            } catch (Throwable) {
                $completed = 0;
            }
        }
        $programKey = trim((string)($cohort['program_key'] ?? ''));
        $programTitle = $programKey !== ''
            ? ucwords(str_replace(array('-', '_'), ' ', $programKey))
            : (string)($cohort['course_title'] ?? '');
        return array(
            'enrolled' => true,
            'program_title' => $programTitle,
            'cohort_name' => (string)($cohort['name'] ?? ''),
            'completed_lessons' => $completed,
            'total_lessons' => $total,
            'percent' => $total > 0 ? (int)round(($completed / $total) * 100) : 0,
            'honesty_note' => self::HONESTY_NOTE,
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function actions(int $userId): array
    {
        $items = array();
        if ($this->tableExists('lesson_activity') && $this->tableExists('lessons')) {
            try {
                $stmt = $this->pdo->prepare("
                    SELECT la.lesson_id, la.completion_status, la.effective_deadline_utc, l.title AS lesson_title, co.name AS cohort_name
                    FROM lesson_activity la
                    INNER JOIN lessons l ON l.id = la.lesson_id
                    LEFT JOIN cohorts co ON co.id = la.cohort_id
                    WHERE la.user_id = ?
                      AND la.completion_status IN (
                        'awaiting_summary_review',
                        'awaiting_test_completion',
                        'summary_required',
                        'remediation_required',
                        'instructor_required',
                        'deadline_blocked',
                        'training_suspended'
                      )
                    ORDER BY la.updated_at DESC
                    LIMIT 20
                ");
                $stmt->execute(array($userId));
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $status = (string)$row['completion_status'];
                    $items[] = array(
                        'id' => 'theory-' . (string)$row['lesson_id'] . '-' . $status,
                        'source' => 'theory',
                        'title' => self::ACTION_LABELS[$status] ?? $status,
                        'subtitle' => trim((string)$row['lesson_title'] . (isset($row['cohort_name']) && (string)$row['cohort_name'] !== '' ? ' · ' . $row['cohort_name'] : '')),
                        'status' => $status,
                        'due_at' => $this->localTimestamp((string)($row['effective_deadline_utc'] ?? '')),
                    );
                }
            } catch (Throwable) {
            }
        }
        if ($this->tableExists('student_required_actions')) {
            try {
                $stmt = $this->pdo->prepare("
                    SELECT sra.id, sra.action_type, sra.title, sra.status, l.title AS lesson_title
                    FROM student_required_actions sra
                    LEFT JOIN lessons l ON l.id = sra.lesson_id
                    WHERE sra.user_id = ?
                      AND LOWER(TRIM(COALESCE(sra.status, 'pending'))) IN ('pending', 'open', 'sent')
                      AND sra.completed_at IS NULL
                    ORDER BY sra.id DESC
                    LIMIT 20
                ");
                $stmt->execute(array($userId));
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $title = trim((string)($row['title'] ?? ''));
                    if ($title === '') {
                        $title = ucwords(str_replace('_', ' ', (string)$row['action_type']));
                    }
                    $items[] = array(
                        'id' => 'required-' . (string)$row['id'],
                        'source' => 'required_action',
                        'title' => $title,
                        'subtitle' => (string)($row['lesson_title'] ?? ''),
                        'status' => (string)$row['status'],
                        'due_at' => '',
                    );
                }
            } catch (Throwable) {
            }
        }
        if ($this->tableExists('ipca_internal_inbox_items')) {
            try {
                $stmt = $this->pdo->prepare("
                    SELECT id, title, summary, status
                    FROM ipca_internal_inbox_items
                    WHERE recipient_user_id = ?
                      AND item_type IN ('form', 'document')
                      AND LOWER(TRIM(COALESCE(status, 'pending'))) IN ('pending', 'opened')
                    ORDER BY id DESC
                    LIMIT 10
                ");
                $stmt->execute(array($userId));
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $items[] = array(
                        'id' => 'form-' . (string)$row['id'],
                        'source' => 'form',
                        'title' => (string)($row['title'] !== '' ? $row['title'] : 'Form to complete'),
                        'subtitle' => (string)($row['summary'] ?? ''),
                        'status' => (string)$row['status'],
                        'due_at' => '',
                    );
                }
            } catch (Throwable) {
            }
        }
        return array_slice($items, 0, 30);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function deadlines(int $userId): array
    {
        if (!$this->tableExists('cohort_students') || !$this->tableExists('cohort_lesson_deadlines') || !$this->tableExists('lessons')) {
            return array();
        }
        $hasActivity = $this->tableExists('lesson_activity');
        try {
            $activityJoin = $hasActivity
                ? 'LEFT JOIN lesson_activity la ON la.user_id = cs.user_id AND la.cohort_id = d.cohort_id AND la.lesson_id = d.lesson_id'
                : '';
            $incomplete = $hasActivity
                ? "AND (la.completion_status IS NULL OR la.completion_status <> 'completed')"
                : '';
            $stmt = $this->pdo->prepare("
                SELECT d.lesson_id, d.deadline_utc, l.title AS lesson_title, co.name AS cohort_name
                FROM cohort_students cs
                INNER JOIN cohort_lesson_deadlines d ON d.cohort_id = cs.cohort_id
                INNER JOIN lessons l ON l.id = d.lesson_id
                INNER JOIN cohorts co ON co.id = cs.cohort_id
                {$activityJoin}
                WHERE cs.user_id = ?
                  {$incomplete}
                ORDER BY d.deadline_utc ASC
                LIMIT 12
            ");
            $stmt->execute(array($userId));
            $out = array();
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $due = (string)$row['deadline_utc'];
                $out[] = array(
                    'id' => 'deadline-' . (string)$row['lesson_id'] . '-' . $due,
                    'title' => (string)$row['lesson_title'],
                    'cohort_name' => (string)($row['cohort_name'] ?? ''),
                    'due_at' => $this->localTimestamp($due),
                    'due_label' => $this->deadlineLabel($due),
                    'days_left' => $this->daysLeft($due),
                );
            }
            return $out;
        } catch (Throwable) {
            return array();
        }
    }

    /**
     * @return array<int,string>
     */
    private function otherCrewNames(int $slotId, int $userId): array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT person_name_snapshot, user_id
                FROM ipca_flight_schedule_crew
                WHERE schedule_slot_id = ?
                ORDER BY id ASC
            ");
            $stmt->execute(array($slotId));
            $names = array();
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ((int)($row['user_id'] ?? 0) === $userId) {
                    continue;
                }
                $name = trim((string)$row['person_name_snapshot']);
                if ($name !== '') {
                    $names[] = $name;
                }
            }
            return array_values(array_unique($names));
        } catch (Throwable) {
            return array();
        }
    }

    private function tableExists(string $name): bool
    {
        if (array_key_exists($name, $this->tableCache)) {
            return $this->tableCache[$name];
        }
        if (!preg_match('/^[a-z0-9_]+$/', $name)) {
            return false;
        }
        try {
            $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $stmt = $this->pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
                $stmt->execute(array($name));
                $exists = (bool)$stmt->fetchColumn();
            } else {
                $stmt = $this->pdo->prepare("
                    SELECT COUNT(*) FROM information_schema.TABLES
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                ");
                $stmt->execute(array($name));
                $exists = (int)$stmt->fetchColumn() > 0;
            }
        } catch (Throwable) {
            $exists = false;
        }
        $this->tableCache[$name] = $exists;
        return $exists;
    }

    private function localTimestamp(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        return str_replace(' ', 'T', substr($value, 0, 19));
    }

    private function whenLabel(string $start, string $end): string
    {
        $zone = new DateTimeZone('America/Los_Angeles');
        $startDt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', substr(trim($start), 0, 19), $zone)
            ?: DateTimeImmutable::createFromFormat('Y-m-d\\TH:i:s', substr(trim($start), 0, 19), $zone);
        if (!$startDt) {
            return $this->localTimestamp($start);
        }
        $endDt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', substr(trim($end), 0, 19), $zone)
            ?: DateTimeImmutable::createFromFormat('Y-m-d\\TH:i:s', substr(trim($end), 0, 19), $zone);
        $label = $startDt->format('D, M j · H:i');
        if ($endDt) {
            $label .= '–' . $endDt->format('H:i');
        }
        return $label;
    }

    private function deadlineLabel(string $due): string
    {
        $days = $this->daysLeft($due);
        if ($days === null) {
            return 'No date';
        }
        if ($days < 0) {
            return abs($days) . (abs($days) === 1 ? ' day overdue' : ' days overdue');
        }
        if ($days === 0) {
            return 'Due today';
        }
        if ($days === 1) {
            return '1 day left';
        }
        return $days . ' days left';
    }

    private function daysLeft(string $deadlineUtc): ?int
    {
        $deadlineUtc = trim($deadlineUtc);
        if ($deadlineUtc === '') {
            return null;
        }
        try {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $deadline = new DateTimeImmutable(substr($deadlineUtc, 0, 19), new DateTimeZone('UTC'));
            $now = $now->setTime(0, 0, 0);
            $deadline = $deadline->setTime(0, 0, 0);
            return (int)$now->diff($deadline)->format('%r%a');
        } catch (Throwable) {
            return null;
        }
    }

    private function prettyRole(string $role): string
    {
        $role = trim($role);
        if ($role === '') {
            return '';
        }
        return ucwords(str_replace(array('_', '-'), ' ', $role));
    }
}
