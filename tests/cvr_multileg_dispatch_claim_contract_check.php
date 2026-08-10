<?php
declare(strict_types=1);

/**
 * Multi-leg schedule claim: device sessions are per-hop (A→B, B→C) while the
 * schedule slot stores first→last (A→C). Claim must accept any planned hop and
 * allow later-leg Dispatches to share the same claimed slot.
 */

$root = dirname(__DIR__);
$dispatchSource = file_get_contents($root . '/src/CvrDispatchIntakeService.php') ?: '';
$scheduleSource = file_get_contents($root . '/src/FlightScheduleService.php') ?: '';

$failures = array();

function require_contains(string $haystack, string $needle, string $label, array &$failures): void
{
    if (!str_contains($haystack, $needle)) {
        $failures[] = $label . ' (missing: ' . $needle . ')';
    }
}

function require_not_contains(string $haystack, string $needle, string $label, array &$failures): void
{
    if (str_contains($haystack, $needle)) {
        $failures[] = $label . ' (unexpected: ' . $needle . ')';
    }
}

require_contains($scheduleSource, 'expandSlotToDeviceSessions', 'device expands multi-leg slots per hop', $failures);
require_contains($dispatchSource, 'function dispatchAirportsMatchScheduledPlan', 'claim matches planned hops', $failures);
require_contains($dispatchSource, 'function scheduledAirportHops', 'claim loads scheduled hops', $failures);
require_contains($dispatchSource, 'function isSiblingLegDispatchForSlot', 'claim allows sibling-leg ownership', $failures);
require_contains($dispatchSource, 'listLegsForReservation', 'claim reads canonical reservation legs', $failures);
require_contains($dispatchSource, 'scheduler_record_id', 'sibling claim keyed by scheduler record', $failures);
require_contains(
    $dispatchSource,
    'Keep the original claim owner; later legs share the same scheduled reservation.',
    'sibling claim does not overwrite claimed_dispatch_uuid',
    $failures
);
$schedulerIndexSql = file_get_contents($root . '/scripts/sql/2026_08_08_multileg_dispatch_scheduler_index.sql') ?: '';
require_contains(
    $schedulerIndexSql,
    'DROP INDEX uk_ipca_cvr_dispatches_scheduler',
    'migration drops single-leg-only scheduler unique index',
    $failures
);
require_contains(
    $schedulerIndexSql,
    'uk_ipca_cvr_dispatches_scheduler_flight',
    'migration uniques scheduler+flight_record for multi-leg',
    $failures
);

// Regression: old first→last-only equality loop must not remain as the sole airport check.
require_not_contains(
    $dispatchSource,
    "foreach (array('planned_departure_airport', 'planned_destination_airport') as \$field) {\n"
    . "            \$scheduledAirport = strtoupper(trim((string)(\$slot[\$field] ?? '')));\n"
    . "            if (\$scheduledAirport !== '' && \$scheduledAirport !== (string)\$normalized[\$field]) {\n"
    . "                throw new CvrUserCorrectionRequired('Scheduled session airports do not match the Dispatch.');",
    'legacy first→last-only airport equality removed',
    $failures
);

require_once $root . '/src/CvrDispatchIntakeService.php';
$service = (new ReflectionClass(CvrDispatchIntakeService::class))->newInstanceWithoutConstructor();

$matchMethod = new ReflectionMethod(CvrDispatchIntakeService::class, 'dispatchAirportsMatchScheduledPlan');
$hopsMethod = new ReflectionMethod(CvrDispatchIntakeService::class, 'scheduledAirportHops');

$slot = array(
    'id' => 42,
    'organization_id' => 1,
    'scheduler_record_id' => '11111111-1111-4111-8111-111111111111',
    'planned_departure_airport' => 'KTRM',
    'planned_destination_airport' => 'KTRM',
);

// Without identity legs, only first→last is available — leg1 A→B must NOT match A→C alone.
// Inject hops via a thin subclass test by temporarily stubbing is not available; instead
// verify the pure matching helper against explicit hop lists by calling match with a slot
// that only has first→last, then verify match accepts when departure/destination equal slot.

$fullRouteMatch = $matchMethod->invoke(
    $service,
    $slot,
    array(
        'planned_departure_airport' => 'KTRM',
        'planned_destination_airport' => 'KTRM',
    )
);
if ($fullRouteMatch !== true) {
    $failures[] = 'full-route Dispatch must match slot first→last airports';
}

$leg1Mismatch = $matchMethod->invoke(
    $service,
    $slot,
    array(
        'planned_departure_airport' => 'KTRM',
        'planned_destination_airport' => 'KPSP',
    )
);
// Without identity legs, KTRM→KPSP should fail against KTRM→KTRM-only slot hops.
if ($leg1Mismatch !== false) {
    $failures[] = 'without identity hops, intermediate leg airports must not falsely match first→last-only slot';
}

$hops = $hopsMethod->invoke($service, $slot);
if (!is_array($hops) || $hops === array()) {
    $failures[] = 'scheduledAirportHops must always include slot first→last when present';
} else {
    $found = false;
    foreach ($hops as $hop) {
        if (($hop['departure'] ?? '') === 'KTRM' && ($hop['destination'] ?? '') === 'KTRM') {
            $found = true;
            break;
        }
    }
    if (!$found) {
        $failures[] = 'scheduledAirportHops must include slot first→last hop';
    }
}

// Direct hop-match behavior: simulate identity-expanded hops by matching against a slot
// whose planned airports already equal a single hop (single-leg expansion case).
$legSlot = array(
    'id' => 43,
    'organization_id' => 1,
    'scheduler_record_id' => '22222222-2222-4222-8222-222222222222',
    'planned_departure_airport' => 'KTRM',
    'planned_destination_airport' => 'KPSP',
);
$legMatch = $matchMethod->invoke(
    $service,
    $legSlot,
    array(
        'planned_departure_airport' => 'KTRM',
        'planned_destination_airport' => 'KPSP',
    )
);
if ($legMatch !== true) {
    $failures[] = 'per-leg Dispatch airports must match the corresponding planned hop';
}

if ($failures === array()) {
    fwrite(STDOUT, "PASS cvr_multileg_dispatch_claim_contract_check\n");
    exit(0);
}

fwrite(STDERR, "FAIL cvr_multileg_dispatch_claim_contract_check\n");
foreach ($failures as $failure) {
    fwrite(STDERR, ' - ' . $failure . "\n");
}
exit(1);
