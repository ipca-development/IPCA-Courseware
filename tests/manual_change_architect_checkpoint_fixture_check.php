<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/publishing/BooksManualsChangeArchitectService.php';

/** @param mixed $condition */
function architect_fixture_assert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/** @param list<array<string,mixed>> $rows @return list<string> */
function architect_fixture_numbers(array $rows): array
{
    $numbers = array_values(array_map(
        static fn(array $row): string => (string)$row['section_number'],
        $rows
    ));
    sort($numbers, SORT_NATURAL);
    return $numbers;
}

$fixture = require __DIR__ . '/fixtures/manual_change_architect_sms_ecairs.php';
$service = new BooksManualsChangeArchitectService(new PDO('sqlite::memory:'));
$report = $service->runFixtureCheckpoint($fixture);
$expected = $fixture['expected'];

$mustChange = architect_fixture_numbers($report['must_change']);
$expectedMustChange = $expected['must_change_numbers'];
sort($expectedMustChange, SORT_NATURAL);
architect_fixture_assert($mustChange === $expectedMustChange, 'Must Change sections differ from the gold standard.');

$mustPreserve = architect_fixture_numbers($report['must_preserve']);
foreach ($expected['must_preserve_numbers'] as $number) {
    architect_fixture_assert(in_array($number, $mustPreserve, true), "Expected preserved section {$number} is missing.");
}

$outOfScope = architect_fixture_numbers($report['out_of_scope']);
foreach ($expected['out_of_scope_numbers'] as $number) {
    architect_fixture_assert(in_array($number, $outOfScope, true), "Expected out-of-scope section {$number} is missing.");
}

$reviewSeparately = architect_fixture_numbers($report['review_separately']);
architect_fixture_assert(
    $reviewSeparately === $expected['review_separately_numbers'],
    'Review Separately sections differ from the gold standard.'
);

$amendments = $report['what_should_actually_be_amended'];
$amendmentNumbers = architect_fixture_numbers($amendments);
foreach ($expected['forbidden_amendment_numbers'] as $number) {
    architect_fixture_assert(
        !in_array($number, $amendmentNumbers, true),
        "False-positive amendment generated for {$number}."
    );
}
$primary = array_values(array_filter(
    $amendments,
    static fn(array $impact): bool =>
        (string)$impact['section_number'] === (string)$expected['primary_restructure_number']
));
architect_fixture_assert(
    count($primary) === 1 && (string)$primary[0]['treatment'] === 'RESTRUCTURE',
    'The occurrence-reporting lifecycle is not the single primary restructure.'
);

architect_fixture_assert(count($report['legacy_references']) === 7, 'Every exact legacy reference must be reported.');
$legacyIdentities = array_column($report['legacy_references'], 'legacy_identity');
architect_fixture_assert(
    in_array('E-Occurrence Reporting System / E-OR', $legacyIdentities, true),
    'The governed SMS catalog did not capture the E-OR reference.'
);
foreach ($report['legacy_references'] as $hit) {
    architect_fixture_assert(
        in_array(
            (string)$hit['proposed_disposition'],
            array('REMOVE_OR_REPLACE', 'PRESERVE_WITH_JUSTIFICATION', 'REVIEW_SEPARATELY'),
            true
        ),
        'An exact legacy reference lacks a governed disposition.'
    );
}

$componentTypes = array_values(array_unique(array_column(
    $report['operational_target_state']['components'],
    'component_type'
)));
foreach (array(
    'lifecycle', 'role', 'human_decision', 'automatic_action', 'record_evidence',
    'deadline', 'control', 'approval', 'monitoring', 'closure', 'limitation',
) as $type) {
    architect_fixture_assert(in_array($type, $componentTypes, true), "Target-state component {$type} is missing.");
}

architect_fixture_assert($report['publishing_content_mutated'] === false, 'Fixture checkpoint must remain read-only.');
architect_fixture_assert($report['contains_drafting_or_proposals'] === false, 'Checkpoint must not draft wording.');
architect_fixture_assert(
    $report['reasoning']['minimality']['satisfied'] === true,
    'Minimality reasoning did not pass.'
);
architect_fixture_assert(
    $report['reasoning']['completeness']['satisfied'] === true,
    'Completeness reasoning did not pass.'
);

