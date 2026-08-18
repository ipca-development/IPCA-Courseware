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

$legacyTerms = $expand->invoke($service, array(
    'Pipedrive',
    'E-OR',
    'Online Safety Management System',
    'sms.europilotcenter.be',
));
context_fixture_assert(in_array('pipedrive', $legacyTerms, true), 'Pipedrive must remain an exact legacy scan term.');
context_fixture_assert(in_array('crm', $legacyTerms, true), 'Pipedrive must retain its normalized CRM variant.');

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

echo "PASS: Context-preserving SMS/Pipedrive impact fixtures\n";
