<?php
declare(strict_types=1);

require_once __DIR__ . '/ManualReconstructionBundleService.php';

/**
 * Server-only: when Dispatch + Cockpit audio + Garmin CSV share a flight UUID,
 * auto-freeze a reconstruction bundle and queue structured debrief once Pass 4 is ready.
 * No iOS app changes required.
 */
final class CvrAutoReconstructionOrchestrator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ManualReconstructionBundleService $bundles,
    ) {
    }

    public static function autoFreezeEnabled(): bool
    {
        $env = getenv('CW_AUTO_FREEZE_ON_INTAKE');
        if ($env === '0' || $env === 'false') {
            return false;
        }
        return true;
    }

    /**
     * Best-effort entry for intake hooks. Never throws.
     *
     * @return array<string,mixed>
     */
    public static function safeConsider(
        PDO $pdo,
        ?string $flightUuid = null,
        ?int $recordingId = null,
        ?int $dispatchId = null,
        ?int $garminCsvId = null
    ): array {
        try {
            return self::fromPdo($pdo)->consider($flightUuid, $recordingId, $dispatchId, $garminCsvId);
        } catch (Throwable $e) {
            error_log('[CvrAutoReconstructionOrchestrator] ' . $e->getMessage());
            return array(
                'ok' => false,
                'error' => $e->getMessage(),
            );
        }
    }

    public static function fromPdo(PDO $pdo): self
    {
        return new self($pdo, new ManualReconstructionBundleService($pdo));
    }

    /**
     * @return array<string,mixed>
     */
    public function consider(
        ?string $flightUuid = null,
        ?int $recordingId = null,
        ?int $dispatchId = null,
        ?int $garminCsvId = null
    ): array {
        if (!self::autoFreezeEnabled()) {
            return array('ok' => true, 'skipped' => true, 'reason' => 'auto_freeze_disabled');
        }
        if (!$this->tableExists('ipca_manual_intake_bundles')
            || !$this->tableExists('ipca_cvr_dispatches')
            || !$this->tableExists('ipca_cockpit_recordings')
            || !$this->tableExists('ipca_garmin_csv_files')
        ) {
            return array('ok' => true, 'skipped' => true, 'reason' => 'schema_unavailable');
        }

        $resolved = $this->resolveFlightUuid($flightUuid, $recordingId, $dispatchId, $garminCsvId);
        if ($resolved === '') {
            return array('ok' => true, 'skipped' => true, 'reason' => 'missing_flight_uuid');
        }

        $dispatch = $this->latestDispatch($resolved, $dispatchId);
        if ($dispatch === null) {
            return array('ok' => true, 'skipped' => true, 'reason' => 'waiting_for_dispatch', 'flight_uuid' => $resolved);
        }
        $recording = $this->latestRecording($resolved, $recordingId);
        if ($recording === null) {
            return array('ok' => true, 'skipped' => true, 'reason' => 'waiting_for_audio', 'flight_uuid' => $resolved);
        }
        $garmin = $this->latestGarmin($resolved, $garminCsvId);
        if ($garmin === null) {
            return $this->considerPreliminary($resolved, $dispatch, $recording);
        }

        $dispatchId = (int)$dispatch['id'];
        $recordingId = (int)$recording['id'];
        $garminCsvId = (int)$garmin['id'];

        $matching = $this->findMatchingTriadBundle($dispatchId, $recordingId, $garminCsvId);
        $unlocked = $this->findUnlockedBundleForRecording($recordingId);
        $bundleId = 0;
        $froze = false;
        if ($matching !== null) {
            $bundleId = (int)$matching['id'];
        } elseif ($unlocked !== null) {
            $bundleId = (int)$unlocked['id'];
        } else {
            try {
                $bundle = $this->bundles->freezeAndPrepare(
                    $dispatchId,
                    $recordingId,
                    $garminCsvId,
                    true,
                    null
                );
                $bundleId = (int)($bundle['id'] ?? 0);
                $froze = $bundleId > 0;
            } catch (Throwable $e) {
                error_log('[CvrAutoReconstructionOrchestrator] freeze failed: ' . $e->getMessage());
                return array(
                    'ok' => false,
                    'flight_uuid' => $resolved,
                    'dispatch_id' => $dispatchId,
                    'recording_id' => $recordingId,
                    'garmin_csv_file_id' => $garminCsvId,
                    'error' => $e->getMessage(),
                );
            }
        }

        if ($bundleId <= 0) {
            return array('ok' => false, 'reason' => 'bundle_unavailable', 'flight_uuid' => $resolved);
        }

        $out = array(
            'ok' => true,
            'flight_uuid' => $resolved,
            'dispatch_id' => $dispatchId,
            'recording_id' => $recordingId,
            'garmin_csv_file_id' => $garminCsvId,
            'bundle_id' => $bundleId,
            'froze' => $froze,
        );

        $out['reconstruction'] = $this->ensureFlightReconstructionStarted($bundleId, $recordingId);

        require_once __DIR__ . '/CockpitRecorderDebriefQueueService.php';
        $debriefQueue = CockpitRecorderDebriefQueueService::fromPdo($this->pdo);
        if (!CockpitRecorderDebriefQueueService::autoDebriefEnabled()) {
            $out['debrief'] = array('ok' => true, 'skipped' => true, 'reason' => 'auto_debrief_disabled');
            return $out;
        }
        if (!$debriefQueue->hasReadableTranscript($recordingId)) {
            $out['debrief'] = array('ok' => true, 'skipped' => true, 'reason' => 'waiting_for_pass4_readable');
            return $out;
        }
        if ($this->hasStructuredDebrief($bundleId)) {
            $out['debrief'] = array('ok' => true, 'skipped' => true, 'reason' => 'already_debriefed');
            return $out;
        }

        try {
            $out['debrief'] = $debriefQueue->lockAndQueueDebrief($bundleId, null);
        } catch (Throwable $e) {
            error_log('[CvrAutoReconstructionOrchestrator] debrief queue failed: ' . $e->getMessage());
            $out['debrief'] = array('ok' => false, 'error' => $e->getMessage());
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function considerPreliminary(string $flightUuid, array $dispatch, array $recording): array
    {
        try {
            $bundle = $this->bundles->freezePreliminary(
                (int)$dispatch['id'],
                (int)$recording['id'],
                null
            );
        } catch (Throwable $e) {
            error_log('[CvrAutoReconstructionOrchestrator] preliminary freeze failed: ' . $e->getMessage());
            return array(
                'ok' => false,
                'flight_uuid' => $flightUuid,
                'evidence_stage' => 'preliminary',
                'error' => $e->getMessage(),
            );
        }
        $bundleId = (int)($bundle['id'] ?? 0);
        $out = array(
            'ok' => $bundleId > 0,
            'flight_uuid' => $flightUuid,
            'dispatch_id' => (int)$dispatch['id'],
            'recording_id' => (int)$recording['id'],
            'garmin_csv_file_id' => null,
            'bundle_id' => $bundleId,
            'evidence_stage' => 'preliminary',
            'garmin_required' => false,
        );
        if ($bundleId <= 0) {
            return $out;
        }

        require_once __DIR__ . '/CockpitRecorderDebriefQueueService.php';
        $debriefQueue = CockpitRecorderDebriefQueueService::fromPdo($this->pdo);
        if (!CockpitRecorderDebriefQueueService::autoDebriefEnabled()) {
            $out['debrief'] = array('ok' => true, 'skipped' => true, 'reason' => 'auto_debrief_disabled');
        } elseif (!$debriefQueue->hasPreliminaryTranscript((int)$recording['id'])) {
            $out['debrief'] = array(
                'ok' => true,
                'skipped' => true,
                'reason' => 'waiting_for_live_or_pass4_transcript',
                'garmin_blocking' => false,
            );
        } elseif ($this->hasStructuredDebrief($bundleId)) {
            $out['debrief'] = array('ok' => true, 'skipped' => true, 'reason' => 'already_debriefed');
        } else {
            try {
                $out['debrief'] = $debriefQueue->lockAndQueuePreliminaryDebrief($bundleId, null);
            } catch (Throwable $e) {
                $out['debrief'] = array('ok' => false, 'error' => $e->getMessage());
            }
        }
        return $out;
    }

    /**
     * Spawn CockpitReplayPipeline reconstruction once the frozen triad exists.
     *
     * @return array<string,mixed>
     */
    private function ensureFlightReconstructionStarted(int $bundleId, int $recordingId): array
    {
        if ($bundleId <= 0 || $recordingId <= 0) {
            return array('ok' => false, 'reason' => 'missing_ids');
        }

        $statement = $this->pdo->prepare(
            'SELECT reconstruction_status FROM ipca_cockpit_recordings WHERE id = ? LIMIT 1'
        );
        $statement->execute(array($recordingId));
        $status = strtolower(trim((string)$statement->fetchColumn()));
        if (in_array($status, array('ready', 'processing', 'queued'), true)) {
            return array('ok' => true, 'skipped' => true, 'reason' => 'already_' . ($status !== '' ? $status : 'started'), 'status' => $status);
        }

        try {
            require_once __DIR__ . '/CockpitReconstructionService.php';
            require_once __DIR__ . '/CockpitRecorderService.php';
            require_once __DIR__ . '/AuditEventService.php';

            $source = $this->bundles->reconstructionSource($bundleId);
            $service = new CockpitReconstructionService($this->pdo);
            $jobId = $service->createReconstructionJob($recordingId);

            $this->pdo->prepare(
                "UPDATE ipca_cockpit_recordings
                 SET reconstruction_status = 'processing', timeline_status = 'processing',
                     error_message = NULL, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?"
            )->execute(array($recordingId));

            $this->pdo->prepare(
                "UPDATE ipca_manual_intake_bundles
                 SET status = 'processing', replay_status = 'processing', reconstruction_job_id = ?,
                     processing_error = NULL WHERE id = ?"
            )->execute(array($jobId, $bundleId));

            $php = $this->cliPhpBinary();
            $script = realpath(__DIR__ . '/../scripts/run_cockpit_recorder_reconstruction.php');
            if ($php === '' || $script === false || !function_exists('exec')) {
                throw new RuntimeException('Reconstruction worker is unavailable.');
            }

            $logDir = CockpitRecorderService::projectRoot() . '/storage/logs';
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0775, true);
            }
            $log = $logDir . '/auto_bundle_reconstruction_' . $bundleId . '.log';
            $command = 'nohup '
                . escapeshellarg($php) . ' '
                . escapeshellarg($script) . ' '
                . escapeshellarg('--recording-id=' . $recordingId) . ' '
                . escapeshellarg('--job-id=' . $jobId) . ' '
                . escapeshellarg('--bundle-id=' . $bundleId) . ' '
                . escapeshellarg('--g3x-csv-path=' . (string)$source['g3x_csv_path']) . ' '
                . escapeshellarg('--replay-source-mode=g3x_only')
                . ' >> ' . escapeshellarg($log) . ' 2>&1 < /dev/null & echo $!';
            $output = array();
            $exitCode = 0;
            exec($command, $output, $exitCode);
            if ($exitCode !== 0) {
                throw new RuntimeException('Could not start the Reconstruction worker.');
            }

            if ($this->tableExists('ipca_manual_intake_bundle_audit')) {
                $this->pdo->prepare(
                    'INSERT INTO ipca_manual_intake_bundle_audit
                     (event_uuid, bundle_id, event_type, actor_user_id, detail_json)
                     VALUES (?, ?, \'reconstruction_auto_started\', NULL, ?)'
                )->execute(array(
                    AuditEventService::uuid(),
                    $bundleId,
                    AuditEventService::jsonEncode(array(
                        'job_id' => $jobId,
                        'recording_id' => $recordingId,
                        'pid' => trim((string)($output[0] ?? '')),
                    )),
                ));
            }

            return array(
                'ok' => true,
                'started' => true,
                'job_id' => $jobId,
                'status' => 'processing',
            );
        } catch (Throwable $e) {
            error_log('[CvrAutoReconstructionOrchestrator] reconstruction start failed: ' . $e->getMessage());
            return array('ok' => false, 'error' => $e->getMessage());
        }
    }

    private function cliPhpBinary(): string
    {
        $candidates = array();
        $bindir = trim((string)PHP_BINDIR);
        if ($bindir !== '') {
            $candidates[] = $bindir . DIRECTORY_SEPARATOR . 'php';
        }
        $candidates[] = '/usr/bin/php';
        $candidates[] = '/usr/local/bin/php';
        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return '';
    }

    private function resolveFlightUuid(
        ?string $flightUuid,
        ?int $recordingId,
        ?int $dispatchId,
        ?int $garminCsvId
    ): string {
        $candidate = strtolower(trim((string)$flightUuid));
        if ($this->isUuid($candidate)) {
            return $candidate;
        }
        if ($dispatchId !== null && $dispatchId > 0) {
            $statement = $this->pdo->prepare(
                'SELECT workflow_flight_record_uuid FROM ipca_cvr_dispatches WHERE id = ? LIMIT 1'
            );
            $statement->execute(array($dispatchId));
            $value = strtolower(trim((string)$statement->fetchColumn()));
            if ($this->isUuid($value)) {
                return $value;
            }
        }
        if ($recordingId !== null && $recordingId > 0) {
            $statement = $this->pdo->prepare(
                'SELECT flight_session_uid FROM ipca_cockpit_recordings WHERE id = ? LIMIT 1'
            );
            $statement->execute(array($recordingId));
            $value = strtolower(trim((string)$statement->fetchColumn()));
            if ($this->isUuid($value)) {
                return $value;
            }
        }
        if ($garminCsvId !== null && $garminCsvId > 0) {
            $statement = $this->pdo->prepare(
                'SELECT workflow_flight_record_uuid FROM ipca_garmin_csv_files WHERE id = ? LIMIT 1'
            );
            $statement->execute(array($garminCsvId));
            $value = strtolower(trim((string)$statement->fetchColumn()));
            if ($this->isUuid($value)) {
                return $value;
            }
        }
        return '';
    }

    /** @return array<string,mixed>|null */
    private function latestDispatch(string $flightUuid, ?int $preferredId): ?array
    {
        if ($preferredId !== null && $preferredId > 0) {
            $statement = $this->pdo->prepare(
                'SELECT * FROM ipca_cvr_dispatches
                 WHERE id = ? AND LOWER(workflow_flight_record_uuid) = ?
                   AND LOWER(TRIM(COALESCE(status, \'\'))) <> \'released\'
                 LIMIT 1'
            );
            $statement->execute(array($preferredId, $flightUuid));
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                return $row;
            }
        }
        $statement = $this->pdo->prepare(
            'SELECT * FROM ipca_cvr_dispatches
             WHERE LOWER(workflow_flight_record_uuid) = ?
               AND LOWER(TRIM(COALESCE(status, \'\'))) <> \'released\'
             ORDER BY id DESC LIMIT 1'
        );
        $statement->execute(array($flightUuid));
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function latestRecording(string $flightUuid, ?int $preferredId): ?array
    {
        if ($preferredId !== null && $preferredId > 0) {
            $statement = $this->pdo->prepare(
                'SELECT * FROM ipca_cockpit_recordings
                 WHERE id = ? AND LOWER(flight_session_uid) = ?
                 LIMIT 1'
            );
            $statement->execute(array($preferredId, $flightUuid));
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                return $row;
            }
        }
        $statement = $this->pdo->prepare(
            'SELECT * FROM ipca_cockpit_recordings
             WHERE LOWER(flight_session_uid) = ?
             ORDER BY id DESC LIMIT 1'
        );
        $statement->execute(array($flightUuid));
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function latestGarmin(string $flightUuid, ?int $preferredId): ?array
    {
        if ($preferredId !== null && $preferredId > 0) {
            $statement = $this->pdo->prepare(
                'SELECT * FROM ipca_garmin_csv_files
                 WHERE id = ? AND LOWER(workflow_flight_record_uuid) = ?
                 LIMIT 1'
            );
            $statement->execute(array($preferredId, $flightUuid));
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                return $row;
            }
        }
        $statement = $this->pdo->prepare(
            'SELECT * FROM ipca_garmin_csv_files
             WHERE LOWER(workflow_flight_record_uuid) = ?
             ORDER BY id DESC LIMIT 1'
        );
        $statement->execute(array($flightUuid));
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function findMatchingTriadBundle(int $dispatchId, int $recordingId, int $garminCsvId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM ipca_manual_intake_bundles
             WHERE dispatch_id = ? AND cockpit_recording_id = ? AND garmin_csv_file_id = ?
             ORDER BY id DESC LIMIT 1'
        );
        $statement->execute(array($dispatchId, $recordingId, $garminCsvId));
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function findUnlockedBundleForRecording(int $recordingId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM ipca_manual_intake_bundles
             WHERE cockpit_recording_id = ? AND transcript_snapshot_id IS NULL
               AND garmin_csv_file_id IS NOT NULL
             ORDER BY id DESC LIMIT 1'
        );
        $statement->execute(array($recordingId));
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function hasStructuredDebrief(int $bundleId): bool
    {
        if ($bundleId <= 0 || !$this->tableExists('ipca_structured_debriefs')) {
            return false;
        }
        $statement = $this->pdo->prepare(
            'SELECT id FROM ipca_structured_debriefs WHERE bundle_id = ? ORDER BY id DESC LIMIT 1'
        );
        $statement->execute(array($bundleId));
        return (int)$statement->fetchColumn() > 0;
    }

    private function isUuid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $value
        ) === 1;
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
}
