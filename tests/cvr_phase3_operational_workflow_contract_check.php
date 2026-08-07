#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Phase 3 operational multi-leg workflow static contract check.
 * Verifies iOS surfaces expose Schedule grouping, Transient Stop soft-split,
 * Check-In fields, engine continuity, and per-leg Log columns.
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

function require_absent_marker(string $path, string $needle, string $label, array &$failures): void
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

$catalog = $root . '/ipca-cvr-unit/IPCACVRUnit/Models/CVRCatalogModels.swift';
$models = $root . '/ipca-cvr-unit/IPCACVRUnit/Models/CVRWorkflowModels.swift';
$store = $root . '/ipca-cvr-unit/IPCACVRUnit/Services/CVRWorkflowStore.swift';
$coordinator = $root . '/ipca-cvr-unit/IPCACVRUnit/Services/CVRUnitCoordinator.swift';
$views = $root . '/ipca-cvr-unit/IPCACVRUnit/Views/OperationalWorkflowViews.swift';
$identity = $root . '/ipca-cvr-unit/IPCACVRUnit/Services/CVROperationalIdentityLocal.swift';

require_contains($catalog, 'reservationUUID', 'schedule identity decode', $failures);
require_contains($catalog, 'CVRScheduledReservationGrouping', 'schedule grouping', $failures);
require_contains($catalog, 'legUUID', 'schedule leg identity', $failures);

require_contains($models, 'CVROperationalSessionContext', 'continuity session', $failures);
require_contains($models, 'CVRPlannedLegRecord', 'planned legs', $failures);
require_contains($models, 'CVRCheckInMode', 'check-in mode', $failures);
require_contains($models, 'carryoverCrew', 'next-leg crew carryover', $failures);
require_contains($models, 'carryoverOilQuantity', 'next-leg oil carryover', $failures);
require_contains($models, 'carryoverFuel', 'next-leg fuel carryover', $failures);
require_contains($store, 'carryoverCrew = dispatch.crew', 'persist crew on check-in', $failures);
require_contains($store, 'previousLegCrewCarryover', 'apply previous crew on next dispatch', $failures);
require_contains($store, 'resolvedLegCarryover', 'merged continuity+archive carryover', $failures);
require_contains($store, 'session.carryoverOilQuantity', 'apply previous oil on continuity', $failures);
require_contains($store, 'backfillDispatchCarryoverIfNeeded', 'dispatch on-appear carryover backfill', $failures);
require_contains($store, 'serverFuelUSG', 'server fuel applied to dispatch carryover', $failures);
require_contains($views, 'backfillDispatchCarryoverIfNeeded', 'dispatch page invokes carryover backfill', $failures);
require_contains($views, 'refreshFuelState', 'dispatch page refreshes server fuel state', $failures);
require_contains($views, 'ENGINE SESSION CONTINUING', 'continuity carryover messaging', $failures);
require_contains($views, 'Engine Was Shut Down', 'continuity mistaken-stop recovery hint', $failures);
require_contains($store, 'Phase 3 operational flight-test: no crew consent gate', 'phase3 consent gate off', $failures);
require_contains($store, 'ensuredOperationalConsents', 'phase3 operational consent mint', $failures);
require_contains($store, 'phase3_operational_flight_test_waiver', 'phase3 consent text version', $failures);
require_contains($store, 'repairArchivedDispatchConsents', 'archive consent repair for log retry', $failures);
require_contains($store, 'requestPayloadSnapshot = nil', 'clear failed consent snapshot on repair', $failures);

require_contains($store, 'createLocalMultiLegReservation', 'local multi-leg create', $failures);
require_contains($store, 'completeTransientStopLocally', 'transient stop complete', $failures);
require_contains($store, 'completeEngineShutdownAfterAvionicsOff', 'engine shutdown complete', $failures);
require_contains($store, 'endEngineContinuityPreservingUnusedLegs', 'end false continuity keep unused legs', $failures);
require_contains($store, 'cancelUnusedPlannedLegsAndEndSession', 'cancel unused legs without blocking uploads', $failures);
require_contains($store, 'convertTransientStopToEngineShutdown', 'convert mistaken transient stop', $failures);
require_contains($store, 'clearFalseContinuityOnActiveLeg', 'clear synthesized continuity off block', $failures);
require_contains($store, 'hasOpenPlannedLegs', 'preserve unused legs after engine shutdown', $failures);
require_contains($views, 'ENGINE WAS SHUT DOWN', 'schedule recovery for mistaken transient', $failures);
require_contains($views, 'CANCEL REMAINING LEGS', 'schedule cancel unused legs', $failures);
require_contains($views, 'canCancelLeftoverPlannedLegs', 'cancel leftover legs without requiring continuity', $failures);
require_contains($views, 'LOCAL PLANNED LEGS REMAIN', 'warn when local planned legs outlive online schedule', $failures);
require_contains($store, 'isReservationCrewLocked', 'reservation-scoped crew lock', $failures);
require_contains($views, 'RESERVATION CREW LOCKED', 'crew lock messaging', $failures);
require_contains($views, 'ACTUALLY ENGINE SHUTDOWN', 'in-flight convert transient', $failures);
require_contains($store, 'saveCheckInValues', 'check-in save', $failures);
require_contains($store, 'synthesizeEngineContinuityIfNeeded', 'continuity engine start', $failures);
require_contains($store, 'transientStop', 'transient mode enum use', $failures);

