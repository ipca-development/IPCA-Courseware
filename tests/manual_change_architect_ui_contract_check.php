<?php
declare(strict_types=1);

/** @param mixed $condition */
function architect_ui_assert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$page = file_get_contents($root . '/public/admin/books_manuals/change_architect.php');
$css = file_get_contents($root . '/public/assets/manual-change-architect.css');
$js = file_get_contents($root . '/public/assets/manual-change-architect.js');
$api = file_get_contents($root . '/public/admin/api/books_manuals_change_architect_api.php');
$plans = file_get_contents($root . '/src/publishing/BooksManualsChangePlanService.php');
$architect = file_get_contents($root . '/src/publishing/BooksManualsChangeArchitectService.php');
$seed = file_get_contents($root . '/scripts/seed_manual_change_architect_sms_ecairs.php');
$library = file_get_contents($root . '/public/admin/books_manuals/index.php');

architect_ui_assert(is_string($page) && is_string($css) && is_string($js), 'Architect UI files are missing.');
foreach (array(
    'Manual Change Wizzard',
    'What change do you want to make?',
    'Supporting evidence — optional',
    'Manual(s) to review',
    'Analyze Change',
    'Review Proposed Manual Changes',
    'Accept Impact Analysis &amp; Continue',
    'Proposed Manual Structure',
    'Proposed Manual Amendments',
    'Independent Review',
    'Create Working Revision',
) as $label) {
    architect_ui_assert(stripos($page, $label) !== false, "Architect wizard label {$label} is missing.");
}
architect_ui_assert(str_contains((string)$library, "'Manual Change Wizzard'"), 'Library button was not renamed.');
architect_ui_assert(str_contains($css, 'max-width: 940px'), 'The wizard must use one readable vertical column.');
architect_ui_assert(!str_contains($css, '.mca-rail') && !str_contains($css, '.mca-inspector'), 'Permanent dashboard rails or inspectors must not remain.');
architect_ui_assert(str_contains($page, '$activeStep'), 'Future wizard steps are not progressively gated.');
architect_ui_assert(str_contains($page, 'mcw-step--complete'), 'Completed-step summaries are missing.');
architect_ui_assert(
    str_contains($page, '$startNew') && str_contains($page, 'change_architect.php?new=1')
        && str_contains($page, 'Start New Wizzard'),
    'Users must be able to start a blank Change Plan without deleting the current plan.'
);
architect_ui_assert(
    str_contains($page, "v.lifecycle_status IN ('draft','in_review')"),
    'Primary-manual choices must be limited to Draft and Draft Review revisions.'
);
architect_ui_assert(
    str_contains($page, "b.book_type NOT IN ('annex','annex_book')")
        && str_contains($page, 'annex_map.id IS NULL')
        && str_contains($page, 'legacy_annex.id IS NULL'),
    'Annex Books must not appear in the primary-manual selector.'
);
architect_ui_assert(str_contains($page, 'data-mcw-accept-impacts'), 'Single impact-analysis continuation action is missing.');
foreach (array(
    'Why this section is affected',
    'Current manual',
    'Canonical source',
    'Proposed change',
    'Architect recommendation',
    'What will remain unchanged',
    'Related amendments',
    'View complete current section',
    'View evidence / analysis',
    'Flag this section',
    'Request Changes',
) as $stepTwoLabel) {
    architect_ui_assert(str_contains($page, $stepTwoLabel), "Structured Step 2 label {$stepTwoLabel} is missing.");
}
architect_ui_assert(
    !str_contains($page, '<dt>What changes</dt>') && !str_contains($page, '<dt>What stays</dt>'),
    'Step 2 must not flatten Architect output into equal-width prose columns.'
);
architect_ui_assert(
    str_contains((string)$architect, 'ipca.manual-change-impact-presentation.v1')
        && str_contains((string)$architect, 'amendment_components')
        && str_contains((string)$architect, 'current_manual')
        && str_contains((string)$architect, 'canonicalReviewContexts(')
        && str_contains((string)$architect, 'manualAmendmentRows(')
        && str_contains((string)$architect, 'buildImpactPresentation('),
    'Architect reasoning must expose a deterministic structured presentation projection.'
);
architect_ui_assert(
    !str_contains($page, 'data-mcw-impact-decision="MODIFY"')
        && !str_contains($page, 'data-mcw-impact-decision="REJECT"')
        && !str_contains($page, 'Decision note'),
    'Step 2 must not force per-card Modify, Reject or Decision Note work.'
);
architect_ui_assert(
    str_contains($page, 'What should the Architect reconsider?')
        && str_contains($page, 'General / overall analysis')
        && str_contains($page, 'Re-analyze Impact')
        && str_contains($js, "request('request_impact_changes'")
        && str_contains($api, "case 'request_impact_changes':"),
    'Governed global impact correction and re-analysis is missing.'
);
architect_ui_assert(str_contains($page, '/admin/api/books_manuals_change_architect_api.php'), 'Architect workspace is not isolated behind its own API.');
architect_ui_assert(str_contains($js, "request('analyze_change'"), 'Analyze Change is not connected to the Architect.');
architect_ui_assert(
    str_contains($js, "request('analysis_status'") && str_contains($page, 'data-analysis-pending'),
    'Long-running analysis must progress by polling instead of holding the browser request open.'
);
architect_ui_assert(
    str_contains($page, 'data-mcw-progress-bar')
        && str_contains($page, 'data-mcw-progress-percent')
        && str_contains($page, 'data-mcw-progress-label')
        && str_contains($page, 'data-mcw-progress-elapsed')
        && str_contains($js, 'renderProgress(result.progress)')
        && str_contains($api, "'progress' => architect_api_read_progress")
        && str_contains($api, 'architect_api_progress_callback')
        && str_contains($architect, "'progress_callback'"),
    'Long-running analysis must expose and render determinate server-side stage progress.'
);
architect_ui_assert(str_contains($js, "request('accept_impact_analysis'"), 'Impact acceptance is not connected to wizard progression.');
architect_ui_assert(str_contains($api, "case 'analyze_change':"), 'Wizard analysis API is missing.');
architect_ui_assert(
    str_contains($api, 'architect_api_finish_response(202')
        && str_contains($api, "case 'analysis_status':")
        && str_contains($api, '$architect->runCheckpoint('),
    'Analysis must return its Plan before continuing the reasoning checkpoint.'
);
architect_ui_assert(!str_contains($api, 'BooksManualsChangeAssistantService'), 'Architect API must remain separate from the legacy Change Assistant.');
architect_ui_assert(str_contains($plans, 'recordImpactDecision('), 'Governed impact decision persistence is missing.');
architect_ui_assert(str_contains($plans, 'acceptImpactAnalysis('), 'Governed wizard progression is missing.');
architect_ui_assert(str_contains((string)$seed, 'SMS / ECCAIRS Occurrence Lifecycle'), 'Real SMS/ECCAIRS seed plan is missing.');
foreach (array('confidence percentage', 'candidate retrieval', 'canonical hashes', 'data-mca-inspector') as $forbidden) {
    architect_ui_assert(!str_contains($page, $forbidden), "Wizard exposes forbidden dashboard detail: {$forbidden}.");
}
architect_ui_assert(
    !str_contains($architect, "'section_title' => 'New target-state content'")
        && !str_contains($architect, "'impact_key' => 'new-target-state-content'"),
    'Unplaced target-state concepts must not become a synthetic manual amendment area.'
);

echo "PASS: Manual Change Wizzard vertical workflow contract\n";
