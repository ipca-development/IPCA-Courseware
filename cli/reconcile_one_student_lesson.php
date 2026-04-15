<?php
declare(strict_types=1);

putenv('DB_HOST=ipca-core-prod-do-user-23917502-0.i.db.ondigitalocean.com');
putenv('DB_NAME=ipca_courseware');
putenv('DB_USER=ipca_courseware_app');
putenv('DB_PASS=AVNS_UmBSv-HZSDvE7z5r5EC');

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/courseware_progression_v2.php';

$userId   = 38;
$cohortId = YOUR_COHORT_ID;
$lessonId = YOUR_LESSON_ID;

try {
    $engine = new CoursewareProgressionV2(cw_db());
    $result = $engine->reconcileAttemptAndRequiredActionState($userId, $cohortId, $lessonId);

    echo "Reconciliation completed.\n";
    print_r($result);
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}