require_contains($coordinator, 'finalizeRecordingForLegBoundary', 'soft-split finalize', $failures);
require_contains($coordinator, 'softStartRecordingIfAvionicsOn', 'soft-start recording', $failures);

require_contains($store, 'transient_stop_on_block', 'transient on-block event', $failures);
require_contains($store, 'recordTransientStopOnBlock', 'transient stop record', $failures);
require_contains($store, 'hasRemainingPlannedLegAfterCurrent', 'transient only when next leg remains', $failures);
require_contains($views, 'canOfferTransientStop', 'in-flight gates transient stop', $failures);
require_contains($views, 'takeoffLandingControls(metrics:', 'takeoffs/landings after safety event', $failures);
require_contains($store, '0.70', 'tacho estimate factor', $failures);
require_contains($store, 'currentLegIndex', 'current leg index', $failures);
require_contains($views, 'Hold 3 seconds — end leg', 'transient stop hold UI', $failures);
require_contains($views, 'largeMeterField("TACHO"', 'check-in tacho left', $failures);
require_contains($views, 'largeMeterField("HOBBS"', 'check-in hobbs right', $failures);
require_contains($views, 'case .meters:', 'dispatch meters editor', $failures);
require_contains($views, 'Leg stored safely on this device.', 'local store confirmation', $failures);
require_contains($views, 'CheckInView', 'check-in UI', $failures);
require_contains($views, 'CREATE LOCAL DISPATCH', 'local dispatch entry', $failures);
require_contains($views, 'LocalMultiLegDispatchSheet', 'multi-leg sheet', $failures);
require_contains($views, 'Select Flight Mission', 'local dispatch mission picker', $failures);
require_contains($views, 'CVRMissionPickerSheet', 'mission picker uses scrollable sheet', $failures);
require_contains($views, 'ADD LEG', 'local dispatch add leg', $failures);
require_absent_marker($views, 'Single-leg Dispatch only', 'single-leg toggle removed', $failures);
require_contains($views, 'DISPATCH FLIGHT', 'dispatch flight button', $failures);
require_contains($views, 'Tap to add crew', 'crew block empty hint', $failures);
require_contains($views, 'routeOverview', 'dispatch route overview', $failures);
require_absent_marker($views, 'Edit Dispatch', 'edit dispatch button removed', $failures);
require_contains($views, '"DISPATCH"', 'log dispatch column', $failures);
require_contains($views, '"AUDIO"', 'log audio column', $failures);
require_contains($views, '"TRANSCRIPT"', 'log transcript column', $failures);
require_contains($views, 'FUEL REMAINING', 'check-in fuel field', $failures);
require_contains($views, 'CVRFluidCylinderPicker', 'check-in fuel slider', $failures);
require_contains($views, 'AIRPORT OF ARRIVAL', 'check-in arrival airport', $failures);
require_contains($views, 'largeAirportField("ARR AD"', 'check-in arrival field sizing', $failures);

require_contains($identity, 'createOfflineMultiLegBundles', 'multi-leg identity mint', $failures);

// Guard: Check-In must not collect oil.
$viewsText = is_file($views) ? file_get_contents($views) : '';
$checkInStart = strpos($viewsText, 'private struct CheckInView: View {');
$checkInEnd = $checkInStart === false ? false : strpos($viewsText, "\nstruct GarminWorkflowView", $checkInStart);
if ($checkInStart === false || $checkInEnd === false) {
    $failures[] = 'check-in UI slice could not be isolated';
} else {
    $checkInSlice = substr($viewsText, $checkInStart, $checkInEnd - $checkInStart);
    if (preg_match('/\bOil\b|\bOIL\b/', $checkInSlice)) {
        $failures[] = 'check-in UI must not include oil fields';
    }
    foreach (['FUEL REMAINING', 'AIRPORT OF ARRIVAL', 'ARR AD', 'TAKEOFFS', 'LANDINGS', 'Comments', 'Hobbs', 'Tacho', 'CVRFluidCylinderPicker'] as $field) {
        if (!str_contains($checkInSlice, $field)) {
            $failures[] = "check-in UI missing field marker: {$field}";
        }
    }
}

if ($failures) {
    fwrite(STDERR, "Phase 3 contract FAILED\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "Phase 3 contract OK\n";
exit(0);
