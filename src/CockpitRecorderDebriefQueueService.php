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
