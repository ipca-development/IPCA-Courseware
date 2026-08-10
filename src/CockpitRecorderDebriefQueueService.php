<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';
require_once __DIR__ . '/ManualReconstructionBundleService.php';
require_once __DIR__ . '/FlightDebriefService.php';
require_once __DIR__ . '/AviationEvidence/ProcessingRunRepository.php';

/**
 * Lock transcript snapshots and queue structured debriefs after Pass 4 readable quality processing.
 */
final class CockpitRecorderDebriefQueueService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ManualReconstructionBundleService $bundles,
        private readonly ProcessingRunRepository $processingRuns,
    ) {
    }

    public static function autoDebriefEnabled(): bool
    {
        $env = getenv('CW_AUTO_DEBRIEF_ON_TRANSCRIPT');
        if ($env === '0' || $env === 'false') {
            return false;
        }
        return true;
    }

    public function hasReadableTranscript(int $recordingId): bool
    {
        if ($recordingId <= 0) {
            return false;
        }
        return $this->processingRuns->findLatestPublishableForRecording($recordingId) !== null;
    }

    public function hasPreliminaryTranscript(int $recordingId): bool
    {
        return $this->hasReadableTranscript($recordingId)
            || $this->bundles->hasCompleteLiveTranscript($recordingId);
    }

    /**
     * @return array<string,mixed>
     */
    public function onTranscriptionReady(int $recordingId, ?int $actorUserId = null): array
    {
        return $this->onReadableTranscriptReady($recordingId, $actorUserId);
    }

    /**
     * @return array<string,mixed>
     */
    public function onReadableTranscriptReady(int $recordingId, ?int $actorUserId = null): array
    {
        if ($recordingId <= 0) {
            return array('ok' => false, 'reason' => 'invalid_recording_id');
        }
        if (!self::autoDebriefEnabled()) {
            return array('ok' => true, 'skipped' => true, 'reason' => 'auto_debrief_disabled');
        }
        if (!$this->hasReadableTranscript($recordingId)) {
            return array('ok' => true, 'skipped' => true, 'reason' => 'waiting_for_pass4_readable');
        }

        // If Pass 4 finished before a bundle existed, auto-freeze siblings first.
        require_once __DIR__ . '/CvrAutoReconstructionOrchestrator.php';
        CvrAutoReconstructionOrchestrator::safeConsider($this->pdo, null, $recordingId, null, null);

        $statement = $this->pdo->prepare(
            'SELECT id FROM ipca_manual_intake_bundles
             WHERE cockpit_recording_id = ? AND transcript_snapshot_id IS NULL
             ORDER BY id DESC'
        );
        $statement->execute(array($recordingId));
        $bundleIds = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN) ?: array());

        $out = array();
        foreach ($bundleIds as $bundleId) {
            if ($bundleId <= 0) {
                continue;
            }
            try {
                $out[] = $this->lockAndQueueDebrief($bundleId, $actorUserId);
            } catch (Throwable $e) {
                $out[] = array(
                    'ok' => false,
                    'bundle_id' => $bundleId,
                    'error' => $e->getMessage(),
                );
            }
        }

        return array(
            'ok' => true,
            'recording_id' => $recordingId,
            'bundles' => $out,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function lockAndQueueDebrief(int $bundleId, ?int $actorUserId = null): array
    {
        $snapshotId = $this->bundles->lockTranscript($bundleId, $actorUserId);
        $queued = $this->queueDebriefJob($bundleId, $snapshotId, $actorUserId);
        return array(
            'ok' => true,
            'bundle_id' => $bundleId,
            'transcript_snapshot_id' => $snapshotId,
            'debrief_job' => $queued,
        );
    }

    /** @return array<string,mixed> */
    public function lockAndQueuePreliminaryDebrief(int $bundleId, ?int $actorUserId = null): array
    {
        $bundle = $this->bundles->bundleById($bundleId);
        $recordingId = (int)($bundle['cockpit_recording_id'] ?? 0);
        $snapshotId = $this->hasReadableTranscript($recordingId)
            ? $this->bundles->lockTranscript($bundleId, $actorUserId)
            : $this->bundles->lockLiveTranscript($bundleId, $actorUserId);
        $queued = $this->queueDebriefJob($bundleId, $snapshotId, $actorUserId);
        return array(
            'ok' => true,
            'bundle_id' => $bundleId,
            'transcript_snapshot_id' => $snapshotId,
            'evidence_stage' => 'preliminary',
            'debrief_job' => $queued,
        );
    }

    /**
     * Merge progress fields into an async debrief job payload (no schema migration).
     */
    public function updateJobProgress(int $jobId, int $progress, string $message): void
    {
        if ($jobId <= 0) {
            return;
        }
        $progress = max(0, min(100, $progress));
        $message = trim($message);
        $statement = $this->pdo->prepare(
            'SELECT payload_json FROM ipca_async_jobs WHERE id = ? LIMIT 1'
        );
        $statement->execute(array($jobId));
        $raw = $statement->fetchColumn();
        $payload = is_string($raw) && $raw !== ''
            ? (json_decode($raw, true) ?: array())
            : array();
        if (!is_array($payload)) {
            $payload = array();
        }
        $payload['progress'] = $progress;
        $payload['progress_message'] = $message !== '' ? $message : (string)($payload['progress_message'] ?? '');
        $this->pdo->prepare(
            "UPDATE ipca_async_jobs
             SET payload_json = ?, heartbeat_at = CURRENT_TIMESTAMP(3), updated_at = CURRENT_TIMESTAMP(3)
             WHERE id = ?"
        )->execute(array(AuditEventService::jsonEncode($payload), $jobId));
    }

    /**
     * @param list<int> $bundleIds
     * @return list<array<string,mixed>>
     */
    public function statusForBundles(array $bundleIds): array
    {
        $bundleIds = array_values(array_unique(array_filter(array_map('intval', $bundleIds), static fn(int $id): bool => $id > 0)));
        if ($bundleIds === array()) {
            return array();
        }
        $out = array();
        foreach ($bundleIds as $bundleId) {
            $out[] = $this->statusForBundle($bundleId);
        }
        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    public function statusForBundle(int $bundleId): array
    {
        if ($bundleId <= 0) {
            return array(
                'bundle_id' => 0,
                'job_id' => null,
                'status' => '',
                'progress' => 0,
                'progress_message' => '',
                'error' => '',
                'debrief_id' => null,
                'evidence_stage' => '',
                'running' => false,
            );
        }

        $jobStatement = $this->pdo->prepare(
            "SELECT id, status, payload_json, result_json, last_error
             FROM ipca_async_jobs
             WHERE job_type = 'generate_structured_debrief'
               AND entity_type = 'ipca_manual_intake_bundles'
               AND entity_id = ?
             ORDER BY id DESC LIMIT 1"
        );
        $jobStatement->execute(array((string)$bundleId));
        $job = $jobStatement->fetch(PDO::FETCH_ASSOC);

        $debriefId = null;
        $evidenceStage = '';
        if ($this->tableExists('ipca_structured_debriefs')) {
            $debriefStatement = $this->pdo->prepare(
                'SELECT id, evidence_stage FROM ipca_structured_debriefs
                 WHERE bundle_id = ? ORDER BY id DESC LIMIT 1'
            );
            $debriefStatement->execute(array($bundleId));
            $debriefRow = $debriefStatement->fetch(PDO::FETCH_ASSOC);
            if (is_array($debriefRow)) {
                $debriefId = (int)($debriefRow['id'] ?? 0) ?: null;
                $evidenceStage = trim((string)($debriefRow['evidence_stage'] ?? ''));
            }
        }

        if (!is_array($job)) {
            return array(
                'bundle_id' => $bundleId,
                'job_id' => null,
                'status' => '',
                'progress' => $debriefId ? 100 : 0,
                'progress_message' => $debriefId ? 'Ready' : '',
                'error' => '',
                'debrief_id' => $debriefId,
                'evidence_stage' => $evidenceStage,
                'running' => false,
            );
        }

        $status = strtolower(trim((string)($job['status'] ?? '')));
        $payload = json_decode((string)($job['payload_json'] ?? ''), true);
        if (!is_array($payload)) {
            $payload = array();
        }
        $result = json_decode((string)($job['result_json'] ?? ''), true);
        if (!is_array($result)) {
            $result = array();
        }
        if ((int)($result['debrief_id'] ?? 0) > 0) {
            $debriefId = (int)$result['debrief_id'];
        }

        $progress = max(0, min(100, (int)($payload['progress'] ?? 0)));
        $message = trim((string)($payload['progress_message'] ?? ''));
        $running = in_array($status, array('pending', 'claimed', 'running', 'retry_wait'), true);
        if ($status === 'succeeded') {
            $progress = 100;
            if ($message === '') {
                $message = 'Ready';
            }
        } elseif ($running && $progress <= 0) {
            $progress = 5;
            if ($message === '') {
                $message = 'Queued';
            }
        }

        return array(
            'bundle_id' => $bundleId,
            'job_id' => (int)$job['id'],
            'status' => $status,
            'progress' => $progress,
            'progress_message' => $message,
            'error' => trim((string)($job['last_error'] ?? '')),
            'debrief_id' => $debriefId,
            'evidence_stage' => $evidenceStage,
            'running' => $running,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function queueDebriefJob(int $bundleId, int $snapshotId, ?int $actorUserId = null): array
    {
        if ($bundleId <= 0 || $snapshotId <= 0) {
            throw new RuntimeException('Bundle and transcript snapshot are required.');
        }

        $active = $this->pdo->prepare(
            "SELECT id FROM ipca_async_jobs
             WHERE job_type = 'generate_structured_debrief'
               AND entity_type = 'ipca_manual_intake_bundles'
               AND entity_id = ?
               AND status IN ('pending','claimed','running','retry_wait')
             ORDER BY id DESC LIMIT 1"
        );
        $active->execute(array((string)$bundleId));
        $activeJobId = (int)$active->fetchColumn();
        if ($activeJobId > 0) {
            return array('ok' => true, 'skipped' => true, 'reason' => 'already_running', 'job_id' => $activeJobId);
        }

        $sequenceStatement = $this->pdo->prepare(
            'SELECT COUNT(*) + 1 FROM ipca_structured_debriefs WHERE bundle_id = ?'
        );
        $sequenceStatement->execute(array($bundleId));
        $sequence = max(1, (int)$sequenceStatement->fetchColumn());
        $jobUuid = AuditEventService::uuid();
        $idempotencyKey = hash(
            'sha256',
            'structured-debrief:' . $bundleId . ':' . $snapshotId . ':' . $sequence . ':' . $jobUuid
        );

        $insert = $this->pdo->prepare(
            "INSERT INTO ipca_async_jobs
             (job_uuid, organization_id, queue_name, job_type, entity_type, entity_id,
              idempotency_key, priority, status, claimed_by, claimed_at, heartbeat_at,
              attempt_count, max_attempts, payload_json)
             VALUES (?, 1, 'cvr_debrief', 'generate_structured_debrief',
                     'ipca_manual_intake_bundles', ?, ?, 100, 'running',
                     'transcript_worker', CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), 1, 3, ?)"
        );
        $insert->execute(array(
            $jobUuid,
            (string)$bundleId,
            $idempotencyKey,
            AuditEventService::jsonEncode(array(
                'bundle_id' => $bundleId,
                'transcript_snapshot_id' => $snapshotId,
                'actor_user_id' => $actorUserId,
                'progress' => 5,
                'progress_message' => 'Queued',
            )),
        ));
        $jobId = (int)$this->pdo->lastInsertId();
        if ($jobId <= 0) {
            throw new RuntimeException('Could not create debrief async job.');
        }

        $spawned = $this->spawnDebriefWorker($bundleId, $jobId, $actorUserId);
        if (!$spawned) {
            throw new RuntimeException('Debrief job created but worker could not be started.');
        }

        return array('ok' => true, 'job_id' => $jobId, 'spawned' => true);
    }

    private function tableExists(string $table): bool
    {
        static $cache = array();
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
        );
        $statement->execute(array($table));
        $cache[$table] = (bool)$statement->fetchColumn();
        return $cache[$table];
    }

    private function spawnDebriefWorker(int $bundleId, int $jobId, ?int $actorUserId): bool
    {
        if (!function_exists('exec')) {
            return false;
        }

        require_once __DIR__ . '/CockpitRecorderService.php';
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

        $script = realpath(__DIR__ . '/../scripts/run_structured_flight_debrief.php');
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

        $logFile = $logDir . '/structured_debrief_' . $bundleId . '_' . $jobId . '.log';
        $command = 'nohup '
            . escapeshellarg($php) . ' '
            . escapeshellarg($script) . ' '
            . escapeshellarg('--bundle-id=' . $bundleId) . ' '
            . escapeshellarg('--job-id=' . $jobId)
            . ($actorUserId !== null ? ' ' . escapeshellarg('--actor-user-id=' . $actorUserId) : '')
            . ' >> ' . escapeshellarg($logFile) . ' 2>&1 < /dev/null & echo $!';
        exec($command, $output, $exitCode);
        return $exitCode === 0;
    }

    public static function fromPdo(PDO $pdo): self
    {
        return new self($pdo, new ManualReconstructionBundleService($pdo), new ProcessingRunRepository($pdo));
    }
}
