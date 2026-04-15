<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/courseware_progression_v2.php';

$userId   = 38; // change if needed
$cohortId = 1;  // set the real cohort_id
$lessonId = 1;  // set the blocked lesson_id

try {
    $engine = new CoursewareProgressionV2(cw_db());

    $result = $engine->reconcileAttemptAndRequiredActionState($userId, $cohortId, $lessonId);

    echo "Reconciliation completed.\n";
    echo "User ID:   " . $userId . "\n";
    echo "Cohort ID: " . $cohortId . "\n";
    echo "Lesson ID: " . $lessonId . "\n";
    echo "\nResult:\n";
    print_r($result);
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}