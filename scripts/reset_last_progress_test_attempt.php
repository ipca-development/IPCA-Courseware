<?php
declare(strict_types=1);

/**
 * Reset the latest progress test attempt for a student so the oral V4 test
 * can be taken again on the same prepared attempt row.
 *
 * Usage:
 *   php scripts/reset_last_progress_test_attempt.php --name=Kay
 *   php scripts/reset_last_progress_test_attempt.php --user-id=21 --apply
 *   php scripts/reset_last_progress_test_attempt.php --attempt-id=2323 --apply
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

$apply = false;
$userId = 0;
$attemptId = 0;
$name = '';

foreach (array_slice($argv ?? [], 1) as $arg) {
    if ($arg === '--apply') {
        $apply = true;
        continue;
    }
    if (preg_match('/^--user-id=(\d+)$/', $arg, $m)) {
        $userId = (int)$m[1];
        continue;
    }
    if (preg_match('/^--attempt-id=(\d+)$/', $arg, $m)) {
        $attemptId = (int)$m[1];
        continue;
    }
    if (preg_match('/^--name=(.+)$/', $arg, $m)) {
        $name = trim($m[1]);
        continue;
    }
    fwrite(STDERR, "Unknown argument: {$arg}\n");
    fwrite(STDERR, "Usage: php scripts/reset_last_progress_test_attempt.php [--name=Kay] [--user-id=UID] [--attempt-id=ID] [--apply]\n");
    exit(2);
}

$pdo = cw_db();

if ($attemptId <= 0) {
    if ($userId <= 0 && $name !== '') {
        $st = $pdo->prepare("SELECT id, name, email FROM users WHERE name LIKE ? ORDER BY id DESC LIMIT 1");
        $st->execute(['%' . $name . '%']);
        $user = $st->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            fwrite(STDERR, "No user found matching name: {$name}\n");
            exit(1);
        }
        $userId = (int)$user['id'];
        echo "Matched user #{$userId}: {$user['name']} ({$user['email']})\n";
    }
    if ($userId <= 0) {
        fwrite(STDERR, "Provide --attempt-id, --user-id, or --name.\n");
        exit(2);
    }
    $st = $pdo->prepare("
        SELECT id, user_id, cohort_id, lesson_id, attempt, status, score_pct, pass_gate_met,
               formal_result_code, formal_result_label, started_at, completed_at, updated_at
        FROM progress_tests_v2
        WHERE user_id = ?
        ORDER BY COALESCE(completed_at, updated_at, started_at) DESC, id DESC
        LIMIT 1
    ");
    $st->execute([$userId]);
    $attempt = $st->fetch(PDO::FETCH_ASSOC);
} else {
    $st = $pdo->prepare("
        SELECT id, user_id, cohort_id, lesson_id, attempt, status, score_pct, pass_gate_met,
               formal_result_code, formal_result_label, started_at, completed_at, updated_at
        FROM progress_tests_v2
        WHERE id = ?
        LIMIT 1
    ");
    $st->execute([$attemptId]);
    $attempt = $st->fetch(PDO::FETCH_ASSOC);
}

if (!$attempt) {
    fwrite(STDERR, "No progress test attempt found.\n");
    exit(1);
}

$attemptId = (int)$attempt['id'];
$userId = (int)$attempt['user_id'];

echo "=== Reset progress test attempt ===\n";
echo 'mode:       ' . ($apply ? 'APPLY' : 'READ-ONLY') . "\n";
echo "attempt_id: {$attemptId}\n";
echo "user_id:    {$userId}\n";
echo "lesson_id:  {$attempt['lesson_id']}\n";
echo "cohort_id:  {$attempt['cohort_id']}\n";
echo "attempt #:  {$attempt['attempt']}\n";
echo "status:     {$attempt['status']}\n";
echo "score:      " . ($attempt['score_pct'] === null ? 'NULL' : $attempt['score_pct']) . "\n";
echo "pass_gate:  " . (int)$attempt['pass_gate_met'] . "\n";
echo "result:     " . ($attempt['formal_result_code'] ?: '—') . ' / ' . ($attempt['formal_result_label'] ?: '—') . "\n\n";

if (!$apply) {
    echo "Would:\n";
    echo "  - delete oral responses, card sessions, answer chunks, integrity review\n";
    echo "  - delete badges earned on this attempt\n";
    echo "  - reset progress_test_items_v2 scoring/transcripts for this test\n";
    echo "  - set progress_tests_v2 back to status='ready' for oral retake\n";
    echo "  - revert lesson_activity to awaiting_test_completion\n";
    echo "\nRe-run with --apply to execute.\n";
    exit(0);
}

$pdo->beginTransaction();
try {
    $pdo->prepare('DELETE FROM progress_test_oral_item_responses WHERE attempt_id = ?')->execute([$attemptId]);
    $pdo->prepare('DELETE FROM progress_test_v4_card_sessions WHERE attempt_id = ?')->execute([$attemptId]);
    $pdo->prepare('DELETE FROM progress_test_v4_answer_chunks WHERE attempt_id = ?')->execute([$attemptId]);
    $pdo->prepare('DELETE FROM progress_test_oral_integrity_reviews WHERE attempt_id = ?')->execute([$attemptId]);
    $pdo->prepare('DELETE FROM progress_test_user_badges WHERE attempt_id = ?')->execute([$attemptId]);
    $pdo->prepare("
        UPDATE progress_test_items_v2
        SET transcript_text = NULL,
            is_correct = NULL,
            score_points = NULL,
            max_points = NULL,
            updated_at = NOW()
        WHERE test_id = ?
    ")->execute([$attemptId]);
    $pdo->prepare("
        UPDATE progress_tests_v2
        SET status = 'ready',
            score_pct = NULL,
            progress_pct = 100,
            pass_gate_met = 0,
            counts_as_unsat = 0,
            formal_result_code = NULL,
            formal_result_label = NULL,
            status_text = 'Progress test ready (reset for retake).',
            ai_summary = NULL,
            weak_areas = NULL,
            started_at = NULL,
            completed_at = NULL,
            timing_status = 'unknown',
            updated_at = NOW()
        WHERE id = ?
    ")->execute([$attemptId]);

    $pdo->prepare("
        UPDATE lesson_activity
        SET test_pass_status = 'in_progress',
            completion_status = 'awaiting_test_completion',
            status = 'awaiting_test_completion',
            completed_at = NULL,
            next_lesson_unlocked_at = NULL,
            last_state_eval_at = NOW(),
            updated_at = NOW()
        WHERE user_id = ?
          AND cohort_id = ?
          AND lesson_id = ?
    ")->execute([$userId, (int)$attempt['cohort_id'], (int)$attempt['lesson_id']]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Reset failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$fresh = $pdo->prepare('SELECT status, score_pct, pass_gate_met, status_text FROM progress_tests_v2 WHERE id = ? LIMIT 1');
$fresh->execute([$attemptId]);
$row = $fresh->fetch(PDO::FETCH_ASSOC) ?: [];

echo "Reset complete.\n";
echo 'New status: ' . ($row['status'] ?? '?') . "\n";
echo 'New score:  ' . ($row['score_pct'] === null ? 'NULL' : $row['score_pct']) . "\n";
echo 'Status txt: ' . ($row['status_text'] ?? '') . "\n";
echo "\nReload the progress test page and tap Ready to start again.\n";
echo "Note: if course progression still shows the lesson as passed, you may need a separate progression revert for a full retake from the course menu.\n";
