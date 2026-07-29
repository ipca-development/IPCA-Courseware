<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$detector = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/FlightLandingCycleDetector.swift') ?: '';
$gps = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/GPSLocationManager.swift') ?: '';
$store = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/CVRWorkflowStore.swift') ?: '';
$views = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Views/OperationalWorkflowViews.swift') ?: '';
$intake = file_get_contents($root . '/src/CvrWorkflowEvidenceIntakeService.php') ?: '';

$checks = array(
    'detector classifies touch and go, stop and go, and full stop' =>
        str_contains($detector, 'case touchAndGo')
        && str_contains($detector, 'case stopAndGo')
        && str_contains($detector, 'case fullStop'),
    'detector uses airport boundary context' =>
        str_contains($detector, 'AirportGeofenceCatalog')
        && str_contains($detector, 'boundaryRadiusNM'),
    'gps manager delegates to landing cycle detector' =>
        str_contains($gps, 'FlightLandingCycleDetector')
        && str_contains($gps, 'airportICAOs'),
    'manual two second hold increments are stored' =>
        str_contains($store, 'manual_takeoff_adjustment')
        && str_contains($store, 'manual_landing_adjustment')
        && str_contains($views, 'Hold 2s to +1'),
    'airborne timer keeps running for touch and go and stop and go' =>
        str_contains($views, 'landing_kind')
        && str_contains($views, 'LandingCycleKind.fullStop.rawValue'),
    'shutdown verification stores pilot-confirmed counts' =>
        str_contains($store, 'verifiedTakeoffCount')
        && str_contains($views, 'TAKEOFFS / LANDINGS'),
    'simulation mode supports demo flow without uploads' =>
        str_contains((string) file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/SettingsStore.swift'), 'isSimulationModeEnabled')
        && str_contains((string) file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Views/OperationalWorkflowViews.swift'), 'SIMULATION MODE')
        && str_contains((string) file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/AvionicsBeaconManager.swift'), 'simulateAvionicsOn')
        && str_contains((string) file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/UploadManager.swift'), 'isSimulationModeEnabled'),
    'closure intake accepts verified counts and optional oil' =>
        str_contains($intake, 'verified_takeoff_count')
        && str_contains($intake, 'when provided'),
);

$failed = array();
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}
if ($failed !== array()) {
    fwrite(STDERR, 'Failed landing cycle checks: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}
echo 'OK: CVR landing cycle contract checks passed.' . PHP_EOL;
