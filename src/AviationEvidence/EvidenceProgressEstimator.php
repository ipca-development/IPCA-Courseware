<?php
declare(strict_types=1);

require_once __DIR__ . '/EvidenceSchema.php';
require_once __DIR__ . '/ProviderRunRepository.php';
require_once __DIR__ . '/SpeechSegmentRepository.php';
require_once __DIR__ . '/InterpretationRevisionRepository.php';
require_once __DIR__ . '/DisplayBlockRepository.php';
require_once __DIR__ . '/ChapterRepository.php';
require_once __DIR__ . '/../CockpitRecorderService.php';

/**
 * Estimates Pass 4 / Pass 5 evidence progress and time remaining for UI polling.
 */
final class EvidenceProgressEstimator
{
    /** @var array<string,int> */
    private const STEP_FLOOR = array(
        'queued' => 0,
        'persisting' => 4,
        'pass_4' => 52,
        'pass_5' => 76,
        'finishing' => 93,
    );

    /** @var array<string,int> */
    private const STEP_CEILING = array(
        'queued' => 4,
        'persisting' => 52,
        'pass_4' => 76,
        'pass_5' => 93,
        'finishing' => 99,
    );

    public function __construct(
        private readonly PDO $pdo,
        private readonly CockpitRecorderService $recorder,
        private readonly ProviderRunRepository $providerRuns,
        private readonly SpeechSegmentRepository $speechSegments,
        private readonly InterpretationRevisionRepository $interpretations,
        private readonly DisplayBlockRepository $displayBlocks,
        private readonly ChapterRepository $chapters,
    ) {
    }

    /**
     * @param array<string,mixed> $recording
     * @param array<string,mixed>|null $runningRun
     * @return array{
     *   evidence_progress:int,
     *   evidence_estimated_total_seconds:int,
     *   evidence_elapsed_seconds:int,
     *   evidence_estimated_remaining_seconds:int
     * }
     */
    public function estimate(array $recording, string $step, ?array $runningRun): array
    {
        $durationSeconds = max(0.0, (float)($recording['duration_seconds'] ?? 0));
        $estimatedTotal = $this->estimatedTotalSeconds($durationSeconds);
        $elapsed = $this->elapsedSeconds($recording, $runningRun);
        $subProgress = $this->subProgressForStep($recording, $step, $runningRun);

        $floor = self::STEP_FLOOR[$step] ?? 0;
        $ceiling = self::STEP_CEILING[$step] ?? 99;
        $progress = (int)round($floor + (($ceiling - $floor) * $subProgress));
        $progress = max(0, min(99, $progress));

        $remainingByProgress = (int)round($estimatedTotal * (1.0 - ($progress / 100.0)));
        $remainingByElapsed = max(0, $estimatedTotal - $elapsed);
        $remaining = max(0, min($remainingByProgress, $remainingByElapsed));

        return array(
            'evidence_progress' => $progress,
            'evidence_estimated_total_seconds' => $estimatedTotal,
            'evidence_elapsed_seconds' => $elapsed,
            'evidence_estimated_remaining_seconds' => $remaining,
        );
    }

    private function estimatedTotalSeconds(float $durationSeconds): int
    {
        $minutes = max(0.5, $durationSeconds / 60.0);
        // Whisper-first: ASR already done; evidence is persist + Pass 4 + Pass 5.
        $estimate = 35.0 + ($minutes * 4.5) + min(180.0, $minutes * 1.2);
        return (int)max(40, min(900, round($estimate)));
    }

    /**
     * @param array<string,mixed> $recording
     * @param array<string,mixed>|null $runningRun
     */
    private function elapsedSeconds(array $recording, ?array $runningRun): int
    {
        $startedAt = null;
        if (is_array($runningRun)) {
            $startedAt = strtotime((string)($runningRun['created_at'] ?? ''));
        }
        if ($startedAt === false || $startedAt === null) {
            $startedAt = strtotime((string)($recording['transcription_completed_at'] ?? ''));
        }
        if ($startedAt === false || $startedAt === null) {
            $startedAt = strtotime((string)($recording['updated_at'] ?? ''));
        }
        if ($startedAt === false || $startedAt === null) {
            return 0;
        }
        return max(0, time() - $startedAt);
    }

    /**
     * @param array<string,mixed> $recording
     * @param array<string,mixed>|null $runningRun
     */
    private function subProgressForStep(array $recording, string $step, ?array $runningRun): float
    {
        $processingRunId = is_array($runningRun) ? (int)($runningRun['id'] ?? 0) : 0;

        return match ($step) {
            'queued' => 0.15,
            'persisting' => $this->persistingSubProgress($recording, $processingRunId),
            'pass_4' => $this->pass4SubProgress($processingRunId),
            'pass_5' => $this->pass5SubProgress($processingRunId),
            'finishing' => 0.85,
            default => 0.0,
        };
    }

    private function persistingSubProgress(array $recording, int $processingRunId): float
    {
        if ($processingRunId <= 0) {
            return 0.1;
        }

        $recordingId = (int)($recording['id'] ?? 0);
        $expectedChunks = count($this->recorder->listLegacyTranscriptionChunks($recordingId));
        if ($expectedChunks <= 0) {
            $expectedChunks = 1;
        }

        $completedChunks = count($this->providerRuns->listCanonicalWhisperRunsForProcessingRun($processingRunId));
        if ($completedChunks <= 0) {
            $completedChunks = count($this->speechSegments->listForProcessingRun($processingRunId)) > 0 ? 1 : 0;
        }

        return max(0.05, min(1.0, $completedChunks / $expectedChunks));
    }

    private function pass4SubProgress(int $processingRunId): float
    {
        if ($processingRunId <= 0 || !EvidenceSchema::pass4Ready($this->pdo)) {
            return 0.1;
        }

        $hasPass4b = $this->interpretations->findLatestForRunByLayer($processingRunId, EvidenceSchema::LAYER_PASS4B) !== null;
        if ($hasPass4b) {
            return 1.0;
        }

        $hasPass4a = $this->interpretations->findLatestForRunByLayer($processingRunId, EvidenceSchema::LAYER_PASS4A) !== null;
        if ($hasPass4a) {
            return 0.55;
        }

        $segmentCount = count($this->speechSegments->listForProcessingRun($processingRunId));
        return $segmentCount > 0 ? 0.25 : 0.1;
    }

    private function pass5SubProgress(int $processingRunId): float
    {
        if ($processingRunId <= 0 || !EvidenceSchema::pass5Ready($this->pdo)) {
            return 0.1;
        }

        $blocks = $this->displayBlocks->listForProcessingRun($processingRunId);
        $chapters = $this->chapters->listForProcessingRun($processingRunId);
        if ($blocks !== array() && $chapters !== array()) {
            return 1.0;
        }
        if ($blocks !== array()) {
            return 0.65;
        }
        return 0.2;
    }

    public static function fromPdo(PDO $pdo): self
    {
        return new self(
            $pdo,
            new CockpitRecorderService($pdo),
            new ProviderRunRepository($pdo),
            new SpeechSegmentRepository($pdo),
            new InterpretationRevisionRepository($pdo),
            new DisplayBlockRepository($pdo),
            new ChapterRepository($pdo),
        );
    }
}
