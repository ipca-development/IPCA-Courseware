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
