<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/courseware_progression_v2.php';

cw_require_login();

$currentUser = cw_current_user($pdo);
$currentRole = trim((string)($currentUser['role'] ?? ''));
$allowedRoles = array('admin', 'supervisor', 'instructor', 'chief_instructor');
if (!in_array($currentRole, $allowedRoles, true)) {
    http_response_code(403);
    exit('Forbidden');
}

$engine = new CoursewareProgressionV2($pdo);

function cp_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function cp_json(mixed $value): string
{
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

function cp_svg_avatar(): string
{
    return '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true">'
        . '<path d="M12 12c2.761 0 5-2.462 5-5.5S14.761 1 12 1 7 3.462 7 6.5 9.239 12 12 12Z" fill="currentColor" opacity=".88"/>'
        . '<path d="M3.5 22c.364-4.157 4.006-7 8.5-7s8.136 2.843 8.5 7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>'
        . '</svg>';
}

function cp_avatar_html(array $user, string $displayName, int $size = 44): string
{
    $photoPath = trim((string)($user['photo_path'] ?? ''));
    if ($photoPath !== '') {
        return '<div class="cp-avatar" style="width:' . $size . 'px;height:' . $size . 'px;flex:0 0 ' . $size . 'px;">'
            . '<img src="' . cp_h($photoPath) . '" alt="' . cp_h($displayName) . '">'
            . '</div>';
    }

    return '<div class="cp-avatar" style="width:' . $size . 'px;height:' . $size . 'px;flex:0 0 ' . $size . 'px;">'
        . '<span class="cp-avatar-fallback">' . cp_svg_avatar() . '</span>'
        . '</div>';
}

function cp_percent(float $value): int
{
    if ($value < 0) {
        $value = 0;
    }
    if ($value > 100) {
        $value = 100;
    }
    return (int)round($value);
}

function cp_fmt_date(?string $dt): string
{
    $dt = trim((string)$dt);
    if ($dt === '') {
        return '—';
    }
    $ts = strtotime($dt);
    if ($ts === false) {
        return $dt;
    }
    return gmdate('M j, Y', $ts);
}

function cp_fmt_datetime(?string $dt): string
{
    $dt = trim((string)$dt);
    if ($dt === '') {
        return '—';
    }
    $ts = strtotime($dt);
    if ($ts === false) {
        return $dt;
    }
    return gmdate('M j, Y · H:i', $ts) . ' UTC';
}

function cp_days_from_now(?string $dt): ?int
{
    $dt = trim((string)$dt);
    if ($dt === '') {
        return null;
    }
    $ts = strtotime($dt);
    if ($ts === false) {
        return null;
    }
    return (int)floor(($ts - time()) / 86400);
}

function cp_deadline_bucket(?string $deadlineUtc, string $completionStatus): string
{
    if (in_array($completionStatus, array('completed'), true)) {
        return 'done';
    }
    $days = cp_days_from_now($deadlineUtc);
    if ($days === null) {
        return 'none';
    }
    if ($days < 0) {
        return 'overdue';
    }
    if ($days <= 3) {
        return 'soon';
    }
    return 'future';
}

function cp_motivation_score(array $student): array
{
    $score = 50.0;
    $trend = '→';

    $progress = (float)($student['progress_percent'] ?? 0.0);
    $avgPt = (float)($student['avg_pt_score'] ?? 0.0);
    $lastPt = (float)($student['last_pt_score'] ?? 0.0);
    $overdue = (int)($student['overdue_count'] ?? 0);
    $dueSoon = (int)($student['due_soon_count'] ?? 0);
    $pending = (int)($student['pending_action_count'] ?? 0);
    $latestSummary = trim((string)($student['latest_summary_status'] ?? ''));
    $inactiveDays = (int)($student['inactive_days'] ?? 0);
    $passRate = (float)($student['pt_pass_rate'] ?? 0.0);

    $score += ($progress * 0.18);
    $score += ($avgPt * 0.16);
    $score += ($lastPt * 0.08);
    $score += ($passRate * 0.10);

    if ($latestSummary === 'acceptable') {
        $score += 8;
    } elseif ($latestSummary === 'needs_revision') {
        $score -= 10;
    }

    $score -= min(22, $overdue * 8);
    $score -= min(12, $dueSoon * 2);
    $score -= min(18, $pending * 5);

    if ($inactiveDays >= 10) {
        $score -= 15;
    } elseif ($inactiveDays >= 5) {
        $score -= 8;
    } else {
        $score += 4;
    }

    if ($lastPt >= $avgPt + 8) {
        $trend = '↑';
        $score += 5;
    } elseif ($lastPt > 0 && $avgPt > 0 && $lastPt <= $avgPt - 8) {
        $trend = '↓';
        $score -= 5;
    }

    $score = cp_percent($score);

    return array(
        'score' => $score,
        'trend' => $trend,
        'class' => $score >= 80 ? 'high' : ($score >= 60 ? 'good' : ($score >= 40 ? 'fragile' : 'low')),
    );
}

function cp_risk_meta(array $student, array $motivation): array
{
    $risk = 0;
    $risk += ((int)($student['overdue_count'] ?? 0)) * 18;
    $risk += ((int)($student['pending_action_count'] ?? 0)) * 16;
    $risk += ((int)($student['training_suspended_count'] ?? 0)) * 40;
    $risk += ((int)($student['one_on_one_required_count'] ?? 0)) * 12;
    $risk += ((int)($student['failed_attempts_count'] ?? 0)) * 6;
    $risk += ((int)($student['extension_count'] ?? 0)) * 4;
    $risk += max(0, 65 - (int)$motivation['score']);

    if ($risk >= 80) {
        return array('label' => 'CRITICAL', 'class' => 'critical');
    }
    if ($risk >= 50) {
        return array('label' => 'HIGH', 'class' => 'high');
    }
    if ($risk >= 24) {
        return array('label' => 'MED', 'class' => 'medium');
    }
    return array('label' => 'LOW', 'class' => 'low');
}

function cp_attention_flags(array $student): array
{
    $flags = array();

    if ((int)($student['training_suspended_count'] ?? 0) > 0) {
        $flags[] = array('icon' => '⛔', 'label' => 'Training suspended', 'class' => 'danger');
    }
    if ((int)($student['pending_instructor_count'] ?? 0) > 0) {
        $flags[] = array('icon' => '🧑‍✈️', 'label' => 'Instructor action pending', 'class' => 'danger');
    }
    if ((int)($student['pending_remediation_count'] ?? 0) > 0) {
        $flags[] = array('icon' => '🛠', 'label' => 'Remediation pending', 'class' => 'warning');
    }
    if ((int)($student['overdue_count'] ?? 0) > 0) {
        $flags[] = array('icon' => '⏰', 'label' => 'Overdue deadlines', 'class' => 'danger');
    }
    if ((int)($student['one_on_one_required_count'] ?? 0) > 0 && (int)($student['one_on_one_completed_count'] ?? 0) === 0) {
        $flags[] = array('icon' => '🎧', 'label' => 'One-on-one required', 'class' => 'warning');
    }
    if (trim((string)($student['latest_summary_status'] ?? '')) === 'needs_revision') {
        $flags[] = array('icon' => '📝', 'label' => 'Summary needs revision', 'class' => 'info');
    }

    return $flags;
}

function cp_latest_attempt_summary(array $student): string
{
    $lessonTitle = trim((string)($student['latest_test_lesson_title'] ?? ''));
    $attempt = (int)($student['latest_test_attempt'] ?? 0);
    $score = $student['last_pt_score'] !== null ? (string)cp_percent((float)$student['last_pt_score']) . '%' : '—';
    if ($lessonTitle === '') {
        return 'No completed tests yet';
    }
    return $lessonTitle . ' · A' . $attempt . ' · ' . $score;
}

function cp_route_marker_segments(int $totalLessons, int $lastCompletedOrder, int $currentOrder, int $overdueCount, int $pendingCount): array
{
    $segments = array_fill(0, 10, 'future');
    if ($totalLessons <= 0) {
        return $segments;
    }

    $completedRatio = $lastCompletedOrder > 0 ? ($lastCompletedOrder / $totalLessons) : 0;
    $completedSegments = (int)floor($completedRatio * 10);
    for ($i = 0; $i < $completedSegments; $i++) {
        $segments[$i] = 'done';
    }

    if ($currentOrder > 0) {
        $currentIndex = min(9, max(0, (int)floor((($currentOrder - 1) / max(1, $totalLessons)) * 10)));
        $segments[$currentIndex] = $overdueCount > 0 ? 'danger' : ($pendingCount > 0 ? 'warning' : 'current');
    }

    return $segments;
}

function cp_fetch_assoc_grouped(PDO $pdo, string $sql, array $params = array(), string $groupKey = ''): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($groupKey === '') {
        return $rows;
    }
    $grouped = array();
    foreach ($rows as $row) {
        $key = (string)($row[$groupKey] ?? '');
        if (!isset($grouped[$key])) {
            $grouped[$key] = array();
        }
        $grouped[$key][] = $row;
    }
    return $grouped;
}

