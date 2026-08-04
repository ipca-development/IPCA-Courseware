<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/CvrWorkflowSyncReconciliationService.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE ipca_cvr_dispatches (
    id INTEGER PRIMARY KEY,
    dispatch_uuid TEXT UNIQUE,
    workflow_flight_record_uuid TEXT,
    device_id INTEGER,
    aircraft_registration TEXT,
    starting_hobbs REAL,
    starting_tacho REAL,
    oil_quantity REAL,
    oil_unit TEXT
)');
$pdo->exec('CREATE TABLE ipca_cvr_dispatch_versions (
    id INTEGER PRIMARY KEY,
    dispatch_id INTEGER,
    dispatch_version INTEGER,
    device_id INTEGER,
    receipt_uuid TEXT,
    payload_sha256 TEXT,
    payload_json TEXT,
    received_at TEXT
)');
$pdo->exec('CREATE TABLE ipca_cvr_workflow_evidence_batches (
    id INTEGER PRIMARY KEY,
    batch_uuid TEXT,
    component_uuid TEXT UNIQUE,
    workflow_flight_record_uuid TEXT,
    dispatch_uuid TEXT,
    device_id INTEGER,
    component_type TEXT,
    payload_sha256 TEXT,
    payload_json TEXT,
    receipt_uuid TEXT,
    received_at TEXT
)');
$pdo->exec('CREATE TABLE ipca_cvr_flight_events (
    id INTEGER PRIMARY KEY,
    event_uuid TEXT,
    batch_id INTEGER
)');
$pdo->exec('CREATE TABLE ipca_cvr_recorder_verifications (
    id INTEGER PRIMARY KEY,
    verification_uuid TEXT,
    batch_id INTEGER
)');
$pdo->exec('CREATE TABLE ipca_cvr_flight_closures (
    id INTEGER PRIMARY KEY,
    closure_uuid TEXT,
    batch_id INTEGER,
    workflow_flight_record_uuid TEXT,
    ending_hobbs REAL,
    ending_tacho REAL,
    fuel_remaining TEXT,
    oil_percentage INTEGER,
    oil_quantity REAL,
    oil_unit TEXT,
    received_at TEXT
)');

$device = array(
    'id' => 10,
    'organization_id' => 1,
    'aircraft_id' => 7,
    'aircraft_registration' => 'N392EA',
);
$dispatchUuid = '11111111-1111-4111-8111-111111111111';
$flightUuid = '22222222-2222-4222-8222-222222222222';
$componentUuid = '33333333-3333-4333-8333-333333333333';
$eventUuid = '44444444-4444-4444-8444-444444444444';
$missingUuid = '55555555-5555-4555-8555-555555555555';
$dependencyUuid = '66666666-6666-4666-8666-666666666666';
$brokenUuid = '77777777-7777-4777-8777-777777777777';
$brokenEventUuid = '88888888-8888-4888-8888-888888888888';
$verificationComponentUuid = '14141414-1414-4141-8141-141414141414';
$verificationUuid = '15151515-1515-4151-8151-151515151515';
$closureComponentUuid = '16161616-1616-4161-8161-161616161616';

$pdo->prepare(
    'INSERT INTO ipca_cvr_dispatches
     (id, dispatch_uuid, workflow_flight_record_uuid, device_id, aircraft_registration)
     VALUES (?, ?, ?, ?, ?)'
)->execute(array(101, $dispatchUuid, $flightUuid, 10, 'N392EA'));

