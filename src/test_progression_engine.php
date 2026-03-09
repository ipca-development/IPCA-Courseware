<?php
declare(strict_types=1);

require_once __DIR__ . '/src/courseware_progression_v2.php';
require_once __DIR__ . '/db_connect.php'; // must define $pdo as PDO

$engine = new CoursewareProgressionV2($pdo);

echo '<pre>';

echo "PASS POLICY:\n";
var_dump($engine->getPolicy('progress_test_pass_pct'));

echo "\nSUMMARY REQUIRED:\n";
var_dump($engine->getPolicy('summary_required_before_test_start'));

echo "\nALL POLICIES FOR COHORT 1:\n";
var_dump($engine->getAllPolicies([
    'cohort_id' => 1
]));

echo "\nEFFECTIVE DEADLINE FOR user=3 cohort=1 lesson=1:\n";
try {
    var_dump($engine->getEffectiveDeadline(3, 1, 1));
} catch (Throwable $e) {
    echo 'Deadline lookup failed: ' . $e->getMessage() . "\n";
}

echo "\nTEST EVENT INSERT:\n";
try {
    $eventId = $engine->logProgressionEvent([
        'user_id' => 3,
        'cohort_id' => 1,
        'lesson_id' => 1,
        'event_type' => 'system_test',
        'event_code' => 'engine_bootstrap_test',
        'event_status' => 'info',
        'actor_type' => 'system',
        'payload' => [
            'test' => true,
            'logic_version' => CoursewareProgressionV2::LOGIC_VERSION
        ],
        'legal_note' => 'Bootstrap event test for progression engine.'
    ]);

    var_dump($eventId);
} catch (Throwable $e) {
    echo 'Event insert failed: ' . $e->getMessage() . "\n";
}

echo '</pre>';