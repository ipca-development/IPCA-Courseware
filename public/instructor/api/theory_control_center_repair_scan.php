<?php
declare(strict_types=1);

/**
 * IPCA Theory Control Center - Read-Only Repair Scan
 *
 * Path:
 *   public/instructor/api/theory_control_center_repair_scan.php
 *
 * Contract:
 *   - READ ONLY.
 *   - No UPDATE / INSERT / DELETE.
 *   - No mutation through CoursewareProgressionV2.
 *   - No repair execution.
 *   - Classifies likely blocker categories so we can safely decide what one-click repairs are allowed later.
 */

require_once __DIR__ . '/../../../src/bootstrap.php';

cw_require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$u = cw_current_user($pdo);
$role = (string)($u['role'] ?? '');
$currentUserId = (int)($u['id'] ?? 0);

$allowedRoles = array('admin', 'supervisor', 'instructor', 'chief_instructor');

if (!in_array($role, $allowedRoles, true)) {
    http_response_code(403);
    echo json_encode(array(
        'ok' => false,
        'error' => 'forbidden',
        'message' => 'Instructor access required.'
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function scan_json($data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function scan_int($value): int
{
    return (int)($value ?? 0);
}

function scan_str($value): string
{
    return trim((string)($value ?? ''));
}

function scan_boolish($value): int
{
    return (int)($value ?? 0);
}

function scan_limit($value, int $default = 250): int
{
    $n = (int)($value ?? $default);
    if ($n < 1) {
        return $default;
    }
    if ($n > 1000) {
        return 1000;
    }
    return $n;
}

function scan_table_exists(PDO $pdo, string $tableName): bool
{
    try {
        $st = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = ?
        ");
        $st->execute(array($tableName));
        return ((int)$st->fetchColumn()) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function scan_column_exists(PDO $pdo, string $tableName, string $columnName): bool
{
    try {
        $st = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = ?
              AND column_name = ?
        ");
        $st->execute(array($tableName, $columnName));
        return ((int)$st->fetchColumn()) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function scan_student_name(array $row): string
{
    $name = trim((string)($row['student_name'] ?? $row['name'] ?? ''));
    if ($name !== '') {
        return $name;
    }

    $first = trim((string)($row['first_name'] ?? ''));
    $last = trim((string)($row['last_name'] ?? ''));
    $full = trim($first . ' ' . $last);

    if ($full !== '') {
        return $full;
    }

    $email = trim((string)($row['student_email'] ?? $row['email'] ?? ''));

    return $email !== '' ? $email : 'Student';
}

function scan_issue(
    string $category,
    string $issueType,
    string $severity,
    string $summary,
    array $row,
    bool $repairAllowed,
    string $repairCode,
    string $officialFlowUrl = '',
    array $evidence = array()
): array {
    $cohortId = (int)($row['cohort_id'] ?? 0);
    $studentId = (int)($row['student_id'] ?? $row['user_id'] ?? 0);
    $lessonId = (int)($row['lesson_id'] ?? 0);

    return array(
        'blocker_category' => $category,
        'issue_type' => $issueType,
        'severity' => $severity,
        'repair_allowed' => $repairAllowed,
        'repair_code' => $repairCode,
        'official_flow_url' => $officialFlowUrl,
        'recurrence_key' => $issueType . '|cohort:' . $cohortId . '|lesson:' . $lessonId,
        'student_id' => $studentId,
        'student_name' => scan_student_name($row),
        'student_email' => (string)($row['student_email'] ?? $row['email'] ?? ''),
        'cohort_id' => $cohortId,
        'lesson_id' => $lessonId,
        'lesson_title' => (string)($row['lesson_title'] ?? ''),
        'summary' => $summary,
        'evidence' => $evidence,
    );
}

function scan_token_url(string $actionType, string $token): string
{
    $token = trim($token);
    if ($token === '') {
        return '';
    }

    if ($actionType === 'instructor_approval') {
        return '/instructor/instructor_approval.php?token=' . rawurlencode($token);
    }

    if ($actionType === 'deadline_reason_submission') {
        return '/student/deadline_reason.php?token=' . rawurlencode($token);
    }

    if ($actionType === 'remediation_acknowledgement') {
        return '/student/remediation_action.php?token=' . rawurlencode($token);
    }

    return '/student/remediation_action.php?token=' . rawurlencode($token);
}

function scan_summarize_counts(array $issues): array
{
    $out = array(
        'total' => count($issues),
        'by_category' => array(),
        'by_issue_type' => array(),
        'by_repair_code' => array(),
        'repair_allowed_count' => 0,
    );

    foreach ($issues as $issue) {
        $cat = (string)($issue['blocker_category'] ?? 'unknown');
        $type = (string)($issue['issue_type'] ?? 'unknown');
        $repair = (string)($issue['repair_code'] ?? 'inspect_only');

        if (!isset($out['by_category'][$cat])) {
            $out['by_category'][$cat] = 0;
        }
        $out['by_category'][$cat]++;

        if (!isset($out['by_issue_type'][$type])) {
            $out['by_issue_type'][$type] = 0;
        }
        $out['by_issue_type'][$type]++;

        if (!isset($out['by_repair_code'][$repair])) {
            $out['by_repair_code'][$repair] = 0;
        }
        $out['by_repair_code'][$repair]++;

        if (!empty($issue['repair_allowed'])) {
            $out['repair_allowed_count']++;
        }
    }

    ksort($out['by_category']);
    ksort($out['by_issue_type']);
    ksort($out['by_repair_code']);

    return $out;
}

function scan_top_recurrences(array $issues, int $max = 25): array
{
    $map = array();

    foreach ($issues as $issue) {
        $key = (string)($issue['recurrence_key'] ?? '');
        if ($key === '') {
            continue;
        }

        if (!isset($map[$key])) {
            $map[$key] = array(
                'recurrence_key' => $key,
                'count' => 0,
                'issue_type' => (string)($issue['issue_type'] ?? ''),
                'blocker_category' => (string)($issue['blocker_category'] ?? ''),
                'lesson_id' => (int)($issue['lesson_id'] ?? 0),
                'lesson_title' => (string)($issue['lesson_title'] ?? ''),
                'sample_student_id' => (int)($issue['student_id'] ?? 0),
                'sample_student_name' => (string)($issue['student_name'] ?? ''),
            );
        }

        $map[$key]['count']++;
    }

    $rows = array_values($map);

    usort($rows, function ($a, $b) {
        if ((int)$a['count'] === (int)$b['count']) {
            return strcmp((string)$a['recurrence_key'], (string)$b['recurrence_key']);
        }

        return (int)$b['count'] <=> (int)$a['count'];
    });

    return array_slice($rows, 0, $max);
}

function scan_get_recent_security_events(PDO $pdo, int $cohortId, int $studentId, int $lessonId): array
{
    if (!scan_table_exists($pdo, 'lesson_summary_security_events')) {
        return array(
            'available' => false,
            'count' => 0,
            'recent' => array()
        );
    }

    try {
        $st = $pdo->prepare("
            SELECT *
            FROM lesson_summary_security_events
            WHERE cohort_id = ?
              AND user_id = ?
              AND lesson_id = ?
            ORDER BY id DESC
            LIMIT 10
        ");
        $st->execute(array($cohortId, $studentId, $lessonId));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return array(
            'available' => true,
            'count' => count($rows),
            'recent' => $rows
        );
    } catch (Throwable $e) {
        return array(
            'available' => true,
            'error' => $e->getMessage(),
            'count' => 0,
            'recent' => array()
        );
    }
}

function scan_get_summary_version_metrics(PDO $pdo, int $cohortId, int $studentId, int $lessonId): array
{
    if (!scan_table_exists($pdo, 'lesson_summary_versions')) {
        return array(
            'available' => false,
            'version_count' => 0
        );
    }

    try {
        $st = $pdo->prepare("
            SELECT
                COUNT(*) AS version_count,
                MIN(created_at) AS first_version_at,
                MAX(created_at) AS last_version_at
            FROM lesson_summary_versions
            WHERE cohort_id = ?
              AND user_id = ?
              AND lesson_id = ?
        ");
        $st->execute(array($cohortId, $studentId, $lessonId));
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: array();

        return array(
            'available' => true,
            'version_count' => (int)($row['version_count'] ?? 0),
            'first_version_at' => (string)($row['first_version_at'] ?? ''),
            'last_version_at' => (string)($row['last_version_at'] ?? '')
        );
    } catch (Throwable $e) {
        return array(
            'available' => true,
            'error' => $e->getMessage(),
            'version_count' => 0
        );
    }
}

function scan_collect_context_for_summary(PDO $pdo, int $cohortId, int $studentId, int $lessonId): array
{
    return array(
        'summary_versions' => scan_get_summary_version_metrics($pdo, $cohortId, $studentId, $lessonId),
        'summary_security_events' => scan_get_recent_security_events($pdo, $cohortId, $studentId, $lessonId),
    );
}

$cohortId = scan_int($_GET['cohort_id'] ?? 0);
$studentId = scan_int($_GET['student_id'] ?? 0);
$lessonId = scan_int($_GET['lesson_id'] ?? 0);
$limit = scan_limit($_GET['limit'] ?? 250);

$whereCohort = '';
$whereStudent = '';
$whereLesson = '';
$paramsBase = array();

if ($cohortId > 0) {
    $whereCohort = ' AND base.cohort_id = ? ';
    $paramsBase[] = $cohortId;
}

if ($studentId > 0) {
    $whereStudent = ' AND base.user_id = ? ';
    $paramsBase[] = $studentId;
}

if ($lessonId > 0) {
    $whereLesson = ' AND base.lesson_id = ? ';
    $paramsBase[] = $lessonId;
}

$issues = array();
$diagnostics = array(
    'tables' => array(
        'lesson_activity' => scan_table_exists($pdo, 'lesson_activity'),
        'progress_tests_v2' => scan_table_exists($pdo, 'progress_tests_v2'),
        'student_required_actions' => scan_table_exists($pdo, 'student_required_actions'),
        'lesson_summaries' => scan_table_exists($pdo, 'lesson_summaries'),
        'lesson_summary_versions' => scan_table_exists($pdo, 'lesson_summary_versions'),
        'lesson_summary_security_events' => scan_table_exists($pdo, 'lesson_summary_security_events'),
        'training_progression_events' => scan_table_exists($pdo, 'training_progression_events'),
        'training_progression_emails' => scan_table_exists($pdo, 'training_progression_emails'),
        'student_lesson_deadline_overrides' => scan_table_exists($pdo, 'student_lesson_deadline_overrides'),
        'cohort_lesson_deadlines' => scan_table_exists($pdo, 'cohort_lesson_deadlines'),
    ),
    'scan_errors' => array(),
);

try {
    /**
     * 1. Policy blockers: open required actions that must go through official flow.
     */
    $sql = "
        SELECT
            base.id AS required_action_id,
            base.user_id,
            base.user_id AS student_id,
            base.cohort_id,
            base.lesson_id,
            base.action_type,
            base.status,
            base.title,
            base.token,
            base.created_at,
            base.opened_at,
            u.name AS student_name,
            u.email AS student_email,
            l.title AS lesson_title
        FROM student_required_actions base
        JOIN users u ON u.id = base.user_id
        LEFT JOIN lessons l ON l.id = base.lesson_id
        WHERE base.status IN ('pending','opened')
          " . $whereCohort . "
          " . $whereStudent . "
          " . $whereLesson . "
        ORDER BY base.created_at ASC, base.id ASC
        LIMIT " . (int)$limit . "
    ";

    $st = $pdo->prepare($sql);
    $st->execute($paramsBase);

    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $actionType = (string)($row['action_type'] ?? '');
        $token = (string)($row['token'] ?? '');
        $officialUrl = scan_token_url($actionType, $token);

        $knownPolicyTypes = array(
            'instructor_approval',
            'deadline_reason_submission',
            'remediation_acknowledgement',
            'summary_revision_required',
            'instructor_review_required',
        );

        $category = in_array($actionType, $knownPolicyTypes, true) ? 'policy' : 'ambiguous';
        $severity = $actionType === 'instructor_approval' ? 'high' : ($category === 'policy' ? 'medium' : 'medium');

        $issues[] = scan_issue(
            $category,
            'open_required_action_' . ($actionType !== '' ? $actionType : 'unknown'),
            $severity,
            $category === 'policy'
                ? 'Open required action exists. This must be handled through the official workflow, not one-click repair.'
                : 'Open required action exists, but action type is not yet classified as safe policy flow or repairable bug.',
            $row,
            false,
            'official_flow_only',
            $officialUrl,
            array(
                'required_action_id' => (int)($row['required_action_id'] ?? 0),
                'action_type' => $actionType,
                'status' => (string)($row['status'] ?? ''),
                'created_at' => (string)($row['created_at'] ?? ''),
                'opened_at' => (string)($row['opened_at'] ?? ''),
                'has_token' => $token !== '',
            )
        );
    }
} catch (Throwable $e) {
    $diagnostics['scan_errors'][] = array(
        'scan' => 'open_required_actions',
        'error' => $e->getMessage()
    );
}

try {
    /**
     * 2. Stale projection: canonical PASS exists, but lesson_activity does not show completed/passed.
     */
    $params = array();

    $sqlCohort = '';
    $sqlStudent = '';
    $sqlLesson = '';

    if ($cohortId > 0) {
        $sqlCohort = ' AND la.cohort_id = ? ';
        $params[] = $cohortId;
    }

    if ($studentId > 0) {
        $sqlStudent = ' AND la.user_id = ? ';
        $params[] = $studentId;
    }

    if ($lessonId > 0) {
        $sqlLesson = ' AND la.lesson_id = ? ';
        $params[] = $lessonId;
    }

    $sql = "
        SELECT
            la.user_id,
            la.user_id AS student_id,
            la.cohort_id,
            la.lesson_id,
            la.completion_status,
            la.summary_status,
            la.test_pass_status,
            la.training_suspended,
            la.deadline_blocked,
            la.summary_blocked,
            la.effective_deadline_utc,
            la.completed_at,
            u.name AS student_name,
            u.email AS student_email,
            l.title AS lesson_title,
            MAX(pt.id) AS pass_test_id,
            MAX(pt.completed_at) AS pass_completed_at
        FROM lesson_activity la
        JOIN users u ON u.id = la.user_id
        LEFT JOIN lessons l ON l.id = la.lesson_id
        JOIN progress_tests_v2 pt
          ON pt.user_id = la.user_id
         AND pt.cohort_id = la.cohort_id
         AND pt.lesson_id = la.lesson_id
         AND pt.status = 'completed'
         AND pt.pass_gate_met = 1
        WHERE 1=1
          " . $sqlCohort . "
          " . $sqlStudent . "
          " . $sqlLesson . "
          AND (
              COALESCE(la.completion_status, '') <> 'completed'
              OR COALESCE(la.test_pass_status, '') <> 'passed'
              OR COALESCE(la.training_suspended, 0) <> 0
              OR COALESCE(la.deadline_blocked, 0) <> 0
              OR COALESCE(la.summary_blocked, 0) <> 0
          )
        GROUP BY
            la.user_id,
            la.cohort_id,
            la.lesson_id,
            la.completion_status,
            la.summary_status,
            la.test_pass_status,
            la.training_suspended,
            la.deadline_blocked,
            la.summary_blocked,
            la.effective_deadline_utc,
            la.completed_at,
            u.name,
            u.email,
            l.title
        ORDER BY la.cohort_id DESC, la.user_id ASC, la.lesson_id ASC
        LIMIT " . (int)$limit . "
    ";

    $st = $pdo->prepare($sql);
    $st->execute($params);

    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $issues[] = scan_issue(
            'stale_bug',
            'pass_exists_projection_not_completed',
            'high',
            'Canonical PASS exists in progress_tests_v2, but lesson_activity still shows incomplete, not passed, suspended, or blocked.',
            $row,
            true,
            'recompute_projection',
            '',
            array(
                'completion_status' => (string)($row['completion_status'] ?? ''),
                'summary_status' => (string)($row['summary_status'] ?? ''),
                'test_pass_status' => (string)($row['test_pass_status'] ?? ''),
                'training_suspended' => scan_boolish($row['training_suspended'] ?? 0),
                'deadline_blocked' => scan_boolish($row['deadline_blocked'] ?? 0),
                'summary_blocked' => scan_boolish($row['summary_blocked'] ?? 0),
                'pass_test_id' => (int)($row['pass_test_id'] ?? 0),
                'pass_completed_at' => (string)($row['pass_completed_at'] ?? ''),
            )
        );
    }
} catch (Throwable $e) {
    $diagnostics['scan_errors'][] = array(
        'scan' => 'pass_exists_projection_not_completed',
        'error' => $e->getMessage()
    );
}

try {
    /**
     * 3. Open required action exists even though canonical PASS exists.
     */
    $params = array();

    $sqlCohort = '';
    $sqlStudent = '';
    $sqlLesson = '';

    if ($cohortId > 0) {
        $sqlCohort = ' AND base.cohort_id = ? ';
        $params[] = $cohortId;
    }

    if ($studentId > 0) {
        $sqlStudent = ' AND base.user_id = ? ';
        $params[] = $studentId;
    }

    if ($lessonId > 0) {
        $sqlLesson = ' AND base.lesson_id = ? ';
        $params[] = $lessonId;
    }

    $sql = "
        SELECT
            base.id AS required_action_id,
            base.user_id,
            base.user_id AS student_id,
            base.cohort_id,
            base.lesson_id,
            base.action_type,
            base.status,
            base.title,
            base.token,
            base.created_at,
            u.name AS student_name,
            u.email AS student_email,
            l.title AS lesson_title,
            MAX(pt.id) AS pass_test_id,
            MAX(pt.completed_at) AS pass_completed_at
        FROM student_required_actions base
        JOIN users u ON u.id = base.user_id
        LEFT JOIN lessons l ON l.id = base.lesson_id
        JOIN progress_tests_v2 pt
          ON pt.user_id = base.user_id
         AND pt.cohort_id = base.cohort_id
         AND pt.lesson_id = base.lesson_id
         AND pt.status = 'completed'
         AND pt.pass_gate_met = 1
        WHERE base.status IN ('pending','opened')
          " . $sqlCohort . "
          " . $sqlStudent . "
          " . $sqlLesson . "
        GROUP BY
            base.id,
            base.user_id,
            base.cohort_id,
            base.lesson_id,
            base.action_type,
            base.status,
            base.title,
            base.token,
            base.created_at,
            u.name,
            u.email,
            l.title
        ORDER BY base.created_at ASC, base.id ASC
        LIMIT " . (int)$limit . "
    ";

    $st = $pdo->prepare($sql);
    $st->execute($params);

    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $issues[] = scan_issue(
            'stale_bug',
            'open_required_action_on_passed_lesson',
            'high',
            'Required action is still open even though canonical PASS exists for the same student/cohort/lesson.',
            $row,
            true,
            'close_action_after_pass',
            '',
            array(
                'required_action_id' => (int)($row['required_action_id'] ?? 0),
                'action_type' => (string)($row['action_type'] ?? ''),
                'status' => (string)($row['status'] ?? ''),
                'pass_test_id' => (int)($row['pass_test_id'] ?? 0),
                'pass_completed_at' => (string)($row['pass_completed_at'] ?? ''),
            )
        );
    }
} catch (Throwable $e) {
    $diagnostics['scan_errors'][] = array(
        'scan' => 'open_required_action_on_passed_lesson',
        'error' => $e->getMessage()
    );
}

try {
    /**
     * 4. Duplicate active attempts for same student/cohort/lesson.
     *    Safe repair classification depends on whether a completed PASS exists.
     */
    $params = array();

    $sqlCohort = '';
    $sqlStudent = '';
    $sqlLesson = '';

    if ($cohortId > 0) {
        $sqlCohort = ' AND pt.cohort_id = ? ';
        $params[] = $cohortId;
    }

    if ($studentId > 0) {
        $sqlStudent = ' AND pt.user_id = ? ';
        $params[] = $studentId;
    }

    if ($lessonId > 0) {
        $sqlLesson = ' AND pt.lesson_id = ? ';
        $params[] = $lessonId;
    }

    $sql = "
        SELECT
            pt.user_id,
            pt.user_id AS student_id,
            pt.cohort_id,
            pt.lesson_id,
            COUNT(*) AS active_count,
            GROUP_CONCAT(pt.id ORDER BY pt.id DESC) AS active_test_ids,
            MIN(pt.created_at) AS oldest_active_created_at,
            MAX(pt.created_at) AS newest_active_created_at,
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
        WHERE pt.status IN ('ready','in_progress','processing','preparing')
          " . $sqlCohort . "
          " . $sqlStudent . "
          " . $sqlLesson . "
        GROUP BY
            pt.user_id,
            pt.cohort_id,
            pt.lesson_id,
            u.name,
            u.email,
            l.title
        HAVING COUNT(*) > 1
        ORDER BY active_count DESC, newest_active_created_at DESC
        LIMIT " . (int)$limit . "
    ";

    $st = $pdo->prepare($sql);
    $st->execute($params);

    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $passCount = (int)($row['canonical_pass_count'] ?? 0);
        $category = $passCount > 0 ? 'stale_bug' : 'ambiguous';
        $repairAllowed = $passCount > 0;

        $issues[] = scan_issue(
            $category,
            'duplicate_active_progress_test_attempts',
            'high',
            $passCount > 0
                ? 'Multiple active attempts exist even though a canonical PASS already exists. This is likely stale test noise.'
                : 'Multiple active attempts exist and no canonical PASS exists. This requires inspection before any repair.',
            $row,
            $repairAllowed,
            $repairAllowed ? 'cleanup_duplicate_active_attempts_after_pass' : 'inspect_only',
            '',
            array(
                'active_count' => (int)($row['active_count'] ?? 0),
                'active_test_ids' => (string)($row['active_test_ids'] ?? ''),
                'oldest_active_created_at' => (string)($row['oldest_active_created_at'] ?? ''),
                'newest_active_created_at' => (string)($row['newest_active_created_at'] ?? ''),
                'canonical_pass_count' => $passCount,
            )
        );
    }
} catch (Throwable $e) {
    $diagnostics['scan_errors'][] = array(
        'scan' => 'duplicate_active_progress_test_attempts',
        'error' => $e->getMessage()
    );
}

try {
    /**
     * 5. Stale active attempts older than a threshold.
     *    Read-only classification only. Not automatically safe unless a PASS exists.
     */
    $staleHours = scan_int($_GET['stale_hours'] ?? 24);
    if ($staleHours <= 0) {
        $staleHours = 24;
    }
    if ($staleHours > 168) {
        $staleHours = 168;
    }

    $params = array($staleHours);

    $sqlCohort = '';
    $sqlStudent = '';
    $sqlLesson = '';

    if ($cohortId > 0) {
        $sqlCohort = ' AND pt.cohort_id = ? ';
        $params[] = $cohortId;
    }

    if ($studentId > 0) {
        $sqlStudent = ' AND pt.user_id = ? ';
        $params[] = $studentId;
    }

    if ($lessonId > 0) {
        $sqlLesson = ' AND pt.lesson_id = ? ';
        $params[] = $lessonId;
    }

    $sql = "
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
        WHERE pt.status IN ('ready','in_progress','processing','preparing')
          AND COALESCE(pt.updated_at, pt.started_at, pt.created_at) < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? HOUR)
          " . $sqlCohort . "
          " . $sqlStudent . "
          " . $sqlLesson . "
        ORDER BY COALESCE(pt.updated_at, pt.started_at, pt.created_at) ASC
        LIMIT " . (int)$limit . "
    ";

    $st = $pdo->prepare($sql);
    $st->execute($params);

    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $passCount = (int)($row['canonical_pass_count'] ?? 0);
        $category = $passCount > 0 ? 'stale_bug' : 'ambiguous';

        $issues[] = scan_issue(
            $category,
            'old_active_progress_test_attempt',
            $passCount > 0 ? 'medium' : 'high',
            $passCount > 0
                ? 'Old active progress test attempt exists after canonical PASS. Likely stale noise.'
                : 'Old active progress test attempt exists without canonical PASS. Could be interrupted test and needs inspection.',
            $row,
            $passCount > 0,
            $passCount > 0 ? 'cleanup_old_active_attempt_after_pass' : 'inspect_only',
            '',
            array(
                'test_id' => (int)($row['test_id'] ?? 0),
                'attempt' => (int)($row['attempt'] ?? 0),
                'status' => (string)($row['status'] ?? ''),
                'created_at' => (string)($row['created_at'] ?? ''),
                'started_at' => (string)($row['started_at'] ?? ''),
                'updated_at' => (string)($row['updated_at'] ?? ''),
                'canonical_pass_count' => $passCount,
                'stale_hours_threshold' => $staleHours,
            )
        );
    }
} catch (Throwable $e) {
    $diagnostics['scan_errors'][] = array(
        'scan' => 'old_active_progress_test_attempt',
        'error' => $e->getMessage()
    );
}

try {
    /**
     * 6. Summary accepted/reviewed but lesson_activity still summary-blocked.
     */
    $params = array();

    $sqlCohort = '';
    $sqlStudent = '';
    $sqlLesson = '';

    if ($cohortId > 0) {
        $sqlCohort = ' AND la.cohort_id = ? ';
        $params[] = $cohortId;
    }

    if ($studentId > 0) {
        $sqlStudent = ' AND la.user_id = ? ';
        $params[] = $studentId;
    }

    if ($lessonId > 0) {
        $sqlLesson = ' AND la.lesson_id = ? ';
        $params[] = $lessonId;
    }

    $sql = "
        SELECT
            la.user_id,
            la.user_id AS student_id,
            la.cohort_id,
            la.lesson_id,
            la.summary_status AS activity_summary_status,
            la.summary_blocked,
            ls.review_status,
            ls.review_score,
            ls.updated_at AS summary_updated_at,
            u.name AS student_name,
            u.email AS student_email,
            l.title AS lesson_title
        FROM lesson_activity la
        JOIN lesson_summaries ls
          ON ls.user_id = la.user_id
         AND ls.cohort_id = la.cohort_id
         AND ls.lesson_id = la.lesson_id
        JOIN users u ON u.id = la.user_id
        LEFT JOIN lessons l ON l.id = la.lesson_id
        WHERE 1=1
          " . $sqlCohort . "
          " . $sqlStudent . "
          " . $sqlLesson . "
          AND COALESCE(ls.review_status, '') IN ('acceptable','approved','accepted')
          AND (
              COALESCE(la.summary_blocked, 0) <> 0
              OR COALESCE(la.summary_status, '') NOT IN ('accepted','acceptable','approved')
          )
        ORDER BY ls.updated_at DESC
        LIMIT " . (int)$limit . "
    ";

    $st = $pdo->prepare($sql);
    $st->execute($params);

    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $context = scan_collect_context_for_summary(
            $pdo,
            (int)$row['cohort_id'],
            (int)$row['student_id'],
            (int)$row['lesson_id']
        );

        $issues[] = scan_issue(
            'stale_bug',
            'accepted_summary_projection_blocked',
            'medium',
            'Lesson summary appears accepted/reviewed, but lesson_activity still shows blocked or non-accepted summary projection.',
            $row,
            true,
            'recompute_projection',
            '',
            array(
                'activity_summary_status' => (string)($row['activity_summary_status'] ?? ''),
                'summary_blocked' => scan_boolish($row['summary_blocked'] ?? 0),
                'review_status' => (string)($row['review_status'] ?? ''),
                'review_score' => $row['review_score'] === null ? null : (int)$row['review_score'],
                'summary_updated_at' => (string)($row['summary_updated_at'] ?? ''),
                'context' => $context,
            )
        );
    }
} catch (Throwable $e) {
    $diagnostics['scan_errors'][] = array(
        'scan' => 'accepted_summary_projection_blocked',
        'error' => $e->getMessage()
    );
}

try {
    /**
     * 7. Deadline override exists but lesson_activity effective deadline appears not updated.
     *    Ambiguous until we know exact engine rules, so inspect only.
     */
    if (scan_table_exists($pdo, 'student_lesson_deadline_overrides')) {
        $params = array();

        $sqlCohort = '';
        $sqlStudent = '';
        $sqlLesson = '';

        if ($cohortId > 0) {
            $sqlCohort = ' AND o.cohort_id = ? ';
            $params[] = $cohortId;
        }

        if ($studentId > 0) {
            $sqlStudent = ' AND o.user_id = ? ';
            $params[] = $studentId;
        }

        if ($lessonId > 0) {
            $sqlLesson = ' AND o.lesson_id = ? ';
            $params[] = $lessonId;
        }

        $deadlineColumn = scan_column_exists($pdo, 'student_lesson_deadline_overrides', 'deadline_utc')
            ? 'o.deadline_utc'
            : (scan_column_exists($pdo, 'student_lesson_deadline_overrides', 'new_deadline_utc') ? 'o.new_deadline_utc' : "NULL");

        $sql = "
            SELECT
                o.id AS override_id,
                o.user_id,
                o.user_id AS student_id,
                o.cohort_id,
                o.lesson_id,
                o.is_active,
                " . $deadlineColumn . " AS override_deadline_utc,
                o.created_at,
                la.effective_deadline_utc,
                la.deadline_blocked,
                u.name AS student_name,
                u.email AS student_email,
                l.title AS lesson_title
            FROM student_lesson_deadline_overrides o
            JOIN users u ON u.id = o.user_id
            LEFT JOIN lessons l ON l.id = o.lesson_id
            LEFT JOIN lesson_activity la
              ON la.user_id = o.user_id
             AND la.cohort_id = o.cohort_id
             AND la.lesson_id = o.lesson_id
            WHERE COALESCE(o.is_active, 0) = 1
              " . $sqlCohort . "
              " . $sqlStudent . "
              " . $sqlLesson . "
              AND " . $deadlineColumn . " IS NOT NULL
              AND (
                  la.effective_deadline_utc IS NULL
                  OR la.effective_deadline_utc <> " . $deadlineColumn . "
              )
            ORDER BY o.created_at DESC, o.id DESC
            LIMIT " . (int)$limit . "
        ";

        $st = $pdo->prepare($sql);
        $st->execute($params);

        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $issues[] = scan_issue(
                'ambiguous',
                'active_deadline_override_projection_mismatch',
                'medium',
                'Active deadline override exists, but lesson_activity effective deadline does not match. This may be projection drift or valid engine behavior; inspect first.',
                $row,
                false,
                'inspect_only',
                '',
                array(
                    'override_id' => (int)($row['override_id'] ?? 0),
                    'override_deadline_utc' => (string)($row['override_deadline_utc'] ?? ''),
                    'effective_deadline_utc' => (string)($row['effective_deadline_utc'] ?? ''),
                    'deadline_blocked' => scan_boolish($row['deadline_blocked'] ?? 0),
                )
            );
        }
    }
} catch (Throwable $e) {
    $diagnostics['scan_errors'][] = array(
        'scan' => 'active_deadline_override_projection_mismatch',
        'error' => $e->getMessage()
    );
}

try {
    /**
     * 8. Summary data quality context: security/version activity that may be important later.
     *    These are not blockers and not repairs, but useful context for next AI analysis layer.
     */
    if (scan_table_exists($pdo, 'lesson_summaries')) {
        $params = array();

        $sqlCohort = '';
        $sqlStudent = '';
        $sqlLesson = '';

        if ($cohortId > 0) {
            $sqlCohort = ' AND ls.cohort_id = ? ';
            $params[] = $cohortId;
        }

        if ($studentId > 0) {
            $sqlStudent = ' AND ls.user_id = ? ';
            $params[] = $studentId;
        }

        if ($lessonId > 0) {
            $sqlLesson = ' AND ls.lesson_id = ? ';
            $params[] = $lessonId;
        }

        $sql = "
            SELECT
                ls.user_id,
                ls.user_id AS student_id,
                ls.cohort_id,
                ls.lesson_id,
                ls.review_status,
                ls.review_score,
                ls.created_at,
                ls.updated_at,
                u.name AS student_name,
                u.email AS student_email,
                l.title AS lesson_title
            FROM lesson_summaries ls
            JOIN users u ON u.id = ls.user_id
            LEFT JOIN lessons l ON l.id = ls.lesson_id
            WHERE 1=1
              " . $sqlCohort . "
              " . $sqlStudent . "
              " . $sqlLesson . "
            ORDER BY ls.updated_at DESC
            LIMIT " . (int)min($limit, 100) . "
        ";

        $st = $pdo->prepare($sql);
        $st->execute($params);

        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $context = scan_collect_context_for_summary(
                $pdo,
                (int)$row['cohort_id'],
                (int)$row['student_id'],
                (int)$row['lesson_id']
            );

            $versionCount = (int)($context['summary_versions']['version_count'] ?? 0);
            $securityCount = (int)($context['summary_security_events']['count'] ?? 0);

            if ($versionCount >= 5 || $securityCount > 0) {
                $issues[] = scan_issue(
                    'context_only',
                    'summary_edit_or_security_activity_detected',
                    $securityCount > 0 ? 'medium' : 'low',
                    'Summary has notable version/security activity. This is not a blocker and not repairable, but useful for AI analysis and future pattern detection.',
                    $row,
                    false,
                    'no_repair_context_only',
                    '',
                    array(
                        'review_status' => (string)($row['review_status'] ?? ''),
                        'review_score' => $row['review_score'] === null ? null : (int)$row['review_score'],
                        'summary_created_at' => (string)($row['created_at'] ?? ''),
                        'summary_updated_at' => (string)($row['updated_at'] ?? ''),
                        'context' => $context,
                    )
                );
            }
        }
    }
} catch (Throwable $e) {
    $diagnostics['scan_errors'][] = array(
        'scan' => 'summary_edit_or_security_activity_detected',
        'error' => $e->getMessage()
    );
}

$policyBlockers = array();
$staleBugBlockers = array();
$ambiguousBlockers = array();
$contextOnly = array();

foreach ($issues as $issue) {
    $category = (string)($issue['blocker_category'] ?? '');

    if ($category === 'policy') {
        $policyBlockers[] = $issue;
    } elseif ($category === 'stale_bug') {
        $staleBugBlockers[] = $issue;
    } elseif ($category === 'context_only') {
        $contextOnly[] = $issue;
    } else {
        $ambiguousBlockers[] = $issue;
    }
}

scan_json(array(
    'ok' => true,
    'read_only' => true,
    'script' => 'theory_control_center_repair_scan',
    'generated_at_utc' => gmdate('Y-m-d H:i:s'),
    'requested_by_user_id' => $currentUserId,
    'filters' => array(
        'cohort_id' => $cohortId > 0 ? $cohortId : null,
        'student_id' => $studentId > 0 ? $studentId : null,
        'lesson_id' => $lessonId > 0 ? $lessonId : null,
        'limit_per_scan' => $limit,
        'stale_hours' => scan_int($_GET['stale_hours'] ?? 24) > 0 ? scan_int($_GET['stale_hours'] ?? 24) : 24,
    ),
    'summary' => scan_summarize_counts($issues),
    'recurring_issue_counts' => scan_top_recurrences($issues),
    'policy_blockers' => array(
        'count' => count($policyBlockers),
        'items' => $policyBlockers,
    ),
    'stale_bug_blockers' => array(
        'count' => count($staleBugBlockers),
        'items' => $staleBugBlockers,
    ),
    'ambiguous_blockers' => array(
        'count' => count($ambiguousBlockers),
        'items' => $ambiguousBlockers,
    ),
    'context_only_signals' => array(
        'count' => count($contextOnly),
        'items' => $contextOnly,
    ),
    'diagnostics' => $diagnostics,
    'agent_instructions' => array(
        'purpose' => 'Use this read-only scan to decide which blockers can safely receive a one-click repair.',
        'rules' => array(
            'Policy blockers must be resolved only through official workflow URLs.',
            'stale_bug blockers may become eligible for one-click repair after exact repair code implementation.',
            'ambiguous blockers must remain inspect-only until proven safe.',
            'context_only signals are not blockers and must not be repaired.',
            'This script intentionally performs no writes.'
        )
    )
));
