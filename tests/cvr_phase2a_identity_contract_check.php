<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/CvrOperationalIdentityService.php';
require_once __DIR__ . '/../src/CvrOperationalIdentityReadService.php';
require_once __DIR__ . '/../src/CvrOperationalIdentityBackfillService.php';

$root = dirname(__DIR__);
$migration = file_get_contents($root . '/scripts/sql/2026_08_04_cvr_operational_reservation_leg_identity.sql') ?: '';
$identitySource = file_get_contents($root . '/src/CvrOperationalIdentityService.php') ?: '';
$readSource = file_get_contents($root . '/src/CvrOperationalIdentityReadService.php') ?: '';
$backfillSource = file_get_contents($root . '/src/CvrOperationalIdentityBackfillService.php') ?: '';
$cliSource = file_get_contents($root . '/scripts/backfill_operational_reservation_leg_identity.php') ?: '';
$docs = file_get_contents($root . '/docs/cvr_phase2a_operational_identity.md') ?: '';

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
  resolved_at_utc TEXT NULL,
  UNIQUE (organization_id, subject_type, subject_table, subject_pk, reason_code)
)");
$pdo->exec("CREATE TABLE ipca_flight_schedule_slots (
  id INTEGER PRIMARY KEY,
  scheduler_record_id TEXT NOT NULL,
  organization_id INTEGER NOT NULL,
  reservation_type TEXT NOT NULL,
  status TEXT NOT NULL,
  scheduled_start_time TEXT NOT NULL,
  scheduled_end_time TEXT NOT NULL,
  planned_departure_airport TEXT NOT NULL DEFAULT '',
  planned_destination_airport TEXT NOT NULL DEFAULT '',
  claimed_dispatch_uuid TEXT NULL
)");
$pdo->exec("CREATE TABLE ipca_cvr_dispatches (
  id INTEGER PRIMARY KEY,
  dispatch_uuid TEXT NOT NULL,
  workflow_flight_record_uuid TEXT NOT NULL,
  scheduler_record_id TEXT NULL,
  current_version INTEGER NOT NULL DEFAULT 1,
  organization_id INTEGER NOT NULL,
  device_id INTEGER NOT NULL
)");

$identity = new CvrOperationalIdentityService($pdo);
$reader = new CvrOperationalIdentityReadService($pdo, $identity);
$backfill = new CvrOperationalIdentityBackfillService($pdo, $identity);

$checks = array();

$checks['migration is additive and idempotent'] =
    str_contains($migration, 'CREATE TABLE IF NOT EXISTS ipca_operational_reservations')
    && str_contains($migration, 'CREATE TABLE IF NOT EXISTS ipca_operational_reservation_legs')
    && str_contains($migration, 'CREATE TABLE IF NOT EXISTS ipca_operational_identity_aliases')
    && str_contains($migration, 'CREATE TABLE IF NOT EXISTS ipca_operational_identity_backfill_quarantine')
    && !preg_match('/\bDROP\s+TABLE\b/i', $migration)
    && !preg_match('/\bTRUNCATE\b/i', $migration)
    && !preg_match('/\bDELETE\s+FROM\b/i', $migration);

$checks['organization_id never defaults to 1'] =
    substr_count($migration, 'organization_id BIGINT UNSIGNED NOT NULL') >= 4
    && !preg_match('/organization_id BIGINT UNSIGNED NOT NULL DEFAULT 1/', $migration)
    && str_contains($identitySource, 'organization_id is required and must never default');

$checks['no aircraft_id on canonical reservations'] =
    !preg_match('/ipca_operational_reservations[\s\S]{0,1200}aircraft_id/', $migration)
    && !str_contains($identitySource, "'aircraft_id'");

$checks['activity_domain gates legs'] =
    str_contains($migration, "activity_domain VARCHAR(32) NOT NULL")
    && str_contains($identitySource, "activity_domain = flight")
    && CvrOperationalIdentityService::defaultActivityDomainForReservationType('flight_training') === 'flight'
    && CvrOperationalIdentityService::defaultActivityDomainForReservationType('simulator_training') === 'simulator'
    && CvrOperationalIdentityService::defaultActivityDomainForReservationType('practical_exam') === null;

