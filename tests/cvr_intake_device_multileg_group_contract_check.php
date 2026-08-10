<?php
declare(strict_types=1);

/**
 * Device multi-leg intake grouping: N synced Dispatches under one scheduler
 * become one Master Logbook row with Via + leg_segments, sharing Garmin/replay.
 */

$root = dirname(__DIR__);
require_once $root . '/src/CvrDataIntakeReadService.php';

$failures = array();

function sibling(string $id, string $flight, string $dep, string $arr, array $extra = array()): array
{
    return array_merge(array(
        'id' => (int)$id,
        'dispatch_uuid' => 'dddddddd-dddd-4ddd-8ddd-' . str_pad($id, 12, '0', STR_PAD_LEFT),
        'workflow_flight_record_uuid' => $flight,
        'scheduler_record_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        'reservation_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        'departure_airport' => $dep,
        'arrival_airport' => $arr,
        'off_block_utc' => '2026-08-08 14:5' . $id . ':00',
        'on_block_utc' => '2026-08-08 15:5' . $id . ':00',
        'starting_hobbs' => 100.0 + ((int)$id - 1),
        'ending_hobbs' => 101.0 + ((int)$id - 1),
        'starting_tacho' => 50.0 + ((int)$id - 1) * 0.5,
        'ending_tacho' => 50.5 + ((int)$id - 1) * 0.5,
        'fuel_onboard' => (string)(13 - ((int)$id - 1) * 3),
        'fuel_remaining' => (string)(10 - ((int)$id - 1) * 3),
        'takeoff_count' => 1,
        'landing_count' => 1,
        'leg_segments' => array(),
        'via_airports' => array(),
        'has_garmin_csv' => false,
        'recording_uid' => '',
        'reconstruction_status' => '',
        'audio_status_label' => 'Stored on Device',
        'transcript_status' => 'pending',
    ), $extra);
}

$leg1 = sibling('1', '11111111-1111-4111-8111-111111111111', 'KTRM', 'KEED', array(
    'has_garmin_csv' => true,
    'flight_data_status_label' => 'Garmin Uploaded',
    'recording_uid' => 'rec-leg1',
    'reconstruction_status' => 'ready',
    'off_block_utc' => '2026-08-08 14:51:00',
    'on_block_utc' => '2026-08-08 16:00:00',
    'starting_hobbs' => 842.6,
    'ending_hobbs' => 844.0,
    'fuel_onboard' => '13.0',
    'fuel_remaining' => '9.0',
));
$leg2 = sibling('2', '22222222-2222-4222-8222-222222222222', 'KEED', 'KBLH', array(
    'off_block_utc' => '2026-08-08 16:20:00',
    'on_block_utc' => '2026-08-08 17:10:00',
    'starting_hobbs' => 844.0,
    'ending_hobbs' => 845.2,
    'fuel_onboard' => '9.0',
    'fuel_remaining' => '6.0',
));
$leg3 = sibling('3', '33333333-3333-4333-8333-333333333333', 'KBLH', 'KTRM', array(
    'off_block_utc' => '2026-08-08 17:40:00',
    'on_block_utc' => '2026-08-08 18:57:00',
    'starting_hobbs' => 845.2,
    'ending_hobbs' => 846.7,
    'fuel_onboard' => '6.0',
    'fuel_remaining' => '3.0',
));

