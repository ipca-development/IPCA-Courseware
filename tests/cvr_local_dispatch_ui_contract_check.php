#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$views = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Views/OperationalWorkflowViews.swift') ?: '';
$store = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/CVRWorkflowStore.swift') ?: '';
$catalog = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/MissionCatalogStore.swift') ?: '';
$failures = array();

function local_require(bool $condition, string $message, array &$failures): void
{
    if (!$condition) {
        $failures[] = $message;
    }
}

local_require(str_contains($views, 'private struct LocalDispatchSheet: View'), 'route-free LocalDispatchSheet missing', $failures);
local_require(str_contains($views, 'subtitle: "Customer, crew and mission · route-free"'), 'route-free entry caption missing', $failures);
local_require(str_contains($views, 'Select Flight Mission'), 'mission picker prompt missing', $failures);
local_require(str_contains($views, 'CVRMissionPickerSheet'), 'scrollable mission picker missing', $failures);
local_require(str_contains($catalog, 'var flightMissions'), 'flight mission filtering missing', $failures);
local_require(str_contains($views, 'Text("INFORMATIVE ROUTE (OPTIONAL)")'), 'optional informative route missing', $failures);
local_require(str_contains($views, 'Planning information only. The actual flown legs will be derived from session evidence.'), 'informative route explanation missing', $failures);
foreach (array(
    'DatePicker("DATE"',
    'CVRReservationTimePickerRow(title: "START"',
    'CVRReservationTimePickerRow(title: "END"',
    'picker.minuteInterval = 15',
    'formatter.dateFormat = "HH:mm"',
    'Schedule End must be later than Schedule Start.',
    'scheduledStartTime: resolvedScheduleWindow.start',
    'scheduledEndTime: resolvedScheduleWindow.end',
) as $required) {
    local_require(str_contains($views, $required), "required schedule window behavior missing: {$required}", $failures);
}
local_require(substr_count($views, 'showLocalDispatchSheet = true') >= 2, 'all Local Dispatch entry points must use the complete sheet', $failures);
local_require(!str_contains($views, 'CREATE LOCAL DISPATCH'), 'legacy Create Local Dispatch label remains visible', $failures);
foreach (array(
    'ADD RESERVATION',
    'SCHEDULED DEPARTURE',
    'SCHEDULED ARRIVAL',
    'ScheduleWindowEditorSheet',
    'updateActiveScheduleWindow(start: newStart, end: newEnd)',
    'InformativeRouteEditorSheet',
    'updateActiveInformativeRoute(airports: updatedAirports)',
    'ROUTE · INFORMATIVE',
    'TAP TO EDIT',
    'reservationHasOverlap(group)',
    'Text("OVERLAP")',
    'scheduledWindowSummary(group)',
    'SCHEDULED DEPARTURE',
    'SCHEDULED ARRIVAL',
) as $required) {
    local_require(str_contains($views, $required), "Dispatch schedule-time editor missing: {$required}", $failures);
}

foreach (array(
    'localCrewPanel("CUSTOMER")',
    'localCrewPanel("PERSON 2 (OPTIONAL)")',
    'localCrewPanel("PERSON 3 (OPTIONAL)")',
    'Button("Instructor")',
    'Button("Pilot Monitoring")',
    'Button("Safety Pilot")',
    'Button("Examiner")',
    'Button("Supervising Instructor")',
    'Button("Observer")',
) as $required) {
    local_require(str_contains($views, $required), "scheduler-equivalent crew option missing: {$required}", $failures);
}

local_require(str_contains($store, 'missionCode: String = ""'), 'route-free local create must accept mission', $failures);
local_require(str_contains($store, 'crew: [CVRCrewAssignment] = []'), 'route-free local create must accept crew', $failures);
local_require(str_contains($store, 'informativeRouteAirports: [String] = []'), 'local create must accept optional informative route', $failures);
local_require(str_contains($store, 'forceNewReservation: Bool = false'), 'local create must support explicit fresh assignment', $failures);
local_require(str_contains($store, 'scheduledStartTime: Date? = nil'), 'local create must accept schedule start', $failures);
local_require(str_contains($store, 'scheduledEndTime: Date? = nil'), 'local create must accept schedule end', $failures);