function cp_render_student_modal(PDO $pdo, CoursewareProgressionV2 $engine, int $cohortId, int $userId): string
{
    $studentStmt = $pdo->prepare(
        "SELECT u.id, u.name, u.first_name, u.last_name, u.email, u.photo_path, c.name AS cohort_name, c.course_id,
                COALESCE(cr.title, CONCAT('Course #', c.course_id)) AS course_title
         FROM cohort_students cs
         INNER JOIN users u ON u.id = cs.user_id
         INNER JOIN cohorts c ON c.id = cs.cohort_id
         LEFT JOIN courses cr ON cr.id = c.course_id
         WHERE cs.cohort_id = ? AND cs.user_id = ?
         LIMIT 1"
    );
    $studentStmt->execute(array($cohortId, $userId));
    $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
    if (!$student) {
        return '<div class="cp-empty">Student not found in this cohort.</div>';
    }

    $displayName = trim((string)($student['name'] ?? ''));
    if ($displayName === '') {
        $displayName = trim((string)($student['first_name'] ?? '') . ' ' . (string)($student['last_name'] ?? ''));
    }
    if ($displayName === '') {
        $displayName = 'Student #' . $userId;
    }

    $lessonStmt = $pdo->prepare(
        "SELECT l.id AS lesson_id, l.title, cld.sort_order, cld.deadline_utc,
                la.completion_status, la.summary_status, la.test_pass_status, la.attempt_count, la.best_score,
                la.extension_count, la.one_on_one_required, la.one_on_one_completed, la.training_suspended,
                ls.review_status AS latest_summary_status, ls.review_score AS latest_summary_score,
                pt.id AS latest_test_id, pt.attempt AS latest_test_attempt, pt.status AS latest_test_status,
                pt.score_pct AS latest_test_score, pt.formal_result_code AS latest_test_result_code,
                pt.formal_result_label AS latest_test_result_label
         FROM cohort_lesson_deadlines cld
         INNER JOIN lessons l ON l.id = cld.lesson_id
         LEFT JOIN lesson_activity la
                ON la.user_id = ? AND la.cohort_id = cld.cohort_id AND la.lesson_id = cld.lesson_id
         LEFT JOIN lesson_summaries ls
                ON ls.id = (
                    SELECT ls2.id
                    FROM lesson_summaries ls2
                    WHERE ls2.user_id = ? AND ls2.cohort_id = cld.cohort_id AND ls2.lesson_id = cld.lesson_id
                    ORDER BY ls2.updated_at DESC, ls2.id DESC
                    LIMIT 1
                )
         LEFT JOIN progress_tests_v2 pt
                ON pt.id = (
                    SELECT pt2.id
                    FROM progress_tests_v2 pt2
                    WHERE pt2.user_id = ? AND pt2.cohort_id = cld.cohort_id AND pt2.lesson_id = cld.lesson_id
                    ORDER BY COALESCE(pt2.completed_at, pt2.created_at) DESC, pt2.id DESC
                    LIMIT 1
                )
         WHERE cld.cohort_id = ?
         ORDER BY cld.sort_order ASC, cld.id ASC"
    );
    $lessonStmt->execute(array($userId, $userId, $userId, $cohortId));
    $lessons = $lessonStmt->fetchAll(PDO::FETCH_ASSOC);

    $testsStmt = $pdo->prepare(
        "SELECT pt.*, l.title AS lesson_title
         FROM progress_tests_v2 pt
         INNER JOIN lessons l ON l.id = pt.lesson_id
         WHERE pt.user_id = ? AND pt.cohort_id = ?
         ORDER BY pt.lesson_id ASC, pt.attempt DESC, pt.id DESC"
    );
    $testsStmt->execute(array($userId, $cohortId));
    $tests = $testsStmt->fetchAll(PDO::FETCH_ASSOC);

    $testIds = array();
    foreach ($tests as $test) {
        $testIds[] = (int)$test['id'];
    }

    $itemsByTest = array();
    if ($testIds) {
        $placeholders = implode(',', array_fill(0, count($testIds), '?'));
        $itemsStmt = $pdo->prepare(
            "SELECT *
             FROM progress_test_items_v2
             WHERE test_id IN ($placeholders)
             ORDER BY test_id ASC, idx ASC, id ASC"
        );
        $itemsStmt->execute($testIds);
        while ($item = $itemsStmt->fetch(PDO::FETCH_ASSOC)) {
            $itemsByTest[(int)$item['test_id']][] = $item;
        }
    }

    $actionsStmt = $pdo->prepare(
        "SELECT *
         FROM student_required_actions
         WHERE user_id = ? AND cohort_id = ?
         ORDER BY lesson_id ASC, created_at DESC, id DESC"
    );
    $actionsStmt->execute(array($userId, $cohortId));
    $actionsByLesson = array();
    while ($action = $actionsStmt->fetch(PDO::FETCH_ASSOC)) {
        $actionsByLesson[(int)$action['lesson_id']][] = $action;
    }

    $testsByLesson = array();
    foreach ($tests as $test) {
        $testsByLesson[(int)$test['lesson_id']][] = $test;
    }

    ob_start();
    ?>
    <div class="cp-modal-header">
        <div class="cp-modal-student">
            <?= cp_avatar_html($student, $displayName, 72) ?>
            <div class="cp-modal-student-copy">
                <div class="cp-modal-kicker">Student Detail</div>
                <h2><?= cp_h($displayName) ?></h2>
                <div class="cp-modal-meta"><?= cp_h($student['cohort_name'] ?? 'Cohort') ?> · <?= cp_h($student['course_title'] ?? 'Course') ?></div>
            </div>
        </div>
        <button type="button" class="cp-modal-close" data-close-modal>Close</button>
    </div>

    <div class="cp-modal-sections">
        <section class="cp-modal-section">
            <h3>Lessons, Deadlines & Attempts</h3>
            <div class="cp-lesson-list">
                <?php foreach ($lessons as $lesson):
                    $lessonId = (int)$lesson['lesson_id'];
                    $lessonTests = $testsByLesson[$lessonId] ?? array();
                    $lessonActions = $actionsByLesson[$lessonId] ?? array();
                    $deadlineBucket = cp_deadline_bucket((string)($lesson['deadline_utc'] ?? ''), (string)($lesson['completion_status'] ?? ''));
                    $statusClass = $deadlineBucket === 'overdue' ? 'danger' : ($deadlineBucket === 'soon' ? 'warning' : 'neutral');
                    ?>
                    <details class="cp-lesson-item" <?= $lessonId === (int)($lessons[0]['lesson_id'] ?? 0) ? 'open' : '' ?>>
                        <summary>
                            <div class="cp-lesson-summary-left">
                                <div class="cp-lesson-index">L<?= (int)$lesson['sort_order'] ?></div>
                                <div>
                                    <div class="cp-lesson-title"><?= cp_h($lesson['title']) ?></div>
                                    <div class="cp-lesson-sub">Deadline <?= cp_h(cp_fmt_date($lesson['deadline_utc'] ?? null)) ?> · Completion <?= cp_h((string)($lesson['completion_status'] ?? '—')) ?></div>
                                </div>
                            </div>
                            <div class="cp-lesson-summary-right">
                                <span class="cp-badge <?= cp_h($statusClass) ?>"><?= cp_h($deadlineBucket === 'future' ? 'On Track' : ($deadlineBucket === 'soon' ? 'Due Soon' : ($deadlineBucket === 'overdue' ? 'Overdue' : 'Complete'))) ?></span>
                                <span class="cp-badge info">Attempts <?= (int)($lesson['attempt_count'] ?? 0) ?></span>
                                <span class="cp-badge <?= cp_h((string)($lesson['latest_summary_status'] ?? '') === 'acceptable' ? 'ok' : ((string)($lesson['latest_summary_status'] ?? '') === 'needs_revision' ? 'warning' : 'neutral')) ?>">Summary <?= cp_h((string)($lesson['latest_summary_status'] ?? '—')) ?></span>
                            </div>
                        </summary>
                        <div class="cp-lesson-body">
                            <?php if ($lessonActions): ?>
                                <div class="cp-subpanel">
                                    <h4>Required Actions</h4>
                                    <div class="cp-action-list">
                                        <?php foreach ($lessonActions as $action): ?>
                                            <div class="cp-action-row">
                                                <div>
                                                    <strong><?= cp_h((string)$action['action_type']) ?></strong>
                                                    <div class="cp-muted">Status <?= cp_h((string)$action['status']) ?> · Created <?= cp_h(cp_fmt_datetime($action['created_at'] ?? null)) ?></div>
                                                </div>
                                                <div class="cp-action-meta">
                                                    <?php if (trim((string)($action['decision_code'] ?? '')) !== ''): ?>
                                                        <span class="cp-badge neutral">Decision <?= cp_h((string)$action['decision_code']) ?></span>
                                                    <?php endif; ?>
                                                    <?php if ((int)($action['granted_extra_attempts'] ?? 0) > 0): ?>
                                                        <span class="cp-badge info">+<?= (int)$action['granted_extra_attempts'] ?> attempts</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="cp-subpanel">
                                <h4>Progress Tests</h4>
                                <?php if (!$lessonTests): ?>
                                    <div class="cp-empty">No progress tests recorded for this lesson.</div>
                                <?php else: ?>
                                    <div class="cp-attempt-list">
                                        <?php foreach ($lessonTests as $test):
                                            $testId = (int)$test['id'];
                                            $items = $itemsByTest[$testId] ?? array();
                                            ?>
                                            <details class="cp-attempt-card">
                                                <summary>
                                                    <div>
                                                        <strong>Attempt <?= (int)$test['attempt'] ?></strong>
                                                        <div class="cp-muted"><?= cp_h((string)$test['status']) ?> · <?= cp_h(cp_fmt_datetime($test['created_at'] ?? null)) ?></div>
                                                    </div>
                                                    <div class="cp-attempt-summary-right">
                                                        <span class="cp-badge <?= cp_h(((int)($test['pass_gate_met'] ?? 0) === 1) ? 'ok' : (((string)($test['status'] ?? '') === 'completed') ? 'warning' : 'neutral')) ?>">
                                                            <?= $test['score_pct'] !== null ? cp_h((string)cp_percent((float)$test['score_pct'])) . '%' : '—' ?>
                                                        </span>
                                                        <span class="cp-badge neutral"><?= cp_h((string)($test['formal_result_label'] ?? ($test['formal_result_code'] ?? '—'))) ?></span>
                                                    </div>
                                                </summary>
                                                <div class="cp-attempt-body">
                                                    <?php if (trim((string)($test['ai_summary'] ?? '')) !== ''): ?>
                                                        <div class="cp-attempt-text"><strong>Evaluation</strong><div><?= nl2br(cp_h((string)$test['ai_summary'])) ?></div></div>
                                                    <?php endif; ?>
                                                    <?php if (trim((string)($test['weak_areas'] ?? '')) !== ''): ?>
                                                        <div class="cp-attempt-text"><strong>Weak Areas</strong><div><?= nl2br(cp_h((string)$test['weak_areas'])) ?></div></div>
                                                    <?php endif; ?>
                                                    <div class="cp-item-table-wrap">
                                                        <table class="cp-item-table">
                                                            <thead>
                                                                <tr>
                                                                    <th>#</th>
                                                                    <th>Prompt</th>
                                                                    <th>Transcript</th>
                                                                    <th>Audio</th>
                                                                    <th>Score</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <?php foreach ($items as $item):
                                                                    $audioPath = trim((string)($item['audio_path'] ?? ''));
                                                                    $audioUrl = $audioPath !== '' ? 'https://ipca-media.nyc3.cdn.digitaloceanspaces.com/' . ltrim($audioPath, '/') : '';
                                                                    ?>
                                                                    <tr>
                                                                        <td><?= (int)$item['idx'] ?></td>
                                                                        <td><?= cp_h((string)$item['prompt']) ?></td>
                                                                        <td><?= nl2br(cp_h(trim((string)($item['transcript_text'] ?? '')) !== '' ? (string)$item['transcript_text'] : '—')) ?></td>
                                                                        <td>
                                                                            <?php if ($audioUrl !== ''): ?>
                                                                                <audio controls preload="none" src="<?= cp_h($audioUrl) ?>"></audio>
                                                                            <?php else: ?>
                                                                                —
                                                                            <?php endif; ?>
                                                                        </td>
                                                                        <td><?= $item['score_points'] !== null ? (int)$item['score_points'] . ' / ' . (int)($item['max_points'] ?? 0) : '—' ?></td>
                                                                    </tr>
                                                                <?php endforeach; ?>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </div>
                                            </details>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
    <?php
    return (string)ob_get_clean();
}

