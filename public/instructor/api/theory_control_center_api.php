<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/courseware_progression_v2.php';

cw_require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$u = cw_current_user($pdo);
$role = (string)($u['role'] ?? '');
$currentUserId = (int)($u['id'] ?? 0);

$allowedRoles = array('admin', 'supervisor', 'instructor', 'chief_instructor');
if (!in_array($role, $allowedRoles, true)) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => 'forbidden',
        'message' => 'Instructor access required.'
    ]);
    exit;
}

$engine = new CoursewareProgressionV2($pdo);

function tcc_json($data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function tcc_int($value): int {
    return (int)($value ?? 0);
}

function tcc_str($value): string {
    return trim((string)($value ?? ''));
}

function tcc_student_name(array $row): string {
    $name = trim((string)($row['name'] ?? ''));
    if ($name !== '') return $name;

    $first = trim((string)($row['first_name'] ?? ''));
    $last = trim((string)($row['last_name'] ?? ''));
    $full = trim($first . ' ' . $last);

    if ($full !== '') return $full;

    return trim((string)($row['email'] ?? 'Student'));
}

function tcc_avatar_initials(string $name): string {
    $name = trim($name);
    if ($name === '') return 'S';

    $parts = preg_split('/\s+/', $name);
    if (!$parts) return strtoupper(substr($name, 0, 1));

    $a = strtoupper(substr((string)$parts[0], 0, 1));
    $b = isset($parts[1]) ? strtoupper(substr((string)$parts[1], 0, 1)) : '';

    return $a . $b;
}

function tcc_fetch_cohorts(PDO $pdo): array {
    $st = $pdo->query("
        SELECT
            co.id,
            co.name,
            co.start_date,
            co.end_date,
            c.title AS course_title,
            p.program_key,
            'UTC' AS timezone
        FROM cohorts co
        JOIN courses c ON c.id = co.course_id
        JOIN programs p ON p.id = c.program_id
        ORDER BY co.start_date DESC, co.id DESC
    ");

    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function tcc_cohort_students(PDO $pdo, int $cohortId): array {
    $st = $pdo->prepare("
        SELECT
            u.id,
            u.name,
            u.email,
            u.photo_path
        FROM cohort_students cs
        JOIN users u ON u.id = cs.user_id
        WHERE cs.cohort_id = ?
        ORDER BY u.name ASC, u.email ASC
    ");
    $st->execute([$cohortId]);

    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function tcc_total_lessons(PDO $pdo, int $cohortId): int {
    $st = $pdo->prepare("
        SELECT COUNT(DISTINCT d.lesson_id)
        FROM cohort_lesson_deadlines d
        WHERE d.cohort_id = ?
    ");
    $st->execute([$cohortId]);

    return (int)$st->fetchColumn();
}

function tcc_passed_lessons(PDO $pdo, int $userId, int $cohortId): int {
    $st = $pdo->prepare("
        SELECT COUNT(DISTINCT pt.lesson_id)
        FROM progress_tests_v2 pt
        WHERE pt.user_id = ?
          AND pt.cohort_id = ?
          AND pt.status = 'completed'
          AND pt.pass_gate_met = 1
    ");
    $st->execute([$userId, $cohortId]);

    return (int)$st->fetchColumn();
}

function tcc_pending_actions(PDO $pdo, int $cohortId, ?int $userId = null): array {
    $params = [$cohortId];
    $userSql = '';

    if ($userId !== null && $userId > 0) {
        $userSql = ' AND sra.user_id = ? ';
        $params[] = $userId;
    }

    $st = $pdo->prepare("
        SELECT
            sra.*,
            u.name AS student_name,
            u.email AS student_email,
            u.photo_path AS student_photo_path,
            l.title AS lesson_title
        FROM student_required_actions sra
        JOIN users u ON u.id = sra.user_id
        LEFT JOIN lessons l ON l.id = sra.lesson_id
        WHERE sra.cohort_id = ?
          {$userSql}
          AND (
              (sra.action_type = 'deadline_reason_submission' AND sra.status IN ('pending','opened','completed'))
              OR
              (sra.action_type <> 'deadline_reason_submission' AND sra.status IN ('pending','opened'))
          )
        ORDER BY sra.created_at ASC, sra.id ASC
    ");
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function tcc_completed_actions(PDO $pdo, int $cohortId, int $userId): array {
    $st = $pdo->prepare("
        SELECT
            sra.*,
            l.title AS lesson_title
        FROM student_required_actions sra
        LEFT JOIN lessons l ON l.id = sra.lesson_id
        WHERE sra.cohort_id = ?
          AND sra.user_id = ?
          AND sra.status IN ('completed','approved')
        ORDER BY sra.updated_at DESC, sra.id DESC
        LIMIT 50
    ");
    $st->execute([$cohortId, $userId]);

    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function tcc_student_attempt_stats(PDO $pdo, int $cohortId, int $userId): array {
    $st = $pdo->prepare("
        SELECT
            COUNT(*) AS total_attempts,
            SUM(CASE WHEN status='completed' AND pass_gate_met=0 THEN 1 ELSE 0 END) AS failed_attempts,
            AVG(CASE WHEN status='completed' AND score_pct IS NOT NULL THEN score_pct ELSE NULL END) AS avg_score,
            MAX(completed_at) AS last_completed_at
        FROM progress_tests_v2
        WHERE cohort_id = ?
          AND user_id = ?
          AND NOT (
              COALESCE(formal_result_code, '') = 'STALE_ABORTED'
              AND COALESCE(counts_as_unsat, 0) = 0
              AND COALESCE(pass_gate_met, 0) = 0
          )
    ");
    $st->execute([$cohortId, $userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'total_attempts' => (int)($row['total_attempts'] ?? 0),
        'failed_attempts' => (int)($row['failed_attempts'] ?? 0),
        'avg_score' => $row['avg_score'] === null ? null : round((float)$row['avg_score'], 1),
        'last_completed_at' => (string)($row['last_completed_at'] ?? ''),
    ];
}

function tcc_deadline_missed_count(PDO $pdo, int $cohortId, int $userId): int {
    $st = $pdo->prepare("
        SELECT COUNT(*)
        FROM student_required_actions
        WHERE cohort_id = ?
          AND user_id = ?
          AND action_type = 'deadline_reason_submission'
    ");
    $st->execute([$cohortId, $userId]);

    return (int)$st->fetchColumn();
}

function tcc_cohort_metric_averages(PDO $pdo, int $cohortId): array {
    $students = tcc_cohort_students($pdo, $cohortId);

    if (!$students) {
        return [
            'avg_deadlines_missed' => 0,
            'avg_failed_attempts' => 0,
            'avg_score' => null,
        ];
    }

    $deadlineCounts = [];
    $failedCounts = [];
    $scores = [];

    foreach ($students as $s) {
        $sid = (int)$s['id'];
        $deadlineCounts[] = tcc_deadline_missed_count($pdo, $cohortId, $sid);

        $stats = tcc_student_attempt_stats($pdo, $cohortId, $sid);
        $failedCounts[] = (int)$stats['failed_attempts'];

        if ($stats['avg_score'] !== null) {
            $scores[] = (float)$stats['avg_score'];
        }
    }

    return [
        'avg_deadlines_missed' => round(array_sum($deadlineCounts) / max(1, count($deadlineCounts)), 1),
        'avg_failed_attempts' => round(array_sum($failedCounts) / max(1, count($failedCounts)), 1),
        'avg_score' => $scores ? round(array_sum($scores) / count($scores), 1) : null,
    ];
}

function tcc_motivation_signal(array $stats, int $deadlineMisses, int $pendingActions): array {
    $score = 100;

    $score -= min(40, $deadlineMisses * 10);
    $score -= min(35, ((int)$stats['failed_attempts']) * 7);
    $score -= min(25, $pendingActions * 10);

    if ($stats['avg_score'] !== null && (float)$stats['avg_score'] < 70) {
        $score -= 10;
    }

    if ($score >= 80) {
        return ['level' => 'strong', 'label' => 'Strong engagement', 'trend' => 'stable', 'score' => $score];
    }

    if ($score >= 60) {
        return ['level' => 'stable', 'label' => 'Stable', 'trend' => 'flat', 'score' => $score];
    }

    if ($score >= 40) {
        return ['level' => 'drifting', 'label' => 'Drifting', 'trend' => 'down', 'score' => $score];
    }

    return ['level' => 'needs_contact', 'label' => 'Needs instructor contact', 'trend' => 'down', 'score' => max(0, $score)];
}

function tcc_action_severity(string $actionType): string {
    if ($actionType === 'instructor_approval') return 'high';
    if ($actionType === 'deadline_reason_submission') return 'medium';
    if ($actionType === 'remediation_acknowledgement') return 'medium';
    return 'low';
}

function tcc_recommended_action(string $actionType): string {
    if ($actionType === 'instructor_approval') return 'review_instructor_approval';
    if ($actionType === 'deadline_reason_submission') return 'review_deadline_reason';
    if ($actionType === 'remediation_acknowledgement') return 'monitor_remediation_completion';
    return 'review_required_action';
}

function tcc_official_flow_url(string $actionType, string $token): string {

    $actionType = trim($actionType);
    $token = trim($token);

    // ONLY instructor approval is allowed to open that page
    if ($actionType === 'instructor_approval' && $token !== '') {
        return '/instructor/instructor_approval.php?token=' . rawurlencode($token);
    }

    // EVERYTHING ELSE must stay inside TCC (no redirect)
    return '';
}



function tcc_safe_actions(string $actionType): array {
    if ($actionType === 'instructor_approval') {
        return ['review', 'grant_attempts', 'require_one_on_one', 'suspend_training'];
    }

    if ($actionType === 'deadline_reason_submission') {
        return ['review', 'extend_deadline', 'request_more_info'];
    }

    if ($actionType === 'remediation_acknowledgement') {
        return ['review', 'acknowledge_completion'];
    }

    return ['review'];
}

/**
 * UI grouping for bulk-select families. Uses action_type; instructor_approval titles
 * from deadline escalation use "Missed Deadline" and belong with deadline workflows.
 */
function tcc_blocker_family(string $actionType, string $title = ''): string
{
    if ($actionType === 'deadline_reason_submission') {
        return 'deadline_related';
    }
    if ($actionType === 'instructor_approval') {
        if (stripos($title, 'Missed Deadline') !== false) {
            return 'deadline_related';
        }
        return 'progress_test_failure_related';
    }
    return 'other';
}

function tcc_action_sort_rank(string $actionType): int
{
    if ($actionType === 'deadline_reason_submission') return 10;
    if ($actionType === 'instructor_approval') return 20;
    return 90;
}

function tcc_bulk_allowed_actions_for_item(array $item): array
{
    $actionType = (string)($item['action_type'] ?? '');
    $status = (string)($item['status'] ?? '');
    if ($actionType === 'deadline_reason_submission') {
        if (!in_array($status, ['pending', 'opened', 'completed'], true)) return [];
        return ['approve_deadline_reason_submission'];
    }
    if (!in_array($status, ['pending', 'opened'], true)) return [];

    if ($actionType === 'instructor_approval') return ['approve_additional_attempts'];
    return [];
}

/** Plain-language text for instructors (bulk preview / execute). */
function tcc_bulk_validation_message(?string $code): string
{
    if ($code === null || $code === '') {
        return '';
    }

    static $map = [
        'use_bulk_approve_additional_attempts_for_instructor_approval_rows' => 'Wrong bulk mode for this row: it is an instructor-approval item (for example “Missed final deadline”). Use “Instructor approval queue…” instead, with decision notes and at least +1 attempt.',
        'bulk_deadline_reason_only_for_deadline_reason_submission_actions' => 'Wrong bulk mode: “Approve deadline reason…” only applies to deadline-reason submission rows, not this action type.',
        'deadline_reason_not_actionable' => 'This deadline-reason row cannot be approved in its current status.',
        'use_bulk_approve_deadline_reason_for_deadline_reason_submission_rows' => 'Wrong bulk mode for this row: it is a deadline-reason submission. Use “Student submitted a deadline reason…” instead (and set +Days if you extend).',
        'bulk_additional_attempts_only_for_instructor_approval_actions' => 'Wrong bulk mode: “Instructor approval queue…” only applies to instructor-approval rows.',
        'granted_extra_attempts_must_be_at_least_1' => 'Enter +Attempts as at least 1 when using instructor-approval bulk.',
        'decision_notes_required' => 'Enter decision notes (required for instructor-approval bulk).',
        'not_allowed_for_action_type_or_status' => 'This row’s type or status does not allow the selected bulk action.',
        'unknown_bulk_action_code' => 'Unknown bulk action. Refresh the page and try again.',
    ];

    if (isset($map[$code])) {
        return $map[$code];
    }

    $pfxDeadline = 'deadline_reason_not_actionable_status_';
    if (strncmp($code, $pfxDeadline, strlen($pfxDeadline)) === 0) {
        $st = substr($code, strlen($pfxDeadline));
        return 'This deadline-reason row is in status “' . $st . '” and cannot be approved with this bulk action.';
    }

    $pfxInstructor = 'instructor_approval_not_pending_or_open_status_';
    if (strncmp($code, $pfxInstructor, strlen($pfxInstructor)) === 0) {
        $st = substr($code, strlen($pfxInstructor));
        return 'This instructor-approval row is in status “' . $st . '”. Bulk only applies when status is pending or opened.';
    }

    return $code;
}

function tcc_actor_ip(): string
{
    return trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
}

function tcc_actor_user_agent(): string
{
    return trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
}

function tcc_log_bulk_event(PDO $pdo, array $row, int $actorUserId, string $actionCode, string $batchId, array $payload, string $status): void
{
    $st = $pdo->prepare("
        INSERT INTO training_progression_events
        (
            user_id,
            cohort_id,
            lesson_id,
            progress_test_id,
            event_type,
            event_code,
            event_status,
            actor_type,
            actor_user_id,
            event_time,
            payload_json,
            legal_note
        )
        VALUES
        (
            :user_id,
            :cohort_id,
            :lesson_id,
            :progress_test_id,
            :event_type,
            :event_code,
            :event_status,
            :actor_type,
            :actor_user_id,
            :event_time,
            :payload_json,
            :legal_note
        )
    ");

    $st->execute([
        ':user_id' => (int)$row['user_id'],
        ':cohort_id' => (int)$row['cohort_id'],
        ':lesson_id' => (int)$row['lesson_id'],
        ':progress_test_id' => $row['progress_test_id'] === null ? null : (int)$row['progress_test_id'],
        ':event_type' => 'instructor_bulk_intervention',
        ':event_code' => $actionCode,
        ':event_status' => $status,
        ':actor_type' => 'admin',
        ':actor_user_id' => $actorUserId,
        ':event_time' => gmdate('Y-m-d H:i:s'),
        ':payload_json' => json_encode([
            'batch_id' => $batchId,
            'required_action_id' => (int)$row['id'],
            'action_type' => (string)$row['action_type'],
            'status_before' => (string)$row['status'],
            'requested_payload' => $payload,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':legal_note' => 'Bulk intervention executed by instructor via Theory Control Center.',
    ]);
}

function tcc_system_watch(PDO $pdo, int $cohortId, ?int $userId = null): array {
    $issues = [];

    $params = [$cohortId];
    $userSql = '';
    if ($userId !== null && $userId > 0) {
        $userSql = ' AND la.user_id = ? ';
        $params[] = $userId;
    }

    $st = $pdo->prepare("
        SELECT
            la.user_id,
            la.cohort_id,
            la.lesson_id,
            la.completion_status,
            la.test_pass_status,
            u.name AS student_name,
            l.title AS lesson_title
        FROM lesson_activity la
        JOIN users u ON u.id = la.user_id
        LEFT JOIN lessons l ON l.id = la.lesson_id
        WHERE la.cohort_id = ?
          {$userSql}
          AND EXISTS (
              SELECT 1
              FROM progress_tests_v2 pt
              WHERE pt.user_id = la.user_id
                AND pt.cohort_id = la.cohort_id
                AND pt.lesson_id = la.lesson_id
                AND pt.status = 'completed'
                AND pt.pass_gate_met = 1
          )
          /* Exclude normal states after a canonical PASS: lesson completed, or test passed and summary review pending. */
          AND NOT (
              COALESCE(la.test_pass_status, '') = 'passed'
              AND COALESCE(la.completion_status, '') IN ('completed', 'awaiting_summary_review')
          )
        LIMIT 100
    ");
    $st->execute($params);

    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $issues[] = [
            'issue_type' => 'pass_exists_projection_not_completed',
            'severity' => 'high',
            'student_id' => (int)$row['user_id'],
            'cohort_id' => (int)$row['cohort_id'],
            'lesson_id' => (int)$row['lesson_id'],
            'student_name' => tcc_student_name($row),
            'lesson_title' => (string)($row['lesson_title'] ?? ''),
            'summary' => 'Canonical PASS exists, but lesson_activity still looks wrong (test not marked passed, or lesson stuck outside awaiting-summary / completed).',
            'recommended_safe_action' => 'recompute_projection',
        ];
    }

    $params = [$cohortId];
    $userSql = '';
    if ($userId !== null && $userId > 0) {
        $userSql = ' AND pt.user_id = ? ';
        $params[] = $userId;
    }

    $st = $pdo->prepare("
        SELECT
            pt.user_id,
            pt.cohort_id,
            pt.lesson_id,
            COUNT(*) AS active_count,
            u.name AS student_name,
            l.title AS lesson_title
        FROM progress_tests_v2 pt
        JOIN users u ON u.id = pt.user_id
        LEFT JOIN lessons l ON l.id = pt.lesson_id
        WHERE pt.cohort_id = ?
          {$userSql}
          AND pt.status IN ('ready','in_progress','processing','preparing')
        GROUP BY pt.user_id, pt.cohort_id, pt.lesson_id
        HAVING COUNT(*) > 1
        LIMIT 100
    ");
    $st->execute($params);

    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $issues[] = [
            'issue_type' => 'duplicate_active_attempts',
            'severity' => 'high',
            'student_id' => (int)$row['user_id'],
            'cohort_id' => (int)$row['cohort_id'],
            'lesson_id' => (int)$row['lesson_id'],
            'student_name' => tcc_student_name($row),
            'lesson_title' => (string)($row['lesson_title'] ?? ''),
            'active_count' => (int)$row['active_count'],
            'summary' => 'More than one active progress test attempt exists for this lesson.',
            'recommended_safe_action' => 'inspect_attempts_and_cleanup_stale',
        ];
    }

    $params = [$cohortId];
    $userSql = '';
    if ($userId !== null && $userId > 0) {
        $userSql = ' AND sra.user_id = ? ';
        $params[] = $userId;
    }

    $st = $pdo->prepare("
        SELECT
            sra.user_id,
            sra.cohort_id,
            sra.lesson_id,
            sra.action_type,
            sra.status,
            u.name AS student_name,
            l.title AS lesson_title
        FROM student_required_actions sra
        JOIN users u ON u.id = sra.user_id
        LEFT JOIN lessons l ON l.id = sra.lesson_id
        WHERE sra.cohort_id = ?
          {$userSql}
          AND sra.status IN ('pending','opened')
          AND EXISTS (
              SELECT 1
              FROM progress_tests_v2 pt
              WHERE pt.user_id = sra.user_id
                AND pt.cohort_id = sra.cohort_id
                AND pt.lesson_id = sra.lesson_id
                AND pt.status = 'completed'
                AND pt.pass_gate_met = 1
          )
        LIMIT 100
    ");
    $st->execute($params);

    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $issues[] = [
            'issue_type' => 'pending_action_on_passed_lesson',
            'severity' => 'high',
            'student_id' => (int)$row['user_id'],
            'cohort_id' => (int)$row['cohort_id'],
            'lesson_id' => (int)$row['lesson_id'],
            'student_name' => tcc_student_name($row),
            'lesson_title' => (string)($row['lesson_title'] ?? ''),
            'action_type' => (string)$row['action_type'],
            'summary' => 'A pending/open required action exists even though the lesson has a canonical PASS.',
            'recommended_safe_action' => 'inspect_required_action_and_recompute',
        ];
    }

	
	    $params = array($cohortId);
    $userSql = '';

    if ($userId !== null && $userId > 0) {
        $userSql = ' AND pt.user_id = ? ';
        $params[] = $userId;
    }

    $st = $pdo->prepare("
        SELECT
            pt.id AS test_id,
            pt.user_id,
            pt.user_id AS student_id,
            pt.cohort_id,
            pt.lesson_id,
            pt.attempt,
            pt.status,
            pt.created_at,
            pt.started_at,
            pt.updated_at,
            u.name AS student_name,
            u.email AS student_email,
            l.title AS lesson_title,
            (
                SELECT COUNT(*)
                FROM progress_tests_v2 pass_pt
                WHERE pass_pt.user_id = pt.user_id
                  AND pass_pt.cohort_id = pt.cohort_id
                  AND pass_pt.lesson_id = pt.lesson_id
                  AND pass_pt.status = 'completed'
                  AND pass_pt.pass_gate_met = 1
            ) AS canonical_pass_count
        FROM progress_tests_v2 pt
        JOIN users u ON u.id = pt.user_id
        LEFT JOIN lessons l ON l.id = pt.lesson_id
        WHERE pt.cohort_id = ?
          {$userSql}
          AND pt.status IN ('ready','in_progress','processing','preparing')
          AND COALESCE(pt.updated_at, pt.started_at, pt.created_at) < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)
        ORDER BY COALESCE(pt.updated_at, pt.started_at, pt.created_at) ASC
        LIMIT 100
    ");
    $st->execute($params);

    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $passCount = (int)($row['canonical_pass_count'] ?? 0);

        if ($passCount <= 0) {
            $issues[] = array(
                'issue_type' => 'old_active_progress_test_attempt',
                'type' => 'old_active_progress_test_attempt',
                'blocker_category' => 'ambiguous',
                'severity' => 'high',
                'repair_allowed' => false,
                'repair_code' => 'inspect_only',
                'student_id' => (int)$row['user_id'],
                'cohort_id' => (int)$row['cohort_id'],
                'lesson_id' => (int)$row['lesson_id'],
                'student_name' => tcc_student_name($row),
                'lesson_title' => (string)($row['lesson_title'] ?? ''),
                'title' => 'Old active progress test attempt',
                'summary' => 'Old active progress test attempt exists without canonical PASS. Inspect only.',
                'recurrence_key' => 'old_active_progress_test_attempt|cohort:' . (int)$row['cohort_id'] . '|lesson:' . (int)$row['lesson_id'],
                'evidence' => array(
                    'test_id' => (int)$row['test_id'],
                    'attempt' => (int)$row['attempt'],
                    'status' => (string)$row['status'],
                    'created_at' => (string)($row['created_at'] ?? ''),
                    'started_at' => (string)($row['started_at'] ?? ''),
                    'updated_at' => (string)($row['updated_at'] ?? ''),
                    'canonical_pass_count' => $passCount,
                    'stale_hours_threshold' => 24
                )
            );
            continue;
        }

        $issues[] = array(
            'issue_type' => 'old_active_progress_test_attempt',
            'type' => 'old_active_progress_test_attempt',
            'blocker_category' => 'stale_bug',
            'severity' => 'medium',
            'repair_allowed' => true,
            'repair_code' => 'cleanup_old_active_attempt_after_pass',
            'student_id' => (int)$row['user_id'],
            'cohort_id' => (int)$row['cohort_id'],
            'lesson_id' => (int)$row['lesson_id'],
            'student_name' => tcc_student_name($row),
            'lesson_title' => (string)($row['lesson_title'] ?? ''),
            'title' => 'Stale progress test attempt after PASS',
            'summary' => 'Old active progress test attempt exists after canonical PASS. Eligible for one-click stale cleanup.',
            'recurrence_key' => 'old_active_progress_test_attempt|cohort:' . (int)$row['cohort_id'] . '|lesson:' . (int)$row['lesson_id'],
            'evidence' => array(
                'test_id' => (int)$row['test_id'],
                'attempt' => (int)$row['attempt'],
                'status' => (string)$row['status'],
                'created_at' => (string)($row['created_at'] ?? ''),
                'started_at' => (string)($row['started_at'] ?? ''),
                'updated_at' => (string)($row['updated_at'] ?? ''),
                'canonical_pass_count' => $passCount,
                'stale_hours_threshold' => 24
            )
        );
    }
	
	
	
    return $issues;
}


function tcc_deadline_delta(?string $completedAt, ?string $deadlineUtc): array {
    $completedAt = trim((string)$completedAt);
    $deadlineUtc = trim((string)$deadlineUtc);

    if ($completedAt === '') {
        return [
            'label' => 'Not completed',
            'days' => null,
            'class' => 'neutral'
        ];
    }

    if ($deadlineUtc === '') {
        return [
            'label' => 'Completed',
            'days' => null,
            'class' => 'ok'
        ];
    }

    try {
        $completed = new DateTime($completedAt, new DateTimeZone('UTC'));
        $deadline = new DateTime($deadlineUtc, new DateTimeZone('UTC'));
        $seconds = $completed->getTimestamp() - $deadline->getTimestamp();
        $days = (int)ceil(abs($seconds) / 86400);

        if ($seconds <= 0) {
            return [
                'label' => ($days <= 0 ? 'On time' : $days . ' day' . ($days === 1 ? '' : 's') . ' early'),
                'days' => -$days,
                'class' => 'ok'
            ];
        }

        return [
            'label' => $days . ' day' . ($days === 1 ? '' : 's') . ' late',
            'days' => $days,
            'class' => 'danger'
        ];
    } catch (Throwable $e) {
        return [
            'label' => 'Unknown',
            'days' => null,
            'class' => 'neutral'
        ];
    }
}

function tcc_student_lesson_timeline(PDO $pdo, int $cohortId, int $studentId): array {
    $lessonStmt = $pdo->prepare("
        SELECT
            d.lesson_id,
            d.deadline_utc AS original_deadline_utc,
            d.sort_order AS cohort_lesson_sort_order,
            l.external_lesson_id,
            l.title AS lesson_title,
            c.id AS course_id,
            c.title AS course_title,
            c.sort_order AS course_sort_order,
            la.completed_at,
            la.effective_deadline_utc,
            la.extension_count,
            la.summary_status,
            la.test_pass_status,
            la.completion_status,
            ls.review_status AS summary_review_status,
            ls.review_score AS summary_review_score,
            ls.updated_at AS summary_updated_at
        FROM cohort_lesson_deadlines d
        JOIN lessons l ON l.id = d.lesson_id
        JOIN courses c ON c.id = l.course_id
        LEFT JOIN lesson_activity la
               ON la.user_id = ?
              AND la.cohort_id = d.cohort_id
              AND la.lesson_id = d.lesson_id
        LEFT JOIN lesson_summaries ls
               ON ls.user_id = ?
              AND ls.cohort_id = d.cohort_id
              AND ls.lesson_id = d.lesson_id
        WHERE d.cohort_id = ?
        ORDER BY c.sort_order ASC, c.id ASC, d.sort_order ASC, d.id ASC
    ");
    $lessonStmt->execute([$studentId, $studentId, $cohortId]);
    $rows = $lessonStmt->fetchAll(PDO::FETCH_ASSOC);

    $lessonIds = array_map(static fn($row): int => (int)$row['lesson_id'], $rows);

    if (!$lessonIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($lessonIds), '?'));

    $testMap = [];
    foreach ($lessonIds as $lessonId) {
        $testMap[$lessonId] = [
            'attempt_count' => 0,
            'last_score' => null,
            'last_status' => '',
            'last_completed_at' => '',
            'passed' => false
        ];
    }

    $testParams = array_merge([$studentId, $cohortId], $lessonIds);
    $testStmt = $pdo->prepare("
        SELECT
            id,
            lesson_id,
            attempt,
            status,
            pass_gate_met,
            score_pct,
            completed_at,
            formal_result_code,
            counts_as_unsat
        FROM progress_tests_v2
        WHERE user_id = ?
          AND cohort_id = ?
          AND lesson_id IN (" . $placeholders . ")
        ORDER BY lesson_id ASC, id DESC
    ");
    $testStmt->execute($testParams);

    foreach ($testStmt->fetchAll(PDO::FETCH_ASSOC) as $testRow) {
        $lessonId = (int)$testRow['lesson_id'];
        $formalCode = (string)($testRow['formal_result_code'] ?? '');
        $countsAsUnsat = (int)($testRow['counts_as_unsat'] ?? 0);
        $passGateMet = (int)($testRow['pass_gate_met'] ?? 0);
        $isStaleNoise = ($formalCode === 'STALE_ABORTED' && $countsAsUnsat === 0 && $passGateMet === 0);

        if ($isStaleNoise) {
            continue;
        }

        $testMap[$lessonId]['attempt_count']++;

        if ($testMap[$lessonId]['last_status'] === '') {
            $testMap[$lessonId]['last_status'] = (string)($testRow['status'] ?? '');
            $testMap[$lessonId]['last_score'] = ($testRow['score_pct'] === null ? null : (int)$testRow['score_pct']);
            $testMap[$lessonId]['last_completed_at'] = (string)($testRow['completed_at'] ?? '');
        }

        if ((string)($testRow['status'] ?? '') === 'completed' && $passGateMet === 1) {
            $testMap[$lessonId]['passed'] = true;
        }
    }

    $interventionMap = array_fill_keys($lessonIds, 0);
    $actionParams = array_merge([$studentId, $cohortId], $lessonIds);
    $actionStmt = $pdo->prepare("
        SELECT lesson_id, COUNT(*) AS intervention_count
        FROM student_required_actions
        WHERE user_id = ?
          AND cohort_id = ?
          AND lesson_id IN (" . $placeholders . ")
        GROUP BY lesson_id
    ");
    $actionStmt->execute($actionParams);
    foreach ($actionStmt->fetchAll(PDO::FETCH_ASSOC) as $actionRow) {
        $interventionMap[(int)$actionRow['lesson_id']] = (int)$actionRow['intervention_count'];
    }

    $overrideMap = array_fill_keys($lessonIds, 0);
    try {
        $overrideParams = array_merge([$studentId, $cohortId], $lessonIds);
        $overrideStmt = $pdo->prepare("
            SELECT lesson_id, COUNT(*) AS override_count
            FROM student_lesson_deadline_overrides
            WHERE user_id = ?
              AND cohort_id = ?
              AND is_active = 1
              AND lesson_id IN (" . $placeholders . ")
            GROUP BY lesson_id
        ");
        $overrideStmt->execute($overrideParams);
        foreach ($overrideStmt->fetchAll(PDO::FETCH_ASSOC) as $overrideRow) {
            $overrideMap[(int)$overrideRow['lesson_id']] = (int)$overrideRow['override_count'];
        }
    } catch (Throwable $e) {
        $overrideMap = array_fill_keys($lessonIds, 0);
    }

    $timeline = [];
    foreach ($rows as $row) {
        $lessonId = (int)$row['lesson_id'];
        $effectiveDeadline = trim((string)($row['effective_deadline_utc'] ?? ''));
        if ($effectiveDeadline === '') {
            $effectiveDeadline = (string)($row['original_deadline_utc'] ?? '');
        }

        $completedAt = (string)($row['completed_at'] ?? '');
        $delta = tcc_deadline_delta($completedAt, $effectiveDeadline);
        $summaryStatus = (string)($row['summary_review_status'] ?? '');
        if ($summaryStatus === '') {
            $summaryStatus = (string)($row['summary_status'] ?? '');
        }

        $extensionCount = (int)($row['extension_count'] ?? 0);
        if (isset($overrideMap[$lessonId]) && $overrideMap[$lessonId] > $extensionCount) {
            $extensionCount = $overrideMap[$lessonId];
        }

        $timeline[] = [
            'course_id' => (int)$row['course_id'],
            'course_title' => (string)$row['course_title'],
            'lesson_id' => $lessonId,
            'external_lesson_id' => (int)($row['external_lesson_id'] ?? 0),
            'lesson_title' => (string)$row['lesson_title'],
            'original_deadline_utc' => (string)($row['original_deadline_utc'] ?? ''),
            'effective_deadline_utc' => $effectiveDeadline,
            'completed_at' => $completedAt,
            'deadline_delta_label' => $delta['label'],
            'deadline_delta_days' => $delta['days'],
            'deadline_delta_class' => $delta['class'],
            'extension_count' => $extensionCount,
            'summary_status' => $summaryStatus,
            'summary_score' => ($row['summary_review_score'] === null ? null : (int)$row['summary_review_score']),
            'summary_updated_at' => (string)($row['summary_updated_at'] ?? ''),
            'test_status' => $testMap[$lessonId]['last_status'] ?? '',
            'test_passed' => !empty($testMap[$lessonId]['passed']),
            'last_score' => $testMap[$lessonId]['last_score'] ?? null,
            'attempt_count' => (int)($testMap[$lessonId]['attempt_count'] ?? 0),
            'intervention_count' => (int)($interventionMap[$lessonId] ?? 0),
            'completion_status' => (string)($row['completion_status'] ?? '')
        ];
    }

    return $timeline;
}


function tcc_audio_url(?string $audioPath): string {
    $audioPath = trim((string)$audioPath);
    if ($audioPath === '') {
        return '';
    }
    if (preg_match('~^https?://~i', $audioPath)) {
        return $audioPath;
    }
    $base = 'https://ipca-media.nyc3.cdn.digitaloceanspaces.com/';
    return $base . ltrim($audioPath, '/');
}

function tcc_email_recipient_label(array $row): string {
    $recipientType = strtolower(trim((string)($row['recipient_type'] ?? '')));
    if ($recipientType === 'student' || $recipientType === 'instructor') {
        return $recipientType;
    }

    $emailType = strtolower(trim((string)($row['email_type'] ?? '')));
    if (strpos($emailType, 'chief') !== false || strpos($emailType, 'instructor') !== false) {
        return 'instructor';
    }

    return 'student';
}

function tcc_email_delivery_status(array $row): string {
    $sentStatus = strtolower(trim((string)($row['sent_status'] ?? '')));
    if (in_array($sentStatus, ['sent', 'failed', 'queued', 'pending'], true)) {
        return $sentStatus === 'queued' || $sentStatus === 'pending' ? 'sent' : $sentStatus;
    }

    return trim((string)($row['sent_at'] ?? '')) !== '' ? 'sent' : 'failed';
}

function tcc_email_readable_body(array $row): string {
    $html = trim((string)($row['body_html'] ?? ''));
    $text = trim((string)($row['body_text'] ?? ''));
    if ($text !== '') {
        return $text;
    }

    if ($html === '') {
        return 'No rendered email body available.';
    }

    $normalized = preg_replace('/<br\s*\/?>/i', "\n", $html);
    $normalized = preg_replace('/<\/p>/i', "\n\n", (string)$normalized);
    $plain = strip_tags((string)$normalized);
    $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $plain = preg_replace('/[ \t]+/', ' ', $plain);
    $plain = preg_replace('/\R{3,}/', "\n\n", $plain);
    $plain = trim((string)$plain);
    return $plain !== '' ? $plain : 'No rendered email body available.';
}

function tcc_lesson_identity(PDO $pdo, int $cohortId, int $lessonId): array {
    $st = $pdo->prepare("
        SELECT
            l.id AS lesson_id,
            l.external_lesson_id,
            l.title AS lesson_title,
            c.id AS course_id,
            c.title AS course_title,
            d.deadline_utc AS original_deadline_utc,
            d.sort_order AS cohort_lesson_sort_order
        FROM cohort_lesson_deadlines d
        JOIN lessons l ON l.id = d.lesson_id
        JOIN courses c ON c.id = l.course_id
        WHERE d.cohort_id = ?
          AND d.lesson_id = ?
        LIMIT 1
    ");
    $st->execute([$cohortId, $lessonId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: [];
}

function tcc_lesson_summary_detail(PDO $pdo, int $cohortId, int $studentId, int $lessonId): array {
    $lesson = tcc_lesson_identity($pdo, $cohortId, $lessonId);

    $st = $pdo->prepare("
        SELECT *
        FROM lesson_summaries
        WHERE cohort_id = ?
          AND user_id = ?
          AND lesson_id = ?
        LIMIT 1
    ");
    $st->execute([$cohortId, $studentId, $lessonId]);
    $summary = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    $versions = [];
    try {
        $vs = $pdo->prepare("
            SELECT *
            FROM lesson_summary_versions
            WHERE cohort_id = ?
              AND user_id = ?
              AND lesson_id = ?
            ORDER BY id DESC
            LIMIT 10
        ");
        $vs->execute([$cohortId, $studentId, $lessonId]);
        $versions = $vs->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $versions = [];
    }

    return [
        'lesson' => $lesson,
        'summary' => $summary,
        'versions' => $versions,
        'ai_interpretation' => [
            'status' => 'not_generated_in_phase_2j',
            'copy_paste_check' => 'Not evaluated yet.',
            'ai_generated_check' => 'Not evaluated yet.',
            'similarity_check' => 'Not evaluated yet.',
            'quality_feedback' => 'AI interpretation will be added after the UI and canonical data views are stable.',
            'improvement_suggestions' => []
        ]
    ];
}

function tcc_lesson_attempts_detail(PDO $pdo, int $cohortId, int $studentId, int $lessonId): array {
    $lesson = tcc_lesson_identity($pdo, $cohortId, $lessonId);

    $attemptStmt = $pdo->prepare("
        SELECT *
        FROM progress_tests_v2
        WHERE cohort_id = ?
          AND user_id = ?
          AND lesson_id = ?
        ORDER BY attempt DESC, id DESC
        LIMIT 20
    ");
    $attemptStmt->execute([$cohortId, $studentId, $lessonId]);
    $attempts = $attemptStmt->fetchAll(PDO::FETCH_ASSOC);

    $attemptIds = [];
    foreach ($attempts as $a) {
        $attemptIds[] = (int)($a['id'] ?? 0);
    }
    $attemptIds = array_values(array_filter($attemptIds));

    $itemsByAttempt = [];
    if ($attemptIds) {
        $placeholders = implode(',', array_fill(0, count($attemptIds), '?'));
        try {
            $itemStmt = $pdo->prepare("
                SELECT *
                FROM progress_test_items_v2
                WHERE test_id IN (" . $placeholders . ")
                ORDER BY test_id DESC, idx ASC, id ASC
            ");
            $itemStmt->execute($attemptIds);
            foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $testId = (int)($item['test_id'] ?? 0);
                if (!isset($itemsByAttempt[$testId])) {
                    $itemsByAttempt[$testId] = [];
                }
                if (isset($item['audio_path'])) {
                    $item['audio_url'] = tcc_audio_url((string)$item['audio_path']);
                } else {
                    $item['audio_url'] = '';
                }
                $itemsByAttempt[$testId][] = $item;
            }
        } catch (Throwable $e) {
            $itemsByAttempt = [];
        }
    }

    foreach ($attempts as &$attempt) {
        $testId = (int)($attempt['id'] ?? 0);
        $attempt['items'] = $itemsByAttempt[$testId] ?? [];
    }
    unset($attempt);

    return [
        'lesson' => $lesson,
        'attempts' => $attempts
    ];
}

function tcc_lesson_interventions_detail(PDO $pdo, int $cohortId, int $studentId, int $lessonId = 0): array {
    $lesson = $lessonId > 0 ? tcc_lesson_identity($pdo, $cohortId, $lessonId) : [];
    $lessonWhere = $lessonId > 0 ? ' AND lesson_id = ? ' : '';

    $actionsParams = $lessonId > 0 ? [$cohortId, $studentId, $lessonId] : [$cohortId, $studentId];
    $actionsStmt = $pdo->prepare("
        SELECT *
        FROM student_required_actions
        WHERE cohort_id = ?
          AND user_id = ?
          " . $lessonWhere . "
        ORDER BY created_at ASC, id ASC
        LIMIT 100
    ");
    $actionsStmt->execute($actionsParams);
    $requiredActions = $actionsStmt->fetchAll(PDO::FETCH_ASSOC);

    $deadlineOverrides = [];
    try {
        $overrideParams = $lessonId > 0 ? [$cohortId, $studentId, $lessonId] : [$cohortId, $studentId];
        $overrideStmt = $pdo->prepare("
            SELECT *
            FROM student_lesson_deadline_overrides
            WHERE cohort_id = ?
              AND user_id = ?
              " . $lessonWhere . "
            ORDER BY created_at ASC, id ASC
            LIMIT 100
        ");
        $overrideStmt->execute($overrideParams);
        $deadlineOverrides = $overrideStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $deadlineOverrides = [];
    }

    $emails = [];
    try {
        $emailParams = $lessonId > 0 ? [$cohortId, $studentId, $lessonId] : [$cohortId, $studentId];
        $emailStmt = $pdo->prepare("
            SELECT *
            FROM training_progression_emails
            WHERE cohort_id = ?
              AND user_id = ?
              " . $lessonWhere . "
            ORDER BY created_at ASC, id ASC
            LIMIT 100
        ");
        $emailStmt->execute($emailParams);
        $emails = $emailStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($emails as &$emailRow) {
            $emailRow['recipient_label'] = tcc_email_recipient_label($emailRow);
            $emailRow['delivery_status'] = tcc_email_delivery_status($emailRow);
            $emailRow['sent_timestamp'] = (string)($emailRow['sent_at'] ?? $emailRow['created_at'] ?? '');
            $emailRow['readable_body'] = tcc_email_readable_body($emailRow);
            if (trim((string)($emailRow['title'] ?? '')) === '') {
                $emailRow['title'] = (string)($emailRow['subject'] ?? $emailRow['email_type'] ?? 'Progression Email');
            }
        }
        unset($emailRow);
    } catch (Throwable $e) {
        $emails = [];
    }

    $events = [];
    try {
        $eventParams = $lessonId > 0 ? [$cohortId, $studentId, $lessonId] : [$cohortId, $studentId];
        $eventStmt = $pdo->prepare("
            SELECT *
            FROM training_progression_events
            WHERE cohort_id = ?
              AND user_id = ?
              " . $lessonWhere . "
            ORDER BY created_at ASC, id ASC
            LIMIT 100
        ");
        $eventStmt->execute($eventParams);
        $events = $eventStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $events = [];
    }

    return [
        'lesson' => $lesson,
        'required_actions' => $requiredActions,
        'deadline_overrides' => $deadlineOverrides,
        'emails' => $emails,
        'events' => $events
    ];
}

function tcc_ai_env_key(): string
{
    $keys = [
        'CW_OPENAI_API_KEY',
        'OPENAI_API_KEY',
        'IPCA_OPENAI_API_KEY',
    ];

    foreach ($keys as $key) {
        $value = trim((string)getenv($key));
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function tcc_strip_summary_for_ai(string $html, string $plain): string
{
    $text = trim($plain);
    if ($text === '') {
        $text = trim(strip_tags($html));
    }

    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\R{3,}/', "\n\n", $text);
    $text = trim((string)$text);

    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, 9000);
    }

    return substr($text, 0, 9000);
}

function tcc_word_count_for_ai(string $text): int
{
    $text = trim($text);
    if ($text === '') {
        return 0;
    }

    $parts = preg_split('/\s+/u', $text);
    return is_array($parts) ? count(array_filter($parts)) : 0;
}

function tcc_text_similarity_pct(string $a, string $b): float
{
    $a = trim(preg_replace('/\s+/', ' ', strtolower($a)));
    $b = trim(preg_replace('/\s+/', ' ', strtolower($b)));

    if ($a === '' || $b === '') {
        return 0.0;
    }

    if (function_exists('mb_substr')) {
        $a = mb_substr($a, 0, 4000);
        $b = mb_substr($b, 0, 4000);
    } else {
        $a = substr($a, 0, 4000);
        $b = substr($b, 0, 4000);
    }

    similar_text($a, $b, $pct);
    return round((float)$pct, 1);
}

function tcc_extract_response_text(array $response): string
{
    if (isset($response['output_text']) && is_string($response['output_text'])) {
        return trim($response['output_text']);
    }

    $chunks = [];

    if (!empty($response['output']) && is_array($response['output'])) {
        foreach ($response['output'] as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (!empty($item['content']) && is_array($item['content'])) {
                foreach ($item['content'] as $contentItem) {
                    if (!is_array($contentItem)) {
                        continue;
                    }

                    if (isset($contentItem['text']) && is_string($contentItem['text'])) {
                        $chunks[] = $contentItem['text'];
                    }
                }
            }
        }
    }

    return trim(implode("\n", $chunks));
}

function tcc_safe_json_from_ai(string $text): array
{
    $text = trim($text);

    if ($text === '') {
        return [];
    }

    $decoded = json_decode($text, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    if (preg_match('/\{.*\}/s', $text, $m)) {
        $decoded = json_decode($m[0], true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return [
        'analysis_status' => 'unparsed',
        'raw_text' => $text,
    ];
}


require_once __DIR__ . '/../../../src/openai.php';

function tcc_call_openai_summary_analysis(array $payload): array
{
    $summaryText = trim((string)($payload['summary']['text'] ?? ''));
    if ($summaryText === '') {
        return [
            'ok' => false,
            'error' => 'empty_summary_text',
            'message' => 'The lesson summary text is empty.',
        ];
    }

    $studentName = (string)($payload['student']['student_name'] ?? 'Student');
    $lessonTitle = (string)($payload['lesson']['lesson_title'] ?? 'Lesson');
    $courseTitle = (string)($payload['lesson']['course_title'] ?? 'Module');
    $reviewStatus = (string)($payload['summary']['review_status'] ?? '');
    $reviewScore = $payload['summary']['review_score'] ?? null;
    $wordCount = (int)($payload['summary']['word_count'] ?? 0);
    $similarityContext = $payload['cohort_similarity_context'] ?? [];

    $promptPayload = [
        'task' => 'Analyze an aviation theory lesson summary for instructor advisory review only.',
        'important_rules' => [
            'Return valid JSON only. No markdown. No prose outside JSON.',
            'Do not accuse the student of misconduct. Use likelihood language only.',
            'This is advisory only and must not be treated as canonical progression truth.',
            'Base the educational quality assessment on the supplied summary text only.',
        ],
        'required_json_schema' => [
            'copy_paste_likelihood' => 'Low|Medium|High|Not evaluated',
            'ai_tool_likelihood' => 'Low|Medium|High|Not evaluated',
            'similarity' => 'Low|Medium|High|Not evaluated',
            'highest_similarity' => 'Low|Medium|High|Not evaluated',
            'highest_similarity_student' => 'student name or null',
            'highest_similarity_pct' => 'number 0-100',
            'understanding' => 'Poor|Partial|Good|Strong|Not evaluated',
            'deep_understanding' => 'Poor|Partial|Good|Strong|Not evaluated',
            'quality_feedback' => 'short instructor-facing paragraph',
            'substantially_good' => ['short bullet strings'],
            'substantially_weak' => ['short bullet strings'],
            'suggestions' => ['short actionable improvement suggestions'],
            'red_flags' => ['short caution signals, empty if none'],
        ],
        'student' => [
            'name' => $studentName,
        ],
        'lesson' => [
            'module' => $courseTitle,
            'lesson' => $lessonTitle,
        ],
        'current_review_state' => [
            'review_status' => $reviewStatus,
            'review_score' => $reviewScore,
            'word_count' => $wordCount,
        ],
        'cohort_similarity_context' => $similarityContext,
        'student_summary_text' => $summaryText,
    ];

    try {
        $model = cw_openai_model();

        $resp = cw_openai_responses([
            'model' => $model,
            'input' => json_encode($promptPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'max_output_tokens' => 900,
        ]);

        $analysis = cw_openai_extract_json_text($resp);

        if (!is_array($analysis) || !$analysis) {
            return [
                'ok' => false,
                'error' => 'empty_or_invalid_ai_json',
                'message' => 'OpenAI returned no usable JSON analysis.',
            ];
        }

        return [
            'ok' => true,
            'model' => $model,
            'response_id' => (string)($resp['id'] ?? ''),
            'analysis' => $analysis,
        ];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'error' => 'openai_request_failed',
            'message' => $e->getMessage(),
        ];
    }
}



function tcc_similarity_context_for_summary(PDO $pdo, int $cohortId, int $studentId, int $lessonId, string $summaryText): array
{
    $out = [
        'highest_similarity' => 'Not evaluated',
        'highest_similarity_student' => null,
        'highest_similarity_pct' => 0,
        'matches' => [],
    ];

    if (trim($summaryText) === '') {
        return $out;
    }

    try {
        $st = $pdo->prepare("
            SELECT
                ls.user_id,
                COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))), ''), u.email, CONCAT('User #', u.id)) AS student_name,
                ls.summary_html,
                ls.summary_plain
            FROM lesson_summaries ls
            JOIN users u ON u.id = ls.user_id
            WHERE ls.cohort_id = ?
              AND ls.lesson_id = ?
              AND ls.user_id <> ?
            LIMIT 50
        ");
        $st->execute([$cohortId, $lessonId, $studentId]);

        $best = null;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $otherText = tcc_strip_summary_for_ai((string)($row['summary_html'] ?? ''), (string)($row['summary_plain'] ?? ''));
            $pct = tcc_text_similarity_pct($summaryText, $otherText);

            $match = [
                'student_id' => (int)$row['user_id'],
                'student_name' => (string)$row['student_name'],
                'similarity_pct' => $pct,
            ];

            $out['matches'][] = $match;

            if ($best === null || $pct > (float)$best['similarity_pct']) {
                $best = $match;
            }
        }

        usort($out['matches'], function ($a, $b) {
            return (float)$b['similarity_pct'] <=> (float)$a['similarity_pct'];
        });

        $out['matches'] = array_slice($out['matches'], 0, 5);

        if ($best !== null) {
            $out['highest_similarity_student'] = $best['student_name'];
            $out['highest_similarity_pct'] = $best['similarity_pct'];

            if ($best['similarity_pct'] >= 82) {
                $out['highest_similarity'] = 'High';
            } elseif ($best['similarity_pct'] >= 62) {
                $out['highest_similarity'] = 'Medium';
            } elseif ($best['similarity_pct'] > 0) {
                $out['highest_similarity'] = 'Low';
            }
        }
    } catch (Throwable $e) {
        $out['highest_similarity'] = 'Not evaluated';
    }

    return $out;
}

$action = tcc_str($_GET['action'] ?? '');

if ($action === '') {
    tcc_json([
        'ok' => false,
        'error' => 'missing_action',
        'allowed_actions' => [
            'cohort_overview',
            'action_queue',
            'bulk_action_preview',
            'bulk_action_execute',
            'student_snapshot',
            'system_watch',
            'student_lessons',
            'lesson_summary_detail',
            'lesson_attempts_detail',
            'lesson_interventions_detail',
            'debug_report',
            'ai_summary_analysis'
        ]
    ], 400);
}

try {
    if ($action === 'cohort_overview') {
        $cohortId = tcc_int($_GET['cohort_id'] ?? 0);

        if ($cohortId <= 0) {
            tcc_json([
                'ok' => true,
                'action' => 'cohort_overview',
                'cohorts' => tcc_fetch_cohorts($pdo)
            ]);
        }

        $students = tcc_cohort_students($pdo, $cohortId);
        $totalLessons = tcc_total_lessons($pdo, $cohortId);
        $studentRows = [];

        $blockedCount = 0;
        $atRiskCount = 0;
        $onTrackCount = 0;

        foreach ($students as $student) {
            $sid = (int)$student['id'];
            $name = tcc_student_name($student);
            $passed = tcc_passed_lessons($pdo, $sid, $cohortId);
            $progressPct = $totalLessons > 0 ? (int)round(($passed / $totalLessons) * 100) : 0;
            $actions = tcc_pending_actions($pdo, $cohortId, $sid);
            $stats = tcc_student_attempt_stats($pdo, $cohortId, $sid);
            $deadlineMisses = tcc_deadline_missed_count($pdo, $cohortId, $sid);
            $motivation = tcc_motivation_signal($stats, $deadlineMisses, count($actions));

            $state = 'on_track';
            if (count($actions) > 0) {
                $state = 'blocked';
                $blockedCount++;
            } elseif ($motivation['level'] === 'drifting' || $motivation['level'] === 'needs_contact') {
                $state = 'at_risk';
                $atRiskCount++;
            } else {
                $onTrackCount++;
            }

            $studentRows[] = [
                'student_id' => $sid,
                'name' => $name,
                'email' => (string)($student['email'] ?? ''),
                'photo_path' => (string)($student['photo_path'] ?? ''),
                'avatar_initials' => tcc_avatar_initials($name),
                'progress_pct' => $progressPct,
                'passed_lessons' => $passed,
                'total_lessons' => $totalLessons,
                'pending_action_count' => count($actions),
                'failed_attempts' => $stats['failed_attempts'],
                'avg_score' => $stats['avg_score'],
                'deadline_misses' => $deadlineMisses,
                'motivation' => $motivation,
                'state' => $state,
            ];
        }

        usort($studentRows, function ($a, $b) {
            if ($a['progress_pct'] === $b['progress_pct']) {
                return strcmp($a['name'], $b['name']);
            }
            return $b['progress_pct'] <=> $a['progress_pct'];
        });

        tcc_json([
            'ok' => true,
            'action' => 'cohort_overview',
            'cohort_id' => $cohortId,
            'summary' => [
                'student_count' => count($students),
                'total_lessons' => $totalLessons,
                'on_track_count' => $onTrackCount,
                'at_risk_count' => $atRiskCount,
                'blocked_count' => $blockedCount,
            ],
            'students' => $studentRows,
        ]);
    }

    if ($action === 'action_queue') {
        $cohortId = tcc_int($_GET['cohort_id'] ?? 0);
        if ($cohortId <= 0) {
            tcc_json(['ok' => false, 'error' => 'missing_cohort_id'], 400);
        }

        $actions = tcc_pending_actions($pdo, $cohortId);
        $items = [];

        foreach ($actions as $row) {
            $studentName = tcc_student_name([
                'name' => $row['student_name'] ?? '',
                'email' => $row['student_email'] ?? ''
            ]);

            $actionType = (string)$row['action_type'];

            $items[] = [
                'required_action_id' => (int)$row['id'],
                'student_id' => (int)$row['user_id'],
                'student_name' => $studentName,
                'photo_path' => (string)($row['student_photo_path'] ?? ''),
                'avatar_initials' => tcc_avatar_initials($studentName),
                'cohort_id' => (int)$row['cohort_id'],
                'lesson_id' => (int)$row['lesson_id'],
                'lesson_title' => (string)($row['lesson_title'] ?? ''),
                'action_type' => $actionType,
				'status' => (string)$row['status'],
				'severity' => tcc_action_severity($actionType),
                'blocker_family' => tcc_blocker_family($actionType, (string)($row['title'] ?? '')),
                'sort_rank' => tcc_action_sort_rank($actionType),
				'official_flow_url' => tcc_official_flow_url($actionType, (string)($row['token'] ?? '')),
                'reason' => (string)($row['title'] ?? $actionType),
                'recommended_action' => tcc_recommended_action($actionType),
                'safe_actions' => tcc_safe_actions($actionType),
                'bulk_allowed_actions' => tcc_bulk_allowed_actions_for_item($row),
                'created_at' => (string)($row['created_at'] ?? ''),
                'opened_at' => (string)($row['opened_at'] ?? ''),
                'token' => (string)($row['token'] ?? ''),
            ];
        }

        usort($items, static function (array $a, array $b): int {
            $ar = (int)($a['sort_rank'] ?? 99);
            $br = (int)($b['sort_rank'] ?? 99);
            if ($ar !== $br) return $ar <=> $br;

            $severityRank = ['high' => 1, 'medium' => 2, 'low' => 3];
            $sa = $severityRank[(string)($a['severity'] ?? '')] ?? 9;
            $sb = $severityRank[(string)($b['severity'] ?? '')] ?? 9;
            if ($sa !== $sb) return $sa <=> $sb;

            $at = strtotime((string)($a['created_at'] ?? '')) ?: PHP_INT_MAX;
            $bt = strtotime((string)($b['created_at'] ?? '')) ?: PHP_INT_MAX;
            if ($at !== $bt) return $at <=> $bt;

            return strcmp((string)($a['student_name'] ?? ''), (string)($b['student_name'] ?? ''));
        });

        tcc_json([
            'ok' => true,
            'action' => 'action_queue',
            'cohort_id' => $cohortId,
            'count' => count($items),
            'items' => $items,
        ]);
    }

    if ($action === 'bulk_action_preview' || $action === 'bulk_action_execute') {
        if (function_exists('set_time_limit')) {
            @set_time_limit($action === 'bulk_action_execute' ? 900 : 300);
        }
        if ($action === 'bulk_action_execute' && function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }

        $payload = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($payload)) $payload = [];

        $cohortId = (int)($payload['cohort_id'] ?? 0);
        $requiredActionIds = array_values(array_unique(array_map('intval', (array)($payload['required_action_ids'] ?? []))));
        $actionCode = trim((string)($payload['bulk_action_code'] ?? ''));
        $decisionNotes = trim((string)($payload['decision_notes'] ?? ''));
        $grantedExtraAttempts = max(0, min(5, (int)($payload['granted_extra_attempts'] ?? 0)));
        $deadlineExtensionDays = max(0, min(10, (int)($payload['deadline_extension_days'] ?? 0)));

        if ($cohortId <= 0 || !$requiredActionIds || $actionCode === '') {
            tcc_json(['ok' => false, 'error' => 'missing_bulk_payload'], 400);
        }

        $ph = implode(',', array_fill(0, count($requiredActionIds), '?'));
        $params = array_merge([$cohortId], $requiredActionIds);
        $st = $pdo->prepare("
            SELECT id,user_id,cohort_id,lesson_id,progress_test_id,action_type,status,title,token,created_at,updated_at
            FROM student_required_actions
            WHERE cohort_id = ?
              AND id IN ($ph)
            ORDER BY created_at ASC, id ASC
        ");
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        $allowedCount = 0;
        foreach ($rows as $row) {
            $allowedActions = tcc_bulk_allowed_actions_for_item($row);
            $rowActionType = (string)$row['action_type'];
            $rowStatus = (string)$row['status'];
            $validation = null;

            // Validate chosen bulk code against this row (specific messages first; do not short-circuit with a generic code).
            if ($actionCode === 'approve_deadline_reason_submission') {
                if ($rowActionType !== 'deadline_reason_submission') {
                    $validation = $rowActionType === 'instructor_approval'
                        ? 'use_bulk_approve_additional_attempts_for_instructor_approval_rows'
                        : 'bulk_deadline_reason_only_for_deadline_reason_submission_actions';
                } elseif (!in_array($rowStatus, ['pending', 'opened', 'completed'], true)) {
                    $validation = 'deadline_reason_not_actionable_status_' . $rowStatus;
                } elseif (!in_array($actionCode, $allowedActions, true)) {
                    $validation = 'deadline_reason_not_actionable';
                }
            } elseif ($actionCode === 'approve_additional_attempts') {
                if ($rowActionType !== 'instructor_approval') {
                    $validation = $rowActionType === 'deadline_reason_submission'
                        ? 'use_bulk_approve_deadline_reason_for_deadline_reason_submission_rows'
                        : 'bulk_additional_attempts_only_for_instructor_approval_actions';
                } elseif (!in_array($rowStatus, ['pending', 'opened'], true)) {
                    $validation = 'instructor_approval_not_pending_or_open_status_' . $rowStatus;
                } elseif ($grantedExtraAttempts < 1) {
                    $validation = 'granted_extra_attempts_must_be_at_least_1';
                } elseif ($decisionNotes === '') {
                    $validation = 'decision_notes_required';
                } elseif (!in_array($actionCode, $allowedActions, true)) {
                    $validation = 'not_allowed_for_action_type_or_status';
                }
            } else {
                $validation = 'unknown_bulk_action_code';
            }

            $isAllowed = $validation === null;
            if ($isAllowed) {
                $allowedCount++;
            }

            $results[] = [
                'required_action_id' => (int)$row['id'],
                'user_id' => (int)$row['user_id'],
                'lesson_id' => (int)$row['lesson_id'],
                'action_type' => (string)$row['action_type'],
                'status' => (string)$row['status'],
                'title' => (string)$row['title'],
                'allowed' => $validation === null,
                'validation_error' => $validation,
                'validation_message' => tcc_bulk_validation_message($validation),
            ];
        }

        if ($action === 'bulk_action_preview') {
            tcc_json([
                'ok' => true,
                'action' => 'bulk_action_preview',
                'bulk_action_code' => $actionCode,
                'requested_count' => count($requiredActionIds),
                'matched_count' => count($rows),
                'allowed_count' => $allowedCount,
                'results' => $results,
            ]);
        }

        $batchId = 'BULK-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
        $ip = tcc_actor_ip();
        $ua = tcc_actor_user_agent();
        $executeResults = [];

        foreach ($rows as $row) {
            $rowId = (int)$row['id'];
            $rowResult = null;
            foreach ($results as $r) {
                if ((int)$r['required_action_id'] === $rowId) {
                    $rowResult = $r;
                    break;
                }
            }
            if (!$rowResult || empty($rowResult['allowed'])) {
                $skipCode = (string)($rowResult['validation_error'] ?? 'validation_failed');
                $skipMsg = tcc_bulk_validation_message($skipCode !== '' ? $skipCode : null);
                if ($skipMsg === '' && $skipCode !== '') {
                    $skipMsg = $skipCode;
                }
                $executeResults[] = [
                    'required_action_id' => $rowId,
                    'executed' => false,
                    'status' => 'skipped',
                    'message' => $skipMsg,
                    'validation_code' => $skipCode,
                ];
                continue;
            }

            try {
                $automationDispatch = null;
                $automationDispatchError = null;
                if ($actionCode === 'approve_deadline_reason_submission') {
                    $engine->approveDeadlineReasonSubmissionByInstructor(
                        $rowId,
                        $currentUserId,
                        $decisionNotes,
                        $deadlineExtensionDays,
                        $ip,
                        $ua
                    );
                    try {
                        $decisionNotesText = $decisionNotes !== '' ? $decisionNotes : 'Approved by instructor.';
                        $decisionNotesHtml = nl2br(htmlspecialchars($decisionNotesText, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                        $automationDispatch = $engine->dispatchRequiredActionCompletedAutomationEvent(
                            $rowId,
                            $currentUserId,
                            'admin',
                            [
                                'decision_code' => 'approve_deadline_reason_submission',
                                'decision_notes_text' => $decisionNotesText,
                                'decision_notes_html' => $decisionNotesHtml,
                            ]
                        );
                    } catch (Throwable $dispatchError) {
                        $automationDispatchError = $dispatchError->getMessage();
                    }
                } elseif ($actionCode === 'approve_additional_attempts') {
                    $engine->processInstructorApprovalDecision(
                        $rowId,
                        [
                            'decision_code' => 'approve_additional_attempts',
                            'decision_notes' => $decisionNotes,
                            'granted_extra_attempts' => $grantedExtraAttempts,
                            'deadline_extension_days' => $deadlineExtensionDays,
                        ],
                        $currentUserId,
                        $ip,
                        $ua
                    );
                } else {
                    throw new RuntimeException('Unsupported bulk action code.');
                }

                tcc_log_bulk_event($pdo, $row, $currentUserId, $actionCode, $batchId, [
                    'decision_notes' => $decisionNotes,
                    'granted_extra_attempts' => $grantedExtraAttempts,
                    'deadline_extension_days' => $deadlineExtensionDays,
                    'automation_dispatch' => $automationDispatch,
                    'automation_dispatch_error' => $automationDispatchError,
                ], 'success');

                $executeResults[] = [
                    'required_action_id' => $rowId,
                    'user_id' => (int)$row['user_id'],
                    'executed' => true,
                    'status' => 'success',
                    'message' => $automationDispatchError === null ? 'applied' : ('applied_with_notification_warning: ' . $automationDispatchError),
                ];
            } catch (Throwable $e) {
                tcc_log_bulk_event($pdo, $row, $currentUserId, $actionCode, $batchId, [
                    'decision_notes' => $decisionNotes,
                    'granted_extra_attempts' => $grantedExtraAttempts,
                    'deadline_extension_days' => $deadlineExtensionDays,
                ], 'failure');

                $executeResults[] = [
                    'required_action_id' => $rowId,
                    'user_id' => (int)$row['user_id'],
                    'executed' => false,
                    'status' => 'failed',
                    'message' => $e->getMessage(),
                ];
            }
        }

        $summary = ['success' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($executeResults as $r) {
            if ($r['status'] === 'success') $summary['success']++;
            elseif ($r['status'] === 'failed') $summary['failed']++;
            else $summary['skipped']++;
        }

        tcc_json([
            'ok' => true,
            'action' => 'bulk_action_execute',
            'batch_id' => $batchId,
            'bulk_action_code' => $actionCode,
            'summary' => $summary,
            'results' => $executeResults,
        ]);
    }

    if ($action === 'student_snapshot') {
        $cohortId = tcc_int($_GET['cohort_id'] ?? 0);
        $studentId = tcc_int($_GET['student_id'] ?? 0);

        if ($cohortId <= 0 || $studentId <= 0) {
            tcc_json(['ok' => false, 'error' => 'missing_cohort_or_student_id'], 400);
        }

        $st = $pdo->prepare("
            SELECT id, name, email, photo_path
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
        $st->execute([$studentId]);
        $student = $st->fetch(PDO::FETCH_ASSOC);

        if (!$student) {
            tcc_json(['ok' => false, 'error' => 'student_not_found'], 404);
        }

        $totalLessons = tcc_total_lessons($pdo, $cohortId);
        $passed = tcc_passed_lessons($pdo, $studentId, $cohortId);
        $progressPct = $totalLessons > 0 ? (int)round(($passed / $totalLessons) * 100) : 0;

        $pending = tcc_pending_actions($pdo, $cohortId, $studentId);
        $completedActions = tcc_completed_actions($pdo, $cohortId, $studentId);
        $stats = tcc_student_attempt_stats($pdo, $cohortId, $studentId);
        $deadlineMisses = tcc_deadline_missed_count($pdo, $cohortId, $studentId);
        $cohortAvg = tcc_cohort_metric_averages($pdo, $cohortId);
        $motivation = tcc_motivation_signal($stats, $deadlineMisses, count($pending));
        $systemIssues = tcc_system_watch($pdo, $cohortId, $studentId);

        $issues = [];

		
foreach ($pending as $p) {
            $actionType = (string)$p['action_type'];
            $token = (string)($p['token'] ?? '');

            $issues[] = [
                'type' => $actionType,
                'issue_type' => 'open_required_action_' . $actionType,
                'blocker_category' => 'policy',
                'repair_allowed' => false,
                'repair_code' => 'official_flow_only',
                'official_flow_url' => tcc_official_flow_url($actionType, $token),
                'status' => (string)$p['status'],
                'student_id' => $studentId,
                'cohort_id' => $cohortId,
                'lesson_id' => (int)$p['lesson_id'],
                'lesson_title' => (string)($p['lesson_title'] ?? ''),
                'title' => (string)($p['title'] ?? ''),
                'token' => $token,
                'evidence' => [
                    'required_action_id' => (int)$p['id'],
                    'action_type' => $actionType,
                    'status' => (string)$p['status'],
                    'created_at' => (string)($p['created_at'] ?? ''),
                    'opened_at' => (string)($p['opened_at'] ?? ''),
                    'has_token' => trim($token) !== '',
                ],
            ];
        }		
		

        foreach ($systemIssues as $si) {
            $issues[] = [
    'type' => (string)($si['type'] ?? $si['issue_type'] ?? 'system_watch'),
    'issue_type' => (string)($si['issue_type'] ?? $si['type'] ?? 'system_watch'),
    'blocker_category' => (string)($si['blocker_category'] ?? 'ambiguous'),
    'repair_allowed' => !empty($si['repair_allowed']),
    'repair_code' => (string)($si['repair_code'] ?? 'inspect_only'),
    'status' => 'system_watch',
    'student_id' => (int)($si['student_id'] ?? $studentId),
    'cohort_id' => (int)($si['cohort_id'] ?? $cohortId),
    'lesson_id' => (int)($si['lesson_id'] ?? 0),
    'lesson_title' => (string)($si['lesson_title'] ?? ''),
    'title' => (string)($si['summary'] ?? $si['title'] ?? 'System watch issue'),
    'summary' => (string)($si['summary'] ?? ''),
    'recurrence_key' => (string)($si['recurrence_key'] ?? ''),
    'evidence' => is_array($si['evidence'] ?? null) ? $si['evidence'] : [],
];
        }

        $name = tcc_student_name($student);

        tcc_json([
            'ok' => true,
            'action' => 'student_snapshot',
            'student' => [
                'student_id' => $studentId,
                'name' => $name,
                'email' => (string)($student['email'] ?? ''),
                'photo_path' => (string)($student['photo_path'] ?? ''),
                'avatar_initials' => tcc_avatar_initials($name),
            ],
            'cohort_id' => $cohortId,
            'progress' => [
                'passed_lessons' => $passed,
                'total_lessons' => $totalLessons,
                'progress_pct' => $progressPct,
            ],
            'comparison' => [
                'deadlines_missed' => $deadlineMisses,
                'cohort_avg_deadlines_missed' => $cohortAvg['avg_deadlines_missed'],
                'failed_attempts' => $stats['failed_attempts'],
                'cohort_avg_failed_attempts' => $cohortAvg['avg_failed_attempts'],
                'avg_score' => $stats['avg_score'],
                'cohort_avg_score' => $cohortAvg['avg_score'],
            ],
            'motivation' => $motivation,
            'main_issues' => $issues,
            'pending_action_count' => count($pending),
            'completed_intervention_count' => count($completedActions),
            'system_issue_count' => count($systemIssues),
        ]);
    }


    if ($action === 'ai_summary_analysis') {
        $cohortId = tcc_int($_GET['cohort_id'] ?? 0);
        $studentId = tcc_int($_GET['student_id'] ?? 0);
        $lessonId = tcc_int($_GET['lesson_id'] ?? 0);
    
        if ($cohortId <= 0 || $studentId <= 0 || $lessonId <= 0) {
            tcc_json([
                'ok' => false,
                'error' => 'missing_required_ids',
            ], 400);
        }
    
        $summarySt = $pdo->prepare("
            SELECT
                ls.summary_html,
                ls.summary_plain,
                ls.review_status,
                ls.review_score,
                ls.review_feedback,
                ls.review_notes_by_instructor,
                ls.updated_at,
                l.title AS lesson_title,
                c.title AS course_title
            FROM lesson_summaries ls
            JOIN lessons l ON l.id = ls.lesson_id
            JOIN courses c ON c.id = l.course_id
            WHERE ls.cohort_id = ?
              AND ls.user_id = ?
              AND ls.lesson_id = ?
            LIMIT 1
        ");
        $summarySt->execute([$cohortId, $studentId, $lessonId]);
        $summary = $summarySt->fetch(PDO::FETCH_ASSOC);
    
        if (!$summary || !is_array($summary)) {
            tcc_json([
                'ok' => false,
                'error' => 'summary_not_found',
                'message' => 'No lesson summary found for this student and lesson.',
            ], 404);
        }
    
        $studentSt = $pdo->prepare("
            SELECT
                id,
                email,
                COALESCE(NULLIF(TRIM(CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,''))), ''), email, CONCAT('User #', id)) AS student_name
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
        $studentSt->execute([$studentId]);
        $student = $studentSt->fetch(PDO::FETCH_ASSOC) ?: [];
    
        $summaryText = tcc_strip_summary_for_ai((string)($summary['summary_html'] ?? ''), (string)($summary['summary_plain'] ?? ''));
        $similarity = tcc_similarity_context_for_summary($pdo, $cohortId, $studentId, $lessonId, $summaryText);
    
        $payload = [
            'student' => [
                'student_id' => $studentId,
                'student_name' => (string)($student['student_name'] ?? ('Student #' . $studentId)),
            ],
            'cohort_id' => $cohortId,
            'lesson' => [
                'lesson_id' => $lessonId,
                'lesson_title' => (string)($summary['lesson_title'] ?? ''),
                'course_title' => (string)($summary['course_title'] ?? ''),
            ],
            'summary' => [
                'review_status' => (string)($summary['review_status'] ?? ''),
                'review_score' => $summary['review_score'] === null ? null : (int)$summary['review_score'],
                'word_count' => tcc_word_count_for_ai($summaryText),
                'text' => $summaryText,
            ],
            'cohort_similarity_context' => $similarity,
        ];
    
        $result = tcc_call_openai_summary_analysis($payload);
    
        if (empty($result['ok'])) {
            tcc_json([
                'ok' => false,
                'action' => 'ai_summary_analysis',
                'error' => $result['error'] ?? 'ai_analysis_failed',
                'message' => $result['message'] ?? 'AI summary analysis failed.',
                'advisory_only' => true,
            ], 500);
        }
    
        $analysis = is_array($result['analysis'] ?? null) ? $result['analysis'] : [];
    
        if (!isset($analysis['highest_similarity']) || $analysis['highest_similarity'] === 'Not evaluated') {
            $analysis['highest_similarity'] = $similarity['highest_similarity'];
            $analysis['highest_similarity_student'] = $similarity['highest_similarity_student'];
            $analysis['highest_similarity_pct'] = $similarity['highest_similarity_pct'];
        }
    
        tcc_json([
            'ok' => true,
            'action' => 'ai_summary_analysis',
            'generated_at_utc' => gmdate('Y-m-d H:i:s'),
            'advisory_only' => true,
            'student_id' => $studentId,
            'cohort_id' => $cohortId,
            'lesson_id' => $lessonId,
            'model' => (string)($result['model'] ?? 'gpt-5.1-mini'),
            'response_id' => (string)($result['response_id'] ?? ''),
            'analysis' => $analysis,
            'similarity_context' => $similarity,
            'agent_instructions' => [
                'purpose' => 'Instructor advisory insight only. Do not use as canonical progression truth.',
                'rules' => [
                    'AI analysis does not change lesson_activity.',
                    'AI analysis does not create or close required actions.',
                    'AI analysis is not proof of misconduct.',
                    'Use as instructor review support only.',
                ],
            ],
        ]);
    }
    
    
    if ($action === 'student_lessons') {
        $cohortId = tcc_int($_GET['cohort_id'] ?? 0);
        $studentId = tcc_int($_GET['student_id'] ?? 0);

        if ($cohortId <= 0 || $studentId <= 0) {
            tcc_json([
                'ok' => false,
                'error' => 'missing_cohort_or_student_id'
            ], 400);
        }

        $timeline = tcc_student_lesson_timeline($pdo, $cohortId, $studentId);

        tcc_json([
            'ok' => true,
            'action' => 'student_lessons',
            'cohort_id' => $cohortId,
            'student_id' => $studentId,
            'count' => count($timeline),
            'lessons' => $timeline
        ]);
    }


    if ($action === 'lesson_summary_detail') {
        $cohortId = tcc_int($_GET['cohort_id'] ?? 0);
        $studentId = tcc_int($_GET['student_id'] ?? 0);
        $lessonId = tcc_int($_GET['lesson_id'] ?? 0);

        if ($cohortId <= 0 || $studentId <= 0 || $lessonId <= 0) {
            tcc_json(['ok' => false, 'error' => 'missing_required_ids'], 400);
        }

        tcc_json([
            'ok' => true,
            'action' => 'lesson_summary_detail',
            'cohort_id' => $cohortId,
            'student_id' => $studentId,
            'lesson_id' => $lessonId,
            'data' => tcc_lesson_summary_detail($pdo, $cohortId, $studentId, $lessonId)
        ]);
    }

    if ($action === 'lesson_attempts_detail') {
        $cohortId = tcc_int($_GET['cohort_id'] ?? 0);
        $studentId = tcc_int($_GET['student_id'] ?? 0);
        $lessonId = tcc_int($_GET['lesson_id'] ?? 0);

        if ($cohortId <= 0 || $studentId <= 0 || $lessonId <= 0) {
            tcc_json(['ok' => false, 'error' => 'missing_required_ids'], 400);
        }

        tcc_json([
            'ok' => true,
            'action' => 'lesson_attempts_detail',
            'cohort_id' => $cohortId,
            'student_id' => $studentId,
            'lesson_id' => $lessonId,
            'data' => tcc_lesson_attempts_detail($pdo, $cohortId, $studentId, $lessonId)
        ]);
    }

    if ($action === 'lesson_interventions_detail') {
        $cohortId = tcc_int($_GET['cohort_id'] ?? 0);
        $studentId = tcc_int($_GET['student_id'] ?? 0);
        $lessonId = tcc_int($_GET['lesson_id'] ?? 0);

        if ($cohortId <= 0 || $studentId <= 0) {
            tcc_json(['ok' => false, 'error' => 'missing_required_ids'], 400);
        }

        tcc_json([
            'ok' => true,
            'action' => 'lesson_interventions_detail',
            'cohort_id' => $cohortId,
            'student_id' => $studentId,
            'lesson_id' => $lessonId,
            'data' => tcc_lesson_interventions_detail($pdo, $cohortId, $studentId, $lessonId)
        ]);
    }

    if ($action === 'system_watch') {
        $cohortId = tcc_int($_GET['cohort_id'] ?? 0);
        $studentId = tcc_int($_GET['student_id'] ?? 0);

        if ($cohortId <= 0) {
            tcc_json(['ok' => false, 'error' => 'missing_cohort_id'], 400);
        }

        $issues = tcc_system_watch($pdo, $cohortId, $studentId > 0 ? $studentId : null);

        tcc_json([
            'ok' => true,
            'action' => 'system_watch',
            'cohort_id' => $cohortId,
            'student_id' => $studentId ?: null,
            'count' => count($issues),
            'issues' => $issues,
        ]);
    }

    if ($action === 'debug_report') {
        $cohortId = tcc_int($_GET['cohort_id'] ?? 0);
        $studentId = tcc_int($_GET['student_id'] ?? 0);
        $lessonId = tcc_int($_GET['lesson_id'] ?? 0);
        $issueType = tcc_str($_GET['issue_type'] ?? '');

        if ($cohortId <= 0 || $studentId <= 0 || $lessonId <= 0) {
            tcc_json(['ok' => false, 'error' => 'missing_required_ids'], 400);
        }

        $pt = $pdo->prepare("
            SELECT id, attempt, status, formal_result_code, pass_gate_met, counts_as_unsat, score_pct, started_at, completed_at, updated_at
            FROM progress_tests_v2
            WHERE cohort_id = ?
              AND user_id = ?
              AND lesson_id = ?
            ORDER BY id DESC
            LIMIT 20
        ");
        $pt->execute([$cohortId, $studentId, $lessonId]);

        $la = $pdo->prepare("
            SELECT *
            FROM lesson_activity
            WHERE cohort_id = ?
              AND user_id = ?
              AND lesson_id = ?
            LIMIT 1
        ");
        $la->execute([$cohortId, $studentId, $lessonId]);

        $ra = $pdo->prepare("
            SELECT id, action_type, status, title, created_at, opened_at, completed_at, updated_at
            FROM student_required_actions
            WHERE cohort_id = ?
              AND user_id = ?
              AND lesson_id = ?
            ORDER BY id DESC
            LIMIT 20
        ");
        $ra->execute([$cohortId, $studentId, $lessonId]);

        $emails = [];
        try {
            $em = $pdo->prepare("
                SELECT id, email_type, subject, sent_status, created_at, sent_at
                FROM training_progression_emails
                WHERE cohort_id = ?
                  AND user_id = ?
                  AND lesson_id = ?
                ORDER BY id DESC
                LIMIT 20
            ");
            $em->execute([$cohortId, $studentId, $lessonId]);
            $emails = $em->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $emails = [];
        }

        tcc_json([
            'ok' => true,
            'action' => 'debug_report',
            'generated_at_utc' => gmdate('Y-m-d H:i:s'),
            'issue_type' => $issueType !== '' ? $issueType : 'manual_debug_report',
            'student_id' => $studentId,
            'cohort_id' => $cohortId,
            'lesson_id' => $lessonId,
            'canonical' => [
                'progress_tests_v2' => $pt->fetchAll(PDO::FETCH_ASSOC),
                'required_actions' => $ra->fetchAll(PDO::FETCH_ASSOC),
                'emails' => $emails,
            ],
            'projection' => [
                'lesson_activity' => $la->fetch(PDO::FETCH_ASSOC) ?: null,
            ],
            'agent_instructions' => [
                'purpose' => 'Use this report to diagnose SSOT drift or progression blockage without guessing.',
                'rules' => [
                    'Do not treat lesson_activity as canonical truth.',
                    'PASS in progress_tests_v2 is terminal.',
                    'Required actions must be checked against canonical completion state.',
                    'Any manual repair must be auditable.'
                ],
            ],
        ]);
    }

    tcc_json([
        'ok' => false,
        'error' => 'unknown_action',
        'action' => $action
    ], 400);

} catch (Throwable $e) {
    error_log('TCC_API_ERROR action=' . $action . ' msg=' . $e->getMessage());

    tcc_json([
        'ok' => false,
        'error' => 'server_error',
        'message' => $e->getMessage()
    ], 500);
}
