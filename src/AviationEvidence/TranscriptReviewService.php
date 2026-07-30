<?php
declare(strict_types=1);

require_once __DIR__ . '/EvidenceSchema.php';
require_once __DIR__ . '/ProcessingRunRepository.php';
require_once __DIR__ . '/SpeechSegmentRepository.php';
require_once __DIR__ . '/SuppressionRepository.php';
require_once __DIR__ . '/DisplayBlockRepository.php';
require_once __DIR__ . '/ChapterRepository.php';
require_once __DIR__ . '/ProviderRunRepository.php';
require_once __DIR__ . '/InterpretationRevisionRepository.php';
require_once __DIR__ . '/PublishedTranscriptVersionRepository.php';
require_once __DIR__ . '/Pass4aSpeechQualityService.php';
require_once __DIR__ . '/Pass4bRepetitionDetectorService.php';
require_once __DIR__ . '/DisplayBlockBuilderService.php';
require_once __DIR__ . '/ChapterBuilderService.php';
require_once __DIR__ . '/TerminologyCorrectionRepository.php';
require_once __DIR__ . '/EvidencePass5Runner.php';
require_once __DIR__ . '/../CockpitRecorderEvidenceQueueService.php';
require_once __DIR__ . '/../CockpitRecorderService.php';

final class TranscriptReviewService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ProcessingRunRepository $processingRuns,
        private readonly SpeechSegmentRepository $speechSegments,
        private readonly SuppressionRepository $suppressions,
        private readonly DisplayBlockRepository $displayBlocks,
        private readonly ChapterRepository $chapters,
        private readonly ProviderRunRepository $providerRuns,
        private readonly InterpretationRevisionRepository $interpretations,
        private readonly PublishedTranscriptVersionRepository $publishedVersions,
        private readonly TerminologyCorrectionRepository $terminologyCorrections,
        private readonly Pass4aSpeechQualityService $pass4a,
        private readonly DisplayBlockBuilderService $blockBuilder,
        private readonly ChapterBuilderService $chapterBuilder,
    ) {
    }

    /**
     * @param array<string,mixed> $recording
     * @return array<string,mixed>
     */
    public function buildReviewPayload(array $recording, ?string $preferredLayer = null): array
    {
        $recordingId = (int)($recording['id'] ?? 0);
        $hasAudio = trim((string)($recording['storage_path'] ?? '')) !== '';

        $pipeline = $this->pipelineStatus($recording);
        $processingRunId = (int)($pipeline['active_processing_run_id'] ?? 0);
        $publishedVersion = $this->resolvePublishedVersion($recording);
        $snapshot = is_array($publishedVersion) ? $this->decodeSnapshot($publishedVersion) : null;

        if ($processingRunId <= 0 && is_array($snapshot)) {
            $processingRunId = (int)($snapshot['processing_run_id'] ?? 0);
        }

        if ($processingRunId > 0) {
            $this->ensureDisplayMaterialized($recordingId, $processingRunId);
        }

        $blocks = array();
        $chapters = array();
        $quality = null;
        $layers = $this->buildLayers($recording, $processingRunId, $snapshot);
        $suppressedSegmentIds = array();

        if ($processingRunId > 0) {
            $suppressedSegmentIds = $this->suppressedSegmentIds($processingRunId);
            $blocks = $this->resolveBlocks($recordingId, $processingRunId, $snapshot, $suppressedSegmentIds);
            $chapters = $this->resolveChapters($recordingId, $processingRunId, $snapshot);
            $quality = $this->buildQualitySummary($processingRunId, $suppressedSegmentIds);
        }

        $activeLayer = $preferredLayer ?? ($publishedVersion !== null ? 'published' : ($blocks !== array() ? 'readable' : 'legacy'));
        if (!isset($layers[$activeLayer])) {
            $activeLayer = isset($layers['readable']) ? 'readable' : (isset($layers['legacy']) ? 'legacy' : 'published');
        }

        $corrections = EvidenceSchema::terminologyReady($this->pdo)
            ? $this->terminologyCorrections->listForRecording($recordingId)
            : array();

        return array(
            'ok' => true,
            'recording_id' => $recordingId,
            'recording_uid' => (string)($recording['recording_uid'] ?? ''),
            'aircraft_registration' => (string)($recording['aircraft_registration'] ?? $recording['aircraft_ident'] ?? ''),
            'transcription_status' => (string)($recording['transcription_status'] ?? ''),
            'transcription_progress' => (int)($recording['transcription_progress'] ?? 0),
            'duration_seconds' => (float)($recording['duration_seconds'] ?? 0),
            'audio_url' => $hasAudio ? ('/admin/cockpit_recorder_audio.php?id=' . rawurlencode((string)$recordingId)) : null,
            'pipeline' => $pipeline,
            'published' => $publishedVersion !== null ? array(
                'published_transcript_version_id' => (int)($publishedVersion['id'] ?? 0),
                'published_version_uuid' => (string)($publishedVersion['version_uuid'] ?? ''),
                'published_at' => (string)($publishedVersion['published_at'] ?? ''),
            ) : null,
            'processing_run_id' => $processingRunId,
            'layers' => $layers,
            'active_layer' => $activeLayer,
            'blocks' => $blocks,
            'chapters' => $chapters,
            'quality' => $quality,
            'terminology_corrections' => array_map(static fn(array $row): array => array(
                'correction_uuid' => (string)($row['correction_uuid'] ?? ''),
                'raw_text' => (string)($row['raw_text'] ?? ''),
                'corrected_text' => (string)($row['corrected_text'] ?? ''),
                'status' => (string)($row['status'] ?? ''),
                'speech_segment_id' => isset($row['speech_segment_id']) ? (int)$row['speech_segment_id'] : null,
                'start_time_ms' => isset($row['start_time_ms']) ? (int)$row['start_time_ms'] : null,
                'end_time_ms' => isset($row['end_time_ms']) ? (int)$row['end_time_ms'] : null,
            ), $corrections),
            'view_mode' => $blocks !== array() ? 'structured' : 'legacy',
            'legacy_text' => trim((string)($recording['transcript_text'] ?? '')),
            'block_count' => count($blocks),
            'chapter_count' => count($chapters),
        );
    }

    /**
     * @param array<string,mixed> $recording
     * @return array<string,mixed>
     */
    private function pipelineStatus(array $recording): array
    {
        $recordingId = (int)($recording['id'] ?? 0);
        $status = strtolower(trim((string)($recording['transcription_status'] ?? '')));
        $publishableRun = $this->processingRuns->findLatestPublishableForRecording($recordingId);
        $currentRunId = (int)($recording['current_processing_run_id'] ?? 0);
        if ($currentRunId <= 0 && is_array($publishableRun)) {
            $currentRunId = (int)($publishableRun['id'] ?? 0);
        }

        $evidenceReady = EvidenceSchema::persistenceReady($this->pdo);
        $pass4Ready = EvidenceSchema::pass4Ready($this->pdo);
        $pass5Ready = EvidenceSchema::pass5Ready($this->pdo);
        $publishReady = EvidenceSchema::publishReady($this->pdo);
        $evidenceQueue = CockpitRecorderEvidenceQueueService::fromPdo($this->pdo);
        $runningRun = $this->processingRuns->findRunningForRecording($recordingId);
        $evidenceInProgress = $evidenceQueue->isEvidenceInProgress($recordingId)
            || ($status === 'ready' && is_array($runningRun));
        $needsEvidence = $status === 'ready' && $publishableRun === null && $evidenceQueue->needsEvidenceProcessing($recording);

        $stage = 'legacy';
        if ($status === 'queued' || $status === 'transcribing' || $status === 'pending') {
            $stage = 'transcribing';
        } elseif ($needsEvidence && ($evidenceInProgress || $evidenceReady)) {
            $stage = 'processing_evidence';
        } elseif ($currentRunId > 0 && is_array($publishableRun)) {
            $stage = (int)($recording['published_transcript_version_id'] ?? 0) > 0 ? 'published' : 'publishable';
        } elseif ($currentRunId > 0) {
            $stage = 'evidence';
        } elseif ($status === 'ready') {
            $stage = 'transcribed';
        }

        return array(
            'stage' => $stage,
            'evidence_ready' => $evidenceReady,
            'pass4_ready' => $pass4Ready,
            'pass5_ready' => $pass5Ready,
            'publish_ready' => $publishReady,
            'publishable' => $publishableRun !== null,
            'evidence_in_progress' => $evidenceInProgress,
            'needs_evidence_processing' => $needsEvidence,
            'running_processing_run_id' => is_array($runningRun) ? (int)($runningRun['id'] ?? 0) : null,
            'latest_publishable_processing_run_id' => is_array($publishableRun) ? (int)($publishableRun['id'] ?? 0) : null,
            'active_processing_run_id' => $currentRunId > 0 ? $currentRunId : null,
            'published_transcript_version_id' => (int)($recording['published_transcript_version_id'] ?? 0),
        );
    }

    /**
     * @param array<string,mixed> $recording
     * @return array<string,mixed>|null
     */
    private function resolvePublishedVersion(array $recording): ?array
    {
        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_PUBLISHED_VERSIONS)) {
            return null;
        }
        $publishedId = (int)($recording['published_transcript_version_id'] ?? 0);
        if ($publishedId <= 0) {
            return null;
        }
        return $this->publishedVersions->findById($publishedId);
    }

    /**
     * @param array<string,mixed>|null $publishedVersion
     * @return array<string,mixed>|null
     */
    private function decodeSnapshot(?array $publishedVersion): ?array
    {
        if ($publishedVersion === null) {
            return null;
        }
        $snapshot = json_decode((string)($publishedVersion['snapshot_json'] ?? ''), true);
        return is_array($snapshot) ? $snapshot : null;
    }

    /**
     * @param array<string,mixed>|null $snapshot
     * @return array<string,string>
     */
    private function buildLayers(array $recording, int $processingRunId, ?array $snapshot): array
    {
        $layers = array(
            'legacy' => trim((string)($recording['transcript_text'] ?? '')),
        );

        if ($processingRunId > 0) {
            $readable = $this->interpretations->findLatestReadableForProcessingRun($processingRunId);
            if (is_array($readable)) {
                $layers['readable'] = trim((string)($readable['text'] ?? ''));
            }

            $productionText = $this->providerRuns->mergedProductionTextForProcessingRun($processingRunId);
            if ($productionText !== null && $productionText !== '') {
                $layers['production'] = $productionText;
            }

            $whisperParts = array();
            foreach ($this->providerRuns->listCanonicalWhisperRunsForProcessingRun($processingRunId) as $run) {
                foreach ($this->providerRuns->listSegments((int)$run['id']) as $segment) {
                    $text = trim((string)($segment['text'] ?? ''));
                    if ($text !== '') {
                        $whisperParts[] = $text;
                    }
                }
            }
            if ($whisperParts !== array()) {
                $layers['whisper'] = trim(implode(' ', $whisperParts));
            }
        }

        if (is_array($snapshot) && trim((string)($snapshot['readable_text'] ?? '')) !== '') {
            $layers['published'] = trim((string)$snapshot['readable_text']);
        }

        return array_filter($layers, static fn(string $text): bool => $text !== '');
    }

    /**
     * @return list<int>
     */
    private function suppressedSegmentIds(int $processingRunId): array
    {
        $ids = array();
        foreach ($this->suppressions->listForProcessingRun($processingRunId) as $row) {
            $segmentId = (int)($row['speech_segment_id'] ?? 0);
            if ($segmentId > 0) {
                $ids[$segmentId] = $segmentId;
            }
        }
        return array_values($ids);
    }

    /**
     * @param list<int> $suppressedSegmentIds
     * @param array<string,mixed>|null $snapshot
     * @return list<array<string,mixed>>
     */
    private function resolveBlocks(
        int $recordingId,
        int $processingRunId,
        ?array $snapshot,
        array $suppressedSegmentIds
    ): array {
        if (is_array($snapshot) && is_array($snapshot['display_blocks'] ?? null) && $snapshot['display_blocks'] !== array()) {
            $fromSnapshot = $this->normalizeBlocksForView($snapshot['display_blocks']);
            if ($fromSnapshot !== array()) {
                return $fromSnapshot;
            }
        }

        $rows = $this->displayBlocks->listForProcessingRun($processingRunId);
        if ($rows !== array()) {
            $formatted = $this->formatBlockRows($rows, $suppressedSegmentIds);
            if ($formatted !== array()) {
                return $formatted;
            }
        }

        $speechSegments = $this->enrichSpeechSegments($this->speechSegments->listForProcessingRun($processingRunId));
        if ($speechSegments === array()) {
            return $this->blocksFromTimeline($snapshot, $suppressedSegmentIds);
        }

        $built = $this->blockBuilder->build($speechSegments, $suppressedSegmentIds);
        if ($built === array()) {
            return $this->blocksFromTimeline($snapshot, $suppressedSegmentIds);
        }

        if (EvidenceSchema::pass5Ready($this->pdo)) {
            $rows = $this->displayBlocks->replaceForProcessingRun($recordingId, $processingRunId, $built);
            $formatted = $this->formatBlockRows($rows, $suppressedSegmentIds);
            if ($formatted !== array()) {
                return $formatted;
            }
        }

        return $this->normalizeBlocksForView($built);
    }

    /**
     * @param array<string,mixed>|null $snapshot
     * @return list<array<string,mixed>>
     */
    private function resolveChapters(int $recordingId, int $processingRunId, ?array $snapshot): array
    {
        if (is_array($snapshot) && is_array($snapshot['chapters'] ?? null) && $snapshot['chapters'] !== array()) {
            return $snapshot['chapters'];
        }

        $rows = $this->chapters->listForProcessingRun($processingRunId);
        if ($rows === array() && EvidenceSchema::pass5Ready($this->pdo)) {
            $speechSegments = $this->speechSegments->listForProcessingRun($processingRunId);
            $suppressedIds = $this->suppressedSegmentIds($processingRunId);
            $visibleSegments = array();
            foreach ($speechSegments as $segment) {
                $segmentId = (int)($segment['id'] ?? 0);
                if ($segmentId > 0 && !in_array($segmentId, $suppressedIds, true)) {
                    $visibleSegments[] = $segment;
                }
            }
            if ($visibleSegments !== array()) {
                $built = $this->chapterBuilder->build($visibleSegments);
                $rows = $this->chapters->replaceForProcessingRun($recordingId, $processingRunId, $built);
            }
        }

        return array_map(static function (array $row): array {
            $segmentIds = json_decode((string)($row['supporting_segment_ids_json'] ?? '[]'), true);
            return array(
                'id' => (int)($row['id'] ?? 0),
                'title' => (string)($row['title'] ?? ''),
                'category' => (string)($row['category'] ?? ''),
                'start_time_ms' => (int)($row['start_time_ms'] ?? 0),
                'end_time_ms' => (int)($row['end_time_ms'] ?? 0),
                'confidence' => isset($row['calculated_confidence']) ? (float)$row['calculated_confidence'] : null,
                'supporting_segment_ids' => is_array($segmentIds) ? $segmentIds : array(),
            );
        }, $rows);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param list<int> $suppressedSegmentIds
     * @return list<array<string,mixed>>
     */
    private function formatBlockRows(array $rows, array $suppressedSegmentIds): array
    {
        $segmentsById = array();
        if ($rows !== array()) {
            $processingRunId = (int)($rows[0]['processing_run_id'] ?? 0);
            if ($processingRunId > 0) {
                foreach ($this->enrichSpeechSegments($this->speechSegments->listForProcessingRun($processingRunId)) as $segment) {
                    $segmentsById[(int)($segment['id'] ?? 0)] = $segment;
                }
            }
        }

        $out = array();
        foreach ($rows as $row) {
            $segmentIds = json_decode((string)($row['speech_segment_ids_json'] ?? '[]'), true);
            if (!is_array($segmentIds)) {
                $segmentIds = array();
            }
            $textParts = array();
            $suppressed = false;
            foreach ($segmentIds as $segmentId) {
                $segmentId = (int)$segmentId;
                if ($segmentId <= 0) {
                    continue;
                }
                if (in_array($segmentId, $suppressedSegmentIds, true)) {
                    $suppressed = true;
                    continue;
                }
                $segment = $segmentsById[$segmentId] ?? null;
                if (is_array($segment)) {
                    $text = trim((string)($segment['provider_segment_text'] ?? ''));
                    if ($text !== '') {
                        $textParts[] = $text;
                    }
                }
            }
            $out[] = array(
                'id' => (int)($row['id'] ?? 0),
                'start_time_ms' => (int)($row['start_time_ms'] ?? 0),
                'end_time_ms' => (int)($row['end_time_ms'] ?? 0),
                'text' => trim(implode(' ', $textParts)),
                'speech_segment_ids' => $segmentIds,
                'suppressed' => $suppressed,
            );
        }
        return array_values(array_filter($out, static fn(array $b): bool => trim((string)($b['text'] ?? '')) !== ''));
    }

    /**
     * @param array<string,mixed>|null $snapshot
     * @param list<int> $suppressedSegmentIds
     * @return list<array<string,mixed>>
     */
    private function blocksFromTimeline(?array $snapshot, array $suppressedSegmentIds): array
    {
        if (!is_array($snapshot) || !is_array($snapshot['timeline'] ?? null)) {
            return array();
        }

        $segments = array();
        foreach ($snapshot['timeline'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $segments[] = array(
                'id' => (int)($item['speech_segment_id'] ?? 0),
                'start_time_ms' => (int)($item['start_time_ms'] ?? 0),
                'end_time_ms' => (int)($item['end_time_ms'] ?? 0),
                'provider_segment_text' => (string)($item['text'] ?? ''),
            );
        }
        return $this->blockBuilder->build($segments, $suppressedSegmentIds);
    }

    private function ensureDisplayMaterialized(int $recordingId, int $processingRunId): void
    {
        if ($recordingId <= 0 || $processingRunId <= 0) {
            return;
        }

        $suppressedSegmentIds = $this->suppressedSegmentIds($processingRunId);
        $existingRows = $this->displayBlocks->listForProcessingRun($processingRunId);
        if ($existingRows !== array()) {
            $formatted = $this->formatBlockRows($existingRows, $suppressedSegmentIds);
            if ($formatted !== array()) {
                return;
            }
        }

        if (!EvidenceSchema::pass5Ready($this->pdo)) {
            return;
        }

        $speechSegments = $this->speechSegments->listForProcessingRun($processingRunId);
        if ($speechSegments === array()) {
            return;
        }

        try {
            EvidencePass5Runner::fromPdo($this->pdo)->runForProcessingRun($processingRunId, $existingRows !== array());
        } catch (Throwable $e) {
            error_log('[TranscriptReview] Pass 5 backfill recording ' . $recordingId . ': ' . $e->getMessage());
        }
    }

    /**
     * @param list<array<string,mixed>> $segments
     * @return list<array<string,mixed>>
     */
    private function enrichSpeechSegments(array $segments): array
    {
        return $this->speechSegments->enrichProviderText($segments);
    }

    /**
     * @param list<array<string,mixed>> $blocks
     * @return list<array<string,mixed>>
     */
    private function normalizeBlocksForView(array $blocks): array
    {
        $out = array();
        $nextId = 1;
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $text = trim((string)($block['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $id = (int)($block['id'] ?? 0);
            if ($id <= 0) {
                $id = $nextId;
            }
            $nextId = max($nextId + 1, $id + 1);
            $segmentIds = $block['speech_segment_ids'] ?? array();
            if (!is_array($segmentIds)) {
                $segmentIds = array();
            }
            $out[] = array(
                'id' => $id,
                'start_time_ms' => (int)($block['start_time_ms'] ?? 0),
                'end_time_ms' => (int)($block['end_time_ms'] ?? 0),
                'text' => $text,
                'speech_segment_ids' => array_values(array_map('intval', $segmentIds)),
                'suppressed' => !empty($block['suppressed']),
            );
        }
        return $out;
    }

    /**
     * @param list<int> $suppressedSegmentIds
     * @return array<string,mixed>|null
     */
    private function buildQualitySummary(int $processingRunId, array $suppressedSegmentIds): ?array
    {
        $speechSegments = $this->speechSegments->listForProcessingRun($processingRunId);
        if ($speechSegments === array()) {
            return null;
        }

        $pass4a = $this->pass4a->analyze($speechSegments);
        $pass4bRev = $this->interpretations->findLatestForRunByLayer($processingRunId, EvidenceSchema::LAYER_PASS4B);
        $pass4bFindings = array();
        if (is_array($pass4bRev)) {
            $decoded = json_decode((string)($pass4bRev['text'] ?? ''), true);
            if (is_array($decoded)) {
                $pass4bFindings = $decoded;
            }
        }

        $suppressionRows = $this->suppressions->listForProcessingRun($processingRunId);

        return array(
            'speech_segment_count' => count($speechSegments),
            'suppressed_segment_count' => count($suppressedSegmentIds),
            'suppression_count' => count($suppressionRows),
            'pass_4a' => $pass4a['chunk_summary'] ?? array(),
            'pass_4a_flagged_count' => count($pass4a['findings'] ?? array()),
            'pass_4b_finding_count' => count($pass4bFindings),
            'pass_4a_findings_preview' => array_slice($pass4a['findings'] ?? array(), 0, 8),
        );
    }

    public static function fromPdo(PDO $pdo): self
    {
        return new self(
            $pdo,
            new ProcessingRunRepository($pdo),
            new SpeechSegmentRepository($pdo),
            new SuppressionRepository($pdo),
            new DisplayBlockRepository($pdo),
            new ChapterRepository($pdo),
            new ProviderRunRepository($pdo),
            new InterpretationRevisionRepository($pdo),
            new PublishedTranscriptVersionRepository($pdo),
            new TerminologyCorrectionRepository($pdo),
            new Pass4aSpeechQualityService(),
            new DisplayBlockBuilderService(),
            new ChapterBuilderService(),
        );
    }
}
