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
$seed = file_get_contents($root . '/scripts/seed_manual_change_architect_sms_ecairs.php');
$library = file_get_contents($root . '/public/admin/books_manuals/index.php');

architect_ui_assert(is_string($page) && is_string($css) && is_string($js), 'Architect UI files are missing.');
foreach (array(
    'Manual Change Wizzard',
    'What change do you want to make?',
    'Supporting evidence — optional',
    'Manual(s) to review',
    'Analyze Change',
    'What should actually be amended?',
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
architect_ui_assert(str_contains($page, 'data-mcw-impact-decision="MODIFY"'), 'Governed Modify control is missing.');
architect_ui_assert(str_contains($page, 'data-mcw-impact-decision="REJECT"'), 'Governed Reject control is missing.');
architect_ui_assert(str_contains($page, '/admin/api/books_manuals_change_architect_api.php'), 'Architect workspace is not isolated behind its own API.');
architect_ui_assert(str_contains($js, "request('analyze_change'"), 'Analyze Change is not connected to the Architect.');
architect_ui_assert(str_contains($js, "request('accept_impact_analysis'"), 'Impact acceptance is not connected to wizard progression.');
architect_ui_assert(str_contains($api, "case 'impact_decision':"), 'Architect impact decision API is missing.');
architect_ui_assert(str_contains($api, "case 'analyze_change':"), 'Wizard analysis API is missing.');
architect_ui_assert(!str_contains($api, 'BooksManualsChangeAssistantService'), 'Architect API must remain separate from the legacy Change Assistant.');
architect_ui_assert(str_contains($plans, 'recordImpactDecision('), 'Governed impact decision persistence is missing.');
architect_ui_assert(str_contains($plans, 'acceptImpactAnalysis('), 'Governed wizard progression is missing.');
architect_ui_assert(str_contains((string)$seed, 'SMS / ECCAIRS Occurrence Lifecycle'), 'Real SMS/ECCAIRS seed plan is missing.');
foreach (array('confidence percentage', 'candidate retrieval', 'canonical hashes', 'data-mca-inspector') as $forbidden) {
    architect_ui_assert(!str_contains($page, $forbidden), "Wizard exposes forbidden dashboard detail: {$forbidden}.");
}

echo "PASS: Manual Change Wizzard vertical workflow contract\n";
