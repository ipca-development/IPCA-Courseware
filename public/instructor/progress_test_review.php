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

function ptr_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function ptr_now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

function ptr_format_dt(?string $dt): string
{
    $dt = trim((string)$dt);
    if ($dt === '') {
        return '—';
    }

    $ts = strtotime($dt);
    if ($ts === false) {
        return $dt;
    }

    return gmdate('D M j, Y · H:i', $ts) . ' UTC';
}

function ptr_create_token(): string
{
    try {
        return bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        return md5(uniqid((string)mt_rand(), true));
    }
}

function ptr_fetch_one(PDO $pdo, string $sql, array $params = array()): ?array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function ptr_fetch_all(PDO $pdo, string $sql, array $params = array()): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return $rows ?: array();
}

function ptr_column_exists(PDO $pdo, string $table, string $column): bool
{
    static $cache = array();

    $key = $table . '.' . $column;
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $sql = "
        SELECT COUNT(*) AS cnt
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array($table, $column));
    $cnt = (int)$stmt->fetchColumn();

    $cache[$key] = ($cnt > 0);
    return $cache[$key];
}

function ptr_required_action_url(array $action): string
{
    $token = trim((string)($action['token'] ?? ''));
    $type  = trim((string)($action['action_type'] ?? ''));

    if ($token === '') {
        return '';
    }

    if ($type === 'remediation_acknowledgement') {
        return '/student/remediation_action.php?token=' . rawurlencode($token);
    }

    if ($type === 'deadline_reason_submission') {
        return '/student/deadline_reason.php?token=' . rawurlencode($token);
    }

    if ($type === 'instructor_approval') {
        return '/instructor/instructor_approval.php?token=' . rawurlencode($token);
    }

    return '';
}

function ptr_find_latest_open_action(PDO $pdo, int $userId, int $cohortId, int $lessonId, string $type): ?array
{
    return ptr_fetch_one(
        $pdo,
        "
        SELECT *
        FROM student_required_actions
        WHERE user_id = ?
          AND cohort_id = ?
          AND lesson_id = ?
          AND action_type = ?
          AND status IN ('pending','opened','approved')
        ORDER BY id DESC
        LIMIT 1
        ",
        array($userId, $cohortId, $lessonId, $type)
    );
}

function ptr_mark_opened_if_needed(PDO $pdo, int $actionId): void
{
    $hasUpdatedAt = ptr_column_exists($pdo, 'student_required_actions', 'updated_at');

    $sql = "UPDATE student_required_actions SET status = CASE WHEN status = 'pending' THEN 'opened' ELSE status END";
    if ($hasUpdatedAt) {
        $sql .= ", updated_at = ?";
        $params = array(ptr_now_utc(), $actionId);
    } else {
        $params = array($actionId);
    }
    $sql .= " WHERE id = ?";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

function ptr_insert_instructor_action(PDO $pdo, array $testRow, int $createdByUserId): array
{
    $token = ptr_create_token();
    $nowUtc = ptr_now_utc();

    $columns = array(
        'user_id',
        'cohort_id',
        'lesson_id',
        'progress_test_id',
        'action_type',
        'status',
        'token',
        'created_at'
    );
    $values = array(
        (int)$testRow['user_id'],
        (int)$testRow['cohort_id'],
        (int)$testRow['lesson_id'],
        (int)$testRow['id'],
        'instructor_approval',
        'pending',
        $token,
        $nowUtc
    );

    if (ptr_column_exists($pdo, 'student_required_actions', 'updated_at')) {
        $columns[] = 'updated_at';
        $values[] = $nowUtc;
    }
    if (ptr_column_exists($pdo, 'student_required_actions', 'created_by_user_id')) {
        $columns[] = 'created_by_user_id';
        $values[] = $createdByUserId;
    }
    if (ptr_column_exists($pdo, 'student_required_actions', 'required_by_utc')) {
        $columns[] = 'required_by_utc';
        $values[] = null;
    }
    if (ptr_column_exists($pdo, 'student_required_actions', 'metadata_json')) {
        $columns[] = 'metadata_json';
        $values[] = json_encode(array(
            'source' => 'progress_test_review_manual_recovery',
            'created_at_utc' => $nowUtc,
            'reason' => 'Manual recovery created instructor approval because no active instructor link existed.'
        ));
    }
    if (ptr_column_exists($pdo, 'student_required_actions', 'notes')) {
        $columns[] = 'notes';
        $values[] = 'Manually created by admin recovery in progress_test_review.php';
    }

    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $sql = "INSERT INTO student_required_actions (" . implode(',', $columns) . ") VALUES (" . $placeholders . ")";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);

    return ptr_fetch_one(
        $pdo,
        "SELECT * FROM student_required_actions WHERE id = ? LIMIT 1",
        array((int)$pdo->lastInsertId())
    ) ?: array();
}

