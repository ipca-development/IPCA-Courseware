<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$sql = (string)file_get_contents($root . '/scripts/sql/2026_08_21_theory_content_studio_phase1.sql');
if ($sql === '') {
    fwrite(STDERR, "FAIL: migration SQL missing\n");
    exit(1);
}

$forbidden = array(
    'DROP TABLE' => '/\bDROP\s+TABLE\b/i',
    'DROP COLUMN' => '/\bDROP\s+COLUMN\b/i',
    'UPDATE programs' => '/\bUPDATE\s+programs\b/i',
    'UPDATE courses' => '/\bUPDATE\s+courses\b/i',
    'UPDATE lessons' => '/\bUPDATE\s+lessons\b/i',
    'UPDATE slides' => '/\bUPDATE\s+slides\b/i',
    'UPDATE cohorts' => '/\bUPDATE\s+cohorts\b/i',
    'UPDATE cohort_courses' => '/\bUPDATE\s+cohort_/i',
    'UPDATE slide_content' => '/\bUPDATE\s+slide_content\b/i',
    'UPDATE slide_enrichment' => '/\bUPDATE\s+slide_enrichment\b/i',
    'UPDATE progress_test' => '/\bUPDATE\s+progress_test_/i',
    'UPDATE lesson_summary' => '/\bUPDATE\s+lesson_summary_/i',
);

foreach ($forbidden as $label => $pattern) {
    if (preg_match($pattern, $sql)) {
        fwrite(STDERR, "FAIL: migration contains {$label}\n");
        exit(1);
    }
}

$required = array(
    'authoring_origin',
    'theory_program_revisions',
    'CREATE TABLE IF NOT EXISTS theory_program_revisions',
    "'legacy'",
    "'live'",
    "'studio'",
    "'operational'",
);

foreach ($required as $needle) {
    if (!str_contains($sql, $needle)) {
        fwrite(STDERR, "FAIL: migration missing {$needle}\n");
        exit(1);
    }
}

if (str_contains($sql, 'program_revision_id')) {
    fwrite(STDERR, "FAIL: Phase 1 must not add courses.program_revision_id\n");
    exit(1);
}

echo "Theory Content Studio schema contract: PASS\n";
