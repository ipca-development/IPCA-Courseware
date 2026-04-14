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

function ptr_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function ptr_svg_users(): string
{
    return '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true">'
        . '<path d="M12 12c2.761 0 5-2.462 5-5.5S14.761 1 12 1 7 3.462 7 6.5 9.239 12 12 12Z" fill="currentColor" opacity=".88"/>'
        . '<path d="M3.5 22c.364-4.157 4.006-7 8.5-7s8.136 2.843 8.5 7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>'
        . '</svg>';
}

function ptr_avatar_html(array $user, string $displayName, string $size = '76'): string
{
    $photoPath = trim((string)($user['photo_path'] ?? ''));
    $sizePx = max(40, (int)$size);
    $radius = max(14, (int)round($sizePx * 0.29));
    $fallbackSize = max(20, (int)round($sizePx * 0.40));

    if ($photoPath !== '') {
        return '<div class="ptr-avatar" style="width:' . $sizePx . 'px;height:' . $sizePx . 'px;border-radius:' . $radius . 'px;flex:0 0 ' . $sizePx . 'px;">'
            . '<img src="' . ptr_h($photoPath) . '" alt="' . ptr_h($displayName) . '">'
            . '</div>';
    }

    return '<div class="ptr-avatar" style="width:' . $sizePx . 'px;height:' . $sizePx . 'px;border-radius:' . $radius . 'px;flex:0 0 ' . $sizePx . 'px;">'
        . '<span class="ptr-avatar-fallback" style="width:' . $fallbackSize . 'px;height:' . $fallbackSize . 'px;">' . ptr_svg_users() . '</span>'
        . '</div>';
}

function ptr_format_datetime(?string $dt): string
{
    $dt = trim((string)$dt);
    if ($dt === '') {
        return '—';
    }

    $ts = strtotime($dt);
    if ($ts === false) {
        return $dt;
    }

    return gmdate('D M j, Y', $ts) . ' · ' . gmdate('H:i:s', $ts) . ' UTC';
}

function ptr_format_short(?string $dt): string
{
    $dt = trim((string)$dt);
    if ($dt === '') {
        return '—';
    }

    $ts = strtotime($dt);
    if ($ts === false) {
        return $dt;
    }

    return gmdate('Y-m-d H:i:s', $ts);
}

function ptr_status_chip_class(string $status): string
{
    $status = strtolower(trim($status));
    if ($status === 'completed' || $status === 'passed' || $status === 'acceptable' || $status === 'approved') {
        return 'ok';
    }
    if ($status === 'ready' || $status === 'pending' || $status === 'opened' || $status === 'in_progress' || $status === 'unknown') {
        return 'info';
    }
    if ($status === 'needs_revision' || $status === 'warning') {
        return 'warning';
    }
    if ($status === 'failed' || $status === 'unsat' || $status === 'deadline_blocked' || $status === 'rejected' || $status === 'suspended') {
        return 'danger';
    }
    return 'neutral';
}

function ptr_bool_label(mixed $value): string
{
    return (int)$value === 1 ? 'Yes' : 'No';
}

function ptr_has_column(array $row, string $column): bool
{
    return array_key_exists($column, $row);
}

function ptr_first_non_empty(array $row, array $keys): string
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $row)) {
            $value = trim((string)$row[$key]);
            if ($value !== '') {
                return $value;
            }
        }
    }
    return '';
}

function ptr_first_numeric_or_null(array $row, array $keys): ?float
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
            return (float)$row[$key];
        }
    }
    return null;
}

function ptr_progress_fill_class(?float $score): string
{
    if ($score === null) {
        return 'neutral';
    }
    if ($score >= 75) {
        return 'good';
    }
    if ($score >= 60) {
        return 'amber';
    }
    return 'danger';
}

function ptr_clamped_percent(?float $score): int
{
    if ($score === null) {
        return 0;
    }
    if ($score < 0) {
        $score = 0;
    }
    if ($score > 100) {
        $score = 100;
    }
    return (int)round($score);
}

