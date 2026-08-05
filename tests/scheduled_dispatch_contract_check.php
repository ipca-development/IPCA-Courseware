<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/FlightScheduleService.php';
require_once __DIR__ . '/../src/AircraftOperationalConfigService.php';

$checks = array();
$migration = file_get_contents(__DIR__ . '/../scripts/sql/2026_07_31_scheduled_dispatch_start_end.sql') ?: '';
$resourceMigration = file_get_contents(__DIR__ . '/../scripts/sql/2026_07_31_schedule_resource_scheduler.sql') ?: '';
$dispatchSource = file_get_contents(__DIR__ . '/../src/CvrDispatchIntakeService.php') ?: '';
$scheduleSource = file_get_contents(__DIR__ . '/../src/FlightScheduleService.php') ?: '';
$scheduleAdmin = file_get_contents(__DIR__ . '/../public/admin/schedule.php') ?: '';
$scheduleJs = file_get_contents(__DIR__ . '/../public/admin/assets/flight_schedule.js') ?: '';
$aircraftSource = file_get_contents(__DIR__ . '/../src/CockpitAircraftService.php') ?: '';
$apiSource = file_get_contents(__DIR__ . '/../public/api/cvr/scheduled_sessions.php') ?: '';
$iosModels = file_get_contents(__DIR__ . '/../ipca-cvr-unit/IPCACVRUnit/Models/CVRCatalogModels.swift') ?: '';
$iosUpload = file_get_contents(__DIR__ . '/../ipca-cvr-unit/IPCACVRUnit/Services/UploadManager.swift') ?: '';
$iosWorkflow = file_get_contents(__DIR__ . '/../ipca-cvr-unit/IPCACVRUnit/Services/CVRWorkflowStore.swift') ?: '';
$iosViews = file_get_contents(__DIR__ . '/../ipca-cvr-unit/IPCACVRUnit/Views/OperationalWorkflowViews.swift') ?: '';
$iosApiClient = file_get_contents(__DIR__ . '/../ipca-cvr-unit/IPCACVRUnit/Services/APIClient.swift') ?: '';

$schedule = (new ReflectionClass(FlightScheduleService::class))->newInstanceWithoutConstructor();
$payloadMethod = new ReflectionMethod(FlightScheduleService::class, 'payload');
$slotPayload = $payloadMethod->invoke($schedule, array(
    'scheduler_record_id' => '11111111-1111-4111-8111-111111111111',
    'scheduled_date' => '2026-07-31',
    'scheduled_start_time' => '2026-07-31 10:00:00',
    'scheduled_end_time' => '2026-07-31 12:00:00',
    'aircraft_id' => 7,
    'aircraft_registration' => 'N446CS',
    'mission_id' => 2,
    'cohort_id' => 5,
    'cohort_name' => 'FAA ACP 25A',
    'resolved_mission_code' => 'SPC-1',
    'mission_name' => 'SPC 1',
    'planned_departure_airport' => 'KPAE',
    'planned_destination_airport' => 'KPAE',
    'status' => 'scheduled',
    'notes' => '',
), array(array('person_id' => 4, 'person_name' => 'Pilot', 'role' => 'student')));

$checks['scheduled sessions payload matches iOS contract'] = static fn(): bool =>
    ($slotPayload['scheduler_record_id'] ?? '') !== ''
    && ($slotPayload['scheduled_date'] ?? '') === '2026-07-31'
    && ($slotPayload['scheduled_start_time'] ?? '') === '2026-07-31T10:00:00'
    && ($slotPayload['scheduled_end_time'] ?? '') === '2026-07-31T12:00:00'
    && ($slotPayload['aircraft']['id'] ?? 0) === 7
    && ($slotPayload['aircraft']['registration'] ?? '') === 'N446CS'
    && ($slotPayload['mission']['code'] ?? '') === 'SPC-1'
    && ($slotPayload['cohort']['id'] ?? 0) === 5
    && count($slotPayload['crew'] ?? array()) === 1
    && ($slotPayload['status'] ?? '') === 'scheduled';
$checks['API requires enrolled device authentication'] = static fn(): bool =>
    str_contains($apiSource, 'DeviceAuthService')
    && str_contains($apiSource, 'requireDevice()')
    && str_contains($apiSource, "'scheduled_sessions'");
