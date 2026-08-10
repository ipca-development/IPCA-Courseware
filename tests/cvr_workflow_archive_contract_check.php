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
$garminRecovery = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/GarminRecoveryServices.swift') ?: '';
$garminImport = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/GarminSDCardImportCoordinator.swift') ?: '';
$apiClient = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/APIClient.swift') ?: '';
$garminEvidence = file_get_contents($root . '/src/GarminCsvEvidenceService.php') ?: '';
$garminChunkEndpoint = file_get_contents($root . '/public/api/cvr/csv_upload_chunk.php') ?: '';

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
    'locally checked-in flight can open the next flight without server receipts' =>
        str_contains($store, 'Check-In must be saved locally before opening the next flight.')
        && !str_contains($store, 'NEXT FLIGHT is blocked until every Dispatch, event, closure, and Garmin component')
        && !str_contains($store, 'components.allSatisfy({ $0.state == .serverVerified'),
    'locally closed aircraft carryover prefills next dispatch while upload continues' =>
        str_contains($store, 'resolvedLegCarryover(for: registration)')
        && str_contains($store, 'startingHobbs: carryover?.endingHobbs')
        && str_contains($store, 'startingTacho: carryover?.endingTacho')
        && str_contains($store, 'fuelOnboard: carryover?.fuelRemaining')
        && str_contains($views, 'PREVIOUS FLIGHT VALUES SAVED ON THIS IPHONE')
        && str_contains($store, '"previous_locally_closed_flight_carryover"'),
    'shutdown save persists locally before immediate closure upload' =>
        str_contains($store, 'func saveCheckInValues(')
        && str_contains($store, 'return persisted')
        && str_contains($views, 'let saved = workflow.saveCheckInValues(')
        && str_contains($views, 'guard saved else { return }')
        && str_contains($views, 'uploadManager.uploadQueuedWorkflowComponents'),
    'closure repair replaces immutable upload identity instead of looping' =>
        str_contains($store, 'repairCompletedClosureUploadIfNeeded')
        && str_contains($store, '$0.uploadComponents.removeAll')
        && str_contains($store, '$0.uploadComponents.append(evidenceComponent(')
        && str_contains($views, 'workflow.repairCompletedClosureUploadIfNeeded()')
        && !str_contains($views, 'FIX ENDING METERS / FUEL'),
    'server rejects incomplete or regressing closure values' =>
        str_contains($intake, 'assertCompleteClosure')
        && str_contains($intake, 'Ending Hobbs cannot be lower than Starting Hobbs.')
        && str_contains($intake, 'fuel_remaining must be a valid non-negative quantity when provided.')
        && str_contains($intake, 'verified_takeoff_count'),
    'landing cycle detection uses airport geofence gates' =>
        str_contains((string) file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/FlightLandingCycleDetector.swift'), 'touch_and_go')
        && str_contains((string) file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/FlightLandingCycleDetector.swift'), 'stop_and_go')
        && str_contains((string) file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Views/OperationalWorkflowViews.swift'), 'CVROperationalHoldTile')
        && str_contains((string) file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Views/OperationalWorkflowViews.swift'), 'verifiedTakeoffCount'),
    'Check-In saves meters and fuel while Garmin remains optional' =>
        str_contains($views, 'Enter the fuel remaining.')
        && str_contains($views, 'workflow.saveCheckInValues(')
        && !str_contains((string) file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Views/OperationalWorkflowViews.swift'), '@State private var oilRemaining')
        && !str_contains($store, 'fuel remaining, and oil remaining are required before closure upload')
        && !str_contains((string) file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/UploadManager.swift'), 'item["ending_oil_quantity"]')
        && str_contains((string) file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/UploadManager.swift'), 'Its presence is optional locally and never gates Flight Closure')
        && str_contains((string) file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/UploadManager.swift'), 'verified_takeoff_count'),
    'Garmin metadata verifies dispatch counter starts' =>
        str_contains($classification, "metadata['airframe_hours']")
        && str_contains($classification, "metadata['engine_hours']")
        && str_contains($derivation, "'authority' => 'dispatch_start_crew_end'")
        && str_contains($derivation, 'verify the dispatch entry')
        && str_contains($derivation, 'Garmin airframe_hours'),
    'SD recovery synchronizes all data-rich files without GPS-only logs' =>
        str_contains($garminImport, 'if result.isDataRich')
        && str_contains($garminImport, 'knownGarminCsvHashes')
        && str_contains($garminRecovery, 'let standaloneRecords = vault.pendingRecords().filter')
        && str_contains($garminRecovery, 'uploadStandaloneRecord(')
        && str_contains($apiClient, 'appendField("standalone_upload", "1")')
        && str_contains($garminChunkEndpoint, "'standalone_upload' =>")
        && str_contains($garminEvidence, '$standaloneUpload'),
    'SD recovery exposes live scan and synchronization progress' =>
        str_contains($garminImport, 'scanFilesProcessed')
        && str_contains($garminRecovery, 'syncFilesProcessed')
        && str_contains($garminRecovery, 'currentFileProgress')
        && str_contains($views, 'activeRecoveryProgress')
        && str_contains($views, 'SYNCHRONIZING GARMIN FILES')
        && str_contains(
            (string) file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Views/CVROperationalDesign.swift'),
            'ProgressView(value: progress)'
        ),
    'crew endings override derived durations with discrepancy reporting' =>
        str_contains($derivation, 'crew_hobbs_duration_hours')
        && str_contains($derivation, 'crew_hobbs_end')
        && str_contains($derivation, 'crew counter remains authoritative')
        && str_contains($derivation, 'UPDATE ipca_operational_flight_record_versions'),
    'meter fuel and oil continuity are advisory beyond tolerance' =>
        str_contains($models, 'refueledSincePreviousFlight')
        && str_contains($models, 'oilServicedSincePreviousFlight')
        && str_contains($dispatchIntake, 'previousFlightContinuityWarnings')
        && str_contains($dispatchIntake, "'continuity_warnings'")
        && str_contains($dispatchIntake, '> 0.20')
        && !str_contains($models, 'items.append(contentsOf: continuityDiscrepancies)'),
    'continuity warning never replaces generic Dispatch retry' =>
        str_contains($store, 'updateActiveDispatchForUploadRepair')
        && str_contains($store, 'dispatchContinuityUploadIssue')
        && str_contains($store, 'dispatchUploadVerified')
        && str_contains($views, 'DISPATCH CONTINUITY WARNING')
        && str_contains($views, 'FUEL DISCREPANCY >20%')
        && str_contains($views, 'Confirm the airplane was refueled before this flight')
        && str_contains($views, 'Oil has been uploaded')
        && !str_contains($views, 'Adjust dispatch oil if the reading was wrong'),
    'failed closure upload can be repaired from Garmin and In-Flight tabs' =>
        str_contains($store, 'saveFlightClosureValues')
        && str_contains($store, 'canEditFlightClosure')
        && str_contains($store, 'reconcileClosureUploadComponents')
        && str_contains($store, 'repairCompletedClosureUploadIfNeeded')
        && str_contains($views, 'FIX ENDING METERS')
        && str_contains($views, 'RETRY FAILED ITEMS')
        && str_contains($views, 'CheckInView(repairExistingClosureUpload: true)'),
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
    'malformed archive records are isolated and raw evidence is quarantined' =>
        str_contains($store, 'CVRArchiveRecordRecovery.records(in: sourceData)')
        && str_contains($store, 'decoder.decode(CVRWorkflowArchiveRecord.self, from: rawRecord)')
        && str_contains($store, 'quarantineArchiveRecord(')
        && str_contains($store, 'rawRecord.write(to: evidenceURL, options: [.atomic])')
        && str_contains($store, 'archiveRewriteSafe')
        && strpos($store, 'quarantineArchiveRecord(') < strpos($store, 'try saveArchives(recovered)'),
    'archive failure cannot suppress active workflow loading' =>
        str_contains($store, 'Historical workflow archive recovery failed:')
        && str_contains($store, 'Active workflow recovery failed:')
        && strpos($store, 'diagnostics.append(contentsOf: try loadArchives())')
            < strpos($store, 'let url = try storeURL()'),
    'failed flight component cannot stop later queued flights' =>
        str_contains($upload, 'for component in components')
        && str_contains($upload, 'workflow.recordWorkflowUploadFailure(')
        && str_contains($store, 'state = .failed')
        && !str_contains($upload, 'break uploadQueue'),
    'same-process orphaned workflow uploads recover without disturbing active tasks' =>
        str_contains($store, 'func recoverOrphanedUploads(activeComponentIDs: Set<String>)')
        && str_contains($store, '!activeComponentIDs.contains')
        && str_contains($coordinator, 'activeWorkflowUploadIDs')
        && str_contains(
            (string) file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/IPCACVRUnitApp.swift'),
            'workflowStore.recoverOrphanedUploads'
        ),
    'Dispatch verification is atomic with durable server identity and receipt' =>
        str_contains($store, 'func persistVerifiedDispatch(')
        && str_contains($store, 'dispatch.serverDispatchID = serverDispatchID')
        && str_contains($store, 'func persistReconciliationMatch(')
        && str_contains($upload, 'guard persisted else')
        && str_contains($upload, 'local receipt persistence will reconcile automatically')
        && str_contains($upload, 'workflow.persistReconciliationMatch(')
        && str_contains($upload, 'self.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)'),
    'workflow reconciliation preserves payload and authoritative metadata' =>
        str_contains($models, 'var requestPayloadSnapshot: Data?')
        && str_contains($models, 'var authoritativePayloadSHA256: String?')
        && str_contains($models, 'var canonicalIdentifiers: [String: String]?')
        && str_contains($store, 'func persistReconciliationMatch(')
        && str_contains($store, 'hasCompleteVerificationMetadata')
        && str_contains($upload, 'reconcileWorkflowSync(')
        && str_contains($upload, 'workflowReconciliationScanInFlight')
        && !str_contains($upload, 'SHA256.hash(data: snapshot)'),
    'evidence verification checks durable persistence independently' =>
        str_contains($store, 'func persistVerifiedWorkflowComponent(')
        && str_contains($upload, 'workflow.persistVerifiedWorkflowComponent(')
        && str_contains($upload, 'local metadata persistence will reconcile automatically')
        && str_contains($upload, 'for item in batch'),
    'immutable reconciliation conflicts remain technical review only' =>
        str_contains($upload, 'case .immutableConflict:')
        && str_contains($upload, 'errorCode: "IMMUTABLE_CONFLICT"')
        && str_contains($store, 'component.userActionRequired = errorCode == "USER_CORRECTION_REQUIRED"')
        && str_contains($store, '$0.errorCode != "IMMUTABLE_CONFLICT"'),
    'routine scans cannot bypass reconciliation-required components' =>
        str_contains($upload, 'let reconciliationBlockedIDs = Set(allReconciliationComponents.map(\.id))')
        && str_contains($upload, 'if reconciliationBlockedIDs.contains(component.id)')
        && str_contains($upload, 'continue')
        && str_contains($upload, 'case .notFound:')
        && str_contains($upload, 'reconciliationRequired: false'),
    'payload snapshots are byte-bounded and exclude media content' =>
        str_contains($store, 'static let maximumRequestPayloadSnapshotBytes = 256 * 1024')
        && str_contains($store, 'payload.count <= Self.maximumRequestPayloadSnapshotBytes')
        && str_contains($store, 'operational evidence was preserved without the oversized snapshot')
        && str_contains($upload, 'maximumRequestPayloadSnapshotBytes')
        && !str_contains($upload, 'SHA256.hash(data: snapshot)'),
    'crew-correctable reconciliation statuses remain distinct from immutable conflicts' =>
        str_contains($upload, 'case .userCorrectionRequired:')
        && str_contains($upload, 'errorCode: "USER_CORRECTION_REQUIRED"')
        && str_contains($upload, 'state: .needsUserAction')
        && str_contains((string) file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/APIClient.swift'), 'case userCorrectionRequired = "USER_CORRECTION_REQUIRED"'),
    'restart recovery reconciles incomplete accepted metadata' =>
        str_contains($store, 'recoverIncompleteActiveVerificationMetadata')
        && str_contains($store, 'component.state == .serverVerified && !Self.hasCompleteVerificationMetadata(component)')
        && str_contains($store, 'component.attemptCount > 0')
        && str_contains($store, 'component.reconciliationRequired = true'),
    'reconciliation outcomes remain item scoped and retryable where required' =>
        str_contains($upload, 'case .notFound:')
        && str_contains($upload, 'reconciliationRequired: false')
        && str_contains($upload, 'case .dependencyNotReady, .temporaryTechnicalFailure:')
        && str_contains($upload, 'deferredWorkflowReconciliationIDs.insert(result.itemID)')
        && str_contains($upload, 'applyReconciliationResult(result, workflow: workflow)'),
    'Phase 1B authentication gate also controls reconciliation probes' =>
        str_contains($upload, 'let reconciliationIsAuthenticationProbe = workflowSyncAuthenticationPaused')
        && str_contains($upload, 'trigger.permitsAuthenticationProbe')
        && str_contains($upload, 'activeWorkflowAuthenticationRequestIDs.isEmpty')
        && str_contains($upload, 'let mayStartReconciliation = !workflowSyncAuthenticationPaused')
        && str_contains($upload, 'workflowAuthenticationProbeInFlight = true'),
    'deferred reconciliation does not hammer while Garmin and recording remain independent' =>
        str_contains($upload, 'private var deferredWorkflowReconciliationIDs: Set<String> = []')
        && str_contains($upload, 'if trigger != .routine')
        && str_contains($upload, 'component.componentType != "garmin_csv"')
        && str_contains($upload, 'func uploadPending(store: RecordingStore'),
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