function ptr_refresh_lesson_activity(PDO $pdo, array $testRow): void
{
    $userId   = (int)$testRow['user_id'];
    $cohortId = (int)$testRow['cohort_id'];
    $lessonId = (int)$testRow['lesson_id'];

    $attempts = ptr_fetch_all(
        $pdo,
        "
        SELECT *
        FROM progress_tests_v2
        WHERE user_id = ?
          AND cohort_id = ?
          AND lesson_id = ?
        ORDER BY attempt_no ASC, id ASC
        ",
        array($userId, $cohortId, $lessonId)
    );

    $completedAttempts = 0;
    $latestCompleted   = null;
    $hasPass           = 0;
    $hasUnsat          = 0;
    $latestReady       = null;

    foreach ($attempts as $attemptRow) {
        $status = trim((string)($attemptRow['status'] ?? ''));
        if ($status === 'completed') {
            $completedAttempts++;
            $latestCompleted = $attemptRow;

            if ((int)($attemptRow['pass_gate_met'] ?? 0) === 1 || strtoupper((string)($attemptRow['formal_result_code'] ?? '')) === 'PASS') {
                $hasPass = 1;
            } else {
                $hasUnsat = 1;
            }
        } elseif ($status === 'ready') {
            $latestReady = $attemptRow;
        }
    }

    $summary = ptr_fetch_one(
        $pdo,
        "
        SELECT review_status, review_score
        FROM lesson_summaries
        WHERE user_id = ?
          AND cohort_id = ?
          AND lesson_id = ?
        ORDER BY updated_at DESC, id DESC
        LIMIT 1
        ",
        array($userId, $cohortId, $lessonId)
    );

    $summaryStatus = trim((string)($summary['review_status'] ?? ''));
    $summaryStateForActivity = 'not_started';
    if ($summaryStatus === 'acceptable') {
        $summaryStateForActivity = 'acceptable';
    } elseif ($summaryStatus === 'needs_revision' || $summaryStatus === 'rejected') {
        $summaryStateForActivity = 'needs_revision';
    } elseif ($summaryStatus === 'pending') {
        $summaryStateForActivity = 'pending';
    }

    $testPassStatus = 'not_started';
    if ($hasPass) {
        $testPassStatus = 'passed';
    } elseif ($hasUnsat) {
        $testPassStatus = 'failed';
    } elseif ($latestReady) {
        $testPassStatus = 'ready';
    }

    $completionStatus = 'in_progress';
    if ($summaryStateForActivity === 'acceptable' && $testPassStatus === 'passed') {
        $completionStatus = 'completed';
    }

    $pendingInstructor = ptr_fetch_one(
        $pdo,
        "
        SELECT id
        FROM student_required_actions
        WHERE user_id = ?
          AND cohort_id = ?
          AND lesson_id = ?
          AND action_type = 'instructor_approval'
          AND status IN ('pending','opened','approved')
        ORDER BY id DESC
        LIMIT 1
        ",
        array($userId, $cohortId, $lessonId)
    );

    $pendingRemediation = ptr_fetch_one(
        $pdo,
        "
        SELECT id
        FROM student_required_actions
        WHERE user_id = ?
          AND cohort_id = ?
          AND lesson_id = ?
          AND action_type = 'remediation_acknowledgement'
          AND status IN ('pending','opened')
        ORDER BY id DESC
        LIMIT 1
        ",
        array($userId, $cohortId, $lessonId)
    );

    $pendingReason = ptr_fetch_one(
        $pdo,
        "
        SELECT id
        FROM student_required_actions
        WHERE user_id = ?
          AND cohort_id = ?
          AND lesson_id = ?
          AND action_type = 'deadline_reason_submission'
          AND status IN ('pending','opened')
        ORDER BY id DESC
        LIMIT 1
        ",
        array($userId, $cohortId, $lessonId)
    );

    $reasonRequired      = $pendingReason ? 1 : 0;
    $reasonSubmitted     = 0;
    $trainingSuspended   = $pendingInstructor ? 1 : 0;
    $oneOnOneRequired    = 0;
    $oneOnOneCompleted   = 0;
    $grantedExtraAttempts = 0;

    $existing = ptr_fetch_one(
        $pdo,
        "
        SELECT *
        FROM lesson_activity
        WHERE user_id = ?
          AND cohort_id = ?
          AND lesson_id = ?
        LIMIT 1
        ",
        array($userId, $cohortId, $lessonId)
    );

    if ($existing) {
        $sql = "
            UPDATE lesson_activity
            SET completion_status = ?,
                summary_status = ?,
                test_pass_status = ?,
                training_suspended = ?,
                reason_required = ?,
                reason_submitted = ?,
                one_on_one_required = ?,
                one_on_one_completed = ?,
                granted_extra_attempts = ?,
                updated_at = ?
            WHERE id = ?
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array(
            $completionStatus,
            $summaryStateForActivity,
            $testPassStatus,
            $trainingSuspended,
            $reasonRequired,
            $reasonSubmitted,
            $oneOnOneRequired,
            $oneOnOneCompleted,
            $grantedExtraAttempts,
            ptr_now_utc(),
            (int)$existing['id']
        ));
    } else {
        $sql = "
            INSERT INTO lesson_activity (
                user_id,
                cohort_id,
                lesson_id,
                completion_status,
                summary_status,
                test_pass_status,
                training_suspended,
                reason_required,
                reason_submitted,
                one_on_one_required,
                one_on_one_completed,
                granted_extra_attempts,
                created_at,
                updated_at
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array(
            $userId,
            $cohortId,
            $lessonId,
            $completionStatus,
            $summaryStateForActivity,
            $testPassStatus,
            $trainingSuspended,
            $reasonRequired,
            $reasonSubmitted,
            $oneOnOneRequired,
            $oneOnOneCompleted,
            $grantedExtraAttempts,
            ptr_now_utc(),
            ptr_now_utc()
        ));
    }
}

