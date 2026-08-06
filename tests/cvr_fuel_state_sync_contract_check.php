<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$fuelState = file_get_contents($root . '/src/AircraftFuelStateService.php') ?: '';
$dispatchIntake = file_get_contents($root . '/src/CvrDispatchIntakeService.php') ?: '';
$deviceStatus = file_get_contents($root . '/public/api/cvr/device_status.php') ?: '';
$apiClient = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/APIClient.swift') ?: '';
$settings = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/SettingsStore.swift') ?: '';
$store = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/CVRWorkflowStore.swift') ?: '';
$views = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Views/OperationalWorkflowViews.swift') ?: '';
$app = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/IPCACVRUnitApp.swift') ?: '';
$upload = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/UploadManager.swift') ?: '';

$checks = array(
    'fuel state service resolves uplift over older closure' =>
        str_contains($fuelState, 'function stateForRegistration(')
        && str_contains($fuelState, 'source')
        && str_contains($fuelState, "'uplift'")
        && str_contains($fuelState, "'closure'")
        && str_contains($fuelState, 'latestForAircraft'),
    'dispatch intake auto-creates uplift when full or refueled' =>
        str_contains($fuelState, 'function createUpliftFromDispatchIfNeeded(')
        && str_contains($fuelState, 'refueled_since_previous_flight')
        && str_contains($fuelState, 'CVR Unit: fuel set to full')
        && str_contains($dispatchIntake, 'createUpliftFromDispatchIfNeeded')
        && str_contains($dispatchIntake, "'fuel_uplift'"),
    'device_status exposes fuel_state for enrolled aircraft' =>
        str_contains($deviceStatus, 'AircraftFuelStateService')
        && str_contains($deviceStatus, "'fuel_state'")
        && str_contains($deviceStatus, 'stateForRegistration'),
    'app client decodes fuel_state and polls device_status' =>
        str_contains($apiClient, 'struct AircraftFuelStateResponse')
        && str_contains($apiClient, 'quantity_usg')
        && str_contains($apiClient, 'func deviceStatus(credential:')
        && str_contains($settings, 'serverFuelState')
        && str_contains($settings, 'func refreshFuelState()'),
    'app applies server fuel into dispatch carryover' =>
        str_contains($store, 'serverFuelUSG')
        && str_contains($views, 'refreshFuelState')
        && str_contains($views, 'serverFuelUSG: settings.serverFuelState?.quantityUSG')
        && str_contains($app, 'refreshFuelState')
        && str_contains($app, 'backfillDispatchCarryoverIfNeeded'),
    'app marks full tanks as refueled and syncs capacity' =>
        str_contains($views, 'fuelGallons >= (operationalConfig.fuelCapacity - 0.05)')
        && str_contains($views, 'refueledSincePreviousFlight = true')
        && str_contains($upload, '"fuel_capacity"')
        && str_contains($upload, 'refueled_since_previous_flight'),
);

$failed = array();
foreach ($checks as $name => $ok) {
    if (!$ok) {
        $failed[] = $name;
    }
}

if ($failed) {
    fwrite(STDERR, "cvr_fuel_state_sync_contract_check FAILED:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "cvr_fuel_state_sync_contract_check OK (" . count($checks) . " checks)\n";
exit(0);
