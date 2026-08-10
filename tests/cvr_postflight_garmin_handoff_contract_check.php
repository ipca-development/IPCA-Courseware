#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$models = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Models/CVRWorkflowModels.swift');
$store = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/CVRWorkflowStore.swift');
$coordinator = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/CVRUnitCoordinator.swift');
$garmin = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/GarminSDCardImportCoordinator.swift');
$uploads = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/UploadManager.swift');
$views = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Views/OperationalWorkflowViews.swift');
$intake = file_get_contents($root . '/src/CvrDispatchIntakeService.php');

$checks = [
    'handoff is persisted in workflow state' =>
        str_contains($models, 'struct CVRPostFlightGarminHandoff: Codable')
        && str_contains($models, 'var postFlightGarminHandoff: CVRPostFlightGarminHandoff?'),
    'stable beacon loss starts a 30 second Garmin countdown' =>
        str_contains($coordinator, 'beginPostFlightGarminCountdown')
        && str_contains($store, 'now.addingTimeInterval(30)'),
    'beacon return cancels only the active pre-expiry countdown' =>
        str_contains($coordinator, 'cancelPostFlightGarminCountdownIfBeaconReturned')
        && str_contains($store, 'now < handoff.countdownEndsAt'),
    'countdown prohibits early card removal and guides insertion' =>
        str_contains($views, 'WARNING! WAITING FOR GARMIN DATA')
        && str_contains($views, 'DO NOT TAKE THE SD CARD OUT OF THE GARMIN UNIT YET.')
        && str_contains($views, 'PLEASE INSERT THE SD CARD IN THE CVR UNIT')
        && str_contains($views, 'title: "SD CARD INSERTED"')
        && str_contains($views, 'color: CVROperationalPalette.primaryBlue'),
    'guided import is locked to exact flight evidence' =>
        str_contains($garmin, 'openGuidedFromLogRow')
        && str_contains($garmin, 'The mandatory Garmin handoff must remain linked to its completed flight.')
        && str_contains($garmin, '!candidate.isRecommended || candidate.matchWarning != nil'),
    'successful upload gates leg review and card return confirmation' =>
        str_contains($views, 'GARMIN CSV UPLOAD SUCCESSFUL')
        && str_contains($views, 'CONTINUE TO LEG VERIFICATION')
        && str_contains($views, 'showingLegReview = true')
        && str_contains($views, 'reconcilePostFlightGarminUploadState')
        && str_contains($store, 'reconcilePostFlightGarminUpload')
        && str_contains($views, 'I CONFIRM THE SD CARD IS BACK IN THE GARMIN DEVICE'),
    'verified upload clears retry state before Log refresh and advances globally' =>
        strpos($models, 'clearPendingGarminAfterVerifiedSuccess(fileURL: pending.fileURL)')
            < strpos($models, 'await refresh(settings: settings)', strpos($models, 'clearPendingGarminAfterVerifiedSuccess(fileURL: pending.fileURL)'))
        && str_contains($views, '.onChange(of: garminSDCard.lastImportResult)')
        && str_contains($views, 'handoff.phase == .selectingCSV || handoff.phase == .uploadingCSV'),
    'operational Dispatch excludes synthetic consent evidence' =>
        str_contains($uploads, '"consents": isOperationalSession ? []')
        && str_contains($intake, 'if ($isOperationalSession) {')
        && str_contains($intake, "? 'not_required'"),
    'Dispatch response is not delayed by reconstruction orchestration' =>
        !str_contains($intake, 'CvrAutoReconstructionOrchestrator::safeConsider'),
    'voiding is unavailable outside Admin mode' =>
        str_contains($views, 'FlightLogView(adminUnlocked: adminUnlocked)')
        && str_contains($views, 'guard adminUnlocked else { return }'),
];

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
if ($failed !== []) {
    fwrite(STDERR, "Post-flight Garmin handoff contract FAILED\n - " . implode("\n - ", $failed) . "\n");
    exit(1);
}

echo "Post-flight Garmin handoff contract passed (" . count($checks) . " checks).\n";
