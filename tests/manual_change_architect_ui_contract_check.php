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

architect_ui_assert(is_string($page) && is_string($css) && is_string($js), 'Architect UI files are missing.');
foreach (array(
    'Change Request',
    'Impact Analysis',
    'Proposed Structure',
    'Draft Amendments',
    'Independent Review',
    'Apply',
    'What should actually be amended?',
    'Must preserve',
    'Out of scope',
    'Related issue — review separately',
    'Legacy references and dispositions',
) as $label) {
    architect_ui_assert(str_contains($page, $label), "Architect UI label {$label} is missing.");
}
architect_ui_assert(str_contains($css, 'grid-template-columns: 218px minmax(540px, 1fr) minmax(310px, 360px)'), 'Three-zone desktop layout is missing.');
architect_ui_assert(str_contains($css, '.mca-rail') && str_contains($css, '.mca-inspector'), 'Sticky stage rail or inspector styling is missing.');
architect_ui_assert(str_contains($page, 'data-mca-impact-card'), 'Substantial amendment-area cards are missing.');
architect_ui_assert(str_contains($page, 'data-mca-inspector'), 'Contextual amendment inspector is missing.');
architect_ui_assert(str_contains($page, 'data-mca-impact-decision="ACCEPT"'), 'Accept control is missing.');
architect_ui_assert(str_contains($page, 'data-mca-impact-decision="MODIFY"'), 'Modify control is missing.');
architect_ui_assert(str_contains($page, 'data-mca-impact-decision="REJECT"'), 'Reject control is missing.');
architect_ui_assert(str_contains($page, '/admin/api/books_manuals_change_architect_api.php'), 'Architect workspace is not isolated behind its own API.');
architect_ui_assert(str_contains($js, "api('impact_decision'"), 'Impact decisions are not connected to the API.');
architect_ui_assert(str_contains($api, "case 'impact_decision':"), 'Architect impact decision API is missing.');
architect_ui_assert(!str_contains($api, 'BooksManualsChangeAssistantService'), 'Architect API must remain separate from the legacy Change Assistant.');
architect_ui_assert(str_contains($plans, 'recordImpactDecision('), 'Governed impact decision persistence is missing.');
architect_ui_assert(str_contains((string)$seed, 'SMS / ECCAIRS Occurrence Lifecycle'), 'Real SMS/ECCAIRS seed plan is missing.');
architect_ui_assert(!str_contains($page, 'confidence percentage'), 'The Impact Analysis must not emphasize confidence percentages.');
architect_ui_assert(!str_contains($page, 'chat bubble'), 'The workspace must not use chat presentation.');

echo "PASS: Manual Change Architect Impact Analysis workspace contract\n";
