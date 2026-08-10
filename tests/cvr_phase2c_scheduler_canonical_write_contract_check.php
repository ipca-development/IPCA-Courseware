<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/AuditEventService.php';
require_once __DIR__ . '/../src/CvrOperationalIdentityService.php';
require_once __DIR__ . '/../src/CvrOperationalIdentityReadService.php';
require_once __DIR__ . '/../src/CvrOperationalIdentityBackfillService.php';
require_once __DIR__ . '/../src/FlightScheduleService.php';

$root = dirname(__DIR__);
$identitySource = file_get_contents($root . '/src/CvrOperationalIdentityService.php') ?: '';
$scheduleSource = file_get_contents($root . '/src/FlightScheduleService.php') ?: '';
$readSource = file_get_contents($root . '/src/CvrOperationalIdentityReadService.php') ?: '';
$backfillSource = file_get_contents($root . '/src/CvrOperationalIdentityBackfillService.php') ?: '';
$dutySource = file_get_contents($root . '/src/CvrDutyAssignmentIdentityService.php') ?: '';
$docs = file_get_contents($root . '/docs/cvr_phase2a_operational_identity.md') ?: '';
$saveSlotStart = strpos($scheduleSource, 'public function saveSlot(');
$saveSlotEnd = strpos($scheduleSource, 'public function createScheduledDutyFromDevice(', $saveSlotStart ?: 0);
$saveSlotSource = $saveSlotStart !== false
    ? substr(
        $scheduleSource,
        $saveSlotStart,
        $saveSlotEnd !== false ? $saveSlotEnd - $saveSlotStart : null
    )
    : '';

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
  status TEXT NOT NULL DEFAULT 'open'
)");
$pdo->exec("CREATE TABLE ipca_missions (
  id INTEGER PRIMARY KEY,
  organization_id INTEGER NOT NULL,
  code TEXT NOT NULL
)");
$pdo->exec("INSERT INTO ipca_missions (id, organization_id, code) VALUES (10, 7, '2.1.5'), (11, 8, '3.1.1')");
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

$checks = array();

$checks['activity_domain mapping includes approved Phase 2C types'] =
    CvrOperationalIdentityService::defaultActivityDomainForReservationType('flight_training') === 'flight'
    && CvrOperationalIdentityService::defaultActivityDomainForReservationType('simulator_training') === 'simulator'
    && CvrOperationalIdentityService::defaultActivityDomainForReservationType('briefing') === 'ground'
    && CvrOperationalIdentityService::defaultActivityDomainForReservationType('ground_training') === 'ground'
    && CvrOperationalIdentityService::defaultActivityDomainForReservationType('other') === 'administrative';

$checks['other never creates flight legs by domain'] =
    CvrOperationalIdentityService::defaultActivityDomainForReservationType('other') !== 'flight';

$checks['online create helper and atomic create wiring exist'] =
    str_contains($identitySource, 'function createOnlineScheduleReservationIdentity')
    && str_contains($identitySource, "'linkage_method' => 'online_create'")
    && str_contains($identitySource, "'confidence_state' => 'VERIFIED'")
    && str_contains($scheduleSource, 'createOnlineScheduleReservationIdentity')
    && str_contains($scheduleSource, 'FLAG_CANONICAL_WRITE')
    && str_contains($scheduleSource, 'requireOrganizationIdForCreate')
    && str_contains($scheduleSource, 'operational identity could not be recorded')
    && preg_match('/INSERT INTO ipca_flight_schedule_slots\s*\([^)]*organization_id/s', $scheduleSource) === 1
    && (
        str_contains($scheduleSource, 'if ($this->identityWrite()->isFlagEnabled(CvrOperationalIdentityService::FLAG_CANONICAL_WRITE))')
        || str_contains($scheduleSource, 'if ($canonicalWrite)')
    );

