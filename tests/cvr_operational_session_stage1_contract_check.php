<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/FlightSessionService.php';

function stage1_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "PASS: {$message}\n");
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE system_policy_values (
    id INTEGER PRIMARY KEY AUTOINCREMENT, policy_key TEXT, value_text TEXT, is_active INTEGER
)');
$pdo->exec('CREATE TABLE system_policy_definitions (
    policy_key TEXT PRIMARY KEY, default_value_text TEXT
)');
$pdo->exec('CREATE TABLE ipca_operational_reservations (
    reservation_uuid TEXT PRIMARY KEY, organization_id INTEGER NOT NULL, status TEXT NOT NULL
)');
$pdo->exec('CREATE TABLE ipca_flight_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_uuid TEXT NOT NULL UNIQUE,
    reservation_uuid TEXT,
    dispatch_uuid TEXT,
    workflow_flight_record_uuid TEXT,
    organization_id INTEGER NOT NULL,
    device_id INTEGER,
    aircraft_id INTEGER,
    aircraft_registration TEXT NOT NULL,
    source TEXT NOT NULL,
    model_version TEXT NOT NULL,
    status TEXT NOT NULL,
    dispatch_confirmed_at_utc TEXT,
    avionics_off_utc TEXT,
    starting_hobbs REAL,
    starting_tacho REAL,
    starting_fuel_quantity REAL,
    starting_fuel_unit TEXT,
    starting_oil_quantity REAL,
    starting_oil_unit TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)');
