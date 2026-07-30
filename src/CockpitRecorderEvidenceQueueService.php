<?php
declare(strict_types=1);

require_once __DIR__ . '/CockpitRecorderService.php';
require_once __DIR__ . '/AviationEvidence/EvidenceSchema.php';
require_once __DIR__ . '/AviationEvidence/ProcessingRunRepository.php';
require_once __DIR__ . '/AviationEvidence/InterpretationRevisionRepository.php';
require_once __DIR__ . '/AviationEvidence/SpeechSegmentRepository.php';
require_once __DIR__ . '/AviationEvidence/DisplayBlockRepository.php';

final class CockpitRecorderEvidenceQueueService
{
    private const RUNNING_STALE_SECONDS = 14400;
    private const WORKER_ACTIVE_SECONDS = 300;

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
        if ($pipelineStage === 'processing_evidence') {
            $evidenceStep = $this->inferEvidenceStep($recordingId, $runningRun);
            $evidenceStepLabel = $this->evidenceStepLabel($evidenceStep);
        }

        $displayStatus = $pipelineStage === 'processing_evidence' ? 'processing_evidence' : $transcriptionStatus;
        $displayProgress = $pipelineStage === 'transcribing'
            ? $transcriptionProgress
            : ($pipelineStage === 'processing_evidence' ? 100 : $transcriptionProgress);

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
            'queued' => 'Waiting for evidence worker',
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

    public static function fromPdo(PDO $pdo): self
    {
        return new self($pdo, new CockpitRecorderService($pdo), new ProcessingRunRepository($pdo));
    }
}
