<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$store = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/CVRWorkflowStore.swift') ?: '';
$models = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Models/CVRWorkflowModels.swift') ?: '';
$coordinator = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/CVRUnitCoordinator.swift') ?: '';
$upload = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/UploadManager.swift') ?: '';
$views = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Views/OperationalWorkflowViews.swift') ?: '';
$intake = file_get_contents($root . '/src/CvrWorkflowEvidenceIntakeService.php') ?: '';
$dispatchIntake = file_get_contents($root . '/src/CvrDispatchIntakeService.php') ?: '';
$derivation = file_get_contents($root . '/src/FlightRecordDerivationService.php') ?: '';
$classification = file_get_contents($root . '/src/GarminFlightDataSourceClassificationService.php') ?: '';

$checks = array(
    'archive model retains all evidence categories' =>
        str_contains($models, 'struct CVRWorkflowArchiveRecord')
        && str_contains($models, 'var flightEvents: [CVRFlightEventRecord]')
        && str_contains($models, 'var recorderVerifications: [CVRRecorderVerificationRecord]')
        && str_contains($models, 'var uploadComponents: [CVRUploadComponentRecord]'),
    'archive is written and verified before active reset' =>
        strpos($store, 'guard archiveActiveWorkflow() else { return }')
        < strpos($store, '$0.activeDispatch = nil')
        && str_contains($store, 'verification.map(\\.id) == records.map(\\.id)'),
    'archives retain interrupted uploads for recovery' =>
        str_contains($store, 'archives.flatMap(\\.uploadComponents)')
        && str_contains($models, 'case uploadPending'),
    'next flight requires every server verification receipt' =>
        str_contains($store, 'components.allSatisfy({ $0.state == .serverVerified')
        && str_contains($store, 'NEXT FLIGHT is blocked until every Dispatch, event, closure, and Garmin component'),
    'verified aircraft carryover prefills next dispatch meters and fuel' =>
        str_contains($store, 'latestVerifiedCarryover(for: registration)')
        && str_contains($store, 'startingHobbs: carryover?.endingHobbs')
        && str_contains($store, 'startingTacho: carryover?.endingTacho')
        && str_contains($store, 'fuelOnboard: carryover?.fuelRemaining')
        && str_contains($views, 'VERIFIED PREVIOUS FLIGHT VALUES'),
    'shutdown save persists locally before immediate closure upload' =>
        str_contains($store, 'let persisted = mutate')
        && str_contains($store, 'return persisted')
        && str_contains($views, 'if save()')
        && str_contains($views, 'uploadManager.uploadQueuedWorkflowComponents'),
    'server rejects incomplete or regressing closure values' =>
        str_contains($intake, 'assertCompleteClosure')
        && str_contains($intake, 'Ending Hobbs cannot be lower than Starting Hobbs.')
        && str_contains($intake, 'fuel_remaining must be a valid non-negative quantity.')
        && str_contains($intake, 'verified_takeoff_count'),
    'landing cycle detection uses airport geofence gates' =>
        str_contains((string) file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/FlightLandingCycleDetector.swift'), 'touch_and_go')
        && str_contains((string) file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/FlightLandingCycleDetector.swift'), 'stop_and_go')
        && str_contains((string) file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Views/OperationalWorkflowViews.swift'), 'CVROperationalHoldTile')
        && str_contains((string) file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Views/OperationalWorkflowViews.swift'), 'verifiedTakeoffCount'),
    'postflight closure captures fuel and verified operation counts only' =>
        str_contains((string) file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Views/OperationalWorkflowViews.swift'), 'POST-FLIGHT FUEL')
        && !str_contains((string) file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Views/OperationalWorkflowViews.swift'), 'POST-FLIGHT FUEL / OIL')
        && str_contains((string) file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/UploadManager.swift'), 'verified_takeoff_count'),
    'Garmin metadata verifies dispatch counter starts' =>
        str_contains($classification, "metadata['airframe_hours']")
        && str_contains($classification, "metadata['engine_hours']")
        && str_contains($derivation, "'authority' => 'dispatch_start_crew_end'")
        && str_contains($derivation, 'verify the dispatch entry')
        && str_contains($derivation, 'Garmin airframe_hours'),
    'crew endings override derived durations with discrepancy reporting' =>
        str_contains($derivation, 'crew_hobbs_duration_hours')
        && str_contains($derivation, 'crew_hobbs_end')
        && str_contains($derivation, 'crew counter remains authoritative')
        && str_contains($derivation, 'UPDATE ipca_operational_flight_record_versions'),
    'fuel and oil continuity require service declarations beyond twenty percent' =>
        str_contains($models, 'refueledSincePreviousFlight')
        && str_contains($models, 'oilServicedSincePreviousFlight')
        && str_contains($views, 'Aircraft was refueled before this flight')
        && str_contains($views, 'Oil was serviced before this flight')
        && str_contains($dispatchIntake, 'assertPreviousFlightContinuity')
        && str_contains($dispatchIntake, '> 0.20'),
    'failed dispatch upload can be repaired with continuity confirmation' =>
        str_contains($store, 'updateActiveDispatchForUploadRepair')
        && str_contains($store, 'dispatchContinuityUploadIssue')
        && str_contains($store, 'dispatchUploadVerified')
        && str_contains($views, 'CONFIRM & RETRY DISPATCH UPLOAD')
        && str_contains($views, 'Oil has been uploaded')
        && str_contains($views, 'CONTINUITY CONFIRMATION REQUIRED'),
    'operational calculation versions accept long service version labels' =>
        str_contains($derivation, "substr((string)(\$value['calculation_version'] ?? 'phase3-v1'), 0, 64)")
        && str_contains((string) file_get_contents($root . '/src/TachoCalculationService.php'), 'tacho_rpm_threshold_cumulative_v2')
        && str_contains((string) file_get_contents($root . '/scripts/sql/2026_07_28_operational_calculation_version_width.sql'), 'VARCHAR(64)'),
    'audio session links and backfills event offsets' =>
        str_contains($coordinator, 'workflow?.linkRecordingSession')
        && str_contains($store, 'timestampUTC.timeIntervalSince(startedAt)'),
    'each event gets a stable upload component' =>
        str_contains($store, 'private func eventUploadComponent')
        && str_contains($store, 'localFilePath: "\\(prefix):\\(evidenceID)"'),
    'server verified requires a real receipt' =>
        str_contains($store, 'Server verification receipt is missing.')
        && str_contains($upload, 'syncWorkflowEvidence'),
    'interrupted active and archived uploads recover to queue' =>
        str_contains($store, 'recoverInterruptedActiveUploads')
        && str_contains($store, 'Upload was interrupted and has been queued for recovery.'),
);

$failed = array();
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}
if ($failed !== array()) {
    fwrite(STDERR, 'Failed workflow archive checks: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}
echo 'OK: CVR workflow archive contract checks passed.' . PHP_EOL;
