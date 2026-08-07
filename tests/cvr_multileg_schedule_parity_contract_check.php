<?php
declare(strict_types=1);

/**
 * Shared policy: one reservation = one crew set; optional multi-leg airport chain;
 * Transient/Shutdown at mid-route; cross-aircraft drag for unclaimed reservations.
 */

$root = dirname(__DIR__);
$scheduleService = file_get_contents($root . '/src/FlightScheduleService.php') ?: '';
$identity = file_get_contents($root . '/src/CvrOperationalIdentityService.php') ?: '';
$identityRead = file_get_contents($root . '/src/CvrOperationalIdentityReadService.php') ?: '';
$scheduleAdmin = file_get_contents($root . '/public/admin/schedule.php') ?: '';
$scheduleJs = file_get_contents($root . '/public/admin/assets/flight_schedule.js') ?: '';
$workflowStore = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/CVRWorkflowStore.swift') ?: '';
$views = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Views/OperationalWorkflowViews.swift') ?: '';

$failures = array();

function require_contains(string $haystack, string $needle, string $label, array &$failures): void
{
    if (!str_contains($haystack, $needle)) {
        $failures[] = $label . ' (missing: ' . $needle . ')';
    }
}

require_contains($scheduleService, 'function normalizeAirportChain', 'airport chain normalizer', $failures);
require_contains($scheduleService, 'function assertReservationScopedCrew', 'reservation-scoped crew enforcement', $failures);
require_contains($scheduleService, 'Different crew or a PIC role swap requires a separate reservation', 'crew mismatch rejection message', $failures);
require_contains($scheduleService, 'Multi-leg reservations require operational identity', 'multi-leg requires canonical write', $failures);
require_contains($scheduleService, 'expandSlotToDeviceSessions', 'device multi-leg session expansion', $failures);
require_contains($scheduleService, "'airport_chain'", 'payload exposes airport_chain', $failures);
require_contains($scheduleService, '?int $aircraftId = null', 'reschedule accepts aircraft_id', $failures);
require_contains($scheduleService, 'aircraft_id = ?, updated_by = ?', 'reschedule updates aircraft', $failures);

require_contains($identity, "'airport_chain'", 'identity create accepts airport_chain', $failures);
require_contains($identity, '$sequenceNumber = 1', 'reusable leg match accepts sequence', $failures);

require_contains($identityRead, 'Multi-leg is valid', 'dual-read allows multi-leg', $failures);
require_contains($identityRead, 'usort(', 'dual-read sorts legs by sequence', $failures);

require_contains($scheduleAdmin, 'airport_chain[]', 'admin form posts airport_chain', $failures);
require_contains($scheduleAdmin, 'flightAddLegBtn', 'admin add-leg control', $failures);
require_contains($scheduleAdmin, 'Different crew or a PIC role swap requires a separate reservation', 'admin crew policy copy', $failures);
require_contains($scheduleAdmin, 'flightChangeAircraftId', 'reschedule form includes aircraft_id', $failures);
require_contains($scheduleAdmin, '(int)($_POST[\'aircraft_id\'] ?? 0) ?: null', 'admin reschedule posts aircraft', $failures);

require_contains($scheduleJs, 'proposedAircraftId', 'JS tracks target aircraft on drag', $failures);
require_contains($scheduleJs, 'timelineAtPoint', 'JS hit-tests aircraft rows', $failures);
require_contains($scheduleJs, 'openChangeConfirmation(reservation, proposedStart, proposedEnd, proposedAircraftId)', 'JS confirm includes aircraft', $failures);

require_contains($workflowStore, 'isReservationCrewLocked', 'iOS reservation crew lock', $failures);
require_contains($views, 'RESERVATION CREW LOCKED', 'iOS crew lock messaging', $failures);
require_contains($views, 'canCancelLeftoverPlannedLegs', 'cancel leftover legs without continuity', $failures);
require_contains($workflowStore, 'hasRemainingPlannedLegAfterCurrent', 'Transient still gated to remaining legs', $failures);
require_contains($views, 'ENGINE SHUTDOWN', 'Engine Shutdown control present', $failures);
require_contains($views, 'TRANSIENT STOP', 'Transient Stop control present', $failures);
require_contains($views, 'canOfferTransientStop', 'Transient gated in UI', $failures);

if ($failures === array()) {
    fwrite(STDOUT, "PASS cvr_multileg_schedule_parity_contract_check\n");
    exit(0);
}

fwrite(STDERR, "FAIL cvr_multileg_schedule_parity_contract_check\n");
foreach ($failures as $failure) {
    fwrite(STDERR, ' - ' . $failure . "\n");
}
exit(1);
