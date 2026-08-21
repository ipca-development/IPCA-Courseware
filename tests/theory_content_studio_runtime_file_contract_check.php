<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = array(
    'public/student/course.php',
    'public/student/courses.php',
    'public/student/dashboard.php',
    'src/navigation.php',
    'public/player/slide.php',
    'src/courseware_progression_v2.php',
    'src/time_based_progression_cron.php',
    'src/schedule.php',
);

foreach ($files as $rel) {
    $path = $root . '/' . $rel;
    if (!is_file($path)) {
        fwrite(STDERR, "FAIL: missing {$rel}\n");
        exit(1);
    }
    $src = (string)file_get_contents($path);
    if (str_contains($src, 'authoring_origin') || str_contains($src, 'theory_program_revisions') || str_contains($src, 'TheoryContentStudio')) {
        fwrite(STDERR, "FAIL: {$rel} was modified with Studio isolation predicates\n");
        exit(1);
    }
}

$required = array(
    'public/admin/cohorts.php' => 'theory_studio_operational_program_sql_for',
    'public/admin/cohort.php' => 'theory_studio_require_operational_program',
    'public/admin/written_test.php' => 'theory_studio_operational_program_sql_for',
    'public/admin/bulk_enrich.php' => 'theory_studio_operational_program_sql_for',
    'public/admin/lesson_summary_blueprints.php' => 'theory_studio_operational_program_sql_for',
    'src/communication/CommunicationTrainingVideoService.php' => 'theory_studio_operational_program_sql_for',
    'public/admin/import_lab.php' => 'theory_studio_require_operational_program',
);
foreach ($required as $rel => $needle) {
    $src = (string)file_get_contents($root . '/' . $rel);
    if (!str_contains($src, $needle)) {
        fwrite(STDERR, "FAIL: {$rel} missing {$needle}\n");
        exit(1);
    }
}

echo "Theory Content Studio student/player file contract: PASS\n";
