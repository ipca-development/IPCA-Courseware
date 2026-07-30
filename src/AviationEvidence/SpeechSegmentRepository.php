<?php
declare(strict_types=1);

require_once __DIR__ . '/EvidenceSchema.php';

final class SpeechSegmentRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Materialize canonical speech segments 1:1 from whisper provider segments.
     *
     * @param list<array<string,mixed>> $providerSegments
     * @return list<array<string,mixed>>
     */
    public function materializeFromProviderRun(
        int $recordingId,
        int $processingRunId,
        int $providerRunId,
        array $providerSegments,
        ?string $detectedLanguage = null
    ): array {
        EvidenceSchema::requireTables($this->pdo, array(EvidenceSchema::TABLE_SPEECH_SEGMENTS));

        $existing = $this->listForProcessingRun($processingRunId);
        if ($existing !== array()) {
            return $existing;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO ' . EvidenceSchema::TABLE_SPEECH_SEGMENTS
            . ' (recording_id, processing_run_id, primary_provider_segment_id, primary_provider_run_id,'
            . ' start_time_ms, end_time_ms, detected_language)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($providerSegments as $segment) {
            $providerSegmentId = (int)($segment['id'] ?? 0);
            $insert->execute(array(
                $recordingId,
                $processingRunId,
                $providerSegmentId > 0 ? $providerSegmentId : null,
                $providerRunId,
                (int)($segment['start_time_ms'] ?? 0),
                (int)($segment['end_time_ms'] ?? 0),
                $detectedLanguage,
            ));
        }

        return $this->listForProcessingRun($processingRunId);
    }

    /**
     * Materialize speech segments from one or more whisper provider runs, offsetting chunk-relative times.
     *
     * @param list<array<string,mixed>> $whisperRuns
     * @return list<array<string,mixed>>
     */
    public function materializeFromWhisperRuns(
        int $recordingId,
        int $processingRunId,
        array $whisperRuns,
        ?string $detectedLanguage = null
    ): array {
        EvidenceSchema::requireTables($this->pdo, array(EvidenceSchema::TABLE_SPEECH_SEGMENTS));

        $existing = $this->listForProcessingRun($processingRunId);
        if ($existing !== array()) {
            return $existing;
        }

        if ($whisperRuns === array()) {
            return array();
        }

        require_once __DIR__ . '/ProviderRunRepository.php';
        $providerRuns = new ProviderRunRepository($this->pdo);

        $insert = $this->pdo->prepare(
            'INSERT INTO ' . EvidenceSchema::TABLE_SPEECH_SEGMENTS
            . ' (recording_id, processing_run_id, primary_provider_segment_id, primary_provider_run_id,'
            . ' start_time_ms, end_time_ms, detected_language)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($whisperRuns as $run) {
            $providerRunId = (int)($run['id'] ?? 0);
            if ($providerRunId <= 0) {
                continue;
            }
            $offsetMs = (int)($run['chunk_start_time_ms'] ?? 0);
            foreach ($providerRuns->listSegments($providerRunId) as $segment) {
                $providerSegmentId = (int)($segment['id'] ?? 0);
                $startMs = (int)($segment['start_time_ms'] ?? 0) + $offsetMs;
                $endMs = (int)($segment['end_time_ms'] ?? 0) + $offsetMs;
                $insert->execute(array(
                    $recordingId,
                    $processingRunId,
                    $providerSegmentId > 0 ? $providerSegmentId : null,
                    $providerRunId,
                    $startMs,
                    $endMs,
                    $detectedLanguage,
                ));
            }
        }

        return $this->listForProcessingRun($processingRunId);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listForProcessingRun(int $processingRunId): array
    {
        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_SPEECH_SEGMENTS)) {
            return array();
        }
        $stmt = $this->pdo->prepare(
            'SELECT s.*, ps.segment_index AS provider_segment_index, ps.text AS provider_segment_text,'
            . ' ps.avg_log_probability, ps.compression_ratio, ps.no_speech_probability'
            . ' FROM ' . EvidenceSchema::TABLE_SPEECH_SEGMENTS . ' s'
            . ' LEFT JOIN ' . EvidenceSchema::TABLE_PROVIDER_SEGMENTS . ' ps ON ps.id = s.primary_provider_segment_id'
            . ' WHERE s.processing_run_id = ? ORDER BY s.start_time_ms ASC, s.id ASC'
        );
        $stmt->execute(array($processingRunId));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    /**
     * Fill missing provider_segment_text from provider segments table when JOIN did not populate it.
     *
     * @param list<array<string,mixed>> $segments
     * @return list<array<string,mixed>>
     */
    public function enrichProviderText(array $segments): array
    {
        if ($segments === array()) {
            return array();
        }

        $missingProviderSegmentIds = array();
        foreach ($segments as $segment) {
            if (trim((string)($segment['provider_segment_text'] ?? '')) !== '') {
                continue;
            }
            $providerSegmentId = (int)($segment['primary_provider_segment_id'] ?? 0);
            if ($providerSegmentId > 0) {
                $missingProviderSegmentIds[$providerSegmentId] = $providerSegmentId;
            }
        }

        $providerTextById = array();
        if ($missingProviderSegmentIds !== array() && EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_PROVIDER_SEGMENTS)) {
            $placeholders = implode(',', array_fill(0, count($missingProviderSegmentIds), '?'));
            $stmt = $this->pdo->prepare(
                'SELECT id, text FROM ' . EvidenceSchema::TABLE_PROVIDER_SEGMENTS . ' WHERE id IN (' . $placeholders . ')'
            );
            $stmt->execute(array_values($missingProviderSegmentIds));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row) {
                $text = trim((string)($row['text'] ?? ''));
                if ($text !== '') {
                    $providerTextById[(int)($row['id'] ?? 0)] = $text;
                }
            }
        }

        $out = array();
        foreach ($segments as $segment) {
            $text = trim((string)($segment['provider_segment_text'] ?? ''));
            if ($text === '') {
                $providerSegmentId = (int)($segment['primary_provider_segment_id'] ?? 0);
                if ($providerSegmentId > 0 && isset($providerTextById[$providerSegmentId])) {
                    $segment['provider_segment_text'] = $providerTextById[$providerSegmentId];
                }
            }
            $out[] = $segment;
        }

        return $out;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findById(int $id): ?array
    {
        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_SPEECH_SEGMENTS)) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM ' . EvidenceSchema::TABLE_SPEECH_SEGMENTS . ' WHERE id = ? LIMIT 1');
        $stmt->execute(array($id));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}
