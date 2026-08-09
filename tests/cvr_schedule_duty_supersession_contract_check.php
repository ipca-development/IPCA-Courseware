<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$migration = file_get_contents($root . '/scripts/sql/2026_08_08_cvr_schedule_duty_supersession.sql') ?: '';
$schedule = file_get_contents($root . '/src/FlightScheduleService.php') ?: '';
$identity = file_get_contents($root . '/src/CvrOperationalIdentityService.php') ?: '';
$endpoint = file_get_contents($root . '/public/api/cvr/schedule_duty_sync.php') ?: '';
$liveEndpoint = file_get_contents($root . '/public/admin/api/schedule_reservations.php') ?: '';
$webSchedule = file_get_contents($root . '/public/admin/schedule.php') ?: '';
$scheduleJs = file_get_contents($root . '/public/admin/assets/flight_schedule.js') ?: '';
$models = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Models/CVRWorkflowModels.swift') ?: '';
$store = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/CVRWorkflowStore.swift') ?: '';
$uploads = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/UploadManager.swift') ?: '';
$api = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/APIClient.swift') ?: '';
$views = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Views/OperationalWorkflowViews.swift') ?: '';

$checks = array(
    'migration records append-only scheduler and reservation lineage' =>
        str_contains($migration, 'supersedes_scheduler_record_id')
        && str_contains($migration, 'superseded_by_scheduler_record_id')
        && str_contains($migration, 'supersedes_reservation_uuid')
        && str_contains($migration, 'superseded_by_reservation_uuid')
        && str_contains($migration, "'superseded'"),
    'authenticated device endpoint exposes schedule Duty sync' =>
        str_contains($endpoint, 'DeviceAuthService')
        && str_contains($endpoint, 'requireDevice()')
        && str_contains($endpoint, 'supersedeScheduledDutyFromDevice')
        && str_contains($endpoint, 'CvrTemporaryTechnicalFailure'),
    'server replacement is transactional idempotent and refuses claimed reservations' =>
        str_contains($schedule, 'public function supersedeScheduledDutyFromDevice')
        && str_contains($schedule, 'beginTransaction()')
        && str_contains($schedule, "'already_present' => true")
        && str_contains($schedule, 'Only an unclaimed scheduled reservation may be replaced.')
        && str_contains($schedule, "SET status = 'superseded', superseded_by_scheduler_record_id")
        && str_contains($schedule, 'supersedes_scheduler_record_id, organization_id')
        && str_contains($schedule, 'assertReplacementRetryEquivalent($existingNew, $payload, $crew)')
        && str_contains($schedule, 'A later material change requires a new replacement UUID.'),
    'device replacement reports overlap as advisory instead of rejecting mission edit' =>
        str_contains($schedule, '$overlapWarnings = $this->resourceConflictWarnings(')
        && str_contains($schedule, "'warnings' => \$overlapWarnings")
        && str_contains($schedule, "'warnings' => \$retryWarnings"),
    'normal schedule hides retained superseded audit rows' =>
        str_contains($schedule, "LOWER(TRIM(COALESCE(s.status, ''))) <> 'superseded'"),
    'web scheduler live polls and rerenders authoritative reservations' =>
        str_contains($liveEndpoint, 'cw_require_flight_schedule_editor()')
        && str_contains($liveEndpoint, 'listSlots($date, $date)')
        && str_contains($webSchedule, "'liveRefreshMilliseconds' => 5000")
        && str_contains($scheduleJs, 'function refreshLiveReservations()')
        && str_contains($scheduleJs, 'cache: \'no-store\'')
        && str_contains($scheduleJs, 'config.reservations = next;')
        && str_contains($scheduleJs, 'window.setInterval(refreshLiveReservations, liveRefreshMilliseconds)')
        && str_contains($scheduleJs, "document.addEventListener('visibilitychange'")
        && str_contains($scheduleJs, 'function updateHeroStats(reservations)')
        && str_contains($scheduleJs, 'LIVE · updated ')
        && str_contains($webSchedule, 'id="flightScheduleLiveStatus"'),
    'replacement writes canonical identity, exact leg UUIDs and immutable duty snapshot' =>
        str_contains($schedule, "'leg_uuids' => array_map")
        && str_contains($identity, "\$input['leg_uuids']")
        && str_contains($schedule, 'writeSnapshot($newId, $dutyInput)')
        && str_contains($schedule, "SET supersedes_reservation_uuid = ?"),
    'iOS material Duty edit remints identity and freezes queued replacement payload' =>
        str_contains($models, 'supersedesReservationUUID')
        && str_contains($store, 'dutyMaterialSignature')
        && str_contains($store, 'remintAndQueueScheduledDutyReplacement')
        && str_contains($store, 'componentType: "schedule_duty_sync"')
        && str_contains($store, 'requestPayloadSnapshot: snapshot'),
    'Operational Session mission edit queues replacement without legacy planned-leg state' =>
        str_contains($store, 'dispatch.reservationUUID = replacementUUID')
        && str_contains($store, 'dispatch.informativeRouteAirports ?? []')
        && str_contains($store, 'dispatch.informativePlannedLegUUIDs ?? []')
        && str_contains($store, 'let replacementReservation = dispatch.reservationUUID')
        && str_contains($schedule, "if (\$crew === array())")
        && str_contains($schedule, "'allow_route_free_flight' => \$airportChain === array()")
        && str_contains($identity, "\$allowRouteFreeFlight = !empty(\$input['allow_route_free_flight'])"),
    'later material edit starts a new supersession chain after server acceptance' =>
        str_contains($store, 'let priorReplacementWasAccepted = state.uploadComponents.contains')
        && str_contains($store, 'component.state == .serverVerified || component.state == .uploaded')
        && str_contains($store, 'dispatch.supersedesSchedulerRecordID = nil')
        && str_contains($store, 'Never reuse the UUID that the server has already accepted.'),
    'queued replacement survives ordinary workflow clearing and does not block Dispatch' =>
        str_contains($store, 'removeAll { $0.componentType != "schedule_duty_sync" }')
        && str_contains($views, 'private var canConfirmDispatch')
        && str_contains($views, 'workflow.dispatchMissingItems.isEmpty')
        && !str_contains($views, 'hasQueuedScheduleDutyReplacement && canConfirmDispatch'),
    'upload manager retries schedule replacement independently from flight evidence' =>
        str_contains($uploads, '"schedule_duty_sync", "dispatch_metadata"')
        && str_contains($uploads, 'uploadQueuedScheduleDutyComponent')
        && str_contains($uploads, 'syncScheduleDuty')
        && str_contains($uploads, 'scheduleDutyReplacementIsPending')
        && str_contains($api, 'api/cvr/schedule_duty_sync.php'),
    'scheduler receipts never enter flight-evidence reconciliation' =>
        str_contains($store, 'component.componentType != "schedule_duty_sync"')
        && str_contains($store, 'Unsupported reconciliation component type')
        && str_contains($store, 'state.uploadComponents[index].reconciliationRequired = false')
        && str_contains($store, 'if component.componentType == "schedule_duty_sync", state == .serverVerified'),
    'schedule hides superseded cached row and avoids false local-leg warning' =>
        str_contains($views, 'workflow.locallySupersededSchedulerRecordIDs.contains')
        && str_contains($views, '!workflow.hasQueuedScheduleDutyReplacement'),
    'cockpit clearly distinguishes queued syncing synced and attention states' =>
        str_contains($store, 'var scheduleDutySyncInfo: CVRScheduleDutySyncInfo?')
        && str_contains($store, 'queued. It will sync automatically when internet connectivity is available.')
        && str_contains($views, 'RESERVATION SYNC QUEUED')
        && str_contains($views, 'SYNCING RESERVATION')
        && str_contains($views, 'RESERVATION SYNCED')
        && str_contains($views, 'RESERVATION SYNC NEEDS ATTENTION')
        && substr_count($views, 'CVRScheduleDutySyncBanner(info: syncInfo)') >= 2,
);

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
if ($failed !== array()) {
    fwrite(STDERR, "cvr_schedule_duty_supersession_contract_check FAILED:\n- "
        . implode("\n- ", $failed) . "\n");
    exit(1);
}
fwrite(STDOUT, 'cvr_schedule_duty_supersession_contract_check OK (' . count($checks) . " checks)\n");
