<?php
declare(strict_types=1);

/**
 * scripts/unblock_user21_lesson930_take_test.php
 *
 * One-time quick fix to let user 21 (cohort 10) take the progress test for
 * lesson 930 again.
 *
 * Why this is needed
 * ------------------
 * Test 1373 (attempt 3, lesson 930) is in the terminal state
 *   status='failed', formal_result_code='STALE_ABORTED'
 * which is the correct historical state - it was already cleaned up by
 * scripts/unblock_lessons_930_931.php. The student is still blocked because:
 *
 *   - student_required_actions row 1111 is a 'deadline_reason_submission'
 *     in status='completed' / lesson_activity.reason_decision='pending'
 *     (the student already submitted their reason; it's awaiting an
 *     instructor decision in the TCC pending-decisions queue), AND
 *   - effective_deadline_utc 2026-05-09 06:59 UTC has already passed.
 *
 * Until that action is approved with a fresh deadline, the engine will not
 * let the student start attempt 4.
 *
 * What this script does
 * ---------------------
 * Runs exactly the same path the TCC bulk-approve UI runs for this row:
 *
 *   CoursewareProgressionV2::approveDeadlineReasonSubmissionByInstructor(
 *       1111, $actorUserId, "<notes>", $deadlineExtensionDays
 *   )
 *
 * That:
 *   - Marks action 1111 status='approved', decision_code=
 *     'approve_deadline_reason_submission'
 *   - Inserts a manual_override deadline row that re-opens the lesson with
 *     a new effective_deadline_utc = now + N days
 *   - Sets lesson_activity.reason_decision='accepted', reason_required=0,
 *     completion_status='in_progress', test_pass_status='in_progress'
 *   - Logs deadline_reason_approved_by_instructor in
 *     training_progression_events
 *
 * After this runs, the student's UI will let them start a new attempt of
 * lesson 930 (attempt 4) before the new deadline.
 *
 * Modes
 * -----
 *   php scripts/unblock_user21_lesson930_take_test.php
 *       READ-ONLY (default). Prints current state of action 1111 + test 1373
 *       and what would change. No writes.
 *
 *   php scripts/unblock_user21_lesson930_take_test.php --apply
 *       APPLY with a 7-day extension and the default actor user id (0 ->
 *       NULL, recorded as system-initiated since this is a backfill, not
 *       an instructor decision).
 *
 *   php scripts/unblock_user21_lesson930_take_test.php --apply --days=14
 *       APPLY with a custom extension window (clamped to 1..10 days, matching
 *       the engine's own clamp inside approveDeadlineReasonSubmissionByInstructor).
 *
 *   php scripts/unblock_user21_lesson930_take_test.php --apply --actor=<uid>
 *       APPLY and attribute the decision to instructor user <uid>.
 *
 * Safety
 * ------
 *   - Defensive: re-validates action 1111 belongs to user 21 / cohort 10 /
 *     lesson 930 and is a deadline_reason_submission before doing anything.
 *   - Idempotent in spirit: if the action is already 'approved', the engine
 *     method itself accepts that state (see approveDeadlineReasonSubmissionByInstructor
 *     guard in src/courseware_progression_v2.php which allows status in
 *     [pending, opened, completed, approved]) but this script will refuse
 *     to re-apply once status='approved' to avoid stacking extra extensions
 *     on accident.
 */

$root = dirname(__DIR__);

$loadDotenv = static function (string $path): void {
    if (!is_readable($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $m)) {
            continue;
        }
        $key = $m[1];
        $val = $m[2];
        if ($val !== '' && (($val[0] === '"' && str_ends_with($val, '"')) || ($val[0] === "'" && str_ends_with($val, "'")))) {
            $val = substr($val, 1, -1);
        }
        if (getenv($key) !== false) {
            continue;
        }
        putenv($key . '=' . $val);
        $_ENV[$key] = $val;
    }
};

if (!getenv('CW_DB_HOST')) {
    $loadDotenv($root . '/.env');
}

require_once $root . '/src/db.php';
require_once $root . '/src/courseware_progression_v2.php';

$apply = false;
$days = 7;
$actor = 0;

