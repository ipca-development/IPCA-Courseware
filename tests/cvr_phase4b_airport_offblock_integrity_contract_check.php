<?php
declare(strict_types=1);

/**
 * Phase 4B – forward-looking airport + Off Block integrity (new flights only).
 * Static contract check. Does not mutate historical server records.
 */

$root = dirname(__DIR__);
$failures = array();

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

$models = $root . '/ipca-cvr-unit/IPCACVRUnit/Models/CVRWorkflowModels.swift';
$draft = $root . '/ipca-cvr-unit/IPCACVRUnit/Services/CVRLocalDispatchDraft.swift';
$store = $root . '/ipca-cvr-unit/IPCACVRUnit/Services/CVRWorkflowStore.swift';
$identity = $root . '/ipca-cvr-unit/IPCACVRUnit/Services/CVROperationalIdentityLocal.swift';
$upload = $root . '/ipca-cvr-unit/IPCACVRUnit/Services/UploadManager.swift';
$views = $root . '/ipca-cvr-unit/IPCACVRUnit/Views/OperationalWorkflowViews.swift';

// --- Airport integrity ---
require_contains($models, 'Destination airport is required.', 'dispatch blocks blank destination', $failures);
require_contains($models, 'Departure airport is required.', 'dispatch blocks blank departure', $failures);

require_contains($draft, 'Enter the destination airport.', 'draft blocks blank destination', $failures);
require_contains($draft, 'must never blank another leg\'s destination', 'editing one leg preserves others', $failures);
require_contains($draft, 'isValidICAOIdentifier', 'ICAO validation helper', $failures);

require_contains($store, 'plannedDestinationAirport: routeAirports.count >= 2', 'local create supports route-free Operational Sessions', $failures);
require_contains($store, 'destinationAirport: homeAirport', 'local identity defaults same-airport destination', $failures);
require_contains($store, 'Enter the destination airport.', 'open-leg blocks blank destination', $failures);
require_contains($store, 'normalizedAirports.allSatisfy({ !$0.isEmpty })', 'multi-leg refuses blank chain slots', $failures);
require_contains($store, 'preservingNonEmptyAirport', 'check-in preserves destination on dispatch/legs', $failures);
require_contains($store, 'verifiedDestinationAirport = destination', 'check-in persists verified destination', $failures);

require_contains($identity, 'preservingNonEmptyAirport', 'never replace non-empty airport with blank', $failures);
require_contains($identity, 'uppercased()', 'airport identifiers normalized uppercase', $failures);

require_contains($upload, 'Departure and destination airports are required before Dispatch can synchronize.', 'upload refuses blank airports', $failures);
require_contains($upload, 'normalizeAirport(dispatch.plannedDepartureAirport)', 'upload normalizes departure', $failures);
require_contains($upload, 'normalizeAirport(dispatch.plannedDestinationAirport)', 'upload normalizes destination', $failures);

// --- Off Block integrity ---
require_contains($store, 'func recordEngineStartOffBlock(gpsSample: GPSSample?) -> Bool', 'engine start returns persist result', $failures);
require_contains($store, 'Off Block was not saved on this device', 'failed persist surfaces to crew', $failures);
require_contains($store, 'metadata["leg_uuid"]', 'engine start linked to leg_uuid', $failures);
require_contains($store, 'Off Block is not saved on this device yet', 'check-in requires local Off Block', $failures);
require_contains($store, 'synthesizeEngineContinuityIfNeeded(gpsSample: nil)', 'continuity Off Block at dispatch confirm', $failures);
require_absent($store, 'isRecorderVerified else { return false }', 'continuity Off Block not gated on recorder', $failures);
// Continuity synthesize must not require recorder verification (historical defect).
$storeContents = (string)file_get_contents($store);
$synthesizePos = strpos($storeContents, 'func synthesizeEngineContinuityIfNeeded');
if ($synthesizePos === false) {
    $failures[] = 'synthesizeEngineContinuityIfNeeded missing';
} else {
    $snippet = substr($storeContents, $synthesizePos, 900);
    if (str_contains($snippet, 'isRecorderVerified')) {
        $failures[] = 'synthesizeEngineContinuityIfNeeded must not require isRecorderVerified';
    }
}

require_contains($views, 'Persist Off Block before UI confirmation', 'UI confirms after persist', $failures);
require_contains($views, 'let action: () async -> Bool', 'hold button confirms only after persisted async action', $failures);
require_contains($views, 'uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)', 'engine start queues independent upload', $failures);
require_contains($upload, 'requestPayloadSnapshot', 'reconciliation preserves original payload/timestamp', $failures);
require_contains($upload, 'flight_events', 'events upload independently', $failures);

// Snapshot must prefer preserved payload (original Off Block timestamp).
require_contains($upload, 'if let snapshot = component.requestPayloadSnapshot', 'retry uses preserved snapshot', $failures);

foreach (array($models, $draft, $store, $identity, $upload, $views) as $path) {
    $out = array();
    $code = 0;
    exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
    // Swift files: php -l will fail; only lint PHP. Skip non-php.
    if (str_ends_with($path, '.php') && $code !== 0) {
        $failures[] = 'php -l failed for ' . $path . ': ' . implode(' ', $out);
    }
}

if ($failures) {
    fwrite(STDERR, "Phase 4B airport/Off Block integrity contract FAILED\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, ' - ' . $failure . PHP_EOL);
    }
    exit(1);
}

fwrite(STDOUT, "Phase 4B airport/Off Block integrity contract OK\n");
exit(0);
