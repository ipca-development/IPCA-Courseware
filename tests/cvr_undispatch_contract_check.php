<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$release = file_get_contents($root . '/src/CvrDispatchReleaseService.php') ?: '';
$endpoint = file_get_contents($root . '/public/api/cvr/dispatch_release.php') ?: '';
$schedule = file_get_contents($root . '/public/admin/schedule.php') ?: '';
$scheduleJs = file_get_contents($root . '/public/admin/assets/flight_schedule.js') ?: '';
$flightSchedule = file_get_contents($root . '/src/FlightScheduleService.php') ?: '';
$intake = file_get_contents($root . '/src/CvrDispatchIntakeService.php') ?: '';
$apiClient = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/APIClient.swift') ?: '';
$store = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/CVRWorkflowStore.swift') ?: '';
$views = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Views/OperationalWorkflowViews.swift') ?: '';

$checks = array(
    'release service clears claim and soft-releases dispatch' =>
        str_contains($release, 'class CvrDispatchReleaseService')
        && str_contains($release, "status = 'scheduled'")
        && str_contains($release, 'claimed_dispatch_uuid = NULL')
        && str_contains($release, "status = 'released'")
        && str_contains($release, 'scheduler_record_id = NULL')
        && str_contains($release, 'assertNoFlightEvidence'),
    'device release endpoint exists' =>
        str_contains($endpoint, 'CvrDispatchReleaseService')
        && str_contains($endpoint, 'releaseByDispatchUuid')
        && str_contains($endpoint, 'DeviceAuthService'),
    'scheduler exposes can_undispatch and Undispatch action' =>
        str_contains($flightSchedule, "'can_undispatch'")
        && str_contains($schedule, "'undispatch'")
        && str_contains($schedule, 'flightUndispatchModal')
        && str_contains($scheduleJs, 'can_undispatch')
        && str_contains($scheduleJs, 'Undispatch'),
    'released dispatches cannot be reclaimed with same UUID' =>
        str_contains($intake, "=== 'released'")
        && str_contains($intake, 'This Dispatch was undispatched'),
    'app can Undispatch locked Dispatch before evidence' =>
        str_contains($store, 'canUndispatchActiveFlight')
        && str_contains($store, 'func undispatchActiveFlight')
        && str_contains($apiClient, 'func releaseDispatch')
        && str_contains($apiClient, 'dispatch_release.php')
        && str_contains($views, 'UNDISPATCH')
        && str_contains($views, 'undispatchActiveFlight'),
);

$failed = array();
foreach ($checks as $name => $ok) {
    if (!$ok) {
        $failed[] = $name;
    }
}

if ($failed) {
    fwrite(STDERR, "cvr_undispatch_contract_check FAILED:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "cvr_undispatch_contract_check OK (" . count($checks) . " checks)\n";
exit(0);