// DESC order as intake SQL returns
$grouped = CvrDataIntakeReadService::groupDeviceMultiLegDispatchRows(array($leg3, $leg2, $leg1));
if (count($grouped) !== 1) {
    $failures[] = 'three sibling Dispatches must collapse to one intake row (got ' . count($grouped) . ')';
} else {
    $row = $grouped[0];
    if (($row['departure_airport'] ?? '') !== 'KTRM' || ($row['arrival_airport'] ?? '') !== 'KTRM') {
        $failures[] = 'grouped route must be first departure → last arrival (KTRM→KTRM)';
    }
    $via = $row['via_airports'] ?? array();
    if ($via !== array('KEED', 'KBLH')) {
        $failures[] = 'via_airports must be KEED, KBLH (got ' . json_encode($via) . ')';
    }
    $segments = $row['leg_segments'] ?? array();
    if (!is_array($segments) || count($segments) !== 3) {
        $failures[] = 'leg_segments must contain 3 hops';
    }
    if (empty($row['device_multi_leg_grouped'])) {
        $failures[] = 'device_multi_leg_grouped must be true';
    }
    if (empty($row['has_garmin_csv'])) {
        $failures[] = 'Garmin CSV from leg 1 must be shared onto the grouped row';
    }
    if (($row['recording_uid'] ?? '') !== 'rec-leg1') {
        $failures[] = 'replay recording_uid must come from the Garmin/evidence donor leg';
    }
    if ((int)($row['takeoff_count'] ?? 0) !== 3 || (int)($row['landing_count'] ?? 0) !== 3) {
        $failures[] = 'TO/LDG must sum across sibling legs';
    }
    if ((float)($row['starting_hobbs'] ?? 0) !== 842.6 || (float)($row['ending_hobbs'] ?? 0) !== 846.7) {
        $failures[] = 'outer Hobbs must be first start → last end';
    }
    if (($row['workflow_flight_record_uuid'] ?? '') !== '11111111-1111-4111-8111-111111111111') {
        $failures[] = 'grouped evidence flight UUID must be the Garmin donor';
    }
}

$singleton = sibling('9', '99999999-9999-4999-8999-999999999999', 'KTRM', 'KPSP', array(
    'scheduler_record_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
));
$alone = CvrDataIntakeReadService::groupDeviceMultiLegDispatchRows(array($singleton));
if (count($alone) !== 1 || !empty($alone[0]['device_multi_leg_grouped'])) {
    $failures[] = 'single-leg Dispatch must remain ungrouped';
}

$annotated = sibling('7', '77777777-7777-4777-8777-777777777777', 'KTRM', 'KTRM', array(
    'scheduler_record_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
    'leg_segments' => array(
        array('sequence_number' => 1, 'departure_airport' => 'KTRM', 'arrival_airport' => 'KEED'),
        array('sequence_number' => 2, 'departure_airport' => 'KEED', 'arrival_airport' => 'KTRM'),
    ),
    'via_airports' => array('KEED'),
));
$annotatedSibling = sibling('8', '88888888-8888-4888-8888-888888888888', 'KEED', 'KTRM', array(
    'scheduler_record_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
));
$annotatedGroup = CvrDataIntakeReadService::groupDeviceMultiLegDispatchRows(array($annotated, $annotatedSibling));
if (count($annotatedGroup) !== 1) {
    $failures[] = 'annotated continuous Check-In must hide incomplete siblings (got ' . count($annotatedGroup) . ')';
} elseif (($annotatedGroup[0]['id'] ?? null) != 7) {
    $failures[] = 'annotated continuous Check-In must remain the visible row';
}

$source = file_get_contents($root . '/src/CvrDataIntakeReadService.php') ?: '';
$page = file_get_contents($root . '/public/admin/master_logbook_intake.php') ?: '';
foreach (array(
    'groupDeviceMultiLegDispatchRows' => $source,
    'pickDeviceMultiLegEvidenceDonor' => $source,
    'device_multi_leg_grouped' => $page,
    'splitBtn.hidden = alreadyMulti' => $page,
) as $needle => $haystack) {
    if (!str_contains($haystack, $needle)) {
        $failures[] = 'missing contract needle: ' . $needle;
    }
}

if ($failures === array()) {
    fwrite(STDOUT, "PASS cvr_intake_device_multileg_group_contract_check\n");
    exit(0);
}

fwrite(STDERR, "FAIL cvr_intake_device_multileg_group_contract_check\n");
foreach ($failures as $failure) {
    fwrite(STDERR, ' - ' . $failure . "\n");
}
exit(1);
