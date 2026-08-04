<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/CvrWorkflowSyncReconciliationService.php';

$root = dirname(__DIR__);
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
$otherDevice = array(
    'id' => 99,
    'organization_id' => 1,
    'aircraft_id' => 8,
    'aircraft_registration' => 'N999XX',
);
$dispatchUuid = '11111111-1111-4111-8111-111111111111';
$flightUuid = '22222222-2222-4222-8222-222222222222';
$eventUuid = '44444444-4444-4444-8444-444444444444';
$componentUuid = '33333333-3333-4333-8333-333333333333';
$missingVersionUuid = '55555555-5555-4555-8555-555555555555';

$pdo->prepare(
    'INSERT INTO ipca_cvr_dispatches
     (id, dispatch_uuid, workflow_flight_record_uuid, device_id, aircraft_registration)
     VALUES (?, ?, ?, ?, ?)'
)->execute(array(101, $dispatchUuid, $flightUuid, 10, 'N392EA'));

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

$service = new CvrWorkflowSyncReconciliationService($pdo);

$wrongAircraftPayload = $dispatchPayload;
$wrongAircraftPayload['dispatch']['tail_number'] = 'N999XX';
$wrongAircraftPayload['dispatch']['aircraft_id'] = 8;
$wrongAircraftPayload['dispatch']['version'] = 5;
$wrongAircraftNotFound = array(
    'item_id' => 'dispatch-' . $dispatchUuid . '-v5',
    'component_type' => 'dispatch_metadata',
    'dispatch_uuid' => $dispatchUuid,
    'dispatch_version' => 5,
    'flight_record_uuid' => $flightUuid,
    'payload' => $wrongAircraftPayload,
);

$missingVersionPayload = $dispatchPayload;
$missingVersionPayload['dispatch']['version'] = 5;
$missingVersionPayload['consents'][0]['dispatch_version'] = 5;
$missingVersionItem = array(
    'item_id' => 'dispatch-' . $dispatchUuid . '-v5-ok',
    'component_type' => 'dispatch_metadata',
    'dispatch_uuid' => $dispatchUuid,
    'dispatch_version' => 5,
    'flight_record_uuid' => $flightUuid,
    'payload' => $missingVersionPayload,
);

$wrongDevicePayload = $dispatchPayload;
$wrongDevicePayload['dispatch']['tail_number'] = 'N999XX';
$wrongDevicePayload['dispatch']['aircraft_id'] = 8;
$wrongDeviceItem = array(
    'item_id' => 'dispatch-' . $dispatchUuid . '-v4-other-device',
    'component_type' => 'dispatch_metadata',
    'dispatch_uuid' => $dispatchUuid,
    'dispatch_version' => 4,
    'flight_record_uuid' => $flightUuid,
    'payload' => $wrongDevicePayload,
);

$immutableConflictItem = array(
    'item_id' => $componentUuid . '-conflict',
    'component_type' => 'flight_events',
    'component_uuid' => $componentUuid,
    'dispatch_uuid' => $dispatchUuid,
    'flight_record_uuid' => $flightUuid,
    'payload' => $eventPayload,
);
$immutableConflictItem['payload']['evidence']['event_type'] = 'takeoff';

$crewCorrectionDispatch = $dispatchPayload;
$crewCorrectionDispatch['dispatch']['tail_number'] = 'N000ZZ';
$crewCorrectionDispatch['dispatch']['aircraft_id'] = 99;
$crewCorrectionAgainstStored = array(
    'item_id' => 'dispatch-' . $dispatchUuid . '-v4-wrong-tail',
    'component_type' => 'dispatch_metadata',
    'dispatch_uuid' => $dispatchUuid,
    'dispatch_version' => 4,
    'flight_record_uuid' => $flightUuid,
    'payload' => $crewCorrectionDispatch,
);

$beforeCounts = table_counts($pdo);
$results = $service->reconcile(array(
    $wrongAircraftNotFound,
    $missingVersionItem,
    $immutableConflictItem,
    $crewCorrectionAgainstStored,
), $device);
$wrongDeviceResults = $service->reconcile(array($wrongDeviceItem), $otherDevice);
$afterCounts = table_counts($pdo);

