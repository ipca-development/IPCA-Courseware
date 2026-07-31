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
require_once __DIR__ . '/GibberishSegmentDetectorService.php';
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
        private readonly GibberishSegmentDetectorService $gibberish = new GibberishSegmentDetectorService(),
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
        // Only read finalized, publishable evidence here. A transcript GET must never
        // materialize or analyze a partially written processing run.
        $processingRunId = (int)($pipeline['latest_publishable_processing_run_id'] ?? 0);
        $publishedVersion = $this->resolvePublishedVersion($recording);
        $snapshot = is_array($publishedVersion) ? $this->decodeSnapshot($publishedVersion) : null;

        if ($processingRunId <= 0 && is_array($snapshot)) {
            $processingRunId = (int)($snapshot['processing_run_id'] ?? 0);
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
        $evidenceQueue = CockpitRecorderEvidenceQueueService::fromPdo($this->pdo);
        $public = $evidenceQueue->publicStatusForRecording($recording);

        return array(
            'stage' => (string)($public['pipeline_stage'] ?? 'legacy'),
            'evidence_ready' => EvidenceSchema::persistenceReady($this->pdo),
            'pass4_ready' => EvidenceSchema::pass4Ready($this->pdo),
            'pass5_ready' => EvidenceSchema::pass5Ready($this->pdo),
            'publish_ready' => EvidenceSchema::publishReady($this->pdo),
            'publishable' => !empty($public['publishable']),
            'evidence_in_progress' => !empty($public['evidence_in_progress']),
            'needs_evidence_processing' => !empty($public['needs_evidence_processing']),
            'evidence_step' => $public['evidence_step'] ?? null,
            'evidence_step_label' => $public['evidence_step_label'] ?? null,
            'evidence_progress' => $public['evidence_progress'] ?? null,
            'evidence_elapsed_seconds' => $public['evidence_elapsed_seconds'] ?? null,
            'evidence_estimated_remaining_seconds' => $public['evidence_estimated_remaining_seconds'] ?? null,
            'evidence_worker_failed' => !empty($public['evidence_worker_failed']),
            'evidence_worker_failure_reason' => $public['evidence_worker_failure_reason'] ?? null,
            'evidence_worker_failure_code' => $public['evidence_worker_failure_code'] ?? null,
            'evidence_worker_failure_detail' => $public['evidence_worker_failure_detail'] ?? null,
            'evidence_worker_log_excerpt' => $public['evidence_worker_log_excerpt'] ?? null,
            'can_retry_evidence' => !empty($public['can_retry_evidence']),
            'running_processing_run_id' => $public['running_processing_run_id'] ?? null,
            'latest_publishable_processing_run_id' => $public['latest_publishable_processing_run_id'] ?? null,
            'active_processing_run_id' => $public['latest_publishable_processing_run_id']
                ?? ((int)($recording['current_processing_run_id'] ?? 0) > 0
                    ? (int)$recording['current_processing_run_id']
                    : ($public['running_processing_run_id'] ?? null)),
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
        $rows = $this->displayBlocks->listForProcessingRun($processingRunId);
        if ($rows !== array()) {
            $formatted = $this->formatBlockRows($rows, $suppressedSegmentIds);
            if ($formatted !== array()) {
                return $formatted;
            }
        }

        if (is_array($snapshot) && is_array($snapshot['display_blocks'] ?? null) && $snapshot['display_blocks'] !== array()) {
            $fromSnapshot = $this->normalizeBlocksForView($snapshot['display_blocks']);
            if ($fromSnapshot !== array()) {
                return $fromSnapshot;
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
                    if ($text !== '' && !$this->gibberish->isGibberish($text)) {
                        $textParts[] = $text;
                    }
                }
            }
            $joined = trim(implode(' ', $textParts));
            if ($joined === '' || $this->gibberish->isGibberish($joined)) {
                continue;
            }
            $out[] = array(
                'id' => (int)($row['id'] ?? 0),
                'start_time_ms' => (int)($row['start_time_ms'] ?? 0),
                'end_time_ms' => (int)($row['end_time_ms'] ?? 0),
                'text' => $joined,
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

    private function ensureGibberishSuppressions(int $recordingId, int $processingRunId): void
    {
        if ($recordingId <= 0 || $processingRunId <= 0 || !EvidenceSchema::pass4Ready($this->pdo)) {
            return;
        }

        $suppressedIds = array_flip($this->suppressedSegmentIds($processingRunId));
        $speechSegments = $this->enrichSpeechSegments($this->speechSegments->listForProcessingRun($processingRunId));
        if ($speechSegments === array()) {
            return;
        }

        $created = false;
        foreach ($speechSegments as $segment) {
            $segmentId = (int)($segment['id'] ?? 0);
            if ($segmentId <= 0 || isset($suppressedIds[$segmentId])) {
                continue;
            }
            $text = trim((string)($segment['provider_segment_text'] ?? ''));
            $gibberish = $this->gibberish->analyze($text);
            if ($gibberish === null || !$this->gibberish->shouldSuppressConfidence(
                (float)($gibberish['confidence'] ?? 0),
                $gibberish['signals']
            )) {
                continue;
            }
            $this->suppressions->create(
                $processingRunId,
                'gibberish_hallucination',
                'Pass 4A backfill: ' . implode(', ', $gibberish['signals']),
                $segmentId,
                null,
                (string)($gibberish['text_preview'] ?? $text)
            );
            $suppressedIds[$segmentId] = true;
            $created = true;
        }

        if ($created && EvidenceSchema::pass5Ready($this->pdo)) {
            try {
                EvidencePass5Runner::fromPdo($this->pdo)->runForProcessingRun($processingRunId, true);
            } catch (Throwable $e) {
                error_log('[TranscriptReview] Gibberish backfill Pass 5 recording ' . $recordingId . ': ' . $e->getMessage());
            }
            return;
        }
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
            if ($text === '' || $this->gibberish->isGibberish($text)) {
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
        $segmentStartMsById = array();
        foreach ($speechSegments as $segment) {
            $segmentId = (int)($segment['id'] ?? 0);
            if ($segmentId > 0) {
                $segmentStartMsById[$segmentId] = (int)($segment['start_time_ms'] ?? 0);
            }
        }

        return array(
            'speech_segment_count' => count($speechSegments),
            'suppressed_segment_count' => count($suppressedSegmentIds),
            'suppression_count' => count($suppressionRows),
            'pass_4a' => $pass4a['chunk_summary'] ?? array(),
            'pass_4a_flagged_count' => count($pass4a['findings'] ?? array()),
            'pass_4b_finding_count' => count($pass4bFindings),
            'pass_4a_findings_preview' => $this->formatPass4aFindingsPreview(
                array_slice($pass4a['findings'] ?? array(), 0, 8)
            ),
            'pass_4b_findings_preview' => $this->formatPass4bFindingsPreview(
                array_slice($pass4bFindings, 0, 8),
                $segmentStartMsById
            ),
        );
    }

    /**
     * @param list<array<string,mixed>> $findings
     * @return list<array<string,mixed>>
     */
    private function formatPass4aFindingsPreview(array $findings): array
    {
        $out = array();
        foreach ($findings as $finding) {
            $signals = is_array($finding['signals'] ?? null) ? $finding['signals'] : array();
            $label = $signals !== array()
                ? implode(', ', array_map(array($this, 'humanizeSignal'), $signals))
                : 'Speech quality flag';
            $out[] = array(
                'kind' => 'pass_4a',
                'label' => $label,
                'text_preview' => (string)($finding['text_preview'] ?? ''),
                'start_time_ms' => (int)($finding['start_time_ms'] ?? 0),
                'confidence' => isset($finding['confidence']) ? (float)$finding['confidence'] : null,
                'speech_segment_id' => (int)($finding['speech_segment_id'] ?? 0),
            );
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $findings
     * @param array<int,int> $segmentStartMsById
     * @return list<array<string,mixed>>
     */
    private function formatPass4bFindingsPreview(array $findings, array $segmentStartMsById): array
    {
        $out = array();
        foreach ($findings as $finding) {
            $segmentIds = is_array($finding['speech_segment_ids'] ?? null) ? $finding['speech_segment_ids'] : array();
            $firstSegmentId = 0;
            foreach ($segmentIds as $segmentId) {
                $segmentId = (int)$segmentId;
                if ($segmentId > 0) {
                    $firstSegmentId = $segmentId;
                    break;
                }
            }
            $out[] = array(
                'kind' => 'pass_4b',
                'label' => $this->formatPass4bFindingLabel($finding),
                'detection_type' => (string)($finding['detection_type'] ?? ''),
                'text_preview' => $this->pass4bFindingPreviewText($finding),
                'start_time_ms' => $firstSegmentId > 0 ? (int)($segmentStartMsById[$firstSegmentId] ?? 0) : 0,
                'confidence' => isset($finding['confidence']) ? (float)$finding['confidence'] : null,
                'speech_segment_id' => $firstSegmentId > 0 ? $firstSegmentId : null,
                'occurrence_count' => isset($finding['occurrence_count']) ? (int)$finding['occurrence_count'] : null,
            );
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $finding
     */
    private function formatPass4bFindingLabel(array $finding): string
    {
        $type = (string)($finding['detection_type'] ?? 'finding');
        $count = (int)($finding['occurrence_count'] ?? $finding['cycle_count'] ?? $finding['repeat_count'] ?? 0);
        $countSuffix = $count > 0 ? (' x' . $count) : '';

        return match ($type) {
            'exact_repeated_phrase' => 'Repeated phrase' . $countSuffix . ': ' . substr((string)($finding['phrase'] ?? ''), 0, 80),
            'repeated_ngram' => 'Repeated n-gram' . $countSuffix . ': ' . substr((string)($finding['ngram'] ?? ''), 0, 80),
            'aa_loop' => 'A-A loop' . $countSuffix . ': ' . substr((string)($finding['phrase'] ?? ''), 0, 80),
            'ab_loop' => 'A-B loop' . $countSuffix . ': '
                . substr((string)($finding['phrase_a'] ?? ''), 0, 40)
                . ' / '
                . substr((string)($finding['phrase_b'] ?? ''), 0, 40),
            'phrase_cycle_loop' => 'Phrase cycle loop' . $countSuffix,
            'low_lexical_diversity' => 'Low lexical diversity',
            'abnormal_compression' => 'Abnormal text compression',
            'repeated_token_dominance' => 'Dominant repeated token: ' . (string)($finding['token'] ?? ''),
            'repetition_concentrated_near_chunk_end' => 'Repetition concentrated near chunk end',
            'secondary_hypothesis_repetition' => 'Secondary transcript repetition',
            default => str_replace('_', ' ', $type),
        };
    }

    /**
     * @param array<string,mixed> $finding
     */
    private function pass4bFindingPreviewText(array $finding): string
    {
        $type = (string)($finding['detection_type'] ?? '');
        if ($type === 'exact_repeated_phrase' || $type === 'aa_loop') {
            return substr((string)($finding['phrase'] ?? ''), 0, 120);
        }
        if ($type === 'repeated_ngram') {
            return substr((string)($finding['ngram'] ?? ''), 0, 120);
        }
        if ($type === 'secondary_hypothesis_repetition') {
            return substr((string)($finding['details']['secondary_text_preview'] ?? ''), 0, 120);
        }
        if ($type === 'repeated_token_dominance') {
            return (string)($finding['token'] ?? '');
        }
        return '';
    }

    private function humanizeSignal(string $signal): string
    {
        return str_replace('_', ' ', $signal);
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
