<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/courseware_progression_v2.php';

echo '<pre>';

try {

    $engine = new CoursewareProgressionV2($pdo);

    echo "===== POLICY TEST =====\n";
    var_dump($engine->getPolicy('progress_test_pass_pct'));

    echo "\n===== ALL POLICIES =====\n";
    print_r($engine->getAllPolicies());

    echo "\n===== EVENT TEST =====\n";

    $eventId = $engine->logProgressionEvent([
        'user_id' => 3,
        'cohort_id' => 2,
        'lesson_id' => 2,
        'event_type' => 'system_test',
        'event_code' => 'engine_bootstrap_test',
        'event_status' => 'info',
        'actor_type' => 'system',
        'payload' => ['test' => true],
        'legal_note' => 'Bootstrap event test'
    ]);

    echo "Event inserted with ID: ".$eventId."\n";

} catch (Throwable $e) {

    echo "ERROR:\n";
    echo $e->getMessage()."\n\n";
    echo $e->getTraceAsString();

}

echo '</pre>';