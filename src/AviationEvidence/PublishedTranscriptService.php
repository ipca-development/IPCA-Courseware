<?php
declare(strict_types=1);

require_once __DIR__ . '/EvidenceSchema.php';
require_once __DIR__ . '/ProcessingRunRepository.php';
require_once __DIR__ . '/InterpretationRevisionRepository.php';
require_once __DIR__ . '/SpeechSegmentRepository.php';
require_once __DIR__ . '/SuppressionRepository.php';
require_once __DIR__ . '/ProviderRunRepository.php';
require_once __DIR__ . '/PublishedTranscriptVersionRepository.php';

final class PublishedTranscriptService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ProcessingRunRepository $processingRuns,
        private readonly InterpretationRevisionRepository $interpretations,
        private readonly SpeechSegmentRepository $speechSegments,
        private readonly SuppressionRepository $suppressions,
        private readonly ProviderRunRepository $providerRuns,
        private readonly PublishedTranscriptVersionRepository $publishedVersions,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function publishProcessingRun(
        int $recordingId,
        int $processingRunId,
        ?int $publishedBy = null,
        bool $regenerateLegacyCache = true
    ): array {
        if (!EvidenceSchema::publishReady($this->pdo)) {
            throw new RuntimeException('Publish tables not ready. Apply Phase 1 migration first.');
        }

        $processingRun = $this->processingRuns->findById($processingRunId);
        if ($processingRun === null) {
            throw new RuntimeException('Processing run not found: ' . $processingRunId);
        }
        if ((int)($processingRun['recording_id'] ?? 0) !== $recordingId) {
            throw new RuntimeException('Processing run does not belong to recording ' . $recordingId);
        }

        $readable = $this->interpretations->findLatestReadableForProcessingRun($processingRunId);
        if ($readable === null) {
            throw new RuntimeException('No readable_primary interpretation for processing run ' . $processingRunId);
        }

        $readableText = trim((string)($readable['text'] ?? ''));
        if ($readableText === '') {
            throw new RuntimeException('Readable primary text is empty for processing run ' . $processingRunId);
        }

        $interpretationRevisionIds = $this->interpretations->listRevisionIdsForProcessingRun($processingRunId);
        $suppressionRows = $this->suppressions->listForProcessingRun($processingRunId);
        $suppressionIds = array_map(static fn(array $row): int => (int)$row['id'], $suppressionRows);
        $speechSegmentRows = $this->speechSegments->listForProcessingRun($processingRunId);
        $suppressedSegmentIds = $this->suppressedSegmentIdsFromRows($suppressionRows);
        $timeline = $this->buildTimeline($speechSegmentRows, $suppressedSegmentIds);
        $canonical = $this->providerRuns->findCanonicalForProcessingRun($processingRunId);

        $reasoning = json_decode((string)($readable['reasoning_json'] ?? ''), true);
        if (!is_array($reasoning)) {
            $reasoning = array();
        }

        $snapshot = array(
            'snapshot_version' => EvidenceSchema::PUBLISH_SNAPSHOT_VERSION,
            'recording_id' => $recordingId,
            'processing_run_id' => $processingRunId,
            'readable_interpretation_revision_id' => (int)($readable['id'] ?? 0),
            'readable_text' => $readableText,
            'speech_segment_count' => count($speechSegmentRows),
            'timeline_segment_count' => count($timeline),
            'suppression_count' => count($suppressionIds),
            'suppressed_segment_ids' => array_values($suppressedSegmentIds),
            'interpretation_revision_count' => count($interpretationRevisionIds),
            'speech_quality_version' => $processingRun['speech_quality_version'] ?? null,
            'semantic_validation_version' => $processingRun['semantic_validation_version'] ?? null,
            'canonical_provider_run_id' => is_array($canonical) ? (int)($canonical['id'] ?? 0) : null,
            'readable_reasoning' => $reasoning,
            'timeline' => $timeline,
        );

        $published = $this->publishedVersions->create(
            $recordingId,
            $processingRunId,
            $interpretationRevisionIds,
            $snapshot,
            $readableText,
            null,
            $publishedBy
        );

        $publishedId = (int)($published['id'] ?? 0);
        if ($publishedId <= 0) {
            throw new RuntimeException('Failed to create published transcript version.');
        }

        $this->attachPublishedVersionToRecording($recordingId, $processingRunId, $publishedId);

        $cacheResult = null;
        if ($regenerateLegacyCache) {
            $cacheResult = $this->regenerateLegacyCache($recordingId, $publishedId);
        }

        return array(
            'ok' => true,
            'recording_id' => $recordingId,
            'processing_run_id' => $processingRunId,
            'published_transcript_version_id' => $publishedId,
            'version_uuid' => (string)($published['version_uuid'] ?? ''),
            'published_at' => (string)($published['published_at'] ?? ''),
            'interpretation_revision_ids' => $interpretationRevisionIds,
            'suppression_ids' => $suppressionIds,
            'readable_text_preview' => substr($readableText, 0, 400),
            'legacy_cache' => $cacheResult,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function publishLatestForRecording(int $recordingId, ?int $publishedBy = null): array
    {
        $processingRun = $this->processingRuns->findLatestPublishableForRecording($recordingId);
        if ($processingRun === null) {
            throw new RuntimeException('No publishable processing run for recording ' . $recordingId);
        }
        return $this->publishProcessingRun(
            $recordingId,
            (int)$processingRun['id'],
            $publishedBy,
            true
        );
    }

    /**
     * @param array<string,mixed> $recording
     * @return array<string,mixed>|null
     */
    public function resolvePublishedForRecording(array $recording): ?array
    {
        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_PUBLISHED_VERSIONS)) {
            return null;
        }

        $publishedId = (int)($recording['published_transcript_version_id'] ?? 0);
        if ($publishedId <= 0) {
            return null;
        }

        $published = $this->publishedVersions->findById($publishedId);
        if ($published === null) {
            return null;
        }

        $text = trim((string)($published['legacy_transcript_cache_text'] ?? ''));
        if ($text === '') {
            $snapshot = json_decode((string)($published['snapshot_json'] ?? ''), true);
            if (is_array($snapshot)) {
                $text = trim((string)($snapshot['readable_text'] ?? ''));
            }
        }

        if ($text === '') {
            return null;
        }

        return array(
            'transcript' => $text,
            'transcript_source' => 'published_evidence',
            'published_transcript_version_id' => $publishedId,
            'published_version_uuid' => (string)($published['version_uuid'] ?? ''),
            'published_at' => (string)($published['published_at'] ?? ''),
            'processing_run_id' => (int)($published['processing_run_id'] ?? 0),
            'snapshot_version' => EvidenceSchema::PUBLISH_SNAPSHOT_VERSION,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function regenerateLegacyCache(int $recordingId, ?int $publishedVersionId = null): array
    {
        if ($publishedVersionId === null || $publishedVersionId <= 0) {
            $stmt = $this->pdo->prepare(
                'SELECT published_transcript_version_id FROM ipca_cockpit_recordings WHERE id = ? LIMIT 1'
            );
            $stmt->execute(array($recordingId));
            $publishedVersionId = (int)$stmt->fetchColumn();
        }
        if ($publishedVersionId <= 0) {
            throw new RuntimeException('No published transcript version linked to recording ' . $recordingId);
        }

        $published = $this->publishedVersions->findById($publishedVersionId);
        if ($published === null) {
            throw new RuntimeException('Published transcript version not found: ' . $publishedVersionId);
        }
        if ((int)($published['recording_id'] ?? 0) !== $recordingId) {
            throw new RuntimeException('Published version does not belong to recording ' . $recordingId);
        }

        $cacheText = trim((string)($published['legacy_transcript_cache_text'] ?? ''));
        if ($cacheText === '') {
            $snapshot = json_decode((string)($published['snapshot_json'] ?? ''), true);
            $cacheText = is_array($snapshot) ? trim((string)($snapshot['readable_text'] ?? '')) : '';
        }
        if ($cacheText === '') {
            throw new RuntimeException('Published snapshot has no readable text.');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE ipca_cockpit_recordings'
            . ' SET transcript_text = ?, published_transcript_version_id = ?, transcript_cache_generated_at = CURRENT_TIMESTAMP(3)'
            . ' WHERE id = ?'
        );
        $stmt->execute(array($cacheText, $publishedVersionId, $recordingId));

        return array(
            'ok' => true,
            'recording_id' => $recordingId,
            'published_transcript_version_id' => $publishedVersionId,
            'cache_text_length' => strlen($cacheText),
            'transcript_cache_generated_at' => gmdate('Y-m-d H:i:s'),
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listPublishedVersions(int $recordingId, int $limit = 20): array
    {
        return $this->publishedVersions->listForRecording($recordingId, $limit);
    }

    private function attachPublishedVersionToRecording(int $recordingId, int $processingRunId, int $publishedId): void
    {
        $sets = array(
            'published_transcript_version_id = ?',
        );
        $params = array($publishedId);

        if (EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_PROCESSING_RUNS)) {
            $col = $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS"
                . " WHERE TABLE_SCHEMA = DATABASE()"
                . " AND TABLE_NAME = 'ipca_cockpit_recordings'"
                . " AND COLUMN_NAME = 'current_processing_run_id'"
            )->fetchColumn();
            if ((int)$col > 0) {
                $sets[] = 'current_processing_run_id = ?';
                $params[] = $processingRunId;
            }
        }

        $params[] = $recordingId;
        $stmt = $this->pdo->prepare(
            'UPDATE ipca_cockpit_recordings SET ' . implode(', ', $sets) . ' WHERE id = ?'
        );
        $stmt->execute($params);
    }

    /**
     * @param list<array<string,mixed>> $suppressionRows
     * @return list<int>
     */
    private function suppressedSegmentIdsFromRows(array $suppressionRows): array
    {
        $ids = array();
        foreach ($suppressionRows as $row) {
            $segmentId = (int)($row['speech_segment_id'] ?? 0);
            if ($segmentId > 0) {
                $ids[$segmentId] = $segmentId;
            }
        }
        return array_values($ids);
    }

    /**
     * @param list<array<string,mixed>> $speechSegmentRows
     * @param list<int> $suppressedSegmentIds
     * @return list<array<string,mixed>>
     */
    private function buildTimeline(array $speechSegmentRows, array $suppressedSegmentIds): array
    {
        $timeline = array();
        foreach ($speechSegmentRows as $segment) {
            $segmentId = (int)($segment['id'] ?? 0);
            if ($segmentId <= 0 || in_array($segmentId, $suppressedSegmentIds, true)) {
                continue;
            }
            $text = trim((string)($segment['provider_segment_text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $timeline[] = array(
                'speech_segment_id' => $segmentId,
                'start_time_ms' => (int)($segment['start_time_ms'] ?? 0),
                'end_time_ms' => (int)($segment['end_time_ms'] ?? 0),
                'text' => $text,
            );
        }
        return $timeline;
    }

    public static function fromPdo(PDO $pdo): self
    {
        return new self(
            $pdo,
            new ProcessingRunRepository($pdo),
            new InterpretationRevisionRepository($pdo),
            new SpeechSegmentRepository($pdo),
            new SuppressionRepository($pdo),
            new ProviderRunRepository($pdo),
            new PublishedTranscriptVersionRepository($pdo),
        );
    }
}