$selectedCohortId = max(0, (int)($_GET['cohort_id'] ?? 0));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 8;
$showRetired = !empty($_GET['show_retired']) ? 1 : 0;

if (isset($_GET['ajax']) && $_GET['ajax'] === 'student_detail') {
    $cohortId = max(0, (int)($_GET['cohort_id'] ?? 0));
    $userId = max(0, (int)($_GET['user_id'] ?? 0));
    header('Content-Type: application/json; charset=UTF-8');
    if ($cohortId <= 0 || $userId <= 0) {
        echo cp_json(array('ok' => false, 'html' => '<div class="cp-empty">Invalid student request.</div>'));
        exit;
    }
    echo cp_json(array('ok' => true, 'html' => cp_render_student_modal($pdo, $engine, $cohortId, $userId)));
    exit;
}

$cohortStmt = $pdo->prepare(
    "SELECT c.id, c.course_id, c.name, c.start_date, c.end_date,
            COALESCE(cr.title, CONCAT('Course #', c.course_id)) AS course_title,
            COUNT(DISTINCT CASE WHEN u.role = 'student' AND u.status = 'active' THEN cs.user_id END) AS student_count
     FROM cohorts c
     LEFT JOIN courses cr ON cr.id = c.course_id
     LEFT JOIN cohort_students cs ON cs.cohort_id = c.id
     LEFT JOIN users u ON u.id = cs.user_id
     WHERE (:show_retired = 1 OR c.end_date >= CURDATE())
     GROUP BY c.id, c.course_id, c.name, c.start_date, c.end_date, cr.title
     ORDER BY c.start_date ASC, c.id ASC"
);
$cohortStmt->execute(array(':show_retired' => $showRetired));
$cohorts = $cohortStmt->fetchAll(PDO::FETCH_ASSOC);

if ($selectedCohortId <= 0 && !empty($cohorts)) {
    $selectedCohortId = (int)$cohorts[0]['id'];
}

$selectedCohort = null;
foreach ($cohorts as $cohort) {
    if ((int)$cohort['id'] === $selectedCohortId) {
        $selectedCohort = $cohort;
        break;
    }
}

$students = array();
$distribution = array();
$summary = array(
    'student_count' => 0,
    'avg_progress' => 0,
    'avg_pt' => 0,
    'urgent' => 0,
    'attention' => 0,
    'overdue' => 0,
    'pending' => 0,
);
$totalLessons = 0;

