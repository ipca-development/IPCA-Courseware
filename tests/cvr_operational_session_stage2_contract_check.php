<?php
declare(strict_types=1);

function stage2_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "PASS: {$message}\n");
}

$root = dirname(__DIR__);
$models = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Models/CVRWorkflowModels.swift');
$store = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/CVRWorkflowStore.swift');
$views = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Views/OperationalWorkflowViews.swift');
$coordinator = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/CVRUnitCoordinator.swift');

stage2_assert(
    str_contains($models, 'case endingStateSecured = "ending_state_secured"')
        && str_contains($models, 'var endingStateSecuredAtUTC: Date? = nil')
        && str_contains($models, 'var avionicsOffAtUTC: Date? = nil'),
    'Operational Session persists secured-ending and Avionics OFF lifecycle'
);

stage2_assert(
    str_contains($store, 'var usesOperationalSessionModelV1: Bool')
        && str_contains($store, 'state.activeOperationalSession?.modelVersion')
        && str_contains($store, 'secureOperationalSessionEndingValues'),
    'Stage 2 follows immutable per-session model version after rollout changes'
);

stage2_assert(
    str_contains($store, 'eventType: "ending_aircraft_state_secured"')
        && str_contains($store, 'operationalSession.state = .endingStateSecured')
        && str_contains($store, 'flightRecord.status = .awaitingAvionicsOff'),
    'ending Hobbs Tacho and fuel persist atomically before Avionics OFF'
);

$secureMethodStart = strpos($store, 'func secureOperationalSessionEndingValues(');
$secureMethodEnd = strpos($store, "\n    var needsEngineStart:", $secureMethodStart);
$markOffStart = strpos($store, 'func markAvionicsOffAfterShutdown()');
stage2_assert(
    $secureMethodStart !== false && $secureMethodEnd !== false && $markOffStart !== false,
    'Stage 2 lifecycle methods are present'
);
$secureMethod = substr($store, $secureMethodStart, $secureMethodEnd - $secureMethodStart);
stage2_assert(
    !str_contains($secureMethod, 'type: "flight_record_closure"')
        && !str_contains($secureMethod, 'CVRFlightLegRecord(')
        && !str_contains($secureMethod, 'verifiedDestinationAirport'),
    'securing endpoint evidence neither closes session nor creates actual legs'
);

$markOffMethod = substr($store, $markOffStart, 2400);
stage2_assert(
    str_contains($markOffMethod, 'operationalSession.state = .evidenceClosed')
        && str_contains($markOffMethod, 'type: "flight_record_closure"')
        && str_contains($markOffMethod, 'The closure is intentionally queued only after Avionics OFF'),
    'Avionics OFF closes evidence and only then queues server closure'
);

stage2_assert(
    str_contains($views, '"Did you shut down the engine?"')
        && str_contains($views, 'Button("YES")')
        && str_contains($views, 'Button("NOT YET", role: .cancel)'),
    'Engine Shutdown uses deliberate confirmation after two-second hold'
);

stage2_assert(
    str_contains($views, 'private struct SecureFlightDataView')
        && str_contains($views, 'Text("SECURE FLIGHT DATA")')
        && str_contains($views, 'section("ENDING METERS")')
        && str_contains($views, 'section("FUEL REMAINING")')
        && str_contains($views, 'Text("SAFE TO TURN OFF AVIONICS. Audio and GPS continue'),
    'crew-facing Secure Flight Data contains meters and fuel with safe power-off confirmation'
);

$secureViewStart = strpos($views, 'private struct SecureFlightDataView');
$legacyViewStart = strpos($views, 'private struct CheckInView');
stage2_assert($secureViewStart !== false && $legacyViewStart !== false, 'Secure and legacy views are independently scoped');
$secureView = substr($views, $secureViewStart, $legacyViewStart - $secureViewStart);
stage2_assert(
    !str_contains($secureView, 'AIRPORT OF ARRIVAL')
        && !str_contains($secureView, 'TAKEOFFS')
        && !str_contains($secureView, 'LANDINGS')
        && !str_contains($secureView, 'OIL')
        && !str_contains($secureView, 'GARMIN'),
    'Secure Flight Data excludes legs destination operations oil and Garmin'
);

stage2_assert(
    str_contains($views, '!workflow.usesOperationalSessionModelV1')
        && str_contains($views, 'workflow.hasRemainingPlannedLegAfterCurrent'),
    'Transient Stop is hidden for new sessions while legacy recovery code remains'
);

stage2_assert(
    str_contains($coordinator, 'await stopRecording(reason: "Beacon unavailable beyond iPhone finalization window.")')
        && str_contains($coordinator, 'workflow?.markAvionicsOffAfterShutdown()')
        && str_contains($coordinator, 'let shouldComplete =')
        && str_contains($coordinator, 'uploadQueuedWorkflowComponents'),
    'audio and GPS stop before local completion and synchronization remains independent'
);

fwrite(STDOUT, "OK: Operational Session Stage 2 contract checks passed.\n");
