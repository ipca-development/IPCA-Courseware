<?php
declare(strict_types=1);

require_once __DIR__ . '/EvidenceSchema.php';

final class ProcessingRunRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function create(
        int $recordingId,
        ?int $parentRunId = null,
        string $canonicalAsrModel = 'whisper-1',
        ?string $secondaryAsrModel = 'gpt-4o-transcribe',
        ?int $createdBy = null
    ): array {
        return $this->createWithUuid($recordingId, $this->uuid(), EvidenceSchema::RUN_PURPOSE_REPROBE, $parentRunId, $canonicalAsrModel, $secondaryAsrModel, $createdBy);
    }

    /**
     * @return array<string,mixed>
     */
    public function createWithUuid(
        int $recordingId,
        string $runUuid,
        string $runPurpose = EvidenceSchema::RUN_PURPOSE_REPROBE,
        ?int $parentRunId = null,
        string $canonicalAsrModel = 'whisper-1',
        ?string $secondaryAsrModel = 'gpt-4o-transcribe',
        ?int $createdBy = null
    ): array {
        EvidenceSchema::requireTables($this->pdo, array(EvidenceSchema::TABLE_PROCESSING_RUNS));

        if (EvidenceSchema::processingRunHasLifecycleColumns($this->pdo)) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO ' . EvidenceSchema::TABLE_PROCESSING_RUNS
                . ' (run_uuid, recording_id, parent_run_id, status, canonical_timeline_source, canonical_asr_model, secondary_asr_model, created_by, heartbeat_at, current_phase)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?)'
            );
            $stmt->execute(array(
                $runUuid,
                $recordingId,
                $parentRunId,
                'running',
                'whisper_segment_timestamps',
                $canonicalAsrModel,
                $secondaryAsrModel,
                $createdBy,
                'starting',
            ));
        } else {
            $stmt = $this->pdo->prepare(
                'INSERT INTO ' . EvidenceSchema::TABLE_PROCESSING_RUNS
                . ' (run_uuid, recording_id, parent_run_id, status, canonical_timeline_source, canonical_asr_model, secondary_asr_model, created_by)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute(array(
                $runUuid,
                $recordingId,
                $parentRunId,
                'running',
                'whisper_segment_timestamps',
                $canonicalAsrModel,
                $secondaryAsrModel,
                $createdBy,
            ));
        }

        return $this->findById((int)$this->pdo->lastInsertId()) ?? array('id' => (int)$this->pdo->lastInsertId(), 'run_uuid' => $runUuid);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findById(int $id): ?array
    {
        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_PROCESSING_RUNS)) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM ' . EvidenceSchema::TABLE_PROCESSING_RUNS . ' WHERE id = ? LIMIT 1');
        $stmt->execute(array($id));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function markRunning(int $runId): void
    {
        $this->updateStatus($runId, 'running');
    }

    public function markCompleted(int $runId): void
    {
        if (EvidenceSchema::processingRunHasLifecycleColumns($this->pdo)) {
            $stmt = $this->pdo->prepare(
                'UPDATE ' . EvidenceSchema::TABLE_PROCESSING_RUNS
                . ' SET status = ?, completed_at = CURRENT_TIMESTAMP(3), current_phase = ?, failure_reason = NULL'
                . ' WHERE id = ?'
            );
            $stmt->execute(array('completed', 'completed', $runId));
            return;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE ' . EvidenceSchema::TABLE_PROCESSING_RUNS
            . ' SET status = ?, completed_at = CURRENT_TIMESTAMP(3) WHERE id = ?'
        );
        $stmt->execute(array('completed', $runId));
    }

    public function markFailed(int $runId, string $reason, ?string $phase = null): bool
    {
        if ($runId <= 0 || !EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_PROCESSING_RUNS)) {
            return false;
        }

        $reason = mb_substr(trim($reason), 0, 512);
        if ($reason === '') {
            $reason = 'unknown_failure';
        }

        if (EvidenceSchema::processingRunHasLifecycleColumns($this->pdo)) {
            $stmt = $this->pdo->prepare(
                'UPDATE ' . EvidenceSchema::TABLE_PROCESSING_RUNS
                . ' SET status = ?, failure_reason = ?, completed_at = CURRENT_TIMESTAMP(3), current_phase = COALESCE(?, current_phase)'
                . ' WHERE id = ? AND status = ?'
            );
            $stmt->execute(array('failed', $reason, $phase, $runId, 'running'));
            return $stmt->rowCount() > 0;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE ' . EvidenceSchema::TABLE_PROCESSING_RUNS
            . ' SET status = ?, completed_at = CURRENT_TIMESTAMP(3) WHERE id = ? AND status = ?'
        );
        $stmt->execute(array('failed', $runId, 'running'));
        return $stmt->rowCount() > 0;
    }

    public function touchHeartbeat(int $runId, string $phase): void
    {
        if ($runId <= 0 || !EvidenceSchema::processingRunHasLifecycleColumns($this->pdo)) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE ' . EvidenceSchema::TABLE_PROCESSING_RUNS
            . ' SET heartbeat_at = CURRENT_TIMESTAMP(3), current_phase = ?'
            . ' WHERE id = ? AND status = ?'
        );
        $stmt->execute(array(mb_substr(trim($phase), 0, 64), $runId, 'running'));
    }

    public function updateStatus(int $runId, string $status): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . EvidenceSchema::TABLE_PROCESSING_RUNS . ' SET status = ? WHERE id = ?'
        );
        $stmt->execute(array($status, $runId));
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findByRunUuid(string $runUuid): ?array
    {
        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_PROCESSING_RUNS)) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM ' . EvidenceSchema::TABLE_PROCESSING_RUNS . ' WHERE run_uuid = ? LIMIT 1');
        $stmt->execute(array($runUuid));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * Latest completed initial-transcription run for a recording with matching source audio hash.
     *
     * @return array<string,mixed>|null
     */
    public function findLatestInitialForRecording(int $recordingId, string $sourceAudioSha256): ?array
    {
        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_PROCESSING_RUNS)) {
            return null;
        }
        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_PROVIDER_RUNS)) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT pr.* FROM ' . EvidenceSchema::TABLE_PROCESSING_RUNS . ' pr'
            . ' INNER JOIN ' . EvidenceSchema::TABLE_PROVIDER_RUNS . ' p ON p.processing_run_id = pr.id'
            . ' WHERE pr.recording_id = ? AND pr.status = ? AND p.run_purpose = ? AND p.source_audio_sha256 = ?'
            . ' GROUP BY pr.id ORDER BY pr.completed_at DESC, pr.id DESC LIMIT 1'
        );
        $stmt->execute(array(
            $recordingId,
            'completed',
            EvidenceSchema::RUN_PURPOSE_INITIAL,
            strtolower($sourceAudioSha256),
        ));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * Latest completed run for a recording that has a readable_primary interpretation.
     *
     * @return array<string,mixed>|null
     */
    public function findLatestPublishableForRecording(int $recordingId): ?array
    {
        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_PROCESSING_RUNS)) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT pr.* FROM ' . EvidenceSchema::TABLE_PROCESSING_RUNS . ' pr'
            . ' WHERE pr.recording_id = ? AND pr.status = ?'
            . ' AND EXISTS ('
            . '   SELECT 1 FROM ' . EvidenceSchema::TABLE_SPEECH_SEGMENTS . ' s'
            . '   INNER JOIN ' . EvidenceSchema::TABLE_INTERPRETATION_REVISIONS . ' i ON i.speech_segment_id = s.id'
            . '   WHERE s.processing_run_id = pr.id AND i.layer = ?'
            . ' )'
            . ' ORDER BY pr.completed_at DESC, pr.id DESC LIMIT 1'
        );
        $stmt->execute(array($recordingId, 'completed', EvidenceSchema::LAYER_READABLE));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findRunningForRecording(int $recordingId): ?array
    {
        $rows = $this->listRunningForRecording($recordingId);
        return $rows[0] ?? null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listRunningForRecording(int $recordingId): array
    {
        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_PROCESSING_RUNS)) {
            return array();
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . EvidenceSchema::TABLE_PROCESSING_RUNS
            . ' WHERE recording_id = ? AND status = ? ORDER BY id DESC'
        );
        $stmt->execute(array($recordingId, 'running'));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findLatestFailedForRecording(int $recordingId): ?array
    {
        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_PROCESSING_RUNS)) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . EvidenceSchema::TABLE_PROCESSING_RUNS
            . ' WHERE recording_id = ? AND status = ? ORDER BY completed_at DESC, id DESC LIMIT 1'
        );
        $stmt->execute(array($recordingId, 'failed'));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findLatestForRecording(int $recordingId): ?array
    {
        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_PROCESSING_RUNS)) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . EvidenceSchema::TABLE_PROCESSING_RUNS
            . ' WHERE recording_id = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(array($recordingId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