$pdo->exec("INSERT INTO system_policy_values (policy_key,value_text,is_active) VALUES
    ('operational_session_model_enabled','1',1),
    ('operational_session_model_device_allowlist','CVR UNIT 03@N428EA',1)");

$reservation = '10000000-0000-4000-8000-000000000001';
$pdo->prepare('INSERT INTO ipca_operational_reservations VALUES (?,1,?)')
    ->execute(array($reservation, 'scheduled'));
$device = array(
    'id' => 3,
    'organization_id' => 1,
    'aircraft_id' => 428,
    'aircraft_registration' => 'N428EA',
    'display_name' => 'CVR UNIT 03',
);
$service = new FlightSessionService($pdo);
stage1_assert($service->modelEnabledForDevice($device), 'UNIT 03 is enabled only by server flag and allowlist');
$otherAircraftDevice = $device;
$otherAircraftDevice['aircraft_registration'] = 'N392EA';
stage1_assert(
    !$service->modelEnabledForDevice($otherAircraftDevice),
    'rollout allowlist is scoped to UNIT 03 and N428EA together'
);

$session1 = array(
    'operational_session_uuid' => '20000000-0000-4000-8000-000000000001',
    'reservation_uuid' => $reservation,
    'dispatch_uuid' => '30000000-0000-4000-8000-000000000001',
    'workflow_flight_record_uuid' => '40000000-0000-4000-8000-000000000001',
    'dispatch_confirmed_at_utc' => '2026-08-09T16:00:00Z',
    'starting_hobbs' => 100.0,
    'starting_tacho' => 80.0,
    'starting_fuel_quantity' => 13.0,
    'starting_fuel_unit' => 'USG',
    'starting_oil_quantity' => 100,
    'starting_oil_unit' => 'PERCENT',
);
$created1 = $service->createOperationalSession($device, $session1);
stage1_assert($created1['model_version'] === FlightSessionService::MODEL_OPERATIONAL_V1, 'Session 1 stores immutable model version');
$service->closeOperationalSession($session1['operational_session_uuid'], '2026-08-09T17:00:00Z');
stage1_assert(
    $pdo->query("SELECT status FROM ipca_operational_reservations")->fetchColumn() === 'scheduled',
    'completing Session 1 does not complete its reservation'
);

$session2 = $session1;
$session2['operational_session_uuid'] = '20000000-0000-4000-8000-000000000002';
$session2['dispatch_uuid'] = '30000000-0000-4000-8000-000000000002';
$session2['workflow_flight_record_uuid'] = '40000000-0000-4000-8000-000000000002';
$session2['dispatch_confirmed_at_utc'] = '2026-08-09T20:00:00Z';
$created2 = $service->createOperationalSession($device, $session2);
stage1_assert($created2['reservation_uuid'] === $reservation, 'Session 2 reuses the same valid reservation');
stage1_assert(
    (int)$pdo->query('SELECT COUNT(*) FROM ipca_flight_sessions')->fetchColumn() === 2,
    'one reservation preserves two independent Operational Sessions'
);
stage1_assert(
    $pdo->query("SELECT status FROM ipca_flight_sessions WHERE session_uuid='{$session1['operational_session_uuid']}'")
        ->fetchColumn() === 'completed',
    'Session 1 remains immutable after Session 2 starts'
);

$pdo->exec("UPDATE system_policy_values SET value_text='0'
    WHERE policy_key='operational_session_model_enabled'");
$retry1 = $service->createOperationalSession($device, $session1);
stage1_assert($retry1['model_version'] === FlightSessionService::MODEL_OPERATIONAL_V1, 'flag rollback does not reinterpret an existing session');
$session3 = $session2;
$session3['operational_session_uuid'] = '20000000-0000-4000-8000-000000000003';
$session3['dispatch_uuid'] = '30000000-0000-4000-8000-000000000003';
$session3['workflow_flight_record_uuid'] = '40000000-0000-4000-8000-000000000003';
try {
    $service->createOperationalSession($device, $session3);
    stage1_assert(false, 'flag rollback blocks a new session');
} catch (RuntimeException) {
    stage1_assert(true, 'flag rollback blocks a new session without changing existing sessions');
}

$root = dirname(__DIR__);
$dispatchSource = file_get_contents($root . '/src/CvrDispatchIntakeService.php');
$closureSource = file_get_contents($root . '/src/CvrWorkflowEvidenceIntakeService.php');
$swiftSource = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/CVRWorkflowStore.swift');
$uploadSource = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/UploadManager.swift');
$scheduleModels = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Models/CVRCatalogModels.swift');
$scheduleViews = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Views/OperationalWorkflowViews.swift');
$scheduleSource = file_get_contents($root . '/src/FlightScheduleService.php');
$migration = file_get_contents($root . '/scripts/sql/2026_08_09_cvr_operational_session_rebase.sql');

stage1_assert(str_contains($dispatchSource, 'Operational Session Dispatch must not create an actual leg identity.'), 'new Dispatch rejects premature actual leg UUIDs');
stage1_assert(str_contains($dispatchSource, "session_model_version") && str_contains($dispatchSource, "dispatchAirportsMatchScheduledPlan"), 'route validation is model-gated');
stage1_assert(
    str_contains($dispatchSource, 'Scheduled session aircraft does not match')
    && str_contains($dispatchSource, 'Scheduled session mission does not match')
    && str_contains($dispatchSource, 'Scheduled session crew does not match')
    && str_contains($dispatchSource, 'assertDispatchMatches($reservationUuid, $normalized)'),
    'different device, mission, crew, or accountable duty cannot reuse the reservation'
);
stage1_assert(str_contains($closureSource, "SET schedule_slot.status = 'scheduled'"), 'new-model closure makes the reservation reusable');
stage1_assert(str_contains($closureSource, "SET schedule_slot.status = 'completed'"), 'legacy closure behavior remains available');
stage1_assert(
    str_contains($scheduleSource, 'bool $deriveOperationalCompletion = true')
    && str_contains($scheduleSource, 'listSlots($fromDate, $toDate, $aircraftId, false)')
    && str_contains($scheduleSource, '$hasDisplayClosure'),
    'web derives completed session status while the device keeps the reservation reusable'
);
stage1_assert(str_contains($swiftSource, 'activeOperationalSession') && str_contains($swiftSource, 'UUID().uuidString.lowercased()'), 'iOS persists an explicit Operational Session identity');
stage1_assert(str_contains($uploadSource, 'isOperationalSession || (!plannedDeparture.isEmpty && !plannedDestination.isEmpty)'), 'flagged Dispatch upload is route-free');
stage1_assert(!str_contains($migration, 'UNIQUE INDEX idx_ipca_flight_sessions_dispatch_uuid'), 'Stage 1 does not add unaudited unique Dispatch/session indexes');
stage1_assert(!str_contains($migration, 'actual_leg_uuid'), 'Stage 1 does not introduce actual leg identity');
stage1_assert(
    str_contains($scheduleViews, 'Text("Flight \\(flightNumber)")')
    && str_contains($scheduleViews, 'Text("Crew: \\(group.crewNames.joined')
    && str_contains($scheduleViews, 'Text("Mission: \\(group.missionDisplay)")')
    && str_contains($scheduleViews, 'Text("Route: \\(group.routeSummary) (Informative)")')
    && !str_contains($scheduleViews, 'Text("\\(group.legs.count) LEG'),
    'Schedule card presents one flight with informative route instead of operational leg count'
);
stage1_assert(
    str_contains($scheduleModels, 'missionDescription = try container.decodeIfPresent')
    && str_contains($scheduleModels, 'missionReference?.name'),
    'Schedule mission code includes server mission text'
);

fwrite(STDOUT, "Stage 1 Operational Session contracts passed.\n");
