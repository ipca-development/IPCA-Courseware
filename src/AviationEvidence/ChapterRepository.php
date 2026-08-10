<?php
declare(strict_types=1);

require_once __DIR__ . '/EvidenceSchema.php';

final class ChapterRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listForProcessingRun(int $processingRunId): array
    {
        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_CHAPTERS)) {
            return array();
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . EvidenceSchema::TABLE_CHAPTERS
            . ' WHERE processing_run_id = ? ORDER BY start_time_ms ASC, id ASC'
        );
        $stmt->execute(array($processingRunId));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    public function deleteForProcessingRun(int $processingRunId): void
    {
        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_CHAPTERS)) {
            return;
        }
        $stmt = $this->pdo->prepare(
            'DELETE FROM ' . EvidenceSchema::TABLE_CHAPTERS . ' WHERE processing_run_id = ?'
        );
        $stmt->execute(array($processingRunId));
    }

    /**
     * @param list<array<string,mixed>> $chapters
     * @return list<array<string,mixed>>
     */
    public function replaceForProcessingRun(int $recordingId, int $processingRunId, array $chapters): array
    {
        EvidenceSchema::requireTables($this->pdo, array(EvidenceSchema::TABLE_CHAPTERS));
        $this->deleteForProcessingRun($processingRunId);

        $insert = $this->pdo->prepare(
            'INSERT INTO ' . EvidenceSchema::TABLE_CHAPTERS
            . ' (recording_id, processing_run_id, title, category, start_time_ms, end_time_ms,'
            . ' calculated_confidence, confidence_algorithm_version, manually_edited, supporting_segment_ids_json)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $out = array();
        foreach ($chapters as $chapter) {
            $segmentIds = is_array($chapter['supporting_segment_ids'] ?? null) ? $chapter['supporting_segment_ids'] : array();
            $insert->execute(array(
                $recordingId,
                $processingRunId,
                mb_substr((string)($chapter['title'] ?? 'Section'), 0, 255),
                isset($chapter['category']) ? mb_substr((string)$chapter['category'], 0, 64) : null,
                (int)($chapter['start_time_ms'] ?? 0),
                (int)($chapter['end_time_ms'] ?? 0),
                isset($chapter['confidence']) ? (float)$chapter['confidence'] : null,
                EvidenceSchema::PASS5_VERSION,
                !empty($chapter['manually_edited']) ? 1 : 0,
                json_encode(array_values(array_map('intval', $segmentIds)), JSON_UNESCAPED_SLASHES),
            ));
            $id = (int)$this->pdo->lastInsertId();
            $out[] = array(
                'id' => $id,
                'title' => (string)($chapter['title'] ?? ''),
                'category' => (string)($chapter['category'] ?? ''),
                'start_time_ms' => (int)($chapter['start_time_ms'] ?? 0),
                'end_time_ms' => (int)($chapter['end_time_ms'] ?? 0),
                'confidence' => isset($chapter['confidence']) ? (float)$chapter['confidence'] : null,
                'supporting_segment_ids' => $segmentIds,
            );
        }
        return $out;
    }
}
