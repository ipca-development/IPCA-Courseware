<?php
declare(strict_types=1);

require_once __DIR__ . '/EvidenceSchema.php';

final class DisplayBlockRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listForProcessingRun(int $processingRunId): array
    {
        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_DISPLAY_BLOCKS)) {
            return array();
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . EvidenceSchema::TABLE_DISPLAY_BLOCKS
            . ' WHERE processing_run_id = ? ORDER BY start_time_ms ASC, id ASC'
        );
        $stmt->execute(array($processingRunId));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    public function deleteForProcessingRun(int $processingRunId): void
    {
        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_DISPLAY_BLOCKS)) {
            return;
        }
        $stmt = $this->pdo->prepare(
            'DELETE FROM ' . EvidenceSchema::TABLE_DISPLAY_BLOCKS . ' WHERE processing_run_id = ?'
        );
        $stmt->execute(array($processingRunId));
    }

    /**
     * @param list<array<string,mixed>> $blocks
     * @return list<array<string,mixed>>
     */
    public function replaceForProcessingRun(int $recordingId, int $processingRunId, array $blocks): array
    {
        EvidenceSchema::requireTables($this->pdo, array(EvidenceSchema::TABLE_DISPLAY_BLOCKS));
        $this->deleteForProcessingRun($processingRunId);

        $insert = $this->pdo->prepare(
            'INSERT INTO ' . EvidenceSchema::TABLE_DISPLAY_BLOCKS
            . ' (recording_id, processing_run_id, start_time_ms, end_time_ms, speech_segment_ids_json)'
            . ' VALUES (?, ?, ?, ?, ?)'
        );

        $out = array();
        foreach ($blocks as $block) {
            $segmentIds = is_array($block['speech_segment_ids'] ?? null) ? $block['speech_segment_ids'] : array();
            $insert->execute(array(
                $recordingId,
                $processingRunId,
                (int)($block['start_time_ms'] ?? 0),
                (int)($block['end_time_ms'] ?? 0),
                json_encode(array_values(array_map('intval', $segmentIds)), JSON_UNESCAPED_SLASHES),
            ));
            $id = (int)$this->pdo->lastInsertId();
            $out[] = array(
                'id' => $id,
                'start_time_ms' => (int)($block['start_time_ms'] ?? 0),
                'end_time_ms' => (int)($block['end_time_ms'] ?? 0),
                'text' => (string)($block['text'] ?? ''),
                'speech_segment_ids' => $segmentIds,
                'suppressed' => !empty($block['suppressed']),
            );
        }
        return $out;
    }
}
