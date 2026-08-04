<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/AuditEventService.php';
require_once __DIR__ . '/../src/CvrOperationalIdentityService.php';
require_once __DIR__ . '/../src/FlightScheduleService.php';

$root = dirname(__DIR__);
$scheduleSource = file_get_contents($root . '/src/FlightScheduleService.php') ?: '';
$identitySource = file_get_contents($root . '/src/CvrOperationalIdentityService.php') ?: '';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec("CREATE TABLE system_policy_values (
  id INTEGER PRIMARY KEY,
  policy_key TEXT NOT NULL,
  value_text TEXT NOT NULL,
  is_active INTEGER NOT NULL DEFAULT 1
)");
$pdo->exec("INSERT INTO system_policy_values (policy_key, value_text, is_active) VALUES
  ('operational_identity_canonical_write_enabled', '1', 1)");
$pdo->exec("CREATE TABLE ipca_operational_reservations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  reservation_uuid TEXT NOT NULL UNIQUE,
  organization_id INTEGER NOT NULL,
  organization_timezone_iana TEXT NOT NULL,
  reservation_type TEXT NOT NULL,
  activity_domain TEXT NOT NULL,
  status TEXT NOT NULL,
  source TEXT NOT NULL,
  adoption_source_system TEXT NULL,
  adoption_provenance_json TEXT NULL,
  created_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE ipca_operational_reservation_legs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  leg_uuid TEXT NOT NULL UNIQUE,
  reservation_uuid TEXT NOT NULL,
  organization_id INTEGER NOT NULL,
  sequence_number INTEGER NOT NULL,
  origin_airport TEXT NOT NULL DEFAULT '',
  destination_airport TEXT NOT NULL DEFAULT '',
  planned_start_at_utc TEXT NULL,
  planned_end_at_utc TEXT NULL,
  planned_start_local TEXT NULL,
  planned_end_local TEXT NULL,
  organization_timezone_iana TEXT NOT NULL,
  planned_start_utc_offset_minutes INTEGER NULL,
  planned_end_utc_offset_minutes INTEGER NULL,
  planned_start_dst_resolution TEXT NULL,
  planned_end_dst_resolution TEXT NULL,
  status TEXT NOT NULL,
  source TEXT NOT NULL,
  created_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (reservation_uuid, sequence_number)
)");
$pdo->exec("CREATE TABLE ipca_operational_identity_aliases (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  organization_id INTEGER NOT NULL,
  source_system TEXT NOT NULL,
  alias_type TEXT NOT NULL,
  alias_value TEXT NOT NULL,
  alias_version TEXT NULL,
  alias_version_key TEXT NOT NULL DEFAULT '',
  target_type TEXT NOT NULL,
  reservation_uuid TEXT NULL,
  leg_uuid TEXT NULL,
  confidence_state TEXT NOT NULL,
  linkage_method TEXT NOT NULL,
  created_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (organization_id, source_system, alias_type, alias_value, alias_version_key)
)");
$pdo->exec("CREATE TABLE ipca_missions (
  id INTEGER PRIMARY KEY,
  organization_id INTEGER NOT NULL,
  code TEXT NOT NULL
)");
$pdo->exec("INSERT INTO ipca_missions (id, organization_id, code) VALUES (10, 7, '2.1.5'), (11, 8, '3.1.1')");
$pdo->exec("CREATE TABLE ipca_aircraft_devices (id INTEGER PRIMARY KEY, registration TEXT NOT NULL)");
$pdo->exec("INSERT INTO ipca_aircraft_devices (id, registration) VALUES (1, 'N392EA')");
$pdo->exec("CREATE TABLE ipca_flight_schedule_slots (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  scheduler_record_id TEXT NOT NULL UNIQUE,
  organization_id INTEGER NOT NULL,
  reservation_type TEXT NOT NULL DEFAULT 'flight_training',
  scheduled_date TEXT NOT NULL,
  scheduled_start_time TEXT NOT NULL,
  scheduled_end_time TEXT NOT NULL,
  aircraft_id INTEGER NOT NULL,
  mission_id INTEGER NULL,
  cohort_id INTEGER NULL,
  mission_code TEXT NOT NULL DEFAULT '',
  planned_departure_airport TEXT NOT NULL DEFAULT '',
  planned_destination_airport TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'scheduled',
  claimed_dispatch_uuid TEXT NULL,
  notes TEXT NOT NULL DEFAULT '',
  created_by INTEGER NULL,
  updated_by INTEGER NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE ipca_flight_schedule_crew (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  schedule_slot_id INTEGER NOT NULL,
  user_id INTEGER NULL,
  person_name_snapshot TEXT NOT NULL,
  crew_role TEXT NOT NULL
)");

$checks = array();
$identity = new CvrOperationalIdentityService($pdo);
$schedule = new FlightScheduleService($pdo);
$orgMethod = (new ReflectionClass($schedule))->getMethod('requireOrganizationIdForCreate');

$checks['posted organization_id is not authoritative in source'] =
    str_contains($scheduleSource, 'resolveTrustedOrganizationId')
    && str_contains($scheduleSource, 'optional consistency assertion')
    && str_contains($scheduleSource, 'Organization context does not match this schedule reservation.')
    && str_contains($identitySource, 'assertReusableOnlineReservationMatches')
    && str_contains($identitySource, 'assertReusableOnlineLegMatches')
    && str_contains($scheduleSource, 'schedule_create_technical_failure')
    && str_contains($scheduleSource, 'isSafeScheduleUserError');

$spoofRejected = false;
try {
    $orgMethod->invoke($schedule, array('organization_id' => 99), 10);
} catch (RuntimeException $e) {
    $spoofRejected = str_contains($e->getMessage(), 'Organization context does not match')
        && !str_contains(strtolower($e->getMessage()), 'sql');
}
$checks['spoofed POST organization_id cannot create in another organization'] =
    $spoofRejected
    && $orgMethod->invoke($schedule, array(), 10) === 7;

$checks['matching optional POST organization_id succeeds'] =
    $orgMethod->invoke($schedule, array('organization_id' => 7), 10) === 7;

$missingTrusted = false;
try {
    $orgMethod->invoke($schedule, array('organization_id' => 7), null);
} catch (RuntimeException $e) {
    // Posted org alone is insufficient when no trusted context exists.
    $missingTrusted = str_contains($e->getMessage(), 'Organization context is required')
        || str_contains($e->getMessage(), 'Organization context does not match');
}
$checks['missing trusted organization context fails safely'] = $missingTrusted;

// Cross-organization canonical reuse remains blocked.
$sharedUuid = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$identity->createReservation(array(
    'reservation_uuid' => $sharedUuid,
    'organization_id' => 99,
    'organization_timezone_iana' => 'America/Los_Angeles',
    'reservation_type' => 'flight_training',
    'activity_domain' => 'flight',
    'status' => 'scheduled',
    'source' => 'manual',
), false);
$crossOrgBlocked = false;
$safeCrossOrg = false;
try {
    $identity->createOnlineScheduleReservationIdentity(array(
        'organization_id' => 7,
        'scheduler_record_id' => $sharedUuid,
        'schedule_slot_id' => 1,
        'reservation_type' => 'flight_training',
        'status' => 'scheduled',
        'planned_departure_airport' => 'KSBA',
        'planned_destination_airport' => 'KSMX',
        'scheduled_start_time' => '2026-08-07 10:00:00',
        'scheduled_end_time' => '2026-08-07 12:00:00',
        'organization_timezone_iana' => 'America/Los_Angeles',
    ));
} catch (RuntimeException $e) {
    $crossOrgBlocked = true;
    $safeCrossOrg = !str_contains($e->getMessage(), 'SQLSTATE')
        && !str_contains(strtolower($e->getMessage()), 'pdo');
}
$stillOrg99 = $identity->findReservationByUuid($sharedUuid);
$checks['cross-organization canonical reuse remains blocked'] =
    $crossOrgBlocked
    && $safeCrossOrg
    && is_array($stillOrg99)
    && (int)$stillOrg99['organization_id'] === 99
    && (int)$pdo->query('SELECT COUNT(*) FROM ipca_operational_reservations')->fetchColumn() === 1;

// Immutable reservation conflict: same UUID/org but different type.
$typeConflictUuid = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
$identity->createReservation(array(
    'reservation_uuid' => $typeConflictUuid,
    'organization_id' => 7,
    'organization_timezone_iana' => 'America/Los_Angeles',
    'reservation_type' => 'briefing',
    'activity_domain' => 'ground',
    'status' => 'scheduled',
    'source' => 'server_create',
    'adoption_source_system' => 'schedule',
), false);
$typeConflict = false;
$reservationCountBefore = (int)$pdo->query('SELECT COUNT(*) FROM ipca_operational_reservations')->fetchColumn();
try {
    $identity->createOnlineScheduleReservationIdentity(array(
        'organization_id' => 7,
        'scheduler_record_id' => $typeConflictUuid,
        'schedule_slot_id' => 2,
        'reservation_type' => 'flight_training',
        'status' => 'scheduled',
        'planned_departure_airport' => 'KSBA',
        'planned_destination_airport' => 'KSMX',
        'scheduled_start_time' => '2026-08-07 10:00:00',
        'scheduled_end_time' => '2026-08-07 12:00:00',
        'organization_timezone_iana' => 'America/Los_Angeles',
    ));
} catch (RuntimeException $e) {
    $typeConflict = str_contains($e->getMessage(), 'identity conflict')
        || str_contains($e->getMessage(), 'another organization');
}
$unchanged = $identity->findReservationByUuid($typeConflictUuid);
$checks['materially different reservation payload is immutable conflict'] =
    $typeConflict
    && is_array($unchanged)
    && (string)$unchanged['reservation_type'] === 'briefing'
    && (string)$unchanged['activity_domain'] === 'ground'
    && (int)$pdo->query('SELECT COUNT(*) FROM ipca_operational_reservations')->fetchColumn() === $reservationCountBefore
    && (int)$pdo->query('SELECT COUNT(*) FROM ipca_operational_reservation_legs')->fetchColumn() === 0;

// Create a valid online flight, then conflict on materially different leg times.
$flightUuid = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
$first = $identity->createOnlineScheduleReservationIdentity(array(
    'organization_id' => 7,
    'scheduler_record_id' => $flightUuid,
    'schedule_slot_id' => 3,
    'reservation_type' => 'flight_training',
    'status' => 'scheduled',
    'planned_departure_airport' => 'KSBA',
    'planned_destination_airport' => 'KSMX',
    'scheduled_start_time' => '2026-08-07 10:00:00',
    'scheduled_end_time' => '2026-08-07 12:00:00',
    'organization_timezone_iana' => 'America/Los_Angeles',
));
$legBefore = $identity->listLegsForReservation($flightUuid);
$legCountBefore = count($legBefore);
$legConflict = false;
try {
    $identity->createOnlineScheduleReservationIdentity(array(
        'organization_id' => 7,
        'scheduler_record_id' => $flightUuid,
        'schedule_slot_id' => 3,
        'reservation_type' => 'flight_training',
        'status' => 'scheduled',
        'planned_departure_airport' => 'KSBA',
        'planned_destination_airport' => 'KSMX',
        'scheduled_start_time' => '2026-08-07 10:30:00',
        'scheduled_end_time' => '2026-08-07 12:30:00',
        'organization_timezone_iana' => 'America/Los_Angeles',
    ));
} catch (RuntimeException $e) {
    $legConflict = str_contains($e->getMessage(), 'identity conflict');
}
$legAfter = $identity->listLegsForReservation($flightUuid);
$checks['materially different leg payload is immutable conflict and does not overwrite'] =
    is_array($first)
    && $legConflict
    && count($legAfter) === $legCountBefore
    && (string)$legAfter[0]['leg_uuid'] === (string)$legBefore[0]['leg_uuid']
    && (string)$legAfter[0]['planned_start_local'] === (string)$legBefore[0]['planned_start_local'];

// Identical retry still reuses.
$identical = $identity->createOnlineScheduleReservationIdentity(array(
    'organization_id' => 7,
    'scheduler_record_id' => $flightUuid,
    'schedule_slot_id' => 3,
    'reservation_type' => 'flight_training',
    'status' => 'scheduled',
    'planned_departure_airport' => 'KSBA',
    'planned_destination_airport' => 'KSMX',
    'scheduled_start_time' => '2026-08-07 10:00:00',
    'scheduled_end_time' => '2026-08-07 12:00:00',
    'organization_timezone_iana' => 'America/Los_Angeles',
));
$checks['identical retry still reuses reservation and leg'] =
    $identical['reservation_uuid'] === $flightUuid
    && $identical['leg_uuid'] === (string)$legBefore[0]['leg_uuid']
    && count($identity->listLegsForReservation($flightUuid)) === 1;

// Transaction rollback + sanitized technical error via mirrored create path.
$failUuid = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
$identity->createReservation(array(
    'reservation_uuid' => $failUuid,
    'organization_id' => 99,
    'organization_timezone_iana' => 'America/Los_Angeles',
    'reservation_type' => 'flight_training',
    'activity_domain' => 'flight',
    'status' => 'scheduled',
    'source' => 'manual',
), false);
$slotsBefore = (int)$pdo->query('SELECT COUNT(*) FROM ipca_flight_schedule_slots')->fetchColumn();
$safeRollback = false;
$noSqlLeak = false;
$pdo->beginTransaction();
try {
    $trustedOrg = $orgMethod->invoke($schedule, array(), 10);
    $pdo->prepare(
        'INSERT INTO ipca_flight_schedule_slots
         (scheduler_record_id, organization_id, reservation_type, scheduled_date, scheduled_start_time, scheduled_end_time,
          aircraft_id, planned_departure_airport, planned_destination_airport, status, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute(array(
        $failUuid, $trustedOrg, 'flight_training', '2026-08-08',
        '2026-08-08 10:00:00', '2026-08-08 12:00:00', 1, 'KSBA', 'KSMX', 'scheduled', '',
    ));
    $slotId = (int)$pdo->lastInsertId();
    try {
        $identity->createOnlineScheduleReservationIdentity(array(
            'organization_id' => $trustedOrg,
            'scheduler_record_id' => $failUuid,
            'schedule_slot_id' => $slotId,
            'reservation_type' => 'flight_training',
            'status' => 'scheduled',
            'planned_departure_airport' => 'KSBA',
            'planned_destination_airport' => 'KSMX',
            'scheduled_start_time' => '2026-08-08 10:00:00',
            'scheduled_end_time' => '2026-08-08 12:00:00',
            'organization_timezone_iana' => 'America/Los_Angeles',
        ));
    } catch (Throwable $canonicalError) {
        throw new RuntimeException(
            'Unable to create the schedule reservation because operational identity could not be recorded. Please try again.',
            0,
            $canonicalError
        );
    }
    $pdo->commit();
} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $safeRollback = str_contains($e->getMessage(), 'operational identity could not be recorded');
    $noSqlLeak = !str_contains($e->getMessage(), 'SQLSTATE')
        && !str_contains($e->getMessage(), 'ipca_operational')
        && !str_contains(strtolower($e->getMessage()), 'stack');
}
$checks['canonical conflict rolls back legacy slot with safe error'] =
    $safeRollback
    && $noSqlLeak
    && (int)$pdo->query('SELECT COUNT(*) FROM ipca_flight_schedule_slots')->fetchColumn() === $slotsBefore;

$failed = array();
foreach ($checks as $name => $ok) {
    if (!$ok) {
        $failed[] = $name;
    }
}

if ($failed === array()) {
    fwrite(STDOUT, "PASS cvr_phase2c_integrity_contract_check (" . count($checks) . " checks)\n");
    exit(0);
}

fwrite(STDERR, "FAIL cvr_phase2c_integrity_contract_check\n");
foreach ($failed as $name) {
    fwrite(STDERR, " - {$name}\n");
}
exit(1);
