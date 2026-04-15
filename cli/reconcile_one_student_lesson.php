<?php
declare(strict_types=1);

putenv('DB_HOST=ipca-core-prod-do-user-23917502-0.i.db.ondigitalocean.com');
putenv('DB_NAME=ipca_courseware');
putenv('DB_USER=ipca_courseware_app');
putenv('DB_PASS=AVNS_UmBSv-HZSDvE7z5r5EC');

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/courseware_progression_v2.php';

$userId   = 38;
$cohortId = 6;
$lessonId = 1;

try {
    $engine = new CoursewareProgressionV2(cw_db());

    $context = $engine->getProgressionContextForUserLesson($userId, $cohortId, $lessonId);
    $projection = $engine->computeLessonActivityProjection($context);
    $engine->persistLessonActivityProjection($userId, $cohortId, $lessonId, $projection);

    echo "Projection refresh completed.\n";
    print_r($projection);
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}