function ptr_safe_recover(PDO $pdo, array $testRow, int $actorUserId): array
{
    $result = array(
        'messages' => array(),
        'created_action_url' => '',
        'opened_action_url' => '',
    );

    $pdo->beginTransaction();

    try {
        $lockedCurrent = ptr_fetch_one(
            $pdo,
            "
            SELECT *
            FROM progress_tests_v2
            WHERE id = ?
            FOR UPDATE
            ",
            array((int)$testRow['id'])
        );

        if (!$lockedCurrent) {
            throw new RuntimeException('Progress test not found during recovery.');
        }

        $userId   = (int)$lockedCurrent['user_id'];
        $cohortId = (int)$lockedCurrent['cohort_id'];
        $lessonId = (int)$lockedCurrent['lesson_id'];

        $attemptRows = ptr_fetch_all(
            $pdo,
            "
            SELECT *
            FROM progress_tests_v2
            WHERE user_id = ?
              AND cohort_id = ?
              AND lesson_id = ?
            ORDER BY attempt_no ASC, id ASC
            FOR UPDATE
            ",
            array($userId, $cohortId, $lessonId)
        );

        $completedCount = 0;
        $readyCount = 0;
        $latestReady = null;
        $latestCompleted = null;

        foreach ($attemptRows as $row) {
            $status = trim((string)($row['status'] ?? ''));
            if ($status === 'completed') {
                $completedCount++;
                $latestCompleted = $row;
            }
            if ($status === 'ready') {
                $readyCount++;
                $latestReady = $row;
            }
        }

        $result['messages'][] = 'Locked all attempts for this user/cohort/lesson.';
        $result['messages'][] = 'Completed attempts found: ' . $completedCount . '.';
        $result['messages'][] = 'Ready attempts found: ' . $readyCount . '.';

        $instructorAction = ptr_find_latest_open_action($pdo, $userId, $cohortId, $lessonId, 'instructor_approval');
        $remediationAction = ptr_find_latest_open_action($pdo, $userId, $cohortId, $lessonId, 'remediation_acknowledgement');
        $reasonAction = ptr_find_latest_open_action($pdo, $userId, $cohortId, $lessonId, 'deadline_reason_submission');

        if ($instructorAction) {
            ptr_mark_opened_if_needed($pdo, (int)$instructorAction['id']);
            $url = ptr_required_action_url($instructorAction);
            $result['opened_action_url'] = $url;
            $result['messages'][] = 'Existing instructor action found and restored.';
        } elseif ($remediationAction) {
            $result['opened_action_url'] = ptr_required_action_url($remediationAction);
            $result['messages'][] = 'Remediation action exists; no new instructor action created.';
        } elseif ($reasonAction) {
            $result['opened_action_url'] = ptr_required_action_url($reasonAction);
            $result['messages'][] = 'Deadline-reason action exists; no new instructor action created.';
        } else {
            $shouldCreateInstructorAction = false;

            if ($latestCompleted) {
                $formalResultCode = strtoupper(trim((string)($latestCompleted['formal_result_code'] ?? '')));
                $passGateMet = (int)($latestCompleted['pass_gate_met'] ?? 0);

                if ($passGateMet !== 1 && $formalResultCode !== 'PASS') {
                    $shouldCreateInstructorAction = true;
                }
            }

            if ($shouldCreateInstructorAction) {
                $newAction = ptr_insert_instructor_action($pdo, $lockedCurrent, $actorUserId);
                if ($newAction && !empty($newAction['id'])) {
                    ptr_mark_opened_if_needed($pdo, (int)$newAction['id']);
                    $result['created_action_url'] = ptr_required_action_url($newAction);
                    $result['messages'][] = 'New instructor approval action created because no active action existed.';
                }
            } else {
                $result['messages'][] = 'No active action existed, but the latest completed attempt did not justify creating a new instructor approval action.';
            }
        }

        ptr_refresh_lesson_activity($pdo, $lockedCurrent);
        $result['messages'][] = 'Lesson activity projection refreshed from canonical tables.';

        $pdo->commit();
        return $result;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

$testId = (int)($_GET['test_id'] ?? $_POST['test_id'] ?? 0);
if ($testId <= 0) {
    http_response_code(400);
    exit('Missing test_id');
}

$flashSuccess = '';
$flashError = '';
$flashLines = array();
$recoveryUrl = '';
$openUrl = '';

$testRow = ptr_fetch_one(
    $pdo,
    "
    SELECT
        pt.*,
        u.name AS student_name,
        u.email AS student_email,
        u.photo_path AS student_photo_path,
        c.name AS cohort_name,
        c.course_id,
        l.title AS lesson_title
    FROM progress_tests_v2 pt
    INNER JOIN users u
        ON u.id = pt.user_id
    INNER JOIN cohorts c
        ON c.id = pt.cohort_id
    INNER JOIN lessons l
        ON l.id = pt.lesson_id
    WHERE pt.id = ?
    LIMIT 1
    ",
    array($testId)
);

if (!$testRow) {
    http_response_code(404);
    exit('Progress test not found');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['admin_action'] ?? ''));

    try {
        if ($action === 'refresh_projection_snapshot') {
            ptr_refresh_lesson_activity($pdo, $testRow);
            $flashSuccess = 'Projection snapshot refreshed.';
        } elseif ($action === 'safe_recover') {
            $recovery = ptr_safe_recover($pdo, $testRow, (int)($currentUser['id'] ?? 0));
            $flashSuccess = 'Safe recovery completed.';
            $flashLines = $recovery['messages'];
            $recoveryUrl = trim((string)($recovery['created_action_url'] ?? ''));
            $openUrl = trim((string)($recovery['opened_action_url'] ?? ''));
        } else {
            $flashError = 'Unknown action.';
        }
    } catch (Throwable $e) {
        $flashError = $e->getMessage();
    }

    $testRow = ptr_fetch_one(
        $pdo,
        "
        SELECT
            pt.*,
            u.name AS student_name,
            u.email AS student_email,
            u.photo_path AS student_photo_path,
            c.name AS cohort_name,
            c.course_id,
            l.title AS lesson_title
        FROM progress_tests_v2 pt
        INNER JOIN users u
            ON u.id = pt.user_id
        INNER JOIN cohorts c
            ON c.id = pt.cohort_id
        INNER JOIN lessons l
            ON l.id = pt.lesson_id
        WHERE pt.id = ?
        LIMIT 1
        ",
        array($testId)
    );
}

