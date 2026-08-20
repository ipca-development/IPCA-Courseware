<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/publishing/BooksManualsChangeArchitectService.php';
require_once __DIR__ . '/../src/publishing/BooksManualsChangePlanService.php';

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
$complianceCandidateIds = array_map(
    static fn(array $candidate): int => (int)($candidate['section_id'] ?? 0),
    (array)($report['candidate_discovery'] ?? array())
);
architect_fixture_assert(
    in_array(115, $complianceCandidateIds, true),
    'Compliance Monitoring Sections 10/10.2 must remain discoverable candidates.'
);

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
architect_fixture_assert(
    in_array('8.1', $amendmentNumbers, true),
    'Section 8.1 must remain the correct SMS occurrence-lifecycle training amendment.'
);
architect_fixture_assert(
    count(array_filter(
        $amendments,
        static fn(array $impact): bool => (int)($impact['section_id'] ?? 0) === 115
    )) === 0,
    'Parent/child Compliance Monitoring candidates were not consolidated out of amendment scope.'
);
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
    'Step 2 did not present the complete five-card SMS/ECCAIRS review: '
        . json_encode(architect_fixture_numbers($presentation['areas']))
);
architect_fixture_assert(
    $presentation['quality_gate']['reviewable'] === true,
    'The gold-standard five-card review did not pass the deterministic quality gate: '
        . json_encode($presentation['quality_gate']['failures'])
        . ' areas=' . json_encode(array_map(
            static fn(array $area): array => array(
                $area['section_number'],
                $area['primary_function'],
                $area['secondary_functions'],
                $area['why_affected'],
            ),
            $presentation['areas']
        ))
);

$ambiguousAreas = $presentation['areas'];
$adjacentArea = $ambiguousAreas[array_key_last($ambiguousAreas)];
$adjacentArea['impact_id'] = 999;
$adjacentArea['section_id'] = 115;
$adjacentArea['section_number'] = '10.2';
$adjacentArea['section_title'] = 'Compliance Monitoring Training';
$adjacentArea['is_primary_change'] = false;
$adjacentArea['why_affected'] = (string)$ambiguousAreas[0]['why_affected'];
$adjacentArea['must_preserve'] = array('Existing Compliance Monitoring competence requirements');
$ambiguousAreas[] = $adjacentArea;
$ambiguousGate = $service->validateImpactPresentation($ambiguousAreas);
$duplicateBlocker = array_values(array_filter(
    $ambiguousGate['failures'],
    static fn(array $failure): bool =>
        (string)$failure['code'] === 'duplicate_why'
        && (string)$failure['section'] === '10.2'
))[0] ?? null;
architect_fixture_assert(
    is_array($duplicateBlocker)
        && in_array('HUMAN_DISPOSITION', $duplicateBlocker['resolution_paths'], true),
    'A genuine ambiguous adjacent-section blocker was not classified as human-resolvable.'
);
$dispositionPayload = array(
    'blocker_id' => (string)$duplicateBlocker['blocker_id'],
    'disposition' => 'PRESERVE_UNCHANGED',
    'rationale' => 'This section governs Compliance Monitoring competence and has no demonstrated SMS occurrence-lifecycle delta.',
    'section_id' => 115,
    'section_number' => '10.2',
    'section_title' => 'Compliance Monitoring Training',
);
$resolvedProjection = $service->applyGovernedReviewResolutions(
    $ambiguousAreas,
    $ambiguousGate['failures'],
    array(array(
        'event_type' => 'REVIEW_BLOCKER_DISPOSITION_RECORDED',
        'event_payload_json' => $dispositionPayload,
    ))
);
architect_fixture_assert(
    $service->validateImpactPresentation($resolvedProjection['areas'])['reviewable'] === true,
    'The governed human disposition did not feed back into a successful quality-gate rerun.'
);
architect_fixture_assert(
    count($resolvedProjection['human_dispositions']) === 1
        && count($resolvedProjection['preserved_areas']) === 1,
    'The human disposition was not retained in the governed Architect projection.'
);

