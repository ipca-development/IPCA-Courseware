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
            p.program_key
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
          AND sra.status IN ('pending','opened')
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
          AND (
              COALESCE(la.completion_status, '') <> 'completed'
              OR COALESCE(la.test_pass_status, '') <> 'passed'
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
            'summary' => 'Canonical PASS exists, but lesson_activity does not show completed/passed.',
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

$action = tcc_str($_GET['action'] ?? '');

if ($action === '') {
    tcc_json([
        'ok' => false,
        'error' => 'missing_action',
        'allowed_actions' => [
            'cohort_overview',
            'action_queue',
            'student_snapshot',
            'system_watch',
            'student_lessons',
            'debug_report'
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
                'reason' => (string)($row['title'] ?? $actionType),
                'recommended_action' => tcc_recommended_action($actionType),
                'safe_actions' => tcc_safe_actions($actionType),
                'created_at' => (string)($row['created_at'] ?? ''),
                'opened_at' => (string)($row['opened_at'] ?? ''),
                'token' => (string)($row['token'] ?? ''),
            ];
        }

        tcc_json([
            'ok' => true,
            'action' => 'action_queue',
            'cohort_id' => $cohortId,
            'count' => count($items),
            'items' => $items,
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
            $issues[] = [
                'type' => (string)$p['action_type'],
                'status' => (string)$p['status'],
                'lesson_id' => (int)$p['lesson_id'],
                'lesson_title' => (string)($p['lesson_title'] ?? ''),
                'title' => (string)($p['title'] ?? ''),
            ];
        }

        foreach ($systemIssues as $si) {
            $issues[] = [
                'type' => (string)$si['issue_type'],
                'status' => 'system_watch',
                'lesson_id' => (int)$si['lesson_id'],
                'lesson_title' => (string)($si['lesson_title'] ?? ''),
                'title' => (string)$si['summary'],
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