$presentationImpacts = array();
foreach ($amendments as $index => $impact) {
    $impact['id'] = $index + 1;
    $impact['canonical_evidence_json'] = $impact['canonical_evidence'];
    $impact['target_component_keys_json'] = $impact['target_component_keys'];
    $impact['target_concepts_json'] = $impact['target_state_concepts'];
    $impact['preserved_logic_json'] = $impact['preserved_logic'];
    $impact['dependencies_json'] = $impact['dependencies'];
    $presentationImpacts[] = $impact;
}
$presentationComponents = array_map(
    static function (array $component): array {
        $component['title'] = (string)($component['name'] ?? '');
        $component['target_state'] = (string)($component['desired_state'] ?? '');
        return $component;
    },
    $report['operational_target_state']['components']
);
$presentation = $service->buildImpactPresentation(array(
    'impacts' => $presentationImpacts,
    'target_components' => $presentationComponents,
    'change_intents' => array($report['change_intent']),
    'boundaries' => $report['boundaries'],
    'legacy_hits' => $report['legacy_references'],
    'coverage' => $report['coverage_matrix'],
    'impact_dependencies' => array(),
    'structure_nodes' => array(),
));
architect_fixture_assert(
    $presentation['schema'] === 'ipca.manual-change-impact-presentation.v2',
    'Step 2 did not emit the structured v2 review contract.'
);
architect_fixture_assert(
    architect_fixture_numbers($presentation['areas']) === $expectedMustChange,
    'Step 2 did not present the complete five-card SMS/ECCAIRS review.'
);
architect_fixture_assert(
    $presentation['quality_gate']['reviewable'] === true,
    'The gold-standard five-card review did not pass the deterministic quality gate: '
        . json_encode($presentation['quality_gate']['failures'])
);
foreach ($presentation['areas'] as $area) {
    foreach (array(
        'why_affected', 'current_relevant_content', 'obsolete_or_inaccurate_content',
        'proposed_amendment_summary', 'proposed_structure_items', 'must_preserve',
        'related_amendments', 'legacy_references', 'evidence_refs',
    ) as $field) {
        architect_fixture_assert(
            array_key_exists($field, $area),
            "Step 2 area {$area['section_number']} is missing {$field}."
        );
    }
}
$presentationPrimary = array_values(array_filter(
    $presentation['areas'],
    static fn(array $area): bool => !empty($area['is_primary_change'])
))[0] ?? array();
architect_fixture_assert(
    count((array)($presentationPrimary['proposed_structure_items'] ?? array())) === 9,
    'The primary Step 2 restructure does not show the complete nine-node future hierarchy: '
        . json_encode(array_column((array)($presentationPrimary['proposed_structure_items'] ?? array()), 'title'))
);
$rejectedQuality = $service->validateImpactPresentation(array(
    array(
        'section_number' => 'X.1',
        'treatment' => 'RESTRUCTURE',
        'is_primary_change' => true,
        'why_affected' => 'The section demonstrably covers a target-state component that changes.',
        'proposed_amendment_summary' => 'Align the section.',
        'must_preserve' => array(),
        'proposed_structure_items' => array(),
        'related_amendments' => array(),
    ),
));
$rejectedCodes = array_column($rejectedQuality['failures'], 'code');
foreach (array('generic_why', 'vague_proposal', 'missing_preservation', 'incomplete_structure') as $code) {
    architect_fixture_assert(
        in_array($code, $rejectedCodes, true),
        "The Step 2 quality gate did not reject {$code}."
    );
}
if (getenv('IPCA_SHOW_STEP2_REVIEW') === '1') {
    echo "\nSTEP 2 REVIEW OUTPUT\n";
    foreach ($presentation['areas'] as $area) {
        echo "\n{$area['section_number']} {$area['section_title']} — {$area['treatment']}\n";
        echo "WHY: {$area['why_affected']}\n";
        echo "PROPOSED: {$area['proposed_amendment_summary']}\n";
        if ((array)$area['proposed_structure_items'] !== array()) {
            echo "STRUCTURE/DECISIONS:\n";
            foreach ($area['proposed_structure_items'] as $item) {
                echo "  {$item['number']} {$item['title']} [{$item['treatment']}] — {$item['summary']}\n";
            }
        }
        echo "PRESERVE: " . implode('; ', $area['must_preserve']) . "\n";
        foreach ($area['related_amendments'] as $related) {
            echo "RELATED: {$related['section_number']} {$related['section_title']} — {$related['relationship']}\n";
        }
    }
    echo "\nQUALITY GATE: REVIEWABLE\n";
}

$structuralFixture = $fixture;
$structuralFixture['manual']['sections'] = array_merge(
    array(
        array(
            'id' => 201,
            'number' => '0.4',
            'title' => 'Highlight of Changes',
            'section_key' => 'highlights',
            'section_type' => 'highlights',
            'is_system_managed' => true,
            'is_generated' => true,
            'text' => 'Revision summary: remove Pipedrive and introduce the updated occurrence workflow.',
        ),
        array(
            'id' => 202,
            'number' => '2.0',
            'title' => 'Scope of the Safety Management Manual',
            'parent_number' => '2',
            'parent_title' => 'Safety Management System',
            'text' => 'This manual describes safety management, occurrence reporting, investigation and monitoring.',
        ),
        array(
            'id' => 203,
            'number' => '5',
            'title' => 'Safety Risk Management',
            'parent_number' => '5',
            'parent_title' => 'Safety Management System',
            'text' => 'Safety risk management includes occurrence review, investigation, corrective action and monitoring.',
        ),
    ),
    $fixture['manual']['sections']
);
$structuralReport = $service->runFixtureCheckpoint($structuralFixture);
$structuralMustChange = architect_fixture_numbers($structuralReport['must_change']);
architect_fixture_assert(
    $structuralMustChange === $expectedMustChange,
    'System-managed, scope and chapter-container surfaces must not displace procedural amendment homes.'
);
$structuralAmendments = architect_fixture_numbers($structuralReport['what_should_actually_be_amended']);
foreach (array('0.4', '2.0', '5') as $forbiddenStructuralArea) {
    architect_fixture_assert(
        !in_array($forbiddenStructuralArea, $structuralAmendments, true),
        "Structural surface {$forbiddenStructuralArea} became a false amendment."
    );
}

echo "PASS: Manual Change Architect SMS/ECCAIRS checkpoint fixture\n";