$storeStart = strpos($store, 'func createOrOpenLocalDispatch(');
$storeEnd = $storeStart === false ? false : strpos($store, "\n    /// Create a local multi-leg reservation", $storeStart);
local_require($storeStart !== false && $storeEnd !== false, 'createOrOpenLocalDispatch slice unavailable', $failures);
if ($storeStart !== false && $storeEnd !== false) {
    $slice = substr($store, $storeStart, $storeEnd - $storeStart);
    local_require(str_contains($slice, 'missionCode: missionCode.trimmingCharacters'), 'selected mission is not persisted', $failures);
    local_require(str_contains($slice, 'crew: crew,'), 'selected canonical crew is not persisted', $failures);
    local_require(str_contains($slice, 'informativeRouteAirports: routeAirports'), 'informative route is not persisted as planning data', $failures);
    local_require(str_contains($slice, '$0.operationalSession = nil'), 'previous planned-leg context is not cleared', $failures);
    local_require(str_contains($slice, '$0.activeOperationalSession = nil'), 'previous Operational Session identity is not cleared', $failures);
    local_require(str_contains($slice, 'if !forceNewReservation,'), 'existing Dispatch shortcut is not bypassable', $failures);
    local_require(str_contains($slice, '? UUID().uuidString.lowercased()'), 'new Local Dispatch does not mint a new reservation', $failures);
    local_require(str_contains($slice, 'schedulerRecordID: schedulerRecordID'), 'stable scheduler identity is not persisted', $failures);
    local_require(str_contains($slice, 'Self.queueLocalScheduleCreation'), 'scheduler create is not queued atomically with Local Dispatch', $failures);
}
local_require(str_contains($store, '"operation": "create"'), 'queued scheduler create operation missing', $failures);
local_require(str_contains($store, '"scheduler_record_id": schedulerRecordID.lowercased()'), 'frozen scheduler UUID missing', $failures);
local_require(str_contains($store, '"scheduled_start_time": scheduleLocalTimestampString(start)'), 'frozen schedule start missing', $failures);
local_require(str_contains($store, 'requestPayloadSnapshot: snapshot'), 'durable queued payload snapshot missing', $failures);
local_require(str_contains($store, '@Published private(set) var scheduleRefreshRevision'), 'post-sync schedule refresh signal missing', $failures);
local_require(str_contains($views, '.onChange(of: workflow.scheduleRefreshRevision)'), 'schedule cache refresh listener missing', $failures);

$viewStart = strpos($views, 'private struct LocalDispatchSheet: View {');
$viewEnd = $viewStart === false ? false : strpos($views, "\n/// Edit planned legs", $viewStart);
local_require($viewStart !== false && $viewEnd !== false, 'LocalDispatchSheet slice unavailable', $failures);
if ($viewStart !== false && $viewEnd !== false) {
    $slice = substr($views, $viewStart, $viewEnd - $viewStart);
    foreach (array('createLocalMultiLegReservation', 'plannedLegs:') as $forbidden) {
        local_require(!str_contains($slice, $forbidden), "planned-leg input leaked into Local Dispatch: {$forbidden}", $failures);
    }
    local_require(str_contains($slice, 'ADD INFORMATIVE LEG'), 'informative leg editor missing', $failures);
    local_require(str_contains($slice, 'informativeRouteAirports: informativeAirportChain'), 'informative airport chain not passed to route-free creator', $failures);
    local_require(str_contains($slice, 'forceNewReservation: true'), 'Local Dispatch form can reopen stale crew instead of creating fresh', $failures);
    foreach (array(
        'role: .student',
        'pilotFunction: .pilotFlying',
        'pilotFunction: .pilotMonitoring',
        'role: personThreeRole',
        'pilotFunction: .none',
        'Select one or two pilots logging PIC.',
        'Each position must use a different user account.',
    ) as $required) {
        local_require(str_contains($slice, $required), "canonical local crew rule missing: {$required}", $failures);
    }
}

if ($failures !== array()) {
    fwrite(STDERR, "Create Local Dispatch UI contract FAILED\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "Create Local Dispatch UI contract OK\n");