$attemptRows = ptr_fetch_all(
    $pdo,
    "
    SELECT *
    FROM progress_tests_v2
    WHERE user_id = ?
      AND cohort_id = ?
      AND lesson_id = ?
    ORDER BY attempt_no ASC, id ASC
    ",
    array(
        (int)$testRow['user_id'],
        (int)$testRow['cohort_id'],
        (int)$testRow['lesson_id']
    )
);

$lessonActivity = ptr_fetch_one(
    $pdo,
    "
    SELECT *
    FROM lesson_activity
    WHERE user_id = ?
      AND cohort_id = ?
      AND lesson_id = ?
    LIMIT 1
    ",
    array(
        (int)$testRow['user_id'],
        (int)$testRow['cohort_id'],
        (int)$testRow['lesson_id']
    )
);

$summaryRow = ptr_fetch_one(
    $pdo,
    "
    SELECT *
    FROM lesson_summaries
    WHERE user_id = ?
      AND cohort_id = ?
      AND lesson_id = ?
    ORDER BY updated_at DESC, id DESC
    LIMIT 1
    ",
    array(
        (int)$testRow['user_id'],
        (int)$testRow['cohort_id'],
        (int)$testRow['lesson_id']
    )
);

$requiredActions = ptr_fetch_all(
    $pdo,
    "
    SELECT *
    FROM student_required_actions
    WHERE user_id = ?
      AND cohort_id = ?
      AND lesson_id = ?
    ORDER BY id DESC
    ",
    array(
        (int)$testRow['user_id'],
        (int)$testRow['cohort_id'],
        (int)$testRow['lesson_id']
    )
);

