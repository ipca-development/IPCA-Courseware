#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/communication/CommunicationKernel.php';
require_once __DIR__ . '/../src/FlightScheduleService.php';
require_once __DIR__ . '/../src/scheduler/SchedulerApiService.php';
require_once __DIR__ . '/../src/scheduler/SchedulerVisibilityService.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec("CREATE TABLE users (
 id INTEGER PRIMARY KEY, uuid TEXT, email TEXT, name TEXT, first_name TEXT, last_name TEXT,
 role TEXT, status TEXT, account_valid_until TEXT NULL, photo_path TEXT, password_hash TEXT
)");
$pdo->exec("CREATE TABLE ipca_communication_devices (
 id INTEGER PRIMARY KEY AUTOINCREMENT, device_uuid TEXT UNIQUE, user_id INTEGER, organization_id INTEGER,
 platform TEXT, model TEXT, os_version TEXT, app_version TEXT, revoked_at_utc TEXT NULL,
 last_sync_cursor INTEGER DEFAULT 0, last_seen_at_utc TEXT NULL, push_authorized INTEGER NULL,
 created_at_utc TEXT, updated_at_utc TEXT
)");
$pdo->exec("CREATE TABLE ipca_communication_device_credentials (
 id INTEGER PRIMARY KEY AUTOINCREMENT, credential_uuid TEXT, device_id INTEGER, token_hash TEXT UNIQUE,
 label TEXT, created_at_utc TEXT, expires_at_utc TEXT NULL, revoked_at_utc TEXT NULL, last_used_at_utc TEXT NULL
)");
$pdo->exec("CREATE TABLE ipca_communication_app_config (config_key TEXT PRIMARY KEY, config_value TEXT)");
$pdo->exec("CREATE TABLE system_policy_values (
 id INTEGER PRIMARY KEY AUTOINCREMENT, policy_key TEXT, value_text TEXT, is_active INTEGER DEFAULT 1
)");
$pdo->exec("CREATE TABLE ipca_flight_schedule_slots (
 id INTEGER PRIMARY KEY AUTOINCREMENT, scheduler_record_id TEXT UNIQUE, organization_id INTEGER,
 reservation_type TEXT DEFAULT 'flight_training', scheduled_date TEXT, scheduled_start_time TEXT,
 scheduled_end_time TEXT, aircraft_id INTEGER, mission_id INTEGER NULL, cohort_id INTEGER NULL,
 mission_code TEXT DEFAULT '', planned_departure_airport TEXT DEFAULT '',
 planned_destination_airport TEXT DEFAULT '', status TEXT DEFAULT 'scheduled',
 claimed_dispatch_uuid TEXT NULL, claimed_at TEXT NULL, notes TEXT DEFAULT '',
 created_by INTEGER NULL, updated_by INTEGER NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE ipca_flight_schedule_crew (
 id INTEGER PRIMARY KEY AUTOINCREMENT, schedule_slot_id INTEGER, user_id INTEGER,
 person_name_snapshot TEXT, crew_role TEXT, pilot_function TEXT DEFAULT 'NONE',
 is_pic INTEGER DEFAULT 0
)");
$pdo->exec("CREATE TABLE ipca_operational_reservations (
 id INTEGER PRIMARY KEY AUTOINCREMENT, reservation_uuid TEXT UNIQUE, organization_id INTEGER,
 organization_timezone_iana TEXT, reservation_type TEXT, activity_domain TEXT, status TEXT,
 source TEXT, adoption_source_system TEXT NULL, adoption_provenance_json TEXT NULL,
 created_at_utc TEXT DEFAULT CURRENT_TIMESTAMP, updated_at_utc TEXT DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE ipca_operational_reservation_legs (
 id INTEGER PRIMARY KEY AUTOINCREMENT, leg_uuid TEXT UNIQUE, reservation_uuid TEXT, organization_id INTEGER,
 sequence_number INTEGER, origin_airport TEXT, destination_airport TEXT,
 planned_start_at_utc TEXT NULL, planned_end_at_utc TEXT NULL,
 planned_start_local TEXT NULL, planned_end_local TEXT NULL,
 organization_timezone_iana TEXT, planned_start_utc_offset_minutes INTEGER NULL,
 planned_end_utc_offset_minutes INTEGER NULL, planned_start_dst_resolution TEXT NULL,
 planned_end_dst_resolution TEXT NULL, status TEXT, source TEXT,
 created_at_utc TEXT DEFAULT CURRENT_TIMESTAMP, updated_at_utc TEXT DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE ipca_operational_identity_aliases (
 id INTEGER PRIMARY KEY AUTOINCREMENT, organization_id INTEGER, source_system TEXT, alias_type TEXT,
 alias_value TEXT, alias_version TEXT NULL, alias_version_key TEXT DEFAULT '', target_type TEXT,
 reservation_uuid TEXT NULL, leg_uuid TEXT NULL, confidence_state TEXT, linkage_method TEXT,
 created_at_utc TEXT DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE ipca_scheduler_api_mutations (
 id INTEGER PRIMARY KEY AUTOINCREMENT, actor_user_id INTEGER, organization_id INTEGER,
 idempotency_key TEXT, request_sha256 TEXT, reservation_uuid TEXT, status TEXT,
 created_at_utc TEXT, updated_at_utc TEXT, completed_at_utc TEXT NULL,
 UNIQUE(actor_user_id, idempotency_key)
)");
$pdo->exec("CREATE TABLE ipca_aircraft_devices (
 id INTEGER PRIMARY KEY, registration TEXT, display_name TEXT DEFAULT '', aircraft_type TEXT DEFAULT '',
 home_airport TEXT DEFAULT '', active INTEGER DEFAULT 1
)");
$pdo->exec("CREATE TABLE ipca_missions (
 id INTEGER PRIMARY KEY, organization_id INTEGER, code TEXT, name TEXT DEFAULT ''
)");
$pdo->exec("CREATE TABLE cohorts (id INTEGER PRIMARY KEY, name TEXT)");
$pdo->exec("CREATE TABLE ipca_cvr_dispatches (
 id INTEGER PRIMARY KEY, dispatch_uuid TEXT, workflow_flight_record_uuid TEXT,
 operational_session_uuid TEXT NULL, current_version INTEGER DEFAULT 1, last_received_at TEXT NULL,
 starting_hobbs REAL NULL, starting_tacho REAL NULL, fuel_onboard TEXT NULL,
 aircraft_registration TEXT, scheduler_record_id TEXT NULL, status TEXT,
 aircraft_id INTEGER, scheduled_date TEXT, mission_code TEXT, first_received_at TEXT
)");
$pdo->exec("CREATE TABLE ipca_cvr_flight_closures (id INTEGER PRIMARY KEY, workflow_flight_record_uuid TEXT)");
$pdo->exec("CREATE TABLE ipca_cvr_flight_events (id INTEGER PRIMARY KEY, workflow_flight_record_uuid TEXT)");
$pdo->exec("CREATE TABLE ipca_cockpit_recordings (
 id INTEGER PRIMARY KEY, flight_session_uid TEXT, upload_status TEXT
)");
$pdo->exec("CREATE TABLE ipca_structured_debriefs (id INTEGER PRIMARY KEY, bundle_id INTEGER, status TEXT)");
$pdo->exec("CREATE TABLE ipca_manual_intake_bundles (id INTEGER PRIMARY KEY, dispatch_id INTEGER)");

$password = password_hash('correct horse battery staple', PASSWORD_DEFAULT);
$insertUser = $pdo->prepare(
    "INSERT INTO users
      (id, uuid, email, name, first_name, last_name, role, status, account_valid_until, photo_path, password_hash)
     VALUES (?, ?, ?, ?, '', '', ?, ?, ?, '', ?)"
);
$insertUser->execute(array(1, '11111111-1111-1111-1111-111111111111', 'admin@example.test', 'Admin', 'admin', 'active', null, $password));
$insertUser->execute(array(2, '22222222-2222-2222-2222-222222222222', 'student@example.test', 'Student', 'student', 'active', null, $password));
$insertUser->execute(array(3, '33333333-3333-3333-3333-333333333333', 'locked@example.test', 'Locked', 'student', 'locked', null, $password));
$insertUser->execute(array(4, '44444444-4444-4444-4444-444444444444', 'expired@example.test', 'Expired', 'student', 'active', '2020-01-01 00:00:00', $password));
$insertUser->execute(array(5, '55555555-5555-5555-5555-555555555555', 'instructor@example.test', 'Instructor', 'supervisor', 'active', null, $password));

$checks = array();

// Human mobile auth: same credential tables, eligibility and revocation behavior.
$kernel = new CommunicationKernel($pdo);
$login = $kernel->auth->login('admin@example.test', 'correct horse battery staple', array(
    'device_uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
    'platform' => 'iphone',
    'model' => 'Test iPhone',
    'os_version' => '19.0',
    'app_version' => '1.0',
), false);
$session = $kernel->auth->authenticateToken((string)$login['token']);
$checks['valid bearer session preserves organization context'] =
    (int)$session['user']['id'] === 1 && (int)$session['device']['organization_id'] === 1;

$lockedRejected = false;
try {
    $kernel->auth->login('locked@example.test', 'correct horse battery staple', array(
        'device_uuid' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
        'platform' => 'iphone',
    ), false);
} catch (CommunicationException $e) {
    $lockedRejected = $e->errorCode === 'account_ineligible';
}
$expiredRejected = false;
try {
    $kernel->auth->login('expired@example.test', 'correct horse battery staple', array(
        'device_uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccccc',
        'platform' => 'iphone',
    ), false);
} catch (CommunicationException $e) {
    $expiredRejected = $e->errorCode === 'account_ineligible';
}
$checks['inactive and expired accounts are rejected'] = $lockedRejected && $expiredRejected;
$kernel->auth->logout($session);
$revokedRejected = false;
try {
    $kernel->auth->authenticateToken((string)$login['token']);
} catch (CommunicationException $e) {
    $revokedRejected = $e->errorCode === 'credential_revoked';
}
$checks['revoked bearer session is rejected'] = $revokedRejected;
$missingRejected = false;
$invalidRejected = false;
try {
    $kernel->auth->authenticateToken('');
} catch (CommunicationException $e) {
    $missingRejected = $e->errorCode === 'unauthenticated';
}
try {
    $kernel->auth->authenticateToken('invalid-token');
} catch (CommunicationException $e) {
    $invalidRejected = $e->errorCode === 'unauthenticated';
}
$checks['missing and invalid bearer tokens are rejected'] = $missingRejected && $invalidRejected;

// Visibility is enforced in SQL, not by the client.
$ownId = '10000000-0000-4000-8000-000000000001';
$otherId = '10000000-0000-4000-8000-000000000002';
$pdo->exec("INSERT INTO ipca_flight_schedule_slots
 (scheduler_record_id, organization_id, scheduled_date, scheduled_start_time, scheduled_end_time, aircraft_id, status)
 VALUES
 ('$ownId', 1, '2026-08-20', '2026-08-20 10:00:00', '2026-08-20 12:00:00', 10, 'scheduled'),
 ('$otherId', 1, '2026-08-20', '2026-08-20 13:00:00', '2026-08-20 14:00:00', 11, 'scheduled')");
$pdo->exec("INSERT INTO ipca_flight_schedule_crew
 (schedule_slot_id, user_id, person_name_snapshot, crew_role)
 SELECT id, 2, 'Student', 'student' FROM ipca_flight_schedule_slots WHERE scheduler_record_id = '$ownId'");
$visibility = new SchedulerVisibilityService($pdo);
$studentUser = array('id' => 2, 'role' => 'student');
$staffUser = array('id' => 5, 'role' => 'supervisor');
$studentOwn = $visibility->requireVisibleReservationDate($studentUser, 1, $ownId) === '2026-08-20';
$studentUnrelatedHidden = false;
try {
    $visibility->requireVisibleReservationDate($studentUser, 1, $otherId);
} catch (SchedulerApiException $e) {
    $studentUnrelatedHidden = $e->errorCode === 'not_found';
}
$checks['students see assigned reservations but not unrelated UUIDs'] =
    $studentOwn && $studentUnrelatedHidden
    && $visibility->requireVisibleReservationDate($staffUser, 1, $otherId) === '2026-08-20';

// Route-window regression: route and canonical legs update together.
$routeId = '20000000-0000-4000-8000-000000000001';
$pdo->exec("INSERT INTO ipca_flight_schedule_slots
 (scheduler_record_id, organization_id, scheduled_date, scheduled_start_time, scheduled_end_time,
  aircraft_id, planned_departure_airport, planned_destination_airport, status)
 VALUES ('$routeId', 1, '2026-08-21', '2026-08-21 09:00:00', '2026-08-21 11:00:00',
  20, 'KPSP', 'KTRM', 'scheduled')");
$routeSlotId = (int)$pdo->query("SELECT id FROM ipca_flight_schedule_slots WHERE scheduler_record_id = '$routeId'")->fetchColumn();
$pdo->exec("INSERT INTO ipca_flight_schedule_crew
 (schedule_slot_id, user_id, person_name_snapshot, crew_role) VALUES ($routeSlotId, 2, 'Student', 'student')");
$pdo->exec("INSERT INTO ipca_operational_reservations
 (reservation_uuid, organization_id, organization_timezone_iana, reservation_type, activity_domain, status, source)
 VALUES ('$routeId', 1, 'America/Los_Angeles', 'flight_training', 'flight', 'scheduled', 'test')");
$pdo->exec("INSERT INTO ipca_operational_reservation_legs
 (leg_uuid, reservation_uuid, organization_id, sequence_number, origin_airport, destination_airport,
  planned_start_local, planned_end_local, organization_timezone_iana, status, source)
 VALUES
 ('21000000-0000-4000-8000-000000000001', '$routeId', 1, 1, 'KPSP', 'KTRM',
  '2026-08-21 09:00:00', '2026-08-21 11:00:00', 'America/Los_Angeles', 'scheduled', 'test')");
$flight = new FlightScheduleService($pdo);
$routeResult = $flight->updateScheduledDutyWindowFromDevice(
    array('aircraft_id' => 20),
    array(
        'scheduler_record_id' => $routeId,
        'reservation_uuid' => $routeId,
        'aircraft_id' => 20,
        'scheduled_date' => '2026-08-21',
        'scheduled_start_time' => '2026-08-21 10:00:00',
        'scheduled_end_time' => '2026-08-21 12:00:00',
        'legs' => array(array(
            'sequence_number' => 1,
            'origin_airport' => 'KPSP',
            'destination_airport' => 'KUDD',
        )),
    )
);
$routeSlot = $pdo->query("SELECT * FROM ipca_flight_schedule_slots WHERE scheduler_record_id = '$routeId'")->fetch(PDO::FETCH_ASSOC);
$routeLeg = $pdo->query("SELECT * FROM ipca_operational_reservation_legs WHERE reservation_uuid = '$routeId'")->fetch(PDO::FETCH_ASSOC);
$checks['route-window update persists slot route and canonical leg'] =
    !empty($routeResult['ok'])
    && $routeSlot['planned_destination_airport'] === 'KUDD'
    && $routeLeg['destination_airport'] === 'KUDD'
    && $routeLeg['planned_start_local'] === '2026-08-21 10:00:00.000';

// Cancellation is atomic across slot and canonical identity and preserves history.
$cancelId = '30000000-0000-4000-8000-000000000001';
$pdo->exec("INSERT INTO ipca_flight_schedule_slots
 (scheduler_record_id, organization_id, scheduled_date, scheduled_start_time, scheduled_end_time,
  aircraft_id, status, updated_at)
 VALUES ('$cancelId', 1, '2026-08-22', '2026-08-22 10:00:00', '2026-08-22 12:00:00',
  30, 'scheduled', '2026-08-19 12:00:00')");
$cancelSlotId = (int)$pdo->query("SELECT id FROM ipca_flight_schedule_slots WHERE scheduler_record_id = '$cancelId'")->fetchColumn();
$pdo->exec("INSERT INTO ipca_flight_schedule_crew
 (schedule_slot_id, user_id, person_name_snapshot, crew_role) VALUES ($cancelSlotId, 3, 'Former Student', 'student')");
$pdo->exec("INSERT INTO ipca_operational_reservations
 (reservation_uuid, organization_id, organization_timezone_iana, reservation_type, activity_domain, status, source)
 VALUES ('$cancelId', 1, 'America/Los_Angeles', 'flight_training', 'flight', 'scheduled', 'test')");
$pdo->exec("INSERT INTO ipca_operational_reservation_legs
 (leg_uuid, reservation_uuid, organization_id, sequence_number, origin_airport, destination_airport,
  organization_timezone_iana, status, source)
 VALUES ('31000000-0000-4000-8000-000000000001', '$cancelId', 1, 1, 'KPSP', 'KTRM',
  'America/Los_Angeles', 'scheduled', 'test')");
$pdo->exec("INSERT INTO ipca_cvr_dispatches
 (id, dispatch_uuid, workflow_flight_record_uuid, scheduler_record_id, status, aircraft_id, scheduled_date, mission_code)
 VALUES (90, '32000000-0000-4000-8000-000000000001', '33000000-0000-4000-8000-000000000001',
 '$cancelId', 'released', 30, '2026-08-22', '')");
$staleRejected = false;
try {
    $flight->cancelSlot($cancelId, 1, '2026-08-19T11:59:59');
} catch (RuntimeException $e) {
    $staleRejected = str_contains($e->getMessage(), 'changed in another session');
}
$flight->cancelSlot($cancelId, 1, '2026-08-19T12:00:00');
$checks['cancel rejects stale version and aligns canonical states without deleting history'] =
    $staleRejected
    && $pdo->query("SELECT status FROM ipca_flight_schedule_slots WHERE scheduler_record_id = '$cancelId'")->fetchColumn() === 'cancelled'
    && $pdo->query("SELECT status FROM ipca_operational_reservations WHERE reservation_uuid = '$cancelId'")->fetchColumn() === 'cancelled'
    && $pdo->query("SELECT status FROM ipca_operational_reservation_legs WHERE reservation_uuid = '$cancelId'")->fetchColumn() === 'cancelled'
    && (int)$pdo->query("SELECT COUNT(*) FROM ipca_flight_schedule_crew WHERE schedule_slot_id = $cancelSlotId")->fetchColumn() === 1
    && (int)$pdo->query("SELECT COUNT(*) FROM ipca_cvr_dispatches WHERE scheduler_record_id = '$cancelId'")->fetchColumn() === 1;

// Crew eligibility only applies to newly assigned users; historical inactive crew remains readable/editable in place.
$inactiveRejected = false;
try {
    $flight->assertCrewUsersEligible(array(array('user_id' => 3)));
} catch (RuntimeException $e) {
    $inactiveRejected = str_contains($e->getMessage(), 'active, eligible');
}
$expiredCrewRejected = false;
try {
    $flight->assertCrewUsersEligible(array(array('user_id' => 4)));
} catch (RuntimeException $e) {
    $expiredCrewRejected = str_contains($e->getMessage(), 'active, eligible');
}
$historicalAllowed = true;
try {
    $flight->assertCrewUsersEligible(array(array('user_id' => 3)), array(3));
} catch (Throwable) {
    $historicalAllowed = false;
}
$checks['new crew must be eligible while historical inactive crew is preserved'] =
    $inactiveRejected && $expiredCrewRejected && $historicalAllowed;
$crewComposition = new ReflectionMethod($flight, 'assertCrewComposition');
$validCrew = array(
    array(
        'user_id' => 2,
        'person_name' => 'Student',
        'role' => 'student',
        'is_primary_customer' => true,
        'is_pic' => false,
    ),
    array(
        'user_id' => 5,
        'person_name' => 'Instructor',
        'role' => 'instructor',
        'is_primary_customer' => false,
        'is_pic' => true,
    ),
);
$crewRulesPass = true;
try {
    $crewComposition->invoke($flight, 'flight_training', $validCrew);
} catch (Throwable) {
    $crewRulesPass = false;
}
$missingPicRejected = false;
try {
    $withoutPic = $validCrew;
    $withoutPic[1]['is_pic'] = false;
    $crewComposition->invoke($flight, 'flight_training', $withoutPic);
} catch (RuntimeException $e) {
    $missingPicRejected = str_contains($e->getMessage(), 'logging PIC');
}
$checks['authoritative service enforces primary customer and flight PIC composition'] =
    $crewRulesPass && $missingPicRejected;

// Overlaps remain structured warnings and are not thrown as conflicts.
$warningDetails = $flight->assessResourceConflicts(
    '40000000-0000-4000-8000-000000000001',
    10,
    null,
    array(2),
    '2026-08-20 11:00:00',
    '2026-08-20 11:30:00'
);
$warningCodes = array_column($warningDetails, 'code');
$checks['aircraft and crew overlap remain allowed structured warnings'] =
    in_array('aircraft_overlap', $warningCodes, true)
    && in_array('crew_overlap', $warningCodes, true)
    && array_reduce($warningDetails, static fn(bool $ok, array $warning): bool =>
        $ok && !empty($warning['conflicting_reservation_uuid']), true);

// Reads no longer invoke operational reconciliation.
$pdo->exec("INSERT INTO ipca_cvr_dispatches
 (id, dispatch_uuid, workflow_flight_record_uuid, scheduler_record_id, status, aircraft_id,
  scheduled_date, mission_code, first_received_at, aircraft_registration)
 VALUES (91, '51000000-0000-4000-8000-000000000001', '52000000-0000-4000-8000-000000000001',
 NULL, 'completed', 50, '2026-09-01', '', '2026-09-01 12:00:00', 'NTEST')");
$pdo->exec("INSERT INTO ipca_cvr_flight_closures
 (id, workflow_flight_record_uuid) VALUES (91, '52000000-0000-4000-8000-000000000001')");
$emptyRead = $flight->listSlots('2026-09-01', '2026-09-01');
$orphanStillUnlinked = $pdo->query(
    'SELECT scheduler_record_id FROM ipca_cvr_dispatches WHERE id = 91'
)->fetchColumn();
$source = file_get_contents(__DIR__ . '/../src/FlightScheduleService.php') ?: '';
$listStart = strpos($source, 'public function listSlots(');
$listEnd = strpos($source, 'public function scheduledSessionsForDevice(', $listStart ?: 0);
$listSource = $listStart !== false ? substr($source, $listStart, ($listEnd ?: strlen($source)) - $listStart) : '';
$checks['normal listSlots reads are side-effect free'] =
    $emptyRead === array()
    && ($orphanStillUnlinked === false || $orphanStillUnlinked === null)
    && !str_contains($listSource, 'reconcileUnlinkedCompletedDispatches(')
    && str_contains($source, 'public function reconcileUnlinkedCompletedDispatchesForRange');

// Time contract preserves naive operational-local values across PST/PDT and DST boundaries.
$api = new SchedulerApiService($pdo);
$presentLocal = new ReflectionMethod($api, 'localWithMilliseconds');
$timeFixtures = array(
    '2026-01-15T10:00:00' => '2026-01-15T10:00:00.000',
    '2026-07-15T10:00:00' => '2026-07-15T10:00:00.000',
    '2026-03-08T02:30:00' => '2026-03-08T02:30:00.000',
    '2026-11-01T01:30:00' => '2026-11-01T01:30:00.000',
);
$timeOk = true;
foreach ($timeFixtures as $input => $expected) {
    $timeOk = $timeOk && $presentLocal->invoke($api, $input) === $expected;
}
$bootstrap = $api->bootstrap(array(
    'user' => array('id' => 1, 'uuid' => '', 'email' => '', 'name' => 'Admin', 'role' => 'admin'),
    'device' => array('organization_id' => 1),
));
$checks['timezone contract is explicit and never appends UTC Z'] =
    $timeOk
    && $bootstrap['operational_timezone'] === 'America/Los_Angeles'
    && $bootstrap['scheduler']['schedule_time_semantics'] === 'timezone_free_operational_local';
$studentCapabilities = $api->capabilities(array('id' => 2, 'role' => 'student'));
$staffCapabilities = $api->capabilities(array('id' => 5, 'role' => 'supervisor'));
$checks['server capabilities derive actions from existing scheduler authorization'] =
    !empty($studentCapabilities['schedule_read'])
    && empty($studentCapabilities['reservation_create'])
    && !empty($staffCapabilities['reservation_create'])
    && !empty($staffCapabilities['reservation_cancel'])
    && empty($staffCapabilities['reservation_undispatch'])
    && empty($staffCapabilities['manual_checkin'])
    && empty($staffCapabilities['dispatch']);
$rangeRejected = false;
try {
    $api->normalizeRange('2026-01-01', '2026-02-15');
} catch (SchedulerApiException $e) {
    $rangeRejected = $e->errorCode === 'invalid_request';
}
$checks['schedule ranges are validated and bounded'] =
    $api->normalizeRange('2026-08-19', '2026-08-25') === array('2026-08-19', '2026-08-25')
    && $rangeRejected;

// Full create path uses one authoritative save and returns the same reservation on retry.
$pdo->exec("INSERT INTO ipca_aircraft_devices
 (id, registration, display_name, aircraft_type, home_airport, active)
 VALUES (60, 'N60TEST', 'Test Aircraft', 'Test Type', 'KPSP', 1)");
$pdo->exec("INSERT INTO ipca_missions
 (id, organization_id, code, name) VALUES (60, 1, 'TEST', 'Catalog context')");
$adminSession = array(
    'user' => array('id' => 1, 'uuid' => '', 'email' => 'admin@example.test', 'name' => 'Admin', 'role' => 'admin'),
    'device' => array('organization_id' => 1),
);
$createInput = array(
    'reservation_type' => 'other',
    'start_local' => '2026-09-02T10:00:00.000',
    'end_local' => '2026-09-02T11:00:00.000',
    'aircraft_id' => 60,
    'airport_chain' => array('KPSP', 'KTRM'),
    'crew' => array(array(
        'user_id' => 2,
        'role' => 'student',
        'pilot_function' => 'NONE',
        'is_pic' => false,
        'is_primary_customer' => true,
    )),
    'notes' => 'API test',
);
$created = $api->createReservation($adminSession, $createInput, 'full-create-1');
$createdRetry = $api->createReservation($adminSession, $createInput, 'full-create-1');
$createdUuid = (string)($created['reservation']['reservation_uuid'] ?? '');
$checks['full API create is retry-safe and returns canonical schedule state'] =
    !empty($created['ok'])
    && empty($created['already_present'])
    && !empty($createdRetry['already_present'])
    && $createdUuid !== ''
    && $createdRetry['reservation']['reservation_uuid'] === $createdUuid
    && (int)$pdo->query(
        "SELECT COUNT(*) FROM ipca_flight_schedule_slots WHERE scheduler_record_id = "
        . $pdo->quote($createdUuid)
    )->fetchColumn() === 1;
$originalUpdatedAt = (string)$created['reservation']['updated_at'];
$pdo->exec(
    "UPDATE ipca_flight_schedule_slots SET updated_at = '2026-09-02 09:59:00'"
    . ' WHERE scheduler_record_id = ' . $pdo->quote($createdUuid)
);
$apiStaleRejected = false;
try {
    $api->updateReservation($adminSession, $createdUuid, array(
        'expected_updated_at' => $originalUpdatedAt,
        'notes' => 'Stale update',
    ));
} catch (RuntimeException $e) {
    $apiStaleRejected = SchedulerApiException::fromThrowable($e)->errorCode === 'reservation_changed';
}
$updated = $api->updateReservation($adminSession, $createdUuid, array(
    'expected_updated_at' => '2026-09-02T09:59:00',
    'notes' => 'Changed safely',
));
$cancelled = $api->cancelReservation(
    $adminSession,
    $createdUuid,
    (string)$updated['reservation']['updated_at']
);
$checks['API edit and cancel enforce optimistic version and return canonical state'] =
    $apiStaleRejected
    && $updated['reservation']['notes'] === 'Changed safely'
    && $cancelled['reservation']['status'] === 'cancelled';

// Receipt behavior: same actor/key/request always resolves to one server UUID.
$claim = new ReflectionMethod($api, 'claimIdempotency');
$first = $claim->invoke($api, 1, 1, 'create-test-1', hash('sha256', 'same request'));
$receiptUuid = (string)$first['reservation_uuid'];
$pdo->exec("INSERT INTO ipca_flight_schedule_slots
 (scheduler_record_id, organization_id, scheduled_date, scheduled_start_time, scheduled_end_time, aircraft_id, status)
 VALUES ('$receiptUuid', 1, '2026-08-23', '2026-08-23 10:00:00', '2026-08-23 11:00:00', 40, 'scheduled')");
$second = $claim->invoke($api, 1, 1, 'create-test-1', hash('sha256', 'same request'));
$idempotencyConflict = false;
try {
    $claim->invoke($api, 1, 1, 'create-test-1', hash('sha256', 'different request'));
} catch (SchedulerApiException $e) {
    $idempotencyConflict = $e->errorCode === 'idempotency_conflict';
}
$checks['idempotent retry returns the original server reservation UUID'] =
    empty($first['already_present'])
    && !empty($second['already_present'])
    && $second['reservation_uuid'] === $receiptUuid
    && $idempotencyConflict;
$staleFirst = $claim->invoke($api, 1, 1, 'create-stale-1', hash('sha256', 'stale request'));
$pdo->prepare(
    'UPDATE ipca_scheduler_api_mutations SET updated_at_utc = ?'
    . ' WHERE actor_user_id = ? AND idempotency_key = ?'
)->execute(array('2020-01-01 00:00:00.000', 1, 'create-stale-1'));
$staleRetry = $claim->invoke($api, 1, 1, 'create-stale-1', hash('sha256', 'stale request'));
$checks['stale interrupted receipt retries with the same reserved UUID'] =
    empty($staleRetry['already_present'])
    && $staleRetry['reservation_uuid'] === $staleFirst['reservation_uuid'];

$endpointRoot = __DIR__ . '/../public/api/scheduler/';
$endpointSources = array();
foreach (array('auth', 'bootstrap', 'schedule', 'reservations', 'resources', 'search', 'validation') as $endpoint) {
    $endpointSources[$endpoint] = file_get_contents($endpointRoot . $endpoint . '.php') ?: '';
}
$checks['scheduler API namespace exposes bounded thin human endpoints'] =
    str_contains($endpointSources['auth'], 'false')
    && str_contains($endpointSources['schedule'], 'scheduleRange')
    && str_contains($endpointSources['reservations'], 'idempotencyKey')
    && str_contains($endpointSources['validation'], 'validateReservation')
    && !str_contains($endpointSources['bootstrap'], 'listSlots');
$migration = file_get_contents(
    __DIR__ . '/../scripts/sql/2026_08_19_scheduler_mobile_api_foundation.sql'
) ?: '';
$checks['migration stores mutation receipts without a parallel schedule table'] =
    str_contains($migration, 'ipca_scheduler_api_mutations')
    && str_contains($migration, 'BIGINT UNSIGNED NOT NULL')
    && str_contains($migration, 'reservation_uuid')
    && !str_contains($migration, 'CREATE TABLE IF NOT EXISTS ipca_scheduler_reservations');

$errorMap = SchedulerApiException::fromThrowable(
    new RuntimeException('This reservation changed in another session. Reload the schedule and try again.')
);
$lockedMap = SchedulerApiException::fromThrowable(
    new RuntimeException('A claimed schedule slot cannot be cancelled.')
);
$checks['stable errors map stale and locked internal exceptions'] =
    $errorMap->errorCode === 'reservation_changed'
    && $errorMap->httpStatus === 409
    && $lockedMap->errorCode === 'reservation_locked'
    && $lockedMap->httpStatus === 409;
$preciseVersion = new ReflectionMethod($flight, 'assertExpectedUpdatedAt');
$millisecondStaleRejected = false;
try {
    $preciseVersion->invoke(
        $flight,
        array('updated_at' => '2026-08-19 12:00:00.123'),
        '2026-08-19T12:00:00.124'
    );
} catch (RuntimeException $e) {
    $millisecondStaleRejected = str_contains($e->getMessage(), 'changed in another session');
}
$checks['mobile optimistic concurrency distinguishes millisecond versions'] =
    $millisecondStaleRejected;

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
if ($failed !== array()) {
    fwrite(STDERR, "scheduler_api_foundation_check FAILED:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}
fwrite(STDOUT, 'scheduler_api_foundation_check OK (' . count($checks) . " checks)\n");
