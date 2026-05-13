<?php
declare(strict_types=1);

/**
 * scripts/unblock_lessons_930_931.php
 *
 * One-time manual unblock for two specific live-student blockers:
 *
 *   Lesson 931 (user 21, cohort 10):
 *     - test 1375 (status=completed, formal_result_code=PASS)  -- canonical pass
 *     - test 1376 (status=ready, formal_result_code=NULL)       -- orphan sibling
 *     - Cause: BUG B (BG resurrected 1375 from STALE_ABORTED back to PASS)
 *     - Fix: stale-abort test 1376 + log audit + recompute lesson_activity projection
 *
 *   Lesson 930 (user 21, cohort 10):
 *     - test 1373 (status=ready, formal_result_code=NULL)       -- orphan ready
 *     - student_required_actions 1111 (deadline_reason_submission)
 *       with lesson_activity.reason_decision='pending' (student already submitted)
 *     - Cause: BUG A (cron dedupe poisoned future re-evals) + BUG D (no orphan-ready sweep)
 *     - Fix: stale-abort test 1373 + log audit + force engine re-evaluation so it sees
 *            the pending reason and creates the instructor_approval action
 *
 * Modes:
 *   php scripts/unblock_lessons_930_931.php                → READ-ONLY (default, prints plan)
 *   php scripts/unblock_lessons_930_931.php --apply        → APPLY both fixes
 *   php scripts/unblock_lessons_930_931.php --apply --only=931   → APPLY only lesson 931
 *   php scripts/unblock_lessons_930_931.php --apply --only=930   → APPLY only lesson 930
 *
 * Safety:
 *   - READ-ONLY mode prints what it would do; no writes.
 *   - APPLY mode locks the affected rows, performs each fix in its own transaction, and
 *     writes a full audit trail to training_progression_events + tcc_repair_log.
 *   - Idempotent: re-running after success is a no-op (the WHERE clauses guard against
 *     double-writes).
 *   - Defensive: if any row state has changed between scan and update, that specific fix
 *     is skipped and logged.
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
$only = null;

foreach (array_slice($argv ?? [], 1) as $arg) {
    if ($arg === '--apply') {
        $apply = true;
        continue;
    }
    if (preg_match('/^--only=(930|931)$/', $arg, $m)) {
        $only = (int)$m[1];
        continue;
    }
    fwrite(STDERR, "Unknown argument: $arg\n");
    fwrite(STDERR, "Usage: php scripts/unblock_lessons_930_931.php [--apply] [--only=930|931]\n");
    exit(2);
}

echo "=== One-time manual unblock for lessons 930 + 931 ===\n";
echo "mode: " . ($apply ? "APPLY (writes will be persisted)" : "READ-ONLY (no writes)") . "\n";
if ($only !== null) echo "only: lesson {$only}\n";
echo "\n";

$pdo = cw_db();

$USER_ID   = 21;
$COHORT_ID = 10;

function describe_row(?array $row): string {
    if (!$row) return '(none)';
    return 'id=' . (int)$row['id']
        . ' status=' . (string)$row['status']
        . ' formal_result_code=' . (string)($row['formal_result_code'] ?? 'NULL')
        . ' pass_gate_met=' . (int)($row['pass_gate_met'] ?? 0)
        . ' attempt=' . (int)($row['attempt'] ?? 0)
        . ' created_at=' . (string)$row['created_at']
        . ' completed_at=' . (string)($row['completed_at'] ?? '');
}

function fetch_pt_row(PDO $pdo, int $testId): ?array {
    $st = $pdo->prepare("SELECT * FROM progress_tests_v2 WHERE id = ? LIMIT 1");
    $st->execute([$testId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function fetch_action_row(PDO $pdo, int $actionId): ?array {
    $st = $pdo->prepare("SELECT * FROM student_required_actions WHERE id = ? LIMIT 1");
    $st->execute([$actionId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function fetch_activity_row(PDO $pdo, int $userId, int $cohortId, int $lessonId): ?array {
    $st = $pdo->prepare("SELECT * FROM lesson_activity WHERE user_id = ? AND cohort_id = ? AND lesson_id = ? LIMIT 1");
    $st->execute([$userId, $cohortId, $lessonId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function plan_and_apply_931(PDO $pdo, bool $apply, int $userId, int $cohortId): void {
    $LESSON_ID = 931;
    $TARGET_TEST_ID = 1376;
    $CANONICAL_PASS_TEST_ID = 1375;

    echo "--- Lesson 931 plan ---\n";

    $target = fetch_pt_row($pdo, $TARGET_TEST_ID);
    $pass   = fetch_pt_row($pdo, $CANONICAL_PASS_TEST_ID);
    $activity = fetch_activity_row($pdo, $userId, $cohortId, $LESSON_ID);

    echo "target (1376):    " . describe_row($target) . "\n";
    echo "canonical (1375): " . describe_row($pass) . "\n";
    echo "lesson_activity:  completion_status=" . (string)($activity['completion_status'] ?? '(no row)')
       . " test_pass_status=" . (string)($activity['test_pass_status'] ?? '')
       . " extension_count=" . (int)($activity['extension_count'] ?? 0)
       . "\n";

    if (!$target || !$pass) {
        echo "Lesson 931: required rows missing. Aborting plan.\n\n";
        return;
    }

    if (!in_array((string)$target['status'], ['ready', 'in_progress', 'preparing', 'processing'], true)) {
        echo "Lesson 931: target 1376 is already in terminal state ({$target['status']}). No action needed.\n\n";
        return;
    }

    if ((string)$pass['formal_result_code'] !== 'PASS' || (int)$pass['pass_gate_met'] !== 1) {
        echo "Lesson 931: canonical 1375 is no longer a PASS. Aborting.\n\n";
        return;
    }

    echo "Planned actions:\n";
    echo "  1. UPDATE progress_tests_v2 SET status='failed', formal_result_code='STALE_ABORTED', counts_as_unsat=0 WHERE id=1376\n";
    echo "  2. Insert training_progression_events row event_code='attempt_stale_aborted' (manual unblock)\n";
    echo "  3. Recompute lesson_activity projection from canonical PASS row (test 1375)\n";
    echo "  4. Insert tcc_repair_log audit row\n";

    if (!$apply) {
        echo "Lesson 931: dry-run, no writes performed.\n\n";
        return;
    }

    try {
        $pdo->beginTransaction();

        $lockStmt = $pdo->prepare("
            SELECT id FROM progress_tests_v2
            WHERE user_id = ? AND cohort_id = ? AND lesson_id = ?
            ORDER BY id FOR UPDATE
        ");
        $lockStmt->execute([$userId, $cohortId, $LESSON_ID]);
        $lockStmt->fetchAll();

        $update = $pdo->prepare("
            UPDATE progress_tests_v2
            SET status='failed',
                formal_result_code='STALE_ABORTED',
                counts_as_unsat=0,
                updated_at=UTC_TIMESTAMP()
            WHERE id = :id
              AND status IN ('ready','in_progress','preparing','processing')
              AND (formal_result_code IS NULL OR formal_result_code != 'PASS')
            LIMIT 1
        ");
        $update->execute([':id' => $TARGET_TEST_ID]);

        if ($update->rowCount() !== 1) {
            $pdo->rollBack();
            echo "Lesson 931: target 1376 state changed between plan and update. Re-run script to retry.\n\n";
            return;
        }

        $eventPayload = [
            'progress_test_id' => $TARGET_TEST_ID,
            'previous_status' => (string)$target['status'],
            'new_status' => 'failed',
            'formal_result_code' => 'STALE_ABORTED',
            'counts_as_unsat' => 0,
            'stale_reason' => 'one_time_manual_unblock_pre_repair_button_fix',
            'canonical_pass_test_id' => $CANONICAL_PASS_TEST_ID,
            'canonical_pass_completed_at' => (string)$pass['completed_at'],
            'manual_unblock_script' => 'scripts/unblock_lessons_930_931.php',
        ];
        $event = $pdo->prepare("
            INSERT INTO training_progression_events
            (user_id, cohort_id, lesson_id, progress_test_id, event_type, event_code, event_status,
             actor_type, actor_user_id, event_time, payload_json, legal_note)
            VALUES
            (:user_id, :cohort_id, :lesson_id, :progress_test_id, 'progress_test', 'attempt_stale_aborted',
             'warning', 'system', NULL, UTC_TIMESTAMP(), :payload,
             'One-time manual unblock for lesson 931 (test 1376) executed before TCC repair-button fix shipped. The canonical PASS (test 1375) remains untouched.')
        ");
        $event->execute([
            ':user_id' => $userId,
            ':cohort_id' => $cohortId,
            ':lesson_id' => $LESSON_ID,
            ':progress_test_id' => $TARGET_TEST_ID,
            ':payload' => json_encode($eventPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo "Lesson 931: stale-abort failed: " . $e->getMessage() . "\n\n";
        return;
    }

    try {
        $engine = new CoursewareProgressionV2($pdo);
        $recompute = $engine->recomputeLessonActivityProjectionFromCanonicalPass($userId, $cohortId, $LESSON_ID);

        echo "Lesson 931: projection recomputed from canonical PASS. Before completion_status="
           . (string)($recompute['before']['completion_status'] ?? '')
           . " test_pass_status=" . (string)($recompute['before']['test_pass_status'] ?? '')
           . " -> After completion_status=" . (string)($recompute['after']['completion_status'] ?? '')
           . " test_pass_status=" . (string)($recompute['after']['test_pass_status'] ?? '')
           . "\n";
    } catch (Throwable $e) {
        echo "Lesson 931: recompute projection failed: " . $e->getMessage() . "\n";
    }

    try {
        // tcc_repair_log.executed_by_user_id is NOT NULL; use 0 for system-initiated actions
        // (matches the project convention where system events use NULL for actor_user_id but
        // tcc_repair_log specifically requires a non-null integer).
        $auditStmt = $pdo->prepare("
            INSERT INTO tcc_repair_log
            (repair_code, issue_type, blocker_category, recurrence_key,
             student_id, cohort_id, lesson_id,
             detected_evidence_json, before_state_json, after_state_json, result_json,
             executed_by_user_id, executed_at_utc)
            VALUES
            ('cleanup_old_active_attempt_after_pass', 'old_active_progress_test_attempt', 'stale_bug',
             :recurrence_key, :student_id, :cohort_id, :lesson_id,
             :detected, :before_state, :after_state, :result, 0, UTC_TIMESTAMP())
        ");
        $auditStmt->execute([
            ':recurrence_key' => 'old_active_progress_test_attempt|cohort:' . $cohortId . '|lesson:' . $LESSON_ID,
            ':student_id' => $userId,
            ':cohort_id' => $cohortId,
            ':lesson_id' => $LESSON_ID,
            ':detected' => json_encode(['test_id' => $TARGET_TEST_ID, 'canonical_pass_test_id' => $CANONICAL_PASS_TEST_ID, 'source' => 'manual_unblock_script'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':before_state' => json_encode(['target_test_id' => $TARGET_TEST_ID, 'target_status_before' => (string)$target['status']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':after_state' => json_encode(['target_test_id' => $TARGET_TEST_ID, 'target_status_after' => 'failed/STALE_ABORTED'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':result' => json_encode(['ok' => true, 'source' => 'manual_unblock_script', 'note' => 'One-time manual unblock before repair-button fix shipped.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        echo "Lesson 931: tcc_repair_log audit row inserted.\n";
    } catch (Throwable $e) {
        echo "Lesson 931: tcc_repair_log insert failed (non-fatal): " . $e->getMessage() . "\n";
    }

    echo "Lesson 931: DONE.\n\n";
}

function plan_and_apply_930(PDO $pdo, bool $apply, int $userId, int $cohortId): void {
    $LESSON_ID = 930;
    $TARGET_TEST_ID = 1373;
    $REASON_ACTION_ID = 1111;

    echo "--- Lesson 930 plan ---\n";

    $target = fetch_pt_row($pdo, $TARGET_TEST_ID);
    $reason = fetch_action_row($pdo, $REASON_ACTION_ID);
    $activity = fetch_activity_row($pdo, $userId, $cohortId, $LESSON_ID);

    echo "orphan-ready (1373):     " . describe_row($target) . "\n";
    echo "reason action (1111):    status=" . (string)($reason['status'] ?? '(missing)')
       . " action_type=" . (string)($reason['action_type'] ?? '')
       . "\n";
    echo "lesson_activity:         reason_submitted=" . (int)($activity['reason_submitted'] ?? 0)
       . " reason_decision=" . (string)($activity['reason_decision'] ?? '')
       . " completion_status=" . (string)($activity['completion_status'] ?? '')
       . "\n";

    if (!$target) {
        echo "Lesson 930: target row missing. Aborting.\n\n";
        return;
    }

    echo "Planned actions:\n";
    if (in_array((string)$target['status'], ['ready', 'in_progress', 'preparing', 'processing'], true)) {
        echo "  1. UPDATE progress_tests_v2 SET status='failed', formal_result_code='STALE_ABORTED' WHERE id=1373\n";
        echo "  2. Insert training_progression_events 'attempt_stale_aborted'\n";
    } else {
        echo "  1. (skip: 1373 already in terminal state)\n";
    }
    echo "  3. Trigger engine re-evaluation via handleMissedDeadlineForLesson (this will see\n";
    echo "     reason_decision=pending and create the instructor_approval required action)\n";

    if (!$apply) {
        echo "Lesson 930: dry-run, no writes performed.\n\n";
        return;
    }

    if (in_array((string)$target['status'], ['ready', 'in_progress', 'preparing', 'processing'], true)) {
        try {
            $pdo->beginTransaction();

            $lockStmt = $pdo->prepare("
                SELECT id FROM progress_tests_v2
                WHERE user_id = ? AND cohort_id = ? AND lesson_id = ?
                ORDER BY id FOR UPDATE
            ");
            $lockStmt->execute([$userId, $cohortId, $LESSON_ID]);
            $lockStmt->fetchAll();

            $update = $pdo->prepare("
                UPDATE progress_tests_v2
                SET status='failed',
                    formal_result_code='STALE_ABORTED',
                    counts_as_unsat=0,
                    updated_at=UTC_TIMESTAMP()
                WHERE id = :id
                  AND status IN ('ready','in_progress','preparing','processing')
                  AND (formal_result_code IS NULL OR formal_result_code != 'PASS')
                LIMIT 1
            ");
            $update->execute([':id' => $TARGET_TEST_ID]);

            if ($update->rowCount() === 1) {
                $eventPayload = [
                    'progress_test_id' => $TARGET_TEST_ID,
                    'previous_status' => (string)$target['status'],
                    'new_status' => 'failed',
                    'formal_result_code' => 'STALE_ABORTED',
                    'counts_as_unsat' => 0,
                    'stale_reason' => 'one_time_manual_unblock_pre_cron_sweeper_ship',
                    'reason_action_id' => $REASON_ACTION_ID,
                    'manual_unblock_script' => 'scripts/unblock_lessons_930_931.php',
                ];
                $event = $pdo->prepare("
                    INSERT INTO training_progression_events
                    (user_id, cohort_id, lesson_id, progress_test_id, event_type, event_code, event_status,
                     actor_type, actor_user_id, event_time, payload_json, legal_note)
                    VALUES
                    (:user_id, :cohort_id, :lesson_id, :progress_test_id, 'progress_test', 'attempt_stale_aborted',
                     'warning', 'system', NULL, UTC_TIMESTAMP(), :payload,
                     'One-time manual unblock for lesson 930 (test 1373) executed before orphan-ready cron sweeper shipped.')
                ");
                $event->execute([
                    ':user_id' => $userId,
                    ':cohort_id' => $cohortId,
                    ':lesson_id' => $LESSON_ID,
                    ':progress_test_id' => $TARGET_TEST_ID,
                    ':payload' => json_encode($eventPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
                echo "Lesson 930: stale-abort applied + audit logged.\n";
            } else {
                echo "Lesson 930: target state changed between plan and update. Skipping stale-abort.\n";
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo "Lesson 930: stale-abort failed: " . $e->getMessage() . "\n";
            return;
        }
    }

    // INTENTIONALLY DO NOT call handleMissedDeadlineForLesson here.
    //
    // Earlier this script invoked the engine to "force re-evaluation". That over-escalated
    // past the pending instructor decision on action 1111 because the engine's
    // handleMissedDeadlineForLesson() does not check lesson_activity.reason_decision='pending'
    // (engine logic gap Q8) and willingly issues an additional extension whose new deadline
    // is already in the past (engine logic gap Q9).
    //
    // After Q4 (orphan-ready sweep) and Q1 (cron dedupe v=2) ship, the cron will naturally
    // re-evaluate this lesson on the next tick where action_status changes. The correct
    // next-step action for lesson 930 today is for the instructor to act on action 1111
    // (deadline_reason_submission, status=completed, reason_decision=pending). The lesson
    // should appear in the instructor's "pending decisions" queue without any further code
    // intervention.
    echo "Lesson 930: orphan staled. Pending instructor decision on action 1111 remains the\n";
    echo "Lesson 930: next-step action (lesson_activity.reason_decision='pending'). Verify the\n";
    echo "Lesson 930: instructor sees this lesson in their 'pending decisions' queue.\n\n";
}

if ($only === null || $only === 931) {
    plan_and_apply_931($pdo, $apply, $USER_ID, $COHORT_ID);
}
if ($only === null || $only === 930) {
    plan_and_apply_930($pdo, $apply, $USER_ID, $COHORT_ID);
}

echo "Done.\n";