$checks['scheduled sessions use California date window across UTC midnight'] = static fn(): bool =>
    str_contains($iosApiClient, 'TimeZone(identifier: "America/Los_Angeles")')
    && str_contains($iosApiClient, 'URLQueryItem(name: "from"')
    && str_contains($iosApiClient, 'URLQueryItem(name: "to"')
    && str_contains($scheduleSource, "new DateTimeZone('America/Los_Angeles')")
    && !str_contains($scheduleSource, "fromDate ?: gmdate('Y-m-d')");
$checks['schedule claim is transactional row locked and idempotent'] = static fn(): bool =>
    str_contains($dispatchSource, 'claimScheduledSlot')
    && str_contains($dispatchSource, 'FOR UPDATE')
    && str_contains($dispatchSource, 'claimed_dispatch_uuid')
    && str_contains($dispatchSource, "THEN 'completed'")
    && str_contains($dispatchSource, "ELSE 'claimed'");
$checks['offline Dispatch safely reconciles one matching return reservation'] = static fn(): bool =>
    str_contains($dispatchSource, 'resolveUnambiguousScheduledRecordId')
    && str_contains($dispatchSource, 'count($matches) === 1')
    && str_contains($dispatchSource, 'ipca_cvr_flight_closures closure_record')
    && str_contains($scheduleSource, 'reconcileUnlinkedCompletedDispatches')
    && str_contains($scheduleSource, 'count($slots) !== 1')
    && str_contains($scheduleSource, "SET status = 'completed', claimed_dispatch_uuid = ?");
$checks['schedule times are planning data and cannot block Dispatch upload'] = static fn(): bool =>
    !str_contains($dispatchSource, 'Scheduled session times do not match the Dispatch.')
    && str_contains($iosWorkflow, 'failedForLegacyScheduleTimeRule')
    && str_contains($iosWorkflow, 'componentState == .failed || componentState == .needsUserAction');
$checks['consumed scheduled sessions disappear from the iOS schedule immediately'] = static fn(): bool =>
    str_contains($iosViews, 'consumedSchedulerRecordIDs')
    && str_contains($iosViews, 'workflow.archives.compactMap')
    && str_contains($iosViews, '!consumedSchedulerRecordIDs.contains($0.schedulerRecordID)');
$checks['migration is additive and contains relational crew'] = static fn(): bool =>
    str_contains($migration, 'CREATE TABLE IF NOT EXISTS ipca_flight_schedule_slots')
    && str_contains($migration, 'CREATE TABLE IF NOT EXISTS ipca_flight_schedule_crew')
    && str_contains($migration, 'information_schema.COLUMNS')
    && str_contains($migration, 'schedule_slot_id')
    && str_contains($migration, 'user_id INT NULL');
$checks['N446CS seed is conditional and carries 53 USG 8 qt'] = static fn(): bool =>
    str_contains($migration, "N446CS")
    && str_contains($migration, "53.000")
    && str_contains($migration, "'USG'")
    && str_contains($migration, "8.000")
    && str_contains($migration, "'qt'")
    && str_contains($migration, 'FROM ipca_aircraft_devices');
$checks['aircraft payload exposes operational config'] = static fn(): bool =>
    str_contains($aircraftSource, "'operational_config'")
    && str_contains($aircraftSource, 'AircraftOperationalConfigService');
$checks['iOS schedule decoder accepts authenticated API envelope'] = static fn(): bool =>
    str_contains($apiSource, "'scheduled_sessions'")
    && str_contains($iosModels, 'scheduledSessions = "scheduled_sessions"');
$checks['authenticated scheduled missions are selectable unless audio is recording'] = static fn(): bool =>
    str_contains($iosWorkflow, 'return !isAudioRecording')
    && str_contains($iosWorkflow, 'state.activeDispatch != nil')
    && str_contains($iosViews, 'aircraftForSession(session)')
    && str_contains($iosViews, 'Archive Current and Open Scheduled Dispatch');
$checks['engine shutdown requires the original three second hold'] = static fn(): bool =>
    str_contains($iosViews, 'CVRHoldActionButton(title: "ENGINE SHUTDOWN"')
    && str_contains($iosViews, 'subtitle: "Hold 3 seconds for ON Block"')
    && str_contains($iosWorkflow, 'creationMethod: "three_second_hold"')
    && !str_contains($iosViews, 'CVROperationalActionButton(title: "END FLIGHT", subtitle: "Enter Ending Hobbs and Tacho", color: CVROperationalPalette.critical');
