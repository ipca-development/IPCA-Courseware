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
require_contains($models, 'engineSessionContinuityActive', 'engine continuity flag', $failures);
require_contains($models, 'verifiedDestinationAirport', 'check-in destination', $failures);
require_contains($models, 'checkInComments', 'check-in comments', $failures);

require_contains($store, 'createLocalMultiLegReservation', 'local multi-leg create', $failures);
require_contains($store, 'completeTransientStopLocally', 'transient stop complete', $failures);
require_contains($store, 'completeEngineShutdownAfterAvionicsOff', 'engine shutdown complete', $failures);
require_contains($store, 'saveCheckInValues', 'check-in save', $failures);
require_contains($store, 'synthesizeEngineContinuityIfNeeded', 'continuity engine start', $failures);
require_contains($store, 'transientStop', 'transient mode enum use', $failures);

require_contains($coordinator, 'finalizeRecordingForLegBoundary', 'soft-split finalize', $failures);
require_contains($coordinator, 'softStartRecordingIfAvionicsOn', 'soft-start recording', $failures);

require_contains($store, 'transient_stop_on_block', 'transient on-block event', $failures);
require_contains($store, 'recordTransientStopOnBlock', 'transient stop record', $failures);
require_contains($store, '0.70', 'tacho estimate factor', $failures);
require_contains($store, 'currentLegIndex', 'current leg index', $failures);
require_contains($views, 'Hold 3 seconds — end leg', 'transient stop hold UI', $failures);
require_contains($views, 'largeMeterField("TACHO"', 'check-in tacho left', $failures);
require_contains($views, 'largeMeterField("HOBBS"', 'check-in hobbs right', $failures);
require_contains($views, 'Leg stored safely on this device.', 'local store confirmation', $failures);
require_contains($views, 'CheckInView', 'check-in UI', $failures);
require_contains($views, 'CREATE LOCAL DISPATCH', 'local dispatch entry', $failures);
require_contains($views, 'LocalMultiLegDispatchSheet', 'multi-leg sheet', $failures);
require_contains($views, '"DISPATCH"', 'log dispatch column', $failures);
require_contains($views, '"AUDIO"', 'log audio column', $failures);
require_contains($views, '"TRANSCRIPT"', 'log transcript column', $failures);
require_contains($views, 'Fuel Remaining', 'check-in fuel field', $failures);

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
    foreach (['Fuel Remaining', 'Destination', 'TAKEOFFS', 'LANDINGS', 'Comments', 'Hobbs', 'Tacho'] as $field) {
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
