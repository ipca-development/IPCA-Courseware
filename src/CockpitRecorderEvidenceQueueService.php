<?php
declare(strict_types=1);

require_once __DIR__ . '/CockpitRecorderService.php';
require_once __DIR__ . '/AviationEvidence/EvidenceSchema.php';
require_once __DIR__ . '/AviationEvidence/ProcessingRunRepository.php';
require_once __DIR__ . '/AviationEvidence/InterpretationRevisionRepository.php';
require_once __DIR__ . '/AviationEvidence/SpeechSegmentRepository.php';
require_once __DIR__ . '/AviationEvidence/DisplayBlockRepository.php';
require_once __DIR__ . '/AviationEvidence/EvidenceProgressEstimator.php';

final class CockpitRecorderEvidenceQueueService
{
    private const RUNNING_STALE_SECONDS = 14400;
    private const WORKER_ACTIVE_SECONDS = 300;
    private const STALLED_QUEUED_SECONDS = 45;

    public function __construct(
        private readonly PDO $pdo,
        private readonly CockpitRecorderService $recorder,
        private readonly ProcessingRunRepository $processingRuns,
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
        if ($this->workerRecentlyActive($recordingId)) {
            return true;
        }
        $running = $this->processingRuns->findRunningForRecording($recordingId);
        if ($running === null) {
            return false;
        }
        return !$this->isStaleRun($running);
    }

    /**
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

        $this->abandonStaleRuns($recordingId);

        if ($this->isEvidenceInProgress($recordingId)) {
            return array('ok' => true, 'queued' => false, 'reason' => 'already_running');
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
     * Retry evidence processing: spawn background worker, or run inline when spawn is unavailable.
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

        $this->abandonStaleRuns($recordingId);
        $queueResult = $this->ensureQueued($recordingId);
        $inlineFallback = false;
        $inlineResult = null;
        $inlineError = null;

        if (($queueResult['reason'] ?? '') === 'worker_spawn_failed') {
            require_once __DIR__ . '/AviationEvidence/ProductionTranscriptionEvidenceService.php';
            try {
                $inlineFallback = true;
                $inlineResult = ProductionTranscriptionEvidenceService::fromPdo($this->pdo)
                    ->persistAfterTranscription($recordingId, $recording);
            } catch (Throwable $e) {
                $inlineError = $e->getMessage();
            }
        }

        $recording = $this->recorder->recordingByAnyId((string)$recordingId) ?? $recording;
        $pipeline = $this->publicStatusForRecording($recording);

        return array(
            'ok' => $inlineError === null,
            'recording_id' => $recordingId,
            'queue' => $queueResult,
            'inline_fallback' => $inlineFallback,
            'inline_result' => $inlineResult,
            'inline_error' => $inlineError,
            'pipeline' => $pipeline,
            'error' => $inlineError,
        );
    }

    private function abandonStaleRuns(int $recordingId): void
    {
        foreach ($this->processingRuns->listRunningForRecording($recordingId) as $run) {
            if (!$this->isStaleRun($run)) {
                continue;
            }
            $runId = (int)($run['id'] ?? 0);
            if ($runId > 0) {
                $this->processingRuns->abandonRun($runId);
            }
        }
    }

    /**
     * @param array<string,mixed> $run
     */
    private function isStaleRun(array $run): bool
    {
        $createdAt = strtotime((string)($run['created_at'] ?? ''));
        if ($createdAt === false) {
            return true;
        }
        return (time() - $createdAt) > self::RUNNING_STALE_SECONDS;
    }

    private function workerRecentlyActive(int $recordingId): bool
    {
        $logFile = CockpitRecorderService::projectRoot() . '/storage/logs/cockpit_evidence_' . $recordingId . '.log';
        if (!is_file($logFile)) {
            return false;
        }
        $mtime = filemtime($logFile);
        return $mtime !== false && (time() - $mtime) < self::WORKER_ACTIVE_SECONDS;
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
        $transcriptionWorkerActive = $this->transcriptionWorkerRecentlyActive($recordingId);
        $evidenceWorkerActive = $this->workerRecentlyActive($recordingId);
        $evidenceInProgress = $needsEvidence && (
            $evidenceWorkerActive
            || $transcriptionWorkerActive
            || ($transcriptionStatus === 'ready' && is_array($runningRun) && !$this->isStaleRun($runningRun))
            || $this->isEvidenceInProgress($recordingId)
        );

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
            $evidenceStep = $this->inferEvidenceStep($recordingId, $runningRun);
            $evidenceStepLabel = $this->evidenceStepLabel($evidenceStep);
            $progressEstimate = EvidenceProgressEstimator::fromPdo($this->pdo)->estimate(
                $recording,
                $evidenceStep,
                $runningRun
            );
            $evidenceProgress = (int)($progressEstimate['evidence_progress'] ?? 0);
            $evidenceEstimatedRemainingSeconds = (int)($progressEstimate['evidence_estimated_remaining_seconds'] ?? 0);
            $evidenceElapsedSeconds = (int)($progressEstimate['evidence_elapsed_seconds'] ?? 0);
        }

