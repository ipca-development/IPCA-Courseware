<?php
declare(strict_types=1);

require_once __DIR__ . '/CockpitRecorderService.php';
require_once __DIR__ . '/AviationEvidence/EvidenceSchema.php';
require_once __DIR__ . '/AviationEvidence/ProcessingRunRepository.php';
require_once __DIR__ . '/AviationEvidence/EvidenceWorkerDiagnosticsService.php';
require_once __DIR__ . '/AviationEvidence/InterpretationRevisionRepository.php';
require_once __DIR__ . '/AviationEvidence/SpeechSegmentRepository.php';
require_once __DIR__ . '/AviationEvidence/DisplayBlockRepository.php';
require_once __DIR__ . '/AviationEvidence/EvidenceProgressEstimator.php';

final class CockpitRecorderEvidenceQueueService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly CockpitRecorderService $recorder,
        private readonly ProcessingRunRepository $processingRuns,
        private readonly EvidenceWorkerDiagnosticsService $diagnostics,
    ) {
    }

    /**
     * @param array<string,mixed> $recording
     */
    public function needsEvidenceProcessing(array $recording): bool
    {
        if ((string)($recording['transcription_status'] ?? '') !== 'ready') {
            return false;
        }
        if (!EvidenceSchema::persistenceReady($this->pdo) || EvidenceSchema::skipProductionPersist()) {
            return false;
        }
        $recordingId = (int)($recording['id'] ?? 0);
        if ($recordingId <= 0) {
            return false;
        }
        return $this->processingRuns->findLatestPublishableForRecording($recordingId) === null;
    }

    public function isEvidenceInProgress(int $recordingId): bool
    {
        if ($recordingId <= 0) {
            return false;
        }
        return $this->diagnostics->findLiveRunningForRecording($recordingId) !== null;
    }

    /**
     * Start evidence processing once after transcription. Does not retry stalled runs.
     *
     * @return array<string,mixed>
     */
    public function ensureQueued(int $recordingId): array
    {
        if ($recordingId <= 0) {
            return array('ok' => false, 'queued' => false, 'reason' => 'invalid_recording_id');
        }

        $recording = $this->recorder->recordingByAnyId((string)$recordingId);
        if (!is_array($recording)) {
            return array('ok' => false, 'queued' => false, 'reason' => 'recording_not_found');
        }
        if (!$this->needsEvidenceProcessing($recording)) {
            $publishable = $this->processingRuns->findLatestPublishableForRecording($recordingId);
            return array(
                'ok' => true,
                'queued' => false,
                'reason' => $publishable !== null ? 'already_publishable' : 'not_needed',
                'processing_run_id' => is_array($publishable) ? (int)($publishable['id'] ?? 0) : null,
            );
        }

        if ($this->isEvidenceInProgress($recordingId)) {
            return array('ok' => true, 'queued' => false, 'reason' => 'already_running');
        }

        $runningRun = $this->processingRuns->findRunningForRecording($recordingId);
        $failure = $this->diagnostics->diagnose($recordingId, $recording, $runningRun);
        if (is_array($failure)) {
            return array(
                'ok' => true,
                'queued' => false,
                'reason' => 'needs_manual_restart',
                'failure' => $failure,
            );
        }

        if ($this->processingRuns->findLatestFailedForRecording($recordingId) !== null) {
            return array('ok' => true, 'queued' => false, 'reason' => 'needs_manual_restart');
        }

        $spawned = $this->spawnWorker($recordingId);
        return array(
            'ok' => true,
            'queued' => $spawned,
            'reason' => $spawned ? 'worker_spawned' : 'worker_spawn_failed',
        );
    }

    public function spawnWorker(int $recordingId): bool
    {
        if ($recordingId <= 0) {
            return false;
        }
        if (!function_exists('exec')) {
            return false;
        }

        $php = CockpitRecorderService::findBinary(array(
            trim((string)PHP_BINDIR) !== '' ? rtrim((string)PHP_BINDIR, '/') . '/php' : '',
            '/usr/bin/php',
            '/usr/local/bin/php',
            '/opt/homebrew/bin/php',
            'php',
        ));
        if ($php === '') {
            return false;
        }

        $script = realpath(__DIR__ . '/../scripts/run_cockpit_recorder_evidence.php');
        if ($script === false) {
            return false;
        }

        $logDir = CockpitRecorderService::projectRoot() . '/storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        if (!is_dir($logDir) || !is_writable($logDir)) {
            return false;
        }

        $logFile = $logDir . '/cockpit_evidence_' . $recordingId . '.log';
        @file_put_contents($logFile, '[' . gmdate('c') . '] Spawning cockpit evidence worker.' . PHP_EOL, FILE_APPEND);

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $cmd = 'start /B "" '
                . escapeshellarg($php) . ' '
                . escapeshellarg($script) . ' '
                . '--recording-id=' . $recordingId
                . ' >> ' . escapeshellarg($logFile) . ' 2>&1';
        } else {
            $cmd = 'nohup ' . escapeshellarg($php) . ' '
                . escapeshellarg($script) . ' '
                . '--recording-id=' . $recordingId
                . ' >> ' . escapeshellarg($logFile) . ' 2>&1 < /dev/null & echo $!';
        }

        @file_put_contents($logFile, '[' . gmdate('c') . '] Command: ' . $cmd . PHP_EOL, FILE_APPEND);
        exec($cmd, $output, $exitCode);
        if ($exitCode !== 0) {
            @file_put_contents($logFile, '[' . gmdate('c') . '] Worker spawn exited with code ' . $exitCode . PHP_EOL, FILE_APPEND);
            return false;
        }
        return true;
    }

    /**
     * Explicit operator restart: diagnose, seal any dead run, then start a fresh attempt.
     *
     * @return array<string,mixed>
     */
    public function retryProcessing(int $recordingId): array
    {
        if ($recordingId <= 0) {
            return array('ok' => false, 'error' => 'Invalid recording id.');
        }

        $recording = $this->recorder->recordingByAnyId((string)$recordingId);
        if (!is_array($recording)) {
            return array('ok' => false, 'error' => 'Recording not found.');
        }
        if (!$this->needsEvidenceProcessing($recording)) {
            return array(
                'ok' => true,
                'skipped' => true,
                'reason' => 'not_needed',
                'pipeline' => $this->publicStatusForRecording($recording),
            );
        }

        $runningRuns = $this->processingRuns->listRunningForRecording($recordingId);
        $diagnosis = null;
        foreach ($runningRuns as $runningRun) {
            if ($this->diagnostics->isRunLive($runningRun)) {
                return array(
                    'ok' => false,
                    'error' => 'Evidence processing is still running for this recording.',
                    'pipeline' => $this->publicStatusForRecording($recording),
                );
            }
            $runDiagnosis = $this->diagnostics->diagnose($recordingId, $recording, $runningRun);
            if ($diagnosis === null && is_array($runDiagnosis)) {
                $diagnosis = $runDiagnosis;
            }
            $this->diagnostics->sealDiagnosedFailure(
                (int)($runningRun['id'] ?? 0),
                is_array($runDiagnosis) ? $runDiagnosis : array(
                    'code' => 'operator_restart',
                    'reason' => 'Evidence processing was restarted by an operator.',
                    'detail' => 'The previous run was sealed before starting a new attempt.',
                    'phase' => trim((string)($runningRun['current_phase'] ?? '')) ?: null,
                    'log_excerpt' => null,
                    'can_restart' => true,
                )
            );
        }

        if ($this->isEvidenceInProgress($recordingId)) {
            return array(
                'ok' => false,
                'error' => 'Evidence processing is still running for this recording.',
                'pipeline' => $this->publicStatusForRecording($recording),
            );
        }

        require_once __DIR__ . '/AviationEvidence/ProductionTranscriptionEvidenceService.php';
        $inlineError = null;
        $inlineResult = null;
        try {
            @set_time_limit(0);
            $inlineResult = ProductionTranscriptionEvidenceService::fromPdo($this->pdo)
                ->persistAfterTranscription($recordingId, $recording, true);
            if (($inlineResult['reason'] ?? '') === 'in_progress') {
                $inlineError = 'Evidence processing is already running.';
            } elseif (empty($inlineResult['ok']) && empty($inlineResult['skipped'])) {
                $inlineError = (string)($inlineResult['error'] ?? $inlineResult['reason'] ?? 'Evidence processing failed.');
            }
        } catch (Throwable $e) {
            $inlineError = $e->getMessage();
        }

        $recording = $this->recorder->recordingByAnyId((string)$recordingId) ?? $recording;
        $pipeline = $this->publicStatusForRecording($recording);

        return array(
            'ok' => $inlineError === null,
            'recording_id' => $recordingId,
            'diagnosis' => $diagnosis,
            'inline_result' => $inlineResult,
            'pipeline' => $pipeline,
            'error' => $inlineError,
        );
    }

    /**
     * @param array<string,mixed> $recording
     * @return array<string,mixed>
     */
    public function publicStatusForRecording(array $recording): array
    {
        $recordingId = (int)($recording['id'] ?? 0);
        $transcriptionStatus = strtolower(trim((string)($recording['transcription_status'] ?? '')));
        $transcriptionProgress = max(0, min(100, (int)($recording['transcription_progress'] ?? 0)));
        $publishableRun = $this->processingRuns->findLatestPublishableForRecording($recordingId);
        $runningRun = $this->processingRuns->findRunningForRecording($recordingId);
        $needsEvidence = $this->needsEvidenceProcessing($recording);
        $evidenceInProgress = $needsEvidence && $this->isEvidenceInProgress($recordingId);

        $pipelineStage = 'legacy';
        if (in_array($transcriptionStatus, array('queued', 'transcribing', 'pending'), true)) {
            $pipelineStage = 'transcribing';
        } elseif ($needsEvidence || $evidenceInProgress) {
            $pipelineStage = 'processing_evidence';
        } elseif ($publishableRun !== null) {
            $pipelineStage = (int)($recording['published_transcript_version_id'] ?? 0) > 0 ? 'published' : 'publishable';
        } elseif ($transcriptionStatus === 'ready') {
            $pipelineStage = 'transcribed';
        } elseif ($transcriptionStatus === 'failed') {
            $pipelineStage = 'failed';
        }

        $evidenceStep = null;
        $evidenceStepLabel = null;
        $evidenceProgress = null;
        $evidenceEstimatedRemainingSeconds = null;
        $evidenceElapsedSeconds = null;
        if ($pipelineStage === 'processing_evidence') {
            $liveRun = $evidenceInProgress ? ($runningRun ?? $this->diagnostics->findLiveRunningForRecording($recordingId)) : $runningRun;
            $evidenceStep = $this->inferEvidenceStep($recordingId, is_array($liveRun) ? $liveRun : $runningRun);
            $evidenceStepLabel = $this->evidenceStepLabel($evidenceStep, is_array($liveRun) ? $liveRun : $runningRun);
            $progressEstimate = EvidenceProgressEstimator::fromPdo($this->pdo)->estimate(
                $recording,
                $evidenceStep,
                is_array($liveRun) ? $liveRun : $runningRun
            );
            $evidenceProgress = (int)($progressEstimate['evidence_progress'] ?? 0);
            $evidenceEstimatedRemainingSeconds = (int)($progressEstimate['evidence_estimated_remaining_seconds'] ?? 0);
            $evidenceElapsedSeconds = (int)($progressEstimate['evidence_elapsed_seconds'] ?? 0);
        }

        $workerFailure = $this->diagnostics->diagnose(
            $recordingId,
            $recording,
            $runningRun,
            $evidenceStepLabel
        );
        if (is_array($workerFailure)) {
            $evidenceInProgress = false;
        }

        $displayStatus = is_array($workerFailure) && $needsEvidence
            ? 'evidence_failed'
            : ($pipelineStage === 'processing_evidence' ? 'processing_evidence' : $transcriptionStatus);
        $displayProgress = $pipelineStage === 'transcribing'
            ? $transcriptionProgress
            : ($pipelineStage === 'processing_evidence' && !is_array($workerFailure)
                ? ($evidenceProgress ?? 0)
                : $transcriptionProgress);

        return array(
            'pipeline_stage' => is_array($workerFailure) && $needsEvidence ? 'evidence_failed' : $pipelineStage,
            'display_status' => $displayStatus,
            'display_progress' => $displayProgress,
            'transcription_status' => $transcriptionStatus,
            'transcription_progress' => $transcriptionProgress,
            'evidence_in_progress' => $evidenceInProgress,
            'needs_evidence_processing' => $needsEvidence,
            'evidence_step' => $evidenceStep,
            'evidence_step_label' => $evidenceStepLabel,
            'evidence_progress' => $evidenceProgress,
            'evidence_elapsed_seconds' => $evidenceElapsedSeconds,
            'evidence_estimated_remaining_seconds' => $evidenceEstimatedRemainingSeconds,
            'evidence_worker_failed' => is_array($workerFailure),
            'evidence_worker_failure_reason' => is_array($workerFailure) ? (string)($workerFailure['reason'] ?? '') : null,
            'evidence_worker_failure_code' => is_array($workerFailure) ? (string)($workerFailure['code'] ?? '') : null,
            'evidence_worker_failure_detail' => is_array($workerFailure) ? (string)($workerFailure['detail'] ?? '') : null,
            'evidence_worker_log_excerpt' => is_array($workerFailure) ? ($workerFailure['log_excerpt'] ?? null) : null,
            'can_retry_evidence' => is_array($workerFailure) && !empty($workerFailure['can_restart']) && $needsEvidence,
            'publishable' => $publishableRun !== null,
            'running_processing_run_id' => is_array($runningRun) ? (int)($runningRun['id'] ?? 0) : null,
            'latest_publishable_processing_run_id' => is_array($publishableRun) ? (int)($publishableRun['id'] ?? 0) : null,
            'can_view_transcript' => $transcriptionStatus === 'ready',
            'can_view_structured_transcript' => $publishableRun !== null,
        );
    }

    /**
     * @param array<string,mixed>|null $runningRun
     */
    private function inferEvidenceStep(int $recordingId, ?array $runningRun): string
    {
        $processingRunId = is_array($runningRun) ? (int)($runningRun['id'] ?? 0) : 0;
        $phase = is_array($runningRun) ? trim((string)($runningRun['current_phase'] ?? '')) : '';
        if ($phase !== '' && in_array($phase, array('queued', 'starting', 'persisting', 'pass_4', 'pass_5', 'finishing'), true)) {
            return $phase === 'starting' ? 'persisting' : $phase;
        }

        if ($processingRunId <= 0) {
            return $this->diagnostics->findLiveRunningForRecording($recordingId) !== null ? 'persisting' : 'queued';
        }

        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_SPEECH_SEGMENTS)) {
            return 'persisting';
        }

        $speechSegments = new SpeechSegmentRepository($this->pdo);
        if ($speechSegments->listForProcessingRun($processingRunId) === array()) {
            return 'persisting';
        }

        if (!EvidenceSchema::pass4Ready($this->pdo)) {
            return 'pass_4';
        }

        $interpretations = new InterpretationRevisionRepository($this->pdo);
        if ($interpretations->findLatestForRunByLayer($processingRunId, EvidenceSchema::LAYER_PASS4B) === null) {
            return 'pass_4';
        }

        if (!EvidenceSchema::pass5Ready($this->pdo)) {
            return 'pass_5';
        }

        $displayBlocks = new DisplayBlockRepository($this->pdo);
        if ($displayBlocks->listForProcessingRun($processingRunId) === array()) {
            return 'pass_5';
        }

        return 'finishing';
    }

    /**
     * @param array<string,mixed>|null $runningRun
     */
    private function evidenceStepLabel(?string $step, ?array $runningRun = null): ?string
    {
        $phase = is_array($runningRun) ? trim((string)($runningRun['current_phase'] ?? '')) : '';
        if ($phase !== '' && $phase !== 'starting') {
            return match ($phase) {
                'queued' => 'Waiting to start evidence worker',
                'persisting' => 'Persisting timestamped transcript',
                'pass_4' => 'Pass 4 — quality + readable layer',
                'pass_5' => 'Pass 5 — blocks + flight outline',
                'finishing' => 'Finalizing evidence run',
                default => null,
            };
        }

        return match ($step) {
            'queued' => 'Waiting to start evidence worker',
            'persisting' => 'Persisting timestamped transcript',
            'pass_4' => 'Pass 4 — quality + readable layer',
            'pass_5' => 'Pass 5 — blocks + flight outline',
            'finishing' => 'Finalizing evidence run',
            default => null,
        };
    }

    public static function fromPdo(PDO $pdo): self
    {
        return new self(
            $pdo,
            new CockpitRecorderService($pdo),
            new ProcessingRunRepository($pdo),
            EvidenceWorkerDiagnosticsService::fromPdo($pdo),
        );
    }
}
