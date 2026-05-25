<?php
declare(strict_types=1);

/**
 * Reset remote progress test authorization + open attempt data so the full
 * off-site flow can be re-tested from "Request Progress Test".
 *
 * Usage:
 *   php scripts/reset_remote_progress_test_flow.php --discover --name=Kay
 *   php scripts/reset_remote_progress_test_flow.php --name=Kay --cohort-id=5 --lesson-id=3
 *   php scripts/reset_remote_progress_test_flow.php --user-id=UID --cohort-id=5 --lesson-id=3 --apply
 *   php scripts/reset_remote_progress_test_flow.php --user-id=UID --cohort-id=5 --all-lessons --apply
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
require_once $root . '/src/progress_test_prep.php';
require_once $root . '/src/courseware_progression_v2.php';

$apply = false;
$discover = false;
$allLessons = false;
$userId = 0;
$cohortId = 0;
$lessonId = 0;
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
    if ($arg === '--all-lessons') {
        $allLessons = true;
        continue;
    }
    if (preg_match('/^--user-id=(\d+)$/', $arg, $m)) {
        $userId = (int)$m[1];
        continue;
    }
    if (preg_match('/^--cohort-id=(\d+)$/', $arg, $m)) {
        $cohortId = (int)$m[1];
        continue;
    }
    if (preg_match('/^--lesson-id=(\d+)$/', $arg, $m)) {
        $lessonId = (int)$m[1];
        continue;
    }
    if (preg_match('/^--name=(.+)$/', $arg, $m)) {
        $name = trim($m[1]);
        continue;
    }
    fwrite(STDERR, "Unknown argument: {$arg}\n");
    fwrite(STDERR, "Usage: php scripts/reset_remote_progress_test_flow.php [--discover] [--name=Kay] [--user-id=UID] [--cohort-id=CID] [--lesson-id=LID] [--all-lessons] [--apply]\n");
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

if ($discover) {
    echo "\n=== Active cohort enrollments ===\n";
    $enSt = $pdo->prepare("
        SELECT cs.cohort_id, c.name AS cohort_name
        FROM cohort_students cs
        INNER JOIN cohorts c ON c.id = cs.cohort_id
        WHERE cs.user_id = ? AND cs.status = 'active'
        ORDER BY cs.cohort_id ASC
    ");
    $enSt->execute([$userId]);
    foreach ($enSt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        echo "  cohort #{$row['cohort_id']}: {$row['cohort_name']}\n";
    }

    echo "\n=== Remote testing permissions ===\n";
    $permSt = $pdo->prepare('SELECT cohort_id, remote_testing_enabled, updated_at FROM student_remote_test_permissions WHERE student_id = ? ORDER BY cohort_id');
    $permSt->execute([$userId]);
    foreach ($permSt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        echo "  cohort #{$row['cohort_id']}: enabled=" . (int)$row['remote_testing_enabled'] . " updated={$row['updated_at']}\n";
    }

    echo "\n=== Remote authorizations ===\n";
    $authSt = $pdo->prepare("
        SELECT a.id, a.cohort_id, a.lesson_id, l.title AS lesson_title, a.status, a.progress_test_attempt_id, a.expires_at, a.created_at
        FROM progress_test_remote_authorizations a
        LEFT JOIN lessons l ON l.id = a.lesson_id
        WHERE a.student_id = ?
        ORDER BY a.id DESC
        LIMIT 30
    ");
    $authSt->execute([$userId]);
    $authRows = $authSt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$authRows) {
        echo "  (none)\n";
    }
    foreach ($authRows as $row) {
        echo "  auth #{$row['id']} cohort={$row['cohort_id']} lesson={$row['lesson_id']} ({$row['lesson_title']}) status={$row['status']} attempt={$row['progress_test_attempt_id']} expires={$row['expires_at']}\n";
    }

    echo "\n=== Open / recent progress test attempts ===\n";
    $attemptSt = $pdo->prepare("
        SELECT t.id, t.cohort_id, t.lesson_id, l.title AS lesson_title, t.status, t.attempt, t.pass_gate_met,
               t.idempotency_key, t.formal_result_code, t.updated_at
        FROM progress_tests_v2 t
        LEFT JOIN lessons l ON l.id = t.lesson_id
        WHERE t.user_id = ?
          AND (
            t.status IN ('preparing','ready','in_progress','processing')
            OR t.idempotency_key LIKE 'remote_auth_%'
            OR t.updated_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 14 DAY)
          )
        ORDER BY t.id DESC
        LIMIT 40
    ");
    $attemptSt->execute([$userId]);
    $attemptRows = $attemptSt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$attemptRows) {
        echo "  (none)\n";
    }
    foreach ($attemptRows as $row) {
        $prepared = '';
        $open = [
            'user_id' => $userId,
            'cohort_id' => (int)$row['cohort_id'],
            'lesson_id' => (int)$row['lesson_id'],
            'id' => (int)$row['id'],
            'status' => (string)$row['status'],
            'manifest_json' => null,
        ];
        $full = $pdo->prepare('SELECT * FROM progress_tests_v2 WHERE id = ? LIMIT 1');
        $full->execute([(int)$row['id']]);
        $fullRow = $full->fetch(PDO::FETCH_ASSOC);
        if ($fullRow && pt_prep_attempt_is_prepared($fullRow, $pdo)) {
            $prepared = ' PREPARED';
        }
        echo "  attempt #{$row['id']} cohort={$row['cohort_id']} lesson={$row['lesson_id']} ({$row['lesson_title']}) status={$row['status']}{$prepared} key=" . ($row['idempotency_key'] ?: '—') . " updated={$row['updated_at']}\n";
    }

    echo "\n=== Expected button state (off-site / not trusted IP) ===\n";
    $engine = new CoursewareProgressionV2($pdo);
    $seen = [];
    foreach ($attemptRows as $row) {
        $key = (int)$row['cohort_id'] . ':' . (int)$row['lesson_id'];
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $state = $engine->getProgressTestButtonState($userId, (int)$row['cohort_id'], (int)$row['lesson_id'], '');
        echo "  cohort={$row['cohort_id']} lesson={$row['lesson_id']} ({$row['lesson_title']}): mode={$state['mode']} label={$state['label']}\n";
    }
    foreach ($authRows as $row) {
        $key = (int)$row['cohort_id'] . ':' . (int)$row['lesson_id'];
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $state = $engine->getProgressTestButtonState($userId, (int)$row['cohort_id'], (int)$row['lesson_id'], '');
        echo "  cohort={$row['cohort_id']} lesson={$row['lesson_id']} ({$row['lesson_title']}): mode={$state['mode']} label={$state['label']}\n";
    }

    echo "\nUse the cohort_id + lesson_id above with --apply.\n";
    exit(0);
}

if ($cohortId <= 0) {
    fwrite(STDERR, "Provide --cohort-id (or run with --discover first).\n");
    exit(2);
}

if (!$allLessons && $lessonId <= 0) {
    fwrite(STDERR, "Provide --lesson-id or use --all-lessons for the cohort.\n");
    exit(2);
}

$attemptWhere = 't.user_id = ? AND t.cohort_id = ?';
$attemptParams = [$userId, $cohortId];
$authWhere = 'student_id = ? AND cohort_id = ?';
$authParams = [$userId, $cohortId];
$activityWhere = 'user_id = ? AND cohort_id = ?';
$activityParams = [$userId, $cohortId];

if (!$allLessons) {
    $attemptWhere .= ' AND t.lesson_id = ?';
    $attemptParams[] = $lessonId;
    $authWhere .= ' AND lesson_id = ?';
    $authParams[] = $lessonId;
    $activityWhere .= ' AND lesson_id = ?';
    $activityParams[] = $lessonId;
}

$attemptFilter = "
  AND NOT (t.status = 'completed' AND t.pass_gate_met = 1)
  AND (
    t.status IN ('preparing','ready','in_progress','processing')
    OR t.idempotency_key LIKE 'remote_auth_%'
    OR t.status = 'failed'
    OR t.updated_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)
  )
";

$authSt = $pdo->prepare("SELECT id, lesson_id, status, progress_test_attempt_id, student_photo_path, created_at FROM progress_test_remote_authorizations WHERE {$authWhere} ORDER BY id DESC");
$authSt->execute($authParams);
$auths = $authSt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$attemptSql = "
    SELECT t.id, t.lesson_id, t.status, t.attempt, t.pass_gate_met, t.idempotency_key, t.updated_at
    FROM progress_tests_v2 t
    WHERE {$attemptWhere} {$attemptFilter}
    ORDER BY t.id DESC
";
$attemptSt = $pdo->prepare($attemptSql);
$attemptSt->execute($attemptParams);
$attempts = $attemptSt->fetchAll(PDO::FETCH_ASSOC) ?: [];

echo "=== Reset remote progress test flow ===\n";
echo 'mode:       ' . ($apply ? 'APPLY' : 'READ-ONLY') . "\n";
echo "user_id:    {$userId}\n";
echo "cohort_id:  {$cohortId}\n";
echo 'scope:      ' . ($allLessons ? 'ALL LESSONS in cohort' : "lesson {$lessonId}") . "\n";
echo 'remote authorization rows: ' . count($auths) . "\n";
echo 'progress test attempt rows: ' . count($attempts) . "\n\n";

if ($auths) {
    echo "Remote authorizations:\n";
    foreach ($auths as $row) {
        echo "  #{$row['id']} lesson={$row['lesson_id']} status={$row['status']} attempt_id=" . ($row['progress_test_attempt_id'] ?: '—') . "\n";
    }
    echo "\n";
}

if ($attempts) {
    echo "Progress test attempts:\n";
    foreach ($attempts as $row) {
        echo "  #{$row['id']} lesson={$row['lesson_id']} status={$row['status']} pass=" . (int)$row['pass_gate_met'] . " key=" . ($row['idempotency_key'] ?: '—') . "\n";
    }
    echo "\n";
}

if (!$apply) {
    echo "Would delete:\n";
    echo "  - ALL progress_test_remote_authorizations in scope\n";
    echo "  - non-pass progress_tests_v2 rows in scope (+ child oral/V4 rows)\n";
    echo "  - reset lesson_activity for affected lessons\n";
    echo "\nKeeps student_remote_test_permissions unchanged.\n";
    echo "Keeps completed PASS attempts (pass_gate_met=1).\n";
    echo "Re-run with --apply to execute.\n";
    exit(0);
}

$deletedPhotos = 0;
$deletedAuths = 0;
$deletedAttempts = 0;
$affectedLessons = [];

$pdo->beginTransaction();
try {
    foreach ($auths as $auth) {
        $photoPath = ptr_photo_absolute_path((string)($auth['student_photo_path'] ?? ''));
        if ($photoPath && is_file($photoPath)) {
            @unlink($photoPath);
            $deletedPhotos++;
        }
    }

    $delAuth = $pdo->prepare("DELETE FROM progress_test_remote_authorizations WHERE {$authWhere}");
    $delAuth->execute($authParams);
    $deletedAuths = $delAuth->rowCount();

    foreach ($attempts as $attempt) {
        $attemptId = (int)$attempt['id'];
        $affectedLessons[(int)$attempt['lesson_id']] = true;

        foreach ([
            'DELETE FROM progress_test_oral_item_responses WHERE attempt_id = ?',
            'DELETE FROM progress_test_v4_card_sessions WHERE attempt_id = ?',
            'DELETE FROM progress_test_v4_answer_chunks WHERE attempt_id = ?',
            'DELETE FROM progress_test_oral_integrity_reviews WHERE attempt_id = ?',
            'DELETE FROM progress_test_user_badges WHERE attempt_id = ?',
            'DELETE FROM progress_test_v4_debug_events WHERE attempt_id = ?',
        ] as $sql) {
            try {
                $pdo->prepare($sql)->execute([$attemptId]);
            } catch (Throwable $e) {
            }
        }

        $pdo->prepare('DELETE FROM progress_test_items_v2 WHERE test_id = ?')->execute([$attemptId]);
        $pdo->prepare('DELETE FROM progress_tests_v2 WHERE id = ?')->execute([$attemptId]);
        $deletedAttempts++;
    }

    if (!$allLessons && $lessonId > 0) {
        $affectedLessons[$lessonId] = true;
    }

    foreach (array_keys($affectedLessons) as $lid) {
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
        ")->execute([$userId, $cohortId, (int)$lid]);
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
echo "Deleted remote authorizations: {$deletedAuths}\n";
echo "Deleted progress test attempts: {$deletedAttempts}\n";
echo "Deleted auth photo files: {$deletedPhotos}\n";

if (!$allLessons && $lessonId > 0) {
    $engine = new CoursewareProgressionV2($pdo);
    $state = $engine->getProgressTestButtonState($userId, $cohortId, $lessonId, '');
    echo "\nExpected button after reset: mode={$state['mode']} label={$state['label']}\n";
    if (($state['mode'] ?? '') !== 'remote_request') {
        echo "NOTE: If mode is on_site_* you are on a trusted school IP — remote Request only appears off-site.\n";
        echo "NOTE: If mode is remote_start, a prepared attempt or auth row may still exist — re-run --discover.\n";
    }
}

echo "\nReload the course page — you should see Request Progress Test (amber) when off-site.\n";
