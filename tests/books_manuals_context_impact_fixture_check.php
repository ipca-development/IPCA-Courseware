<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/publishing/BooksManualsContextImpactService.php';

function context_fixture_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$reflection = new ReflectionClass(BooksManualsContextImpactService::class);
$service = $reflection->newInstanceWithoutConstructor();

$normalize = $reflection->getMethod('normalizeForSearch');
$expand = $reflection->getMethod('expandConcepts');
$match = $reflection->getMethod('termMatches');
$validate = $reflection->getMethod('validateRequirement');
$fallback = $reflection->getMethod('fallbackWholeRequest');
$candidates = $reflection->getMethod('candidateBundles');

$legacyTerms = $expand->invoke($service, array(
    'Pipedrive',
    'E-OR',
    'Online Safety Management System',
    'sms.europilotcenter.be',
));
context_fixture_assert(in_array('pipedrive', $legacyTerms, true), 'Pipedrive must remain an exact legacy scan term.');
context_fixture_assert(
    !in_array('crm', $legacyTerms, true),
    'Pipedrive scanning must not treat the ambiguous CRM acronym as an exact legacy reference.'
);

$smsSection = $normalize->invoke(
    $service,
    'Occurrence Reporting. The Safety Manager records the report in Pipedrive and creates an E-OR for authority transmission.'
);
$aircraftSection = $normalize->invoke(
    $service,
    'Description of Aircraft. The aircraft equipment list and registration details shall remain current.'
);
$instructorSection = $normalize->invoke(
    $service,
    'Instruction Staff. Instructor recruitment and qualification records are reviewed annually.'
);
$auditClosure = $normalize->invoke(
    $service,
    'Compliance audit findings are closed after corrective action evidence is accepted.'
);

context_fixture_assert(
    $match->invoke($service, $smsSection, $legacyTerms)['distinctive'] > 0,
    'Explicit Pipedrive/E-OR content must be a deterministic impact candidate.'
);
context_fixture_assert(
    $match->invoke($service, $aircraftSection, $legacyTerms)['distinctive'] === 0,
    'Aircraft description must not match an SMS platform replacement.'
);
$ecairsRequirementTerms = $expand->invoke($service, $reflection->getMethod('distinctiveTerms')->invoke(
    $service,
    'The Safety Manager shall verify that the ECCAIRS update has been accepted and retain the corresponding evidence.'
));
$aerodromeText = $normalize->invoke(
    $service,
    'Aerodromes and Operating Sites. We refer to Part 3, Chapter 5, Training Area and the applicable training course.'
);
context_fixture_assert(
    $match->invoke($service, $aerodromeText, $ecairsRequirementTerms)['distinctive'] === 0,
    'Common grammar words must not make aerodrome content relevant to an ECCAIRS evidence requirement.'
);
context_fixture_assert(
    $match->invoke($service, $instructorSection, $legacyTerms)['distinctive'] === 0,
    'Instructor hiring must not match an SMS platform replacement.'
);
context_fixture_assert(
    $match->invoke($service, $auditClosure, $legacyTerms)['distinctive'] === 0,
    'Compliance-audit closure must not be confused with occurrence-report closure.'
);

$sourceText = 'Before performing their assigned functions operationally, personnel involved in the occurrence management process shall receive role-specific training.';
$valid = $validate->invoke($service, $sourceText, $sourceText, array('_full_text' => $sourceText));
$truncatedText = 'Before performing their assigned functions operationally, personnel involved in the occurrence management process shall rece';
$invalid = $validate->invoke($service, $truncatedText, $truncatedText, array('_full_text' => $truncatedText));
context_fixture_assert(
    in_array((string)$valid['status'], array('active', 'valid'), true),
    'A complete traceable requirement must remain active.'
);
context_fixture_assert(
    !in_array((string)$invalid['status'], array('active', 'valid'), true),
    'A truncated requirement must be excluded from impact generation.'
);

$fallbackSource = implode("\n\n", array(
    'The OMM Safety Management content must remove references to the outdated system: Pipedrive.',
    'The ATO implemented its new Safety Management System in the IPCA.training platform.',
    'The Safety Manager shall determine reportability and prepare the initial ECCAIRS notification.',
    'The Action Owner shall provide corrective-action evidence.',
    'Intermediate and final ECCAIRS updates shall be performed directly until automated amendments are operational.',
));
$fallbackResult = $fallback->invoke($service, array(array(
    'id' => 1,
    'title' => 'SMS change',
    '_full_text' => $fallbackSource,
)));
$fallbackIntent = $fallbackResult['intent'];
context_fixture_assert(
    (string)$fallbackIntent['change_type'] === 'SYSTEM_REPLACEMENT',
    'Deterministic fallback must preserve a system-replacement intent.'
);
context_fixture_assert(
    in_array('Pipedrive', $fallbackIntent['legacy_concepts'], true),
    'Deterministic fallback must extract the explicit obsolete system.'
);
context_fixture_assert(
    !in_array('the', $fallbackIntent['legacy_concepts'], true)
        && !in_array('safety', $fallbackIntent['legacy_concepts'], true),
    'Deterministic legacy concepts must not contain generic source words.'
);
context_fixture_assert(
    count($fallbackResult['targets']) >= 4,
    'Deterministic fallback must preserve coherent target workflow areas.'
);

$candidateRequirements = array(array(
    'id' => 10,
    'workflow_area_id' => 20,
    'requirement_text' => 'The Safety Manager shall assess reportability and record the authority reporting deadline.',
));
$candidateBundle = static fn(int $sectionId, string $title, string $text): array => array(
    'section_id' => $sectionId,
    'book_title' => 'Organization Management Manual',
    'parent_section_title' => 'Safety Management',
    'section_title' => $title,
    'blocks' => array(array('block_id' => $sectionId * 10, 'text' => $text)),
);
$candidateResult = $candidates->invoke(
    $service,
    $candidateRequirements,
    array(
        1 => $candidateBundle(1, 'Reportability Assessment', 'The Safety Manager records the authority reporting deadline.'),
        2 => $candidateBundle(2, 'Description of Aircraft', 'The aircraft management system records general information.'),
        3 => $candidateBundle(3, 'Legacy Workflow', 'Pipedrive is used for safety reports.'),
    ),
    array(array('section_id' => 3)),
    array('sections' => array(20 => array(1 => true, 2 => true)), 'warning_count' => 0),
    array()
);
context_fixture_assert(
    count($candidateResult) === 2,
    'Candidate selection must retain scoped procedural and explicit legacy sections while excluding generic aircraft content.'
);

echo "PASS: Context-preserving SMS/Pipedrive impact fixtures\n";