$latestInstructorAction = ptr_find_latest_open_action(
    $pdo,
    (int)$testRow['user_id'],
    (int)$testRow['cohort_id'],
    (int)$testRow['lesson_id'],
    'instructor_approval'
);

$latestRemediationAction = ptr_find_latest_open_action(
    $pdo,
    (int)$testRow['user_id'],
    (int)$testRow['cohort_id'],
    (int)$testRow['lesson_id'],
    'remediation_acknowledgement'
);

$latestReasonAction = ptr_find_latest_open_action(
    $pdo,
    (int)$testRow['user_id'],
    (int)$testRow['cohort_id'],
    (int)$testRow['lesson_id'],
    'deadline_reason_submission'
);

$openInstructorUrl = $latestInstructorAction ? ptr_required_action_url($latestInstructorAction) : '';
$openRemediationUrl = $latestRemediationAction ? ptr_required_action_url($latestRemediationAction) : '';
$openReasonUrl = $latestReasonAction ? ptr_required_action_url($latestReasonAction) : '';

$hasDuplicateReady = 0;
$readyCount = 0;
$completedCount = 0;
$latestReadyId = 0;
$latestCompletedId = 0;

foreach ($attemptRows as $attemptRow) {
    $status = trim((string)($attemptRow['status'] ?? ''));
    if ($status === 'ready') {
        $readyCount++;
        $latestReadyId = (int)$attemptRow['id'];
    } elseif ($status === 'completed') {
        $completedCount++;
        $latestCompletedId = (int)$attemptRow['id'];
    }
}
$hasDuplicateReady = $readyCount > 1 ? 1 : 0;

cw_header('Progress Test Review');
?>

