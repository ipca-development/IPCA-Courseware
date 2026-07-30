<?php
declare(strict_types=1);

require_once __DIR__ . '/EvidenceSchema.php';
require_once __DIR__ . '/GibberishSegmentDetectorService.php';

/**
 * Pass 4A — acoustic / speech-quality interpretation from whisper segment observations.
 * Outputs interpretation findings, not raw provider observations.
 */
final class Pass4aSpeechQualityService
{
    public function __construct(
        private readonly GibberishSegmentDetectorService $gibberish = new GibberishSegmentDetectorService(),
    ) {
    }
    private const NO_SPEECH_WARN = 0.5;
    private const NO_SPEECH_STRONG = 0.7;
    private const LOGPROB_WARN = -0.8;
    private const LOGPROB_STRONG = -1.2;
    private const COMPRESSION_WARN = 2.0;
    private const COMPRESSION_STRONG = 2.8;

    /**
     * @param list<array<string,mixed>> $speechSegments with joined provider metrics
     * @return array{findings:list<array<string,mixed>>,chunk_summary:array<string,mixed>}
     */
    public function analyze(array $speechSegments): array
    {
        $findings = array();
        $highNoSpeech = 0;
        $lowLogprob = 0;
        $highCompression = 0;
        $silenceWithText = 0;
        $gibberishSegments = 0;

        foreach ($speechSegments as $idx => $segment) {
            $segmentId = (int)($segment['id'] ?? 0);
            $text = trim((string)($segment['provider_segment_text'] ?? ''));
            $noSpeech = isset($segment['no_speech_probability']) ? (float)$segment['no_speech_probability'] : null;
            $logprob = isset($segment['avg_log_probability']) ? (float)$segment['avg_log_probability'] : null;
            $compression = isset($segment['compression_ratio']) ? (float)$segment['compression_ratio'] : null;

            $signals = array();
            $score = 0.0;

            if ($noSpeech !== null && $noSpeech >= self::NO_SPEECH_STRONG) {
                $signals[] = 'high_no_speech_probability';
                $score += 0.35;
                $highNoSpeech++;
            } elseif ($noSpeech !== null && $noSpeech >= self::NO_SPEECH_WARN) {
                $signals[] = 'elevated_no_speech_probability';
                $score += 0.15;
            }

            if ($logprob !== null && $logprob <= self::LOGPROB_STRONG) {
                $signals[] = 'low_avg_log_probability';
                $score += 0.3;
                $lowLogprob++;
            } elseif ($logprob !== null && $logprob <= self::LOGPROB_WARN) {
                $signals[] = 'reduced_avg_log_probability';
                $score += 0.12;
            }

            if ($compression !== null && $compression >= self::COMPRESSION_STRONG) {
                $signals[] = 'abnormal_compression_ratio';
                $score += 0.25;
                $highCompression++;
            } elseif ($compression !== null && $compression >= self::COMPRESSION_WARN) {
                $signals[] = 'elevated_compression_ratio';
                $score += 0.1;
            }

            if ($text !== '' && $noSpeech !== null && $noSpeech >= self::NO_SPEECH_WARN) {
                $signals[] = 'text_during_elevated_no_speech';
                $score += 0.2;
                $silenceWithText++;
            }

            $gibberish = $this->gibberish->analyze($text);
            if ($gibberish !== null) {
                foreach ($gibberish['signals'] as $signal) {
                    $signals[] = $signal;
                }
                $score = max($score, (float)($gibberish['confidence'] ?? 0));
                $gibberishSegments++;
            }

            if ($signals === array()) {
                continue;
            }

            $findings[] = array(
                'speech_segment_id' => $segmentId,
                'segment_index' => (int)($segment['provider_segment_index'] ?? $idx),
                'start_time_ms' => (int)($segment['start_time_ms'] ?? 0),
                'end_time_ms' => (int)($segment['end_time_ms'] ?? 0),
                'text_preview' => substr($text, 0, 120),
                'signals' => $signals,
                'confidence' => min(1.0, round($score, 4)),
                'metrics' => array(
                    'no_speech_probability' => $noSpeech,
                    'avg_log_probability' => $logprob,
                    'compression_ratio' => $compression,
                ),
            );
        }

        $total = max(1, count($speechSegments));
        return array(
            'findings' => $findings,
            'chunk_summary' => array(
                'segment_count' => count($speechSegments),
                'flagged_segment_count' => count($findings),
                'high_no_speech_segments' => $highNoSpeech,
                'low_logprob_segments' => $lowLogprob,
                'high_compression_segments' => $highCompression,
                'text_during_elevated_no_speech' => $silenceWithText,
                'gibberish_segment_count' => $gibberishSegments,
                'flagged_ratio' => round(count($findings) / $total, 4),
            ),
        );
    }
}
