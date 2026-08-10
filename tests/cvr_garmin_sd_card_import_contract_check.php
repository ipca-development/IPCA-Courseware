#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Contract checks for Garmin SD Card Import (folder access, classification, status, wiring).
 */

$root = dirname(__DIR__);
$classifier = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/GarminCsvClassifier.swift');
$access = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/GarminExternalFolderAccessService.swift');
$coordinator = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/GarminSDCardImportCoordinator.swift');
$models = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Models/GarminSDCardImportModels.swift');
$settings = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/SettingsStore.swift');
$views = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Views/GarminSDCardImportViews.swift');
$log = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Views/OperationalWorkflowViews.swift');
$api = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/APIClient.swift');
$php = file_get_contents($root . '/src/GarminCsvEvidenceService.php');
$pending = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/CVRPendingGarminPersistence.swift');
$app = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/IPCACVRUnitApp.swift');

$checks = [
    'external folder access states modeled separately' =>
        str_contains($models, 'enum GarminExternalFolderAccessState')
        && str_contains($models, 'notConfigured')
        && str_contains($models, 'accessNeedsRestoration')
        && str_contains($models, 'configuredFolderEmpty'),

    'import states include workflow_linked outcomes' =>
        str_contains($models, 'uploadedLinkingPending')
        && str_contains($models, 'syncedAndLinked')
        && str_contains($models, 'alreadySynced')
        && str_contains($models, 'duplicateOfPendingImport')
        && str_contains($models, 'gpsOnly'),

    'classifier has broad import candidate path' =>
        str_contains($classifier, 'classifyImportCandidate(fileURL:')
        && str_contains($classifier, 'case unsupported')
        && str_contains($classifier, 'case unreadable')
        && str_contains($classifier, 'case unknown'),

    'folder access balances start/stop' =>
        str_contains($access, 'defer { token.stop() }')
        && str_contains($access, 'beginGarminSDCardAccess')
        && str_contains($access, 'willResignActiveNotification'),

    'copy never uploads from external URL' =>
        str_contains($coordinator, 'copyExternalFileToTemporary')
        && str_contains($coordinator, 'stageGarminCSV(from: copied)')
        && str_contains($coordinator, 'Never uploads directly from the external URL'),

    'settings persist bookmark without claiming connected' =>
        str_contains($settings, 'setGarminSDCardFolder')
        && str_contains($settings, 'clearGarminSDCardFolder')
        && str_contains($settings, 'minimalBookmark')
        && !str_contains($settings, 'Connected'),

    'knownHashes returns workflow_linked' =>
        str_contains($php, "'workflow_linked' => \$workflowFlightRecordUuid !== ''")
        && str_contains($php, "'workflow_flight_record_uuid' => \$workflowFlightRecordUuid")
        && str_contains($api, 'workflowFlightRecordUuid')
        && str_contains($api, 'workflowLinked'),

    'server status does not treat unlinked known hash as synced' =>
        str_contains($coordinator, 'uploadedLinkingPending')
        && str_contains($coordinator, 'match.workflowLinked == true'),

    'log row and browse entry points exist' =>
        str_contains($coordinator, 'func openFromLogRow')
        && str_contains($coordinator, 'func openBrowse')
        && str_contains($log, 'Import Garmin CSV from SD card')
        && str_contains($log, 'Label("FILES"')
        && str_contains($log, 'Label("SD CARD"'),

    'sheet titled Garmin SD Card' =>
        str_contains($views, 'Garmin SD Card')
        && str_contains($views, 'Show Excluded Files'),

    'pending persistence reused' =>
        str_contains($coordinator, 'stageGarminCSV')
        && str_contains($coordinator, 'uploadPendingGarminCSV')
        && str_contains($coordinator, 'retryPendingGarminCSV')
        && str_contains($pending, 'writeMetadata')
        && str_contains($app, 'GarminSDCardImportCoordinator'),

    'gps-only cannot import via canImport' =>
        str_contains($models, 'case .gpsOnly')
        && str_contains($models, 'classification.isDataRich'),

    'power-up / tiny files excluded from normal import' =>
        str_contains($coordinator, 'powerUpExclusionReason')
        && str_contains($coordinator, 'minimumEligibleDurationSeconds')
        && str_contains($coordinator, '5 * 60')
        && str_contains($coordinator, 'Too short'),
    'short CSV test override is explicit and removable' =>
        str_contains($coordinator, 'TEMPORARY TEST OVERRIDE')
        && str_contains($coordinator, 'allowShortCSVForPostFlightFlowTesting = true'),

    'scan publishes determinate progress' =>
        str_contains($coordinator, 'scanFilesProcessed')
        && str_contains($coordinator, 'scanFilesTotal')
        && str_contains($coordinator, 'scanProgress')
        && str_contains($views, 'ProgressView(value: progress)'),

    'scan reuses unchanged files via session cache' =>
        str_contains($coordinator, 'fileScanCache')
        && str_contains($coordinator, 'GarminSDCardScanCacheEntry')
        && str_contains($coordinator, 'candidateFromCache')
        && str_contains($coordinator, 'Using cache'),

    'import shows lasting result and invalidates stale server cache' =>
        str_contains($models, 'struct GarminSDCardImportResult')
        && str_contains($coordinator, 'lastImportResult')
        && str_contains($coordinator, 'invalidateServerHashCache')
        && str_contains($coordinator, 'Imported and uploaded')
        && str_contains($views, 'IMPORT COMPLETE')
        && str_contains($views, 'STORED — ACTION NEEDED'),
];

$failed = [];
foreach ($checks as $name => $ok) {
    if (!$ok) {
        $failed[] = $name;
    }
}

if ($failed) {
    fwrite(STDERR, "FAIL garmin SD card import contract:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

fwrite(STDOUT, "PASS garmin SD card import contract (" . count($checks) . " checks)\n");