function ptr_csrf_token(): string
{
    if (!isset($_SESSION['ptr_csrf']) || !is_string($_SESSION['ptr_csrf']) || $_SESSION['ptr_csrf'] === '') {
        $_SESSION['ptr_csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['ptr_csrf'];
}

function ptr_verify_csrf(?string $token): bool
{
    $sessionToken = isset($_SESSION['ptr_csrf']) ? (string)$_SESSION['ptr_csrf'] : '';
    $token = (string)$token;
    return $sessionToken !== '' && $token !== '' && hash_equals($sessionToken, $token);
}

$cohortId = (int)($_GET['cohort_id'] ?? $_POST['cohort_id'] ?? 0);
$userId = (int)($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
$lessonId = (int)($_GET['lesson_id'] ?? $_POST['lesson_id'] ?? 0);

$flash = array();
$errors = array();

if ($cohortId <= 0 || $userId <= 0 || $lessonId <= 0) {
    http_response_code(400);
    cw_header('Progress Test Review');
    echo '<div class="container" style="padding:24px 0;">'
        . '<div class="card" style="padding:24px;">Missing required parameters: cohort_id, user_id, and lesson_id.</div>'
        . '</div>';
    cw_footer();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!ptr_verify_csrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token. Please refresh the page and try again.';
    } elseif (!in_array($currentRole, array('admin', 'supervisor', 'chief_instructor'), true)) {
        $errors[] = 'Only admin, supervisor, or chief instructor roles may run recovery actions.';
    } else {
        $action = trim((string)($_POST['action'] ?? ''));

        try {
            if ($action === 'refresh_projection_snapshot') {
                $activityStmt = $pdo->prepare("
                    SELECT *
                    FROM lesson_activity
                    WHERE user_id = ?
                      AND cohort_id = ?
                      AND lesson_id = ?
                    ORDER BY id DESC
                    LIMIT 1
                ");
                $activityStmt->execute(array($userId, $cohortId, $lessonId));
                $activityRow = $activityStmt->fetch(PDO::FETCH_ASSOC) ?: null;

                $testsStmt = $pdo->prepare("
                    SELECT *
                    FROM progress_tests_v2
                    WHERE user_id = ?
                      AND cohort_id = ?
                      AND lesson_id = ?
                    ORDER BY COALESCE(completed_at, created_at) ASC, id ASC
                ");
                $testsStmt->execute(array($userId, $cohortId, $lessonId));
                $tests = $testsStmt->fetchAll(PDO::FETCH_ASSOC);

                $summaryStmt = $pdo->prepare("
                    SELECT *
                    FROM lesson_summaries
                    WHERE user_id = ?
                      AND cohort_id = ?
                      AND lesson_id = ?
                    ORDER BY updated_at DESC, id DESC
                    LIMIT 1
                ");
                $summaryStmt->execute(array($userId, $cohortId, $lessonId));
                $summaryRow = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: null;

                $actionsStmt = $pdo->prepare("
                    SELECT *
                    FROM student_required_actions
                    WHERE user_id = ?
                      AND cohort_id = ?
                      AND lesson_id = ?
                      AND status IN ('pending', 'opened')
                    ORDER BY id DESC
                ");
                $actionsStmt->execute(array($userId, $cohortId, $lessonId));
                $actionRows = $actionsStmt->fetchAll(PDO::FETCH_ASSOC);

                $completedTests = array();
                $readyTests = array();
                $hasPass = false;

                foreach ($tests as $testRow) {
                    $status = strtolower(trim((string)($testRow['status'] ?? '')));
                    if ($status === 'completed') {
                        $completedTests[] = $testRow;
                        if ((int)($testRow['pass_gate_met'] ?? 0) === 1) {
                            $hasPass = true;
                        }
                    }
                    if ($status === 'ready') {
                        $readyTests[] = $testRow;
                    }
                }

                $summaryStatus = trim((string)($summaryRow['review_status'] ?? ''));
                $newSummaryStatus = $summaryStatus !== '' ? $summaryStatus : trim((string)($activityRow['summary_status'] ?? ''));
                if ($newSummaryStatus === '') {
                    $newSummaryStatus = 'pending';
                }

                $existingCompletionStatus = trim((string)($activityRow['completion_status'] ?? ''));
                $newCompletionStatus = $existingCompletionStatus !== '' ? $existingCompletionStatus : 'in_progress';

                $pendingInstructor = 0;
                $pendingRemediation = 0;
                $pendingReason = 0;

                foreach ($actionRows as $requiredAction) {
                    $type = trim((string)($requiredAction['action_type'] ?? ''));
                    if ($type === 'instructor_approval') {
                        $pendingInstructor++;
                    } elseif ($type === 'remediation_acknowledgement') {
                        $pendingRemediation++;
                    } elseif ($type === 'deadline_reason_submission') {
                        $pendingReason++;
                    }
                }

                $newTestPassStatus = trim((string)($activityRow['test_pass_status'] ?? ''));
                if ($hasPass) {
                    $newTestPassStatus = 'passed';
                } elseif (!empty($completedTests)) {
                    $newTestPassStatus = 'failed';
                } elseif (!empty($readyTests)) {
                    $newTestPassStatus = 'ready';
                } elseif ($newTestPassStatus === '') {
                    $newTestPassStatus = 'unknown';
                }

                if ($hasPass && $newSummaryStatus === 'acceptable') {
                    $newCompletionStatus = 'completed';
                } elseif ($existingCompletionStatus !== 'deadline_blocked') {
                    $newCompletionStatus = 'in_progress';
                }

                if (!$activityRow || empty($activityRow['id'])) {
                    throw new RuntimeException('No lesson_activity row exists yet for this student/lesson, so only diagnostics can be reviewed at this time.');
                }

                $updateStmt = $pdo->prepare("
                    UPDATE lesson_activity
                    SET completion_status = ?,
                        summary_status = ?,
                        test_pass_status = ?,
                        updated_at = NOW(),
                        last_state_eval_at = NOW()
                    WHERE id = ?
                    LIMIT 1
                ");
                $updateStmt->execute(array(
                    $newCompletionStatus,
                    $newSummaryStatus,
                    $newTestPassStatus,
                    (int)$activityRow['id'],
                ));

                $flash[] = 'Projection snapshot refreshed. Attempt history was preserved. This updated lesson_activity only.';
            } else {
                $errors[] = 'Unknown action.';
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$contextStmt = $pdo->prepare("
    SELECT
        u.id AS user_id,
        u.name AS student_name,
        u.first_name,
        u.last_name,
        u.email,
        u.photo_path,
        c.id AS cohort_id,
        c.name AS cohort_name,
        c.course_id,
        c.start_date,
        c.end_date,
        COALESCE(cr.title, CONCAT('Course #', c.course_id)) AS course_title,
        l.id AS lesson_id,
        l.title AS lesson_title
    FROM users u
    INNER JOIN cohort_students cs
        ON cs.user_id = u.id
       AND cs.cohort_id = ?
    INNER JOIN cohorts c
        ON c.id = cs.cohort_id
    LEFT JOIN courses cr
        ON cr.id = c.course_id
    INNER JOIN lessons l
        ON l.id = ?
       AND l.course_id = c.course_id
    WHERE u.id = ?
    LIMIT 1
");
$contextStmt->execute(array($cohortId, $lessonId, $userId));
$context = $contextStmt->fetch(PDO::FETCH_ASSOC) ?: null;

if (!$context) {
    http_response_code(404);
    cw_header('Progress Test Review');
    echo '<div class="container" style="padding:24px 0;">'
        . '<div class="card" style="padding:24px;">Student / cohort / lesson combination not found.</div>'
        . '</div>';
    cw_footer();
    exit;
}

$studentName = trim((string)($context['student_name'] ?? ''));
if ($studentName === '') {
    $studentName = trim((string)($context['first_name'] ?? '') . ' ' . (string)($context['last_name'] ?? ''));
}
if ($studentName === '') {
    $studentName = 'Student #' . $userId;
}

$testsStmt = $pdo->prepare("
    SELECT *
    FROM progress_tests_v2
    WHERE user_id = ?
      AND cohort_id = ?
      AND lesson_id = ?
    ORDER BY COALESCE(completed_at, created_at) ASC, id ASC
");
$testsStmt->execute(array($userId, $cohortId, $lessonId));
$progressTests = $testsStmt->fetchAll(PDO::FETCH_ASSOC);

$actionsStmt = $pdo->prepare("
    SELECT *
    FROM student_required_actions
    WHERE user_id = ?
      AND cohort_id = ?
      AND lesson_id = ?
    ORDER BY id DESC
");
$actionsStmt->execute(array($userId, $cohortId, $lessonId));
$requiredActions = $actionsStmt->fetchAll(PDO::FETCH_ASSOC);

$activityStmt = $pdo->prepare("
    SELECT *
    FROM lesson_activity
    WHERE user_id = ?
      AND cohort_id = ?
      AND lesson_id = ?
    ORDER BY id DESC
    LIMIT 1
");
$activityStmt->execute(array($userId, $cohortId, $lessonId));
$lessonActivity = $activityStmt->fetch(PDO::FETCH_ASSOC) ?: null;

$summaryStmt = $pdo->prepare("
    SELECT *
    FROM lesson_summaries
    WHERE user_id = ?
      AND cohort_id = ?
      AND lesson_id = ?
    ORDER BY updated_at DESC, id DESC
");
$summaryStmt->execute(array($userId, $cohortId, $lessonId));
$summaries = $summaryStmt->fetchAll(PDO::FETCH_ASSOC);

$latestSummary = !empty($summaries) ? $summaries[0] : null;

$instructorActionToken = '';
foreach ($requiredActions as $requiredAction) {
    $type = trim((string)($requiredAction['action_type'] ?? ''));
    $status = trim((string)($requiredAction['status'] ?? ''));
    if ($type === 'instructor_approval' && in_array($status, array('pending', 'opened', 'approved'), true)) {
        $instructorActionToken = trim((string)($requiredAction['token'] ?? ''));
        if ($instructorActionToken !== '') {
            break;
        }
    }
}

$instructorActionUrl = $instructorActionToken !== ''
    ? '/instructor/instructor_approval.php?token=' . rawurlencode($instructorActionToken)
    : '';

$stats = array(
    'total' => count($progressTests),
    'completed' => 0,
    'ready' => 0,
    'in_progress' => 0,
    'other' => 0,
    'passes' => 0,
    'unsat' => 0,
    'duplicate_ready' => 0,
);

$diagnostics = array();
$completedTests = array();
$readyTests = array();
$latestCompleted = null;
$highestAttemptNo = 0;

foreach ($progressTests as $row) {
    $status = strtolower(trim((string)($row['status'] ?? 'unknown')));
    $attemptNo = (int)($row['attempt_no'] ?? $row['attempt'] ?? 0);
    if ($attemptNo > $highestAttemptNo) {
        $highestAttemptNo = $attemptNo;
    }

    if ($status === 'completed') {
        $stats['completed']++;
        $completedTests[] = $row;
        $latestCompleted = $row;
    } elseif ($status === 'ready') {
        $stats['ready']++;
        $readyTests[] = $row;
    } elseif ($status === 'in_progress') {
        $stats['in_progress']++;
    } else {
        $stats['other']++;
    }

    if ((int)($row['pass_gate_met'] ?? 0) === 1) {
        $stats['passes']++;
    }

    $formalResultCode = strtoupper(trim((string)($row['formal_result_code'] ?? '')));
    if ($formalResultCode === 'UNSAT' || $formalResultCode === 'FAIL' || strtolower(trim((string)($row['formal_result_label'] ?? ''))) === 'unsatisfactory') {
        $stats['unsat']++;
    }
}

if ($stats['ready'] > 1) {
    $stats['duplicate_ready'] = $stats['ready'];
    $diagnostics[] = 'More than one progress test attempt is currently in READY state. This is exactly the type of situation that can hide the correct next step in the UI.';
}

if ($stats['completed'] > 0 && $stats['ready'] > 0) {
    $diagnostics[] = 'This lesson has both completed and ready attempts. That can be valid, but it should be reviewed together with lesson_activity and required actions.';
}

$pendingInstructor = 0;
$pendingRemediation = 0;
$pendingReason = 0;

foreach ($requiredActions as $requiredAction) {
    $type = trim((string)($requiredAction['action_type'] ?? ''));
    $status = trim((string)($requiredAction['status'] ?? ''));
    if (!in_array($status, array('pending', 'opened'), true)) {
        continue;
    }
    if ($type === 'instructor_approval') {
        $pendingInstructor++;
    } elseif ($type === 'remediation_acknowledgement') {
        $pendingRemediation++;
    } elseif ($type === 'deadline_reason_submission') {
        $pendingReason++;
    }
}

if ($stats['unsat'] >= 1 && $pendingRemediation === 0 && $pendingInstructor === 0 && $stats['ready'] > 1) {
    $diagnostics[] = 'Unsatisfactory history exists, duplicate READY attempts exist, and no pending remediation/instructor action is visible for this lesson. This strongly suggests a stale or partially recovered progression state.';
}

if ($lessonActivity) {
    $activityCompletion = trim((string)($lessonActivity['completion_status'] ?? ''));
    $activityTestPass = trim((string)($lessonActivity['test_pass_status'] ?? ''));
    $activitySummary = trim((string)($lessonActivity['summary_status'] ?? ''));
    $diagnostics[] = 'lesson_activity currently shows completion_status = '
        . ($activityCompletion !== '' ? $activityCompletion : '—')
        . ', summary_status = '
        . ($activitySummary !== '' ? $activitySummary : '—')
        . ', test_pass_status = '
        . ($activityTestPass !== '' ? $activityTestPass : '—')
        . '.';
} else {
    $diagnostics[] = 'No lesson_activity row exists for this student/lesson. Diagnostics are still visible, but safe projection refresh cannot update a missing row.';
}

if ($latestSummary) {
    $diagnostics[] = 'Latest lesson summary status = ' . trim((string)($latestSummary['review_status'] ?? 'unknown')) . '.';
}

$primaryReadyTest = !empty($readyTests) ? $readyTests[0] : null;
$latestReadyTest = !empty($readyTests) ? $readyTests[count($readyTests) - 1] : null;

cw_header('Progress Test Review');
?>

<style>
.ptr-page{display:flex;flex-direction:column;gap:18px}
.ptr-hero{padding:22px 24px}
.ptr-eyebrow{font-size:11px;text-transform:uppercase;letter-spacing:.14em;color:#64748b;font-weight:800;margin-bottom:8px}
.ptr-title{margin:0;font-size:32px;line-height:1.05;letter-spacing:-.04em;color:#102845}
.ptr-sub{margin-top:10px;font-size:14px;line-height:1.6;color:#64748b}
.ptr-hero-grid{display:grid;grid-template-columns:1.15fr .85fr;gap:18px;margin-top:18px}
.ptr-person{display:flex;gap:14px;align-items:center;min-width:0}
.ptr-person-copy{min-width:0}
.ptr-person-role{font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#64748b}
.ptr-person-name{margin-top:4px;font-size:22px;line-height:1.05;letter-spacing:-.03em;font-weight:820;color:#102845}
.ptr-person-sub{margin-top:8px;font-size:13px;line-height:1.6;color:#64748b}
.ptr-avatar{overflow:hidden;background:linear-gradient(180deg,#e8eef7 0%,#dfe7f2 100%);border:1px solid rgba(15,23,42,.07);display:flex;align-items:center;justify-content:center;box-shadow:inset 0 1px 0 rgba(255,255,255,0.45)}
.ptr-avatar img{width:100%;height:100%;object-fit:cover;display:block}
.ptr-avatar-fallback{color:#7b8aa0}
.ptr-avatar-fallback svg{width:100%;height:100%;display:block}
.ptr-chip-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}
.ptr-chip{display:inline-flex;align-items:center;justify-content:center;min-height:30px;padding:0 10px;border-radius:999px;font-size:11px;font-weight:800;border:1px solid rgba(15,23,42,.08);background:#f8fafc;color:#334155}
.ptr-chip.ok{background:#ecfdf5;color:#166534;border-color:#bbf7d0}
.ptr-chip.info{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe}
.ptr-chip.warning{background:#fff7ed;color:#c2410c;border-color:#fdba74}
.ptr-chip.danger{background:#fee2e2;color:#991b1b;border-color:#fca5a5}
.ptr-chip.neutral{background:#f8fafc;color:#475569;border-color:#e2e8f0}
.ptr-summary-card{padding:18px 20px;background:linear-gradient(135deg,#12355f 0%,#1f4e89 100%);color:#fff;border-radius:22px;border:1px solid rgba(18,53,95,.20)}
.ptr-summary-title{margin:0;font-size:24px;line-height:1.05;font-weight:820;letter-spacing:-.03em;color:#fff}
.ptr-summary-sub{margin-top:8px;font-size:13px;line-height:1.55;color:rgba(255,255,255,.82)}
.ptr-summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:16px}
.ptr-summary-metric{padding:14px 14px;border-radius:16px;background:rgba(255,255,255,.10);border:1px solid rgba(255,255,255,.14)}
.ptr-summary-kicker{font-size:10px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.72)}
.ptr-summary-value{margin-top:8px;font-size:24px;line-height:1;font-weight:820;color:#fff}
.ptr-grid{display:grid;grid-template-columns:1.05fr .95fr;gap:18px}
.ptr-panel{padding:20px 22px}
.ptr-panel-title{margin:0 0 12px 0;font-size:18px;font-weight:820;color:#102845}
.ptr-panel-sub{font-size:13px;line-height:1.55;color:#64748b;margin-bottom:14px}
.ptr-alert{padding:14px 16px;border-radius:16px;border:1px solid}
.ptr-alert.success{background:#ecfdf5;border-color:#bbf7d0;color:#166534}
.ptr-alert.error{background:#fef2f2;border-color:#fecaca;color:#991b1b}
.ptr-list{display:grid;gap:10px}
.ptr-list-item{display:flex;gap:10px;align-items:flex-start;font-size:13px;line-height:1.55;color:#0f172a}
.ptr-dot{width:6px;height:6px;border-radius:999px;background:#12355f;flex:0 0 6px;margin-top:8px}
.ptr-kv{display:grid;grid-template-columns:1fr auto;gap:10px;padding:12px 0;border-bottom:1px solid rgba(15,23,42,.06)}
.ptr-kv:last-child{border-bottom:0}
.ptr-kv-label{font-size:13px;font-weight:700;color:#334155}
.ptr-kv-value{font-size:13px;font-weight:800;color:#102845;text-align:right}
.ptr-actions{display:flex;gap:10px;flex-wrap:wrap}
.ptr-btn{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:0 16px;border-radius:12px;border:1px solid #12355f;background:#12355f;color:#fff;font-size:13px;font-weight:800;text-decoration:none;cursor:pointer}
.ptr-btn.secondary{background:#fff;color:#12355f}
.ptr-btn.ghost{background:#f8fafc;color:#102845;border-color:#dbe4f0}
.ptr-table-wrap{overflow:auto;border:1px solid rgba(15,23,42,.07);border-radius:18px}
.ptr-table{width:100%;border-collapse:separate;border-spacing:0;min-width:920px;background:#fff}
.ptr-table th{background:#f8fafc;color:#334155;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;padding:12px 12px;border-bottom:1px solid rgba(15,23,42,.07);text-align:left;white-space:nowrap}
.ptr-table td{padding:12px 12px;border-bottom:1px solid rgba(15,23,42,.06);font-size:13px;line-height:1.45;color:#0f172a;vertical-align:top}
.ptr-table tr:last-child td{border-bottom:0}
.ptr-code{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;font-size:12px;color:#0f172a;background:#f8fafc;border:1px solid #e2e8f0;padding:3px 6px;border-radius:8px;display:inline-block}
.ptr-badge{display:inline-flex;align-items:center;justify-content:center;min-height:26px;padding:0 10px;border-radius:999px;font-size:11px;font-weight:800;border:1px solid rgba(15,23,42,.08)}
.ptr-badge.ok{background:#ecfdf5;color:#166534;border-color:#bbf7d0}
.ptr-badge.info{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe}
.ptr-badge.warning{background:#fff7ed;color:#c2410c;border-color:#fdba74}
.ptr-badge.danger{background:#fee2e2;color:#991b1b;border-color:#fca5a5}
.ptr-badge.neutral{background:#f8fafc;color:#475569;border-color:#e2e8f0}
.ptr-progress{display:flex;align-items:center;gap:10px;min-width:150px}
.ptr-progress-track{flex:1 1 auto;height:10px;border-radius:999px;overflow:hidden;background:#e7edf5}
.ptr-progress-fill{height:100%;border-radius:999px}
.ptr-progress-fill.good{background:linear-gradient(90deg,#166534 0%,#22c55e 100%)}
.ptr-progress-fill.amber{background:linear-gradient(90deg,#c2410c 0%,#f59e0b 100%)}
.ptr-progress-fill.danger{background:linear-gradient(90deg,#991b1b 0%,#ef4444 100%)}
.ptr-progress-fill.neutral{background:linear-gradient(90deg,#64748b 0%,#cbd5e1 100%)}
.ptr-progress-value{font-size:12px;font-weight:800;color:#475569;min-width:42px;text-align:right}
.ptr-stack{display:grid;gap:8px}
.ptr-mini{font-size:12px;line-height:1.55;color:#64748b}
.ptr-box{padding:14px 16px;border-radius:16px;background:#f8fafc;border:1px solid rgba(15,23,42,.06)}
.ptr-empty{padding:24px 18px;text-align:center;color:#64748b;font-size:14px}
@media (max-width: 1180px){
    .ptr-hero-grid,.ptr-grid{grid-template-columns:1fr}
}
@media (max-width: 860px){
    .ptr-summary-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
}
</style>

<div class="ptr-page">

    <section class="card ptr-hero">
        <div class="ptr-eyebrow">Instructor Platform · Progress Test Diagnostics</div>
        <h1 class="ptr-title">Progress Test Review</h1>
        <div class="ptr-sub">
            Safe diagnostic page for reviewing stuck, duplicated, or stale progress test states without erasing attempt history.
        </div>

        <div class="ptr-hero-grid">
            <div class="card ptr-panel" style="padding:18px;">
                <div class="ptr-person">
                    <?php echo ptr_avatar_html($context, $studentName, '78'); ?>
                    <div class="ptr-person-copy">
                        <div class="ptr-person-role">Student</div>
                        <div class="ptr-person-name"><?php echo ptr_h($studentName); ?></div>
                        <div class="ptr-person-sub">
                            <?php echo ptr_h((string)($context['email'] ?? '')); ?><br>
                            Cohort: <strong><?php echo ptr_h((string)($context['cohort_name'] ?? '')); ?></strong><br>
                            Course: <strong><?php echo ptr_h((string)($context['course_title'] ?? '')); ?></strong><br>
                            Lesson: <strong><?php echo ptr_h((string)($context['lesson_title'] ?? '')); ?></strong>
                        </div>
                    </div>
                </div>

                <div class="ptr-chip-row">
                    <span class="ptr-chip info">User ID: <?php echo (int)$userId; ?></span>
                    <span class="ptr-chip info">Cohort ID: <?php echo (int)$cohortId; ?></span>
                    <span class="ptr-chip info">Lesson ID: <?php echo (int)$lessonId; ?></span>
                    <span class="ptr-chip <?php echo ptr_h($stats['duplicate_ready'] > 1 ? 'danger' : 'ok'); ?>">
                        Ready Attempts: <?php echo (int)$stats['ready']; ?>
                    </span>
                </div>
            </div>

            <div class="ptr-summary-card">
                <h2 class="ptr-summary-title">Diagnostic Summary</h2>
                <div class="ptr-summary-sub">
                    This page preserves all attempt rows and is designed for state review first, recovery second.
                </div>

                <div class="ptr-summary-grid">
                    <div class="ptr-summary-metric">
                        <div class="ptr-summary-kicker">Total Attempts</div>
                        <div class="ptr-summary-value"><?php echo (int)$stats['total']; ?></div>
                    </div>
                    <div class="ptr-summary-metric">
                        <div class="ptr-summary-kicker">Completed</div>
                        <div class="ptr-summary-value"><?php echo (int)$stats['completed']; ?></div>
                    </div>
                    <div class="ptr-summary-metric">
                        <div class="ptr-summary-kicker">Ready</div>
                        <div class="ptr-summary-value"><?php echo (int)$stats['ready']; ?></div>
                    </div>
                    <div class="ptr-summary-metric">
                        <div class="ptr-summary-kicker">Pending Actions</div>
                        <div class="ptr-summary-value"><?php echo (int)($pendingInstructor + $pendingRemediation + $pendingReason); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <?php foreach ($flash as $message): ?>
        <div class="ptr-alert success"><?php echo ptr_h($message); ?></div>
    <?php endforeach; ?>

    <?php foreach ($errors as $message): ?>
        <div class="ptr-alert error"><?php echo ptr_h($message); ?></div>
    <?php endforeach; ?>

    <section class="ptr-grid">

        <section class="card ptr-panel">
            <h2 class="ptr-panel-title">Recommended Reading of This Case</h2>
            <div class="ptr-panel-sub">
                These observations are derived from existing rows only. No attempt history has been altered.
            </div>

            <?php if (!$diagnostics): ?>
                <div class="ptr-empty">No diagnostic flags detected.</div>
            <?php else: ?>
                <div class="ptr-list">
                    <?php foreach ($diagnostics as $message): ?>
                        <div class="ptr-list-item">
                            <span class="ptr-dot"></span>
                            <span><?php echo ptr_h($message); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="ptr-box" style="margin-top:16px;">
                <div class="ptr-mini">
                    <strong>Primary interpretation:</strong>
                    <?php if ($stats['duplicate_ready'] > 1): ?>
                        There are multiple READY attempts for the same lesson. That is a strong sign of a stale or partially recovered state.
                    <?php elseif ($stats['ready'] === 1 && $stats['completed'] >= 1): ?>
                        There is one READY attempt following completed history. This can be normal, but it still needs to match the required-action state and lesson_activity projection.
                    <?php else: ?>
                        This case needs manual review against required actions and lesson_activity projection.
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="card ptr-panel">
            <h2 class="ptr-panel-title">Safe Recovery Actions</h2>
            <div class="ptr-panel-sub">
                These actions do not erase progress test attempts. They are limited to safe projection refresh.
            </div>

            <div class="ptr-actions" style="margin-bottom:14px;">
                <form method="post" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?php echo ptr_h(ptr_csrf_token()); ?>">
                    <input type="hidden" name="cohort_id" value="<?php echo (int)$cohortId; ?>">
                    <input type="hidden" name="user_id" value="<?php echo (int)$userId; ?>">
                    <input type="hidden" name="lesson_id" value="<?php echo (int)$lessonId; ?>">
                    <input type="hidden" name="action" value="refresh_projection_snapshot">
                    <button class="ptr-btn" type="submit">Refresh Projection Snapshot</button>
                </form>

                <?php if ($instructorActionUrl !== ''): ?>
                    <a class="ptr-btn secondary" href="<?php echo ptr_h($instructorActionUrl); ?>">Open Intervention</a>
                <?php else: ?>
                    <span class="ptr-btn ghost" style="opacity:.7;cursor:default;">No Active Instructor Link</span>
                <?php endif; ?>

                <a class="ptr-btn ghost" href="/instructor/cohort_progress_overview.php?cohort_id=<?php echo (int)$cohortId; ?>">Back to Cohort Overview</a>
            </div>

            <div class="ptr-kv">
                <div class="ptr-kv-label">Pending Instructor Actions</div>
                <div class="ptr-kv-value"><?php echo (int)$pendingInstructor; ?></div>
            </div>
            <div class="ptr-kv">
                <div class="ptr-kv-label">Pending Remediation Actions</div>
                <div class="ptr-kv-value"><?php echo (int)$pendingRemediation; ?></div>
            </div>
            <div class="ptr-kv">
                <div class="ptr-kv-label">Pending Reason Actions</div>
                <div class="ptr-kv-value"><?php echo (int)$pendingReason; ?></div>
            </div>
            <div class="ptr-kv">
                <div class="ptr-kv-label">Current Highest Attempt Number</div>
                <div class="ptr-kv-value"><?php echo (int)$highestAttemptNo; ?></div>
            </div>
            <div class="ptr-kv">
                <div class="ptr-kv-label">Primary Ready Attempt</div>
                <div class="ptr-kv-value">
                    <?php echo $primaryReadyTest ? '#' . (int)($primaryReadyTest['id'] ?? 0) : '—'; ?>
                </div>
            </div>
            <div class="ptr-kv">
                <div class="ptr-kv-label">Latest Ready Attempt</div>
                <div class="ptr-kv-value">
                    <?php echo $latestReadyTest ? '#' . (int)($latestReadyTest['id'] ?? 0) : '—'; ?>
                </div>
            </div>
        </section>

    </section>

    <section class="card ptr-panel">
        <h2 class="ptr-panel-title">lesson_activity Projection</h2>
        <div class="ptr-panel-sub">
            Projection only. This is not canonical truth, but it is often what determines what the student sees next.
        </div>

        <?php if (!$lessonActivity): ?>
            <div class="ptr-empty">No lesson_activity row found for this lesson.</div>
        <?php else: ?>
            <div class="ptr-grid" style="grid-template-columns:1fr 1fr;">
                <div class="ptr-stack">
                    <div class="ptr-kv">
                        <div class="ptr-kv-label">Completion Status</div>
                        <div class="ptr-kv-value">
                            <span class="ptr-badge <?php echo ptr_h(ptr_status_chip_class((string)($lessonActivity['completion_status'] ?? ''))); ?>">
                                <?php echo ptr_h((string)($lessonActivity['completion_status'] ?? '—')); ?>
                            </span>
                        </div>
                    </div>
                    <div class="ptr-kv">
                        <div class="ptr-kv-label">Summary Status</div>
                        <div class="ptr-kv-value">
                            <span class="ptr-badge <?php echo ptr_h(ptr_status_chip_class((string)($lessonActivity['summary_status'] ?? ''))); ?>">
                                <?php echo ptr_h((string)($lessonActivity['summary_status'] ?? '—')); ?>
                            </span>
                        </div>
                    </div>
                    <div class="ptr-kv">
                        <div class="ptr-kv-label">Test Pass Status</div>
                        <div class="ptr-kv-value">
                            <span class="ptr-badge <?php echo ptr_h(ptr_status_chip_class((string)($lessonActivity['test_pass_status'] ?? ''))); ?>">
                                <?php echo ptr_h((string)($lessonActivity['test_pass_status'] ?? '—')); ?>
                            </span>
                        </div>
                    </div>
                    <div class="ptr-kv">
                        <div class="ptr-kv-label">Training Suspended</div>
                        <div class="ptr-kv-value"><?php echo ptr_h(ptr_bool_label($lessonActivity['training_suspended'] ?? 0)); ?></div>
                    </div>
                    <div class="ptr-kv">
                        <div class="ptr-kv-label">One-on-One Required</div>
                        <div class="ptr-kv-value"><?php echo ptr_h(ptr_bool_label($lessonActivity['one_on_one_required'] ?? 0)); ?></div>
                    </div>
                    <div class="ptr-kv">
                        <div class="ptr-kv-label">One-on-One Completed</div>
                        <div class="ptr-kv-value"><?php echo ptr_h(ptr_bool_label($lessonActivity['one_on_one_completed'] ?? 0)); ?></div>
                    </div>
                </div>

                <div class="ptr-stack">
                    <div class="ptr-kv">
                        <div class="ptr-kv-label">Reason Required</div>
                        <div class="ptr-kv-value"><?php echo ptr_h(ptr_bool_label($lessonActivity['reason_required'] ?? 0)); ?></div>
                    </div>
                    <div class="ptr-kv">
                        <div class="ptr-kv-label">Reason Submitted</div>
                        <div class="ptr-kv-value"><?php echo ptr_h(ptr_bool_label($lessonActivity['reason_submitted'] ?? 0)); ?></div>
                    </div>
                    <div class="ptr-kv">
                        <div class="ptr-kv-label">Granted Extra Attempts</div>
                        <div class="ptr-kv-value"><?php echo ptr_h((string)($lessonActivity['granted_extra_attempts'] ?? '0')); ?></div>
                    </div>
                    <div class="ptr-kv">
                        <div class="ptr-kv-label">Final Warning Issued</div>
                        <div class="ptr-kv-value"><?php echo ptr_h(ptr_bool_label($lessonActivity['final_warning_issued'] ?? 0)); ?></div>
                    </div>
                    <div class="ptr-kv">
                        <div class="ptr-kv-label">Effective Deadline UTC</div>
                        <div class="ptr-kv-value"><?php echo ptr_h(ptr_format_datetime((string)($lessonActivity['effective_deadline_utc'] ?? ''))); ?></div>
                    </div>
                    <div class="ptr-kv">
                        <div class="ptr-kv-label">Last State Eval</div>
                        <div class="ptr-kv-value"><?php echo ptr_h(ptr_format_datetime((string)($lessonActivity['last_state_eval_at'] ?? ''))); ?></div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </section>

    <section class="card ptr-panel">
        <h2 class="ptr-panel-title">Progress Test Attempts</h2>
        <div class="ptr-panel-sub">
            Every attempt is preserved below. This page does not erase attempt history.
        </div>

        <?php if (!$progressTests): ?>
            <div class="ptr-empty">No progress test attempts found for this lesson.</div>
        <?php else: ?>
            <div class="ptr-table-wrap">
                <table class="ptr-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Attempt</th>
                            <th>Status</th>
                            <th>Score</th>
                            <th>Result</th>
                            <th>Pass Gate</th>
                            <th>Timing</th>
                            <th>Created</th>
                            <th>Completed</th>
                            <th>Idempotency / Token</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($progressTests as $row): ?>
                            <?php
                            $attemptNo = (int)($row['attempt_no'] ?? $row['attempt'] ?? 0);
                            $status = trim((string)($row['status'] ?? ''));
                            $score = ptr_first_numeric_or_null($row, array('score_pct'));
                            $resultCode = ptr_first_non_empty($row, array('formal_result_code', 'result_code'));
                            $resultLabel = ptr_first_non_empty($row, array('formal_result_label', 'result_label'));
                            $timingStatus = ptr_first_non_empty($row, array('timing_status'));
                            $createdAt = ptr_first_non_empty($row, array('created_at'));
                            $completedAt = ptr_first_non_empty($row, array('completed_at'));
                            $idempotencyKey = ptr_first_non_empty($row, array('idempotency_key', 'token'));
                            ?>
                            <tr>
                                <td><span class="ptr-code"><?php echo (int)($row['id'] ?? 0); ?></span></td>
                                <td><?php echo $attemptNo > 0 ? (int)$attemptNo : '—'; ?></td>
                                <td>
                                    <span class="ptr-badge <?php echo ptr_h(ptr_status_chip_class($status)); ?>">
                                        <?php echo ptr_h($status !== '' ? $status : '—'); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($score !== null): ?>
                                        <div class="ptr-progress">
                                            <div class="ptr-progress-track">
                                                <div class="ptr-progress-fill <?php echo ptr_h(ptr_progress_fill_class($score)); ?>" style="width:<?php echo (int)ptr_clamped_percent($score); ?>%;"></div>
                                            </div>
                                            <div class="ptr-progress-value"><?php echo ptr_h((string)round($score, 1)); ?>%</div>
                                        </div>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($resultLabel !== '' || $resultCode !== ''): ?>
                                        <div class="ptr-stack">
                                            <?php if ($resultLabel !== ''): ?>
                                                <span><?php echo ptr_h($resultLabel); ?></span>
                                            <?php endif; ?>
                                            <?php if ($resultCode !== ''): ?>
                                                <span class="ptr-code"><?php echo ptr_h($resultCode); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td><?php echo isset($row['pass_gate_met']) ? ptr_h(ptr_bool_label($row['pass_gate_met'])) : '—'; ?></td>
                                <td>
                                    <?php if ($timingStatus !== ''): ?>
                                        <span class="ptr-badge <?php echo ptr_h(ptr_status_chip_class($timingStatus)); ?>">
                                            <?php echo ptr_h($timingStatus); ?>
                                        </span>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td><?php echo ptr_h(ptr_format_datetime($createdAt)); ?></td>
                                <td><?php echo ptr_h(ptr_format_datetime($completedAt)); ?></td>
                                <td>
                                    <?php if ($idempotencyKey !== ''): ?>
                                        <span class="ptr-code"><?php echo ptr_h($idempotencyKey); ?></span>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php
                            $feedback = ptr_first_non_empty($row, array(
                                'feedback_text',
                                'written_debrief_text',
                                'written_debrief',
                                'overall_feedback_text',
                                'review_message',
                                'remarks',
                                'feedback'
                            ));
                            $weakAreas = ptr_first_non_empty($row, array(
                                'weak_areas_text',
                                'review_areas_text',
                                'review_points_text'
                            ));
                            if ($feedback !== '' || $weakAreas !== ''):
                            ?>
                                <tr>
                                    <td colspan="10" style="background:#fbfdff;">
                                        <div class="ptr-stack">
                                            <?php if ($weakAreas !== ''): ?>
                                                <div class="ptr-mini"><strong>Review Areas:</strong> <?php echo nl2br(ptr_h($weakAreas)); ?></div>
                                            <?php endif; ?>
                                            <?php if ($feedback !== ''): ?>
                                                <div class="ptr-mini"><strong>Debrief / Feedback:</strong> <?php echo nl2br(ptr_h($feedback)); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="ptr-grid">

        <section class="card ptr-panel">
            <h2 class="ptr-panel-title">Required Actions</h2>
            <div class="ptr-panel-sub">
                These rows determine whether the student is blocked on remediation, reason submission, or instructor approval.
            </div>

            <?php if (!$requiredActions): ?>
                <div class="ptr-empty">No required actions found for this lesson.</div>
            <?php else: ?>
                <div class="ptr-table-wrap">
                    <table class="ptr-table" style="min-width:760px;">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Token</th>
                                <th>Decision</th>
                                <th>Created</th>
                                <th>Updated</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($requiredActions as $row): ?>
                                <?php
                                $type = trim((string)($row['action_type'] ?? ''));
                                $status = trim((string)($row['status'] ?? ''));
                                $token = trim((string)($row['token'] ?? ''));
                                $decisionCode = trim((string)($row['decision_code'] ?? ''));
                                ?>
                                <tr>
                                    <td><span class="ptr-code"><?php echo (int)($row['id'] ?? 0); ?></span></td>
                                    <td><?php echo ptr_h($type !== '' ? $type : '—'); ?></td>
                                    <td>
                                        <span class="ptr-badge <?php echo ptr_h(ptr_status_chip_class($status)); ?>">
                                            <?php echo ptr_h($status !== '' ? $status : '—'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $token !== '' ? '<span class="ptr-code">' . ptr_h($token) . '</span>' : '—'; ?></td>
                                    <td><?php echo $decisionCode !== '' ? '<span class="ptr-code">' . ptr_h($decisionCode) . '</span>' : '—'; ?></td>
                                    <td><?php echo ptr_h(ptr_format_datetime((string)($row['created_at'] ?? ''))); ?></td>
                                    <td><?php echo ptr_h(ptr_format_datetime((string)($row['updated_at'] ?? ''))); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="card ptr-panel">
            <h2 class="ptr-panel-title">Latest Summary</h2>
            <div class="ptr-panel-sub">
                This helps determine whether the lesson could legitimately be complete once the test state is corrected.
            </div>

            <?php if (!$latestSummary): ?>
                <div class="ptr-empty">No summary found for this lesson.</div>
            <?php else: ?>
                <div class="ptr-kv">
                    <div class="ptr-kv-label">Review Status</div>
                    <div class="ptr-kv-value">
                        <span class="ptr-badge <?php echo ptr_h(ptr_status_chip_class((string)($latestSummary['review_status'] ?? ''))); ?>">
                            <?php echo ptr_h((string)($latestSummary['review_status'] ?? '—')); ?>
                        </span>
                    </div>
                </div>

                <div class="ptr-kv">
                    <div class="ptr-kv-label">Review Score</div>
                    <div class="ptr-kv-value">
                        <?php
                        $summaryScore = ptr_first_numeric_or_null($latestSummary, array('review_score'));
                        if ($summaryScore !== null):
                        ?>
                            <div class="ptr-progress" style="min-width:180px;">
                                <div class="ptr-progress-track">
                                    <div class="ptr-progress-fill <?php echo ptr_h(ptr_progress_fill_class($summaryScore)); ?>" style="width:<?php echo (int)ptr_clamped_percent($summaryScore); ?>%;"></div>
                                </div>
                                <div class="ptr-progress-value"><?php echo ptr_h((string)round($summaryScore, 1)); ?>%</div>
                            </div>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </div>
                </div>

                <div class="ptr-kv">
                    <div class="ptr-kv-label">Updated</div>
                    <div class="ptr-kv-value"><?php echo ptr_h(ptr_format_datetime((string)($latestSummary['updated_at'] ?? ''))); ?></div>
                </div>

                <?php
                $reviewNotes = ptr_first_non_empty($latestSummary, array('review_notes_by_instructor', 'review_feedback', 'review_notes'));
                if ($reviewNotes !== ''):
                ?>
                    <div class="ptr-box" style="margin-top:14px;">
                        <div class="ptr-mini"><strong>Instructor Notes</strong></div>
                        <div style="margin-top:8px;font-size:13px;line-height:1.6;color:#0f172a;"><?php echo nl2br(ptr_h($reviewNotes)); ?></div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>

    </section>

    <section class="card ptr-panel">
        <h2 class="ptr-panel-title">Operational Guidance</h2>
        <div class="ptr-panel-sub">
            Use this sequence for stuck progress test cases where you want to preserve all attempt history.
        </div>

        <div class="ptr-list">
            <div class="ptr-list-item">
                <span class="ptr-dot"></span>
                <span>Review the attempts table first. If multiple READY attempts exist for the same lesson, treat that as a stale-state problem rather than deleting history.</span>
            </div>
            <div class="ptr-list-item">
                <span class="ptr-dot"></span>
                <span>Review required actions next. If the student should be blocked on remediation or instructor review but no corresponding pending action exists, the progression state is likely incomplete or stale.</span>
            </div>
            <div class="ptr-list-item">
                <span class="ptr-dot"></span>
                <span>Review lesson_activity last. This is projection only, but it often determines what the UI exposes. The “Refresh Projection Snapshot” action updates only that projection layer.</span>
            </div>
            <div class="ptr-list-item">
                <span class="ptr-dot"></span>
                <span>Do not erase attempt rows for recovery. Preserve the audit trail and correct the workflow/projection layer around it.</span>
            </div>
        </div>
    </section>

</div>

<?php cw_footer(); ?>