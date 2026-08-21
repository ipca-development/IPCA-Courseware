<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$nav = (string)file_get_contents($root . '/src/nav/admin.php');
$checks = array(
    'Programs route' => str_contains($nav, '/admin/theory_studio/programs.php'),
    'Questions route' => str_contains($nav, '/admin/question_manager.php'),
    'Templates route' => str_contains($nav, '/admin/theory_studio/templates.php'),
    'Lesson Enhancements label' => str_contains($nav, 'Lesson Enhancements'),
    'Legacy Courses remains' => str_contains($nav, '/admin/courses.php'),
    'Legacy Lessons remains' => str_contains($nav, '/admin/lessons.php'),
    'Legacy Slides remains' => str_contains($nav, '/admin/slides.php'),
    'Bulk Import remains' => str_contains($nav, '/admin/import_lab.php'),
    'Cohorts remains' => str_contains($nav, '/admin/cohorts.php'),
    'Written Test remains' => str_contains($nav, '/admin/written_test.php'),
);
foreach ($checks as $label => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}
echo "Theory Content Studio nav contract: PASS\n";
