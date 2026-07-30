<?php
declare(strict_types=1);

require_once __DIR__ . '/EvidenceSchema.php';
require_once __DIR__ . '/ProcessingRunRepository.php';
require_once __DIR__ . '/../CockpitRecorderService.php';

/**
 * Read-only diagnosis for evidence worker failures.
 * Does not restart processing — only explains why a run appears dead or stuck.
 */
final class EvidenceWorkerDiagnosticsService
{
    public const HEARTBEAT_LEASE_SECONDS = 120;
    public const STARTUP_GRACE_SECONDS = 45;
    public const WORKER_LOG_ACTIVE_SECONDS = 300;
    public const QUEUED_START_TIMEOUT_SECONDS = 45;

    public function __construct(private readonly ProcessingRunRepository $processingRuns)
    {
    }

    public static function fromPdo(PDO $pdo): self
    {
        return new self(new ProcessingRunRepository($pdo));
    }

    /**
     * @param array<string,mixed>|null $recording
     * @param array<string,mixed>|null $runningRun
     * @return array{code:string,reason:string,detail:string,phase:?string,log_excerpt:?string,can_restart:bool}|null
     */
    public function diagnose(int $recordingId, ?array $recording, ?array $runningRun, ?string $evidenceStepLabel = null): ?array
    {
        if ($recordingId <= 0) {
            return null;
        }

        if (!function_exists('exec')) {
            return $this->failure(
                'exec_disabled',
                'Background worker cannot start because PHP exec() is disabled on this server.',
                'The server cannot spawn nohup background workers. Use Restart Evidence to run inline instead.',
                null,
                null
            );
        }

        $failedRun = $this->processingRuns->findLatestFailedForRecording($recordingId);
        if (is_array($failedRun)) {
            $reason = trim((string)($failedRun['failure_reason'] ?? ''));
            if ($reason === '') {
                $reason = 'Evidence processing failed without a recorded reason.';
            }
            return $this->failure(
                'run_failed',
                $reason,
                'Processing run #' . (int)($failedRun['id'] ?? 0) . ' is marked failed.',
                trim((string)($failedRun['current_phase'] ?? '')) ?: null,
                $this->readLogExcerpt($recordingId)
            );
        }

        if ($this->workerLogRecentlyActive($recordingId) || $this->transcriptionWorkerRecentlyActive($recordingId)) {
            return null;
        }

        if (is_array($runningRun) && $this->isRunLive($runningRun)) {
            return null;
        }

        $logExcerpt = $this->readLogExcerpt($recordingId);
        $spawnFailure = $this->readSpawnFailureFromLog($recordingId);
        if ($spawnFailure !== null) {
            return $this->failure(
                'spawn_failed',
                $spawnFailure,
                'The background worker process never started successfully.',
                is_array($runningRun) ? trim((string)($runningRun['current_phase'] ?? '')) ?: null : null,
                $logExcerpt
            );
        }

        $workerError = $this->readWorkerErrorFromLog($recordingId);
        if ($workerError !== null) {
            return $this->failure(
                'worker_exception',
                $workerError,
                'The evidence worker logged an error and exited.',
                is_array($runningRun) ? trim((string)($runningRun['current_phase'] ?? '')) ?: null : null,
                $logExcerpt
            );
        }

        if (is_array($runningRun)) {
            $phase = trim((string)($runningRun['current_phase'] ?? ''));
            $stepText = trim((string)($evidenceStepLabel ?? $phase ?: 'evidence processing'));
            $heartbeatAge = $this->secondsSinceHeartbeat($runningRun);
            if ($heartbeatAge !== null && $heartbeatAge >= self::HEARTBEAT_LEASE_SECONDS) {
                return $this->failure(
                    'heartbeat_timeout',
                    'Evidence worker stopped heartbeating during "' . $stepText . '" (' . $heartbeatAge . 's ago).',
                    'The worker likely crashed, was killed, or lost database connectivity. Check storage/logs/cockpit_evidence_' . $recordingId . '.log.',
                    $phase !== '' ? $phase : null,
                    $logExcerpt
                );
            }

            $runAge = $this->secondsSinceCreated($runningRun);
            if ($runAge !== null && $runAge >= self::STARTUP_GRACE_SECONDS) {
                return $this->failure(
                    'worker_never_heartbeated',
                    'Evidence worker never reported progress during "' . $stepText . '".',
                    'A processing run exists in the database but no heartbeat or worker log activity was observed.',
                    $phase !== '' ? $phase : null,
                    $logExcerpt
                );
            }

            return null;
        }

        if (is_array($recording)) {
            $elapsed = $this->secondsSinceTranscriptionReady($recording);
            if ($elapsed !== null && $elapsed >= self::QUEUED_START_TIMEOUT_SECONDS && !$this->hasEvidenceLog($recordingId)) {
                return $this->failure(
                    'never_started',
                    'Evidence processing never started after transcription finished.',
                    'No evidence worker log exists yet. Transcription completed ' . $elapsed . 's ago.',
                    'queued',
                    null
                );
            }
        }

        return null;
    }

