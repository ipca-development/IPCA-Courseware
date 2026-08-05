<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/CvrIntakeDisplayService.php';
require_once __DIR__ . '/../src/ManualReconstructionBundleService.php';
require_once __DIR__ . '/../src/CvrDataIntakeReadService.php';

$displaySource = file_get_contents(__DIR__ . '/../src/CvrIntakeDisplayService.php');
if ($displaySource === false
    || !str_contains($displaySource, 'dispatchOptionLabel')
    || !str_contains($displaySource, ' LT')) {
    fwrite(STDERR, "CvrIntakeDisplayService contract failed.\n");
    exit(1);
}

$bundleSource = file_get_contents(__DIR__ . '/../src/ManualReconstructionBundleService.php');
if ($bundleSource === false
    || !str_contains($bundleSource, 'supersedeBundleSources')
    || !str_contains($bundleSource, "'admin_manual'")) {
    fwrite(STDERR, "ManualReconstructionBundleService contract failed.\n");
    exit(1);
}

$intakeSource = file_get_contents(__DIR__ . '/../src/CvrDataIntakeReadService.php');
if ($intakeSource === false
    || !str_contains($intakeSource, 'off_block_utc')
    || !str_contains($intakeSource, 'derivedOnBlockUtc')
    || !str_contains($intakeSource, 'off_block_plus_hobbs_increment')
    || !str_contains($intakeSource, "$.evidence.off_block_utc")
    || !str_contains($intakeSource, 'LOWER(fe.workflow_flight_record_uuid)')
    || !str_contains($intakeSource, "'admin_manual'")) {
    fwrite(STDERR, "CvrDataIntakeReadService contract failed.\n");
    exit(1);
}

$uploadSource = file_get_contents(__DIR__ . '/../src/CvrIntakeAdminUploadService.php');
if ($uploadSource === false
    || !str_contains($uploadSource, 'uploadGarminCsv')
    || !str_contains($uploadSource, 'uploadAudio')
    || !str_contains($uploadSource, 'CvrAudioIntakeMetricsService')
    || !str_contains($uploadSource, "'admin_manual'")) {
    fwrite(STDERR, "CvrIntakeAdminUploadService contract failed.\n");
    exit(1);
}

$metricsSource = file_get_contents(__DIR__ . '/../src/CvrAudioIntakeMetricsService.php');
if ($metricsSource === false
    || !str_contains($metricsSource, 'enrichRows')
    || !str_contains($metricsSource, 'inputMix')
    || !str_contains($metricsSource, 'crewLines')) {
    fwrite(STDERR, "CvrAudioIntakeMetricsService contract failed.\n");
    exit(1);
}

$statusApiSource = file_get_contents(__DIR__ . '/../public/admin/api/cockpit_recorder_intake_audio_status.php');
if ($statusApiSource === false
    || !str_contains($statusApiSource, 'transcription_progress')
    || !str_contains($statusApiSource, 'can_view_transcript')) {
    fwrite(STDERR, "cockpit_recorder_intake_audio_status contract failed.\n");
    exit(1);
}

$reprocessApiSource = file_get_contents(__DIR__ . '/../public/admin/api/cockpit_recorder_intake_reprocess_transcript.php');
if ($reprocessApiSource === false
    || !str_contains($reprocessApiSource, 'requeueTranscription')
    || !str_contains($reprocessApiSource, 'cleanupStoredTranscript')) {
    fwrite(STDERR, "cockpit_recorder_intake_reprocess_transcript contract failed.\n");
    exit(1);
}

$pageSource = file_get_contents(__DIR__ . '/../public/admin/master_logbook_intake.php');
if ($pageSource === false
    || !str_contains($pageSource, 'upload_manual_garmin_csv')
    || !str_contains($pageSource, 'upload_manual_audio')
    || !str_contains($pageSource, 'supersede_reconstruction_bundle')
    || !str_contains($pageSource, 'dispatchOptionLabel')
    || !str_contains($pageSource, 'data-audio-short-toggle')
    || !str_contains($pageSource, 'intake-audio-row-short')
    || !str_contains($pageSource, 'cockpit_recorder_intake_transcript.php')
    || !str_contains($pageSource, 'cockpit_recorder_intake_reprocess_transcript.php')
    || !str_contains($pageSource, 'data-audio-transcript-reprocess')
    || !str_contains($pageSource, 'data-audio-transcript-cleanup')
    || !str_contains($pageSource, 'cockpit_recorder_intake_audio_status.php')
    || !str_contains($pageSource, 'CvrAudioIntakeMetricsService')
    || !str_contains($pageSource, 'intake-audio-crew')
    || !str_contains($pageSource, 'data-audio-transcription-progress')
    || !str_contains($pageSource, 'cvr_intake_audio_relevant_error')) {
    fwrite(STDERR, "master_logbook_intake contract failed.\n");
    exit(1);
}

echo "cvr_intake_admin_contract_check: ok\n";