$checks['coarse reservation and leg lifecycles'] =
    str_contains($migration, "'scheduled','active','completed','cancelled'")
    && str_contains($migration, "'scheduled','dispatched','active','checked_in','cancelled'")
    && CvrOperationalIdentityService::deriveReservationStatusFromLegs(array('checked_in')) === 'completed'
    && CvrOperationalIdentityService::deriveReservationStatusFromLegs(array('dispatched')) === 'active'
    && CvrOperationalIdentityService::deriveReservationStatusFromLegs(array('cancelled', 'cancelled')) === 'cancelled'
    && CvrOperationalIdentityService::deriveReservationStatusFromLegs(array('scheduled', 'checked_in')) === 'active';

$checks['no local wall-clock order CHECK; UTC order present'] =
    !str_contains($migration, 'planned_end_local > planned_start_local')
    && str_contains($migration, 'planned_end_at_utc > planned_start_at_utc')
    && str_contains($migration, 'planned_start_local DATETIME(3)')
    && str_contains($migration, 'planned_start_dst_resolution')
    && str_contains($migration, 'planned_end_dst_resolution');

$checks['alias version null-safe and linkage_method controlled'] =
    str_contains($migration, 'alias_version VARCHAR(32) NULL')
    && str_contains($migration, 'alias_version_key VARCHAR(32) NOT NULL')
    && str_contains($migration, 'linkage_method')
    && str_contains($migration, 'DETERMINISTIC_BACKFILL')
    && !str_contains($migration, 'LEGACY_UNIQUE')
    && str_contains($identitySource, 'Do not encode version into alias_value');

$checks['quarantine resolution audit and bounded payload'] =
    str_contains($migration, 'resolved_by_user_id')
    && str_contains($migration, 'resolution_notes')
    && str_contains($migration, 'updated_at_utc')
    && str_contains($migration, 'diagnostic_bytes <= 4096')
    && str_contains($identitySource, 'MAX_DIAGNOSTIC_BYTES = 4096');

$checks['precise feature flags default off'] =
    str_contains($migration, 'operational_identity_backfill_enabled')
    && str_contains($migration, 'operational_identity_dual_read_enabled')
    && str_contains($migration, 'operational_identity_canonical_write_enabled')
    && str_contains($migration, "default_value_text, allowed_values_json")
    && str_contains($cliSource, '--apply')
    && str_contains($cliSource, 'Default mode is dry-run');

$checks['uuid normalize lowercase'] =
    CvrOperationalIdentityService::normalizeUuid('AAAAAAAA-AAAA-4AAA-8AAA-AAAAAAAAAAAA') === 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'
    && str_contains($migration, 'reservation_uuid = LOWER(reservation_uuid)');

$failedUuid = false;
try {
    CvrOperationalIdentityService::normalizeUuid('not-a-uuid');
} catch (InvalidArgumentException) {
    $failedUuid = true;
}
$checks['invalid uuid rejected'] = $failedUuid;

// Runtime: flags default off
$checks['flags default off'] = !$identity->isFlagEnabled(CvrOperationalIdentityService::FLAG_CANONICAL_WRITE)
    && !$identity->isFlagEnabled(CvrOperationalIdentityService::FLAG_DUAL_READ)
    && !$identity->isFlagEnabled(CvrOperationalIdentityService::FLAG_BACKFILL);

$writeBlocked = false;
try {
    $identity->createReservation(array(
        'organization_id' => 7,
        'organization_timezone_iana' => 'America/Los_Angeles',
        'reservation_type' => 'flight_training',
        'activity_domain' => 'flight',
        'status' => 'scheduled',
        'source' => 'server_create',
    ));
} catch (RuntimeException) {
    $writeBlocked = true;
}
$checks['canonical write blocked when flag off'] = $writeBlocked;

