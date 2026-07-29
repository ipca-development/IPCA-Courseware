<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/CvrDispatchIntakeService.php';

$service = (new ReflectionClass(CvrDispatchIntakeService::class))->newInstanceWithoutConstructor();
$normalize = new ReflectionMethod(CvrDispatchIntakeService::class, 'normalizeAndValidate');
$device = array(
    'id' => 10,
    'organization_id' => 1,
    'aircraft_id' => 7,
    'aircraft_registration' => 'N392EA',
);
$dispatchUuid = '11111111-1111-4111-8111-111111111111';
$flightRecordUuid = '22222222-2222-4222-8222-222222222222';
$consentUuid = '33333333-3333-4333-8333-333333333333';
$payload = array(
    'flight_record_uuid' => $flightRecordUuid,
    'dispatch' => array(
        'id' => $dispatchUuid,
        'scheduled_date' => '2026-07-28',
        'tail_number' => 'N392EA',
        'aircraft_id' => 7,
        'mission_code' => 'SPC-1',
        'crew' => array(array(
            'id' => '44444444-4444-4444-8444-444444444444',
            'person_id' => 21,
            'person_name' => 'Student Pilot',
            'role' => 'student',
        )),
        'starting_hobbs' => 166.6,
        'starting_tacho' => 120.2,
        'fuel_onboard' => '13.0',
        'oil_percentage' => 50,
        'version' => 4,
        'created_at' => '2026-07-28T20:00:00Z',
        'modified_at' => '2026-07-28T20:01:00Z',
        'consent_status' => 'complete',
        'status' => 'flightRecordLoggingEnabled',
    ),
    'consents' => array(array(
        'id' => $consentUuid,
        'person_id' => 21,
        'person_name' => 'Student Pilot',
        'crew_role' => 'student',
        'consent_result' => true,
        'timestamp' => '2026-07-28T20:00:30Z',
        'device_id' => 'device',
        'dispatch_id' => $dispatchUuid,
        'dispatch_version' => 4,
        'consent_text_version' => 'v1',
        'app_version' => '1.0',
    )),
);

$failures = array();
check('complete current-version Dispatch is accepted', static function () use ($normalize, $service, $payload, $device): bool {
    $normalized = $normalize->invoke($service, $payload, $device);
    return ($normalized['dispatch_uuid'] ?? '') === '11111111-1111-4111-8111-111111111111'
        && ($normalized['flight_record_uuid'] ?? '') === '22222222-2222-4222-8222-222222222222'
        && ($normalized['dispatch_version'] ?? 0) === 4
        && count($normalized['consents'] ?? array()) === 1;
});
check('tail mismatch is blocking', static function () use ($normalize, $service, $payload, $device): bool {
    $changed = $payload;
    $changed['dispatch']['tail_number'] = 'N000XX';
    return throws_runtime(static fn() => $normalize->invoke($service, $changed, $device), 'tail number');
});
check('tail punctuation is normalized before compare', static function () use ($normalize, $service, $payload, $device): bool {
    $changed = $payload;
    $changed['dispatch']['tail_number'] = 'N392-EA';
    $normalized = $normalize->invoke($service, $changed, $device);
    return ($normalized['aircraft_registration'] ?? '') === 'N392EA';
});
check('stale consent version is blocking', static function () use ($normalize, $service, $payload, $device): bool {
    $changed = $payload;
    $changed['consents'][0]['dispatch_version'] = 3;
    return throws_runtime(static fn() => $normalize->invoke($service, $changed, $device), 'consent');
});
check('missing crew consent is blocking', static function () use ($normalize, $service, $payload, $device): bool {
    $changed = $payload;
    $changed['consents'] = array();
    return throws_runtime(static fn() => $normalize->invoke($service, $changed, $device), 'consent');
});
check('invalid Flight Record UUID is blocking', static function () use ($normalize, $service, $payload, $device): bool {
    $changed = $payload;
    $changed['flight_record_uuid'] = 'not-a-uuid';
    return throws_runtime(static fn() => $normalize->invoke($service, $changed, $device), 'UUID');
});

if ($failures !== array()) {
    fwrite(STDERR, 'Failed CVR Dispatch intake checks: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}
echo 'OK: CVR Dispatch intake contract checks passed.' . PHP_EOL;

function check(string $name, callable $scenario): void
{
    global $failures;
    $passed = false;
    try {
        $passed = $scenario();
    } catch (Throwable) {
        $passed = false;
    }
    echo ($passed ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$passed) {
        $failures[] = $name;
    }
}

function throws_runtime(callable $action, string $messageContains): bool
{
    try {
        $action();
    } catch (RuntimeException $e) {
        return stripos($e->getMessage(), $messageContains) !== false;
    }
    return false;
}
