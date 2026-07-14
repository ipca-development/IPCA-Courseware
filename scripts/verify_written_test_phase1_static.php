<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$passes = [];

$check = static function (bool $condition, string $label) use (&$failures, &$passes): void {
    if ($condition) {
        $passes[] = $label;
    } else {
        $failures[] = $label;
    }
};

$read = static function (string $path): string {
    return is_readable($path) ? (string)file_get_contents($path) : '';
};

$migrationPath = $root . '/scripts/sql/2026_07_14_written_test_phase1_foundation.sql';
$migration = $read($migrationPath);

$check($migration !== '', 'Phase 1 migration exists');
foreach ([
    'written_test_programs',
    'cohort_written_test_allocations',
    'written_test_policy_versions',
    'written_test_access_overrides',
    'written_test_access_approvals',
] as $table) {
    $check(str_contains($migration, 'CREATE TABLE IF NOT EXISTS ' . $table), 'Migration creates ' . $table);
}

foreach ([
    'written_test_attempt',
    'written_test_session',
    'written_test_question',
    'written_test_answer',
    'written_test_image',
    'written_test_mastery',
] as $forbidden) {
    $check(!preg_match('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?' . preg_quote($forbidden, '/') . '/i', $migration), 'Migration does not create premature ' . $forbidden . '* tables');
}

$policyService = $read($root . '/src/written_test/WrittenTestPolicyService.php');
$accessService = $read($root . '/src/written_test/WrittenTestAccessService.php');
$support = $read($root . '/src/written_test/WrittenTestSupport.php');
$studentPage = $read($root . '/public/student/written_test.php');
$adminPage = $read($root . '/public/admin/written_test.php');
$coursePage = $read($root . '/public/student/course.php');
$studentNav = $read($root . '/src/nav/student.php');
$adminNav = $read($root . '/src/nav/admin.php');

$check(str_contains($policyService, 'resolved_policy_json'), 'Policy service writes resolved_policy_json');
$check(str_contains($policyService, 'source_scope_json'), 'Policy service stores source scope metadata');
$check(str_contains($policyService, "version_status = 'superseded'"), 'Policy publication supersedes prior published versions');
$check(str_contains($policyService, 'current_published_policy_version_id'), 'Allocation records current published policy version');
$check(str_contains($accessService, 'applyOverrides'), 'Access service applies formal overrides');
$check(str_contains($accessService, 'manual_denial'), 'Access service supports deny overrides');
$check(str_contains($accessService, 'written_test.require_complete_ground_school'), 'Access service checks Ground School completion policy');
$check(str_contains($accessService, 'written_test.require_progress_tests_completed'), 'Access service checks Progress Test prerequisite policy');
$check(str_contains($accessService, 'written_test_access_approvals'), 'Access service checks approval records');
$check(str_contains($support, 'POLICY_KEYS'), 'Written Test policy key catalog exists');
$check(str_contains($studentPage, 'Question Mastery') && str_contains($studentPage, 'Practice Mock Exams') && str_contains($studentPage, 'Supervised Mock Exam'), 'Student page reserves three mode cards without attempts');
$check(str_contains($adminPage, 'Publish Immutable Policy Snapshot'), 'Admin page exposes immutable policy publication');
$check(str_contains($adminPage, 'Student Access Diagnostics'), 'Admin page exposes student access diagnostics');
$check(str_contains($coursePage, 'Written Test Preparation') && str_contains($coursePage, 'writtenTestCourseStates'), 'Course page includes Written Test locked-state card');
$check(str_contains($studentNav, '/student/written_test.php'), 'Student navigation includes Written Test Preparation route');
$check(str_contains($adminNav, '/admin/written_test.php'), 'Admin navigation includes Written Test Preparation route');

echo "Written Test Preparation Phase 1 static verification\n";
echo "Passes: " . count($passes) . "\n";
foreach ($passes as $pass) {
    echo "  [OK] " . $pass . "\n";
}

if ($failures) {
    echo "Failures: " . count($failures) . "\n";
    foreach ($failures as $failure) {
        echo "  [FAIL] " . $failure . "\n";
    }
    exit(1);
}

echo "All static checks passed.\n";