foreach (array_slice($argv ?? [], 1) as $arg) {
    if ($arg === '--apply') { $apply = true; continue; }
    if (preg_match('/^--days=(\d+)$/', $arg, $m)) { $days = (int)$m[1]; continue; }
    if (preg_match('/^--actor=(\d+)$/', $arg, $m)) { $actor = (int)$m[1]; continue; }
    fwrite(STDERR, "Unknown argument: {$arg}\n");
    fwrite(STDERR, "Usage: php scripts/unblock_user21_lesson930_take_test.php [--apply] [--days=N] [--actor=UID]\n");
    exit(2);
}

$days = max(1, min(10, $days));

$USER_ID   = 21;
$COHORT_ID = 10;
$LESSON_ID = 930;
$ACTION_ID = 1111;

echo "=== Unblock user 21 / cohort 10 / lesson 930 progress test ===\n";
echo "mode:  " . ($apply ? "APPLY (writes will be persisted)" : "READ-ONLY (no writes)") . "\n";
echo "days:  +{$days} day extension from UTC now\n";
echo "actor: " . ($actor > 0 ? "user_id={$actor}" : "(system / NULL)") . "\n\n";

$pdo = cw_db();

$action = (function (PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT * FROM student_required_actions WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
})($pdo, $ACTION_ID);

