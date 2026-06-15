<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingMccfRegulationLinkService.php';
require_once __DIR__ . '/ControlledPublishingMccfEasaPreviewRenderer.php';
require_once __DIR__ . '/ControlledPublishingBookSectionIndexService.php';
require_once __DIR__ . '/ControlledPublishingMccfManualRefService.php';

/**
 * Resolve regulation obligation + manual coverage text and score semantic alignment.
 */
final class ControlledPublishingMccfIntegrityContentService
{
    /** @var array<string,string> */
    private static array $easaObligationCache = array();

    /** @var list<string> */
    private const STOPWORDS = array(
        'that', 'this', 'with', 'from', 'have', 'been', 'will', 'shall', 'should', 'would',
        'their', 'there', 'which', 'when', 'what', 'where', 'while', 'about', 'into', 'upon',
        'under', 'over', 'after', 'before', 'through', 'during', 'within', 'without', 'other',
        'also', 'only', 'such', 'than', 'then', 'them', 'they', 'your', 'must', 'required',
        'requirements', 'requirement', 'manual', 'operations', 'training', 'approved', 'following',
        'accordance', 'organization', 'organisation', 'center', 'centre', 'trained',
        // Common BCAA MCCF audit-question boilerplate (not regulatory substance).
        'rules', 'rule', 'school', 'schools', 'concerning', 'regarding', 'activities', 'activity',
        'describe', 'explain', 'audit', 'checklist', 'ato', 'operator', 'policy', 'policies',
    );

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<string,mixed> $requirement
     * @param list<array<string,mixed>> $linkedExcerpts
     * @return array{score:int,label:string,tone:string,breakdown:array<string,int>,reasons:list<string>}
     */
    public function scoreRequirement(
        array $requirement,
        array $linkedExcerpts,
        array $regulationLinks,
        int $bookVersionId = 0,
        bool $resolveEasa = true,
        bool $lightweight = false
    ): array {
        $applicable = strtoupper(trim((string)($requirement['applicable'] ?? '')));
        $isApplicable = ($applicable === '' || $applicable === 'YES' || $applicable === 'Y');
        $manualRef = trim((string)($requirement['manual_section_ref'] ?? ''));
        $unlinkable = ControlledPublishingMccfManualRefService::isUnlinkableManualRef($manualRef, $linkedExcerpts);

        $breakdown = array(
            'regulation_obligation' => 0,
            'manual_coverage' => 0,
            'content_alignment' => 0,
        );

        if ($unlinkable && !$isApplicable) {
            return $this->pack(85, 'N/A — not applicable', 'muted', $breakdown, array(
                'Marked not applicable on the MCCF checklist.',
            ));
        }

        if ($unlinkable && $isApplicable) {
            return $this->pack(25, 'Header / scope item', 'warn', $breakdown, array(
                'Scope/header row without a linkable OM section reference.',
            ));
        }

        $obligation = $this->regulationObligationText($requirement, $regulationLinks, $resolveEasa);
        $manualText = $this->manualCoverageText($requirement, $linkedExcerpts, $bookVersionId, true);

        if ($obligation !== '') {
            $breakdown['regulation_obligation'] = 15;
        }
        if (strlen(trim($manualText)) >= 120) {
            $breakdown['manual_coverage'] = 15;
        } elseif (strlen(trim($manualText)) >= 40) {
            $breakdown['manual_coverage'] = 8;
        }

        $alignment = $this->scoreContentAlignment($obligation, $manualText, $requirement);
        $breakdown['content_alignment'] = $alignment['score'];

        $score = min(100, array_sum($breakdown));
        if (!$isApplicable) {
            $score = max($score, 70);
        }

        $reasons = $alignment['reasons'];
        if (ControlledPublishingMccfManualRefService::isHeadersPartsScope($manualRef)
            && ControlledPublishingMccfManualRefService::hasSpecificSectionTarget($manualRef)
            && $linkedExcerpts === array()) {
            $reasons[] = 'Scored using the specific Part/Ch line from the MCCF location (Headers Parts scope line ignored).';
        }
        if ($obligation === '') {
            $reasons[] = 'Could not extract the specific regulatory obligation text for comparison.';
        }
        if (trim($manualText) === '') {
            $reasons[] = 'No manual text could be loaded for the linked OM section(s).';
        }

        if ($score >= 80) {
            return $this->pack($score, 'Strong coverage', 'ok', $breakdown, $reasons);
        }
        if ($score >= 55) {
            return $this->pack($score, 'Partial coverage', 'warn', $breakdown, $reasons);
        }

        return $this->pack($score, 'Gap — review required', 'bad', $breakdown, $reasons);
    }

