<?php
declare(strict_types=1);

/**
 * Contract: Master Logbook can preview/apply Define Legs (annotate, not physical split).
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

$service = $root . '/src/CvrAdminLegSplitService.php';
$page = $root . '/public/admin/master_logbook_intake.php';
$api = $root . '/public/admin/api/operational_leg_split_preview.php';
$correction = $root . '/src/CvrAdminLegCorrectionService.php';
$intake = $root . '/src/CvrDataIntakeReadService.php';
$schedule = $root . '/src/FlightScheduleService.php';

require_contains($service, 'class CvrAdminLegSplitService', 'split service', $failures);
require_contains($service, 'function preview', 'preview method', $failures);
require_contains($service, 'function apply', 'apply method', $failures);
require_contains($service, 'persistLegSegments', 'stores leg_segments on one dispatch', $failures);
require_contains($service, 'viaAirportsFromSegments', 'via airports helper', $failures);
require_contains($service, 'operational_leg.annotate_legs', 'annotate audit action', $failures);
require_contains($service, 'detectCsvGroundStops', 'CSV ground-stop detection', $failures);
require_contains($service, 'detectCvrLandingCycles', 'CVR landing-cycle detection', $failures);
require_contains($service, 'planned_hops', 'planned hop preference', $failures);
require_contains($service, 'hobbs_delta', 'hobbs delta on proposed legs', $failures);
require_contains($service, 'tacho_delta', 'tacho delta on proposed legs', $failures);
require_contains($service, 'fuel_burn', 'fuel burn split', $failures);
require_contains($service, 'operationCountsByLegWindow', 'TO/LDG by leg window', $failures);

require_contains($page, 'data-split-delta="hobbs"', 'UI hobbs delta', $failures);
require_contains($page, 'data-split-field="takeoff_count"', 'UI takeoff count', $failures);
require_contains($page, 'data-split-field="fuel_onboard"', 'UI fuel start', $failures);
require_contains($page, 'Total ΔH', 'UI total hobbs chip', $failures);
require_contains($page, 'Define Legs', 'define legs control', $failures);
require_contains($page, 'Confirm Legs', 'confirm control', $failures);
require_contains($page, 'legs-route-via', 'list via route', $failures);
require_contains($page, 'renderAnnotatedSegments', 'details segment renderer', $failures);
require_contains($page, 'legs-edit-route-segments', 'route segment container', $failures);
require_contains($page, 'legs-edit-meter-segments', 'meter segment container', $failures);
require_contains($page, 'legs-edit-fuel-segments', 'fuel segment container', $failures);
require_contains($page, 'Fuel Departure', 'fuel departure label', $failures);

require_contains($api, 'CvrAdminLegSplitService', 'preview API uses service', $failures);
require_contains($api, 'cw_require_admin', 'preview API admin gated', $failures);

require_contains($page, 'split_operational_leg', 'split POST action', $failures);
require_contains($page, 'legs-split-btn', 'split button', $failures);
require_contains($page, 'legs-split-modal', 'split modal', $failures);
require_contains($page, 'operational_leg_split_preview.php', 'preview fetch', $failures);
require_contains($page, 'reservation_uuid', 'identity in leg payload', $failures);

require_contains($intake, 'leg_segments_json', 'intake reads leg segments', $failures);
require_contains($intake, 'via_airports', 'intake exposes via airports', $failures);
require_contains($schedule, 'legSegmentsFromDispatchRow', 'schedule expands annotated legs', $failures);

require_contains($correction, '$ownsTransaction', 'correction supports nested transactions', $failures);

require_once $service;
if (!class_exists('CvrAdminLegSplitService')) {
    $failures[] = 'CvrAdminLegSplitService failed to load';
}

if ($failures === array()) {
    fwrite(STDOUT, "cvr_operational_leg_split_contract_check OK\n");
    exit(0);
}

fwrite(STDERR, "cvr_operational_leg_split_contract_check FAILED\n");
foreach ($failures as $failure) {
    fwrite(STDERR, "- {$failure}\n");
}
exit(1);