$reservation = $identity->createReservation(array(
    'organization_id' => 7,
    'organization_timezone_iana' => 'America/Los_Angeles',
    'reservation_type' => 'flight_training',
    'activity_domain' => 'flight',
    'status' => 'scheduled',
    'source' => 'manual',
    'reservation_uuid' => '11111111-1111-4111-8111-111111111111',
), false);
$checks['reservation create with explicit org'] =
    (int)$reservation['organization_id'] === 7
    && (string)$reservation['activity_domain'] === 'flight'
    && !array_key_exists('aircraft_id', $reservation);

$ground = $identity->createReservation(array(
    'organization_id' => 7,
    'organization_timezone_iana' => 'America/Los_Angeles',
    'reservation_type' => 'briefing',
    'activity_domain' => 'ground',
    'status' => 'scheduled',
    'source' => 'manual',
    'reservation_uuid' => '22222222-2222-4222-8222-222222222222',
), false);
$groundLegBlocked = false;
try {
    $identity->createFlightLeg(array(
        'reservation_uuid' => $ground['reservation_uuid'],
        'organization_id' => 7,
        'sequence_number' => 1,
        'origin_airport' => 'KSBA',
        'destination_airport' => 'KSBA',
        'status' => 'scheduled',
        'source' => 'manual',
    ), false);
} catch (InvalidArgumentException) {
    $groundLegBlocked = true;
}
$checks['non-flight domain cannot create legs'] = $groundLegBlocked;

$leg = $identity->createFlightLeg(array(
    'reservation_uuid' => $reservation['reservation_uuid'],
    'organization_id' => 7,
    'sequence_number' => 1,
    'origin_airport' => 'KSBA',
    'destination_airport' => 'KSMX',
    'planned_start_local' => '2026-08-04 09:00:00',
    'planned_end_local' => '2026-08-04 11:00:00',
    'organization_timezone_iana' => 'America/Los_Angeles',
    'status' => 'scheduled',
    'source' => 'manual',
    'leg_uuid' => '33333333-3333-4333-8333-333333333333',
), false);
$checks['flight domain creates leg with typed times'] =
    (string)$leg['leg_uuid'] === '33333333-3333-4333-8333-333333333333'
    && (string)$leg['planned_start_dst_resolution'] === 'unambiguous'
    && $leg['planned_start_at_utc'] !== null;

$alias = $identity->createAlias(array(
    'organization_id' => 7,
    'source_system' => 'schedule',
    'alias_type' => 'scheduler_record_id',
    'alias_value' => '11111111-1111-4111-8111-111111111111',
    'alias_version' => null,
    'target_type' => 'reservation',
    'reservation_uuid' => $reservation['reservation_uuid'],
    'confidence_state' => 'DETERMINISTIC_BACKFILL',
    'linkage_method' => 'deterministic_backfill',
), false);
$versioned = $identity->createAlias(array(
    'organization_id' => 7,
    'source_system' => 'cvr_unit',
    'alias_type' => 'dispatch_uuid_version',
    'alias_value' => '44444444-4444-4444-8444-444444444444',
    'alias_version' => '2',
    'target_type' => 'leg',
    'leg_uuid' => $leg['leg_uuid'],
    'confidence_state' => 'VERIFIED',
    'linkage_method' => 'manual_verified',
), false);
$checks['aliases target exactly one entity and version is separate'] =
    (string)$alias['target_type'] === 'reservation'
    && $alias['leg_uuid'] === null
    && (string)$versioned['alias_version'] === '2'
    && (string)$versioned['alias_version_key'] === '2'
    && (string)$versioned['leg_uuid'] === $leg['leg_uuid']
    && $versioned['reservation_uuid'] === null;

$bothTargetBlocked = false;
try {
    $identity->createAlias(array(
        'organization_id' => 7,
        'source_system' => 'cvr_unit',
        'alias_type' => 'dispatch_uuid',
        'alias_value' => '55555555-5555-4555-8555-555555555555',
        'target_type' => 'leg',
        'leg_uuid' => $leg['leg_uuid'],
        'reservation_uuid' => $reservation['reservation_uuid'],
        'confidence_state' => 'VERIFIED',
        'linkage_method' => 'manual_verified',
    ), false);
} catch (InvalidArgumentException) {
    $bothTargetBlocked = true;
}
$checks['alias cannot target both reservation and leg'] = $bothTargetBlocked;

