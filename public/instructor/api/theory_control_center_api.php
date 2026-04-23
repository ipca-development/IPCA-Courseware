<?php
/**
 * IPCA Instructor Theory Control Center API - Phase 1
 *
 * Scope:
 * - Read-only API foundation for Instructor Theory Control Center
 * - No override/write actions in this phase
 * - SSOT-safe: reads canonical tables and projection for display/diagnostics only
 *
 * Supported actions:
 * - cohort_overview
 * - action_queue
 * - student_snapshot
 * - system_watch
 * - debug_report
 */

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
    echo json_encode(array(
        'ok' => false,
        'error' => 'forbidden',
        'message' => 'Instructor access required.'
    ));
    exit;
}

$engine = new CoursewareProgressionV2($pdo);

function tcc_json($data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function tcc_int($value): int
{
    return (int)($value ?? 0);
}

function tcc_str($value): string
{
    return trim((string)($value ?? ''));
}

function tcc_student_name(array $row): string
{
    $name = trim((string)($row['name'] ?? ''));
    if ($name !== '') return $name;

    $first = trim((string)($row['first_name'] ?? ''));
    $last = trim((string)($row['last_name'] ?? ''));
    $full = trim($first . ' ' . $last);
    if ($full !== '') return $full;

    return trim((string)($row['email'] ?? 'Student')) ?: 'Student';
}

function tcc_avatar_initials(string $name): string
{
    $name = trim($name);
    if ($name === '') return 'S';

    $parts = preg_split('/\s+/', $name);
    if (!$parts) return strtoupper(substr($name, 0, 1));

    $a = strtoupper(substr((string)$parts[0], 0, 1));
    $b = isset($parts[1]) ? strtoupper(substr((string)$parts[1], 0, 1)) : '';

    return $a . $b;
}

function tcc_fetch_cohorts(PDO $pdo): array
{
    $st = $pdo->query("\n        SELECT\n            co.id,\n            co.name,\n            co.start_date,\n            co.end_date,\n            c.title AS course_title,\n            p.program_key\n        FROM cohorts co\n        JOIN courses c ON c.id = co.course_id\n        JOIN programs p ON p.id = c.program_id\n        ORDER BY co.start_date DESC, co.id DESC\n    ");

    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function tcc_cohort_students(PDO $pdo, int $cohortId): array
{
    $st = $pdo->prepare("\n        SELECT\n            u.id,\n            u.name,\n            u.email\n        FROM cohort_students cs\n        JOIN users u ON u.id = cs.user_id\n        WHERE cs.cohort_id = ?\n        ORDER BY u.name ASC, u.email ASC\n    ");
    $st->execute(array($cohortId));

    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function tcc_total_lessons(PDO $pdo, int $cohortId): int
{
    $st = $pdo->prepare("\n        SELECT COUNT(DISTINCT d.lesson_id)\n        FROM cohort_lesson_deadlines d\n        WHERE d.cohort_id = ?\n    ");
    $st->execute(array($cohortId));

    return (int)$st->fetchColumn();
}

function tcc_pending_actions(PDO $pdo, int $cohortId, ?int $userId = null): array
{
    $params = array($cohortId);
    $userSql = '';

    if ($userId !== null && $userId > 0) {
        $userSql = ' AND sra.user_id = ? ';
        $params[] = $userId;
    }

    $st = $pdo->prepare("\n        SELECT\n            sra.*,\n            u.name AS student_name,\n            u.email AS student_email,\n            l.title AS lesson_title\n        FROM student_required_actions sra\n        JOIN users u ON u.id = sra.user_id\n        LEFT JOIN lessons l ON l.id = sra.lesson_id\n        WHERE sra.cohort_id = ?\n          {$userSql}\n          AND sra.status IN ('pending','opened')\n        ORDER BY sra.created_at ASC, sra.id ASC\n    ");
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function tcc_completed_actions(PDO $pdo, int $cohortId, int $userId): array
{
    $st = $pdo->prepare("\n        SELECT\n            sra.*,\n            l.title AS lesson_title\n        FROM student_required_actions sra\n        LEFT JOIN lessons l ON l.id = sra.lesson_id\n        WHERE sra.cohort_id = ?\n          AND sra.user_id = ?\n          AND sra.status IN ('completed','approved')\n        ORDER BY COALESCE(sra.updated_at, sra.completed_at, sra.created_at) DESC, sra.id DESC\n        LIMIT 50\n    ");
    $st->execute(array($cohortId, $userId));

    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function tcc_action_severity(string $actionType): string
{
    if ($actionType === 'instructor_approval') return 'high';
    if ($actionType === 'deadline_reason_submission') return 'medium';
    if ($actionType === 'remediation_acknowledgement') return 'medium';
    return 'low';
}

function tcc_recommended_action(string $actionType): string
{
    if ($actionType === 'instructor_approval') return 'review_instructor_approval';
    if ($actionType === 'deadline_reason_submission') return 'review_deadline_reason';
    if ($actionType === 'remediation_acknowledgement') return 'monitor_remediation_completion';
    return 'review_required_action';
}

function tcc_safe_actions(string $actionType): array
{
    if ($actionType === 'instructor_approval') {
        return array('review', 'grant_attempts', 'require_one_on_one', 'suspend_training');
    }

    if ($actionType === 'deadline_reason_submission') {
        return array('review', 'extend_deadline', 'request_more_info');
    }

    if ($actionType === 'remediation_acknowledgement') {
        return array('review', 'acknowledge_completion');
    }

    return array('review');
}

function tcc_batch_progress_stats(PDO $pdo, int $cohortId): array
{
    $stats = array();

    $st = $pdo->prepare("\n        SELECT\n            pt.user_id,\n            COUNT(*) AS total_attempts,\n            SUM(CASE WHEN pt.status='completed' AND COALESCE(pt.pass_gate_met,0)=0 THEN 1 ELSE 0 END) AS failed_attempts,\n            AVG(CASE WHEN pt.status='completed' AND pt.score_pct IS NOT NULL THEN pt.score_pct ELSE NULL END) AS avg_score,\n            MAX(pt.completed_at) AS last_completed_at\n        FROM progress_tests_v2 pt\n        WHERE pt.cohort_id = ?\n          AND NOT (\n              COALESCE(pt.formal_result_code, '') = 'STALE_ABORTED'\n              AND COALESCE(pt.counts_as_unsat, 0) = 0\n              AND COALESCE(pt.pass_gate_met, 0) = 0\n          )\n        GROUP BY pt.user_id\n    ");
    $st->execute(array($cohortId));

    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $uid = (int)$row['user_id'];
        $stats[$uid] = array(
            'total_attempts' => (int)($row['total_attempts'] ?? 0),
            'failed_attempts' => (int)($row['failed_attempts'] ?? 0),
            'avg_score' => $row['avg_score'] === null ? null : round((float)$row['avg_score'], 1),
            'last_completed_at' => (string)($row['last_completed_at'] ?? ''),
        );
    }

    return $stats;
}

function tcc_batch_passed_lessons(PDO $pdo, int $cohortId): array
{
    $map = array();

    $st = $pdo->prepare("\n        SELECT\n            pt.user_id,\n            COUNT(DISTINCT pt.lesson_id) AS passed_lessons\n        FROM progress_tests_v2 pt\n        WHERE pt.cohort_id = ?\n          AND pt.status = 'completed'\n          AND pt.pass_gate_met = 1\n        GROUP BY pt.user_id\n    ");
    $st->execute(array($cohortId));

    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[(int)$row['user_id']] = (int)$row['passed_lessons'];
    }

    return $map;
}

function tcc_batch_deadline_misses(PDO $pdo, int $cohortId): array
{
    $map = array();

    $st = $pdo->prepare("\n        SELECT\n            sra.user_id,\n            COUNT(*) AS miss_count\n        FROM student_required_actions sra\n        WHERE sra.cohort_id = ?\n          AND sra.action_type = 'deadline_reason_submission'\n        GROUP BY sra.user_id\n    ");
    $st->execute(array($cohortId));

    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[(int)$row['user_id']] = (int)$row['miss_count'];
    }

    return $map;
}

function tcc_batch_pending_action_counts(PDO $pdo, int $cohortId): array
{
    $map = array();

    $st = $pdo->prepare("\n        SELECT\n            sra.user_id,\n            COUNT(*) AS pending_count\n        FROM student_required_actions sra\n        WHERE sra.cohort_id = ?\n          AND sra.status IN ('pending','opened')\n        GROUP BY sra.user_id\n    ");
    $st->execute(array($cohortId));

    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[(int)$row['user_id']] = (int)$row['pending_count'];
    }

    return $map;
}

function tcc_default_attempt_stats(): array
{
    return array(
        'total_attempts' => 0,
        'failed_attempts' => 0,
        'avg_score' => null,
        'last_completed_at' => '',
    );
}

function tcc_student_attempt_stats(PDO $pdo, int $cohortId, int $userId): array
{
    $st = $pdo->prepare("\n        SELECT\n            COUNT(*) AS total_attempts,\n            SUM(CASE WHEN status='completed' AND COALESCE(pass_gate_met,0)=0 THEN 1 ELSE 0 END) AS failed_attempts,\n            AVG(CASE WHEN status='completed' AND score_pct IS NOT NULL THEN score_pct ELSE NULL END) AS avg_score,\n            MAX(completed_at) AS last_completed_at\n        FROM progress_tests_v2\n        WHERE cohort_id = ?\n          AND user_id = ?\n          AND NOT (\n              COALESCE(formal_result_code, '') = 'STALE_ABORTED'\n              AND COALESCE(counts_as_unsat, 0) = 0\n              AND COALESCE(pass_gate_met, 0) = 0\n          )\n    ");
    $st->execute(array($cohortId, $userId));
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: array();

    return array(
        'total_attempts' => (int)($row['total_attempts'] ?? 0),
        'failed_attempts' => (int)($row['failed_attempts'] ?? 0),
        'avg_score' => $row['avg_score'] === null ? null : round((float)$row['avg_score'], 1),
        'last_completed_at' => (string)($row['last_completed_at'] ?? ''),
    );
}

function tcc_deadline_missed_count(PDO $pdo, int $cohortId, int $userId): int
{
    $st = $pdo->prepare("\n        SELECT COUNT(*)\n        FROM student_required_actions\n        WHERE cohort_id = ?\n          AND user_id = ?\n          AND action_type = 'deadline_reason_submission'\n    ");
    $st->execute(array($cohortId, $userId));

    return (int)$st->fetchColumn();
}

function tcc_passed_lessons(PDO $pdo, int $userId, int $cohortId): int
{
    $st = $pdo->prepare("\n        SELECT COUNT(DISTINCT pt.lesson_id)\n        FROM progress_tests_v2 pt\n        WHERE pt.user_id = ?\n          AND pt.cohort_id = ?\n          AND pt.status = 'completed'\n          AND pt.pass_gate_met = 1\n    ");
    $st->execute(array($userId, $cohortId));

    return (int)$st->fetchColumn();
}

function tcc_motivation_signal(array $stats, int $deadlineMisses, int $pendingActions): array
{
    $score = 100;

    $score -= min(40, $deadlineMisses * 10);
    $score -= min(35, ((int)$stats['failed_attempts']) * 7);
    $score -= min(25, $pendingActions * 10);

    if ($stats['avg_score'] !== null && (float)$stats['avg_score'] < 70) {
        $score -= 10;
    }

    $score = max(0, min(100, $score));

    if ($score >= 80) {
        return array('level' => 'strong', 'label' => 'Strong engagement', 'trend' => 'stable', 'score' => $score, 'is_provisional' => true);
    }

    if ($score >= 60) {
        return array('level' => 'stable', 'label' => 'Stable', 'trend' => 'flat', 'score' => $score, 'is_provisional' => true);
    }

    if ($score >= 40) {
        return array('level' => 'drifting', 'label' => 'Drifting', 'trend' => 'down', 'score' => $score, 'is_provisional' => true);
    }

    return array('level' => 'needs_contact', 'label' => 'Needs instructor contact', 'trend' => 'down', 'score' => $score, 'is_provisional' => true);
}

function tcc_cohort_metric_averages_from_maps(array $students, array $deadlineMap, array $attemptStatsMap): array
{
    if (!$students) {
        return array(
            'avg_deadlines_missed' => 0,
            'avg_failed_attempts' => 0,
            'avg_score' => null,
        );
    }

    $deadlineCounts = array();
    $failedCounts = array();
    $scores = array();

    foreach ($students as $s) {
        $sid = (int)$s['id'];
        $deadlineCounts[] = (int)($deadlineMap[$sid] ?? 0);

        $stats = $attemptStatsMap[$sid] ?? tcc_default_attempt_stats();
        $failedCounts[] = (int)$stats['failed_attempts'];

        if ($stats['avg_score'] !== null) {
            $scores[] = (float)$stats['avg_score'];
        }
    }

    return array(
        'avg_deadlines_missed' => round(array_sum($deadlineCounts) / max(1, count($deadlineCounts)), 1),
        'avg_failed_attempts' => round(array_sum($failedCounts) / max(1, count($failedCounts)), 1),
        'avg_score' => $scores ? round(array_sum($scores) / count($scores), 1) : null,
    );
}

function tcc_system_watch(PDO $pdo, int $cohortId, ?int $userId = null): array
{
    $issues = array();

    $params = array($cohortId);
    $userSql = '';
    if ($userId !== null && $userId > 0) {
        $userSql = ' AND la.user_id = ? ';
        $params[] = $userId;
    }

    $st = $pdo->prepare("\n        SELECT\n            la.user_id,\n            la.cohort_id,\n            la.lesson_id,\n            la.completion_status,\n            la.test_pass_status,\n            u.name AS student_name,\n            u.email AS student_email,\n            l.title AS lesson_title\n        FROM lesson_activity la\n        JOIN users u ON u.id = la.user_id\n        LEFT JOIN lessons l ON l.id = la.lesson_id\n        WHERE la.cohort_id = ?\n          {$userSql}\n          AND EXISTS (\n              SELECT 1\n              FROM progress_tests_v2 pt\n              WHERE pt.user_id = la.user_id\n                AND pt.cohort_id = la.cohort_id\n                AND pt.lesson_id = la.lesson_id\n                AND pt.status = 'completed'\n                AND pt.pass_gate_met = 1\n          )\n          AND (\n              COALESCE(la.completion_status, '') <> 'completed'\n              OR COALESCE(la.test_pass_status, '') <> 'passed'\n          )\n        LIMIT 100\n    ");
    $st->execute($params);

    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $studentName = tcc_student_name(array('name' => $row['student_name'] ?? '', 'email' => $row['student_email'] ?? ''));
        $issues[] = array(
            'issue_type' => 'pass_exists_projection_not_completed',
            'severity' => 'high',
            'student_id' => (int)$row['user_id'],
            'cohort_id' => (int)$row['cohort_id'],
            'lesson_id' => (int)$row['lesson_id'],
            'student_name' => $studentName,
            'lesson_title' => (string)($row['lesson_title'] ?? ''),
            'summary' => 'Canonical PASS exists, but lesson_activity does not show completed/passed.',
            'recommended_safe_action' => 'recompute_projection',
        );
    }

    $params = array($cohortId);
    $userSql = '';
    if ($userId !== null && $userId > 0) {
        $userSql = ' AND pt.user_id = ? ';
        $params[] = $userId;
    }

    $st = $pdo->prepare("\n        SELECT\n            pt.user_id,\n            pt.cohort_id,\n            pt.lesson_id,\n            COUNT(*) AS active_count,\n            u.name AS student_name,\n            u.email AS student_email,\n            l.title AS lesson_title\n        FROM progress_tests_v2 pt\n        JOIN users u ON u.id = pt.user_id\n        LEFT JOIN lessons l ON l.id = pt.lesson_id\n        WHERE pt.cohort_id = ?\n          {$userSql}\n          AND pt.status IN ('ready','in_progress','processing','preparing')\n        GROUP BY pt.user_id, pt.cohort_id, pt.lesson_id, u.name, u.email, l.title\n        HAVING COUNT(*) > 1\n        LIMIT 100\n    ");
    $st->execute($params);

    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $studentName = tcc_student_name(array('name' => $row['student_name'] ?? '', 'email' => $row['student_email'] ?? ''));
        $issues[] = array(
            'issue_type' => 'duplicate_active_attempts',
            'severity' => 'high',
            'student_id' => (int)$row['user_id'],
            'cohort_id' => (int)$row['cohort_id'],
            'lesson_id' => (int)$row['lesson_id'],
            'student_name' => $studentName,
            'lesson_title' => (string)($row['lesson_title'] ?? ''),
            'active_count' => (int)$row['active_count'],
            'summary' => 'More than one active progress test attempt exists for this lesson.',
            'recommended_safe_action' => 'inspect_attempts_and_cleanup_stale',
        );
    }

    $params = array($cohortId);
    $userSql = '';
    if ($userId !== null && $userId > 0) {
        $userSql = ' AND sra.user_id = ? ';
        $params[] = $userId;
    }

    $st = $pdo->prepare("\n        SELECT\n            sra.user_id,\n            sra.cohort_id,\n            sra.lesson_id,\n            sra.action_type,\n            sra.status,\n            u.name AS student_name,\n            u.email AS student_email,\n            l.title AS lesson_title\n        FROM student_required_actions sra\n        JOIN users u ON u.id = sra.user_id\n        LEFT JOIN lessons l ON l.id = sra.lesson_id\n        WHERE sra.cohort_id = ?\n          {$userSql}\n          AND sra.status IN ('pending','opened')\n          AND EXISTS (\n              SELECT 1\n              FROM progress_tests_v2 pt\n              WHERE pt.user_id = sra.user_id\n                AND pt.cohort_id = sra.cohort_id\n                AND pt.lesson_id = sra.lesson_id\n                AND pt.status = 'completed'\n                AND pt.pass_gate_met = 1\n          )\n        LIMIT 100\n    ");
    $st->execute($params);

    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $studentName = tcc_student_name(array('name' => $row['student_name'] ?? '', 'email' => $row['student_email'] ?? ''));
        $issues[] = array(
            'issue_type' => 'pending_action_on_passed_lesson',
            'severity' => 'high',
            'student_id' => (int)$row['user_id'],
            'cohort_id' => (int)$row['cohort_id'],
            'lesson_id' => (int)$row['lesson_id'],
            'student_name' => $studentName,
            'lesson_title' => (string)($row['lesson_title'] ?? ''),
            'action_type' => (string)$row['action_type'],
            'summary' => 'A pending/open required action exists even though the lesson has a canonical PASS.',
            'recommended_safe_action' => 'inspect_required_action_and_recompute',
        );
    }

    return $issues;
}

