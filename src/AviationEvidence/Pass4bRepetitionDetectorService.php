<?php
declare(strict_types=1);

/**
 * Pass 4B — generic semantic repetition / loop detection (not phrase-specific).
 */
final class Pass4bRepetitionDetectorService
{
    /**
     * @param list<array<string,mixed>> $speechSegments
     * @return array{findings:list<array<string,mixed>>,chunk_summary:array<string,mixed>,readable_text:string}
     */
    public function analyze(
        string $primaryText,
        ?string $secondaryText,
        array $speechSegments
    ): array {
        $findings = array();
        $sentences = self::splitSentences($primaryText);
        $phrases = self::splitPhrases($primaryText);

        $findings = array_merge($findings, self::detectExactRepeatedPhrases($phrases, $speechSegments));
        $findings = array_merge($findings, self::detectRepeatedNgrams($primaryText, $speechSegments, 3));
        $findings = array_merge($findings, self::detectAaLoops($sentences, $speechSegments));
        $findings = array_merge($findings, self::detectAbLoops($sentences, $speechSegments));
        $findings = array_merge($findings, self::detectPhraseCycleLoops($phrases, $speechSegments));

        $lexical = self::lexicalDiversity($primaryText);
        if ($lexical['total_words'] >= 20 && $lexical['unique_ratio'] < 0.35) {
            $findings[] = self::chunkFinding(
                'low_lexical_diversity',
                min(1.0, 0.5 + (0.35 - $lexical['unique_ratio'])),
                array('lexical' => $lexical),
                $speechSegments
            );
        }

        if ($lexical['compression_ratio_estimate'] >= 3.0 && $lexical['total_words'] >= 15) {
            $findings[] = self::chunkFinding(
                'abnormal_compression',
                min(1.0, 0.4 + ($lexical['compression_ratio_estimate'] - 3.0) * 0.1),
                array('lexical' => $lexical),
                $speechSegments
            );
        }

        $tokenDominance = self::repeatedTokenDominance($primaryText);
        if ($tokenDominance !== null) {
            $findings[] = self::chunkFinding(
                'repeated_token_dominance',
                $tokenDominance['confidence'],
                $tokenDominance,
                $speechSegments
            );
        }

        $endConcentration = self::repetitionNearChunkEnd($phrases, $speechSegments);
        if ($endConcentration !== null) {
            $findings[] = $endConcentration;
        }

        if ($secondaryText !== null && trim($secondaryText) !== '' && trim($secondaryText) !== trim($primaryText)) {
            $secondaryLexical = self::lexicalDiversity($secondaryText);
            if ($secondaryLexical['compression_ratio_estimate'] >= 2.5) {
                $findings[] = self::chunkFinding(
                    'secondary_hypothesis_repetition',
                    0.6,
                    array(
                        'secondary_text_preview' => mb_substr(trim($secondaryText), 0, 200),
                        'secondary_lexical' => $secondaryLexical,
                    ),
                    $speechSegments
                );
            }
        }

        $findings = self::dedupeFindings($findings);
        $suppressSegmentIds = self::segmentsToSuppress($findings, $speechSegments);
        $readable = self::buildReadableText($speechSegments, $suppressSegmentIds);

        return array(
            'findings' => $findings,
            'chunk_summary' => array(
                'finding_count' => count($findings),
                'lexical_diversity' => $lexical,
                'proposed_suppression_segment_count' => count($suppressSegmentIds),
                'primary_text_length' => strlen($primaryText),
                'secondary_text_available' => $secondaryText !== null && trim($secondaryText) !== '',
            ),
            'readable_text' => $readable,
            'suppress_segment_ids' => $suppressSegmentIds,
        );
    }

