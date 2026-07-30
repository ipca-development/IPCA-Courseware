<?php
declare(strict_types=1);

require_once __DIR__ . '/EvidenceSchema.php';

final class SuppressionRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function create(
        int $processingRunId,
        string $suppressionType,
        string $reason,
        ?int $speechSegmentId = null,
        ?int $interpretationRevisionId = null,
        ?string $suppressedText = null,
        ?int $retainedSegmentId = null
    ): array {
        EvidenceSchema::requireTables($this->pdo, array(EvidenceSchema::TABLE_SUPPRESSIONS));

        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . EvidenceSchema::TABLE_SUPPRESSIONS
            . ' (processing_run_id, speech_segment_id, interpretation_revision_id, suppression_type, reason,'
            . ' retained_segment_id, suppressed_text)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute(array(
            $processingRunId,
            $speechSegmentId,
            $interpretationRevisionId,
            $suppressionType,
            substr($reason, 0, 255),
            $retainedSegmentId,
            $suppressedText,
        ));

        return array(
            'id' => (int)$this->pdo->lastInsertId(),
            'processing_run_id' => $processingRunId,
            'suppression_type' => $suppressionType,
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listForProcessingRun(int $processingRunId): array
    {
        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_SUPPRESSIONS)) {
            return array();
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . EvidenceSchema::TABLE_SUPPRESSIONS
            . ' WHERE processing_run_id = ? ORDER BY id ASC'
        );
        $stmt->execute(array($processingRunId));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }
}
