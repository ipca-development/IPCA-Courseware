<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$sql = file_get_contents($root . '/scripts/sql/2026_08_06_aircraft_fuel_uplifts.sql') ?: '';
$upliftService = file_get_contents($root . '/src/AircraftFuelUpliftService.php') ?: '';
$fleetService = file_get_contents($root . '/src/AircraftFleetStatusService.php') ?: '';
$intake = file_get_contents($root . '/public/admin/master_logbook_intake.php') ?: '';

$checks = array(
    'fuel uplift table migration exists' =>
        str_contains($sql, 'CREATE TABLE IF NOT EXISTS ipca_aircraft_fuel_uplifts')
        && str_contains($sql, 'fuel_after_usg')
        && str_contains($sql, 'deleted_at'),
    'uplift service supports create list and admin soft-delete' =>
        str_contains($upliftService, 'function create(')
        && str_contains($upliftService, 'function listForAircraft(')
        && str_contains($upliftService, 'function softDelete(')
        && str_contains($upliftService, 'normalizeLocalToUtc'),
    'fleet status reads latest closure meters oil and fuel' =>
        str_contains($fleetService, 'ending_hobbs')
        && str_contains($fleetService, 'ending_tacho')
        && str_contains($fleetService, 'fuel_remaining')
        && str_contains($fleetService, 'latestForAircraft')
        && str_contains($fleetService, 'cardsForAircraft')
        && str_contains($fleetService, 'dispatch_oil_percentage')
        && str_contains($fleetService, 'oilPresentation'),
    'Master Logbook renders compact fleet cards with hobbs oil fuel' =>
        str_contains($intake, 'fleet-status-grid')
        && str_contains($intake, 'fleet-status-meters')
        && str_contains($intake, 'fleet-status-instrument')
        && str_contains($intake, '>Hobbs<')
        && str_contains($intake, '>Tacho<')
        && str_contains($intake, '>Oil<')
        && str_contains($intake, 'fleet-status-burn')
        && str_contains($intake, 'data-fleet-uplift-open')
        && !str_contains($intake, 'data-fleet-uplift-list')
        && !str_contains($intake, 'Log Fuel Uplift')
        && str_contains($intake, 'cvr_intake_fleet_logged_parts'),
    'admin-only uplift create and delete actions' =>
        str_contains($intake, "'create_fuel_uplift'")
        && str_contains($intake, "'delete_fuel_uplift'")
        && str_contains($intake, '$cvrMlCanManageFuelUplifts')
        && str_contains($intake, 'Fuel uplift logging is only available to admins.'),
    'uplift modal includes add form and history table' =>
        str_contains($intake, 'initFleetStatusCards')
        && str_contains($intake, 'fleet-uplift-modal')
        && str_contains($intake, 'fleet-uplift-history')
        && str_contains($intake, 'fleet-uplift-table')
        && str_contains($intake, 'fuel_after_usg')
        && str_contains($intake, 'renderHistory'),
);

$failed = array();
foreach ($checks as $name => $ok) {
    if (!$ok) {
        $failed[] = $name;
    }
}

if ($failed) {
    fwrite(STDERR, "cvr_fleet_status_uplift_contract_check FAILED:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "cvr_fleet_status_uplift_contract_check OK (" . count($checks) . " checks)\n";
exit(0);
