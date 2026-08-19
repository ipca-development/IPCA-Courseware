<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/publishing/BooksManualsChangeStructureService.php';

function structure_fixture_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$fixture = require __DIR__ . '/fixtures/manual_change_architect_sms_structure.php';
$service = new BooksManualsChangeStructureService(new PDO('sqlite::memory:'));
$proposal = $service->buildProposal(
    $fixture['title'],
    $fixture['rationale'],
    $fixture['source_fingerprint'],
    $fixture['areas']
);

structure_fixture_assert(count($proposal['areas']) === 5, 'Proposal must contain five accepted amendment areas.');
$primary = array_values(array_filter(
    $proposal['areas'],
    static fn(array $area): bool => $area['treatment'] === 'RESTRUCTURE'
));
structure_fixture_assert(count($primary) === 1, 'Proposal must contain exactly one primary restructure.');
structure_fixture_assert($primary[0]['section_number'] === '5.6', 'Section 5.6 must be the primary restructure.');

$futureRoot = $primary[0]['future'][0];
$futureNumbers = array_column($futureRoot['children'], 'number');
structure_fixture_assert(
    $futureNumbers === array('5.6.1', '5.6.2', '5.6.3', '5.6.4', '5.6.5', '5.6.6', '5.6.7', '5.6.8', '5.6.9'),
    'The complete future Section 5.6 lifecycle hierarchy is missing or out of order.'
);
foreach (array(
    'Initial Occurrence Reporting',
    'Intake and Reportability Decision',
    'ECCAIRS Reporting and Authority Follow-Up',
    'Internal Safety Investigation',
    'Corrective and Mitigating Actions',
    'Effectiveness Review',
    'Monitoring, Escalation and Reconciliation',
    'Controlled Closure',
) as $title) {
    structure_fixture_assert(
        in_array($title, array_column($futureRoot['children'], 'title'), true),
        "Future Section 5.6 is missing {$title}."
    );
}

structure_fixture_assert($proposal['guardrails']['draft_wording_present'] === false, 'Structure checkpoint contains draft wording.');
structure_fixture_assert($proposal['guardrails']['publishing_content_mutated'] === false, 'Structure checkpoint mutates publishing content.');
structure_fixture_assert(
    count($proposal['operation_primitives']) > 20,
    'Canonical structure operations were not generated for the complete hierarchy.'
);
foreach ($proposal['operation_primitives'] as $operation) {
    structure_fixture_assert(
        $operation['authorized_by_accepted_impact'] === true,
        'A structure operation is not authorized by an accepted impact.'
    );
    structure_fixture_assert(
        preg_match('/^[a-f0-9]{64}$/', $operation['expected_source_fingerprint']) === 1,
        'A structure operation lacks its expected source fingerprint.'
    );
}

echo "PASS: Manual Change Architect CURRENT vs FUTURE structure fixture\n";
