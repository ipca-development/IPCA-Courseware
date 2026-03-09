<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../src/courseware_progression_v2.php';
require_once __DIR__ . '/../config/database.php';

echo "<pre>";

$engine = new CoursewareProgressionEngine($pdo);

echo "===== POLICY TEST =====\n";

$passPct = $engine->getPolicyValue('progress_test_pass_pct');

echo "Pass percentage policy:\n";
var_dump($passPct);

echo "\n===== ALL POLICIES =====\n";
print_r($engine->getAllPolicies());

echo "\n===== DEADLINE TEST =====\n";

$deadline = $engine->getEffectiveDeadline(3,2,2);

print_r($deadline);

echo "\n===== EVENT INSERT TEST =====\n";

$eventId = $engine->logEvent([
    'event_code' => 'engine_bootstrap_test',
    'user_id' => 3,
    'cohort_id' => 2,
    'lesson_id' => 2,
    'progress_test_id' => null,
    'payload_json' => json_encode(['test'=>'engine']),
    'legal_note' => 'Engine bootstrap test'
]);

echo "Event inserted with ID: ".$eventId."\n";

echo "</pre>";