        $workerFailure = $this->workerFailureState(
            $recordingId,
            $recording,
            $evidenceStep,
            $evidenceInProgress,
            $needsEvidence
        );

        $displayStatus = $pipelineStage === 'processing_evidence' ? 'processing_evidence' : $transcriptionStatus;
        $displayProgress = $pipelineStage === 'transcribing'
            ? $transcriptionProgress
            : ($pipelineStage === 'processing_evidence'
                ? ($evidenceProgress ?? 0)
                : $transcriptionProgress);

        return array(
            'pipeline_stage' => $pipelineStage,
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
            'can_retry_evidence' => is_array($workerFailure) && $needsEvidence,
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
        if ($processingRunId <= 0) {
            return $this->transcriptionWorkerRecentlyActive($recordingId) ? 'persisting' : 'queued';
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

    private function evidenceStepLabel(?string $step): ?string
    {
        return match ($step) {
            'queued' => 'Waiting to start evidence worker',
            'persisting' => 'Persisting timestamped transcript',
            'pass_4' => 'Pass 4 — quality + readable layer',
            'pass_5' => 'Pass 5 — blocks + flight outline',
            'finishing' => 'Finalizing evidence run',
            default => null,
        };
    }

    private function transcriptionWorkerRecentlyActive(int $recordingId): bool
    {
        $logFile = CockpitRecorderService::projectRoot() . '/storage/logs/cockpit_recorder_' . $recordingId . '.log';
        if (!is_file($logFile)) {
            return false;
        }
        $mtime = filemtime($logFile);
        return $mtime !== false && (time() - $mtime) < self::WORKER_ACTIVE_SECONDS;
    }

    /**
     * @param array<string,mixed> $recording
     * @return array{reason:string,code:string}|null
     */
    private function workerFailureState(
        int $recordingId,
        array $recording,
        ?string $evidenceStep,
        bool $evidenceInProgress,
        bool $needsEvidence
    ): ?array {
        if (!$needsEvidence || $evidenceInProgress || $evidenceStep !== 'queued') {
            return null;
        }

        if ($this->workerRecentlyActive($recordingId) || $this->transcriptionWorkerRecentlyActive($recordingId)) {
            return null;
        }

        if (!function_exists('exec')) {
            return array(
                'code' => 'exec_disabled',
                'reason' => 'Background worker cannot start because PHP exec() is disabled on this server.',
            );
        }

        $spawnReason = $this->readSpawnFailureFromLog($recordingId);
        if ($spawnReason !== null) {
            return array(
                'code' => 'spawn_failed',
                'reason' => $spawnReason,
            );
        }

        $elapsed = $this->secondsSinceTranscriptionReady($recording);
        if ($elapsed !== null && $elapsed >= self::STALLED_QUEUED_SECONDS) {
            return array(
                'code' => 'stalled',
                'reason' => 'Evidence worker did not start after transcription finished. Click Restart Evidence to retry.',
            );
        }

        return null;
    }

    /**
     * @param array<string,mixed> $recording
     */
    private function secondsSinceTranscriptionReady(array $recording): ?int
    {
        $startedAt = strtotime((string)($recording['transcription_completed_at'] ?? ''));
        if ($startedAt === false) {
            $startedAt = strtotime((string)($recording['updated_at'] ?? ''));
        }
        if ($startedAt === false) {
            return null;
        }
        return max(0, time() - $startedAt);
    }

    private function readSpawnFailureFromLog(int $recordingId): ?string
    {
        $logFile = CockpitRecorderService::projectRoot() . '/storage/logs/cockpit_evidence_' . $recordingId . '.log';
        if (!is_file($logFile)) {
            return null;
        }
        $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines) || $lines === array()) {
            return null;
        }
        foreach (array_reverse(array_slice($lines, -12)) as $line) {
            $text = trim((string)$line);
            if ($text === '') {
                continue;
            }
            if (str_contains($text, 'Worker spawn exited with code')) {
                return 'Evidence worker spawn failed. Check storage/logs/cockpit_evidence_' . $recordingId . '.log.';
            }
            if (str_contains($text, 'ERROR:')) {
                return preg_replace('/^\[[^\]]+\]\s*/', '', $text) ?: 'Evidence worker failed. See cockpit evidence log.';
            }
        }
        return null;
    }

    public static function fromPdo(PDO $pdo): self
    {
        return new self($pdo, new CockpitRecorderService($pdo), new ProcessingRunRepository($pdo));
    }
}
