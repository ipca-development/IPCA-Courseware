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
        $stmt = $this->pdo->prepare(
            'UPDATE ' . EvidenceSchema::TABLE_PROCESSING_RUNS
            . ' SET status = ?, completed_at = CURRENT_TIMESTAMP(3) WHERE id = ?'
        );
        $stmt->execute(array('completed', $runId));
    }

    public function updateStatus(int $runId, string $status): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . EvidenceSchema::TABLE_PROCESSING_RUNS . ' SET status = ? WHERE id = ?'
        );
        $stmt->execute(array($status, $runId));
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
