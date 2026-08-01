<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$api = file_get_contents($root . '/public/api/cvr/flight_logs.php') ?: '';
$service = file_get_contents($root . '/src/CvrFlightLogService.php') ?: '';
$app = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/IPCACVRUnitApp.swift') ?: '';
$models = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Models/CVRWorkflowModels.swift') ?: '';
$views = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Views/OperationalWorkflowViews.swift') ?: '';
$uploads = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/UploadManager.swift') ?: '';
$plist = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Info.plist') ?: '';

$checks = array(
    'flight log API is device authenticated and aircraft scoped' =>
        str_contains($api, 'requireDevice()')
        && str_contains($service, 'd.aircraft_id = :aircraft_id')
        && str_contains($service, 'd.organization_id = :organization_id'),
    'flight log includes route times Hobbs and Garmin completeness' =>
        str_contains($service, "'departure_airport'")
        && str_contains($service, "'departure_time'")
        && str_contains($service, "'arrival_airport'")
        && str_contains($service, "'arrival_time'")
        && str_contains($service, "'total_hobbs_time'")
        && str_contains($service, "'has_garmin_csv'"),
    'iOS accepts AirDrop CSV and routes it to Log assignment' =>
        str_contains($plist, 'public.comma-separated-values-text')
        && str_contains($app, '.onOpenURL')
        && str_contains($app, 'stageGarminCSV')
        && str_contains($app, 'selectTab(.log)'),
    'late CSV upload can target a selected dispatched flight' =>
        str_contains($models, 'CVRPendingGarminCSV')
        && str_contains($models, 'uploadPendingGarminCSV')
        && str_contains($uploads, 'uploadGarminCSVAttachment')
        && str_contains($views, 'Assign Garmin CSV'),
    'Log tab uses the operational shell and exposes missing CSV records' =>
        str_contains($models, 'case log')
        && str_contains($views, 'AIRCRAFT FLIGHT LOG')
        && str_contains($views, 'CSV MISSING')
        && str_contains($views, 'OperationalBottomTabBar'),
    'flight closure makes Garmin optional and asks only for ending meters' =>
        str_contains($views, 'AUDIO FLIGHT CLOSURE')
        && str_contains($views, 'Garmin CSV data is optional now')
        && str_contains($views, 'Enter Ending Hobbs and Tacho'),
);

$failed = array();
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    if (!$passed) {
        $failed[] = $label;
    }
}
if ($failed !== array()) {
    fwrite(STDERR, 'Failed CVR flight log checks: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}
echo "OK: CVR flight log contract checks passed." . PHP_EOL;
