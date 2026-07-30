<?php
declare(strict_types=1);

require_once __DIR__ . '/EvidenceSchema.php';

final class PublishedTranscriptVersionRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param list<int> $interpretationRevisionIds
     * @param list<int>|null $knowledgePackVersionIds
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    public function create(
        int $recordingId,
        int $processingRunId,
        array $interpretationRevisionIds,
        array $snapshot,
        string $legacyCacheText,
        ?array $knowledgePackVersionIds = null,
        ?int $publishedBy = null
    ): array {
        EvidenceSchema::requireTables($this->pdo, array(EvidenceSchema::TABLE_PUBLISHED_VERSIONS));

        $uuid = $this->uuid();
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . EvidenceSchema::TABLE_PUBLISHED_VERSIONS
            . ' (version_uuid, recording_id, processing_run_id, published_by,'
            . ' interpretation_revision_ids_json, knowledge_pack_version_ids_json,'
            . ' snapshot_json, legacy_transcript_cache_text)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute(array(
            $uuid,
            $recordingId,
            $processingRunId,
            $publishedBy,
            json_encode(array_values($interpretationRevisionIds), JSON_UNESCAPED_SLASHES),
            $knowledgePackVersionIds !== null
                ? json_encode(array_values($knowledgePackVersionIds), JSON_UNESCAPED_SLASHES)
                : null,
            json_encode($snapshot, JSON_UNESCAPED_SLASHES),
            $legacyCacheText,
        ));

        return $this->findById((int)$this->pdo->lastInsertId()) ?? array(
            'id' => (int)$this->pdo->lastInsertId(),
            'version_uuid' => $uuid,
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findById(int $id): ?array
    {
        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_PUBLISHED_VERSIONS)) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . EvidenceSchema::TABLE_PUBLISHED_VERSIONS . ' WHERE id = ? LIMIT 1'
        );
        $stmt->execute(array($id));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findByUuid(string $versionUuid): ?array
    {
        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_PUBLISHED_VERSIONS)) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . EvidenceSchema::TABLE_PUBLISHED_VERSIONS
            . ' WHERE version_uuid = ? LIMIT 1'
        );
        $stmt->execute(array($versionUuid));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listForRecording(int $recordingId, int $limit = 20): array
    {
        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_PUBLISHED_VERSIONS)) {
            return array();
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, version_uuid, recording_id, processing_run_id, published_at, published_by, created_at'
            . ' FROM ' . EvidenceSchema::TABLE_PUBLISHED_VERSIONS
            . ' WHERE recording_id = ? ORDER BY published_at DESC, id DESC LIMIT ' . max(1, min(100, $limit))
        );
        $stmt->execute(array($recordingId));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