$deferredBlocked = false;
try {
    $identity->createAlias(array(
        'organization_id' => 7,
        'source_system' => 'garmin',
        'alias_type' => 'session_uuid',
        'alias_value' => '66666666-6666-4666-8666-666666666666',
        'target_type' => 'leg',
        'leg_uuid' => $leg['leg_uuid'],
        'confidence_state' => 'VERIFIED',
        'linkage_method' => 'manual_verified',
    ), false);
} catch (InvalidArgumentException) {
    $deferredBlocked = true;
}
$checks['deferred multi-leg alias types rejected'] = $deferredBlocked;

$q = $identity->quarantine(
    7,
    'schedule_slot',
    'ipca_flight_schedule_slots',
    '99',
    'activity_domain_requires_explicit_classification',
    array(
        'slot_id' => 99,
        'reservation_type' => 'practical_exam',
        'audio' => 'should-be-stripped',
        'transcript_body' => 'should-be-stripped',
    ),
    'scheduler-x'
);
$diag = json_decode((string)$q['diagnostic_json'], true);
$checks['quarantine org-scoped bounded and sanitized'] =
    (int)$q['organization_id'] === 7
    && (int)$q['diagnostic_bytes'] <= 4096
    && is_array($diag)
    && !array_key_exists('audio', $diag)
    && !array_key_exists('transcript_body', $diag)
    && ($diag['reservation_type'] ?? null) === 'practical_exam'
    && str_contains($migration, 'resolved_by_user_id');

$dualOff = $reader->preferReservationForSchedulerRecordId(7, '11111111-1111-4111-8111-111111111111');
$checks['dual-read falls back when flag off'] =
    $dualOff['resolved'] === false
    && $dualOff['reservation_uuid'] === null;

