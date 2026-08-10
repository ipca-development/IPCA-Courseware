<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$orchestrator = file_get_contents($root . '/src/CvrAutoReconstructionOrchestrator.php') ?: '';
$dispatchService = file_get_contents($root . '/src/CvrDispatchIntakeService.php') ?: '';
$garminService = file_get_contents($root . '/src/GarminCsvEvidenceService.php') ?: '';
$recorderService = file_get_contents($root . '/src/CockpitRecorderService.php') ?: '';
$debriefQueue = file_get_contents($root . '/src/CockpitRecorderDebriefQueueService.php') ?: '';
$bundleService = file_get_contents($root . '/src/ManualReconstructionBundleService.php') ?: '';

$checks = array(
    'orchestrator class and env gate exist' =>
        str_contains($orchestrator, 'final class CvrAutoReconstructionOrchestrator')
        && str_contains($orchestrator, 'CW_AUTO_FREEZE_ON_INTAKE')
        && str_contains($orchestrator, 'function autoFreezeEnabled')
        && str_contains($orchestrator, 'function safeConsider')
        && str_contains($orchestrator, 'function consider'),
    'orchestrator freezes through existing ManualReconstructionBundleService' =>
        str_contains($orchestrator, 'freezeAndPrepare(')
        && str_contains($orchestrator, 'require_once __DIR__ . \'/ManualReconstructionBundleService.php\'')
        && str_contains($bundleService, 'function freezeAndPrepare'),
    'orchestrator auto-starts flight reconstruction worker' =>
        str_contains($orchestrator, 'ensureFlightReconstructionStarted')
        && str_contains($orchestrator, 'run_cockpit_recorder_reconstruction.php')
        && str_contains($orchestrator, 'reconstruction_auto_started')
        && str_contains($orchestrator, 'replay-source-mode=g3x_only'),
    'orchestrator queues debrief via existing Pass 4 path' =>
        str_contains($orchestrator, 'lockAndQueueDebrief(')
        && str_contains($orchestrator, 'hasReadableTranscript(')
        && str_contains($orchestrator, 'CockpitRecorderDebriefQueueService')
        && str_contains($debriefQueue, 'function lockAndQueueDebrief'),
    'orchestrator waits for dispatch and audio but not Garmin for preliminary debrief' =>
        str_contains($orchestrator, "'waiting_for_dispatch'")
        && str_contains($orchestrator, "'waiting_for_audio'")
        && str_contains($orchestrator, 'considerPreliminary')
        && str_contains($orchestrator, "'garmin_required' => false")
        && str_contains($orchestrator, 'flight_session_uid')
        && str_contains($orchestrator, 'workflow_flight_record_uuid'),
    'orchestrator is idempotent for matching triad and existing debrief' =>
        str_contains($orchestrator, 'findMatchingTriadBundle')
        && str_contains($orchestrator, 'findUnlockedBundleForRecording')
        && str_contains($orchestrator, "'already_debriefed'"),
    'dispatch intake hooks orchestrator without throwing into response' =>
        str_contains($dispatchService, 'CvrAutoReconstructionOrchestrator::safeConsider')
        && strpos($dispatchService, 'CvrAutoReconstructionOrchestrator::safeConsider')
            > strpos($dispatchService, '$this->pdo->commit()'),
    'garmin finalize hooks orchestrator on new and duplicate linked CSVs' =>
        substr_count($garminService, 'CvrAutoReconstructionOrchestrator::safeConsider') >= 2
        && str_contains($garminService, "status = 'duplicate'")
        && str_contains($garminService, "status = 'finalized'"),
    'audio store hooks orchestrator after session metadata' =>
        str_contains($recorderService, 'CvrAutoReconstructionOrchestrator::safeConsider')
        && strpos($recorderService, 'CvrAutoReconstructionOrchestrator::safeConsider')
            < strpos($recorderService, 'spawnTranscriptionWorker'),
    'pass4 auto debrief path auto-freezes before unlocked bundle scan' =>
        str_contains($debriefQueue, 'CvrAutoReconstructionOrchestrator::safeConsider')
        && strpos($debriefQueue, 'CvrAutoReconstructionOrchestrator::safeConsider')
            < strpos($debriefQueue, 'transcript_snapshot_id IS NULL'),
    'server-only change does not require iOS app updates' =>
        !str_contains($orchestrator, 'ipca-cvr-unit')
        && !str_contains($orchestrator, 'UploadManager')
        && !str_contains($orchestrator, 'APIClient.swift'),
);

$failed = array();
foreach ($checks as $name => $ok) {
    if (!$ok) {
        $failed[] = $name;
    }
}

if ($failed) {
    fwrite(STDERR, "cvr_auto_reconstruction_orchestrator_contract_check FAILED:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "cvr_auto_reconstruction_orchestrator_contract_check OK (" . count($checks) . " checks)\n";
exit(0);
