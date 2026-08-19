<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/publishing/BooksManualsChangeAuthorService.php';
require_once __DIR__ . '/../src/publishing/BooksManualsChangeReviewerService.php';
require_once __DIR__ . '/../src/publishing/BooksManualsChangeStructureService.php';
require_once __DIR__ . '/../src/publishing/BooksManualsChangeOperationService.php';

function operationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$amendmentFixture = require __DIR__ . '/fixtures/manual_change_architect_sms_amendment_readable.php';
$structureFixture = require __DIR__ . '/fixtures/manual_change_architect_sms_structure_readable.php';
$canonical = require __DIR__ . '/fixtures/manual_change_architect_sms_canonical_targets.php';
$minimalPlan = require __DIR__ . '/fixtures/manual_change_architect_sms_minimal_operations.php';
$pdo = new PDO('sqlite::memory:');

$structure = (new BooksManualsChangeStructureService($pdo))->buildProposal(
    $structureFixture['title'],
    $structureFixture['rationale'],
    $structureFixture['source_fingerprint'],
    $structureFixture['areas']
);
$amendment = (new BooksManualsChangeAuthorService($pdo))->assembleAmendmentProposal(
    $amendmentFixture['authorization'],
    $amendmentFixture['section_drafts'],
    $amendmentFixture['lifecycle'],
    array('legacy_status' => $amendmentFixture['legacy_status'])
);
$review = (new BooksManualsChangeReviewerService($pdo))->verifyReadableAmendmentProposal($amendment);
$operationService = new BooksManualsChangeOperationService();
$provisional = $operationService->buildMinimalPackage(
    $canonical,
    $structure,
    $amendment,
    $review,
    $minimalPlan
);
$operationReview = (new BooksManualsChangeReviewerService($pdo))
    ->verifyMinimalOperationPackage($provisional, $amendment);
$package = $operationService->sealReviewedPackage($provisional, $operationReview);

operationAssert($review['status'] === 'READY_FOR_HUMAN_REVIEW', 'Independent Reviewer is not READY.');
operationAssert($package['status'] === 'READY_FOR_HUMAN_REVIEW', 'Operation package is not review-ready.');
operationAssert($package['operation_count'] === 8, 'Expected eight minimal canonical operations.');
operationAssert(
    $package['operation_type_counts'] === array(
        'INSERT_BLOCK' => 3,
        'REPLACE_BLOCK' => 2,
        'INSERT_BLOCKS' => 1,
        'RESTRUCTURE_SECTION_WITH_CONTENT' => 1,
        'UPDATE_TABLE' => 1,
    ),
    'Operation primitive counts are not minimal.'
);
foreach ($package['operations'] as $operation) {
    operationAssert($operation['source_version_id'] === 9, 'Canonical source version changed.');
    operationAssert($operation['destination_version_id'] === null, 'A destination was assigned before apply approval.');
    operationAssert($operation['applied'] === false, 'A content operation was applied.');
}
operationAssert($package['preserved_block_invariant']['verified'] === true, 'Preserved-block invariant failed.');
operationAssert($package['minimality_report']['4.2']['preserved_untouched'] === 5, 'Section 4.2 was unnecessarily replaced.');
operationAssert($package['minimality_report']['5.7']['preserved_untouched'] === 26, 'Section 5.7 was unnecessarily replaced.');
operationAssert($operationReview['status'] === 'READY_FOR_HUMAN_REVIEW', implode('; ', $operationReview['issues']));
operationAssert($operationReview['target_state_coverage_complete'] === true, 'Target-State coverage is incomplete.');
operationAssert($operationReview['unsupported_claims'] === array(), 'Unsupported claims remain.');
operationAssert($operationReview['change_plan_terms_in_content'] === false, 'Change Plan terminology remains in canonical content.');
operationAssert($operationReview['non_target_content_changed'] === false, 'Non-target canonical content changed.');
operationAssert(
    $package['legacy_reference_status'] === $amendmentFixture['legacy_status'],
    'Governed legacy dispositions changed.'
);
operationAssert($package['guardrails']['apply_authorized'] === false, 'Apply was authorized prematurely.');
operationAssert($package['guardrails']['applied'] === false, 'Package was applied.');
operationAssert($package['guardrails']['production_mutated'] === false, 'Production was mutated.');
operationAssert(
    preg_match('/^[a-f0-9]{64}$/', $package['operation_package_fingerprint']) === 1,
    'Operation package fingerprint is missing.'
);

echo "PASS: governed canonical operation package is READY and unapplied.\n";
