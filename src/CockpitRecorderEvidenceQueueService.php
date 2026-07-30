<?php
declare(strict_types=1);

require_once __DIR__ . '/CockpitRecorderService.php';
require_once __DIR__ . '/AviationEvidence/EvidenceSchema.php';
require_once __DIR__ . '/AviationEvidence/ProcessingRunRepository.php';

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

    public static function fromPdo(PDO $pdo): self
    {
        return new self($pdo, new CockpitRecorderService($pdo), new ProcessingRunRepository($pdo));
    }
}
