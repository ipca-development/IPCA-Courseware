<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = array(
    'architect' => $root . '/src/publishing/BooksManualsChangeArchitectService.php',
    'author' => $root . '/src/publishing/BooksManualsChangeAuthorService.php',
    'reviewer' => $root . '/src/publishing/BooksManualsChangeReviewerService.php',
    'orchestrator' => $root . '/src/publishing/BooksManualsChangePlanOrchestratorService.php',
);
foreach ($files as $role => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "FAIL: {$role} service is missing.\n");
        exit(1);
    }
}

$architect = (string)file_get_contents($files['architect']);
$author = (string)file_get_contents($files['author']);
$reviewer = (string)file_get_contents($files['reviewer']);
$orchestrator = (string)file_get_contents($files['orchestrator']);
$planService = (string)file_get_contents(
    $root . '/src/publishing/BooksManualsChangePlanService.php'
);

$checks = array(
    'Architect is read-only and does not draft' =>
        str_contains($architect, 'publishing_content_mutated')
        && str_contains($architect, 'contains_drafting_or_proposals'),
    'Author requires human-approved impacts' =>
        str_contains($author, "['status'] ?? '') === 'approved'")
        && str_contains($author, 'human_approved_impacts_only'),
    'Author requires individually accepted structure nodes' =>
        str_contains($author, "['decision_status'] ?? '') === 'accepted'")
        && str_contains($author, 'accepted_structure_nodes_only'),
    'Semantic relevance cannot authorize drafting' =>
        str_contains($author, "'semantic_relevance_authorizes_drafting' => false"),
    'Author assembles only exact accepted impact and structure scope' =>
        str_contains($author, 'assembleAmendmentProposal')
        && str_contains($author, 'Draft sections must exactly equal the individually accepted impacts.')
        && str_contains($author, 'Drafting was attempted for unaccepted structure node'),
    'Generated drafts pass the same authorization boundary before persistence' =>
        str_contains($author, 'generateAndPersist')
        && str_contains($author, '$this->assembleAmendmentProposal(')
        && str_contains($author, 'validateGeneratedProposal')
        && str_contains($author, "'production_applied' => false"),
    'Reviewer has an independent prompt' =>
        str_contains($reviewer, 'manual-change-independent-reviewer-v1'),
    'Reviewer READY gate requires no unexplained exact legacy hits' =>
        str_contains($reviewer, 'zero unexplained exact legacy hits')
        && str_contains($reviewer, 'unexplained_exact_legacy_hits'),
    'Reviewer verifies protected sections, lifecycle gaps, and unsupported claims' =>
        str_contains($reviewer, 'verifyAmendmentProposal')
        && str_contains($reviewer, 'protected_section_verification')
        && str_contains($reviewer, 'lifecycle_governance_gaps')
        && str_contains($reviewer, 'unsupported_capability_claims'),
    'Orchestrator exposes separate role jobs' =>
        str_contains($orchestrator, "'job_role' => 'ARCHITECT'")
        && str_contains($orchestrator, "'job_role' => 'AMENDMENT_AUTHOR'")
        && str_contains($orchestrator, "'job_role' => 'INDEPENDENT_REVIEWER'"),
    'Human legacy decisions are immutable and do not create plans automatically' =>
        str_contains($planService, 'recordLegacyHitDecision')
        && str_contains($planService, "'create_related_plan' => false")
        && str_contains($planService, "'PRESERVE_WITH_JUSTIFICATION'"),
    'Accepted analysis governs visible legacy and review-separately dispositions' =>
        str_contains($planService, 'governAcceptedAnalysisDispositions')
        && str_contains($planService, 'human_acceptance_of_complete_impact_analysis')
        && str_contains($planService, "'governed_dispositions'"),
    'Author carries legacy accounting and review constraints into the draft' =>
        str_contains($author, 'legacyReferenceStatus')
        && str_contains($author, 'remaining_within_accepted_scope')
        && str_contains(
            $author,
            'Submission of the initial occurrence does not complete the reporting process'
        ),
);

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS {$label}\n";
}

echo "OK: Manual Change Architect role boundaries are separate.\n";
