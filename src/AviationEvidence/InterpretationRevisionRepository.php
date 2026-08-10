<?php
declare(strict_types=1);

require_once __DIR__ . '/EvidenceSchema.php';

final class InterpretationRevisionRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param list<array{factor_type:string,source_type:string,source_id:?int,weight:float,description:string}> $factors
     * @return array<string,mixed>
     */
    public function createRevision(
        int $speechSegmentId,
        string $layer,
        string $text,
        ?array $reasoning = null,
        ?float $confidence = null,
        ?string $algorithmVersion = null,
        array $factors = array()
    ): array {
        EvidenceSchema::requireTables($this->pdo, array(
            EvidenceSchema::TABLE_INTERPRETATION_REVISIONS,
            EvidenceSchema::TABLE_SPEECH_SEGMENTS,
        ));

        $revisionNumber = $this->nextRevisionNumber($speechSegmentId, $layer);
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . EvidenceSchema::TABLE_INTERPRETATION_REVISIONS
            . ' (speech_segment_id, layer, revision_number, text, calculated_confidence,'
            . ' confidence_algorithm_version, reasoning_json)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute(array(
            $speechSegmentId,
            $layer,
            $revisionNumber,
            $text,
            $confidence,
            $algorithmVersion,
            $reasoning !== null ? json_encode($reasoning, JSON_UNESCAPED_SLASHES) : null,
        ));
        $id = (int)$this->pdo->lastInsertId();

        if ($factors !== array() && EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_INTERPRETATION_CONFIDENCE)) {
            $factorStmt = $this->pdo->prepare(
                'INSERT INTO ' . EvidenceSchema::TABLE_INTERPRETATION_CONFIDENCE
                . ' (interpretation_revision_id, factor_type, source_type, source_id, weight, description)'
                . ' VALUES (?, ?, ?, ?, ?, ?)'
            );
            foreach ($factors as $factor) {
                $factorStmt->execute(array(
                    $id,
                    (string)($factor['factor_type'] ?? 'support'),
                    (string)($factor['source_type'] ?? 'pass4'),
                    isset($factor['source_id']) ? (int)$factor['source_id'] : null,
                    (float)($factor['weight'] ?? 0.0),
                    mb_substr((string)($factor['description'] ?? ''), 0, 512),
                ));
            }
        }

        return $this->findById($id) ?? array('id' => $id, 'speech_segment_id' => $speechSegmentId, 'layer' => $layer);
    }

    public function hasRevisionForRun(int $processingRunId, string $layer): bool
    {
        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_INTERPRETATION_REVISIONS)) {
            return false;
        }
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ' . EvidenceSchema::TABLE_INTERPRETATION_REVISIONS . ' i'
            . ' INNER JOIN ' . EvidenceSchema::TABLE_SPEECH_SEGMENTS . ' s ON s.id = i.speech_segment_id'
            . ' WHERE s.processing_run_id = ? AND i.layer = ?'
        );
        $stmt->execute(array($processingRunId, $layer));
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findLatestForRunByLayer(int $processingRunId, string $layer): ?array
    {
        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_INTERPRETATION_REVISIONS)) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT i.* FROM ' . EvidenceSchema::TABLE_INTERPRETATION_REVISIONS . ' i'
            . ' INNER JOIN ' . EvidenceSchema::TABLE_SPEECH_SEGMENTS . ' s ON s.id = i.speech_segment_id'
            . ' WHERE s.processing_run_id = ? AND i.layer = ?'
            . ' ORDER BY i.revision_number DESC, i.id DESC LIMIT 1'
        );
        $stmt->execute(array($processingRunId, $layer));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findLatestReadableForProcessingRun(int $processingRunId): ?array
    {
        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_INTERPRETATION_REVISIONS)) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT i.* FROM ' . EvidenceSchema::TABLE_INTERPRETATION_REVISIONS . ' i'
            . ' INNER JOIN ' . EvidenceSchema::TABLE_SPEECH_SEGMENTS . ' s ON s.id = i.speech_segment_id'
            . ' WHERE s.processing_run_id = ? AND i.layer = ?'
            . ' ORDER BY i.revision_number DESC, i.id DESC LIMIT 1'
        );
        $stmt->execute(array($processingRunId, EvidenceSchema::LAYER_READABLE));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return list<int>
     */
    public function listRevisionIdsForProcessingRun(int $processingRunId): array
    {
        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_INTERPRETATION_REVISIONS)) {
            return array();
        }
        $stmt = $this->pdo->prepare(
            'SELECT i.id FROM ' . EvidenceSchema::TABLE_INTERPRETATION_REVISIONS . ' i'
            . ' INNER JOIN ' . EvidenceSchema::TABLE_SPEECH_SEGMENTS . ' s ON s.id = i.speech_segment_id'
            . ' WHERE s.processing_run_id = ? ORDER BY i.id ASC'
        );
        $stmt->execute(array($processingRunId));
        return array_map(static fn(array $row): int => (int)$row['id'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array());
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findById(int $id): ?array
    {
        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_INTERPRETATION_REVISIONS)) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM ' . EvidenceSchema::TABLE_INTERPRETATION_REVISIONS . ' WHERE id = ? LIMIT 1');
        $stmt->execute(array($id));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function nextRevisionNumber(int $speechSegmentId, string $layer): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(MAX(revision_number), 0) + 1 FROM ' . EvidenceSchema::TABLE_INTERPRETATION_REVISIONS
            . ' WHERE speech_segment_id = ? AND layer = ?'
        );
        $stmt->execute(array($speechSegmentId, $layer));
        return (int)$stmt->fetchColumn();
    }
}
