<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/publishing/BooksManualsChangePlanService.php';
require_once $root . '/src/publishing/BooksManualsChangeReviewResolutionService.php';

$migration = file_get_contents(
    $root . '/scripts/sql/2026_08_20_manual_change_review_convergence.sql'
) ?: '';
$api = file_get_contents(
    $root . '/public/admin/api/books_manuals_change_architect_api.php'
) ?: '';
$ui = file_get_contents(
    $root . '/public/admin/books_manuals/change_architect.php'
) ?: '';
$js = file_get_contents(
    $root . '/public/assets/manual-change-architect.js'
) ?: '';
$author = file_get_contents(
    $root . '/src/publishing/BooksManualsChangeAuthorService.php'
) ?: '';
$reviewer = file_get_contents(
    $root . '/src/publishing/BooksManualsChangeReviewerService.php'
) ?: '';
$recovery = file_get_contents(
    $root . '/scripts/recover_manual_change_architect_review.php'
) ?: '';
$historicalRepair = file_get_contents(
    $root . '/scripts/repair_manual_change_historical_scope.php'
) ?: '';
$contentPreview = file_get_contents(
    $root . '/scripts/prepare_manual_change_review_patch.php'
) ?: '';
$scopeReconciliation = file_get_contents(
    $root . '/scripts/reconcile_manual_change_review_scope.php'
) ?: '';
$planService = file_get_contents(
    $root . '/src/publishing/BooksManualsChangePlanService.php'
) ?: '';
$resolutionService = file_get_contents(
    $root . '/src/publishing/BooksManualsChangeReviewResolutionService.php'
) ?: '';

$service = (new ReflectionClass(BooksManualsChangeReviewResolutionService::class))
    ->newInstanceWithoutConstructor();
$classify = new ReflectionMethod($service, 'classify');
$questionFor = new ReflectionMethod($service, 'questionFor');
$cases = array(
    'Observation only; no manual change required.' => 'INFORMATIONAL',
    'Section 5.6 has an incorrect cross-reference.' => 'MECHANICAL_FIX',
    'Who assumes Safety Manager duties during prolonged unavailability?' => 'HUMAN_DECISION_REQUIRED',
    'Final human-review quality gate failed: 4.2 controls Section 5.6 evidence.' => 'TARGETED_AUTHOR_CORRECTION',
    'The source/destination mismatch makes this operation unsafe.' => 'HARD_INTEGRITY_BLOCKER',
    'New material requirement was not included in the accepted impact analysis.' => 'POTENTIAL_SCOPE_DEFECT',
);
$classificationsCorrect = true;
foreach ($cases as $issue => $expected) {
    $actual = $classify->invoke($service, $issue);
    $classificationsCorrect = $classificationsCorrect
        && (string)($actual['class'] ?? '') === $expected;
}
$hostingQuestion = $questionFor->invoke(
    $service,
    'Should the controlled manual specify physical hosting?',
    array('4.2'),
    array()
);
$questionConsequences = array_column((array)($hostingQuestion['choices'] ?? array()), 'consequence');

