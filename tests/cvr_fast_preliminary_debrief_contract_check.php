<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = array();

function source(string $path): string
{
    $contents = @file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Missing file: ' . $path);
    }
    return $contents;
}

function expect(bool $condition, string $label, array &$failures): void
{
    if (!$condition) {
        $failures[] = $label;
    }
}

$catalogPath = $root . '/ipca-cvr-unit/IPCACVRUnit/Resources/faa_ca_az_nv_airports.json';
$catalog = json_decode(source($catalogPath), true);
$airports = is_array($catalog['airports'] ?? null) ? $catalog['airports'] : array();
$identifiers = array_column($airports, 'identifier');
expect(($catalog['source'] ?? '') === 'FAA NASR 28-Day Subscription', 'catalog identifies authoritative FAA source', $failures);
expect(($catalog['states'] ?? array()) === array('AZ', 'CA', 'NV'), 'catalog is scoped to CA/AZ/NV', $failures);
expect(count($airports) >= 700, 'catalog contains the regional airport population', $failures);
expect(in_array('KTRM', $identifiers, true) && in_array('KPSP', $identifiers, true), 'catalog includes KTRM and KPSP', $failures);
expect(
    array_reduce($airports, static fn(bool $ok, array $airport): bool =>
        $ok || is_array($airport['runways'] ?? null) && $airport['runways'] !== array(), false),
    'catalog includes runway metadata',
    $failures
);

$project = source($root . '/ipca-cvr-unit/IPCACVRUnit.xcodeproj/project.pbxproj');
$detector = source($root . '/ipca-cvr-unit/IPCACVRUnit/Services/FlightLandingCycleDetector.swift');
$gps = source($root . '/ipca-cvr-unit/IPCACVRUnit/Services/GPSLocationManager.swift');
$workflow = source($root . '/ipca-cvr-unit/IPCACVRUnit/Services/CVRWorkflowStore.swift');
$coordinator = source($root . '/ipca-cvr-unit/IPCACVRUnit/Services/CVRUnitCoordinator.swift');
$upload = source($root . '/ipca-cvr-unit/IPCACVRUnit/Services/UploadManager.swift');
expect(str_contains($project, 'faa_ca_az_nv_airports.json in Resources'), 'FAA catalog is bundled in the iOS target', $failures);
expect(str_contains($detector, 'Never restrict evidence-derived'), 'planned route does not constrain airport detection', $failures);
expect(str_contains($detector, 'airportIdentifier:'), 'GPS transitions carry airport provenance', $failures);
expect(str_contains($gps, 'evidenceUploadInterval') && str_contains($workflow, 'gps_position_sample'), 'GPS evidence uploads periodically in flight', $failures);
expect(str_contains($coordinator, 'uploadLiveAudioSegments') && str_contains($upload, 'uploadFinalizedLiveAudioSegments'), 'finalized audio segments upload during recording', $failures);
expect(
    str_contains($upload, 'liveAudioPendingDefaultsKey')
        && str_contains($upload, 'retryQueuedLiveAudioSegments')
        && str_contains($coordinator, 'retryQueuedLiveAudioSegments(settings: settings)'),
    'live segment retries survive connectivity loss and process restart',
    $failures
);

$liveService = source($root . '/src/CvrLiveAudioSegmentService.php');
$liveEndpoint = source($root . '/public/api/cvr/live_audio_segment.php');
$orchestrator = source($root . '/src/CvrAutoReconstructionOrchestrator.php');
$bundleService = source($root . '/src/ManualReconstructionBundleService.php');
$debrief = source($root . '/src/FlightDebriefService.php');
$readModel = source($root . '/src/CvrDataIntakeReadService.php');
$legReview = source($root . '/src/CvrOperationalSessionLegReviewService.php');
$cockpitRecorder = source($root . '/src/CockpitRecorderService.php');
$productionEvidence = source($root . '/src/AviationEvidence/ProductionTranscriptionEvidenceService.php');
$reconstruction = source($root . '/src/CockpitReconstructionService.php');
$replayPage = source($root . '/public/admin/cockpit_recorder_replay.php');
expect(str_contains($liveEndpoint, 'DeviceAuthService') && str_contains($liveService, 'transcribeOpenAiAudioStructured'), 'server authenticates and transcribes live segments', $failures);
expect(str_contains($bundleService, 'freezePreliminary') && str_contains($orchestrator, 'considerPreliminary'), 'shutdown can freeze a preliminary bundle without Garmin', $failures);
expect(str_contains($bundleService, 'lockLiveTranscript') && str_contains($orchestrator, 'garmin_blocking') && str_contains($orchestrator, 'false'), 'incremental transcript can drive a nonblocking preliminary debrief', $failures);
expect(str_contains($debrief, "'evidence_stage' => \$evidenceStage") && str_contains($debrief, 'PRELIMINARY shutdown debrief'), 'debrief records and explains its evidence stage', $failures);
expect(
    str_contains($readModel, 'bundle_evidence_stage')
        && str_contains($readModel, 'debrief_evidence_stage'),
    'admin read model distinguishes preliminary and enriched evidence',
    $failures
);
expect(
    strpos($productionEvidence, '$execution->complete();')
        < strpos($productionEvidence, '$this->maybeAutoQueueDebrief($recordingId);'),
    'debrief queue runs only after the readable processing run becomes publishable',
    $failures
);
expect(!str_contains($legReview, 'A verified Garmin CSV must be linked before legs can be accepted.'), 'leg acceptance is not gated on Garmin', $failures);
expect(str_contains($legReview, 'ios_gps_provisional') && str_contains($legReview, 'reconciliation_required'), 'GPS-derived legs reconcile when Garmin arrives', $failures);
expect(
    str_contains($workflow, 'eventType: "gps_position_sample"')
        && str_contains($reconstruction, "if (\$type === 'gps_position_sample')")
        && str_contains($replayPage, 'function isReplayTelemetryEvent(action)')
        && substr_count($replayPage, 'isReplayTelemetryEvent(') >= 3,
    'GPS position samples remain evidence but are excluded from replay actions and markers',
    $failures
);
expect(
    str_contains($cockpitRecorder, 'isNoSpeechTranscriptionResult')
        && str_contains($cockpitRecorder, "storeTranscriptionChunk(\$recordingId, \$index, \$start, \$end, 'ready', 0, '', null)"),
    'silent transcription chunks complete without blocking the full transcript and debrief',
    $failures
);

if ($failures !== array()) {
    fwrite(STDERR, "cvr_fast_preliminary_debrief_contract_check FAILED\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, ' - ' . $failure . PHP_EOL);
    }
    exit(1);
}

fwrite(STDOUT, "cvr_fast_preliminary_debrief_contract_check OK\n");
