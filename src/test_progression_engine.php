<?php
declare(strict_types=1);

require_once __DIR__ . '/src/courseware_progression_v2.php';

/** @var PDO $pdo */
require_once __DIR__ . '/db_connect.php';

$engine = new CoursewareProgressionV2($pdo);

echo "<pre>";

echo "PASS % POLICY:\n";
var_dump($engine->getPolicy('progress_test_pass_pct'));

echo "\nALL POLICIES:\n";
var_dump($engine->getAllPolicies([
    'cohort_id' => 1
]));

echo "\nEFFECTIVE DEADLINE:\n";
var_dump($engine->getEffectiveDeadline(3, 1, 1));

echo "\nLOG EVENT:\n";
$eventId = $engine->logProgressionEvent([
    'user_id' => 3,
    'cohort_id' => 1,
    'lesson_id' => 1,
    'progress_test_id' => null,
    'event_type' => 'system_test',
    'event_code' => 'engine_bootstrap_test',
    'event_status' => 'info',
    'actor_type' => 'system',
    'payload' => ['note' => 'Bootstrap test event'],
    'legal_note' => 'Initial progression engine connectivity test.'
]);
var_dump($eventId);

echo "</pre>";