function tcc_table_has_columns(PDO $pdo, string $tableName, array $columns): array
{
    $found = array();
    foreach ($columns as $c) {
        $found[$c] = false;
    }

    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `" . str_replace('`', '', $tableName) . "`");
        $st->execute();
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $field = (string)($row['Field'] ?? '');
            if (array_key_exists($field, $found)) {
                $found[$field] = true;
            }
        }
    } catch (Throwable $e) {
        return $found;
    }

    return $found;
}

$action = tcc_str($_GET['action'] ?? '');

if ($action === '') {
    tcc_json(array(
        'ok' => false,
        'error' => 'missing_action',
        'allowed_actions' => array(
            'cohort_overview',
            'action_queue',
            'student_snapshot',
            'system_watch',
            'debug_report'
        )
    ), 400);
}

try {
    if ($action === 'cohort_overview') {
        $cohortId = tcc_int($_GET['cohort_id'] ?? 0);

        if ($cohortId <= 0) {
            tcc_json(array(
                'ok' => true,
                'action' => 'cohort_overview',
                'cohorts' => tcc_fetch_cohorts($pdo)
            ));
        }

        $students = tcc_cohort_students($pdo, $cohortId);
        $totalLessons = tcc_total_lessons($pdo, $cohortId);
        $passedMap = tcc_batch_passed_lessons($pdo, $cohortId);
        $attemptStatsMap = tcc_batch_progress_stats($pdo, $cohortId);
        $deadlineMissMap = tcc_batch_deadline_misses($pdo, $cohortId);
        $pendingActionCountMap = tcc_batch_pending_action_counts($pdo, $cohortId);

        $studentRows = array();
        $blockedCount = 0;
        $atRiskCount = 0;
        $onTrackCount = 0;

        foreach ($students as $student) {
            $sid = (int)$student['id'];
            $name = tcc_student_name($student);
            $passed = (int)($passedMap[$sid] ?? 0);
            $progressPct = $totalLessons > 0 ? (int)round(($passed / $totalLessons) * 100) : 0;
            $stats = $attemptStatsMap[$sid] ?? tcc_default_attempt_stats();
            $deadlineMisses = (int)($deadlineMissMap[$sid] ?? 0);
            $pendingActionCount = (int)($pendingActionCountMap[$sid] ?? 0);
            $motivation = tcc_motivation_signal($stats, $deadlineMisses, $pendingActionCount);

            $state = 'on_track';
            if ($pendingActionCount > 0) {
                $state = 'blocked';
                $blockedCount++;
            } elseif ($motivation['level'] === 'drifting' || $motivation['level'] === 'needs_contact') {
                $state = 'at_risk';
                $atRiskCount++;
            } else {
                $onTrackCount++;
            }

            $studentRows[] = array(
                'student_id' => $sid,
                'name' => $name,
                'email' => (string)($student['email'] ?? ''),
                'avatar_initials' => tcc_avatar_initials($name),
                'progress_pct' => $progressPct,
                'passed_lessons' => $passed,
                'total_lessons' => $totalLessons,
                'pending_action_count' => $pendingActionCount,
                'failed_attempts' => (int)$stats['failed_attempts'],
                'avg_score' => $stats['avg_score'],
                'deadline_misses' => $deadlineMisses,
                'motivation' => $motivation,
                'state' => $state,
            );
        }

        usort($studentRows, function ($a, $b) {
            if ($a['progress_pct'] === $b['progress_pct']) {
                return strcmp((string)$a['name'], (string)$b['name']);
            }
            return $b['progress_pct'] <=> $a['progress_pct'];
        });

        tcc_json(array(
            'ok' => true,
            'action' => 'cohort_overview',
            'cohort_id' => $cohortId,
            'summary' => array(
                'student_count' => count($students),
                'total_lessons' => $totalLessons,
                'on_track_count' => $onTrackCount,
                'at_risk_count' => $atRiskCount,
                'blocked_count' => $blockedCount,
            ),
            'students' => $studentRows,
        ));
    }

    if ($action === 'action_queue') {
        $cohortId = tcc_int($_GET['cohort_id'] ?? 0);
        if ($cohortId <= 0) {
            tcc_json(array('ok' => false, 'error' => 'missing_cohort_id'), 400);
        }

        $actions = tcc_pending_actions($pdo, $cohortId);
        $items = array();

        foreach ($actions as $row) {
            $studentName = tcc_student_name(array(
                'name' => $row['student_name'] ?? '',
                'email' => $row['student_email'] ?? ''
            ));

            $actionType = (string)$row['action_type'];

            $items[] = array(
                'required_action_id' => (int)$row['id'],
                'student_id' => (int)$row['user_id'],
                'student_name' => $studentName,
                'avatar_initials' => tcc_avatar_initials($studentName),
                'cohort_id' => (int)$row['cohort_id'],
                'lesson_id' => (int)$row['lesson_id'],
                'lesson_title' => (string)($row['lesson_title'] ?? ''),
                'action_type' => $actionType,
                'status' => (string)$row['status'],
                'severity' => tcc_action_severity($actionType),
                'reason' => (string)($row['title'] ?? $actionType),
                'recommended_action' => tcc_recommended_action($actionType),
                'safe_actions' => tcc_safe_actions($actionType),
                'created_at' => (string)($row['created_at'] ?? ''),
                'opened_at' => (string)($row['opened_at'] ?? ''),
                'token' => (string)($row['token'] ?? ''),
            );
        }

        tcc_json(array(
            'ok' => true,
            'action' => 'action_queue',
            'cohort_id' => $cohortId,
            'count' => count($items),
            'items' => $items,
        ));
    }

    if ($action === 'student_snapshot') {
        $cohortId = tcc_int($_GET['cohort_id'] ?? 0);
        $studentId = tcc_int($_GET['student_id'] ?? 0);

        if ($cohortId <= 0 || $studentId <= 0) {
            tcc_json(array('ok' => false, 'error' => 'missing_cohort_or_student_id'), 400);
        }

        $st = $pdo->prepare("\n            SELECT id, name, email\n            FROM users\n            WHERE id = ?\n            LIMIT 1\n        ");
        $st->execute(array($studentId));
        $student = $st->fetch(PDO::FETCH_ASSOC);

        if (!$student) {
            tcc_json(array('ok' => false, 'error' => 'student_not_found'), 404);
        }

        $students = tcc_cohort_students($pdo, $cohortId);
        $attemptStatsMap = tcc_batch_progress_stats($pdo, $cohortId);
        $deadlineMissMap = tcc_batch_deadline_misses($pdo, $cohortId);
        $pendingActionCountMap = tcc_batch_pending_action_counts($pdo, $cohortId);
        $cohortAvg = tcc_cohort_metric_averages_from_maps($students, $deadlineMissMap, $attemptStatsMap);

        $totalLessons = tcc_total_lessons($pdo, $cohortId);
        $passed = tcc_passed_lessons($pdo, $studentId, $cohortId);
        $progressPct = $totalLessons > 0 ? (int)round(($passed / $totalLessons) * 100) : 0;

        $pending = tcc_pending_actions($pdo, $cohortId, $studentId);
        $completedActions = tcc_completed_actions($pdo, $cohortId, $studentId);
        $stats = $attemptStatsMap[$studentId] ?? tcc_student_attempt_stats($pdo, $cohortId, $studentId);
        $deadlineMisses = (int)($deadlineMissMap[$studentId] ?? tcc_deadline_missed_count($pdo, $cohortId, $studentId));
        $motivation = tcc_motivation_signal($stats, $deadlineMisses, count($pending));
        $systemIssues = tcc_system_watch($pdo, $cohortId, $studentId);

        $issues = array();
        foreach ($pending as $p) {
            $issues[] = array(
                'type' => (string)$p['action_type'],
                'status' => (string)$p['status'],
                'lesson_id' => (int)$p['lesson_id'],
                'lesson_title' => (string)($p['lesson_title'] ?? ''),
                'title' => (string)($p['title'] ?? ''),
            );
        }

        foreach ($systemIssues as $si) {
            $issues[] = array(
                'type' => (string)$si['issue_type'],
                'status' => 'system_watch',
                'lesson_id' => (int)$si['lesson_id'],
                'lesson_title' => (string)($si['lesson_title'] ?? ''),
                'title' => (string)$si['summary'],
            );
        }

        $name = tcc_student_name($student);

        tcc_json(array(
            'ok' => true,
            'action' => 'student_snapshot',
            'student' => array(
                'student_id' => $studentId,
                'name' => $name,
                'email' => (string)($student['email'] ?? ''),
                'avatar_initials' => tcc_avatar_initials($name),
            ),
            'cohort_id' => $cohortId,
            'progress' => array(
                'passed_lessons' => $passed,
                'total_lessons' => $totalLessons,
                'progress_pct' => $progressPct,
            ),
            'comparison' => array(
                'deadlines_missed' => $deadlineMisses,
                'cohort_avg_deadlines_missed' => $cohortAvg['avg_deadlines_missed'],
                'failed_attempts' => (int)$stats['failed_attempts'],
                'cohort_avg_failed_attempts' => $cohortAvg['avg_failed_attempts'],
                'avg_score' => $stats['avg_score'],
                'cohort_avg_score' => $cohortAvg['avg_score'],
            ),
            'motivation' => $motivation,
            'main_issues' => $issues,
            'pending_action_count' => count($pending),
            'completed_intervention_count' => count($completedActions),
            'system_issue_count' => count($systemIssues),
        ));
    }

    if ($action === 'system_watch') {
        $cohortId = tcc_int($_GET['cohort_id'] ?? 0);
        $studentId = tcc_int($_GET['student_id'] ?? 0);

        if ($cohortId <= 0) {
            tcc_json(array('ok' => false, 'error' => 'missing_cohort_id'), 400);
        }

        $issues = tcc_system_watch($pdo, $cohortId, $studentId > 0 ? $studentId : null);

        tcc_json(array(
            'ok' => true,
            'action' => 'system_watch',
            'cohort_id' => $cohortId,
            'student_id' => $studentId ?: null,
            'count' => count($issues),
            'issues' => $issues,
        ));
    }

if ($action === 'debug_report') {
    $cohortId = tcc_int($_GET['cohort_id'] ?? 0);
    $studentId = tcc_int($_GET['student_id'] ?? 0);
    $lessonId = tcc_int($_GET['lesson_id'] ?? 0);
    $issueType = tcc_str($_GET['issue_type'] ?? '');

    if ($cohortId <= 0 || $studentId <= 0 || $lessonId <= 0) {
        tcc_json(array(
            'ok' => false,
            'error' => 'missing_required_ids'
        ), 400);
    }

    $pt = $pdo->prepare("
        SELECT
            id,
            attempt,
            status,
            formal_result_code,
            pass_gate_met,
            counts_as_unsat,
            score_pct,
            started_at,
            completed_at,
            updated_at
        FROM progress_tests_v2
        WHERE cohort_id = ?
          AND user_id = ?
          AND lesson_id = ?
        ORDER BY id DESC
        LIMIT 20
    ");
    $pt->execute(array($cohortId, $studentId, $lessonId));
    $progressTests = $pt->fetchAll(PDO::FETCH_ASSOC);

    $la = $pdo->prepare("
        SELECT *
        FROM lesson_activity
        WHERE cohort_id = ?
          AND user_id = ?
          AND lesson_id = ?
        LIMIT 1
    ");
    $la->execute(array($cohortId, $studentId, $lessonId));
    $lessonActivity = $la->fetch(PDO::FETCH_ASSOC);
    if (!$lessonActivity) {
        $lessonActivity = null;
    }

    $ra = $pdo->prepare("
        SELECT
            id,
            action_type,
            status,
            title,
            created_at,
            opened_at,
            completed_at,
            updated_at
        FROM student_required_actions
        WHERE cohort_id = ?
          AND user_id = ?
          AND lesson_id = ?
        ORDER BY id DESC
        LIMIT 20
    ");
    $ra->execute(array($cohortId, $studentId, $lessonId));
    $requiredActions = $ra->fetchAll(PDO::FETCH_ASSOC);

    $emails = array();

    $emailColumnState = tcc_table_has_columns($pdo, 'training_progression_emails', array(
        'id',
        'email_type',
        'subject',
        'sent_status',
        'created_at',
        'sent_at',
        'cohort_id',
        'user_id',
        'lesson_id'
    ));

    if (
        !empty($emailColumnState['id']) &&
        !empty($emailColumnState['cohort_id']) &&
        !empty($emailColumnState['user_id']) &&
        !empty($emailColumnState['lesson_id'])
    ) {
        try {
            $selectCols = array('id');

            if (!empty($emailColumnState['email_type'])) {
                $selectCols[] = 'email_type';
            }

            if (!empty($emailColumnState['subject'])) {
                $selectCols[] = 'subject';
            }

            if (!empty($emailColumnState['sent_status'])) {
                $selectCols[] = 'sent_status';
            }

            if (!empty($emailColumnState['created_at'])) {
                $selectCols[] = 'created_at';
            }

            if (!empty($emailColumnState['sent_at'])) {
                $selectCols[] = 'sent_at';
            }

            $em = $pdo->prepare("
                SELECT " . implode(', ', $selectCols) . "
                FROM training_progression_emails
                WHERE cohort_id = ?
                  AND user_id = ?
                  AND lesson_id = ?
                ORDER BY id DESC
                LIMIT 20
            ");
            $em->execute(array($cohortId, $studentId, $lessonId));
            $emails = $em->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $emails = array();
        }
    }

    tcc_json(array(
        'ok' => true,
        'action' => 'debug_report',
        'generated_at_utc' => gmdate('Y-m-d H:i:s'),
        'issue_type' => ($issueType !== '' ? $issueType : 'manual_debug_report'),
        'student_id' => $studentId,
        'cohort_id' => $cohortId,
        'lesson_id' => $lessonId,
        'canonical' => array(
            'progress_tests_v2' => $progressTests,
            'required_actions' => $requiredActions,
            'emails' => $emails
        ),
        'projection' => array(
            'lesson_activity' => $lessonActivity
        ),
        'agent_instructions' => array(
            'purpose' => 'Use this report to diagnose SSOT drift or progression blockage without guessing.',
            'rules' => array(
                'Do not treat lesson_activity as canonical truth.',
                'PASS in progress_tests_v2 is terminal.',
                'Required actions must be checked against canonical completion state.',
                'Any manual repair must be auditable.'
            )
        )
    ));
}

    tcc_json(array(
        'ok' => false,
        'error' => 'unknown_action',
        'action' => $action
    ), 400);

} catch (Throwable $e) {
    error_log('TCC_API_ERROR action=' . $action . ' msg=' . $e->getMessage());

    tcc_json(array(
        'ok' => false,
        'error' => 'server_error',
        'message' => $e->getMessage()
    ), 500);
}
