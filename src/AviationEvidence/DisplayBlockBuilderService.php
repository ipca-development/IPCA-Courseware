<?php
declare(strict_types=1);

require_once __DIR__ . '/GibberishSegmentDetectorService.php';

/**
 * Groups speech segments into UI display blocks (timestamped transcript paragraphs).
 */
final class DisplayBlockBuilderService
{
    private const GAP_MS = 2500;
    private const MAX_BLOCK_MS = 90000;
    private const MAX_BLOCK_CHARS = 1200;

    public function __construct(
        private readonly GibberishSegmentDetectorService $gibberish = new GibberishSegmentDetectorService(),
    ) {
    }

    /**
     * @param list<array<string,mixed>> $speechSegments ordered by start_time_ms
     * @param list<int> $suppressedSegmentIds
     * @return list<array<string,mixed>>
     */
    public function build(array $speechSegments, array $suppressedSegmentIds = array()): array
    {
        $blocks = array();
        $current = null;

        foreach ($speechSegments as $segment) {
            $segmentId = (int)($segment['id'] ?? 0);
            $text = trim((string)($segment['provider_segment_text'] ?? ''));
            if ($segmentId <= 0 || $text === '' || $this->gibberish->isGibberish($text)) {
                continue;
            }

            if (in_array($segmentId, $suppressedSegmentIds, true)) {
                continue;
            }

            $startMs = (int)($segment['start_time_ms'] ?? 0);
            $endMs = (int)($segment['end_time_ms'] ?? $startMs);
            $suppressed = false;

            if ($current === null) {
                $current = $this->startBlock($segmentId, $startMs, $endMs, $text, $suppressed);
                continue;
            }

            $gap = $startMs - (int)$current['end_time_ms'];
            $wouldLength = strlen((string)$current['text']) + 1 + strlen($text);
            $wouldDuration = $endMs - (int)$current['start_time_ms'];

            if ($gap > self::GAP_MS || $wouldDuration > self::MAX_BLOCK_MS || $wouldLength > self::MAX_BLOCK_CHARS) {
                $blocks[] = $current;
                $current = $this->startBlock($segmentId, $startMs, $endMs, $text, $suppressed);
                continue;
            }

            $current['end_time_ms'] = max((int)$current['end_time_ms'], $endMs);
            $current['text'] = trim((string)$current['text'] . ' ' . $text);
            $current['speech_segment_ids'][] = $segmentId;
            if ($suppressed) {
                $current['suppressed'] = true;
            }
        }

        if ($current !== null) {
            $blocks[] = $current;
        }

        return $blocks;
    }

    /**
     * @return array<string,mixed>
     */
    private function startBlock(int $segmentId, int $startMs, int $endMs, string $text, bool $suppressed): array
    {
        return array(
            'start_time_ms' => $startMs,
            'end_time_ms' => $endMs,
            'text' => $text,
            'speech_segment_ids' => array($segmentId),
            'suppressed' => $suppressed,
        );
    }
}
