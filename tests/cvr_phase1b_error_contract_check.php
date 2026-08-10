<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/CvrSyncException.php';

$root = dirname(__DIR__);
$dispatchEndpoint = file_get_contents($root . '/public/api/cvr/dispatch_sync.php') ?: '';
$evidenceEndpoint = file_get_contents($root . '/public/api/cvr/flight_events_sync.php') ?: '';
$dispatchService = file_get_contents($root . '/src/CvrDispatchIntakeService.php') ?: '';
$evidenceService = file_get_contents($root . '/src/CvrWorkflowEvidenceIntakeService.php') ?: '';
$apiClient = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/APIClient.swift') ?: '';
$uploadManager = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/UploadManager.swift') ?: '';
$workflowStore = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/CVRWorkflowStore.swift') ?: '';
$workflowModels = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Models/CVRWorkflowModels.swift') ?: '';
$app = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/IPCACVRUnitApp.swift') ?: '';
$coordinator = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/CVRUnitCoordinator.swift') ?: '';
$settings = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/SettingsStore.swift') ?: '';
$contentView = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Views/ContentView.swift') ?: '';
$workflowViews = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Views/OperationalWorkflowViews.swift') ?: '';

$temporary = (new CvrTemporaryTechnicalFailure())->payload('request-temp');
$dependency = (new CvrDependencyNotReady())->payload('request-dependency');
$authentication = (new CvrAuthenticationRequired())->payload('request-auth');
$correction = (new CvrUserCorrectionRequired('Ending Hobbs is invalid.'))->payload('request-correction');
$technical = (new CvrTechnicalReviewRequired())->payload('request-review');