    /**
     * @param array<string,mixed> $requirement
     * @param list<array<string,mixed>> $regulationLinks
     */
    public function regulationObligationText(
        array $requirement,
        array $regulationLinks,
        bool $resolveEasa = true
    ): string {
        $parts = array();
        $regRef = trim((string)($requirement['regulation_ref'] ?? ''));
        $parsed = ControlledPublishingMccfRegulationLinkService::parseRegulationRef($regRef);

        if ($resolveEasa) {
            foreach ($regulationLinks as $link) {
                if (($link['match_confidence'] ?? '') === 'UNRESOLVED') {
                    continue;
                }
                $batchId = (int)($link['target_batch_id'] ?? 0);
                $nodeUid = trim((string)($link['target_node_uid'] ?? ''));
                if ($batchId <= 0 || $nodeUid === '') {
                    continue;
                }
                $token = (string)($link['rule_token'] ?? '');
                $text = $this->obligationFromEasaNode($batchId, $nodeUid, $token);
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
        }

        if ($parts === array() && $parsed !== array() && $resolveEasa) {
            $regSvc = new ControlledPublishingMccfRegulationLinkService($this->pdo);
            foreach ($parsed as $tokenRow) {
                $resolved = $regSvc->resolveEasaNode(
                    (string)$tokenRow['token'],
                    $tokenRow['prefix'] ?? null,
                    (string)($tokenRow['role'] ?? 'PRIMARY'),
                    (string)($tokenRow['citation'] ?? '')
                );
                if ($resolved === null) {
                    continue;
                }
                $text = $this->obligationFromEasaNode(
                    (int)($resolved['batch_id'] ?? 0),
                    (string)($resolved['node_uid'] ?? ''),
                    (string)$tokenRow['token']
                );
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
        }

        if ($parts === array()) {
            $fallback = array();
            if ($regRef !== '') {
                $fallback[] = $regRef;
            }
            $subject = trim((string)($requirement['subject'] ?? ''));
            if ($subject !== '') {
                $fallback[] = $subject;
            }
            $reqText = trim((string)($requirement['requirement_text'] ?? ''));
            if ($reqText !== '') {
                $fallback[] = $reqText;
            }

            return implode("\n", $fallback);
        }

        return implode("\n", array_unique($parts));
    }

    /**
     * @param array<string,mixed> $requirement
     * @param list<array<string,mixed>> $linkedExcerpts
     */
    public function manualCoverageText(
        array $requirement,
        array $linkedExcerpts,
        int $bookVersionId,
        bool $includeBookBlocks = true
    ): string {
        $index = new ControlledPublishingBookSectionIndexService($this->pdo);
        $manualCode = strtoupper(trim((string)($requirement['manual_code'] ?? 'OM')));
        if ($bookVersionId <= 0) {
            $bookVersionId = $index->resolveBookVersionId($manualCode);
        }

        $sectionRefs = ControlledPublishingMccfManualRefService::collectSectionRefsForScoring(
            $requirement,
            $linkedExcerpts
        );

        if ($sectionRefs === array() || !$includeBookBlocks || $bookVersionId <= 0) {
            $fallback = trim(implode("\n\n", array_filter(array_map(
                static fn(array $row): string => trim((string)($row['body_text'] ?? '')),
                $linkedExcerpts
            ))));

            return $fallback !== '' ? $fallback : trim((string)($requirement['manual_section_ref'] ?? ''));
        }

        $bookText = $index->plainTextForSectionRefs($bookVersionId, $sectionRefs, true);
        if ($bookText !== '') {
            return $bookText;
        }

        return trim((string)($requirement['manual_section_ref'] ?? ''));
    }

    /**
     * @return array{score:int,reasons:list<string>}
     */
    public function scoreContentAlignment(string $obligation, string $manual, array $requirement): array
    {
        $obligation = trim($obligation);
        $manual = trim($manual);
        $reasons = array();

        if ($obligation === '' || $manual === '') {
            return array(
                'score' => 0,
                'reasons' => array('Insufficient regulation or manual text to compare content.'),
            );
        }

        $concepts = $this->extractConcepts($obligation, $requirement);
        if ($concepts === array()) {
            return array(
                'score' => 20,
                'reasons' => array('Regulation obligation parsed but no comparable concepts were extracted.'),
            );
        }

        $manualNorm = $this->normalizeText($manual);
        $subjectNorm = $this->normalizeText((string)($requirement['subject'] ?? ''));
        if ($subjectNorm !== '' && str_contains($manualNorm, $subjectNorm)) {
            return array(
                'score' => 70,
                'reasons' => array(
                    'Manual section title/content matches the MCCF subject line.',
                ),
            );
        }

        $matched = array();
        $missing = array();

        foreach ($concepts as $concept) {
            if ($this->conceptPresent($concept, $manualNorm)) {
                $matched[] = $concept;
            } else {
                $missing[] = $concept;
            }
        }

        $matchRatio = count($matched) / max(1, count($concepts));
        $score = (int)round(min(70, $matchRatio * 70));

        if (strlen($manualNorm) >= 800 && $matchRatio >= 0.55) {
            $score = min(70, $score + 10);
            $reasons[] = 'Manual section contains substantial text addressing the regulatory topic.';
        } elseif (strlen($manualNorm) >= 400 && $matchRatio >= 0.45) {
            $score = min(70, $score + 5);
        }

        if ($matchRatio >= 0.75) {
            $reasons[] = 'Manual content closely implements the regulatory obligation ('
                . count($matched) . '/' . count($concepts) . ' key concepts matched).';
            if ($matched !== array()) {
                $reasons[] = 'Matched concepts include: ' . implode(', ', array_slice($matched, 0, 6))
                    . (count($matched) > 6 ? '…' : '') . '.';
            }
        } elseif ($matchRatio >= 0.5) {
            $reasons[] = 'Manual partially addresses the regulation but some expected topics are thin or missing.';
            if ($missing !== array()) {
                $reasons[] = 'Weak or missing in manual: ' . implode(', ', array_slice($missing, 0, 5))
                    . (count($missing) > 5 ? '…' : '') . '.';
            }
        } else {
            $reasons[] = 'Manual text does not adequately cover what the regulation item requires.';
            if ($missing !== array()) {
                $reasons[] = 'Not found in manual: ' . implode(', ', array_slice($missing, 0, 6))
                    . (count($missing) > 6 ? '…' : '') . '.';
            }
        }

        $reqText = trim((string)($requirement['requirement_text'] ?? ''));
        if ($reqText !== '' && $this->phrasePresent($this->normalizeText($reqText), $manualNorm)) {
            $score = min(70, $score + 8);
            $reasons[] = 'Manual text aligns with the MCCF audit question for this row.';
        }

        return array(
            'score' => max(0, min(70, $score)),
            'reasons' => array_values(array_unique($reasons)),
        );
    }

    private function obligationFromEasaNode(int $batchId, string $nodeUid, string $ruleToken): string
    {
        if ($batchId <= 0 || $nodeUid === '') {
            return '';
        }

        $cacheKey = $batchId . '|' . $nodeUid . '|' . strtoupper(trim($ruleToken));
        if (isset(self::$easaObligationCache[$cacheKey])) {
            return self::$easaObligationCache[$cacheKey];
        }

        require_once __DIR__ . '/../easa_erules_xml_import.php';
        require_once __DIR__ . '/../resource_library_easa_node_detail_build.php';

        try {
            $detail = rl_easa_api_node_detail_build($this->pdo, $batchId, $nodeUid);
        } catch (Throwable) {
            return self::$easaObligationCache[$cacheKey] = '';
        }
        if (!is_array($detail) || empty($detail['ok']) || !is_array($detail['node'] ?? null)) {
            return self::$easaObligationCache[$cacheKey] = '';
        }

        $node = $detail['node'];
        $title = trim((string)($node['title'] ?? ''));
        $blocks = $node['structured_blocks'] ?? null;
        if (!is_array($blocks) || $blocks === array()) {
            return self::$easaObligationCache[$cacheKey] = trim($title . "\n" . (string)($node['plain_text_effective'] ?? $node['plain_text'] ?? ''));
        }

        $markerPath = $this->markerPathFromToken($ruleToken, $title);
        $stack = array();
        $lines = array($title);
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (($block['type'] ?? '') !== 'list_item') {
                continue;
            }
            $marker = trim((string)($block['marker'] ?? ''));
            $stack = $this->advanceMarkerStack($stack, $marker);
            if ($this->listItemMatchesMarkerPath($stack, $markerPath)) {
                $lines[] = trim($marker . ' ' . (string)($block['text'] ?? ''));
            }
        }

        if (count($lines) === 1 && $markerPath !== array()) {
            foreach ($blocks as $block) {
                if (!is_array($block) || ($block['type'] ?? '') !== 'list_item') {
                    continue;
                }
                $lines[] = trim((string)($block['marker'] ?? '') . ' ' . (string)($block['text'] ?? ''));
            }
        }

        return self::$easaObligationCache[$cacheKey] = trim(implode("\n", array_unique(array_filter($lines))));
    }

    /**
     * @param array<string,mixed> $requirement
     * @return list<string>
     */
    private function extractConcepts(string $obligation, array $requirement): array
    {
        $obligation = trim($obligation);
        $subject = trim((string)($requirement['subject'] ?? ''));

        // Compare regulation obligation + MCCF subject — not the audit-question wording.
        $blob = $obligation;
        if ($subject !== '' && stripos($blob, $subject) === false) {
            $blob .= ($blob !== '' ? "\n" : '') . $subject;
        }

        // Only fall back to the audit question when regulation/subject text is too thin.
        if (mb_strlen(preg_replace('/\s+/u', '', $blob) ?: '') < 12) {
            $auditTopic = $this->auditQuestionTopicText($requirement);
            if ($auditTopic !== '') {
                $blob .= ($blob !== '' ? "\n" : '') . $auditTopic;
            }
        }

        $concepts = array();
        $push = static function (string $term) use (&$concepts): void {
            $term = strtolower(trim($term));
            if ($term === '' || strlen($term) < 4 || in_array($term, self::STOPWORDS, true)) {
                return;
            }
            $concepts[$term] = true;
        };

        if (preg_match_all('/\b[a-z]{4,}\b/iu', strtolower($blob), $matches)) {
            foreach ($matches[0] as $word) {
                $push($word);
            }
        }

        foreach (array('student discipline', 'disciplinary action', 'corrective action', 'poor weather', 'carriage of passengers') as $phrase) {
            if (stripos($blob, $phrase) !== false) {
                foreach (preg_split('/\s+/u', $phrase) ?: array() as $part) {
                    $push($part);
                }
            }
        }

        return array_keys($concepts);
    }

    /**
     * Strip BCAA audit-question boilerplate and return the underlying topic phrase.
     *
     * @param array<string,mixed> $requirement
     */
    private function auditQuestionTopicText(array $requirement): string
    {
        $text = trim((string)($requirement['requirement_text'] ?? ''));
        if ($text === '') {
            return '';
        }

        $patterns = array(
            '/^what are the (?:rules|procedures|policy|policies) of the (?:school|organisation|organization|ato|operator) (?:concerning|regarding|on|about)\s+/iu',
            '/^describe the (?:rules|procedures|policy|policies) (?:concerning|regarding|on|about)\s+/iu',
            '/^explain how the (?:school|organisation|organization|ato)\s+/iu',
        );
        foreach ($patterns as $pattern) {
            $stripped = preg_replace($pattern, '', $text);
            if (is_string($stripped) && $stripped !== $text) {
                $text = $stripped;
                break;
            }
        }

        $text = preg_replace('/\bduring training activities\b/iu', '', $text) ?? $text;

        return rtrim(trim($text), " \t\n\r\0\x0B?.");
    }

    private function normalizeText(string $text): string
    {
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9\s]/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private function conceptPresent(string $concept, string $manualNorm): bool
    {
        if ($concept === '') {
            return false;
        }
        foreach ($this->conceptLookupVariants($concept) as $variant) {
            if ($variant !== '' && str_contains($manualNorm, $variant)) {
                return true;
            }
        }

        $stems = array(
            'disciplinary' => 'disciplin',
            'discipline' => 'disciplin',
            'authorization' => 'authori',
            'authorised' => 'authori',
            'authorized' => 'authori',
            'cancellation' => 'cancel',
            'cancellations' => 'cancel',
            'supervision' => 'supervis',
            'supervise' => 'supervis',
            'passengers' => 'passenger',
            'activities' => 'activ',
        );
        foreach ($stems as $from => $stem) {
            if ($concept === $from || str_starts_with($concept, $stem)) {
                return str_contains($manualNorm, $stem);
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function conceptLookupVariants(string $concept): array
    {
        $concept = strtolower(trim($concept));
        if ($concept === '') {
            return array();
        }

        $variants = array($concept);
        if (str_ends_with($concept, 'ies') && strlen($concept) > 4) {
            $variants[] = substr($concept, 0, -3) . 'y';
        }
        if (str_ends_with($concept, 's') && !str_ends_with($concept, 'ss') && strlen($concept) >= 4) {
            $variants[] = substr($concept, 0, -1);
        }

        return array_values(array_unique(array_filter($variants)));
    }

    private function phrasePresent(string $phrase, string $manualNorm): bool
    {
        $words = array_values(array_filter(explode(' ', $phrase)));
        if (count($words) < 3) {
            return false;
        }
        $hits = 0;
        foreach ($words as $word) {
            if (strlen($word) < 4 || in_array($word, self::STOPWORDS, true)) {
                continue;
            }
            if (str_contains($manualNorm, $word)) {
                $hits++;
            }
        }

        return $hits >= max(2, (int)ceil(count($words) * 0.45));
    }

    /**
     * @return list<string>
     */
    private function markerPathFromToken(string $token, string $nodeTitle): array
    {
        preg_match_all('/\(([A-Za-z0-9]+)\)/', strtoupper($token), $matches);
        $path = $matches[1] ?? array();
        preg_match_all('/\(([A-Za-z0-9]+)\)/', strtoupper($nodeTitle), $titleMatches);
        $titlePath = $titleMatches[1] ?? array();
        while ($path !== array() && $titlePath !== array() && $path[0] === $titlePath[0]) {
            array_shift($path);
            array_shift($titlePath);
        }

        return $path;
    }

    /**
     * @param list<string> $stack
     * @return list<string>
     */
    private function advanceMarkerStack(array $stack, string $marker): array
    {
        $marker = strtoupper(trim($marker));
        if (preg_match('/^\(([^)]+)\)$/', $marker, $m)) {
            $norm = strtoupper(trim($m[1]));
        } else {
            $norm = $marker;
        }
        if ($norm === '') {
            return $stack;
        }
        if (preg_match('/^[A-Z]$/', $norm)) {
            return array($norm);
        }
        if (preg_match('/^\d+$/', $norm) && $stack !== array() && preg_match('/^[A-Z]$/', $stack[0])) {
            return array($stack[0], $norm);
        }

        return array($norm);
    }

    /**
     * @param list<string> $stack
     * @param list<string> $markerPath
     */
    private function listItemMatchesMarkerPath(array $stack, array $markerPath): bool
    {
        if ($markerPath === array() || $stack === array() || count($stack) < count($markerPath)) {
            return false;
        }

        return array_slice($stack, -count($markerPath)) === $markerPath;
    }

    /**
     * @param array<string,int> $breakdown
     * @param list<string> $reasons
     * @return array{score:int,label:string,tone:string,breakdown:array<string,int>,reasons:list<string>}
     */
    private function pack(int $score, string $label, string $tone, array $breakdown, array $reasons): array
    {
        return array(
            'score' => max(0, min(100, $score)),
            'label' => $label,
            'tone' => $tone,
            'breakdown' => $breakdown,
            'reasons' => array_values(array_unique(array_filter($reasons))),
        );
    }
}