    /**
     * @return list<string>
     */
    private static function splitSentences(string $text): array
    {
        $parts = preg_split('/(?<=[.!?])\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: array();
        return array_values(array_filter(array_map('trim', $parts), fn(string $s): bool => $s !== ''));
    }

    /**
     * @return list<string>
     */
    private static function splitPhrases(string $text): array
    {
        $parts = preg_split('/[.!?;\n]+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: array();
        return array_values(array_filter(array_map(static fn(string $s): string => trim($s), $parts), fn(string $s): bool => strlen($s) >= 3));
    }

    /**
     * @param list<string> $phrases
     * @param list<array<string,mixed>> $speechSegments
     * @return list<array<string,mixed>>
     */
    private static function detectExactRepeatedPhrases(array $phrases, array $speechSegments): array
    {
        $counts = array_count_values(array_map(static fn(string $p): string => strtolower($p), $phrases));
        $findings = array();
        foreach ($counts as $phrase => $count) {
            $phrase = (string)$phrase;
            if ($count < 3 || strlen($phrase) < 4) {
                continue;
            }
            $findings[] = array(
                'detection_type' => 'exact_repeated_phrase',
                'confidence' => min(1.0, 0.5 + $count * 0.08),
                'phrase' => $phrase,
                'occurrence_count' => $count,
                'speech_segment_ids' => self::segmentIdsContaining($phrase, $speechSegments),
            );
        }
        return $findings;
    }

    /**
     * @param list<array<string,mixed>> $speechSegments
     * @return list<array<string,mixed>>
     */
    private static function detectRepeatedNgrams(string $text, array $speechSegments, int $n): array
    {
        $words = self::tokenize($text);
        if (count($words) < $n * 3) {
            return array();
        }
        $ngrams = array();
        for ($i = 0; $i <= count($words) - $n; $i++) {
            $gram = implode(' ', array_slice($words, $i, $n));
            $ngrams[$gram] = ($ngrams[$gram] ?? 0) + 1;
        }
        arsort($ngrams);
        $findings = array();
        foreach (array_slice($ngrams, 0, 5, true) as $gram => $count) {
            if ($count < 4) {
                break;
            }
            $findings[] = array(
                'detection_type' => 'repeated_ngram',
                'confidence' => min(1.0, 0.45 + $count * 0.06),
                'ngram' => $gram,
                'n' => $n,
                'occurrence_count' => $count,
                'speech_segment_ids' => self::segmentIdsContaining($gram, $speechSegments),
            );
        }
        return $findings;
    }

    /**
     * @param list<string> $sentences
     * @return list<array<string,mixed>>
     */
    private static function detectAaLoops(array $sentences, array $speechSegments): array
    {
        $findings = array();
        $runPhrase = null;
        $runCount = 0;
        foreach ($sentences as $sentence) {
            $key = strtolower(trim($sentence));
            if ($key === '') {
                continue;
            }
            if ($key === $runPhrase) {
                $runCount++;
            } else {
                if ($runPhrase !== null && $runCount >= 3) {
                    $findings[] = array(
                        'detection_type' => 'aa_loop',
                        'confidence' => min(1.0, 0.55 + $runCount * 0.07),
                        'phrase' => $runPhrase,
                        'occurrence_count' => $runCount,
                        'speech_segment_ids' => self::segmentIdsContaining($runPhrase, $speechSegments),
                    );
                }
                $runPhrase = $key;
                $runCount = 1;
            }
        }
        if ($runPhrase !== null && $runCount >= 3) {
            $findings[] = array(
                'detection_type' => 'aa_loop',
                'confidence' => min(1.0, 0.55 + $runCount * 0.07),
                'phrase' => $runPhrase,
                'occurrence_count' => $runCount,
                'speech_segment_ids' => self::segmentIdsContaining($runPhrase, $speechSegments),
            );
        }
        return $findings;
    }

    /**
     * @param list<string> $sentences
     * @return list<array<string,mixed>>
     */
    private static function detectAbLoops(array $sentences, array $speechSegments): array
    {
        $findings = array();
        $keys = array_values(array_filter(array_map(static fn(string $s): string => strtolower(trim($s)), $sentences)));
        for ($i = 0; $i < count($keys) - 5; $i++) {
            $a = $keys[$i];
            $b = $keys[$i + 1] ?? '';
            if ($a === '' || $b === '' || $a === $b) {
                continue;
            }
            $cycles = 0;
            for ($j = $i; $j < count($keys) - 1; $j += 2) {
                if (($keys[$j] ?? '') === $a && ($keys[$j + 1] ?? '') === $b) {
                    $cycles++;
                } else {
                    break;
                }
            }
            if ($cycles >= 3) {
                $findings[] = array(
                    'detection_type' => 'ab_loop',
                    'confidence' => min(1.0, 0.6 + $cycles * 0.08),
                    'phrase_a' => $a,
                    'phrase_b' => $b,
                    'cycle_count' => $cycles,
                    'speech_segment_ids' => array_values(array_unique(array_merge(
                        self::segmentIdsContaining($a, $speechSegments),
                        self::segmentIdsContaining($b, $speechSegments)
                    ))),
                );
            }
        }
        return $findings;
    }

    /**
     * @param list<string> $phrases
     * @return list<array<string,mixed>>
     */
    private static function detectPhraseCycleLoops(array $phrases, array $speechSegments): array
    {
        $findings = array();
        $keys = array_map(static fn(string $p): string => strtolower(trim($p)), $phrases);
        for ($len = 2; $len <= 4; $len++) {
            for ($i = 0; $i < count($keys) - ($len * 3); $i++) {
                $pattern = array_slice($keys, $i, $len);
                if (in_array('', $pattern, true)) {
                    continue;
                }
                $repeats = 1;
                for ($j = $i + $len; $j + $len <= count($keys); $j += $len) {
                    if (array_slice($keys, $j, $len) === $pattern) {
                        $repeats++;
                    } else {
                        break;
                    }
                }
                if ($repeats >= 3) {
                    $findings[] = array(
                        'detection_type' => 'phrase_cycle_loop',
                        'confidence' => min(1.0, 0.55 + $repeats * 0.1),
                        'pattern' => $pattern,
                        'pattern_length' => $len,
                        'repeat_count' => $repeats,
                        'speech_segment_ids' => self::segmentIdsForPattern($pattern, $speechSegments),
                    );
                }
            }
        }
        return $findings;
    }

    /**
     * @return array{total_words:int,unique_words:int,unique_ratio:float,compression_ratio_estimate:float}
     */
    private static function lexicalDiversity(string $text): array
    {
        $words = self::tokenize($text);
        $total = count($words);
        if ($total === 0) {
            return array('total_words' => 0, 'unique_words' => 0, 'unique_ratio' => 0.0, 'compression_ratio_estimate' => 0.0);
        }
        $unique = count(array_unique($words));
        return array(
            'total_words' => $total,
            'unique_words' => $unique,
            'unique_ratio' => round($unique / $total, 4),
            'compression_ratio_estimate' => round($total / max(1, $unique), 3),
        );
    }

    /**
     * @return array{token:string,count:int,ratio:float,confidence:float}|null
     */
    private static function repeatedTokenDominance(string $text): ?array
    {
        $words = self::tokenize($text);
        $total = count($words);
        if ($total < 20) {
            return null;
        }
        $freq = array_count_values($words);
        arsort($freq);
        $top = array_key_first($freq);
        $count = (int)($freq[$top] ?? 0);
        $ratio = $count / $total;
        if ($ratio < 0.12 || $count < 5) {
            return null;
        }
        return array(
            'token' => (string)$top,
            'count' => $count,
            'ratio' => round($ratio, 4),
            'confidence' => min(1.0, 0.4 + $ratio),
        );
    }

    /**
     * @param list<string> $phrases
     * @param list<array<string,mixed>> $speechSegments
     * @return array<string,mixed>|null
     */
    private static function repetitionNearChunkEnd(array $phrases, array $speechSegments): ?array
    {
        if ($phrases === array() || $speechSegments === array()) {
            return null;
        }
        $total = count($phrases);
        $tailStart = (int)floor($total * 0.75);
        $tail = array_slice($phrases, $tailStart);
        $head = array_slice($phrases, 0, $tailStart);
        $tailCounts = array_count_values(array_map('strtolower', $tail));
        $repeatedInTail = 0;
        foreach ($tailCounts as $phrase => $count) {
            $phrase = (string)$phrase;
            if ($count >= 2 && strlen($phrase) >= 4) {
                $repeatedInTail += $count;
            }
        }
        if ($repeatedInTail < 4) {
            return null;
        }
        $tailSegmentCount = (int)max(1, ceil(count($speechSegments) * 0.25));
        $tailSegments = array_slice($speechSegments, -$tailSegmentCount);
        return array(
            'detection_type' => 'repetition_concentrated_near_chunk_end',
            'confidence' => min(1.0, 0.5 + $repeatedInTail * 0.05),
            'repeated_units_in_tail' => $repeatedInTail,
            'tail_phrase_count' => count($tail),
            'head_phrase_count' => count($head),
            'speech_segment_ids' => array_map(static fn(array $s): int => (int)$s['id'], $tailSegments),
        );
    }

    /**
     * @return list<string>
     */
    private static function tokenize(string $text): array
    {
        $normalized = strtolower(trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? $text));
        return preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: array();
    }

    /**
     * @param list<array<string,mixed>> $speechSegments
     * @return list<int>
     */
    private static function segmentIdsContaining(string $needle, array $speechSegments): array
    {
        $ids = array();
        $needle = strtolower($needle);
        foreach ($speechSegments as $segment) {
            $text = strtolower((string)($segment['provider_segment_text'] ?? ''));
            if ($needle !== '' && str_contains($text, $needle)) {
                $ids[] = (int)$segment['id'];
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * @param list<string> $pattern
     * @param list<array<string,mixed>> $speechSegments
     * @return list<int>
     */
    private static function segmentIdsForPattern(array $pattern, array $speechSegments): array
    {
        $ids = array();
        foreach ($pattern as $part) {
            $ids = array_merge($ids, self::segmentIdsContaining($part, $speechSegments));
        }
        return array_values(array_unique($ids));
    }

    /**
     * @param list<array<string,mixed>> $speechSegments
     * @return array<string,mixed>
     */
    private static function chunkFinding(string $type, float $confidence, array $details, array $speechSegments): array
    {
        return array(
            'detection_type' => $type,
            'confidence' => round($confidence, 4),
            'details' => $details,
            'speech_segment_ids' => array_map(static fn(array $s): int => (int)$s['id'], $speechSegments),
        );
    }

    /**
     * @param list<array<string,mixed>> $findings
     * @return list<array<string,mixed>>
     */
    private static function dedupeFindings(array $findings): array
    {
        $seen = array();
        $out = array();
        foreach ($findings as $finding) {
            $key = ($finding['detection_type'] ?? '') . '|' . json_encode($finding['phrase'] ?? $finding['pattern'] ?? $finding['ngram'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $finding;
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $findings
     * @param list<array<string,mixed>> $speechSegments
     * @return list<int>
     */
    private static function segmentsToSuppress(array $findings, array $speechSegments): array
    {
        $ids = array();
        foreach ($findings as $finding) {
            $type = (string)($finding['detection_type'] ?? '');
            if ($type === 'secondary_hypothesis_repetition') {
                continue;
            }
            if (($finding['confidence'] ?? 0) < 0.55) {
                continue;
            }
            if ($type === 'exact_repeated_phrase') {
                $phrase = (string)($finding['phrase'] ?? '');
                if (strlen($phrase) < 8 || in_array($phrase, array('okay', 'thank you', 'thanks', 'yes', 'no'), true)) {
                    continue;
                }
            }
            foreach ($finding['speech_segment_ids'] ?? array() as $id) {
                $ids[(int)$id] = true;
            }
        }
        if (count($ids) > count($speechSegments) * 0.6) {
            return array();
        }
        return array_keys($ids);
    }

    /**
     * @param list<array<string,mixed>> $speechSegments
     * @param list<int> $suppressIds
     */
    private static function buildReadableText(array $speechSegments, array $suppressIds): string
    {
        $gibberish = new GibberishSegmentDetectorService();
        $parts = array();
        foreach ($speechSegments as $segment) {
            $id = (int)($segment['id'] ?? 0);
            if (in_array($id, $suppressIds, true)) {
                continue;
            }
            $text = trim((string)($segment['provider_segment_text'] ?? ''));
            if ($text === '' || $gibberish->isGibberish($text)) {
                continue;
            }
            $parts[] = $text;
        }
        return trim(implode(' ', $parts));
    }
}