if ($selectedCohortId > 0 && $selectedCohort) {
    $courseId = (int)($selectedCohort['course_id'] ?? 0);

    $lessonOrderStmt = $pdo->prepare(
        "SELECT cld.lesson_id, cld.sort_order, cld.deadline_utc, l.title
         FROM cohort_lesson_deadlines cld
         INNER JOIN lessons l ON l.id = cld.lesson_id
         WHERE cld.cohort_id = ?
         ORDER BY cld.sort_order ASC, cld.id ASC"
    );
    $lessonOrderStmt->execute(array($selectedCohortId));
    $lessonOrderRows = $lessonOrderStmt->fetchAll(PDO::FETCH_ASSOC);
    $totalLessons = count($lessonOrderRows);
    $lessonOrderMap = array();
    $lessonTitleMap = array();
    foreach ($lessonOrderRows as $lr) {
        $lessonOrderMap[(int)$lr['lesson_id']] = (int)$lr['sort_order'];
        $lessonTitleMap[(int)$lr['lesson_id']] = (string)$lr['title'];
    }

    $studentStmt = $pdo->prepare(
        "SELECT u.id AS user_id, u.name, u.first_name, u.last_name, u.email, u.photo_path
         FROM cohort_students cs
         INNER JOIN users u ON u.id = cs.user_id
         WHERE cs.cohort_id = ? AND u.role = 'student' AND u.status = 'active'
         ORDER BY COALESCE(NULLIF(TRIM(u.name), ''), CONCAT(TRIM(COALESCE(u.first_name,'')), ' ', TRIM(COALESCE(u.last_name,'')))) ASC, u.id ASC"
    );
    $studentStmt->execute(array($selectedCohortId));
    $studentRows = $studentStmt->fetchAll(PDO::FETCH_ASSOC);
    $userIds = array_map(static fn($r) => (int)$r['user_id'], $studentRows);

    $lessonActivityByUser = array();
    $summariesByUserLesson = array();
    $testsByUser = array();
    $actionsByUser = array();
    $latestSummaryByUser = array();

    if ($userIds) {
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));

        $laStmt = $pdo->prepare(
            "SELECT *
             FROM lesson_activity
             WHERE cohort_id = ? AND user_id IN ($placeholders)"
        );
        $laStmt->execute(array_merge(array($selectedCohortId), $userIds));
        while ($row = $laStmt->fetch(PDO::FETCH_ASSOC)) {
            $uid = (int)$row['user_id'];
            $lid = (int)$row['lesson_id'];
            $lessonActivityByUser[$uid][$lid] = $row;
        }

        $lsStmt = $pdo->prepare(
            "SELECT ls.*
             FROM lesson_summaries ls
             INNER JOIN (
                SELECT user_id, cohort_id, lesson_id, MAX(id) AS max_id
                FROM lesson_summaries
                WHERE cohort_id = ? AND user_id IN ($placeholders)
                GROUP BY user_id, cohort_id, lesson_id
             ) latest ON latest.max_id = ls.id"
        );
        $lsStmt->execute(array_merge(array($selectedCohortId), $userIds));
        while ($row = $lsStmt->fetch(PDO::FETCH_ASSOC)) {
            $uid = (int)$row['user_id'];
            $lid = (int)$row['lesson_id'];
            $summariesByUserLesson[$uid][$lid] = $row;
            if (!isset($latestSummaryByUser[$uid]) || strtotime((string)$row['updated_at']) > strtotime((string)$latestSummaryByUser[$uid]['updated_at'])) {
                $latestSummaryByUser[$uid] = $row;
            }
        }

        $ptStmt = $pdo->prepare(
            "SELECT *
             FROM progress_tests_v2
             WHERE cohort_id = ? AND user_id IN ($placeholders)
             ORDER BY user_id ASC, lesson_id ASC, attempt DESC, id DESC"
        );
        $ptStmt->execute(array_merge(array($selectedCohortId), $userIds));
        while ($row = $ptStmt->fetch(PDO::FETCH_ASSOC)) {
            $uid = (int)$row['user_id'];
            $testsByUser[$uid][] = $row;
        }

        $sraStmt = $pdo->prepare(
            "SELECT *
             FROM student_required_actions
             WHERE cohort_id = ? AND user_id IN ($placeholders)
             ORDER BY user_id ASC, lesson_id ASC, created_at DESC, id DESC"
        );
        $sraStmt->execute(array_merge(array($selectedCohortId), $userIds));
        while ($row = $sraStmt->fetch(PDO::FETCH_ASSOC)) {
            $uid = (int)$row['user_id'];
            $actionsByUser[$uid][] = $row;
        }
    }

    foreach ($studentRows as $studentRow) {
        $uid = (int)$studentRow['user_id'];
        $studentName = trim((string)($studentRow['name'] ?? ''));
        if ($studentName === '') {
            $studentName = trim((string)($studentRow['first_name'] ?? '') . ' ' . (string)($studentRow['last_name'] ?? ''));
        }
        if ($studentName === '') {
            $studentName = 'Student #' . $uid;
        }

        $laRows = $lessonActivityByUser[$uid] ?? array();
        $studentTests = $testsByUser[$uid] ?? array();
        $studentActions = $actionsByUser[$uid] ?? array();

        $completedCount = 0;
        $lastCompletedOrder = 0;
        $lastCompletedLessonId = 0;
        $currentLessonId = 0;
        $overdueCount = 0;
        $dueSoonCount = 0;
        $extensionCount = 0;
        $trainingSuspendedCount = 0;
        $oneOnOneRequiredCount = 0;
        $oneOnOneCompletedCount = 0;
        $bestScore = null;
        $latestActivityTs = 0;

        foreach ($lessonOrderRows as $lessonRow) {
            $lessonId = (int)$lessonRow['lesson_id'];
            $sortOrder = (int)$lessonRow['sort_order'];
            $deadlineUtc = (string)$lessonRow['deadline_utc'];
            $la = $laRows[$lessonId] ?? null;
            $completionStatus = trim((string)($la['completion_status'] ?? ''));

            if ($la) {
                $latestActivityTs = max($latestActivityTs, strtotime((string)($la['updated_at'] ?? '')) ?: 0);
                if ((int)($la['training_suspended'] ?? 0) === 1) {
                    $trainingSuspendedCount++;
                }
                if ((int)($la['one_on_one_required'] ?? 0) === 1) {
                    $oneOnOneRequiredCount++;
                }
                if ((int)($la['one_on_one_completed'] ?? 0) === 1) {
                    $oneOnOneCompletedCount++;
                }
                $extensionCount += (int)($la['extension_count'] ?? 0);
                if ($la['best_score'] !== null) {
                    $bestScore = $bestScore === null ? (float)$la['best_score'] : max($bestScore, (float)$la['best_score']);
                }
            }

            if ($completionStatus === 'completed') {
                $completedCount++;
                $lastCompletedOrder = max($lastCompletedOrder, $sortOrder);
                if ($sortOrder === $lastCompletedOrder) {
                    $lastCompletedLessonId = $lessonId;
                }
            } elseif ($currentLessonId === 0) {
                $currentLessonId = $lessonId;
            }

            $bucket = cp_deadline_bucket($deadlineUtc, $completionStatus);
            if ($bucket === 'overdue') {
                $overdueCount++;
            } elseif ($bucket === 'soon') {
                $dueSoonCount++;
            }
        }

        if ($currentLessonId === 0 && !empty($lessonOrderRows)) {
            $currentLessonId = (int)$lessonOrderRows[count($lessonOrderRows) - 1]['lesson_id'];
        }

        $completedTests = array();
        $failedAttemptCount = 0;
        $latestCompletedTest = null;
        foreach ($studentTests as $test) {
            $latestActivityTs = max($latestActivityTs, strtotime((string)($test['updated_at'] ?? '')) ?: 0);
            if ((string)$test['status'] === 'completed') {
                $completedTests[] = $test;
                if ($latestCompletedTest === null) {
                    $latestCompletedTest = $test;
                }
                if ((int)($test['pass_gate_met'] ?? 0) !== 1) {
                    $failedAttemptCount++;
                }
            }
        }

        $avgPt = null;
        $lastPt = null;
        $passCount = 0;
        if ($completedTests) {
            $sum = 0.0;
            foreach ($completedTests as $t) {
                $sum += (float)($t['score_pct'] ?? 0.0);
                if ((int)($t['pass_gate_met'] ?? 0) === 1) {
                    $passCount++;
                }
            }
            $avgPt = round($sum / count($completedTests), 1);
            $lastPt = (float)($latestCompletedTest['score_pct'] ?? 0.0);
        }

        $latestSummary = $latestSummaryByUser[$uid] ?? null;
        if ($latestSummary) {
            $latestActivityTs = max($latestActivityTs, strtotime((string)($latestSummary['updated_at'] ?? '')) ?: 0);
        }

        $pendingActionCount = 0;
        $pendingInstructorCount = 0;
        $pendingRemediationCount = 0;
        foreach ($studentActions as $action) {
            if (in_array((string)$action['status'], array('pending', 'opened'), true)) {
                $pendingActionCount++;
                if ((string)$action['action_type'] === 'instructor_approval') {
                    $pendingInstructorCount++;
                }
                if ((string)$action['action_type'] === 'remediation_acknowledgement') {
                    $pendingRemediationCount++;
                }
            }
        }

        $progressPercent = $totalLessons > 0 ? cp_percent(($completedCount / $totalLessons) * 100.0) : 0;
        $currentOrder = $currentLessonId > 0 ? (int)($lessonOrderMap[$currentLessonId] ?? 0) : 0;
        $lastCompletedTitle = $lastCompletedLessonId > 0 ? ($lessonTitleMap[$lastCompletedLessonId] ?? '') : '';
        $currentTitle = $currentLessonId > 0 ? ($lessonTitleMap[$currentLessonId] ?? '') : '';
        $inactiveDays = $latestActivityTs > 0 ? (int)floor((time() - $latestActivityTs) / 86400) : 999;
        $ptPassRate = count($completedTests) > 0 ? (($passCount / count($completedTests)) * 100.0) : 0.0;
        $latestTestAttempt = $latestCompletedTest ? (int)($latestCompletedTest['attempt'] ?? 0) : 0;
        $latestTestLessonTitle = $latestCompletedTest ? ($lessonTitleMap[(int)$latestCompletedTest['lesson_id']] ?? '') : '';
        $latestSummaryStatus = $latestSummary ? (string)($latestSummary['review_status'] ?? '') : '';

        $student = array(
            'user_id' => $uid,
            'cohort_id' => $selectedCohortId,
            'name' => $studentName,
            'photo_path' => (string)($studentRow['photo_path'] ?? ''),
            'progress_percent' => $progressPercent,
            'completed_count' => $completedCount,
            'total_lessons' => $totalLessons,
            'last_completed_order' => $lastCompletedOrder,
            'last_completed_title' => $lastCompletedTitle,
            'current_order' => $currentOrder,
            'current_title' => $currentTitle,
            'overdue_count' => $overdueCount,
            'due_soon_count' => $dueSoonCount,
            'extension_count' => $extensionCount,
            'training_suspended_count' => $trainingSuspendedCount,
            'one_on_one_required_count' => $oneOnOneRequiredCount,
            'one_on_one_completed_count' => $oneOnOneCompletedCount,
            'avg_pt_score' => $avgPt,
            'last_pt_score' => $lastPt,
            'pt_pass_rate' => $ptPassRate,
            'latest_test_attempt' => $latestTestAttempt,
            'latest_test_lesson_title' => $latestTestLessonTitle,
            'failed_attempts_count' => $failedAttemptCount,
            'pending_action_count' => $pendingActionCount,
            'pending_instructor_count' => $pendingInstructorCount,
            'pending_remediation_count' => $pendingRemediationCount,
            'latest_summary_status' => $latestSummaryStatus,
            'inactive_days' => $inactiveDays,
            'latest_activity_at' => $latestActivityTs > 0 ? gmdate('Y-m-d H:i:s', $latestActivityTs) : '',
        );

        $motivation = cp_motivation_score($student);
        $risk = cp_risk_meta($student, $motivation);
        $flags = cp_attention_flags($student);
        $student['motivation'] = $motivation;
        $student['risk'] = $risk;
        $student['flags'] = $flags;
        $student['route_segments'] = cp_route_marker_segments($totalLessons, $lastCompletedOrder, $currentOrder, $overdueCount, $pendingActionCount);
        $student['latest_attempt_summary'] = cp_latest_attempt_summary($student);

        $students[] = $student;
        $distribution[] = array(
            'user_id' => $uid,
            'name' => $studentName,
            'photo_path' => (string)($studentRow['photo_path'] ?? ''),
            'position_percent' => $totalLessons > 0 ? (($lastCompletedOrder > 0 ? $lastCompletedOrder : 1) / $totalLessons) * 100.0 : 0,
            'last_completed_order' => $lastCompletedOrder,
            'current_order' => $currentOrder,
            'risk_class' => $risk['class'],
            'last_completed_title' => $lastCompletedTitle,
            'current_title' => $currentTitle,
        );

        $summary['student_count']++;
        $summary['avg_progress'] += $progressPercent;
        $summary['avg_pt'] += (float)($avgPt ?? 0);
        $summary['overdue'] += $overdueCount > 0 ? 1 : 0;
        $summary['pending'] += $pendingActionCount > 0 ? 1 : 0;
        if ($risk['class'] === 'critical' || $risk['class'] === 'high') {
            $summary['urgent']++;
        } elseif ($risk['class'] === 'medium' || !empty($flags)) {
            $summary['attention']++;
        }
    }

    usort($students, function (array $a, array $b): int {
        $riskOrder = array('critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1);
        $cmp = ($riskOrder[$b['risk']['class']] ?? 0) <=> ($riskOrder[$a['risk']['class']] ?? 0);
        if ($cmp !== 0) {
            return $cmp;
        }
        $cmp = ((int)$b['overdue_count']) <=> ((int)$a['overdue_count']);
        if ($cmp !== 0) {
            return $cmp;
        }
        $cmp = ((int)$a['progress_percent']) <=> ((int)$b['progress_percent']);
        if ($cmp !== 0) {
            return $cmp;
        }
        return strcasecmp((string)$a['name'], (string)$b['name']);
    });

    if ($summary['student_count'] > 0) {
        $summary['avg_progress'] = round($summary['avg_progress'] / $summary['student_count']);
        $summary['avg_pt'] = round($summary['avg_pt'] / $summary['student_count'], 1);
    }
}