$integrityArea = $presentation['areas'][0];
$integrityArea['treatment'] = 'RESTRUCTURE';
$integrityArea['proposed_structure_items'] = array();
$integrityGate = $service->validateImpactPresentation(array($integrityArea));
$integrityBlocker = array_values(array_filter(
    $integrityGate['failures'],
    static fn(array $failure): bool => (string)$failure['code'] === 'incomplete_structure'
))[0] ?? null;
architect_fixture_assert(
    is_array($integrityBlocker)
        && $integrityBlocker['integrity_blocker'] === true
        && $integrityBlocker['resolution_paths'] === array('ARCHITECT_RESOLUTION'),
    'Incomplete governed structure was not classified as non-overridable integrity.'
);
$governancePdo = new PDO('sqlite::memory:');
$governancePdo->exec(
    'CREATE TABLE ipca_manual_ai_architect_plans '
    . '(id INTEGER PRIMARY KEY, owner_id INTEGER, status TEXT, stage TEXT, updated_by INTEGER, updated_at TEXT)'
);
$governancePdo->exec(
    "INSERT INTO ipca_manual_ai_architect_plans (id,owner_id,status,stage) VALUES (1,7,'ready_for_review','scope')"
);
$governancePlanService = new BooksManualsChangePlanService($governancePdo);
$integrityRejected = false;
try {
    $governancePlanService->recordReviewBlockerResolution(
        1,
        $integrityBlocker,
        $integrityArea,
        'REVIEW_EXCEPTION',
        '',
        'A qualified reviewer requests an exception.',
        'Residual review uncertainty.',
        '',
        7
    );
} catch (RuntimeException $e) {
    $integrityRejected = str_contains($e->getMessage(), 'cannot be resolved');
}
architect_fixture_assert(
    $integrityRejected,
    'A non-overridable integrity blocker accepted a human review exception.'
);
$governancePdo->exec(
    'CREATE TABLE ipca_manual_ai_architect_decision_events ('
    . 'id INTEGER PRIMARY KEY AUTOINCREMENT,event_uuid TEXT,plan_id INTEGER,aggregate_type TEXT,'
    . 'aggregate_id INTEGER,event_type TEXT,decision TEXT,event_payload_json TEXT,'
    . 'event_fingerprint TEXT,actor_id INTEGER,recorded_at TEXT)'
);
$persistedResolution = $governancePlanService->recordReviewBlockerResolution(
    1,
    $duplicateBlocker,
    $adjacentArea,
    'HUMAN_DISPOSITION',
    'PRESERVE_UNCHANGED',
    'This section governs Compliance Monitoring competence and has no demonstrated SMS occurrence-lifecycle delta.',
    '',
    '',
    7
);
$persistedEvent = $governancePdo->query(
    "SELECT event_type,event_payload_json FROM ipca_manual_ai_architect_decision_events WHERE id="
    . (int)$persistedResolution['event_id']
)->fetch(PDO::FETCH_ASSOC);
$persistedPayload = json_decode((string)($persistedEvent['event_payload_json'] ?? ''), true);
architect_fixture_assert(
    ($persistedEvent['event_type'] ?? '') === 'REVIEW_BLOCKER_DISPOSITION_RECORDED'
        && ($persistedPayload['actor_user_id'] ?? 0) === 7
        && ($persistedPayload['resulting_architect_state']['classification'] ?? '') === 'MUST_PRESERVE',
    'The governed human disposition was not persisted as an immutable audit event.'
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
$expectedFunctions = array(
    '3.3' => 'RESPONSIBILITIES',
    '4.2' => 'RECORDS_CONTROL',
    '5.6' => 'OCCURRENCE_LIFECYCLE',
    '5.7' => 'MONITORING_ASSURANCE',
    '8.1' => 'COMPETENCE_TRAINING',
);
foreach ($presentation['areas'] as $area) {
    $number = (string)$area['section_number'];
    architect_fixture_assert(
        (string)$area['primary_function'] === $expectedFunctions[$number],
        "Section {$number} received the wrong primary functional classification."
    );
}
$safetyManagerArea = array_values(array_filter(
    $presentation['areas'],
    static fn(array $area): bool => (string)$area['section_number'] === '3.3'
))[0] ?? array();
architect_fixture_assert(
    in_array('QUALIFICATION', (array)($safetyManagerArea['secondary_functions'] ?? array()), true)
        && in_array('COMPETENCE_TRAINING', (array)($safetyManagerArea['secondary_functions'] ?? array()), true),
    'Safety Manager must retain demonstrated qualification and competence-training functions.'
);
$safetyManagerPreserved = implode(' ', (array)($safetyManagerArea['must_preserve'] ?? array()));
foreach (array('qualification', 'SMS', 'training') as $preservedConcept) {
    architect_fixture_assert(
        stripos($safetyManagerPreserved, $preservedConcept) !== false,
        "Safety Manager preservation omitted {$preservedConcept}."
    );
}
architect_fixture_assert(
    str_contains((string)$safetyManagerArea['why_affected'], 'accountable decisions')
        && str_contains((string)$safetyManagerArea['proposed_amendment_summary'], 'Amend only'),
    'Safety Manager projection is not limited to the demonstrated lifecycle responsibility and competence delta.'
);
$lexicalOnlyClassification = $service->classifySectionFunctions(array(
    'section_title' => 'Safety Training Reporting Management',
    'current_manual' => array(
        'section_title' => 'Safety Training Reporting Management',
        'hierarchy_path' => array(array('title' => 'General Information')),
        'subsections' => array(array(
            'number' => 'X.1',
            'title' => 'Overview',
            'paragraphs' => array('This section introduces the organization and lists general contact information.'),
        )),
    ),
    'target_components' => $presentationComponents,
    'coverage_decisions' => array(),
    'preservation_boundaries' => array(),
    'impact_dependencies' => array(),
    'structure_nodes' => array(),
));
architect_fixture_assert(
    $lexicalOnlyClassification['primary_function'] === 'OTHER'
        && $lexicalOnlyClassification['secondary_functions'] === array(),
    'Generic title similarity must not establish a governed section function.'
);
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
        echo "FUNCTIONS: {$area['primary_function']}"
            . ((array)$area['secondary_functions'] === array()
                ? ''
                : ' + ' . implode(', ', $area['secondary_functions']))
            . "\n";
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
        array(
            'id' => 204,
            'number' => '2.2',
            'title' => 'Safety Training Reporting Management',
            'parent_number' => '2',
            'parent_title' => 'General Information',
            'text' => 'This section introduces the organization and lists general contact information.',
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
foreach (array('0.4', '2.0', '2.2', '5') as $forbiddenStructuralArea) {
    architect_fixture_assert(
        !in_array($forbiddenStructuralArea, $structuralAmendments, true),
        "Structural surface {$forbiddenStructuralArea} became a false amendment."
    );
}

echo "PASS: Manual Change Architect SMS/ECCAIRS checkpoint fixture\n";
