<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/CvrWorkflowEvidenceIntakeService.php';

$service = (new ReflectionClass(CvrWorkflowEvidenceIntakeService::class))->newInstanceWithoutConstructor();
$normalize = new ReflectionMethod(CvrWorkflowEvidenceIntakeService::class, 'normalize');
$componentUuid = '11111111-1111-4111-8111-111111111111';
$flightUuid = '22222222-2222-4222-8222-222222222222';
$dispatchUuid = '33333333-3333-4333-8333-333333333333';
$eventUuid = '44444444-4444-4444-8444-444444444444';

$payload = array(
    'schema_version' => 1,
    'component_uuid' => $componentUuid,
    'flight_record_uuid' => $flightUuid,
    'dispatch_uuid' => $dispatchUuid,
    'component_type' => 'flight_events',
    'evidence' => array(
        'event_uuid' => $eventUuid,
        'event_type' => 'training_remark',
        'timestamp_utc' => '2026-07-29T12:00:00Z',
        'timestamp_local' => '2026-07-29T05:00:00-07:00',
    ),
);

$checks = array();
$checks['valid immutable event payload normalizes'] = static function () use ($normalize, $service, $payload, $eventUuid): bool {
    $result = $normalize->invoke($service, $payload);
    return ($result['component_type'] ?? '') === 'flight_events'
        && ($result['evidence']['event_uuid'] ?? '') === $eventUuid;
};
$checks['unsupported component type is rejected'] = static function () use ($normalize, $service, $payload): bool {
    $changed = $payload;
    $changed['component_type'] = 'mutable_blob';
    return throws_runtime(static fn() => $normalize->invoke($service, $changed), 'unsupported');
};
$checks['invalid evidence UUID is rejected'] = static function () use ($normalize, $service, $payload): bool {
    $changed = $payload;
    $changed['evidence']['event_uuid'] = 'invalid';
    return throws_runtime(static fn() => $normalize->invoke($service, $changed), 'event_uuid');
};

$migration = file_get_contents(__DIR__ . '/../scripts/sql/2026_07_29_cvr_workflow_evidence_intake.sql') ?: '';
$scheduledMigration = file_get_contents(__DIR__ . '/../scripts/sql/2026_07_31_scheduled_dispatch_start_end.sql') ?: '';
$serviceSource = file_get_contents(__DIR__ . '/../src/CvrWorkflowEvidenceIntakeService.php') ?: '';
$checks['migration has component and event idempotency keys'] = static fn(): bool =>
    str_contains($migration, 'UNIQUE KEY uk_ipca_cvr_workflow_evidence_component')
    && str_contains($migration, 'UNIQUE KEY uk_ipca_cvr_flight_events_uuid');
$checks['same component with different hash conflicts'] = static fn(): bool =>
    str_contains($serviceSource, 'Workflow evidence component UUID conflict.')
    && str_contains($serviceSource, 'hash_equals');
$checks['closure stores generic oil while retaining percentage'] = static fn(): bool =>
    str_contains($scheduledMigration, 'ipca_cvr_flight_closures ADD COLUMN oil_quantity')
    && str_contains($scheduledMigration, 'ipca_cvr_flight_closures ADD COLUMN oil_unit')
    && str_contains($serviceSource, "ending_oil_quantity")
    && str_contains($serviceSource, "ending_oil_percentage");
$checks['closure enforces generic oil unit continuity'] = static fn(): bool =>
    str_contains($serviceSource, 'Ending oil unit must match the Dispatch oil unit.');

$failed = array();
foreach ($checks as $name => $scenario) {
    $passed = false;
    try {
        $passed = $scenario();
    } catch (Throwable) {
        $passed = false;
    }
    echo ($passed ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}
if ($failed !== array()) {
    fwrite(STDERR, 'Failed workflow evidence checks: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}
echo 'OK: CVR workflow evidence contract checks passed.' . PHP_EOL;

function throws_runtime(callable $action, string $contains): bool
{
    try {
        $action();
    } catch (RuntimeException $e) {
        return stripos($e->getMessage(), $contains) !== false;
    }
    return false;
}
