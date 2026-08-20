<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/publishing/BooksManualsChangeAuthorService.php';
require_once __DIR__ . '/../src/publishing/BooksManualsChangeReviewerService.php';

function readableAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$fixture = require __DIR__ . '/fixtures/manual_change_architect_sms_amendment_readable.php';
$pdo = new PDO('sqlite::memory:');
$author = new BooksManualsChangeAuthorService($pdo);
$proposal = $author->assembleAmendmentProposal(
    $fixture['authorization'],
    $fixture['section_drafts'],
    $fixture['lifecycle'],
    array('legacy_status' => $fixture['legacy_status'])
);
$reviewer = new BooksManualsChangeReviewerService($pdo);
$review = $reviewer->verifyReadableAmendmentProposal($proposal);

readableAssert($review['status'] === 'READY_FOR_HUMAN_REVIEW', implode('; ', $review['issues']));
readableAssert($review['readability']['section_5_6_node_count'] === 10, 'Section 5.6 was not consolidated to ten operational nodes.');
readableAssert(!in_array('5.6.4.1', array_keys($fixture['section_drafts']['5.6']['nodes']), true), 'Target-State components still mirror numbered manual subsections.');
readableAssert($review['readability']['eccairs_stage_information_wording'] === true, 'The refined ECCAIRS stage-information wording is missing.');
readableAssert(!in_array(false, $review['readability']['preservation_checks'], true), 'A valid canonical reporting requirement was weakened.');
readableAssert(!in_array(false, $review['readability']['intermediate_final_follow_up_checks'], true), 'The central ECCAIRS follow-up control is incomplete.');
readableAssert(!in_array(false, $review['readability']['final_quality_checks'], true), 'A final human-review quality gate failed.');
readableAssert($review['readability']['validation_matrix_kept_out_of_manual'] === true, 'Validation artifacts leaked into the manual.');
readableAssert($review['legacy_reference_status']['remaining_within_accepted_scope'] === 0, 'Accepted-scope legacy references remain.');
readableAssert($review['legacy_reference_status']['outside_scope']['count'] === 5, 'Outside-scope governed legacy references were not separately reported.');
readableAssert($review['production_applied'] === false, 'The readability checkpoint must not apply production content.');

echo "PASS: readable amendment proposal preserves canonical SMS controls and remains human-review only.\n";
