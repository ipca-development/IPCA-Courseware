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
$catalog = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Models/CVRCatalogModels.swift') ?: '';

$checks = array(
    'release service clears claim and soft-releases dispatch' =>
        str_contains($release, 'class CvrDispatchReleaseService')
        && str_contains($release, "status = 'scheduled'")
        && str_contains($release, 'claimed_dispatch_uuid = NULL')
        && str_contains($release, "status = 'released'")
        && str_contains($release, 'scheduler_record_id = NULL')
        && str_contains($release, 'assertNoFlightEvidence')
        && str_contains($release, 'reconcileSlotAfterDispatchRelease')
        && str_contains($release, 'findRetainedSiblingDispatch')
        && !str_contains($release, 'Completed flights cannot be undispatched.'),
    'multi-leg completed slot does not block evidence-free release' =>
        str_contains($release, 'Earlier hops can mark the slot')
        && str_contains($release, 'schedulerHasClosureOutsideDispatch'),
    'aircraft schedule remains scheduled until acknowledged Dispatch' =>
        str_contains($flightSchedule, "array('scheduled', 'claimed')")
        && str_contains($catalog, 'status: session.status')
        && str_contains($views, 'return workflow.state.activeFlightRecord == nil ? "scheduled" : "dispatched"')
        && str_contains($views, 'return ("DISPATCHED", CVROperationalPalette.success)')
        && !str_contains($views, 'return ("SELECTED"')
        && !str_contains($views, 'Hide every expansion of this reservation while a hop is active'),
    'device release endpoint exists' =>
        str_contains($endpoint, 'CvrDispatchReleaseService')
        && str_contains($endpoint, 'releaseByDispatchUuid')
        && str_contains($endpoint, 'DeviceAuthService'),
    'missing server claim is idempotent success for authenticated device Dispatch' =>
        str_contains($release, 'Releasing that valid device-owned UUID is already satisfied here.')
        && str_contains($release, "'already_released' => true")
        && str_contains($release, "'dispatch_uuid' => \$dispatchUuid"),
    'scheduler exposes can_undispatch and Undispatch action' =>
        str_contains($flightSchedule, "'can_undispatch'")
        && str_contains($schedule, "'undispatch'")
        && str_contains($schedule, 'flightUndispatchModal')
        && str_contains($schedule, 'Undispatch')
        && str_contains($scheduleJs, 'can_undispatch'),
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
    'app can reconcile an administrative release without deleting local evidence' =>
        str_contains($store, 'response.alreadyReleased != true')
        && str_contains($store, 'archiveAdministrativelyReleasedWorkflow')
        && str_contains($store, 'cancelledSession?.state = .cancelled')
        && str_contains($store, 'Administrative server release confirmed; retained as cancelled evidence.')
        && str_contains($views, 'administrative server release; evidence is retained')
        && str_contains($views, 'guard !audio.isRecording else')
        && !str_contains($views, 'isUndispatching || audio.isRecording || audio.recordingSignalActive'),
    'Undispatch preserves prepared values but remints released identity' =>
        str_contains($store, 'draft.id = replacementDispatchID')
        && str_contains($store, 'Undispatch revokes the acknowledged execution, not the prepared')
        && str_contains($store, 'draft.operationalSessionUUID = nil')
        && str_contains($store, '$0.uploadComponents[index].flightRecordID = replacementDispatchID')
        && !str_contains($store, 'if clearEntirely {'),
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
