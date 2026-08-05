#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Create Local Dispatch UI correction — static contract check.
 * Guards against regression of free-form mission, comma airports, and single-leg toggle.
 */

$root = dirname(__DIR__);
$failures = [];

function require_contains(string $path, string $needle, string $label, array &$failures): void
{
    $contents = @file_get_contents($path);
    if ($contents === false) {
        $failures[] = "missing file: {$path}";
        return;
    }
    if (!str_contains($contents, $needle)) {
        $failures[] = "{$label}: expected `{$needle}` in {$path}";
    }
}

function require_absent(string $path, string $needle, string $label, array &$failures): void
{
    $contents = @file_get_contents($path);
    if ($contents === false) {
        $failures[] = "missing file: {$path}";
        return;
    }
    if (str_contains($contents, $needle)) {
        $failures[] = "{$label}: unexpected `{$needle}` in {$path}";
    }
}

$views = $root . '/ipca-cvr-unit/IPCACVRUnit/Views/OperationalWorkflowViews.swift';
$draft = $root . '/ipca-cvr-unit/IPCACVRUnit/Services/CVRLocalDispatchDraft.swift';
$catalog = $root . '/ipca-cvr-unit/IPCACVRUnit/Services/MissionCatalogStore.swift';
$store = $root . '/ipca-cvr-unit/IPCACVRUnit/Services/CVRWorkflowStore.swift';
$identity = $root . '/ipca-cvr-unit/IPCACVRUnit/Services/CVROperationalIdentityLocal.swift';
$pbx = $root . '/ipca-cvr-unit/IPCACVRUnit.xcodeproj/project.pbxproj';
$swiftTest = $root . '/tests/cvr_local_dispatch_ui_contract_check.swift';

require_contains($draft, 'struct CVRLocalDispatchDraft', 'draft model', $failures);
require_contains($draft, 'isAircraftFlightMission(code:', 'flight mission filter', $failures);
require_contains($draft, 'func addLeg()', 'add leg', $failures);
require_contains($draft, 'func eraseLeg', 'erase leg', $failures);
require_contains($draft, 'reapplyContinuity', 'route continuity', $failures);
require_contains($draft, 'Select a flight mission.', 'plain mission validation', $failures);
require_contains($draft, 'Airport code must be a valid ICAO identifier.', 'plain ICAO validation', $failures);
require_contains($draft, 'persistenceKey', 'offline draft persistence', $failures);

require_contains($catalog, 'var flightMissions', 'catalog flight filter surface', $failures);
require_contains($catalog, 'static func chronological', 'missions sorted by program/stage/phase/scenario', $failures);

require_contains($views, 'LocalMultiLegDispatchSheet', 'create local sheet', $failures);
require_contains($views, 'CREATE LOCAL DISPATCH', 'create local entry', $failures);
require_contains($views, 'Select Flight Mission', 'mission picker prompt', $failures);
require_contains($views, 'CVRMissionPickerSheet', 'scrollable mission picker sheet', $failures);
require_contains($catalog, 'replaceMissions', 'catalog skips identical republish', $failures);
require_contains($views, 'ADD LEG', 'add leg control', $failures);
require_contains($views, '"ERASE"', 'swipe erase label', $failures);
require_contains($views, 'DEP AD', 'departure field label', $failures);
require_contains($views, 'ARR AD', 'arrival field label', $failures);
require_contains($views, 'missionCatalog.flightMissions', 'filtered missions only', $failures);
require_contains($views, 'draft.legUUIDs', 'passes draft leg UUIDs', $failures);
require_contains($views, 'CVRLocalDispatchDraft.clear()', 'clears previous draft on open/cancel/create', $failures);
require_contains($views, 'CVRLocalDispatchDraft.fresh(homeAirport:', 'create local starts empty/fresh', $failures);
require_absent($views, 'CVRLocalDispatchDraft.load()', 'create local must not restore previous route draft', $failures);
require_contains($store, 'dispatchSource: "local_multileg_reservation"', 'local multi-leg create path', $failures);

require_absent($views, 'Mission code (optional)', 'free-form mission removed', $failures);
require_absent($views, 'KTRM, KPSP, KBUR, KTRM', 'comma airport field removed', $failures);
require_absent($views, 'Single-leg Dispatch only', 'single-leg toggle removed', $failures);
require_absent($views, 'createSingleLeg', 'single-leg mode flag removed', $failures);
require_absent($views, 'AIRPORTS IN ORDER', 'comma route section removed', $failures);

require_contains($store, 'legUUIDs: [String]? = nil', 'create accepts draft leg UUIDs', $failures);
require_contains($store, 'reservationUUID: String? = nil', 'create accepts draft reservation UUID', $failures);
require_contains($identity, 'legUUIDs: [String]? = nil', 'identity mint accepts leg UUIDs', $failures);

// New local Dispatch must start with empty crew (next-leg continuity still carries crew separately).
$storeText = is_file($store) ? file_get_contents($store) : '';
$multiStart = strpos($storeText, 'func createLocalMultiLegReservation(');
$multiEnd = $multiStart === false ? false : strpos($storeText, "\n    func openDispatchFromScheduledSession(", $multiStart);
if ($multiStart === false || $multiEnd === false) {
    $failures[] = 'createLocalMultiLegReservation slice could not be isolated';
} else {
    $multiSlice = substr($storeText, $multiStart, $multiEnd - $multiStart);
    if (!str_contains($multiSlice, 'crew: [],')) {
        $failures[] = 'createLocalMultiLegReservation must start with empty crew';
    }
    if (str_contains($multiSlice, 'previousLegCrewCarryover')) {
        $failures[] = 'createLocalMultiLegReservation must not prefill crew from previous flights';
    }
}
require_contains($store, 'Crew is only backfilled during an active continuous engine session', 'crew backfill gated to continuity', $failures);

require_contains($pbx, 'CVRLocalDispatchDraft.swift', 'xcode includes draft source', $failures);
require_contains($swiftTest, 'Create Local Dispatch UI contract', 'focused swift test present', $failures);

// Isolate LocalMultiLegDispatchSheet: no free-form TextField for mission code.
$viewsText = is_file($views) ? file_get_contents($views) : '';
$start = strpos($viewsText, 'private struct LocalMultiLegDispatchSheet: View {');
$end = $start === false ? false : strpos($viewsText, "\nstruct DispatchWorkflowView", $start);
if ($start === false || $end === false) {
    $failures[] = 'LocalMultiLegDispatchSheet slice could not be isolated';
} else {
    $slice = substr($viewsText, $start, $end - $start);
    if (preg_match('/TextField\("Mission/i', $slice)) {
        $failures[] = 'LocalMultiLegDispatchSheet must not use free-form Mission TextField';
    }
    if (str_contains($slice, 'axis: .vertical') && str_contains($slice, 'airportsText')) {
        $failures[] = 'LocalMultiLegDispatchSheet must not use comma-separated airports TextField';
    }
    if (!str_contains($slice, 'swipeActions')) {
        $failures[] = 'LocalMultiLegDispatchSheet must use swipeActions for ERASE';
    }
    if (!str_contains($slice, 'showMissionPicker')) {
        $failures[] = 'LocalMultiLegDispatchSheet must open scrollable mission picker sheet';
    }
    if (str_contains($slice, 'Menu {')) {
        $failures[] = 'LocalMultiLegDispatchSheet must not use Menu mission dropdown';
    }
}

if ($failures) {
    fwrite(STDERR, "Create Local Dispatch UI contract FAILED\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "Create Local Dispatch UI contract OK\n");
exit(0);