$checks['Stage 1 duty snapshot is atomic and feature gated'] =
    str_contains($scheduleSource, 'writeSnapshot($recordId, $dutyInput)')
    && str_contains($scheduleSource, 'assertReservationMatches($recordId, $dutyInput)')
    && str_contains($scheduleSource, 'isSnapshotWriteEnabled()')
    && str_contains($dutySource, 'duty_assignment_snapshot_write_enabled')
    && str_contains($dutySource, 'duty_assignment_enforcement_enabled')
    && str_contains($dutySource, 'Material Duty Assignment change requires a new reservation');

$checks['updates do not call online create helper'] =
    str_contains($saveSlotSource, '$isCreate = !is_array($row);')
    && preg_match(
        '/if \(!\$isCreate\) \{[\s\S]*UPDATE ipca_flight_schedule_slots[\s\S]*\} else \{[\s\S]*createOnlineScheduleReservationIdentity/',
        $saveSlotSource
    ) === 1
    && substr_count($saveSlotSource, 'createOnlineScheduleReservationIdentity') === 1;

$checks['dual-read projects flight reservation with one or more legs'] =
    str_contains($readSource, 'Multi-leg is valid')
    && str_contains($readSource, 'listLegsForReservation')
    && str_contains($readSource, 'schedule_flight_leg_count_unexpected');

$checks['dry-run counts server_dispatch_id'] =
    str_contains($backfillSource, 'dispatch_uuid + dispatch_uuid_version + server_dispatch_id')
    && preg_match('/aliases_created\'\]\s*\+=\s*3\s*\+/', $backfillSource) === 1;

$checks['docs describe Phase 2C'] =
    str_contains($docs, 'Phase 2C')
    && str_contains($docs, 'online_create')
    && str_contains($docs, 'operational_identity_canonical_write_enabled');

$identity = new CvrOperationalIdentityService($pdo);
$reader = new CvrOperationalIdentityReadService($pdo, $identity);
$schedule = new FlightScheduleService($pdo);

$disabledThrows = false;
try {
    $identity->createOnlineScheduleReservationIdentity(array(
        'organization_id' => 7,
        'scheduler_record_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        'schedule_slot_id' => 1,
        'reservation_type' => 'flight_training',
        'status' => 'scheduled',
        'organization_timezone_iana' => 'America/Los_Angeles',
    ));
} catch (RuntimeException $e) {
    $disabledThrows = str_contains($e->getMessage(), 'disabled');
}
$checks['flag off blocks online canonical helper'] =
    $disabledThrows
    && !$identity->isFlagEnabled(CvrOperationalIdentityService::FLAG_CANONICAL_WRITE);

