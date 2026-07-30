<?php
declare(strict_types=1);

require_once __DIR__ . '/EvidenceSchema.php';
require_once __DIR__ . '/SpeechSegmentRepository.php';
require_once __DIR__ . '/SuppressionRepository.php';
require_once __DIR__ . '/DisplayBlockRepository.php';
require_once __DIR__ . '/ChapterRepository.php';
require_once __DIR__ . '/DisplayBlockBuilderService.php';
require_once __DIR__ . '/ChapterBuilderService.php';
require_once __DIR__ . '/ProcessingRunRepository.php';

final class EvidencePass5Runner
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ProcessingRunRepository $processingRuns,
        private readonly SpeechSegmentRepository $speechSegments,
        private readonly SuppressionRepository $suppressions,
        private readonly DisplayBlockRepository $displayBlocks,
        private readonly ChapterRepository $chapters,
        private readonly DisplayBlockBuilderService $blockBuilder,
        private readonly ChapterBuilderService $chapterBuilder,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function runForProcessingRun(int $processingRunId, bool $force = false): array
    {
        if (!EvidenceSchema::pass5Ready($this->pdo)) {
            throw new RuntimeException('Pass 5 tables not ready.');
        }

        $processingRun = $this->processingRuns->findById($processingRunId);
        if ($processingRun === null) {
            throw new RuntimeException('Processing run not found: ' . $processingRunId);
        }

        $recordingId = (int)$processingRun['recording_id'];
        $existingBlocks = $this->displayBlocks->listForProcessingRun($processingRunId);
        if (!$force && $existingBlocks !== array()) {
            return array(
                'ok' => true,
                'skipped' => true,
                'reason' => 'Display blocks already exist',
                'processing_run_id' => $processingRunId,
                'display_block_count' => count($existingBlocks),
            );
        }

        $speechSegmentRows = $this->speechSegments->listForProcessingRun($processingRunId);
        if ($speechSegmentRows === array()) {
            throw new RuntimeException('No speech segments for processing run ' . $processingRunId);
        }

        $suppressedIds = array();
        foreach ($this->suppressions->listForProcessingRun($processingRunId) as $row) {
            $segmentId = (int)($row['speech_segment_id'] ?? 0);
            if ($segmentId > 0) {
                $suppressedIds[$segmentId] = $segmentId;
            }
        }
        $suppressedIds = array_values($suppressedIds);

        $visibleSegments = array();
        foreach ($speechSegmentRows as $segment) {
            $segmentId = (int)($segment['id'] ?? 0);
            if ($segmentId > 0 && !in_array($segmentId, $suppressedIds, true)) {
                $visibleSegments[] = $segment;
            }
        }

        $allBlocks = $this->blockBuilder->build($speechSegmentRows, $suppressedIds);
        $visibleBlocks = $this->blockBuilder->build($visibleSegments, array());
        $chapterSpecs = $this->chapterBuilder->build($visibleSegments);

        $persistedBlocks = $this->displayBlocks->replaceForProcessingRun($recordingId, $processingRunId, $allBlocks);
        $persistedChapters = $this->chapters->replaceForProcessingRun($recordingId, $processingRunId, $chapterSpecs);

        return array(
            'ok' => true,
            'skipped' => false,
            'processing_run_id' => $processingRunId,
            'recording_id' => $recordingId,
            'display_block_count' => count($persistedBlocks),
            'visible_block_count' => count($visibleBlocks),
            'chapter_count' => count($persistedChapters),
            'suppressed_segment_count' => count($suppressedIds),
            'speech_segment_count' => count($speechSegmentRows),
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
            new DisplayBlockBuilderService(),
            new ChapterBuilderService(),
        );
    }
}