$checks = array(
    'migration is additive and defines all review artifacts' =>
        substr_count($migration, 'CREATE TABLE IF NOT EXISTS ') === 7
        && !preg_match('/\b(?:DROP|TRUNCATE)\s+TABLE\b|\bDELETE\s+FROM\b/i', $migration)
        && substr_count($migration, 'ALTER TABLE ') === 1
        && str_contains($migration, 'MODIFY COLUMN status VARCHAR(40)')
        && str_contains($migration, 'ipca_manual_ai_architect_review_baselines')
        && str_contains($migration, 'ipca_manual_ai_architect_review_findings')
        && str_contains($migration, 'ipca_manual_ai_architect_review_questions')
        && str_contains($migration, 'ipca_manual_ai_architect_review_answers')
        && str_contains($migration, 'ipca_manual_ai_architect_review_patches')
        && str_contains($migration, 'ipca_manual_ai_architect_review_cycles')
        && str_contains($migration, 'ipca_manual_ai_architect_review_check_metadata'),
    'five finding classes and potential scope defect are explicit' =>
        $classificationsCorrect
        && str_contains($migration, 'HARD_INTEGRITY_BLOCKER')
        && str_contains($migration, 'POTENTIAL_SCOPE_DEFECT'),
    'material questions are constrained and recommendation-free unless evidenced' =>
        is_array($hostingQuestion)
        && array_key_exists('recommendation', $hostingQuestion)
        && $hostingQuestion['recommendation'] === null
        && in_array('NO_MANUAL_CHANGE_REQUIRED', $questionConsequences, true)
        && in_array('TARGETED_WORDING_CHANGE_REQUIRED', $questionConsequences, true),
    'human answers become immutable governed facts' =>
        str_contains($migration, 'governed_fact_json JSON NOT NULL')
        && str_contains($migration, 'UNIQUE KEY uk_imaa_review_answer_question')
        && str_contains($api, 'answer_review_question')
        && str_contains($api, 'selected_choice_ids')
        && str_contains($resolutionService, '$rawConsequences')
        && str_contains(
            $resolutionService,
            "'wording_change_required' => \$consequence === 'STRUCTURAL_CONSEQUENCE'"
        ),
    'automatic Step 5 backward transition endpoint is disabled' =>
        str_contains($api, 'cannot automatically reopen or regenerate accepted Steps 2–4')
        && !str_contains($api, 'AMENDMENT_REVISION_REQUESTED_BY_REVIEW')
        && !str_contains($api, 'STRUCTURE_REVISION_REQUESTED_BY_REVIEW'),
    'explicit baseline reopening requires rationale and eligible finding' =>
        str_contains($api, "case 'reopen_impact_analysis'")
        && str_contains($api, "case 'reopen_proposed_structure'")
        && str_contains($api, "'rationale'")
        && str_contains($ui, 'data-mcw-explicit-reopen'),
    'Reviewer scope expansion becomes a human-governed potential scope defect' =>
        str_contains(
            file_get_contents($root . '/src/publishing/BooksManualsChangeReviewResolutionService.php') ?: '',
            'The Reviewer cannot silently expand accepted amendment scope.'
        )
        && str_contains($api, "case 'record_scope_follow_up'")
        && str_contains($ui, 'Record as Separate Follow-up'),
    'Author has scope-frozen targeted patch mode' =>
        str_contains($author, 'TARGETED PATCH MODE')
        && stripos($author, 'unrelated accepted wording') !== false
        && str_contains($author, 'Targeted Author attempted to add an unaccepted structure node')
        && str_contains($author, 'PRIOR TARGETED ATTEMPT FEEDBACK')
        && str_contains($author, 'Targeted Author correction failed stable checks:')
        && str_contains($author, "'production_applied' => false"),
    'Reviewer performs targeted reverification and scope checks' =>
        str_contains($reviewer, 'verifyTargetedPatch')
        && str_contains($reviewer, 'scopedCheckIds')
        && str_contains($reviewer, 'Targeted correction changed unrelated accepted wording')
        && str_contains($reviewer, "'reconciliation_checks' =>")
        && str_contains($reviewer, "'review_checks' => \$reviewChecks")
        && str_contains($reviewer, 'frozen_nodes_byte_unchanged')
        && str_contains($resolutionService, "\$verification['reconciliation_checks']")
        && str_contains($reviewer, "'architect_rerun_performed' => false"),
    'stable checks and patch verification states are persisted separately' =>
        str_contains($migration, 'check_id VARCHAR(191) NOT NULL')
        && str_contains($migration, 'resolution_status VARCHAR(24) NOT NULL')
        && str_contains($migration, 'HUMAN_ACCEPTED_PENDING_VERIFICATION')
        && str_contains($migration, 'VERIFICATION_FAILED')
        && str_contains($resolutionService, 'reconcileVerificationChecks')
        && str_contains($resolutionService, 'checks_fixed')
        && str_contains($resolutionService, 'new_checks'),
    'historical scope repair is deterministic and separately governed' =>
        str_contains($historicalRepair, 'HISTORICAL_SCOPE_REPAIR')
        && str_contains($historicalRepair, 'accepted_steps_2_4_unchanged')
        && str_contains($historicalRepair, 'source_manual_unchanged')
        && str_contains($resolutionService, 'repairHistoricalPatchScope')
        && str_contains($resolutionService, 'REVIEW_DIVERGENCE_RESOLVED'),
    'content correction previews remain human governed and node scoped' =>
        str_contains($contentPreview, 'human_acceptance_required')
        && str_contains($contentPreview, 'allowed_repair_nodes')
        && str_contains($contentPreview, 'frozen_unaffected_node_count')
        && str_contains($author, 'current_accepted_wording')
        && str_contains($author, 'target_state_evidence')
        && str_contains($author, 'preservation_boundaries'),
    'accepted-scope reconciliation does not rerun governed stages' =>
        str_contains($scopeReconciliation, 'reconcileAcceptedReviewScope')
        && str_contains($scopeReconciliation, 'drafts_unchanged')
        && str_contains($scopeReconciliation, 'review_baselines_unchanged')
        && str_contains($scopeReconciliation, "'architect_rerun_performed' => false")
        && str_contains($scopeReconciliation, "'manual_content_mutated' => false"),
    'targeted correction preflight reconciles the accepted candidate' =>
        str_contains($api, '$reviewResolution->reconcileAcceptedReviewScope(')
        && str_contains($api, "'reconciled_without_patch' => true"),
    'READY_TO_APPLY is strict and only moves forward' =>
        str_contains($api, '$reviewResolution->approveForApply(')
        && str_contains($resolutionService, "'outcome' => 'READY_TO_APPLY'")
        && str_contains($resolutionService, "empty(\$state['ready_to_apply'])")
        && str_contains($resolutionService, "'stage' => 'operations'")
        && str_contains($resolutionService, "'status' => 'ready_to_apply'")
        && str_contains($resolutionService, "\$planStage === 'operations'")
        && str_contains($resolutionService, "\$existingPayload['outcome']")
        && str_contains($api, 'Independent Review cannot automatically reopen'),
    'Step 5 is one-question-at-a-time without an Author correction loop' =>
        str_contains($ui, 'Question <?= $questionPosition ?> of')
        && str_contains($ui, 'Additional instruction (required for “Other”)')
        && str_contains($ui, 'Historical Scope Repair')
        && str_contains($ui, 'Content Correction')
        && str_contains($ui, 'Review Details')
        && str_contains($js, 'answer_review_question')
        && !str_contains($ui, 'Generate Correction for Remaining Issues')
        && str_contains($resolutionService, 'synchronizeClarificationQuestions')
        && str_contains($resolutionService, '$maxQuestions = 8')
        && str_contains($resolutionService, 'bounded-review-summary|')
        && str_contains($resolutionService, 'EDITOR_FOLLOW_UP_REQUIRED')
        && str_contains($resolutionService, "'author_correction_generated' => false"),
    'recovery is metadata-only and fingerprints accepted baselines' =>
        str_contains($recovery, "'manual_content_mutated' => false")
        && str_contains($recovery, "'architect_rerun_performed' => false")
        && str_contains($recovery, "'accepted_baselines_preserved'")
        && str_contains($recovery, "'regressions_detected_before_mutation'"),
    'large review JSON is never server-side filesorted' =>
        str_contains($planService, "'SELECT * FROM ' . \$table . ' WHERE '")
        && !str_contains(
            $planService,
            "'SELECT * FROM ' . \$table . ' WHERE ' . \$this->quoteIdentifier(\$foreignKey) . '=? ORDER BY id'"
        )
        && !preg_match(
            '/review_findings[^;]+ORDER BY id/s',
            $resolutionService
        )
        && str_contains($resolutionService, 'usort(')
        && str_contains($resolutionService, 'SELECT MAX(id) FROM ipca_manual_ai_architect_review_baselines'),
);

$failures = array();
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ' ' . $label . PHP_EOL;
    if (!$passed) {
        $failures[] = $label;
    }
}
if ($failures !== array()) {
    fwrite(STDERR, 'Failed convergent review checks: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}
echo "ok\n";