<style>
.ptr-page{display:flex;flex-direction:column;gap:18px}
.ptr-hero{padding:22px 24px}
.ptr-eyebrow{font-size:11px;text-transform:uppercase;letter-spacing:.14em;color:#64748b;font-weight:800;margin-bottom:8px}
.ptr-title{margin:0;font-size:30px;line-height:1.05;letter-spacing:-.04em;color:#102845}
.ptr-sub{margin-top:10px;font-size:14px;line-height:1.6;color:#64748b}
.ptr-alert{padding:14px 16px;border-radius:16px;font-size:14px;line-height:1.6}
.ptr-alert.success{background:#ecfdf5;color:#166534;border:1px solid #bbf7d0}
.ptr-alert.error{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}
.ptr-grid{display:grid;grid-template-columns:1.15fr .85fr;gap:18px}
.ptr-card{padding:20px 22px}
.ptr-card-title{margin:0 0 14px 0;font-size:18px;font-weight:820;color:#102845}
.ptr-kv{display:grid;grid-template-columns:1fr auto;gap:10px;padding:11px 0;border-bottom:1px solid rgba(15,23,42,.06)}
.ptr-kv:last-child{border-bottom:0}
.ptr-kv-label{font-size:13px;font-weight:700;color:#334155}
.ptr-kv-value{font-size:13px;font-weight:800;color:#102845;text-align:right}
.ptr-chip-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
.ptr-chip{display:inline-flex;align-items:center;justify-content:center;min-height:28px;padding:0 10px;border-radius:999px;font-size:11px;font-weight:800;border:1px solid rgba(15,23,42,.08)}
.ptr-chip.ok{background:#ecfdf5;color:#166534;border-color:#bbf7d0}
.ptr-chip.warning{background:#fff7ed;color:#c2410c;border-color:#fdba74}
.ptr-chip.danger{background:#fee2e2;color:#991b1b;border-color:#fca5a5}
.ptr-chip.info{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe}
.ptr-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}
.ptr-btn{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:0 16px;border-radius:12px;border:1px solid #12355f;background:#12355f;color:#fff;font-size:13px;font-weight:800;text-decoration:none;cursor:pointer}
.ptr-btn.secondary{background:#fff;color:#12355f}
.ptr-btn.warning{background:#92400e;border-color:#92400e}
.ptr-btn.danger{background:#991b1b;border-color:#991b1b}
.ptr-table-wrap{overflow:auto}
.ptr-table{width:100%;border-collapse:collapse}
.ptr-table th,.ptr-table td{padding:11px 12px;border-bottom:1px solid rgba(15,23,42,.08);text-align:left;font-size:13px;vertical-align:top}
.ptr-table th{font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#64748b}
.ptr-code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;word-break:break-word}
.ptr-list{margin:0;padding-left:18px;color:#334155;font-size:13px;line-height:1.6}
.ptr-link-list{display:flex;flex-direction:column;gap:10px}
.ptr-link-card{padding:14px 16px;border:1px solid rgba(15,23,42,.08);border-radius:16px;background:#fff}
.ptr-link-title{font-size:13px;font-weight:800;color:#102845;margin-bottom:6px}
.ptr-link-sub{font-size:12px;color:#64748b;line-height:1.5}
@media (max-width: 1080px){
    .ptr-grid{grid-template-columns:1fr}
}
</style>

<div class="ptr-page">

    <section class="card ptr-hero">
        <div class="ptr-eyebrow">Instructor Platform · Progress Test Review</div>
        <h1 class="ptr-title">Progress Test Review</h1>
        <div class="ptr-sub">
            Student: <strong><?php echo ptr_h((string)$testRow['student_name']); ?></strong><br>
            Cohort: <strong><?php echo ptr_h((string)$testRow['cohort_name']); ?></strong><br>
            Lesson: <strong><?php echo ptr_h((string)$testRow['lesson_title']); ?></strong>
        </div>

        <?php if ($flashSuccess !== ''): ?>
            <div class="ptr-alert success" style="margin-top:16px;">
                <strong><?php echo ptr_h($flashSuccess); ?></strong>
                <?php if ($flashLines): ?>
                    <ul class="ptr-list" style="margin-top:8px;">
                        <?php foreach ($flashLines as $line): ?>
                            <li><?php echo ptr_h((string)$line); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if ($recoveryUrl !== ''): ?>
                    <div class="ptr-actions" style="margin-top:10px;">
                        <a class="ptr-btn" href="<?php echo ptr_h($recoveryUrl); ?>">Open Created Instructor Action</a>
                    </div>
                <?php elseif ($openUrl !== ''): ?>
                    <div class="ptr-actions" style="margin-top:10px;">
                        <a class="ptr-btn" href="<?php echo ptr_h($openUrl); ?>">Open Existing Recovery Link</a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($flashError !== ''): ?>
            <div class="ptr-alert error" style="margin-top:16px;">
                <strong><?php echo ptr_h($flashError); ?></strong>
            </div>
        <?php endif; ?>
    </section>

    <div class="ptr-grid">

        <section class="card ptr-card">
            <h2 class="ptr-card-title">Canonical Test Snapshot</h2>

            <div class="ptr-kv">
                <div class="ptr-kv-label">Current Test ID</div>
                <div class="ptr-kv-value"><?php echo (int)$testRow['id']; ?></div>
            </div>
            <div class="ptr-kv">
                <div class="ptr-kv-label">Attempt No</div>
                <div class="ptr-kv-value"><?php echo (int)($testRow['attempt_no'] ?? 0); ?></div>
            </div>
            <div class="ptr-kv">
                <div class="ptr-kv-label">Status</div>
                <div class="ptr-kv-value"><?php echo ptr_h((string)($testRow['status'] ?? '—')); ?></div>
            </div>
            <div class="ptr-kv">
                <div class="ptr-kv-label">Score</div>
                <div class="ptr-kv-value"><?php echo ptr_h((string)($testRow['score_pct'] ?? '—')); ?></div>
            </div>
            <div class="ptr-kv">
                <div class="ptr-kv-label">Formal Result</div>
                <div class="ptr-kv-value">
                    <?php
                    $formalCode = trim((string)($testRow['formal_result_code'] ?? ''));
                    $formalLabel = trim((string)($testRow['formal_result_label'] ?? ''));
                    echo ptr_h($formalLabel !== '' ? $formalLabel : $formalCode);
                    ?>
                </div>
            </div>
            <div class="ptr-kv">
                <div class="ptr-kv-label">Pass Gate Met</div>
                <div class="ptr-kv-value"><?php echo (int)($testRow['pass_gate_met'] ?? 0); ?></div>
            </div>
            <div class="ptr-kv">
                <div class="ptr-kv-label">Created At</div>
                <div class="ptr-kv-value"><?php echo ptr_h(ptr_format_dt((string)($testRow['created_at'] ?? ''))); ?></div>
            </div>
            <div class="ptr-kv">
                <div class="ptr-kv-label">Completed At</div>
                <div class="ptr-kv-value"><?php echo ptr_h(ptr_format_dt((string)($testRow['completed_at'] ?? ''))); ?></div>
            </div>
            <div class="ptr-kv">
                <div class="ptr-kv-label">Available Until</div>
                <div class="ptr-kv-value"><?php echo ptr_h(ptr_format_dt((string)($testRow['available_until_utc'] ?? ''))); ?></div>
            </div>

            <div class="ptr-chip-row">
                <span class="ptr-chip <?php echo $completedCount > 0 ? 'ok' : 'info'; ?>">
                    Completed Attempts: <?php echo $completedCount; ?>
                </span>
                <span class="ptr-chip <?php echo $readyCount > 1 ? 'danger' : ($readyCount === 1 ? 'warning' : 'info'); ?>">
                    Ready Attempts: <?php echo $readyCount; ?>
                </span>
                <span class="ptr-chip <?php echo $hasDuplicateReady ? 'danger' : 'ok'; ?>">
                    Duplicate Ready Rows: <?php echo $hasDuplicateReady ? 'Yes' : 'No'; ?>
                </span>
            </div>

            <div class="ptr-actions">
                <form method="post" style="margin:0;">
                    <input type="hidden" name="test_id" value="<?php echo (int)$testRow['id']; ?>">
                    <input type="hidden" name="admin_action" value="refresh_projection_snapshot">
                    <button class="ptr-btn secondary" type="submit">Refresh Projection Snapshot</button>
                </form>

                <form method="post" style="margin:0;">
                    <input type="hidden" name="test_id" value="<?php echo (int)$testRow['id']; ?>">
                    <input type="hidden" name="admin_action" value="safe_recover">
                    <button class="ptr-btn warning" type="submit">Run Safe Recovery</button>
                </form>

                <a class="ptr-btn secondary" href="/instructor/cohort_progress_overview.php?cohort_id=<?php echo (int)$testRow['cohort_id']; ?>">
                    Back to Cohort Overview
                </a>
            </div>
        </section>

        <section class="card ptr-card">
            <h2 class="ptr-card-title">Safe Recovery Actions</h2>

            <div class="ptr-link-list">

                <div class="ptr-link-card">
                    <div class="ptr-link-title">Run Safe Recovery</div>
                    <div class="ptr-link-sub">
                        This keeps every historical attempt intact, checks whether a required action link exists, creates an instructor approval action only when justified, and refreshes lesson_activity from canonical tables.
                    </div>
                </div>

                <div class="ptr-link-card">
                    <div class="ptr-link-title">Open Instructor Action</div>
                    <div class="ptr-link-sub">
                        <?php if ($openInstructorUrl !== ''): ?>
                            <a class="ptr-btn" href="<?php echo ptr_h($openInstructorUrl); ?>" style="margin-top:10px;">Open Instructor Approval</a>
                        <?php else: ?>
                            No active instructor link currently exists.
                        <?php endif; ?>
                    </div>
                </div>

                <div class="ptr-link-card">
                    <div class="ptr-link-title">Open Remediation Action</div>
                    <div class="ptr-link-sub">
                        <?php if ($openRemediationUrl !== ''): ?>
                            <a class="ptr-btn" href="<?php echo ptr_h($openRemediationUrl); ?>" style="margin-top:10px;">Open Remediation</a>
                        <?php else: ?>
                            No active remediation link currently exists.
                        <?php endif; ?>
                    </div>
                </div>

                <div class="ptr-link-card">
                    <div class="ptr-link-title">Open Reason Submission Action</div>
                    <div class="ptr-link-sub">
                        <?php if ($openReasonUrl !== ''): ?>
                            <a class="ptr-btn" href="<?php echo ptr_h($openReasonUrl); ?>" style="margin-top:10px;">Open Reason Submission</a>
                        <?php else: ?>
                            No active deadline-reason link currently exists.
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </section>

    </div>

    <section class="card ptr-card">
        <h2 class="ptr-card-title">All Attempts for This Lesson</h2>
        <div class="ptr-table-wrap">
            <table class="ptr-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Attempt</th>
                        <th>Status</th>
                        <th>Score</th>
                        <th>Formal Result</th>
                        <th>Pass Gate</th>
                        <th>Created</th>
                        <th>Completed</th>
                        <th>Timing</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($attemptRows as $row): ?>
                        <tr>
                            <td><?php echo (int)$row['id']; ?></td>
                            <td><?php echo (int)($row['attempt_no'] ?? 0); ?></td>
                            <td><?php echo ptr_h((string)($row['status'] ?? '')); ?></td>
                            <td><?php echo ptr_h((string)($row['score_pct'] ?? '—')); ?></td>
                            <td><?php echo ptr_h(trim((string)($row['formal_result_label'] ?? '')) !== '' ? (string)$row['formal_result_label'] : (string)($row['formal_result_code'] ?? '—')); ?></td>
                            <td><?php echo (int)($row['pass_gate_met'] ?? 0); ?></td>
                            <td><?php echo ptr_h(ptr_format_dt((string)($row['created_at'] ?? ''))); ?></td>
                            <td><?php echo ptr_h(ptr_format_dt((string)($row['completed_at'] ?? ''))); ?></td>
                            <td><?php echo ptr_h((string)($row['timing_status'] ?? '—')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$attemptRows): ?>
                        <tr>
                            <td colspan="9">No attempts found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <div class="ptr-grid">

        <section class="card ptr-card">
            <h2 class="ptr-card-title">Lesson Activity Projection</h2>

            <?php if ($lessonActivity): ?>
                <div class="ptr-kv">
                    <div class="ptr-kv-label">Completion Status</div>
                    <div class="ptr-kv-value"><?php echo ptr_h((string)($lessonActivity['completion_status'] ?? '—')); ?></div>
                </div>
                <div class="ptr-kv">
                    <div class="ptr-kv-label">Summary Status</div>
                    <div class="ptr-kv-value"><?php echo ptr_h((string)($lessonActivity['summary_status'] ?? '—')); ?></div>
                </div>
                <div class="ptr-kv">
                    <div class="ptr-kv-label">Test Pass Status</div>
                    <div class="ptr-kv-value"><?php echo ptr_h((string)($lessonActivity['test_pass_status'] ?? '—')); ?></div>
                </div>
                <div class="ptr-kv">
                    <div class="ptr-kv-label">Training Suspended</div>
                    <div class="ptr-kv-value"><?php echo (int)($lessonActivity['training_suspended'] ?? 0); ?></div>
                </div>
                <div class="ptr-kv">
                    <div class="ptr-kv-label">Reason Required</div>
                    <div class="ptr-kv-value"><?php echo (int)($lessonActivity['reason_required'] ?? 0); ?></div>
                </div>
                <div class="ptr-kv">
                    <div class="ptr-kv-label">Updated At</div>
                    <div class="ptr-kv-value"><?php echo ptr_h(ptr_format_dt((string)($lessonActivity['updated_at'] ?? ''))); ?></div>
                </div>
            <?php else: ?>
                <div class="ptr-alert error">No lesson_activity row exists for this lesson yet.</div>
            <?php endif; ?>
        </section>

        <section class="card ptr-card">
            <h2 class="ptr-card-title">Latest Summary</h2>

            <?php if ($summaryRow): ?>
                <div class="ptr-kv">
                    <div class="ptr-kv-label">Review Status</div>
                    <div class="ptr-kv-value"><?php echo ptr_h((string)($summaryRow['review_status'] ?? '—')); ?></div>
                </div>
                <div class="ptr-kv">
                    <div class="ptr-kv-label">Review Score</div>
                    <div class="ptr-kv-value"><?php echo ptr_h((string)($summaryRow['review_score'] ?? '—')); ?></div>
                </div>
                <div class="ptr-kv">
                    <div class="ptr-kv-label">Updated At</div>
                    <div class="ptr-kv-value"><?php echo ptr_h(ptr_format_dt((string)($summaryRow['updated_at'] ?? ''))); ?></div>
                </div>
            <?php else: ?>
                <div class="ptr-alert error">No summary row exists for this lesson yet.</div>
            <?php endif; ?>
        </section>

    </div>

    <section class="card ptr-card">
        <h2 class="ptr-card-title">Required Actions</h2>
        <div class="ptr-table-wrap">
            <table class="ptr-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Token</th>
                        <th>Created</th>
                        <th>Updated</th>
                        <th>Open</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requiredActions as $action): ?>
                        <?php $actionUrl = ptr_required_action_url($action); ?>
                        <tr>
                            <td><?php echo (int)$action['id']; ?></td>
                            <td><?php echo ptr_h((string)($action['action_type'] ?? '')); ?></td>
                            <td><?php echo ptr_h((string)($action['status'] ?? '')); ?></td>
                            <td class="ptr-code"><?php echo ptr_h((string)($action['token'] ?? '')); ?></td>
                            <td><?php echo ptr_h(ptr_format_dt((string)($action['created_at'] ?? ''))); ?></td>
                            <td><?php echo ptr_h(ptr_format_dt((string)($action['updated_at'] ?? ''))); ?></td>
                            <td>
                                <?php if ($actionUrl !== ''): ?>
                                    <a class="ptr-btn secondary" href="<?php echo ptr_h($actionUrl); ?>">Open</a>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$requiredActions): ?>
                        <tr>
                            <td colspan="7">No required actions found for this lesson.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

</div>

<?php cw_footer(); ?>