$test = (function (PDO $pdo, int $userId, int $cohortId, int $lessonId): ?array {
    $st = $pdo->prepare("
        SELECT id, attempt, status, formal_result_code, started_at, completed_at,
               effective_deadline_utc, deadline_source
        FROM progress_tests_v2
        WHERE user_id = ? AND cohort_id = ? AND lesson_id = ?
        ORDER BY id DESC LIMIT 1
    ");
    $st->execute([$userId, $cohortId, $lessonId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
})($pdo, $USER_ID, $COHORT_ID, $LESSON_ID);

$activity = (function (PDO $pdo, int $userId, int $cohortId, int $lessonId): ?array {
    $st = $pdo->prepare("
        SELECT completion_status, test_pass_status, reason_required, reason_submitted,
               reason_decision, effective_deadline_utc, granted_extra_attempts,
               extension_count, last_state_eval_at
        FROM lesson_activity
        WHERE user_id = ? AND cohort_id = ? AND lesson_id = ?
        LIMIT 1
    ");
    $st->execute([$userId, $cohortId, $lessonId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
})($pdo, $USER_ID, $COHORT_ID, $LESSON_ID);

echo "Current state\n";
echo "-------------\n";
if (!$action) {
    echo "  action 1111: NOT FOUND. Aborting.\n";
    exit(1);
}
echo "  action 1111: action_type=" . (string)$action['action_type']
   . " status=" . (string)$action['status']
   . " user_id=" . (int)$action['user_id']
   . " cohort_id=" . (int)$action['cohort_id']
   . " lesson_id=" . (int)$action['lesson_id']
   . " progress_test_id=" . (string)($action['progress_test_id'] ?? 'NULL')
   . "\n";
echo "  latest test: " . ($test
        ? ("id=" . (int)$test['id']
            . " attempt=" . (int)$test['attempt']
            . " status=" . (string)$test['status']
            . " formal=" . (string)($test['formal_result_code'] ?? 'NULL')
            . " effective_deadline_utc=" . (string)($test['effective_deadline_utc'] ?? 'NULL'))
        : "(none)") . "\n";
echo "  lesson_activity: " . ($activity
        ? ("completion_status=" . (string)($activity['completion_status'] ?? '')
            . " test_pass_status=" . (string)($activity['test_pass_status'] ?? '')
            . " reason_decision=" . (string)($activity['reason_decision'] ?? '')
            . " reason_submitted=" . (int)($activity['reason_submitted'] ?? 0)
            . " effective_deadline_utc=" . (string)($activity['effective_deadline_utc'] ?? 'NULL')
            . " granted_extra_attempts=" . (int)($activity['granted_extra_attempts'] ?? 0)
            . " extension_count=" . (int)($activity['extension_count'] ?? 0))
        : "(no row)") . "\n\n";

if ((int)$action['user_id'] !== $USER_ID
    || (int)$action['cohort_id'] !== $COHORT_ID
    || (int)$action['lesson_id'] !== $LESSON_ID) {
    echo "ABORT: action 1111 is not for user 21 / cohort 10 / lesson 930. Refusing to act.\n";
    exit(1);
}
if ((string)$action['action_type'] !== 'deadline_reason_submission') {
    echo "ABORT: action 1111 is not a deadline_reason_submission. Refusing to act.\n";
    exit(1);
}
if ((string)$action['status'] === 'approved') {
    echo "ABORT: action 1111 is already 'approved'. To stack another extension, use the TCC manual\n";
    echo "       override workflow rather than re-running this script.\n";
    exit(0);
}
if (!in_array((string)$action['status'], ['pending', 'opened', 'completed'], true)) {
    echo "ABORT: action 1111 status='" . (string)$action['status'] . "' is not actionable.\n";
    exit(1);
}

echo "Planned changes (call CoursewareProgressionV2::approveDeadlineReasonSubmissionByInstructor)\n";
echo "------------------------------------------------------------------------------------------\n";
echo "  - student_required_actions.id=1111 -> status='approved',\n";
echo "      decision_code='approve_deadline_reason_submission'\n";
echo "  - student_lesson_deadline_overrides: insert manual_override row with\n";
echo "      new_deadline_utc = UTC_NOW + {$days} days\n";
echo "  - lesson_activity (user=21, cohort=10, lesson=930):\n";
echo "      reason_required=0, reason_decision='accepted',\n";
echo "      completion_status='in_progress', test_pass_status='in_progress',\n";
echo "      effective_deadline_utc <= UTC_NOW + {$days} days\n";
echo "  - training_progression_events: insert deadline_reason_approved_by_instructor\n\n";

if (!$apply) {
    echo "Dry-run only. Re-run with --apply to perform the changes.\n";
    exit(0);
}

try {
    $engine = new CoursewareProgressionV2($pdo);
    $result = $engine->approveDeadlineReasonSubmissionByInstructor(
        $ACTION_ID,
        $actor, // 0 -> stored as NULL (system)
        'Quick-fix unblock so the student can take the progress test (manual script).',
        $days,
        null,
        'unblock_user21_lesson930_take_test.php'
    );

    echo "OK: approveDeadlineReasonSubmissionByInstructor returned:\n";
    echo "  message: " . (string)($result['message'] ?? '') . "\n";
    echo "  deadline_reopened: " . (int)($result['deadline_reopened'] ?? 0) . "\n";
    echo "  reopened_effective_deadline_utc: " . (string)($result['reopened_effective_deadline_utc'] ?? '') . "\n";

    $verifyAction = (function (PDO $pdo, int $id): ?array {
        $st = $pdo->prepare("SELECT id, status, decision_code, decision_at FROM student_required_actions WHERE id = ?");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    })($pdo, $ACTION_ID);
    $verifyActivity = (function (PDO $pdo, int $u, int $c, int $l): ?array {
        $st = $pdo->prepare("
            SELECT completion_status, test_pass_status, reason_decision,
                   effective_deadline_utc, last_state_eval_at
            FROM lesson_activity WHERE user_id=? AND cohort_id=? AND lesson_id=?
        ");
        $st->execute([$u, $c, $l]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    })($pdo, $USER_ID, $COHORT_ID, $LESSON_ID);

    echo "\nPost-apply verification\n";
    echo "-----------------------\n";
    echo "  action 1111: status=" . (string)($verifyAction['status'] ?? '')
       . " decision_code=" . (string)($verifyAction['decision_code'] ?? '')
       . " decision_at=" . (string)($verifyAction['decision_at'] ?? '') . "\n";
    echo "  lesson_activity: completion_status=" . (string)($verifyActivity['completion_status'] ?? '')
       . " test_pass_status=" . (string)($verifyActivity['test_pass_status'] ?? '')
       . " reason_decision=" . (string)($verifyActivity['reason_decision'] ?? '')
       . " effective_deadline_utc=" . (string)($verifyActivity['effective_deadline_utc'] ?? '') . "\n";
    echo "\nDone. The student should now see lesson 930 unblocked and be able to start attempt 4.\n";
} catch (Throwable $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