    /**
     * Seal a dead running row as failed once we have a diagnosis. Does not restart.
     *
     * @param array{code:string,reason:string,detail:string,phase:?string,log_excerpt:?string,can_restart:bool} $diagnosis
     */
    public function sealDiagnosedFailure(int $runId, array $diagnosis): bool
    {
        if ($runId <= 0) {
            return false;
        }

        $reason = trim((string)($diagnosis['reason'] ?? ''));
        if ($reason === '') {
            $reason = (string)($diagnosis['code'] ?? 'unknown_failure');
        }
        $detail = trim((string)($diagnosis['detail'] ?? ''));
        if ($detail !== '') {
            $reason .= ' — ' . $detail;
        }

        return $this->processingRuns->markFailed(
            $runId,
            substr($reason, 0, 512),
            isset($diagnosis['phase']) ? (string)$diagnosis['phase'] : null
        );
    }

    /**
     * @param array<string,mixed> $run
     */
    public function isRunLive(array $run): bool
    {
        if ((string)($run['status'] ?? '') !== 'running') {
            return false;
        }

        if ($this->workerLogRecentlyActive((int)($run['recording_id'] ?? 0))) {
            return true;
        }

        $heartbeatAt = trim((string)($run['heartbeat_at'] ?? ''));
        if ($heartbeatAt !== '') {
            $heartbeatTs = strtotime($heartbeatAt);
            if ($heartbeatTs !== false) {
                return (time() - $heartbeatTs) < self::HEARTBEAT_LEASE_SECONDS;
            }
        }

        $createdAt = strtotime((string)($run['created_at'] ?? ''));
        if ($createdAt === false) {
            return false;
        }

        return (time() - $createdAt) < self::STARTUP_GRACE_SECONDS;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findLiveRunningForRecording(int $recordingId): ?array
    {
        foreach ($this->processingRuns->listRunningForRecording($recordingId) as $run) {
            if ($this->isRunLive($run)) {
                return $run;
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $run
     */
    private function secondsSinceHeartbeat(array $run): ?int
    {
        $heartbeatAt = trim((string)($run['heartbeat_at'] ?? ''));
        if ($heartbeatAt === '') {
            return null;
        }
        $ts = strtotime($heartbeatAt);
        if ($ts === false) {
            return null;
        }
        return max(0, time() - $ts);
    }

    /**
     * @param array<string,mixed> $run
     */
    private function secondsSinceCreated(array $run): ?int
    {
        $createdAt = strtotime((string)($run['created_at'] ?? ''));
        if ($createdAt === false) {
            return null;
        }
        return max(0, time() - $createdAt);
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

    private function workerLogRecentlyActive(int $recordingId): bool
    {
        $logFile = $this->evidenceLogPath($recordingId);
        if (!is_file($logFile)) {
            return false;
        }
        $mtime = filemtime($logFile);
        return $mtime !== false && (time() - $mtime) < self::WORKER_LOG_ACTIVE_SECONDS;
    }

    private function transcriptionWorkerRecentlyActive(int $recordingId): bool
    {
        $logFile = CockpitRecorderService::projectRoot() . '/storage/logs/cockpit_recorder_' . $recordingId . '.log';
        if (!is_file($logFile)) {
            return false;
        }
        $mtime = filemtime($logFile);
        return $mtime !== false && (time() - $mtime) < self::WORKER_LOG_ACTIVE_SECONDS;
    }

    private function hasEvidenceLog(int $recordingId): bool
    {
        return is_file($this->evidenceLogPath($recordingId));
    }

    private function evidenceLogPath(int $recordingId): string
    {
        return CockpitRecorderService::projectRoot() . '/storage/logs/cockpit_evidence_' . $recordingId . '.log';
    }

    private function readSpawnFailureFromLog(int $recordingId): ?string
    {
        foreach ($this->tailLogLines($recordingId, 20) as $line) {
            if (str_contains($line, 'Worker spawn exited with code')) {
                return 'Evidence worker spawn failed. Check storage/logs/cockpit_evidence_' . $recordingId . '.log.';
            }
        }
        return null;
    }

    private function readWorkerErrorFromLog(int $recordingId): ?string
    {
        foreach ($this->tailLogLines($recordingId, 20) as $line) {
            if (!str_contains($line, 'ERROR:')) {
                continue;
            }
            return preg_replace('/^\[[^\]]+\]\s*/', '', $line) ?: 'Evidence worker failed. See cockpit evidence log.';
        }
        return null;
    }

    /**
     * @return list<string>
     */
    private function tailLogLines(int $recordingId, int $limit): array
    {
        $logFile = $this->evidenceLogPath($recordingId);
        if (!is_file($logFile)) {
            return array();
        }
        $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines) || $lines === array()) {
            return array();
        }
        return array_reverse(array_slice($lines, -max(1, $limit)));
    }

    private function readLogExcerpt(int $recordingId): ?string
    {
        $lines = $this->tailLogLines($recordingId, 4);
        if ($lines === array()) {
            return null;
        }
        return implode("\n", array_reverse($lines));
    }

    /**
     * @return array{code:string,reason:string,detail:string,phase:?string,log_excerpt:?string,can_restart:bool}
     */
    private function failure(
        string $code,
        string $reason,
        string $detail,
        ?string $phase,
        ?string $logExcerpt
    ): array {
        return array(
            'code' => $code,
            'reason' => $reason,
            'detail' => $detail,
            'phase' => $phase,
            'log_excerpt' => $logExcerpt,
            'can_restart' => true,
        );
    }
}
