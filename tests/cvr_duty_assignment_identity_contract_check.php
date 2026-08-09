<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/CvrDutyAssignmentIdentityService.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE system_policy_values (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  policy_key TEXT NOT NULL,
  value_text TEXT NOT NULL,
  is_active INTEGER NOT NULL DEFAULT 1
)");
$pdo->exec("INSERT INTO system_policy_values (policy_key, value_text) VALUES
  ('duty_assignment_snapshot_write_enabled', '1'),
  ('duty_assignment_enforcement_enabled', '1')");
$pdo->exec("CREATE TABLE ipca_operational_reservation_duties (
  reservation_uuid TEXT PRIMARY KEY, organization_id INTEGER NOT NULL,
  contract_version INTEGER NOT NULL, fingerprint_version INTEGER NOT NULL,
  duty_fingerprint_sha256 TEXT NOT NULL, primary_customer_identity_key TEXT NOT NULL,
  aircraft_device_id INTEGER NOT NULL, aircraft_registration_snapshot TEXT NOT NULL,
  reservation_type TEXT NOT NULL, activity_domain TEXT NOT NULL,
  training_assignment_category TEXT NOT NULL, mission_id INTEGER NULL,
  mission_code_snapshot TEXT NOT NULL, duty_snapshot_json TEXT NOT NULL,
  source TEXT NOT NULL, created_by_user_id INTEGER NULL
)");
$pdo->exec("CREATE TABLE ipca_operational_reservation_duty_participants (
  id INTEGER PRIMARY KEY AUTOINCREMENT, reservation_uuid TEXT NOT NULL,
  organization_id INTEGER NOT NULL, person_identity_key TEXT NOT NULL,
  person_user_id INTEGER NULL, external_person_uuid TEXT NULL,
  person_name_snapshot TEXT NOT NULL, participant_role TEXT NOT NULL,
  pilot_function TEXT NOT NULL, is_pic INTEGER NOT NULL,
  is_primary_customer INTEGER NOT NULL, is_accountable INTEGER NOT NULL,
  sequence_number INTEGER NOT NULL
)");

$service = new CvrDutyAssignmentIdentityService($pdo);
$base = array(
    'organization_id' => 1,
    'aircraft_device_id' => 428,
    'aircraft_registration' => 'N428EA',
    'reservation_type' => 'flight_training',
    'activity_domain' => 'flight',
    'training_assignment_category' => 'flight_training',
    'mission_id' => 44,
    'crew' => array(
        array('person_id' => 10, 'person_name' => 'Student A', 'role' => 'student', 'pilot_function' => 'PF'),
        array('person_id' => 20, 'person_name' => 'Instructor B', 'role' => 'instructor', 'is_pic' => true),
    ),
);

$failures = array();
$first = $service->canonicalize($base);
$nonMaterial = $base;
$nonMaterial['airport_chain'] = array('KTRM', 'KPSP', 'KTRM');
$nonMaterial['engine_shutdowns'] = 1;
$nonMaterial['exercise'] = 'steep_turns';
if (!hash_equals($first['fingerprint'], $service->canonicalize($nonMaterial)['fingerprint'])) {
    $failures[] = 'route, shutdown, and exercise changes must not change the duty fingerprint';
}
$roleSwap = $base;
$roleSwap['crew'][0]['pilot_function'] = 'PM';
$roleSwap['crew'][1]['pilot_function'] = 'PF';
if (hash_equals($first['fingerprint'], $service->canonicalize($roleSwap)['fingerprint'])) {
    $failures[] = 'PF/PM changes must change the duty fingerprint';
}
$reservationUuid = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$service->writeSnapshot($reservationUuid, $base);
$service->writeSnapshot($reservationUuid, $nonMaterial);
if ((int)$pdo->query('SELECT COUNT(*) FROM ipca_operational_reservation_duties')->fetchColumn() !== 1) {
    $failures[] = 'snapshot write must be idempotent';
}
$rejected = false;
try {
    $service->writeSnapshot($reservationUuid, $roleSwap);
} catch (RuntimeException $e) {
    $rejected = str_contains($e->getMessage(), 'new reservation');
}
if (!$rejected) {
    $failures[] = 'material in-place mutation must be rejected';
}

if ($failures !== array()) {
    fwrite(STDERR, "cvr_duty_assignment_identity_contract_check FAILED:\n- "
        . implode("\n- ", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "cvr_duty_assignment_identity_contract_check OK\n");
