<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/CvrOperationalIdentityService.php';
require_once __DIR__ . '/../src/CvrOperationalIdentityReadService.php';
require_once __DIR__ . '/../src/FlightScheduleService.php';

$root = dirname(__DIR__);
$readSource = file_get_contents($root . '/src/CvrOperationalIdentityReadService.php') ?: '';
$scheduleSource = file_get_contents($root . '/src/FlightScheduleService.php') ?: '';
$flightLogSource = file_get_contents($root . '/src/CvrFlightLogService.php') ?: '';
$intakeSource = file_get_contents($root . '/src/CvrDataIntakeReadService.php') ?: '';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec("CREATE TABLE system_policy_values (
  id INTEGER PRIMARY KEY,
  policy_key TEXT NOT NULL,
  value_text TEXT NOT NULL,
  is_active INTEGER NOT NULL DEFAULT 1
)");
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
$pdo->exec("CREATE TABLE ipca_operational_identity_backfill_quarantine (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  organization_id INTEGER NOT NULL,
  subject_type TEXT NOT NULL,
  subject_table TEXT NOT NULL,
  subject_pk TEXT NOT NULL,
  subject_natural_key TEXT NULL,
  reason_code TEXT NOT NULL,
  diagnostic_json TEXT NOT NULL,
  diagnostic_bytes INTEGER NOT NULL,
  status TEXT NOT NULL DEFAULT 'open',
  resolved_by_user_id INTEGER NULL,
  resolution_notes TEXT NULL,
  created_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at_utc TEXT NULL
)");
$pdo->exec("CREATE TABLE ipca_aircraft_devices (
  id INTEGER PRIMARY KEY,
  registration TEXT NOT NULL
)");
$pdo->exec("INSERT INTO ipca_aircraft_devices (id, registration) VALUES (1, 'N392EA')");
$pdo->exec("CREATE TABLE ipca_flight_schedule_slots (
  id INTEGER PRIMARY KEY,
  scheduler_record_id TEXT NOT NULL,
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
  claimed_at TEXT NULL,
  notes TEXT NOT NULL DEFAULT '',
  created_by INTEGER NULL,
  updated_by INTEGER NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE ipca_flight_schedule_crew (
  id INTEGER PRIMARY KEY,
  schedule_slot_id INTEGER NOT NULL,
  user_id INTEGER NULL,
  person_name_snapshot TEXT NOT NULL,
  crew_role TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE ipca_cvr_dispatches (
  id INTEGER PRIMARY KEY,
  dispatch_uuid TEXT NOT NULL,
  workflow_flight_record_uuid TEXT NOT NULL,
  scheduler_record_id TEXT NULL,
  current_version INTEGER NOT NULL DEFAULT 1,
  organization_id INTEGER NOT NULL,
  device_id INTEGER NOT NULL,
  aircraft_registration TEXT NOT NULL DEFAULT '',
  mission_code TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT '',
  source TEXT NOT NULL DEFAULT '',
  crew_json TEXT NULL,
  error_message TEXT NULL,
  last_received_at TEXT NULL
)");

$identity = new CvrOperationalIdentityService($pdo);
$reader = new CvrOperationalIdentityReadService($pdo, $identity);

$reservationUuid = '11111111-1111-4111-8111-111111111111';
$legUuid = '22222222-2222-4222-8222-222222222222';
$schedulerId = '33333333-3333-4333-8333-333333333333';
$dispatchUuid = '44444444-4444-4444-8444-444444444444';
$flightRecordUuid = '55555555-5555-4555-8555-555555555555';
$otherLegUuid = '66666666-6666-4666-8666-666666666666';
$otherReservationUuid = '77777777-7777-4777-8777-777777777777';

$identity->createReservation(array(
    'reservation_uuid' => $reservationUuid,
    'organization_id' => 7,
    'organization_timezone_iana' => 'America/Los_Angeles',
    'reservation_type' => 'flight_training',
    'activity_domain' => 'flight',
    'status' => 'scheduled',
    'source' => 'manual',
), false);
$identity->createFlightLeg(array(
    'leg_uuid' => $legUuid,
    'reservation_uuid' => $reservationUuid,
    'organization_id' => 7,
    'sequence_number' => 1,
    'origin_airport' => 'KSBA',
    'destination_airport' => 'KSMX',
    'organization_timezone_iana' => 'America/Los_Angeles',
    'status' => 'scheduled',
    'source' => 'manual',
), false);
$identity->createReservation(array(
    'reservation_uuid' => $otherReservationUuid,
    'organization_id' => 7,
    'organization_timezone_iana' => 'America/Los_Angeles',
    'reservation_type' => 'flight_training',
    'activity_domain' => 'flight',
    'status' => 'scheduled',
    'source' => 'manual',
), false);
$identity->createFlightLeg(array(
    'leg_uuid' => $otherLegUuid,
    'reservation_uuid' => $otherReservationUuid,
    'organization_id' => 7,
    'sequence_number' => 1,
    'origin_airport' => 'KSBA',
    'destination_airport' => 'KSBA',
    'organization_timezone_iana' => 'America/Los_Angeles',
    'status' => 'scheduled',
    'source' => 'manual',
), false);

$identity->createAlias(array(
    'organization_id' => 7,
    'source_system' => 'schedule',
    'alias_type' => 'scheduler_record_id',
    'alias_value' => $schedulerId,
    'target_type' => 'reservation',
    'reservation_uuid' => $reservationUuid,
    'confidence_state' => 'DETERMINISTIC_BACKFILL',
    'linkage_method' => 'deterministic_backfill',
), false);
$identity->createAlias(array(
    'organization_id' => 7,
    'source_system' => 'cvr_unit',
    'alias_type' => 'dispatch_uuid',
    'alias_value' => $dispatchUuid,
    'target_type' => 'leg',
    'leg_uuid' => $legUuid,
    'confidence_state' => 'VERIFIED',
    'linkage_method' => 'manual_verified',
), false);
$identity->createAlias(array(
    'organization_id' => 7,
    'source_system' => 'cvr_unit',
    'alias_type' => 'workflow_flight_record_uuid',
    'alias_value' => $flightRecordUuid,
    'target_type' => 'leg',
    'leg_uuid' => $legUuid,
    'confidence_state' => 'DETERMINISTIC_BACKFILL',
    'linkage_method' => 'deterministic_backfill',
), false);

$checks = array();

$checks['wiring is dual-read only'] =
    str_contains($scheduleSource, 'projectScheduleIdentity')
    && str_contains($flightLogSource, 'projectLegIdentity')
    && str_contains($intakeSource, 'projectLegIdentity')
    && str_contains($readSource, 'IDENTITY_SOURCE_CANONICAL_ALIAS')
    && !str_contains($scheduleSource, 'createReservation(')
    && !str_contains($flightLogSource, 'createAlias(')
    && !str_contains($intakeSource, 'quarantine(');

$flagOffSchedule = $reader->projectScheduleIdentity(7, $schedulerId, null);
$flagOffLeg = $reader->projectLegIdentity(7, $dispatchUuid, '1', $flightRecordUuid);
$checks['flag off returns null projection (omit fields)'] =
    $flagOffSchedule === null
    && $flagOffLeg === null
    && !$reader->isDualReadEnabled();

$pdo->exec("INSERT INTO system_policy_values (policy_key, value_text, is_active)
  VALUES ('operational_identity_dual_read_enabled', '1', 1)");
$reader = new CvrOperationalIdentityReadService($pdo, $identity);

$scheduleHit = $reader->projectScheduleIdentity(7, $schedulerId, null);
$checks['verified schedule alias returns reservation_uuid'] =
    is_array($scheduleHit)
    && $scheduleHit['reservation_uuid'] === $reservationUuid
    && $scheduleHit['leg_uuid'] === null
    && $scheduleHit['identity_source'] === 'canonical_alias';

$dispatchHit = $reader->projectLegIdentity(7, $dispatchUuid, null, null);
$checks['verified Dispatch alias returns leg_uuid'] =
    is_array($dispatchHit)
    && $dispatchHit['leg_uuid'] === $legUuid
    && $dispatchHit['reservation_uuid'] === $reservationUuid
    && $dispatchHit['identity_source'] === 'canonical_alias';

$frHit = $reader->projectLegIdentity(7, null, null, $flightRecordUuid);
$checks['verified workflow Flight Record alias returns leg_uuid'] =
    is_array($frHit)
    && $frHit['leg_uuid'] === $legUuid
    && $frHit['identity_source'] === 'canonical_alias';

$missing = $reader->projectScheduleIdentity(7, '99999999-9999-4999-8999-999999999999', null);
$checks['missing alias falls back to legacy'] =
    is_array($missing)
    && $missing['reservation_uuid'] === null
    && $missing['leg_uuid'] === null
    && $missing['identity_source'] === 'legacy_fallback';

// Conflicting aliases: same dispatch_uuid_version points to other leg while dispatch_uuid points to first.
$identity->createAlias(array(
    'organization_id' => 7,
    'source_system' => 'cvr_unit',
    'alias_type' => 'dispatch_uuid_version',
    'alias_value' => $dispatchUuid,
    'alias_version' => '9',
    'target_type' => 'leg',
    'leg_uuid' => $otherLegUuid,
    'confidence_state' => 'VERIFIED',
    'linkage_method' => 'manual_verified',
), false);
$aliasCountBefore = (int)$pdo->query('SELECT COUNT(*) FROM ipca_operational_identity_aliases')->fetchColumn();
$quarantineBefore = (int)$pdo->query('SELECT COUNT(*) FROM ipca_operational_identity_backfill_quarantine')->fetchColumn();
$reservationCountBefore = (int)$pdo->query('SELECT COUNT(*) FROM ipca_operational_reservations')->fetchColumn();
$conflict = $reader->projectLegIdentity(7, $dispatchUuid, '9', null);
$aliasCountAfter = (int)$pdo->query('SELECT COUNT(*) FROM ipca_operational_identity_aliases')->fetchColumn();
$quarantineAfter = (int)$pdo->query('SELECT COUNT(*) FROM ipca_operational_identity_backfill_quarantine')->fetchColumn();
$reservationCountAfter = (int)$pdo->query('SELECT COUNT(*) FROM ipca_operational_reservations')->fetchColumn();
$checks['conflicting aliases fall back without mutation'] =
    is_array($conflict)
    && $conflict['identity_source'] === 'canonical_conflict'
    && $conflict['leg_uuid'] === null
    && $conflict['reservation_uuid'] === null
    && $aliasCountBefore === $aliasCountAfter
    && $quarantineBefore === $quarantineAfter
    && $reservationCountBefore === $reservationCountAfter;

$wrongOrg = $reader->projectScheduleIdentity(99, $schedulerId, null);
$checks['wrong-organization alias is never returned'] =
    is_array($wrongOrg)
    && $wrongOrg['reservation_uuid'] === null
    && $wrongOrg['identity_source'] === 'legacy_fallback';

// Canonical failure must not throw: temporarily remove aliases table.
$pdo->exec('ALTER TABLE ipca_operational_identity_aliases RENAME TO ipca_operational_identity_aliases_bak');
$failingReader = new CvrOperationalIdentityReadService($pdo, $identity);
$unavailable = null;
$threw = false;
try {
    $unavailable = $failingReader->projectScheduleIdentity(7, $schedulerId, null);
} catch (Throwable) {
    $threw = true;
}
$pdo->exec('ALTER TABLE ipca_operational_identity_aliases_bak RENAME TO ipca_operational_identity_aliases');
$checks['canonical database/read failure does not fail legacy request'] =
    !$threw
    && is_array($unavailable)
    && $unavailable['identity_source'] === 'canonical_unavailable'
    && $unavailable['reservation_uuid'] === null;

$checks['no read path creates or updates canonical rows'] =
    !preg_match('/\b(INSERT|UPDATE|DELETE)\b/i', $readSource)
    && str_contains($readSource, 'Never creates or mutates')
    && $aliasCountBefore === $aliasCountAfter;

// Schedule service flag-off: no identity fields.
$pdo->exec("UPDATE system_policy_values SET value_text = '0' WHERE policy_key = 'operational_identity_dual_read_enabled'");
$pdo->exec("INSERT INTO ipca_flight_schedule_slots
  (id, scheduler_record_id, organization_id, reservation_type, scheduled_date, scheduled_start_time, scheduled_end_time,
   aircraft_id, planned_departure_airport, planned_destination_airport, status, notes)
  VALUES
  (1, '{$schedulerId}', 7, 'flight_training', '2026-08-05', '2026-08-05 10:00:00', '2026-08-05 12:00:00',
   1, 'KSBA', 'KSMX', 'scheduled', '')");

// FlightScheduleService::listSlots calls reconcile which may fail on sqlite missing tables — call payload via reflection.
$schedule = new FlightScheduleService($pdo);
$ref = new ReflectionClass($schedule);
$method = $ref->getMethod('payload');
$row = array(
    'id' => 1,
    'scheduler_record_id' => $schedulerId,
    'organization_id' => 7,
    'reservation_type' => 'flight_training',
    'scheduled_date' => '2026-08-05',
    'scheduled_start_time' => '2026-08-05 10:00:00',
    'scheduled_end_time' => '2026-08-05 12:00:00',
    'aircraft_id' => 1,
    'aircraft_registration' => 'N392EA',
    'mission_id' => null,
    'resolved_mission_code' => '',
    'mission_name' => '',
    'cohort_id' => null,
    'cohort_name' => '',
    'planned_departure_airport' => 'KSBA',
    'planned_destination_airport' => 'KSMX',
    'status' => 'scheduled',
    'claimed_dispatch_uuid' => null,
    'notes' => '',
    'updated_at' => '2026-08-05 10:00:00',
    'dispatch_id' => null,
    'has_flight_data' => 0,
    'has_closure' => 0,
    'has_audio' => 0,
    'has_completed_briefing' => 0,
);
$offPayload = $method->invoke($schedule, $row, array());
$checks['flag off preserves exact legacy schedule fields'] =
    !array_key_exists('reservation_uuid', $offPayload)
    && !array_key_exists('leg_uuid', $offPayload)
    && !array_key_exists('identity_source', $offPayload)
    && ($offPayload['scheduler_record_id'] ?? null) === $schedulerId;

$pdo->exec("UPDATE system_policy_values SET value_text = '1' WHERE policy_key = 'operational_identity_dual_read_enabled'");
$scheduleOn = new FlightScheduleService($pdo);
$refOn = new ReflectionClass($scheduleOn);
$methodOn = $refOn->getMethod('payload');
$onPayload = $methodOn->invoke($scheduleOn, $row, array());
$checks['schedule payload exposes reservation_uuid when flag on'] =
    ($onPayload['reservation_uuid'] ?? null) === $reservationUuid
    && ($onPayload['identity_source'] ?? null) === 'canonical_alias'
    && ($onPayload['scheduler_record_id'] ?? null) === $schedulerId;

// Intake dual-read is covered via projectLegIdentity + static wiring (dispatchRows uses MySQL information_schema).
$intakeLegacy = array(
    'dispatch_uuid' => $dispatchUuid,
    'workflow_flight_record_uuid' => $flightRecordUuid,
    'dispatch_version' => '1',
    'organization_id' => 7,
);
$intakeProjection = $reader->projectLegIdentity(7, $dispatchUuid, '1', $flightRecordUuid);
$intakeMerged = $reader->mergeProjection($intakeLegacy, $intakeProjection);
$checks['intake dispatch rows expose leg_uuid when flag on'] =
    str_contains($intakeSource, 'projectLegIdentity')
    && str_contains($intakeSource, 'mergeProjection')
    && ($intakeMerged['leg_uuid'] ?? null) === $legUuid
    && ($intakeMerged['reservation_uuid'] ?? null) === $reservationUuid
    && ($intakeMerged['identity_source'] ?? null) === 'canonical_alias'
    && ($intakeMerged['dispatch_uuid'] ?? null) === $dispatchUuid
    && ($intakeMerged['workflow_flight_record_uuid'] ?? null) === $flightRecordUuid;

$checks['mergeProjection omits fields when null'] =
    !array_key_exists('reservation_uuid', $reader->mergeProjection(array('a' => 1), null));

$failed = array();
foreach ($checks as $name => $ok) {
    if (!$ok) {
        $failed[] = $name;
    }
}

if ($failed === array()) {
    echo 'cvr_phase2b_dual_read_contract_check: PASS (' . count($checks) . " checks)\n";
    exit(0);
}

echo "cvr_phase2b_dual_read_contract_check: FAIL\n";
foreach ($failed as $name) {
    echo '- ' . $name . "\n";
}
exit(1);
