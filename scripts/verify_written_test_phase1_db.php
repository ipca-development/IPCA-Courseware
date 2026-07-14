<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/written_test/bootstrap.php';

$failures = [];
$warnings = [];
$passes = [];

$check = static function (bool $condition, string $label, bool $warnOnly = false) use (&$failures, &$warnings, &$passes): void {
    if ($condition) {
        $passes[] = $label;
        return;
    }
    if ($warnOnly) {
        $warnings[] = $label;
    } else {
        $failures[] = $label;
    }
};

WrittenTestSupport::ensureSchema($pdo);

foreach ([
    'written_test_programs',
    'cohort_written_test_allocations',
    'written_test_policy_versions',
    'written_test_access_overrides',
    'written_test_access_approvals',
] as $table) {
    $check(WrittenTestSupport::tableExists($pdo, $table), 'Required table exists: ' . $table);
}

foreach ([
    'written_test_attempts',
    'written_test_sessions',
    'written_test_questions',
    'written_test_answers',
    'written_test_images',
    'written_test_mastery',
] as $table) {
    $check(!WrittenTestSupport::tableExists($pdo, $table), 'Premature Phase 2+ table absent: ' . $table);
}

$policySvc = new WrittenTestPolicyService($pdo);
$allocationSvc = new WrittenTestAllocationService($pdo);
$accessSvc = new WrittenTestAccessService($pdo);

$definitions = $policySvc->definitions();
$definitionKeys = array_fill_keys(array_map(static fn($row) => (string)$row['policy_key'], $definitions), true);
foreach (WrittenTestSupport::POLICY_KEYS as $key) {
    $check(isset($definitionKeys[$key]), 'Policy definition installed: ' . $key);
}

$programs = (new WrittenTestProgramService($pdo))->listPrograms(true);
$check(count($programs) > 0, 'At least one Written Test Preparation program exists', true);

$allocations = $allocationSvc->listAllocations();
$check(true, 'Allocation query executed');
if (!$allocations) {
    $check(false, 'No cohort allocations exist yet; create an allocation to verify student access states', true);
} else {
    foreach ($allocations as $allocation) {
        $allocationId = (int)$allocation['id'];
        $version = $policySvc->currentPolicyVersionForAllocation($allocationId);
        if ((string)$allocation['allocation_status'] === 'active') {
            $check($version !== null, 'Active allocation has a current published policy version: #' . $allocationId);
        } else {
            $check(true, 'Non-active allocation may omit policy publication: #' . $allocationId);
        }

        if ($version !== null) {
            $payload = json_decode((string)$version['resolved_policy_json'], true);
            $source = json_decode((string)($version['source_scope_json'] ?? '{}'), true);
            $check(is_array($payload) && isset($payload['policy']) && is_array($payload['policy']), 'Policy version JSON contains immutable policy snapshot: #' . (int)$version['id']);
            $check(is_array($source) && isset($source['sources']), 'Policy version JSON contains source metadata: #' . (int)$version['id']);
        }
    }
}

if ($allocations) {
    $firstAllocationId = (int)$allocations[0]['id'];
    $students = $pdo->prepare("
        SELECT cs.user_id
        FROM cohort_students cs
        JOIN cohort_written_test_allocations a ON a.cohort_id = cs.cohort_id
        WHERE a.id = ?
        ORDER BY cs.user_id ASC
        LIMIT 3
    ");
    $students->execute([$firstAllocationId]);
    $studentIds = array_map('intval', $students->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if (!$studentIds) {
        $check(false, 'Selected allocation has no enrolled students for access evaluation', true);
    }
    foreach ($studentIds as $studentId) {
        $state = $accessSvc->evaluate($studentId, $firstAllocationId);
        $check(isset($state['state_code'], $state['access_granted'], $state['requirements'], $state['lock_reasons']), 'Access state has deterministic shape for student #' . $studentId);
        $check(is_array($state['requirements']), 'Access requirements list is structured for student #' . $studentId);
    }
}

echo "Written Test Preparation Phase 1 DB verification\n";
echo "Passes: " . count($passes) . "\n";
foreach ($passes as $pass) {
    echo "  [OK] " . $pass . "\n";
}
if ($warnings) {
    echo "Warnings: " . count($warnings) . "\n";
    foreach ($warnings as $warning) {
        echo "  [WARN] " . $warning . "\n";
    }
}
if ($failures) {
    echo "Failures: " . count($failures) . "\n";
    foreach ($failures as $failure) {
        echo "  [FAIL] " . $failure . "\n";
    }
    exit(1);
}

echo "All DB checks passed";
if ($warnings) {
    echo " with warnings";
}
echo ".\n";