$totalStudents = count($students);
$totalPages = max(1, (int)ceil($totalStudents / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;
$visibleStudents = array_slice($students, $offset, $perPage);

cw_header('Cohort Command Board');
?>
<style>
.cp-page{display:flex;flex-direction:column;gap:14px;padding-bottom:18px}
.cp-card{background:#fff;border:1px solid rgba(15,23,42,.08);border-radius:22px;box-shadow:0 14px 30px rgba(15,23,42,.06)}
.cp-toolbar{display:grid;grid-template-columns:minmax(0,1fr) auto auto;gap:12px;align-items:end;padding:18px 20px}
.cp-toolbar .field{display:flex;flex-direction:column;gap:6px}.cp-toolbar label{font-size:12px;font-weight:800;color:#12355f;letter-spacing:.04em;text-transform:uppercase}
.cp-select,.cp-btn{min-height:44px;border-radius:14px;font:inherit}.cp-select{padding:10px 12px;border:1px solid rgba(15,23,42,.12);background:#fff;color:#102845}
.cp-btn{display:inline-flex;align-items:center;justify-content:center;padding:0 14px;border:1px solid #12355f;background:#12355f;color:#fff;text-decoration:none;font-weight:800;cursor:pointer}.cp-btn.secondary{background:#fff;color:#12355f}
.cp-hero{padding:20px 22px;background:linear-gradient(135deg,#0f2745 0%,#1d4f89 100%);color:#fff}
.cp-hero-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start}
.cp-kicker{font-size:11px;text-transform:uppercase;letter-spacing:.16em;font-weight:900;color:rgba(255,255,255,.72)}
.cp-hero h1{margin:6px 0 0;font-size:30px;line-height:1.02;letter-spacing:-.04em;color:#fff}
.cp-hero-sub{margin-top:8px;font-size:13px;line-height:1.55;color:rgba(255,255,255,.84);max-width:760px}
.cp-summary{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin-top:16px}
.cp-metric{background:rgba(255,255,255,.10);border:1px solid rgba(255,255,255,.12);border-radius:16px;padding:12px 14px}
.cp-metric .label{font-size:10px;text-transform:uppercase;letter-spacing:.12em;font-weight:800;color:rgba(255,255,255,.7)}
.cp-metric .value{margin-top:5px;font-size:24px;line-height:1;font-weight:900;color:#fff;letter-spacing:-.04em}
.cp-metric .sub{margin-top:6px;font-size:11px;color:rgba(255,255,255,.72)}
.cp-distribution{padding:14px 18px}
.cp-distribution-head{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:10px}
.cp-distribution-head h2{margin:0;font-size:18px;font-weight:900;color:#102845}
.cp-legend{display:flex;gap:8px;flex-wrap:wrap}.cp-chip{display:inline-flex;align-items:center;gap:6px;padding:0 10px;min-height:26px;border-radius:999px;font-size:11px;font-weight:800;border:1px solid rgba(15,23,42,.08);background:#f8fafc;color:#334155}.cp-chip .dot{width:8px;height:8px;border-radius:999px;display:inline-block}.dot.low{background:#22c55e}.dot.medium{background:#f59e0b}.dot.high{background:#ef4444}.dot.critical{background:#991b1b}.dot.ext{background:#3b82f6}
.cp-axis-wrap{position:relative;padding:30px 12px 18px}.cp-axis{position:relative;height:72px;border-radius:18px;background:linear-gradient(180deg,#f8fbff 0%,#eef4fb 100%);border:1px solid rgba(15,23,42,.06);overflow:hidden}
.cp-axis-line{position:absolute;left:18px;right:18px;top:38px;height:4px;border-radius:999px;background:linear-gradient(90deg,#12355f 0%,#3f7dd7 100%)}
.cp-axis-ticks{position:absolute;left:18px;right:18px;top:22px;display:grid;grid-template-columns:repeat(5,1fr);font-size:10px;font-weight:800;color:#64748b}.cp-axis-ticks span:last-child{text-align:right}.cp-axis-ticks span:nth-child(n+2):not(:last-child){text-align:center}
.cp-marker{position:absolute;top:26px;transform:translateX(-50%);display:flex;flex-direction:column;align-items:center;gap:4px;cursor:pointer}
.cp-marker .avatar{width:28px;height:28px;border-radius:999px;border:2px solid #fff;box-shadow:0 8px 18px rgba(15,23,42,.18);overflow:hidden;background:#dbe7f4}
.cp-marker .avatar img{width:100%;height:100%;object-fit:cover;display:block}.cp-marker .avatar span{display:flex;align-items:center;justify-content:center;width:100%;height:100%;color:#6b7f98}.cp-marker.low .avatar{outline:2px solid #22c55e}.cp-marker.medium .avatar{outline:2px solid #f59e0b}.cp-marker.high .avatar{outline:2px solid #ef4444}.cp-marker.critical .avatar{outline:2px solid #991b1b}
.cp-marker-label{font-size:10px;font-weight:900;color:#102845;background:rgba(255,255,255,.92);padding:2px 6px;border-radius:999px;box-shadow:0 6px 14px rgba(15,23,42,.08)}
.cp-board{padding:12px 14px 14px}
.cp-board-grid{display:grid;grid-template-columns:210px 220px 110px 130px 128px 110px 92px 124px;gap:10px;align-items:center}
.cp-board-head{padding:0 8px 8px;border-bottom:1px solid rgba(15,23,42,.08);font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.12em;color:#64748b}
.cp-row{padding:10px 8px;border-bottom:1px solid rgba(15,23,42,.06);min-height:72px}.cp-row:last-child{border-bottom:0}
.cp-student{display:flex;align-items:center;gap:10px;min-width:0}.cp-avatar{border-radius:16px;overflow:hidden;background:linear-gradient(180deg,#e8eef7 0%,#dfe7f2 100%);border:1px solid rgba(15,23,42,.07);display:flex;align-items:center;justify-content:center}.cp-avatar img{width:100%;height:100%;object-fit:cover;display:block}.cp-avatar-fallback{width:20px;height:20px;color:#6d7f95}.cp-avatar-fallback svg{width:100%;height:100%;display:block}
.cp-student-copy{min-width:0}.cp-name{font-size:15px;font-weight:900;color:#102845;line-height:1.1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.cp-sub{font-size:11px;color:#64748b;line-height:1.35;margin-top:4px}
.cp-route{display:flex;flex-direction:column;gap:8px}.cp-track{display:grid;grid-template-columns:repeat(10,1fr);gap:4px}.cp-seg{height:10px;border-radius:999px;background:#d9e5f2}.cp-seg.done{background:linear-gradient(90deg,#12355f 0%,#2b6dcc 100%)}.cp-seg.current{background:#22c55e}.cp-seg.warning{background:#f59e0b}.cp-seg.danger{background:#ef4444}.cp-route-meta{display:flex;justify-content:space-between;gap:8px;font-size:11px;color:#64748b}.cp-route-current{font-weight:800;color:#102845;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cp-center{display:flex;flex-direction:column;align-items:center;gap:6px}.cp-big{font-size:18px;font-weight:900;color:#102845;line-height:1}.cp-small{font-size:11px;color:#64748b;text-align:center;line-height:1.3}.cp-progress-count{font-weight:800;color:#102845}
.cp-deadline-stack{display:flex;gap:6px;justify-content:center;flex-wrap:wrap}.cp-mini-pill{display:inline-flex;align-items:center;justify-content:center;min-width:34px;min-height:26px;padding:0 8px;border-radius:999px;font-size:11px;font-weight:900}.cp-mini-pill.red{background:#fee2e2;color:#991b1b}.cp-mini-pill.amber{background:#fef3c7;color:#92400e}.cp-mini-pill.blue{background:#dbeafe;color:#1d4ed8}
.cp-motivation{display:flex;flex-direction:column;gap:6px}.cp-motivation-top{display:flex;justify-content:center;gap:4px;font-size:16px;font-weight:900;color:#102845}.cp-motivation-bar{height:10px;border-radius:999px;background:#e5eef7;overflow:hidden}.cp-motivation-fill{height:100%;background:linear-gradient(90deg,#ef4444 0%,#f59e0b 42%,#84cc16 72%,#22c55e 100%);border-radius:999px}
.cp-risk{display:inline-flex;align-items:center;justify-content:center;min-height:34px;padding:0 10px;border-radius:999px;font-size:12px;font-weight:900;border:1px solid transparent}.cp-risk.low{background:#ecfdf5;color:#166534;border-color:#bbf7d0}.cp-risk.medium{background:#fef3c7;color:#92400e;border-color:#fcd34d}.cp-risk.high{background:#fee2e2;color:#991b1b;border-color:#fca5a5}.cp-risk.critical{background:#7f1d1d;color:#fff;border-color:#991b1b}
.cp-actions{display:flex;align-items:center;justify-content:flex-end;gap:8px}.cp-icon-flag{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:12px;font-size:16px;background:#f8fafc;border:1px solid rgba(15,23,42,.08);cursor:default}.cp-icon-flag.danger{background:#fee2e2}.cp-icon-flag.warning{background:#fef3c7}.cp-icon-flag.info{background:#dbeafe}.cp-icon-btn{display:inline-flex;align-items:center;justify-content:center;min-width:40px;height:40px;border-radius:14px;border:1px solid rgba(15,23,42,.10);background:#fff;color:#12355f;font-size:16px;font-weight:900;cursor:pointer}.cp-icon-btn.primary{background:#12355f;color:#fff;border-color:#12355f}
.cp-footer{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 18px;border-top:1px solid rgba(15,23,42,.08)}.cp-pagination{display:flex;gap:8px;align-items:center}.cp-page-pill{display:inline-flex;align-items:center;justify-content:center;min-width:36px;height:36px;border-radius:12px;border:1px solid rgba(15,23,42,.1);background:#fff;color:#12355f;font-weight:900;text-decoration:none}.cp-page-pill.active{background:#12355f;color:#fff;border-color:#12355f}
.cp-modal-backdrop{position:fixed;inset:0;background:rgba(7,18,32,.58);display:none;align-items:flex-end;justify-content:center;z-index:9999;padding:18px}.cp-modal-backdrop.open{display:flex}.cp-modal-shell{width:min(1320px,100%);max-height:calc(100vh - 36px);background:#fff;border-radius:28px;box-shadow:0 28px 80px rgba(15,23,42,.35);overflow:hidden;display:flex;flex-direction:column}.cp-modal-body{overflow:auto;padding:18px 20px 22px;background:#f8fbff}
.cp-modal-header{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:20px 22px;background:linear-gradient(135deg,#0f2745 0%,#1d4f89 100%);color:#fff}.cp-modal-student{display:flex;gap:14px;align-items:center}.cp-modal-student-copy h2{margin:4px 0 0;font-size:28px;line-height:1.04;letter-spacing:-.04em;color:#fff}.cp-modal-kicker{font-size:11px;text-transform:uppercase;letter-spacing:.14em;font-weight:900;color:rgba(255,255,255,.72)}.cp-modal-meta{margin-top:8px;font-size:13px;color:rgba(255,255,255,.84)}.cp-modal-close{min-height:42px;padding:0 14px;border-radius:14px;border:1px solid rgba(255,255,255,.2);background:rgba(255,255,255,.12);color:#fff;font:inherit;font-weight:900;cursor:pointer}
.cp-modal-section{background:#fff;border:1px solid rgba(15,23,42,.08);border-radius:22px;padding:16px 18px}.cp-modal-section h3{margin:0 0 12px;font-size:18px;font-weight:900;color:#102845}.cp-lesson-list{display:flex;flex-direction:column;gap:12px}.cp-lesson-item{border:1px solid rgba(15,23,42,.08);border-radius:18px;background:#fff}.cp-lesson-item summary{list-style:none;display:flex;justify-content:space-between;gap:14px;align-items:center;padding:14px 16px;cursor:pointer}.cp-lesson-item summary::-webkit-details-marker{display:none}.cp-lesson-summary-left{display:flex;gap:12px;align-items:center;min-width:0}.cp-lesson-index{min-width:42px;height:42px;border-radius:14px;background:#eff6ff;color:#1d4ed8;display:flex;align-items:center;justify-content:center;font-weight:900}.cp-lesson-title{font-size:15px;font-weight:900;color:#102845}.cp-lesson-sub{margin-top:5px;font-size:12px;color:#64748b}.cp-lesson-summary-right{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}.cp-badge{display:inline-flex;align-items:center;justify-content:center;min-height:28px;padding:0 10px;border-radius:999px;font-size:11px;font-weight:900;border:1px solid rgba(15,23,42,.08);background:#f8fafc;color:#334155}.cp-badge.ok{background:#ecfdf5;color:#166534;border-color:#bbf7d0}.cp-badge.warning{background:#fef3c7;color:#92400e;border-color:#fcd34d}.cp-badge.danger{background:#fee2e2;color:#991b1b;border-color:#fca5a5}.cp-badge.info{background:#dbeafe;color:#1d4ed8;border-color:#93c5fd}.cp-badge.neutral{background:#f8fafc;color:#334155}.cp-lesson-body{padding:0 16px 16px;display:flex;flex-direction:column;gap:12px}.cp-subpanel{border:1px solid rgba(15,23,42,.07);border-radius:18px;padding:14px;background:#fff}.cp-subpanel h4{margin:0 0 10px;font-size:14px;font-weight:900;color:#102845}.cp-action-list,.cp-attempt-list{display:flex;flex-direction:column;gap:10px}.cp-action-row{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;padding:10px 12px;border-radius:14px;background:#f8fafc}.cp-action-meta{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}.cp-attempt-card{border:1px solid rgba(15,23,42,.08);border-radius:16px}.cp-attempt-card summary{list-style:none;display:flex;justify-content:space-between;align-items:center;padding:12px 14px;cursor:pointer}.cp-attempt-card summary::-webkit-details-marker{display:none}.cp-attempt-summary-right{display:flex;gap:8px;flex-wrap:wrap}.cp-attempt-body{padding:0 14px 14px;display:flex;flex-direction:column;gap:12px}.cp-attempt-text{font-size:13px;line-height:1.55;color:#334155}.cp-attempt-text strong{display:block;margin-bottom:4px;color:#102845}.cp-item-table-wrap{overflow:auto}.cp-item-table{width:100%;border-collapse:collapse;font-size:12px}.cp-item-table th,.cp-item-table td{padding:10px 8px;border-bottom:1px solid rgba(15,23,42,.07);text-align:left;vertical-align:top}.cp-item-table th{font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#64748b}.cp-item-table audio{width:200px;max-width:100%}.cp-empty{padding:16px;border-radius:16px;background:#f8fafc;color:#64748b;font-size:13px;text-align:center}.cp-muted{font-size:12px;color:#64748b;line-height:1.45}
@media (max-width: 1280px){.cp-board-grid{grid-template-columns:190px 205px 100px 120px 120px 100px 86px 112px}.cp-summary{grid-template-columns:repeat(5,minmax(0,1fr))}}
@media (max-width: 1100px){.cp-summary{grid-template-columns:repeat(3,minmax(0,1fr))}.cp-board{overflow-x:auto}.cp-board-grid{min-width:1160px}}
</style>

<?php
$cohortTitle = $selectedCohort ? (string)($selectedCohort['name'] ?? 'Cohort') : 'No Cohort Selected';
$courseTitle = $selectedCohort ? (string)($selectedCohort['course_title'] ?? '') : '';
?>
<div class="cp-page">
    <section class="cp-card cp-hero">
        <div class="cp-hero-head">
            <div>
                <div class="cp-kicker">IPCA Cohort Command Board</div>
                <h1><?= cp_h($cohortTitle) ?></h1>
                <div class="cp-hero-sub">At-a-glance operational comparison across the cohort with lesson-route position, progress, Progress Test health, deadline pressure, motivation, risk, and fast drill-down into lesson, attempt, audio, and remediation detail.</div>
            </div>
            <div class="cp-chip">No horizontal scroll · iPad landscape safe</div>
        </div>
        <div class="cp-summary">
            <div class="cp-metric"><div class="label">Students</div><div class="value"><?= (int)$summary['student_count'] ?></div><div class="sub"><?= cp_h($courseTitle) ?></div></div>
            <div class="cp-metric"><div class="label">Average Progress</div><div class="value"><?= (int)$summary['avg_progress'] ?>%</div><div class="sub">Lessons completed across the cohort</div></div>
            <div class="cp-metric"><div class="label">Average PT</div><div class="value"><?= $summary['avg_pt'] > 0 ? cp_h((string)$summary['avg_pt']) . '%' : '—' ?></div><div class="sub">Completed Progress Test results only</div></div>
            <div class="cp-metric"><div class="label">Urgent</div><div class="value"><?= (int)$summary['urgent'] ?></div><div class="sub">High / critical students</div></div>
            <div class="cp-metric"><div class="label">Attention</div><div class="value"><?= (int)$summary['attention'] ?></div><div class="sub">Medium risk or flagged students</div></div>
        </div>
    </section>

    <section class="cp-card">
        <form class="cp-toolbar" method="get" action="">
            <div class="field">
                <label for="cohort_id">Cohort</label>
                <select class="cp-select" name="cohort_id" id="cohort_id" onchange="this.form.submit()">
                    <?php foreach ($cohorts as $cohort): ?>
                        <option value="<?= (int)$cohort['id'] ?>" <?= (int)$cohort['id'] === $selectedCohortId ? 'selected' : '' ?>>
                            <?= cp_h((string)$cohort['name']) ?> · <?= cp_h((string)$cohort['course_title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <label class="cp-chip" style="align-self:center;margin-bottom:3px;cursor:pointer;">
                <input type="checkbox" name="show_retired" value="1" <?= $showRetired ? 'checked' : '' ?> onchange="this.form.submit()" style="margin-right:8px;"> Show retired
            </label>
            <a class="cp-btn secondary" href="/instructor/cohort_progress_overview.php?cohort_id=<?= (int)$selectedCohortId ?>">Open legacy action board</a>
        </form>
    </section>

    <section class="cp-card cp-distribution">
        <div class="cp-distribution-head">
            <h2>Cohort Distribution Line</h2>
            <div class="cp-legend">
                <span class="cp-chip"><span class="dot low"></span> Low risk</span>
                <span class="cp-chip"><span class="dot medium"></span> Medium risk</span>
                <span class="cp-chip"><span class="dot high"></span> High risk</span>
                <span class="cp-chip"><span class="dot critical"></span> Critical</span>
                <span class="cp-chip"><span class="dot ext"></span> Extension history</span>
            </div>
        </div>
        <div class="cp-axis-wrap">
            <div class="cp-axis">
                <div class="cp-axis-ticks">
                    <span>Lesson 1</span>
                    <span><?= max(1, (int)round($totalLessons * 0.25)) ?></span>
                    <span><?= max(1, (int)round($totalLessons * 0.50)) ?></span>
                    <span><?= max(1, (int)round($totalLessons * 0.75)) ?></span>
                    <span>Lesson <?= (int)$totalLessons ?></span>
                </div>
                <div class="cp-axis-line"></div>
                <?php foreach ($distribution as $marker):
                    $left = max(2.5, min(97.5, (float)$marker['position_percent']));
                    $displayName = (string)$marker['name'];
                    $photo = trim((string)$marker['photo_path']);
                    $tooltip = $displayName
                        . ' · Last completed L' . (int)$marker['last_completed_order']
                        . ($marker['last_completed_title'] !== '' ? ' (' . $marker['last_completed_title'] . ')' : '')
                        . ' · Active L' . (int)$marker['current_order']
                        . ($marker['current_title'] !== '' ? ' (' . $marker['current_title'] . ')' : '');
                    ?>
                    <button type="button"
                            class="cp-marker <?= cp_h((string)$marker['risk_class']) ?>"
                            style="left:<?= cp_h(number_format($left, 2, '.', '')) ?>%"
                            title="<?= cp_h($tooltip) ?>"
                            data-open-detail="1"
                            data-user-id="<?= (int)$marker['user_id'] ?>"
                            data-cohort-id="<?= (int)$selectedCohortId ?>">
                        <span class="avatar">
                            <?php if ($photo !== ''): ?>
                                <img src="<?= cp_h($photo) ?>" alt="<?= cp_h($displayName) ?>">
                            <?php else: ?>
                                <span><?= cp_svg_avatar() ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="cp-marker-label">L<?= (int)$marker['last_completed_order'] ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="cp-card cp-board">
        <div class="cp-board-grid cp-board-head">
            <div>Student</div>
            <div>Route</div>
            <div>Progress</div>
            <div>PT Health</div>
            <div>Deadlines</div>
            <div>Motivation</div>
            <div>Risk</div>
            <div>Actions</div>
        </div>
        <?php if (!$visibleStudents): ?>
            <div class="cp-empty" style="margin-top:12px;">No active students found for this cohort.</div>
        <?php else: ?>
            <?php foreach ($visibleStudents as $student): ?>
                <div class="cp-board-grid cp-row">
                    <div class="cp-student">
                        <?= cp_avatar_html(array('photo_path' => $student['photo_path']), (string)$student['name'], 44) ?>
                        <div class="cp-student-copy">
                            <div class="cp-name"><?= cp_h((string)$student['name']) ?></div>
                            <div class="cp-sub">Last completed: L<?= (int)$student['last_completed_order'] ?><?= $student['last_completed_title'] !== '' ? ' · ' . cp_h((string)$student['last_completed_title']) : '' ?></div>
                        </div>
                    </div>
                    <div class="cp-route">
                        <div class="cp-track">
                            <?php foreach ($student['route_segments'] as $seg): ?>
                                <span class="cp-seg <?= cp_h((string)$seg) ?>"></span>
                            <?php endforeach; ?>
                        </div>
                        <div class="cp-route-meta">
                            <span class="cp-route-current">Active: <?= $student['current_order'] > 0 ? 'L' . (int)$student['current_order'] : '—' ?><?= $student['current_title'] !== '' ? ' · ' . cp_h((string)$student['current_title']) : '' ?></span>
                            <span><?= (int)$student['last_completed_order'] ?>/<?= (int)$student['total_lessons'] ?></span>
                        </div>
                    </div>
                    <div class="cp-center">
                        <div class="cp-big"><?= (int)$student['progress_percent'] ?>%</div>
                        <div class="cp-small"><span class="cp-progress-count"><?= (int)$student['completed_count'] ?></span> / <?= (int)$student['total_lessons'] ?> lessons</div>
                    </div>
                    <div class="cp-center">
                        <div class="cp-big"><?= $student['avg_pt_score'] !== null ? cp_h((string)cp_percent((float)$student['avg_pt_score'])) . '%' : '—' ?></div>
                        <div class="cp-small"><?= cp_h((string)$student['latest_attempt_summary']) ?></div>
                    </div>
                    <div class="cp-center">
                        <div class="cp-deadline-stack">
                            <span class="cp-mini-pill red">🔴 <?= (int)$student['overdue_count'] ?></span>
                            <span class="cp-mini-pill amber">🟡 <?= (int)$student['due_soon_count'] ?></span>
                            <span class="cp-mini-pill blue">🔵 <?= (int)$student['extension_count'] ?></span>
                        </div>
                    </div>
                    <div class="cp-motivation">
                        <div class="cp-motivation-top"><?= (int)$student['motivation']['score'] ?>% <span><?= cp_h((string)$student['motivation']['trend']) ?></span></div>
                        <div class="cp-motivation-bar"><div class="cp-motivation-fill" style="width:<?= (int)$student['motivation']['score'] ?>%"></div></div>
                    </div>
                    <div class="cp-center">
                        <span class="cp-risk <?= cp_h((string)$student['risk']['class']) ?>"><?= cp_h((string)$student['risk']['label']) ?></span>
                    </div>
                    <div class="cp-actions">
                        <?php if (!empty($student['flags'])): ?>
                            <?php foreach (array_slice($student['flags'], 0, 2) as $flag): ?>
                                <span class="cp-icon-flag <?= cp_h((string)$flag['class']) ?>" title="<?= cp_h((string)$flag['label']) ?>"><?= cp_h((string)$flag['icon']) ?></span>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span class="cp-icon-flag" title="No immediate attention required">✓</span>
                        <?php endif; ?>
                        <button type="button"
                                class="cp-icon-btn primary"
                                title="Open full student detail"
                                data-open-detail="1"
                                data-user-id="<?= (int)$student['user_id'] ?>"
                                data-cohort-id="<?= (int)$selectedCohortId ?>">▶</button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        <div class="cp-footer">
            <div class="cp-muted">Showing <?= $totalStudents > 0 ? ($offset + 1) : 0 ?>–<?= min($offset + $perPage, $totalStudents) ?> of <?= $totalStudents ?> students · tuned for iPad landscape and standard laptop without horizontal scrolling.</div>
            <div class="cp-pagination">
                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <a class="cp-page-pill <?= $p === $page ? 'active' : '' ?>" href="?cohort_id=<?= (int)$selectedCohortId ?>&page=<?= $p ?>&show_retired=<?= $showRetired ?>"><?= $p ?></a>
                <?php endfor; ?>
            </div>
        </div>
    </section>
</div>

<div class="cp-modal-backdrop" id="cpDetailModal">
    <div class="cp-modal-shell">
        <div class="cp-modal-body" id="cpDetailBody">
            <div class="cp-empty">Loading student detail…</div>
        </div>
    </div>
</div>

<script>
(function(){
    var modal = document.getElementById('cpDetailModal');
    var modalBody = document.getElementById('cpDetailBody');
    if (!modal || !modalBody) return;

    function closeModal() {
        modal.classList.remove('open');
        modalBody.innerHTML = '<div class="cp-empty">Loading student detail…</div>';
        document.body.style.overflow = '';
    }

    function bindModalClose() {
        var closeBtn = modalBody.querySelector('[data-close-modal]');
        if (closeBtn) {
            closeBtn.addEventListener('click', closeModal);
        }
    }

    function openDetail(userId, cohortId) {
        modal.classList.add('open');
        document.body.style.overflow = 'hidden';
        modalBody.innerHTML = '<div class="cp-empty">Loading student detail…</div>';
        var url = '?ajax=student_detail&cohort_id=' + encodeURIComponent(cohortId) + '&user_id=' + encodeURIComponent(userId);
        fetch(url, { credentials: 'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(payload){
                if (!payload || !payload.ok) {
                    modalBody.innerHTML = '<div class="cp-empty">Unable to load student detail.</div>';
                    return;
                }
                modalBody.innerHTML = payload.html || '<div class="cp-empty">No detail available.</div>';
                bindModalClose();
            })
            .catch(function(){
                modalBody.innerHTML = '<div class="cp-empty">Unable to load student detail.</div>';
            });
    }

    document.addEventListener('click', function(e){
        var trigger = e.target.closest('[data-open-detail="1"]');
        if (trigger) {
            openDetail(trigger.getAttribute('data-user-id'), trigger.getAttribute('data-cohort-id'));
            return;
        }
        if (e.target === modal) {
            closeModal();
        }
    });

    document.addEventListener('keydown', function(e){
        if (e.key === 'Escape' && modal.classList.contains('open')) {
            closeModal();
        }
    });
})();
</script>

<?php cw_footer(); ?>
