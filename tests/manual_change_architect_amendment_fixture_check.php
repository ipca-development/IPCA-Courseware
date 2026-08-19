<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/publishing/BooksManualsChangeAuthorService.php';
require_once __DIR__ . '/../src/publishing/BooksManualsChangeReviewerService.php';

function amendment_fixture_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$fixture = require __DIR__ . '/fixtures/manual_change_architect_sms_amendment.php';
$pdo = new PDO('sqlite::memory:');
$author = new BooksManualsChangeAuthorService($pdo);
$reviewer = new BooksManualsChangeReviewerService($pdo);
$proposal = $author->assembleAmendmentProposal(
    $fixture['authorization'],
    $fixture['section_drafts'],
    $fixture['lifecycle']
);
$verification = $reviewer->verifyAmendmentProposal($proposal);

amendment_fixture_assert(
    $proposal['accepted_impact_numbers'] === array('3.3', '4.2', '5.6', '5.7', '8.1'),
    'Draft scope differs from the five accepted impacts.'
);
amendment_fixture_assert(
    $verification['status'] === 'READY_FOR_HUMAN_REVIEW',
    'Deterministic verification did not reach the human-review checkpoint.'
);
amendment_fixture_assert($verification['issues'] === array(), 'Verification reported amendment issues.');
amendment_fixture_assert(
    $verification['remaining_legacy_occurrence_references'] === array(),
    'Legacy occurrence-workflow references remain in drafted wording.'
);
amendment_fixture_assert(
    $verification['unsupported_capability_claims'] === array(),
    'Draft contains unsupported capability claims.'
);
amendment_fixture_assert(
    $verification['lifecycle_governance_gaps'] === array(),
    'A lifecycle state lacks accountable role, evidence, deadline control or closure gate.'
);
foreach ($verification['protected_section_verification'] as $protected) {
    amendment_fixture_assert(
        $protected['improperly_modified'] === false,
        "Protected Section {$protected['section_number']} was improperly modified."
    );
}
foreach ($verification['eccairs_5_6_4_1_checks'] as $check => $passed) {
    amendment_fixture_assert($passed === true, "Section 5.6.4.1 failed check: {$check}.");
}
amendment_fixture_assert($proposal['guardrails']['production_applied'] === false, 'Proposal was applied to production.');
amendment_fixture_assert(
    str_contains(
        $proposal['section_drafts']['5.6']['nodes']['5.6.4.3'],
        'Automated intermediate and final ECCAIRS updates are not operational'
    ),
    'The accepted ECCAIRS transitional limitation is missing.'
);

echo "PASS: Manual Change Architect amendment proposal and deterministic review\n";
