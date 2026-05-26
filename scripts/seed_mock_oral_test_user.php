<?php
declare(strict_types=1);

/**
 * Seed mock oral test prerequisites for a student (permission + theory-complete passes).
 *
 * Usage:
 *   php scripts/seed_mock_oral_test_user.php --user=14 --cohort=5
 *   php scripts/seed_mock_oral_test_user.php --user=14 --cohort=5 --apply
 */

$root = dirname(__DIR__);

$loadDotenv = static function (string $path): void {
    if (!is_readable($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $m)) {
            continue;
        }
        if (getenv($m[1]) !== false) {
            continue;
        }
        $val = $m[2];
        if ($val !== '' && (($val[0] === '"' && str_ends_with($val, '"')) || ($val[0] === "'" && str_ends_with($val, "'")))) {
            $val = substr($val, 1, -1);
        }
        putenv($m[1] . '=' . $val);
    }
};

if (!getenv('CW_DB_HOST')) {
    $loadDotenv($root . '/.env');
}

require_once $root . '/src/db.php';
require_once $root . '/src/courseware_progression_v2.php';

$opts = getopt('', ['user:', 'cohort:', 'apply', 'admin::']);
$userId = (int)($opts['user'] ?? 0);
$cohortId = (int)($opts['cohort'] ?? 0);
$apply = array_key_exists('apply', $opts);
$adminUserId = (int)($opts['admin'] ?? 1);

if ($userId <= 0 || $cohortId <= 0) {
    fwrite(STDERR, "Usage: php scripts/seed_mock_oral_test_user.php --user=14 --cohort=5 [--apply]\n");
    exit(1);
}

try {
    $pdo = cw_db();
} catch (Throwable $e) {
    fwrite(STDERR, 'Cannot connect: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$engine = new CoursewareProgressionV2($pdo);

$catalogId = (int)$pdo->query("SELECT id FROM mock_oral_acs_catalogs WHERE catalog_key = 'acs_private_pilot' AND is_active = 1 LIMIT 1")->fetchColumn();
if ($catalogId <= 0) {
    fwrite(STDERR, "Mock oral catalog not found.\n");
    exit(1);
}

$st = $pdo->prepare('SELECT status FROM cohort_students WHERE user_id = ? AND cohort_id = ? LIMIT 1');
$st->execute([$userId, $cohortId]);
$enrollmentStatus = (string)$st->fetchColumn();
if ($enrollmentStatus !== 'active') {
    fwrite(STDERR, "User {$userId} is not actively enrolled in cohort {$cohortId} (status={$enrollmentStatus}).\n");
    exit(1);
}

$st = $pdo->prepare('
    SELECT d.lesson_id, l.title
    FROM cohort_lesson_deadlines d
    INNER JOIN lessons l ON l.id = d.lesson_id
    WHERE d.cohort_id = ?
    ORDER BY d.lesson_id ASC
');
$st->execute([$cohortId]);
$lessons = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$plan = [
    'user_id' => $userId,
    'cohort_id' => $cohortId,
    'catalog_id' => $catalogId,
    'mode' => $apply ? 'apply' : 'dry_run',
    'permission' => [
        'enabled' => true,
        'exists' => $engine->hasMockOralPermission($userId, $cohortId, $catalogId),
    ],
    'theory_complete_before' => $engine->isTheoryCompleteForMockOral($userId, $cohortId),
    'lessons_to_seed' => [],
    'lessons_already_passed' => [],
];

foreach ($lessons as $lesson) {
    $lessonId = (int)$lesson['lesson_id'];
    if ($engine->hasCanonicalPassProgressTest($userId, $cohortId, $lessonId)) {
        $plan['lessons_already_passed'][] = $lessonId;
        continue;
    }
    $plan['lessons_to_seed'][] = [
        'lesson_id' => $lessonId,
        'title' => (string)$lesson['title'],
    ];
}

echo json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

if (!$apply) {
    echo "\nDry run only. Re-run with --apply to write rows.\n";
    exit(0);
}

try {
    if (!$plan['permission']['exists']) {
        $engine->setMockOralPermission($userId, $cohortId, true, $adminUserId, 'Seeded for mock oral flow testing.', $catalogId);
    } elseif (!$engine->hasMockOralPermission($userId, $cohortId, $catalogId)) {
        $engine->setMockOralPermission($userId, $cohortId, true, $adminUserId, 'Re-enabled for mock oral flow testing.', $catalogId);
    }

    $insert = $pdo->prepare("
        INSERT INTO progress_tests_v2 (
            user_id, cohort_id, lesson_id, attempt, status, score_pct, seed, manifest_json,
            ai_summary, weak_areas, debrief_spoken, summary_quality, summary_issues,
            summary_corrections, confirmed_misunderstandings, started_at, completed_at,
            effective_deadline_utc, deadline_source, timing_status, formal_result_code,
            formal_result_label, pass_gate_met, counts_as_unsat, remediation_triggered,
            extension_context_id, finalized_by_logic_version, created_at, updated_at,
            progress_pct, status_text, idempotency_key
        ) VALUES (
            ?, ?, ?, 1, 'completed', 100, ?, '{}',
            'Seeded canonical PASS for mock oral testing.', 'No major weak areas identified.',
            'Seeded test pass.', 'Not assessed.', '', '', 'No major weak areas identified.',
            UTC_TIMESTAMP(), UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 DAY),
            'manual_override', 'on_time', 'PASS', 'Pass', 1, 0, 0,
            NULL, 'v2.0', UTC_TIMESTAMP(), UTC_TIMESTAMP(),
            100, 'Seeded for mock oral testing.', ?
        )
    ");

    foreach ($plan['lessons_to_seed'] as $lesson) {
        $lessonId = (int)$lesson['lesson_id'];
        $seed = bin2hex(random_bytes(16));
        $idempotencyKey = 'mock_oral_test_seed_u' . $userId . '_l' . $lessonId;
        $insert->execute([$userId, $cohortId, $lessonId, $seed, $idempotencyKey]);
        $engine->repairLessonActivityForCanonicalPassIfNeeded($userId, $cohortId, $lessonId);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Apply failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$after = [
    'mock_oral_enabled' => $engine->hasMockOralPermission($userId, $cohortId, $catalogId),
    'theory_complete' => $engine->isTheoryCompleteForMockOral($userId, $cohortId),
    'hub_url' => '/student/mock_oral.php?cohort_id=' . $cohortId,
];

echo "\n=== applied ===\n";
echo json_encode($after, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
