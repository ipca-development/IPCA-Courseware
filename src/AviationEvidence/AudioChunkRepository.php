<?php
declare(strict_types=1);

require_once __DIR__ . '/EvidenceSchema.php';

final class AudioChunkRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function upsert(
        int $recordingId,
        int $processingRunId,
        int $chunkIndex,
        int $startTimeMs,
        int $endTimeMs,
        string $audioSha256,
        ?int $byteLength = null,
        ?int $sampleRate = null,
        ?int $channels = null
    ): array {
        EvidenceSchema::requireTables($this->pdo, array(EvidenceSchema::TABLE_AUDIO_CHUNKS));

        $existing = $this->findByRunAndIndex($processingRunId, $chunkIndex);
        if ($existing !== null) {
            return $existing;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . EvidenceSchema::TABLE_AUDIO_CHUNKS
            . ' (recording_id, processing_run_id, chunk_index, start_time_ms, end_time_ms, audio_sha256, byte_length, sample_rate, channels)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute(array(
            $recordingId,
            $processingRunId,
            $chunkIndex,
            $startTimeMs,
            $endTimeMs,
            strtolower($audioSha256),
            $byteLength,
            $sampleRate,
            $channels,
        ));

        return $this->findById((int)$this->pdo->lastInsertId()) ?? array('id' => (int)$this->pdo->lastInsertId());
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findByRunAndIndex(int $processingRunId, int $chunkIndex): ?array
    {
        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_AUDIO_CHUNKS)) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . EvidenceSchema::TABLE_AUDIO_CHUNKS
            . ' WHERE processing_run_id = ? AND chunk_index = ? LIMIT 1'
        );
        $stmt->execute(array($processingRunId, $chunkIndex));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findById(int $id): ?array
    {
        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_AUDIO_CHUNKS)) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM ' . EvidenceSchema::TABLE_AUDIO_CHUNKS . ' WHERE id = ? LIMIT 1');
        $stmt->execute(array($id));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}
