<?php
declare(strict_types=1);

/**
 * Delete ALL progress test attempts and related data for a student (full QA reset).
 *
 * Usage:
 *   php scripts/reset_all_progress_tests_for_user.php --discover --name=Kay
 *   php scripts/reset_all_progress_tests_for_user.php --name=Kay --apply
 *   php scripts/reset_all_progress_tests_for_user.php --user-id=UID --apply
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
require_once $root . '/src/progress_test_remote.php';

$apply = false;
$discover = false;
$userId = 0;
$name = '';

foreach (array_slice($argv ?? [], 1) as $arg) {
    if ($arg === '--apply') {
        $apply = true;
        continue;
    }
    if ($arg === '--discover') {
        $discover = true;
        continue;
    }
    if (preg_match('/^--user-id=(\d+)$/', $arg, $m)) {
        $userId = (int)$m[1];
        continue;
    }
    if (preg_match('/^--name=(.+)$/', $arg, $m)) {
        $name = trim($m[1]);
        continue;
    }
    fwrite(STDERR, "Unknown argument: {$arg}\n");
    fwrite(STDERR, "Usage: php scripts/reset_all_progress_tests_for_user.php [--discover] [--name=Kay] [--user-id=UID] [--apply]\n");
    exit(2);
}

$pdo = cw_db();

if ($userId <= 0 && $name !== '') {
    $st = $pdo->prepare('SELECT id, name, email FROM users WHERE name LIKE ? OR email LIKE ? ORDER BY id DESC LIMIT 5');
    $st->execute(['%' . $name . '%', '%' . $name . '%']);
    $users = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$users) {
        fwrite(STDERR, "No user found matching: {$name}\n");
        exit(1);
    }
    if (count($users) > 1 && !$discover) {
        echo "Multiple users matched — use --discover or pass --user-id explicitly:\n";
        foreach ($users as $user) {
            echo "  #{$user['id']} {$user['name']} ({$user['email']})\n";
        }
        exit(1);
    }
    $userId = (int)$users[0]['id'];
    echo "Using user #{$userId}: {$users[0]['name']} ({$users[0]['email']})\n";
}

if ($userId <= 0) {
    fwrite(STDERR, "Provide --user-id or --name.\n");
    exit(2);
}

$countSt = $pdo->prepare('SELECT COUNT(*) FROM progress_tests_v2 WHERE user_id = ?');
$countSt->execute([$userId]);
$attemptCount = (int)$countSt->fetchColumn();

$authSt = $pdo->prepare('SELECT COUNT(*) FROM progress_test_remote_authorizations WHERE student_id = ?');
$authSt->execute([$userId]);
$authCount = (int)$authSt->fetchColumn();

if ($discover) {
    echo "\n=== Progress test attempts ===\n";
    $listSt = $pdo->prepare("
        SELECT t.id, t.cohort_id, t.lesson_id, l.title AS lesson_title, t.status, t.pass_gate_met,
               t.score_pct, t.idempotency_key, t.updated_at
        FROM progress_tests_v2 t
        LEFT JOIN lessons l ON l.id = t.lesson_id
        WHERE t.user_id = ?
        ORDER BY t.id DESC
        LIMIT 50
    ");
    $listSt->execute([$userId]);
    $rows = $listSt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows) {
        echo "  (none)\n";
    }
    foreach ($rows as $row) {
        echo "  #{$row['id']} cohort={$row['cohort_id']} lesson={$row['lesson_id']} ({$row['lesson_title']}) status={$row['status']} pass=" . (int)$row['pass_gate_met'] . " score=" . ($row['score_pct'] ?? '—') . "\n";
    }

    echo "\n=== Remote authorizations ===\n";
    $authList = $pdo->prepare("
        SELECT id, cohort_id, lesson_id, status, progress_test_attempt_id, expires_at
        FROM progress_test_remote_authorizations
        WHERE student_id = ?
        ORDER BY id DESC
        LIMIT 20
    ");
    $authList->execute([$userId]);
    $authRows = $authList->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$authRows) {
        echo "  (none)\n";
    }
    foreach ($authRows as $row) {
        echo "  #{$row['id']} cohort={$row['cohort_id']} lesson={$row['lesson_id']} status={$row['status']} attempt={$row['progress_test_attempt_id']}\n";
    }

    echo "\nTotal attempts: {$attemptCount}, remote auths: {$authCount}\n";
    echo "Run with --apply to delete all of the above (+ child rows, lesson_activity reset).\n";
    exit(0);
}

echo "=== Reset ALL progress tests for user ===\n";
echo 'mode:          ' . ($apply ? 'APPLY' : 'READ-ONLY') . "\n";
echo "user_id:       {$userId}\n";
echo "attempt rows:  {$attemptCount}\n";
echo "remote auths:  {$authCount}\n\n";

if ($attemptCount === 0 && $authCount === 0) {
    echo "Nothing to reset.\n";
    exit(0);
}

if (!$apply) {
    echo "Would delete:\n";
    echo "  - ALL progress_tests_v2 rows for this user\n";
    echo "  - oral/V4 child rows, voice events, bank question usage, user badges\n";
    echo "  - ALL progress_test_remote_authorizations (+ auth photos)\n";
    echo "  - reset lesson_activity for every affected cohort/lesson\n";
    echo "\nKeeps student_remote_test_permissions unchanged.\n";
    echo "Re-run with --apply to execute.\n";
    exit(0);
}

$deleteChildSql = [
    'DELETE r FROM progress_test_oral_item_responses r INNER JOIN progress_tests_v2 t ON t.id = r.attempt_id WHERE t.user_id = ?',
    'DELETE s FROM progress_test_v4_card_sessions s INNER JOIN progress_tests_v2 t ON t.id = s.attempt_id WHERE t.user_id = ?',
    'DELETE c FROM progress_test_v4_answer_chunks c INNER JOIN progress_tests_v2 t ON t.id = c.attempt_id WHERE t.user_id = ?',
    'DELETE i FROM progress_test_oral_integrity_reviews i INNER JOIN progress_tests_v2 t ON t.id = i.attempt_id WHERE t.user_id = ?',
    'DELETE b FROM progress_test_user_badges b INNER JOIN progress_tests_v2 t ON t.id = b.attempt_id WHERE t.user_id = ?',
    'DELETE d FROM progress_test_v4_debug_events d INNER JOIN progress_tests_v2 t ON t.id = d.attempt_id WHERE t.user_id = ?',
    'DELETE v FROM progress_test_voice_events v INNER JOIN progress_tests_v2 t ON t.id = v.attempt_id WHERE t.user_id = ?',
    'DELETE it FROM progress_test_items_v2 it INNER JOIN progress_tests_v2 t ON t.id = it.test_id WHERE t.user_id = ?',
];

$lessonPairs = [];
$pairSt = $pdo->prepare('SELECT DISTINCT cohort_id, lesson_id FROM progress_tests_v2 WHERE user_id = ?');
$pairSt->execute([$userId]);
foreach ($pairSt->fetchAll(PDO::FETCH_ASSOC) as $pair) {
    $lessonPairs[] = [(int)$pair['cohort_id'], (int)$pair['lesson_id']];
}

$deletedPhotos = 0;
$photoPathsInDb = 0;
$photoFilesMissing = 0;
$pdo->beginTransaction();
try {
    $photoSt = $pdo->prepare('SELECT id, student_photo_path FROM progress_test_remote_authorizations WHERE student_id = ?');
    $photoSt->execute([$userId]);
    foreach ($photoSt->fetchAll(PDO::FETCH_ASSOC) as $photoRow) {
        $photoPath = trim((string)($photoRow['student_photo_path'] ?? ''));
        if ($photoPath === '') {
            continue;
        }
        $photoPathsInDb++;
        $abs = ptr_photo_absolute_path($photoPath);
        if ($abs && is_file($abs)) {
            @unlink($abs);
            $deletedPhotos++;
        } else {
            $photoFilesMissing++;
        }
    }

    foreach ($deleteChildSql as $sql) {
        try {
            $pdo->prepare($sql)->execute([$userId]);
        } catch (Throwable $e) {
        }
    }

    try {
        $pdo->prepare('DELETE FROM progress_test_bank_question_usage WHERE user_id = ?')->execute([$userId]);
    } catch (Throwable $e) {
    }

    $pdo->prepare('DELETE FROM progress_test_remote_authorizations WHERE student_id = ?')->execute([$userId]);
    $delAttempts = $pdo->prepare('DELETE FROM progress_tests_v2 WHERE user_id = ?');
    $delAttempts->execute([$userId]);
    $deletedAttempts = $delAttempts->rowCount();

    foreach ($lessonPairs as [$cohortId, $lessonId]) {
        $pdo->prepare("
            UPDATE lesson_activity
            SET test_pass_status = 'in_progress',
                completion_status = 'awaiting_test_completion',
                status = 'awaiting_test_completion',
                completed_at = NULL,
                next_lesson_unlocked_at = NULL,
                last_state_eval_at = UTC_TIMESTAMP(),
                updated_at = UTC_TIMESTAMP()
            WHERE user_id = ? AND cohort_id = ? AND lesson_id = ?
        ")->execute([$userId, $cohortId, $lessonId]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Reset failed: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "Reset complete.\n";
echo "Deleted progress test attempts: {$deletedAttempts}\n";
echo "Auth rows with photo path in DB: {$photoPathsInDb}\n";
echo "Deleted auth photo files on this host: {$deletedPhotos}\n";
if ($photoFilesMissing > 0) {
    echo "Auth photo files not found on this host: {$photoFilesMissing}\n";
    echo "Note: photos are stored under storage/remote_progress_test_photos/ on the web server.\n";
    echo "Run this reset script on the production app host (or delete orphans there) to remove image files.\n";
}
echo "Reset lesson_activity rows: " . count($lessonPairs) . "\n";
echo "\nReload your course page — remote lessons should show Request Progress Test (off-site).\n";
