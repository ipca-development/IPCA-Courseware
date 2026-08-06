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
            return array('ok' => true, 'skipped' => true, 'reason' => 'waiting_for_garmin', 'flight_uuid' => $resolved);
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