$pdo->exec("INSERT INTO system_policy_values (policy_key, value_text, is_active) VALUES
  ('operational_identity_canonical_write_enabled', '1', 1),
  ('operational_identity_dual_read_enabled', '1', 1)");
$identity = new CvrOperationalIdentityService($pdo);
$reader = new CvrOperationalIdentityReadService($pdo, $identity);

$orgResolver = new ReflectionClass($schedule);
$orgMethod = $orgResolver->getMethod('requireOrganizationIdForCreate');
$checks['organization_id resolver uses mission context, not posted org'] =
    $orgMethod->invoke($schedule, array(), 10) === 7;
$postedMismatchFailed = false;
try {
    $orgMethod->invoke($schedule, array('organization_id' => 99), 10);
} catch (RuntimeException $e) {
    $postedMismatchFailed = str_contains($e->getMessage(), 'Organization context does not match');
}
$checks['spoofed posted organization_id is rejected'] = $postedMismatchFailed;
$checks['matching optional posted organization_id succeeds'] =
    $orgMethod->invoke($schedule, array('organization_id' => 7), 10) === 7;
$orgMissingFailed = false;
try {
    $orgMethod->invoke($schedule, array(), null);
} catch (RuntimeException) {
    $orgMissingFailed = true;
}
$checks['organization_id resolver never invents a silent default'] = $orgMissingFailed;

/**
 * Mirror saveSlot create transaction: legacy insert + optional canonical write.
 *
 * @param array<string,mixed> $values
 * @return array{slot_id:int,safe_error:?string}
 */
$createWithCanonical = static function (PDO $pdo, CvrOperationalIdentityService $identity, array $values): array {
    $recordId = strtolower(trim((string)$values['scheduler_record_id']));
    $organizationId = (int)$values['organization_id'];
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO ipca_flight_schedule_slots
             (scheduler_record_id, organization_id, reservation_type, scheduled_date, scheduled_start_time, scheduled_end_time,
              aircraft_id, planned_departure_airport, planned_destination_airport, status, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute(array(
            $recordId,
            $organizationId,
            (string)$values['reservation_type'],
            (string)$values['scheduled_date'],
            (string)$values['scheduled_start_time'],
            (string)$values['scheduled_end_time'],
            (int)$values['aircraft_id'],
            (string)($values['planned_departure_airport'] ?? ''),
            (string)($values['planned_destination_airport'] ?? ''),
            (string)($values['status'] ?? 'scheduled'),
            '',
        ));
        $slotId = (int)$pdo->lastInsertId();
        if ($identity->isFlagEnabled(CvrOperationalIdentityService::FLAG_CANONICAL_WRITE)) {
            try {
                $identity->createOnlineScheduleReservationIdentity(array(
                    'organization_id' => $organizationId,
                    'scheduler_record_id' => $recordId,
                    'schedule_slot_id' => $slotId,
                    'reservation_type' => (string)$values['reservation_type'],
                    'status' => (string)($values['status'] ?? 'scheduled'),
                    'planned_departure_airport' => (string)($values['planned_departure_airport'] ?? ''),
                    'planned_destination_airport' => (string)($values['planned_destination_airport'] ?? ''),
                    'scheduled_start_time' => (string)$values['scheduled_start_time'],
                    'scheduled_end_time' => (string)$values['scheduled_end_time'],
                    'organization_timezone_iana' => 'America/Los_Angeles',
                ));
            } catch (Throwable $canonicalError) {
                $identity->logTechnicalDiagnostic('online_schedule_canonical_write_failed', array(
                    'organization_id' => $organizationId,
                    'scheduler_record_id' => $recordId,
                    'schedule_slot_id' => $slotId,
                    'error_class' => $canonicalError::class,
                ));
                throw new RuntimeException(
                    'Unable to create the schedule reservation because operational identity could not be recorded. Please try again.',
                    0,
                    $canonicalError
                );
            }
        }
        $pdo->commit();
        return array('slot_id' => $slotId, 'safe_error' => null);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
};

$briefingId = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
$createWithCanonical($pdo, $identity, array(
    'scheduler_record_id' => $briefingId,
    'organization_id' => 7,
    'reservation_type' => 'briefing',
    'scheduled_date' => '2026-08-06',
    'scheduled_start_time' => '2026-08-06 09:00:00',
    'scheduled_end_time' => '2026-08-06 10:00:00',
    'aircraft_id' => 1,
));
$briefingReservation = $identity->findReservationByUuid($briefingId);
$briefingLegs = $identity->listLegsForReservation($briefingId);
$briefingAliases = (int)$pdo->query("SELECT COUNT(*) FROM ipca_operational_identity_aliases WHERE reservation_uuid = '{$briefingId}'")->fetchColumn();
$briefingProjection = $reader->projectScheduleIdentity(7, $briefingId, null);
$checks['flag on non-flight create writes reservation + aliases and no legs'] =
    is_array($briefingReservation)
    && (string)$briefingReservation['activity_domain'] === 'ground'
    && $briefingLegs === array()
    && $briefingAliases === 2
    && is_array($briefingProjection)
    && $briefingProjection['reservation_uuid'] === $briefingId
    && $briefingProjection['leg_uuid'] === null
    && $briefingProjection['identity_source'] === 'canonical_alias';

$otherId = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
$createWithCanonical($pdo, $identity, array(
    'scheduler_record_id' => $otherId,
    'organization_id' => 7,
    'reservation_type' => 'other',
    'scheduled_date' => '2026-08-06',
    'scheduled_start_time' => '2026-08-06 13:00:00',
    'scheduled_end_time' => '2026-08-06 14:00:00',
    'aircraft_id' => 1,
));
$otherReservation = $identity->findReservationByUuid($otherId);
$checks['other creates administrative reservation without legs'] =
    is_array($otherReservation)
    && (string)$otherReservation['activity_domain'] === 'administrative'
    && $identity->listLegsForReservation($otherId) === array();

$newFlightId = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
$created = $createWithCanonical($pdo, $identity, array(
    'scheduler_record_id' => $newFlightId,
    'organization_id' => 7,
    'reservation_type' => 'flight_training',
    'scheduled_date' => '2026-08-07',
    'scheduled_start_time' => '2026-08-07 10:00:00',
    'scheduled_end_time' => '2026-08-07 12:00:00',
    'aircraft_id' => 1,
    'planned_departure_airport' => 'KSBA',
    'planned_destination_airport' => 'KSMX',
));
$slotId = (string)$created['slot_id'];
$flightReservation = $identity->findReservationByUuid($newFlightId);
$flightLegs = $identity->listLegsForReservation($newFlightId);
$schedulerAlias = $identity->findAlias(7, 'schedule', 'scheduler_record_id', $newFlightId, null);
$slotAlias = $identity->findAlias(7, 'schedule', 'schedule_slot_id', $slotId, null);
$flightProjection = $reader->projectScheduleIdentity(7, $newFlightId, $slotId);
$checks['flag on flight create writes reservation, one leg, verified aliases, and dual-read both UUIDs'] =
    is_array($flightReservation)
    && (string)$flightReservation['activity_domain'] === 'flight'
    && count($flightLegs) === 1
    && (int)$flightLegs[0]['sequence_number'] === 1
    && (string)$flightLegs[0]['origin_airport'] === 'KSBA'
    && (string)$flightLegs[0]['destination_airport'] === 'KSMX'
    && $flightLegs[0]['planned_start_at_utc'] !== null
    && $flightLegs[0]['planned_end_at_utc'] !== null
    && $flightLegs[0]['planned_start_dst_resolution'] !== null
    && $flightLegs[0]['planned_end_dst_resolution'] !== null
    && is_array($schedulerAlias)
    && (string)$schedulerAlias['linkage_method'] === 'online_create'
    && (string)$schedulerAlias['confidence_state'] === 'VERIFIED'
    && is_array($slotAlias)
    && (string)$slotAlias['linkage_method'] === 'online_create'
    && is_array($flightProjection)
    && $flightProjection['reservation_uuid'] === $newFlightId
    && $flightProjection['leg_uuid'] === (string)$flightLegs[0]['leg_uuid']
    && $flightProjection['identity_source'] === 'canonical_alias';

$reservationCountBeforeRetry = (int)$pdo->query('SELECT COUNT(*) FROM ipca_operational_reservations')->fetchColumn();
$legCountBeforeRetry = (int)$pdo->query('SELECT COUNT(*) FROM ipca_operational_reservation_legs')->fetchColumn();
$aliasCountBeforeRetry = (int)$pdo->query('SELECT COUNT(*) FROM ipca_operational_identity_aliases')->fetchColumn();
$legUuidBefore = (string)$flightLegs[0]['leg_uuid'];

$onlineRetry = $identity->createOnlineScheduleReservationIdentity(array(
    'organization_id' => 7,
    'scheduler_record_id' => $newFlightId,
    'schedule_slot_id' => $slotId,
    'reservation_type' => 'flight_training',
    'status' => 'scheduled',
    'planned_departure_airport' => 'KSBA',
    'planned_destination_airport' => 'KSMX',
    'scheduled_start_time' => '2026-08-07 10:00:00',
    'scheduled_end_time' => '2026-08-07 12:00:00',
    'organization_timezone_iana' => 'America/Los_Angeles',
));
$checks['online helper retry reuses reservation, sequence-1 leg, and aliases'] =
    $onlineRetry['reservation_uuid'] === $newFlightId
    && $onlineRetry['leg_uuid'] === $legUuidBefore
    && (int)$pdo->query('SELECT COUNT(*) FROM ipca_operational_reservations')->fetchColumn() === $reservationCountBeforeRetry
    && (int)$pdo->query('SELECT COUNT(*) FROM ipca_operational_reservation_legs')->fetchColumn() === $legCountBeforeRetry
    && (int)$pdo->query('SELECT COUNT(*) FROM ipca_operational_identity_aliases')->fetchColumn() === $aliasCountBeforeRetry;

$identity->createFlightLeg(array(
    'reservation_uuid' => $newFlightId,
    'organization_id' => 7,
    'sequence_number' => 2,
    'origin_airport' => 'KSMX',
    'destination_airport' => 'KSBA',
    'planned_start_local' => '2026-08-07 13:00:00',
    'planned_end_local' => '2026-08-07 14:00:00',
    'organization_timezone_iana' => 'America/Los_Angeles',
    'status' => 'scheduled',
    'source' => 'manual',
), false);
$multiLegProjection = $reader->projectScheduleIdentity(7, $newFlightId, $slotId);
$checks['intentional multi-leg schedule projection succeeds with primary leg'] =
    is_array($multiLegProjection)
    && $multiLegProjection['reservation_uuid'] === $newFlightId
    && is_string($multiLegProjection['leg_uuid'])
    && $multiLegProjection['leg_uuid'] !== ''
    && $multiLegProjection['identity_source'] === 'canonical_alias';

$failId = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
$identity->createReservation(array(
    'reservation_uuid' => $failId,
    'organization_id' => 99,
    'organization_timezone_iana' => 'America/Los_Angeles',
    'reservation_type' => 'flight_training',
    'activity_domain' => 'flight',
    'status' => 'scheduled',
    'source' => 'manual',
), false);
$slotsBeforeFail = (int)$pdo->query('SELECT COUNT(*) FROM ipca_flight_schedule_slots')->fetchColumn();
$safeMessage = false;
$rawDbLeak = false;
try {
    $createWithCanonical($pdo, $identity, array(
        'scheduler_record_id' => $failId,
        'organization_id' => 7,
        'reservation_type' => 'flight_training',
        'scheduled_date' => '2026-08-08',
        'scheduled_start_time' => '2026-08-08 10:00:00',
        'scheduled_end_time' => '2026-08-08 12:00:00',
        'aircraft_id' => 1,
        'planned_departure_airport' => 'KSBA',
        'planned_destination_airport' => 'KSMX',
    ));
} catch (RuntimeException $e) {
    $safeMessage = str_contains($e->getMessage(), 'operational identity could not be recorded')
        && !str_contains(strtolower($e->getMessage()), 'sql')
        && !str_contains(strtolower($e->getMessage()), 'pdo');
    $rawDbLeak = str_contains($e->getMessage(), 'SQLSTATE') || str_contains($e->getMessage(), 'HY000');
}
$slotsAfterFail = (int)$pdo->query('SELECT COUNT(*) FROM ipca_flight_schedule_slots')->fetchColumn();
$failReservation = $identity->findReservationByUuid($failId);
$checks['canonical failure rolls back legacy create and returns safe error'] =
    $safeMessage
    && !$rawDbLeak
    && $slotsAfterFail === $slotsBeforeFail
    && is_array($failReservation)
    && (int)$failReservation['organization_id'] === 99;

$failed = array();
foreach ($checks as $name => $ok) {
    if (!$ok) {
        $failed[] = $name;
    }
}

if ($failed === array()) {
    fwrite(STDOUT, "PASS cvr_phase2c_scheduler_canonical_write_contract_check (" . count($checks) . " checks)\n");
    exit(0);
}

fwrite(STDERR, "FAIL cvr_phase2c_scheduler_canonical_write_contract_check\n");
foreach ($failed as $name) {
    fwrite(STDERR, " - {$name}\n");
}
exit(1);