$pdo->exec("INSERT INTO system_policy_values (policy_key, value_text, is_active) VALUES
  ('operational_identity_dual_read_enabled', '1', 1)");
$reader = new CvrOperationalIdentityReadService($pdo, $identity);
$dualOn = $reader->preferReservationForSchedulerRecordId(7, '11111111-1111-4111-8111-111111111111');
$checks['dual-read prefers verified canonical when enabled'] =
    $dualOn['resolved'] === true
    && $dualOn['reservation_uuid'] === $reservation['reservation_uuid'];

// Backfill dry-run / apply semantics
$pdo->exec("INSERT INTO ipca_flight_schedule_slots
  (id, scheduler_record_id, organization_id, reservation_type, status, scheduled_start_time, scheduled_end_time, planned_departure_airport, planned_destination_airport)
  VALUES
  (1, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 7, 'flight_training', 'scheduled', '2026-08-05 10:00:00', '2026-08-05 12:00:00', 'KSBA', 'KSMX'),
  (2, 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 7, 'briefing', 'scheduled', '2026-08-05 13:00:00', '2026-08-05 14:00:00', '', ''),
  (3, 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 7, 'practical_exam', 'scheduled', '2026-08-05 15:00:00', '2026-08-05 17:00:00', 'KSBA', 'KSBA'),
  (4, 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', 7, 'simulator_training', 'scheduled', '2026-08-05 18:00:00', '2026-08-05 19:00:00', '', '')");

$dry = $backfill->backfill(7, true, 50);
$checks['backfill CLI defaults dry-run semantics'] =
    $dry['dry_run'] === true
    && $dry['scanned_slots'] >= 4
    && str_contains($cliSource, '!$apply');

$applyBlocked = false;
try {
    $backfill->backfill(7, false, 50);
} catch (RuntimeException) {
    $applyBlocked = true;
}
$checks['backfill apply blocked when flag off'] = $applyBlocked;

$pdo->exec("INSERT INTO system_policy_values (policy_key, value_text, is_active) VALUES
  ('operational_identity_backfill_enabled', '1', 1)");
$applied = $backfill->backfill(7, false, 50);
$flightReservation = $identity->findAlias(7, 'schedule', 'scheduler_record_id', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', null);
$briefingReservation = $identity->findAlias(7, 'schedule', 'scheduler_record_id', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', null);
$simReservation = $identity->findAlias(7, 'schedule', 'scheduler_record_id', 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', null);
$practicalQuarantine = $pdo->query("SELECT COUNT(*) FROM ipca_operational_identity_backfill_quarantine WHERE reason_code = 'activity_domain_requires_explicit_classification'")->fetchColumn();

$flightLegs = $flightReservation
    ? $identity->listLegsForReservation((string)$flightReservation['reservation_uuid'])
    : array();
$briefingLegs = $briefingReservation
    ? $identity->listLegsForReservation((string)$briefingReservation['reservation_uuid'])
    : array();
$simLegs = $simReservation
    ? $identity->listLegsForReservation((string)$simReservation['reservation_uuid'])
    : array();

$checks['backfill creates flight leg only for flight domain'] =
    $applied['dry_run'] === false
    && is_array($flightReservation)
    && count($flightLegs) === 1
    && is_array($briefingReservation)
    && count($briefingLegs) === 0
    && is_array($simReservation)
    && count($simLegs) === 0
    && (int)$practicalQuarantine >= 1;

// Dispatch verified 1:1 alias
$pdo->exec("INSERT INTO ipca_cvr_dispatches
  (id, dispatch_uuid, workflow_flight_record_uuid, scheduler_record_id, current_version, organization_id, device_id)
  VALUES
  (10, 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', 'ffffffff-ffff-4fff-8fff-ffffffffffff', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 3, 7, 1)");
$dispatchPass = $backfill->backfill(7, false, 50);
$dispatchAlias = $identity->findAlias(7, 'cvr_unit', 'dispatch_uuid', 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', null);
$frAlias = $identity->findAlias(7, 'cvr_unit', 'workflow_flight_record_uuid', 'ffffffff-ffff-4fff-8fff-ffffffffffff', null);
$versionAlias = $identity->findAlias(7, 'cvr_unit', 'dispatch_uuid_version', 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', '3');
$checks['dispatch and FR alias only with verified 1:1 leg'] =
    is_array($dispatchAlias)
    && (string)$dispatchAlias['leg_uuid'] === (string)$flightLegs[0]['leg_uuid']
    && is_array($frAlias)
    && (string)$frAlias['leg_uuid'] === (string)$flightLegs[0]['leg_uuid']
    && is_array($versionAlias)
    && (string)$versionAlias['alias_version'] === '3'
    && $dispatchPass['aliases_created'] >= 1;

$sourceUnchanged = (string)$pdo->query("SELECT planned_departure_airport FROM ipca_flight_schedule_slots WHERE id = 1")->fetchColumn();
$checks['source schedule rows remain unchanged'] = $sourceUnchanged === 'KSBA';

$checks['docs present'] =
    str_contains($docs, 'activity_domain')
    && str_contains($docs, 'operational_identity_backfill_enabled')
    && str_contains($docs, 'DETERMINISTIC_BACKFILL');

$checks['no intake write wiring in Phase 2A identity services'] =
    !str_contains($identitySource, 'CvrDispatchIntakeService')
    && !str_contains($backfillSource, 'FlightScheduleService')
    && !str_contains($backfillSource, 'dispatch_sync.php')
    && !str_contains($readSource, 'dispatch_sync.php');

$failed = array();
foreach ($checks as $name => $ok) {
    if (!$ok) {
        $failed[] = $name;
    }
}

if ($failed === array()) {
    echo 'cvr_phase2a_identity_contract_check: PASS (' . count($checks) . " checks)\n";
    exit(0);
}

echo "cvr_phase2a_identity_contract_check: FAIL\n";
foreach ($failed as $name) {
    echo '- ' . $name . "\n";
}
exit(1);