$store = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/CVRWorkflowStore.swift') ?: '';
$upload = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/UploadManager.swift') ?: '';
$api = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/APIClient.swift') ?: '';
$serviceSource = file_get_contents($root . '/src/CvrWorkflowSyncReconciliationService.php') ?: '';

$checks = array(
    'Dispatch NOT_FOUND validates aircraft before classifying absence' =>
        ($results[0]['status'] ?? '') === 'USER_CORRECTION_REQUIRED'
        && ($results[0]['user_action_required'] ?? false) === true
        && str_contains((string)($results[0]['error'] ?? ''), 'tail number'),
    'valid Dispatch missing version remains NOT_FOUND after ownership validation' =>
        ($results[1]['status'] ?? '') === 'NOT_FOUND'
        && ($results[1]['retryable'] ?? false) === true,
    'payload hash mismatch against stored immutable evidence is IMMUTABLE_CONFLICT' =>
        ($results[2]['status'] ?? '') === 'IMMUTABLE_CONFLICT'
        && ($results[2]['user_action_required'] ?? true) === false
        && ($results[2]['retryable'] ?? true) === false,
    'crew-correctable aircraft mismatch against stored Dispatch remains USER_CORRECTION_REQUIRED' =>
        ($results[3]['status'] ?? '') === 'USER_CORRECTION_REQUIRED'
        && ($results[3]['user_action_required'] ?? false) === true
        && ($results[3]['status'] ?? '') !== 'IMMUTABLE_CONFLICT',
    'wrong-device ownership against stored Dispatch is IMMUTABLE_CONFLICT' =>
        ($wrongDeviceResults[0]['status'] ?? '') === 'IMMUTABLE_CONFLICT'
        && ($wrongDeviceResults[0]['user_action_required'] ?? true) === false,
    'reconciliation remains read-only during Phase 1D ownership and classification checks' =>
        $beforeCounts === $afterCounts,
    'PHP reconciliation preserves distinct USER_CORRECTION_REQUIRED catch path' =>
        str_contains($serviceSource, "catch (CvrUserCorrectionRequired \$e)")
        && str_contains($serviceSource, "'USER_CORRECTION_REQUIRED'")
        && strpos($serviceSource, "dispatchIntake->canonicalPayload(\$item['payload'], \$device)")
            < strpos($serviceSource, "if (!is_array(\$stored)) {")
        && str_contains($serviceSource, 'Dispatch tail number does not match') === false,
    'iOS snapshot byte limit rejects oversized JSON without media embedding' =>
        str_contains($store, 'static let maximumRequestPayloadSnapshotBytes = 256 * 1024')
        && str_contains($store, 'payload.count <= Self.maximumRequestPayloadSnapshotBytes')
        && str_contains($store, 'operational evidence was preserved without the oversized snapshot')
        && str_contains($upload, 'maximumRequestPayloadSnapshotBytes')
        && !str_contains($upload, 'Data(contentsOf:')
        && !str_contains($upload, 'audioBytes')
        && !str_contains($upload, 'csvData'),
    'routine upload loop blocks reconciliation-required IDs before normal POST' =>
        str_contains($upload, 'let reconciliationBlockedIDs = Set(allReconciliationComponents.map(\.id))')
        && str_contains($upload, 'if reconciliationBlockedIDs.contains(component.id)')
        && str_contains($upload, 'case .notFound:')
        && str_contains($upload, 'reconciliationRequired: false')
        && str_contains($upload, 'case .userCorrectionRequired:')
        && str_contains($api, 'case userCorrectionRequired = "USER_CORRECTION_REQUIRED"'),
    'restart recovery still marks incomplete metadata for reconciliation' =>
        str_contains($store, 'recoverIncompleteActiveVerificationMetadata')
        && str_contains($store, 'component.reconciliationRequired = true')
        && str_contains($store, 'Server verification metadata is incomplete; queued for authoritative reconciliation.'),
);

$failed = array();
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}
if ($failed !== array()) {
    fwrite(STDERR, 'Failed Phase 1D integrity checks: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}
echo 'OK: Phase 1D integrity contract checks passed.' . PHP_EOL;

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
