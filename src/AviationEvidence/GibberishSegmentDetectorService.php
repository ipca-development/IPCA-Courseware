<?php
declare(strict_types=1);

/**
 * Detects ASR hallucination / gibberish segments (repeated symbols, non-Latin noise, etc.).
 */
final class GibberishSegmentDetectorService
{
    /** @var list<string> */
    public const SIGNALS = array(
        'single_grapheme_repetition',
        'anomalous_script',
        'very_low_character_entropy',
        'mostly_symbols',
        'repeated_two_grapheme_loop',
    );

    private const SUPPRESS_CONFIDENCE = 0.55;

    /**
     * @return array{signals:list<string>,confidence:float,text_preview:string}|null
     */
    public function analyze(string $text): ?array
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) < 3) {
            return null;
        }

        $signals = array();
        $score = 0.0;

        if ($this->isDominatedBySingleGrapheme($text, 0.72, 4)) {
            $signals[] = 'single_grapheme_repetition';
            $score = max($score, 0.92);
        }

        if ($this->isRepeatedTwoGraphemeLoop($text)) {
            $signals[] = 'repeated_two_grapheme_loop';
            $score = max($score, 0.88);
        }

        if ($this->hasAnomalousScript($text)) {
            $signals[] = 'anomalous_script';
            $score = max($score, 0.9);
        }

        if ($this->hasVeryLowEntropy($text)) {
            $signals[] = 'very_low_character_entropy';
            $score = max($score, 0.78);
        }

        if ($this->isMostlySymbols($text)) {
            $signals[] = 'mostly_symbols';
            $score = max($score, 0.85);
        }

        if ($signals === array()) {
            return null;
        }

        return array(
            'signals' => $signals,
            'confidence' => min(1.0, round($score, 4)),
            'text_preview' => mb_substr($text, 0, 120),
        );
    }

    public function isGibberish(string $text): bool
    {
        $result = $this->analyze($text);
        return $result !== null && ($result['confidence'] ?? 0) >= self::SUPPRESS_CONFIDENCE;
    }

    public function shouldSuppressConfidence(float $confidence, array $signals): bool
    {
        if ($confidence < self::SUPPRESS_CONFIDENCE) {
            return false;
        }
        if (array_intersect($signals, self::SIGNALS) !== array()) {
            return true;
        }
        return $confidence >= 0.65;
    }

    private function isDominatedBySingleGrapheme(string $text, float $threshold, int $minLen): bool
    {
        $counts = array();
        $visible = 0;
        $length = mb_strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $grapheme = mb_substr($text, $i, 1);
            if (preg_match('/\s/u', $grapheme)) {
                continue;
            }
            $visible++;
            $counts[$grapheme] = ($counts[$grapheme] ?? 0) + 1;
        }
        if ($visible < $minLen || $counts === array()) {
            return false;
        }

        $max = max($counts);
        return ($max / $visible) >= $threshold;
    }

    private function isRepeatedTwoGraphemeLoop(string $text): bool
    {
        $compact = preg_replace('/\s+/u', '', $text) ?? '';
        $len = mb_strlen($compact);
        if ($len < 8) {
            return false;
        }
        if ($len % 2 !== 0) {
            return false;
        }
        $unit = mb_substr($compact, 0, 2);
        if ($unit === '' || preg_match('/^\p{Latin}+$/u', $unit)) {
            return false;
        }
        for ($i = 0; $i < $len; $i += 2) {
            if (mb_substr($compact, $i, 2) !== $unit) {
                return false;
            }
        }
        return ($len / 2) >= 4;
    }

    private function hasAnomalousScript(string $text): bool
    {
        $letters = 0;
        $nonLatinLetters = 0;
        $nonAsciiSymbols = 0;
        $totalNonSpace = 0;

        $length = mb_strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($text, $i, 1);
            if (preg_match('/\s/u', $char)) {
                continue;
            }
            $totalNonSpace++;
            if (preg_match('/\p{L}/u', $char)) {
                $letters++;
                if (!preg_match('/\p{Latin}/u', $char)) {
                    $nonLatinLetters++;
                }
            } elseif (!preg_match('#[0-9.,!?;:()\[\]\-\'/"]#u', $char)) {
                $nonAsciiSymbols++;
            }
        }

        if ($totalNonSpace === 0) {
            return false;
        }

        if ($letters > 0 && ($nonLatinLetters / $letters) >= 0.5 && $nonLatinLetters >= 3) {
            return true;
        }

        return ($nonAsciiSymbols / $totalNonSpace) >= 0.65 && $totalNonSpace >= 4;
    }

    private function hasVeryLowEntropy(string $text): bool
    {
        $compact = preg_replace('/\s+/u', '', $text) ?? '';
        $len = mb_strlen($compact);
        if ($len < 8) {
            return false;
        }
        $chars = preg_split('//u', $compact, -1, PREG_SPLIT_NO_EMPTY) ?: array();
        $unique = count(array_unique($chars));
        $ratio = $unique / $len;
        if ($ratio > 0.2) {
            return false;
        }
        if ($unique <= 4 && preg_match('/^\p{Latin}+$/u', $compact)) {
            return $len >= 24 && $ratio <= 0.15;
        }
        return true;
    }

    private function isMostlySymbols(string $text): bool
    {
        $symbolLike = 0;
        $total = 0;
        $length = mb_strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($text, $i, 1);
            if (preg_match('/\s/u', $char)) {
                continue;
            }
            $total++;
            if (preg_match('/[\p{S}\p{M}]/u', $char) || !preg_match('#[\p{L}\p{N}.,!?;:()\[\]\-\'/"]#u', $char)) {
                $symbolLike++;
            }
        }
        return $total >= 4 && ($symbolLike / $total) >= 0.7;
    }
}