$checks = array(
    'unknown technical failure is retryable and never user action' =>
        ($temporary['error_code'] ?? '') === 'TEMPORARY_TECHNICAL_FAILURE'
        && ($temporary['retryable'] ?? false) === true
        && ($temporary['user_action_required'] ?? true) === false
        && ($temporary['request_id'] ?? '') === 'request-temp'
        && str_contains($dispatchEndpoint, 'catch (Throwable $e)')
        && str_contains($dispatchEndpoint, 'new CvrTemporaryTechnicalFailure(')
        && str_contains($evidenceEndpoint, 'new CvrTemporaryTechnicalFailure()'),
    'dependency remains automatically retryable' =>
        ($dependency['error_code'] ?? '') === 'DEPENDENCY_NOT_READY'
        && ($dependency['retryable'] ?? false) === true
        && ($dependency['user_action_required'] ?? true) === false
        && str_contains($workflowStore, 'case "TEMPORARY_TECHNICAL_FAILURE", "DEPENDENCY_NOT_READY":')
        && str_contains($workflowStore, 'state = .queued'),
    'authentication pauses workflow synchronization only' =>
        ($authentication['error_code'] ?? '') === 'AUTHENTICATION_REQUIRED'
        && ($authentication['retryable'] ?? true) === false
        && ($authentication['user_action_required'] ?? true) === false
        && str_contains($uploadManager, 'workflowAuthenticationPausedCredential')
        && str_contains($workflowStore, 'outcome = .authenticationPaused')
        && str_contains($workflowStore, 'state = .queued'),
    'user correction is explicit and component scoped' =>
        ($correction['error_code'] ?? '') === 'USER_CORRECTION_REQUIRED'
        && ($correction['user_action_required'] ?? false) === true
        && str_contains($workflowStore, 'func recordWorkflowUploadFailure(id: String')
        && str_contains($workflowStore, 'state = failure.userActionRequired ? .needsUserAction : .failed')
        && str_contains($workflowModels, 'var userActionRequired: Bool?'),
    'known nonretryable technical failure is not crew action' =>
        ($technical['error_code'] ?? '') === 'TECHNICAL_REVIEW_REQUIRED'
        && ($technical['retryable'] ?? true) === false
        && ($technical['user_action_required'] ?? true) === false
        && str_contains($workflowStore, 'case "TECHNICAL_REVIEW_REQUIRED":')
        && str_contains($workflowStore, 'state = .failed'),
    'duplicate dispatch returns original receipt and canonical identifiers' =>
        str_contains($dispatchService, "\$receiptUuid = (string)\$existingVersion['receipt_uuid'];")
        && str_contains($dispatchService, "'error_code' => \$alreadyPresent ? 'DUPLICATE_ALREADY_VERIFIED' : null")
        && str_contains($dispatchService, "'server_dispatch_id' => \$dispatchId")
        && str_contains($dispatchService, "'flight_record_uuid' => \$normalized['flight_record_uuid']")
        && str_contains($uploadManager, 'failure.errorCode == "DUPLICATE_ALREADY_VERIFIED"')
        && str_contains($uploadManager, 'workflow.persistReconciliationMatch('),
    'duplicate evidence returns original receipt and canonical identifiers' =>
        str_contains($evidenceService, "\$receipt = (string)\$existing['receipt_uuid'];")
        && str_contains($evidenceService, "'error_code' => \$alreadyPresent ? 'DUPLICATE_ALREADY_VERIFIED' : null")
        && str_contains($evidenceService, "'component_uuid' => \$normalized['component_uuid']")
        && str_contains($evidenceService, "'receipt_id' => \$receipt"),
    'structured JSON errors take precedence in APIClient' =>
        str_contains($apiClient, 'JSONDecoder().decode(APISynchronizationFailure.self, from: data)')
        && strpos($apiClient, 'throw APIClientError.synchronization(failure)')
            < strpos($apiClient, 'throw APIClientError.badResponse("HTTP'),
    'legacy text-only responses retain compatibility classification' =>
        str_contains($workflowStore, 'Compatibility only for older endpoints')
        && str_contains($workflowStore, 'normalized.contains("http 5")')
        && str_contains($workflowStore, 'Self.isConnectivityFailure'),
    'unchanged credential foreground permits one probe' =>
        str_contains($app, 'trigger: .appForeground')
        && str_contains($uploadManager, 'case .appForeground, .networkRestored, .explicitRetry:')
        && str_contains($uploadManager, 'guard trigger.permitsAuthenticationProbe')
        && str_contains($uploadManager, '!workflowAuthenticationProbeInFlight'),
    'unchanged credential network restoration permits one probe' =>
        str_contains($coordinator, 'let networkWasRestored = canUpload && !self.lastNetworkUploadAvailable')
        && str_contains($coordinator, 'networkWasRestored ? .networkRestored : .routine'),
    'explicit synchronization retry permits one probe' =>
        str_contains($uploadManager, 'func retryWorkflowSynchronization(')
        && str_contains($uploadManager, 'trigger: .explicitRetry')
        && str_contains($workflowViews, 'uploadManager.retryWorkflowSynchronization('),
    'successful probe clears pause and awakens remaining workflow queue' =>
        substr_count($uploadManager, 'clearWorkflowAuthenticationPause()') >= 3
        && str_contains($uploadManager, 'uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)'),
    'authentication failure restores pause without failing component' =>
        str_contains($uploadManager, 'if outcome == .authenticationPaused')
        && str_contains($uploadManager, 'workflowAuthenticationPausedCredential = currentCredential')
        && str_contains($workflowStore, 'case "AUTHENTICATION_REQUIRED":')
        && str_contains($workflowStore, 'state = .queued'),
    'paused routine scans cannot hammer workflow API' =>
        str_contains($uploadManager, 'case .routine, .enrollmentSucceeded:')
        && str_contains($uploadManager, 'activeWorkflowAuthenticationRequestIDs.isEmpty')
        && str_contains($uploadManager, 'workflowAuthenticationProbeInFlight = true'),
    'concurrent recovery triggers share one active probe guard' =>
        str_contains($uploadManager, 'private var workflowAuthenticationProbeInFlight = false')
        && str_contains($uploadManager, 'private var activeWorkflowAuthenticationRequestIDs: Set<String> = []')
        && str_contains($uploadManager, 'activeWorkflowAuthenticationRequestIDs.remove(component.id)'),
    'successful enrollment clears pause and starts synchronization' =>
        str_contains($settings, 'func enrollDevice() async -> Bool')
        && str_contains($contentView, 'if await settings.enrollDevice()')
        && str_contains($contentView, 'trigger: .enrollmentSucceeded')
        && str_contains($uploadManager, 'trigger == .enrollmentSucceeded'),
    'Garmin and recording uploads remain outside workflow authentication gate' =>
        str_contains($uploadManager, 'let usesWorkflowAuthentication = component.componentType != "garmin_csv"')
        && str_contains($uploadManager, 'if component.componentType == "garmin_csv"')
        && str_contains($uploadManager, 'func uploadPending(store: RecordingStore'),
);

$failed = array();
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}
if ($failed !== array()) {
    fwrite(STDERR, 'Failed Phase 1B synchronization checks: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}
echo 'OK: Phase 1B synchronization error contract checks passed.' . PHP_EOL;
