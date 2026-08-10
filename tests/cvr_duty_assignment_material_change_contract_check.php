<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/AuditEventService.php';
require_once dirname(__DIR__) . '/src/CvrDutyAssignmentIdentityService.php';

$pdo = new PDO('sqlite::memory:');
$service = new CvrDutyAssignmentIdentityService($pdo);
$base = array(
    'organization_id' => 7,
    'aircraft_device_id' => 397,
    'aircraft_registration' => 'N397EA',
    'reservation_type' => 'flight_training',
    'activity_domain' => 'flight',
    'training_assignment_category' => 'flight_training',
    'mission_id' => 101,
    'mission_code' => 'MISSION-101',
    'crew' => array(
        array('person_id' => 1, 'person_name' => 'Student A', 'role' => 'student', 'pilot_function' => 'PF'),
        array('person_id' => 2, 'person_name' => 'Student B', 'role' => 'student', 'pilot_function' => 'PM'),
        array('person_id' => 3, 'person_name' => 'Instructor B', 'role' => 'instructor', 'pilot_function' => 'NONE', 'is_pic' => true),
    ),
);
$baseHash = $service->canonicalize($base)['fingerprint'];
$failures = array();

$materialMutations = array(
    'primary student/PF' => static function (array $value): array {
        $value['crew'][0]['pilot_function'] = 'PM';
        $value['crew'][1]['pilot_function'] = 'PF';
        return $value;
    },
    'instructor' => static function (array $value): array {
        $value['crew'][2]['person_id'] = 4;
        return $value;
    },
    'PIC responsibility' => static function (array $value): array {
        $value['crew'][2]['is_pic'] = false;
        $value['crew'][0]['is_pic'] = true;
        return $value;
    },
    'aircraft/device' => static function (array $value): array {
        $value['aircraft_device_id'] = 428;
        $value['aircraft_registration'] = 'N428EA';
        return $value;
    },
    'training category' => static function (array $value): array {
        $value['training_assignment_category'] = 'practical_exam';
        return $value;
    },
    'formal mission' => static function (array $value): array {
        $value['mission_id'] = 102;
        return $value;
    },
);
foreach ($materialMutations as $label => $mutation) {
    if (hash_equals($baseHash, $service->canonicalize($mutation($base))['fingerprint'])) {
        $failures[] = $label . ' change must create a different duty fingerprint';
    }
}

$nonMaterial = $base;
$nonMaterial['airport_chain'] = array('KTRM', 'KPSP', 'KBUR', 'KTRM');
$nonMaterial['scheduled_start_time'] = '2026-08-08 10:00:00';
$nonMaterial['scheduled_end_time'] = '2026-08-08 14:00:00';
$nonMaterial['engine_shutdowns'] = 2;
$nonMaterial['events'] = array('slow_flight', 'stalls', 'steep_turns', 'diversion', 'safety_event');
$nonMaterial['remarks'] = 'Normal exercise progression';
if (!hash_equals($baseHash, $service->canonicalize($nonMaterial)['fingerprint'])) {
    $failures[] = 'route, time, shutdown, exercise, event, and remark changes must remain inside the duty';
}

$migration = file_get_contents(dirname(__DIR__) . '/scripts/sql/2026_08_08_cvr_duty_assignment_identity.sql') ?: '';
foreach (array(
    'ipca_operational_reservation_duties',
    'ipca_operational_reservation_duty_participants',
    'duty_assignment_snapshot_write_enabled',
    'duty_assignment_enforcement_enabled',
    "pilot_function IN ('NONE','PF','PM')",
    'is_pic',
) as $needle) {
    if (!str_contains($migration, $needle)) {
        $failures[] = 'migration missing ' . $needle;
    }
}

if ($failures !== array()) {
    fwrite(STDERR, "cvr_duty_assignment_material_change_contract_check FAILED:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "cvr_duty_assignment_material_change_contract_check OK\n");
