<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$api = file_get_contents($root . '/public/api/cvr/flight_logs.php') ?: '';
$service = file_get_contents($root . '/src/CvrFlightLogService.php') ?: '';
$app = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/IPCACVRUnitApp.swift') ?: '';
$models = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Models/CVRWorkflowModels.swift') ?: '';
$views = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Views/OperationalWorkflowViews.swift') ?: '';
$uploads = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/UploadManager.swift') ?: '';
$garminEvidence = file_get_contents($root . '/src/GarminCsvEvidenceService.php') ?: '';
$garminFinalize = file_get_contents($root . '/public/api/cvr/csv_upload_finalize.php') ?: '';
$plist = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Info.plist') ?: '';
$adjustmentApi = file_get_contents($root . '/public/api/cvr/flight_log_adjust.php') ?: '';
$retryApi = file_get_contents($root . '/public/api/cvr/flight_log_retry.php') ?: '';
$adjustmentMigration = file_get_contents($root . '/scripts/sql/2026_08_01_cvr_flight_log_adjustments.sql') ?: '';
$workflowStore = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/CVRWorkflowStore.swift') ?: '';
$coordinator = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/CVRUnitCoordinator.swift') ?: '';
$recordingStore = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/RecordingStore.swift') ?: '';
$derivation = file_get_contents($root . '/src/FlightRecordDerivationService.php') ?: '';
$debrief = file_get_contents($root . '/src/FlightDebriefService.php') ?: '';
$startingMeterMigration = file_get_contents($root . '/scripts/sql/2026_08_01_cvr_flight_log_starting_meter_adjustments.sql') ?: '';