$eventPayload = array(
    'schema_version' => 1,
    'component_uuid' => $componentUuid,
    'flight_record_uuid' => $flightUuid,
    'dispatch_uuid' => $dispatchUuid,
    'component_type' => 'flight_events',
    'evidence' => array(
        'event_uuid' => $eventUuid,
        'event_type' => 'training_remark',
        'timestamp_utc' => '2026-08-03T12:00:00Z',
        'timestamp_local' => '2026-08-03T05:00:00-07:00',
    ),
);
$evidenceIntake = new CvrWorkflowEvidenceIntakeService($pdo);
$eventCanonical = $evidenceIntake->canonicalPayload($eventPayload);
$pdo->prepare(
    'INSERT INTO ipca_cvr_workflow_evidence_batches
     (id, batch_uuid, component_uuid, workflow_flight_record_uuid, dispatch_uuid, device_id,
      component_type, payload_sha256, payload_json, receipt_uuid, received_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
)->execute(array(
    201,
    '99999999-9999-4999-8999-999999999999',
    $componentUuid,
    $flightUuid,
    $dispatchUuid,
    10,
    'flight_events',
    $eventCanonical['payload_sha256'],
    $eventCanonical['payload_json'],
    'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    '2026-08-03 17:00:00.123',
));
$pdo->prepare('INSERT INTO ipca_cvr_flight_events (id, event_uuid, batch_id) VALUES (?, ?, ?)')
    ->execute(array(301, $eventUuid, 201));

$verificationPayload = array(
    'schema_version' => 1,
    'component_uuid' => $verificationComponentUuid,
    'flight_record_uuid' => $flightUuid,
    'dispatch_uuid' => $dispatchUuid,
    'component_type' => 'recorder_verification',
    'evidence' => array(
        'verification_uuid' => $verificationUuid,
        'timestamp' => '2026-08-03T11:55:00Z',
        'device_id' => 'device',
        'app_version' => '1.0',
    ),
);
$verificationCanonical = $evidenceIntake->canonicalPayload($verificationPayload);
$pdo->prepare(
    'INSERT INTO ipca_cvr_workflow_evidence_batches
     (id, batch_uuid, component_uuid, workflow_flight_record_uuid, dispatch_uuid, device_id,
      component_type, payload_sha256, payload_json, receipt_uuid, received_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
)->execute(array(
    203, '17171717-1717-4171-8171-171717171717', $verificationComponentUuid,
    $flightUuid, $dispatchUuid, 10, 'recorder_verification',
    $verificationCanonical['payload_sha256'], $verificationCanonical['payload_json'],
    '18181818-1818-4181-8181-181818181818', '2026-08-03 16:56:00.111',
));
$pdo->prepare(
    'INSERT INTO ipca_cvr_recorder_verifications (id, verification_uuid, batch_id) VALUES (?, ?, ?)'
)->execute(array(303, $verificationUuid, 203));

$closurePayload = array(
    'schema_version' => 1,
    'component_uuid' => $closureComponentUuid,
    'flight_record_uuid' => $flightUuid,
    'dispatch_uuid' => $dispatchUuid,
    'component_type' => 'flight_record_closure',
    'evidence' => array(
        'closure_uuid' => $closureComponentUuid,
        'status' => 'checked_in',
        'updated_at' => '2026-08-03T18:00:00Z',
        'ending_hobbs' => 167.2,
        'ending_tacho' => 120.8,
    ),
);
$closureCanonical = $evidenceIntake->canonicalPayload($closurePayload);
$pdo->prepare(
    'INSERT INTO ipca_cvr_workflow_evidence_batches
     (id, batch_uuid, component_uuid, workflow_flight_record_uuid, dispatch_uuid, device_id,
      component_type, payload_sha256, payload_json, receipt_uuid, received_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
)->execute(array(
    204, '19191919-1919-4191-8191-191919191919', $closureComponentUuid,
    $flightUuid, $dispatchUuid, 10, 'flight_record_closure',
    $closureCanonical['payload_sha256'], $closureCanonical['payload_json'],
    '20202020-2020-4202-8202-202020202020', '2026-08-03 18:01:00.222',
));
$pdo->prepare(
    'INSERT INTO ipca_cvr_flight_closures
     (id, closure_uuid, batch_id, workflow_flight_record_uuid, ending_hobbs, ending_tacho, received_at)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
)->execute(array(304, $closureComponentUuid, 204, $flightUuid, 167.2, 120.8, '2026-08-03 18:01:00.222'));

$brokenPayload = $eventPayload;
$brokenPayload['component_uuid'] = $brokenUuid;
$brokenPayload['evidence']['event_uuid'] = $brokenEventUuid;
$brokenCanonical = $evidenceIntake->canonicalPayload($brokenPayload);
$pdo->prepare(
    'INSERT INTO ipca_cvr_workflow_evidence_batches
     (id, batch_uuid, component_uuid, workflow_flight_record_uuid, dispatch_uuid, device_id,
      component_type, payload_sha256, payload_json, receipt_uuid, received_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
)->execute(array(
    202,
    'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
    $brokenUuid,
    $flightUuid,
    $dispatchUuid,
    10,
    'flight_events',
    $brokenCanonical['payload_sha256'],
    $brokenCanonical['payload_json'],
    'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
    '2026-08-03 17:01:00.456',
));

$dispatchPayload = array(
    'flight_record_uuid' => $flightUuid,
    'dispatch' => array(
        'id' => $dispatchUuid,
        'scheduled_date' => '2026-08-03',
        'tail_number' => 'N392EA',
        'aircraft_id' => 7,
        'mission_code' => 'SPC-1',
        'crew' => array(array(
            'id' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
            'person_id' => 21,
            'person_name' => 'Student Pilot',
            'role' => 'student',
        )),
        'starting_hobbs' => 166.6,
        'starting_tacho' => 120.2,
        'fuel_onboard' => '13.0',
        'oil_percentage' => 50,
        'version' => 4,
        'scheduler_record_id' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
        'created_at' => '2026-08-03T16:00:00Z',
        'modified_at' => '2026-08-03T16:01:00Z',
        'consent_status' => 'complete',
        'status' => 'flightRecordLoggingEnabled',
    ),
    'consents' => array(array(
        'id' => 'ffffffff-ffff-4fff-8fff-ffffffffffff',
        'person_id' => 21,
        'person_name' => 'Student Pilot',
        'crew_role' => 'student',
        'consent_result' => true,
        'timestamp' => '2026-08-03T16:00:30Z',
        'device_id' => 'device',
        'dispatch_id' => $dispatchUuid,
        'dispatch_version' => 4,
        'consent_text_version' => 'v1',
        'app_version' => '1.0',
    )),
);
$dispatchIntake = new CvrDispatchIntakeService($pdo);
$dispatchCanonical = $dispatchIntake->canonicalPayload($dispatchPayload, $device);
$pdo->prepare(
    'INSERT INTO ipca_cvr_dispatch_versions
     (id, dispatch_id, dispatch_version, device_id, receipt_uuid, payload_sha256, payload_json, received_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
)->execute(array(
    401,
    101,
    4,
    10,
    '12121212-1212-4121-8121-121212121212',
    $dispatchCanonical['payload_sha256'],
    $dispatchCanonical['payload_json'],
    '2026-08-03 16:05:00.789',
));

$evidenceItem = array(
    'item_id' => $componentUuid,
    'component_type' => 'flight_events',
    'component_uuid' => $componentUuid,
    'dispatch_uuid' => $dispatchUuid,
    'flight_record_uuid' => $flightUuid,
    'payload' => $eventPayload,
);
$conflictItem = $evidenceItem;
$conflictItem['item_id'] = 'conflicting-event';
$conflictItem['payload']['evidence']['event_type'] = 'takeoff';
$notFoundItem = $evidenceItem;
$notFoundItem['item_id'] = $missingUuid;
$notFoundItem['component_uuid'] = $missingUuid;
$notFoundItem['payload']['component_uuid'] = $missingUuid;
$dependencyItem = $notFoundItem;
$dependencyItem['item_id'] = $dependencyUuid;
$dependencyItem['component_uuid'] = $dependencyUuid;
$dependencyItem['dispatch_uuid'] = '13131313-1313-4131-8131-131313131313';
$dependencyItem['payload']['component_uuid'] = $dependencyUuid;
$dependencyItem['payload']['dispatch_uuid'] = $dependencyItem['dispatch_uuid'];
$brokenItem = $evidenceItem;
$brokenItem['item_id'] = $brokenUuid;
$brokenItem['component_uuid'] = $brokenUuid;
$brokenItem['payload'] = $brokenPayload;
$dispatchRetryPayload = $dispatchPayload;
$dispatchRetryPayload['dispatch']['modified_at'] = '2026-08-03T18:45:00Z';
$dispatchItem = array(
    'item_id' => 'dispatch-' . $dispatchUuid . '-v4',
    'component_type' => 'dispatch_metadata',
    'dispatch_uuid' => $dispatchUuid,
    'dispatch_version' => 4,
    'flight_record_uuid' => $flightUuid,
    'payload' => $dispatchRetryPayload,
);
$verificationItem = array(
    'item_id' => $verificationComponentUuid,
    'component_type' => 'recorder_verification',
    'component_uuid' => $verificationComponentUuid,
    'dispatch_uuid' => $dispatchUuid,
    'flight_record_uuid' => $flightUuid,
    'payload' => $verificationPayload,
);
$closureItem = array(
    'item_id' => $closureComponentUuid,
    'component_type' => 'flight_record_closure',
    'component_uuid' => $closureComponentUuid,
    'dispatch_uuid' => $dispatchUuid,
    'flight_record_uuid' => $flightUuid,
    'payload' => $closurePayload,
);

$beforeCounts = table_counts($pdo);
$results = (new CvrWorkflowSyncReconciliationService($pdo))->reconcile(array(
    $evidenceItem,
    $conflictItem,
    $notFoundItem,
    $dependencyItem,
    $brokenItem,
    $dispatchItem,
    $evidenceItem,
    $verificationItem,
    $closureItem,
), $device);
$afterCounts = table_counts($pdo);

$checks = array(
    'matching evidence returns original metadata and canonical identifiers' =>
        ($results[0]['status'] ?? '') === 'VERIFIED_MATCH'
        && ($results[0]['receipt_id'] ?? '') === 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'
        && ($results[0]['received_at'] ?? '') === '2026-08-03 17:00:00.123'
        && ($results[0]['payload_sha256'] ?? '') === $eventCanonical['payload_sha256']
        && ($results[0]['canonical_identifiers']['server_evidence_batch_id'] ?? '') === '201'
        && ($results[0]['canonical_identifiers']['server_event_id'] ?? '') === '301'
        && ($results[0]['canonical_identifiers']['event_uuid'] ?? '') === $eventUuid,
    'payload mismatch is an immutable conflict' =>
        ($results[1]['status'] ?? '') === 'IMMUTABLE_CONFLICT'
        && ($results[1]['retryable'] ?? true) === false
        && ($results[1]['user_action_required'] ?? true) === false,
    'absent evidence with available Dispatch is not found' =>
        ($results[2]['status'] ?? '') === 'NOT_FOUND'
        && ($results[2]['retryable'] ?? false) === true,
    'absent evidence without Dispatch is dependency not ready' =>
        ($results[3]['status'] ?? '') === 'DEPENDENCY_NOT_READY'
        && ($results[3]['retryable'] ?? false) === true,
    'missing typed row is isolated as temporary failure' =>
        ($results[4]['status'] ?? '') === 'TEMPORARY_TECHNICAL_FAILURE'
        && ($results[5]['status'] ?? '') === 'VERIFIED_MATCH'
        && ($results[6]['status'] ?? '') === 'VERIFIED_MATCH',
    'Dispatch uses exact retry equivalence and original metadata' =>
        ($results[5]['receipt_id'] ?? '') === '12121212-1212-4121-8121-121212121212'
        && ($results[5]['received_at'] ?? '') === '2026-08-03 16:05:00.789'
        && ($results[5]['payload_sha256'] ?? '') === $dispatchCanonical['payload_sha256']
        && ($results[5]['canonical_identifiers']['server_dispatch_id'] ?? '') === '101'
        && ($results[5]['canonical_identifiers']['dispatch_version'] ?? '') === '4',
    'recorder verification lost response recovers original receipt and time' =>
        ($results[7]['status'] ?? '') === 'VERIFIED_MATCH'
        && ($results[7]['receipt_id'] ?? '') === '18181818-1818-4181-8181-181818181818'
        && ($results[7]['received_at'] ?? '') === '2026-08-03 16:56:00.111'
        && ($results[7]['canonical_identifiers']['verification_uuid'] ?? '') === $verificationUuid,
    'Flight Closure lost response recovers original receipt and time' =>
        ($results[8]['status'] ?? '') === 'VERIFIED_MATCH'
        && ($results[8]['receipt_id'] ?? '') === '20202020-2020-4202-8202-202020202020'
        && ($results[8]['received_at'] ?? '') === '2026-08-03 18:01:00.222'
        && ($results[8]['canonical_identifiers']['closure_uuid'] ?? '') === $closureComponentUuid,
    'reconciliation performs no inserts or updates' => $beforeCounts === $afterCounts,
);

$root = dirname(__DIR__);
$serviceSource = file_get_contents($root . '/src/CvrWorkflowSyncReconciliationService.php') ?: '';
$endpointSource = file_get_contents($root . '/public/api/cvr/sync_reconcile.php') ?: '';
$dispatchSource = file_get_contents($root . '/src/CvrDispatchIntakeService.php') ?: '';
$evidenceSource = file_get_contents($root . '/src/CvrWorkflowEvidenceIntakeService.php') ?: '';
$checks['endpoint is authenticated and bounded'] =
    str_contains($endpointSource, 'requireDevice()')
    && str_contains($endpointSource, "count(\$payload['items']) > 50")
    && str_contains($endpointSource, 'CvrAuthenticationRequired');
$checks['service uses immutable indexed identities only'] =
    str_contains($serviceSource, 'd.dispatch_uuid = ? AND v.dispatch_version = ?')
    && str_contains($serviceSource, 'WHERE component_uuid = ? LIMIT 1');
$checks['intake and reconciliation share canonical functions'] =
    substr_count($dispatchSource, 'canonicalPayload(') >= 2
    && substr_count($evidenceSource, 'canonicalPayload(') >= 2
    && str_contains($serviceSource, 'dispatchIntake->canonicalPayload')
    && str_contains($serviceSource, 'evidenceIntake->canonicalPayload')
    && str_contains($serviceSource, 'dispatchIntake->isRetryEquivalent');
$checks['reconciliation service contains no data mutation SQL'] =
    preg_match('/\b(INSERT|UPDATE|DELETE)\s+(INTO|FROM|ipca_)/i', $serviceSource) !== 1;
$checks['Phase 1C introduces no schema migration'] =
    glob($root . '/scripts/sql/*phase1c*') === array()
    && glob($root . '/scripts/sql/*reconciliation*') === array();

$failed = array();
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}
if ($failed !== array()) {
    fwrite(STDERR, 'Failed Phase 1C reconciliation checks: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}
echo 'OK: Phase 1C reconciliation contract checks passed.' . PHP_EOL;

/** @return array<string,int> */
function table_counts(PDO $pdo): array
{
    $counts = array();
    foreach (array(
        'ipca_cvr_dispatches',
        'ipca_cvr_dispatch_versions',
        'ipca_cvr_workflow_evidence_batches',
        'ipca_cvr_flight_events',
        'ipca_cvr_recorder_verifications',
        'ipca_cvr_flight_closures',
    ) as $table) {
        $counts[$table] = (int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }
    return $counts;
}
