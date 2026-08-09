#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/src/FlightScheduleService.php') ?: '';
$endpoint = file_get_contents($root . '/public/api/cvr/schedule_duty_sync.php') ?: '';
$store = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/CVRWorkflowStore.swift') ?: '';
$uploads = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/UploadManager.swift') ?: '';
$api = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/APIClient.swift') ?: '';
$views = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Views/OperationalWorkflowViews.swift') ?: '';
$scheduleJs = file_get_contents($root . '/public/admin/assets/flight_schedule.js') ?: '';

$checks = array(
    'endpoint routes create separately from supersession' =>
        str_contains($endpoint, "\$payload['supersedes_scheduler_record_id']")
        && str_contains($endpoint, 'createScheduledDutyFromDevice($device, $payload)')
        && str_contains($endpoint, 'supersedeScheduledDutyFromDevice($device, $payload)'),
    'device scheduler create validates stable matching UUIDs and aircraft ownership' =>
        str_contains($service, 'public function createScheduledDutyFromDevice')
        && str_contains($service, 'reservationUuid !== $recordId')
        && str_contains($service, 'aircraftId !== $deviceAircraftId')
        && str_contains($service, 'aircraft registration does not match this device'),
    'create requires organization mission crew and local schedule window' =>
        str_contains($service, "\$device['organization_id']")
        && str_contains($service, 'same-date schedule start and end are required')
        && str_contains($service, 'selected mission was not found for this organization')
        && str_contains($service, 'Every crew position must use a valid user account and role.')
        && str_contains($service, 'Select one primary customer and one or two pilots logging PIC.'),
    'create accepts route-free planning and validates informative routes' =>
        str_contains($service, "\$departure = \$airportChain[0] ?? '';")
        && str_contains($service, "'allow_route_free_flight' => \$airportChain === array()")
        && str_contains($service, 'Informative route legs must form one continuous airport chain.'),
    'first create is transactional and records overlap as advisory' =>
        str_contains($service, 'createScheduledDutyFromDevice(array $device, array $payload)')
        && str_contains($service, '$this->pdo->beginTransaction();')
        && str_contains($service, '$overlapWarnings = $this->resourceConflictWarnings(')
        && str_contains($service, "'warnings' => \$overlapWarnings")
        && str_contains($service, 'INSERT INTO ipca_flight_schedule_slots')
        && str_contains($service, 'INSERT INTO ipca_flight_schedule_crew')
        && str_contains($service, 'createOnlineScheduleReservationIdentity')
        && str_contains($service, 'writeSnapshot($recordId'),
    'identical retry is accepted and UUID material mismatch is rejected' =>
        str_contains($service, 'assertDeviceCreateRetryEquivalent(')
        && str_contains($service, "'already_present' => true")
        && str_contains($service, 'already synchronized with different material data'),
    'iOS freezes one durable create payload without blocking Dispatch' =>
        str_contains($store, 'queueLocalScheduleCreation(')
        && str_contains($store, '"operation": "create"')
        && str_contains($store, '"scheduler_record_id": schedulerRecordID.lowercased()')
        && str_contains($store, '"reservation_uuid": reservationUUID.lowercased()')
        && str_contains($store, 'requestPayloadSnapshot: snapshot')
        && str_contains($uploads, 'component.componentType == "schedule_duty_sync"'),
    'material edit before first sync remints create without invalid supersession' =>
        str_contains($store, 'let hasUnsynchronizedLocalCreate')
        && str_contains($store, 'The original local draft never existed online.')
        && str_contains($store, 'queueLocalScheduleCreation(dispatch: &dispatch, state: &state)')
        && str_contains($store, 'dispatch.supersedesSchedulerRecordID = nil'),
    'synced overlap warning remains visible without blocking reservation' =>
        str_contains($api, 'var warnings: [String]?')
        && str_contains($uploads, '(response.warnings ?? []).joined')
        && str_contains($store, 'case syncedWithWarning')
        && str_contains($views, 'RESERVATION SYNCED · OVERLAP'),
    'online scheduler stacks overlapping reservations in separate lanes' =>
        str_contains($scheduleJs, 'var laneEnds = [];')
        && str_contains($scheduleJs, 'createEvent(placement.reservation, timeline, placement.lane)')
        && str_contains($scheduleJs, 'timeline.style.minHeight = Math.max(68, 8 + laneCount * 60)'),
    'simulation and offline paths preserve queued reservation' =>
        str_contains($uploads, 'guard !settings.isSimulationModeEnabled else')
        && str_contains($uploads, 'if let networkMonitor,')
        && str_contains($store, 'Saved safely on this CVR Unit and queued.'),
);

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
if ($failed !== array()) {
    fwrite(STDERR, "cvr_local_schedule_create_contract_check FAILED:\n- "
        . implode("\n- ", $failed) . "\n");
    exit(1);
}
fwrite(STDOUT, 'cvr_local_schedule_create_contract_check OK (' . count($checks) . " checks)\n");