$checks = array(
    'flight log API is device authenticated and aircraft scoped' =>
        str_contains($api, 'requireDevice()')
        && str_contains($service, 'd.aircraft_id = :aircraft_id')
        && str_contains($service, 'd.organization_id = :organization_id'),
    'flight log adjustment uses the same aircraft identity fallback as listing' =>
        str_contains($service, '$aircraftOwnershipPredicate = $aircraftId > 0')
        && str_contains($service, "'aircraft_id = :aircraft_id'")
        && str_contains($service, "'UPPER(aircraft_registration) = :registration'")
        && !str_contains($service, 'aircraft_id IS NULL AND UPPER(aircraft_registration)'),
    'flight log includes route times Hobbs and Garmin completeness' =>
        str_contains($service, "'departure_airport'")
        && str_contains($service, "'departure_time'")
        && str_contains($service, "'arrival_airport'")
        && str_contains($service, "'arrival_time'")
        && str_contains($service, "'total_hobbs_time'")
        && str_contains($service, "'has_garmin_csv'"),
    'duplicate local and server rows collapse by reservation then Dispatch identity' =>
        str_contains($service, "'scheduler_record_id'")
        && str_contains($models, 'var schedulerRecordID: String?')
        && str_contains($views, 'private func logIdentity(')
        && str_contains($views, 'return "schedule:')
        && str_contains($views, 'return "dispatch:')
        && str_contains($views, 'mergeLogEntries')
        && str_contains($views, 'existing.hasGarminCSV || candidate.hasGarminCSV'),
    'offline audio is linked to the workflow flight and repaired for existing archives' =>
        str_contains($coordinator, 'recording.flightSessionID = workflow?.state.activeFlightRecord?.id')
        && str_contains($coordinator, 'linkRecordingSession(recordingID: recordingSessionID')
        && str_contains($workflowStore, 'func recordingSessionFlightRecordLinks()')
        && str_contains($recordingStore, 'func repairFlightSessionLinks(')
        && str_contains($recordingStore, 'recordings[index].uploadStatus = .pending')
        && str_contains($views, '$0.flightSessionID = entry.flightRecordID'),
    'connectivity recovery requeues archived workflow and cockpit audio uploads' =>
        str_contains($workflowStore, 'func requeueConnectivityFailedUploads()')
        && str_contains($recordingStore, 'func requeueConnectivityFailedUploads()')
        && str_contains($coordinator, 'network.canUpload(allowCellular:')
        && str_contains($uploads, 'configureNetworkMonitor')
        && str_contains($app, 'recordingStore.requeueConnectivityFailedUploads()'),
    'retry progress replaces stale offline errors in the merged Log row' =>
        str_contains($views, 'merged.serverUploadStatus?.lowercased() == "failed"')
        && str_contains($views, 'merged.transcriptStatus?.lowercased() == "failed"')
        && strpos($views, 'if values.contains("pending")') < strpos($views, 'if values.contains("failed")')
        && str_contains($views, 'await flightLogs.refresh(settings: settings)'),
    'arrival time is engine start plus elapsed Hobbs with shutdown fallback' =>
        str_contains($service, '$elapsedSeconds = (int)round((float)$row[\'total_hobbs_time\'] * 3600)')
        && str_contains($service, "->modify(sprintf('+%d seconds', \$elapsedSeconds))")
        && str_contains($service, "\$arrivalUtc = \$this->utcDate(\$row['arrival_event_time_utc']")
        && str_contains($views, 'departure.timestampLocal.addingTimeInterval(totalHobbs * 3600)'),
    'flight log times are explicit California local time with daylight saving support' =>
        str_contains($service, 'departure_event.timestamp_utc')
        && str_contains($service, "new DateTimeZone('America/Los_Angeles')")
        && str_contains($service, "->format('Y-m-d\\TH:i:sP')")
        && str_contains($views, 'TimeZone(identifier: "America/Los_Angeles")')
        && str_contains($views, 'californiaTimeFormatter.string(from: date)')
        && !str_contains($views, 'return String(timePart.prefix(5))'),
    'flight log exposes crew and protected operational adjustments' =>
        str_contains($service, "'crew_names'")
        && str_contains($models, 'var crewNames: [String]?')
        && str_contains($models, 'func adjustFlightLog(')
        && str_contains($service, 'adjustForDeviceAircraft')
        && str_contains($adjustmentApi, 'requireDevice()')
        && str_contains($adjustmentMigration, 'ipca_cvr_flight_log_adjustments')
        && str_contains($views, 'ADMIN AUTHORIZATION')
        && str_contains($views, 'adjustmentPIN == settings.adminPIN')
        && str_contains($views, 'ADMINISTRATIVE ADJUSTMENT')
        && str_contains($views, 'DEPARTURE AIRPORT')
        && str_contains($views, 'ARRIVAL AIRPORT')
        && str_contains($views, 'CREW NAMES'),
    'incorrect Dispatch starting meters can be corrected append-only and re-derived' =>
        str_contains($startingMeterMigration, "COLUMN_NAME = 'starting_hobbs'")
        && str_contains($startingMeterMigration, "COLUMN_NAME = 'starting_tacho'")
        && str_contains($service, 'COALESCE(adjustment.starting_hobbs, d.starting_hobbs)')
        && str_contains($service, "'starting_hobbs' => \$startingHobbs")
        && str_contains($models, '"starting_hobbs": startingHobbs')
        && str_contains($views, '"STARTING HOBBS"')
        && str_contains($views, '"STARTING TACHO"')
        && str_contains($derivation, 'COALESCE(a.starting_hobbs, d.starting_hobbs)')
        && str_contains($debrief, 'COALESCE(fla.starting_hobbs, d.starting_hobbs)'),
    'flight log exposes server upload transcript progress and operation counts' =>
        str_contains($service, "'server_upload_status'")
        && str_contains($service, "'server_upload_progress'")
        && str_contains($service, "'transcript_status'")
        && str_contains($service, "'transcript_progress'")
        && str_contains($service, "'takeoff_count'")
        && str_contains($service, "'landing_count'")
        && str_contains($models, 'var serverUploadProgress: Int?')
        && str_contains($models, 'var transcriptProgress: Int?')
        && str_contains($models, 'var takeoffCount: Int?')
        && str_contains($models, 'var landingCount: Int?')
        && str_contains($views, '"SERVER"')
        && str_contains($views, '"TRANSCRIPT"')
        && str_contains($views, '"TAKEOFFS"')
        && str_contains($views, '"LANDINGS"'),
    'failed log upload and transcript processing can be retried securely' =>
        str_contains($retryApi, 'requireDevice()')
        && str_contains($service, 'retryServerProcessingForDeviceAircraft')
        && str_contains($service, 'requeueTranscription')
        && str_contains($models, 'func retryServerProcessing(')
        && str_contains($workflowStore, 'func requeueFailedUploads(forFlightRecordID')
        && str_contains($views, 'Label("RE-UPLOAD"')
        && str_contains($views, 'retryLogUpload(entry)'),
    'iOS accepts AirDrop CSV and routes it to Log assignment' =>
        str_contains($plist, 'public.comma-separated-values-text')
        && str_contains($app, '.onOpenURL')
        && str_contains($app, 'stageGarminCSV')
        && str_contains($app, 'selectTab(.log)')
        && str_contains($views, 'navigationTitle("Assign Garmin CSV")')
        && str_contains($views, '.interactiveDismissDisabled()')
        && str_contains($views, 'set: { _ in }'),
    'late CSV upload can target a selected dispatched flight' =>
        str_contains($models, 'CVRPendingGarminCSV')
        && str_contains($models, 'uploadPendingGarminCSV')
        && str_contains($uploads, 'uploadGarminCSVAttachment')
        && str_contains($uploads, 'uploadCvrCsvChunk')
        && str_contains($uploads, 'workflowFlightRecordUUID: flightRecordID')
        && str_contains($views, 'Assign Garmin CSV'),
    'late CSV attachment is authenticated ownership checked and fully processed' =>
        str_contains($garminFinalize, 'requireDevice()')
        && str_contains($garminFinalize, "'workflow_flight_record_uuid'")
        && str_contains($garminEvidence, 'assertWorkflowFlightOwnership')
        && str_contains($garminEvidence, 'workflow_flight_record_uuid')
        && str_contains($garminEvidence, "'workflow_linked' => \$workflowLinked")
        && str_contains($garminEvidence, 'workflowLinkConfirmed')
        && str_contains($garminEvidence, 'GarminCsvValidationService')
        && str_contains($garminEvidence, 'enqueueJobs')
        && str_contains($uploads, 'finalized.workflowLinked == true'),
    'Log tab uses the operational shell and exposes missing CSV records' =>
        str_contains($models, 'case log')
        && str_contains($views, 'AIRCRAFT FLIGHT LOG')
        && str_contains($views, 'CSV MISSING')
        && str_contains($views, 'OperationalBottomTabBar'),
    'Log replaces the standalone Garmin operational tab' =>
        str_contains($views, 'CVROperationalTab.allCases.filter { $0 != .garmin }')
        && str_contains($views, "case .garmin:\n            FlightLogView()")
        && str_contains($workflowStore, '$0.selectedTab = .log'),
    'ended persisted workflow is archived and operational tabs become idle' =>
        str_contains($workflowStore, 'try loadArchives()')
        && str_contains($workflowStore, 'if finishEndedFlightLocally()')
        && str_contains($views, 'NoActiveFlightView(caption: "RECORDER")')
        && str_contains($views, 'NoActiveFlightView(caption: "IN-FLIGHT")')
        && str_contains($views, 'PREVIOUS FLIGHT ENDED'),
    'flight closure makes Garmin optional and asks only for ending meters' =>
        str_contains($views, 'AUDIO FLIGHT CLOSURE')
        && str_contains($views, 'Garmin CSV data is optional now')
        && str_contains($views, 'Enter Ending Hobbs and Tacho'),
    'ending meters finish locally while uploads continue from the archive' =>
        str_contains($workflowStore, 'func finishEndedFlightLocally() -> Bool')
        && str_contains($workflowStore, 'guard archiveActiveWorkflow() else { return false }')
        && str_contains($workflowStore, '$0.selectedTab = .log')
        && str_contains($views, 'workflow.finishEndedFlightLocally()')
        && str_contains($views, 'uploadManager.uploadQueuedWorkflowComponents'),
    'just-ended local flight is immediately selectable for Garmin attachment' =>
        str_contains($views, 'private var displayEntries: [CVRFlightLogEntry]')
        && str_contains($views, 'for archive in workflow.archives')
        && str_contains($views, 'ForEach(displayEntries.filter { !$0.hasGarminCSV })'),
    'successful Garmin attachment survives assignment-sheet dismissal and refresh cancellation' =>
        str_contains($models, 'locallyAttachedGarminFlightRecordIDs.insert(entry.flightRecordID)')
        && str_contains($models, 'entries[index].hasGarminCSV = true')
        && str_contains($models, 'await refresh(settings: settings)')
        && str_contains($models, 'self.pendingGarminCSV = nil')
        && strpos($models, 'await refresh(settings: settings)') < strrpos($models, 'self.pendingGarminCSV = nil')
        && str_contains($models, 'catch is CancellationError')
        && str_contains($views, 'hasLocallyAttachedGarminCSV'),
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