$checks['completed prior workflow archives without a confusing confirmation'] = static fn(): bool =>
    str_contains($iosWorkflow, 'func requiresArchivingBeforeScheduledSession')
    && str_contains($iosWorkflow, 'let endingMetersEntered')
    && str_contains($iosWorkflow, 'shutdown_verification_completed')
    && str_contains($iosWorkflow, 'if endingMetersEntered || shutdownSaved');
$checks['iOS and backend use the same generic oil payload keys'] = static fn(): bool =>
    str_contains($dispatchSource, "\$dispatch['oil_quantity']")
    && str_contains($dispatchSource, "\$dispatch['oil_unit']")
    && str_contains($iosUpload, 'dispatchPayload["oil_quantity"]')
    && str_contains($iosUpload, 'dispatchPayload["oil_unit"]');
$checks['resource scheduler exposes devices staff cohorts and drag resize'] = static fn(): bool =>
    str_contains($scheduleAdmin, "'label' => 'Devices'")
    && str_contains($scheduleAdmin, "'label' => 'Staff'")
    && str_contains($scheduleAdmin, "'label' => 'Cohorts'")
    && str_contains($scheduleJs, 'startMove(')
    && str_contains($scheduleJs, 'startResize(')
    && str_contains($scheduleJs, 'flightScheduleChangeModal');
$checks['editable reservations support click edit and drag without false concurrency conflicts'] = static fn(): bool =>
    !str_contains($scheduleJs, "if (pointerEvent.target.closest('.fltsch-resize-handle')) return;\n    pointerEvent.preventDefault();")
    && str_contains($scheduleJs, 'if (!moved && Math.abs(event.clientX - originX) < 5) return;')
    && str_contains($scheduleJs, "class=\"fltsch-event-edit\"")
    && str_contains($scheduleJs, '} else if (moved) {')
    && str_contains($scheduleAdmin, 'flight_schedule.js?v=20260801.3')
    && str_contains($scheduleSource, "substr((string)(\$slot['updated_at'] ?? ''), 0, 19)")
    && str_contains($scheduleSource, 'isoPrecise');
$checks['dispatched and completed reservations remain locked with evidence indicators'] = static fn(): bool =>
    str_contains($scheduleSource, "'lock_reason'")
    && str_contains($scheduleSource, "'evidence'")
    && str_contains($scheduleSource, 'has_flight_data')
    && str_contains($scheduleSource, 'has_completed_briefing')
    && str_contains($scheduleJs, 'is-completed')
    && str_contains($scheduleJs, "evidenceChip('D'")
    && str_contains($scheduleJs, "evidenceChip('F'")
    && str_contains($scheduleJs, "evidenceChip('A'")
    && str_contains($scheduleJs, "evidenceChip('B'");
$checks['resource rescheduling retains dispatch lock'] = static fn(): bool =>
    str_contains($scheduleSource, 'function rescheduleSlot(')
    && str_contains($scheduleSource, 'FOR UPDATE')
    && str_contains($scheduleSource, 'A reservation cannot move after Dispatch is activated.')
    && str_contains($scheduleSource, 'This reservation changed in another session.')
    && str_contains($scheduleSource, 'assertNoResourceConflicts');
$checks['resource scheduler migration adds cohort assignment'] = static fn(): bool =>
    str_contains($resourceMigration, "COLUMN_NAME = 'cohort_id'")
    && str_contains($resourceMigration, 'idx_ipca_flight_schedule_slots_cohort')
    && str_contains($resourceMigration, 'idx_ipca_flight_schedule_slots_aircraft_time');
$checks['scheduled session crew is preferred over carryover'] = static function () use ($iosWorkflow, $dispatchSource, $iosModels): bool {
    return str_contains($iosWorkflow, 'Scheduled sessions must keep the online schedule crew')
        && str_contains($iosWorkflow, 'repairDispatchCrewFromScheduledSessions')
        && str_contains($iosWorkflow, 'repairArchivedDispatchCrewFromScheduledSessions')
        && str_contains($dispatchSource, 'dispatchCrewMatchesScheduledMember')
        && str_contains($iosModels, 'filterToAircraft');
};
$failed = array();
foreach ($checks as $name => $scenario) {
    $passed = false;
    try {
        $passed = $scenario();
    } catch (Throwable) {
        $passed = false;
    }
    echo ($passed ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}
if ($failed !== array()) {
    fwrite(STDERR, 'Failed scheduled Dispatch checks: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}
echo 'OK: scheduled Dispatch contract checks passed.' . PHP_EOL;
