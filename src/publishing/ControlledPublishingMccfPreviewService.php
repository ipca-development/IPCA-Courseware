<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingMccfBrowserService.php';
require_once __DIR__ . '/ControlledPublishingMccfRegulationLinkService.php';
require_once __DIR__ . '/ControlledPublishingMccfEasaPreviewRenderer.php';
require_once __DIR__ . '/ControlledPublishingFoundationService.php';
require_once __DIR__ . '/ControlledPublishingSectionService.php';
require_once __DIR__ . '/ControlledPublishingBlockService.php';
require_once __DIR__ . '/ControlledPublishingBookRenderer.php';
require_once __DIR__ . '/ControlledPublishingBookStyleService.php';
require_once __DIR__ . '/ControlledPublishingSectionNumberService.php';
require_once __DIR__ . '/ControlledPublishingRevisionService.php';
require_once __DIR__ . '/ControlledPublishingMccfIntegrityService.php';
require_once __DIR__ . '/../easa_erules_xml_import.php';
require_once __DIR__ . '/../resource_library_easa_node_detail_build.php';

/**
 * Read-only regulation and manual previews for the MCCF browser modals.
 */
final class ControlledPublishingMccfPreviewService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function regulationPreview(int $requirementId, string $ruleToken = ''): array
    {
        $requirement = $this->requirement($requirementId);
        if ($requirement === null) {
            return array('ok' => false, 'error' => 'Requirement not found.');
        }
        $summary = $this->requirementSummary($requirement);

        $tokens = $this->resolveRuleTokens($requirement, $ruleToken);
        if ($tokens === array()) {
            return array('ok' => false, 'error' => 'No regulation reference on this requirement.', 'requirement' => $summary);
        }

        $detail = $this->resolveRegulationNodeDetail($requirementId, $tokens);
        if ($detail === null) {
            return array(
                'ok' => false,
                'error' => 'Could not load regulation source from EASA Resource Library.',
                'requirement' => $summary,
            );
        }

        $node = $detail['node'];
        $highlight = $this->pickHighlightToken($tokens, $ruleToken);
        $title = trim((string)($node['title'] ?? $highlight));
        if ($title === '') {
            $title = $highlight;
        }

        return array(
            'ok' => true,
            'title' => $title,
            'subtitle' => (string)($requirement['regulation_ref'] ?? $highlight),
            'html' => ControlledPublishingMccfEasaPreviewRenderer::renderNodeDetail($node, $highlight),
            'highlight' => $highlight,
            'batch_id' => (int)($node['batch_id'] ?? 0),
            'node_uid' => (string)($node['node_uid'] ?? ''),
            'requirement' => $summary,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function manualPreview(int $requirementId, string $excerptKey = ''): array
    {
        $requirement = $this->requirement($requirementId);
        if ($requirement === null) {
            return array('ok' => false, 'error' => 'Requirement not found.');
        }
        $summary = $this->requirementSummary($requirement);

        $excerpts = $this->linkedExcerpts($requirementId);
        if ($excerpts === array()) {
            return array(
                'ok' => false,
                'error' => 'No linked OM section for this requirement.',
                'requirement' => $summary,
            );
        }

        if ($excerptKey !== '') {
            $excerpts = array_values(array_filter(
                $excerpts,
                static fn(array $row): bool => (string)($row['excerpt_key'] ?? '') === $excerptKey
            ));
        }

        $manualCode = strtoupper(trim((string)($requirement['manual_code'] ?? 'OM')));
        $versionLabel = $manualCode === 'OMM' ? '4.0' : '6.0';
        $bookVersionId = $this->resolveBookVersionId($manualCode, $versionLabel);
        $sections = array();

        foreach ($excerpts as $excerpt) {
            $sectionRef = trim((string)($excerpt['section_ref'] ?? ''));
            $rendered = $this->renderExcerptPreview($bookVersionId, $excerpt, $manualCode, $versionLabel);
            $sections[] = array(
                'excerpt_key' => (string)($excerpt['excerpt_key'] ?? ''),
                'section_ref' => $sectionRef,
                'title' => trim((string)($excerpt['title'] ?? '')),
                'label' => $manualCode . ' ' . $versionLabel . ' Part ' . trim((string)($excerpt['manual_part'] ?? '')) . ' §' . $sectionRef,
                'html' => $rendered['html'],
                'scroll_anchor' => $rendered['scroll_anchor'],
                'highlight' => $sectionRef,
            );
        }

        return array(
            'ok' => true,
            'book_label' => $manualCode . ' Rev ' . $versionLabel,
            'sections' => $sections,
            'requirement' => $summary,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function coveragePair(int $requirementId): array
    {
        $reg = $this->regulationPreview($requirementId);
        $manual = $this->manualPreview($requirementId);
        $requirement = $this->requirement($requirementId);

        return array(
            'ok' => true,
            'requirement_id' => $requirementId,
            'subject' => is_array($requirement) ? (string)($requirement['subject'] ?? '') : '',
            'item_ref' => is_array($requirement)
                ? ControlledPublishingMccfBrowserService::formatItemRef($requirement)
                : '',
            'requirement' => is_array($requirement) ? $this->requirementSummary($requirement) : array(),
            'integrity' => is_array($requirement) ? $this->integrityForRequirement($requirementId, $requirement) : null,
            'regulation' => $reg,
            'manual' => $manual,
        );
    }

    /**
     * @param array<string,mixed> $requirement
     * @return list<array{token:string,role:string,prefix:?string}>
     */
    private function resolveRuleTokens(array $requirement, string $ruleTokenHint): array
    {
        $parsed = ControlledPublishingMccfRegulationLinkService::parseRegulationRef(
            (string)($requirement['regulation_ref'] ?? '')
        );

        $hint = trim($ruleTokenHint);
        if ($hint !== '') {
            $hintNorm = ControlledPublishingMccfRegulationLinkService::normalizeRuleToken($hint);
            $matched = false;
            foreach ($parsed as $row) {
                if ($this->tokensMatch((string)$row['token'], $hintNorm)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched && $hintNorm !== '') {
                array_unshift($parsed, array(
                    'token' => $hintNorm,
                    'role' => 'PRIMARY',
                    'prefix' => ControlledPublishingMccfRegulationLinkService::rulePrefix($hintNorm),
                    'citation' => $hint,
                ));
            }

            usort($parsed, function (array $a, array $b) use ($hintNorm): int {
                $aMatch = $this->tokensMatch((string)$a['token'], $hintNorm) ? 0 : 1;
                $bMatch = $this->tokensMatch((string)$b['token'], $hintNorm) ? 0 : 1;
                if ($aMatch !== $bMatch) {
                    return $aMatch <=> $bMatch;
                }
                $roleOrder = array('PRIMARY' => 0, 'AMC' => 1, 'GM' => 2);
                return ($roleOrder[$a['role'] ?? 'PRIMARY'] ?? 9) <=> ($roleOrder[$b['role'] ?? 'PRIMARY'] ?? 9);
            });
        }

        return $parsed;
    }

    /**
     * @param list<array{token:string,role:string,prefix:?string}> $tokens
     * @return array{ok:true,node:array<string,mixed>}|null
     */
    private function resolveRegulationNodeDetail(int $requirementId, array $tokens): ?array
    {
        foreach ($this->allRegulationLinks($requirementId) as $link) {
            if (($link['match_confidence'] ?? '') === 'UNRESOLVED') {
                continue;
            }
            $detail = $this->nodeDetailFromIds(
                (int)($link['target_batch_id'] ?? 0),
                (string)($link['target_node_uid'] ?? '')
            );
            if ($detail !== null) {
                return $detail;
            }
        }

        $regSvc = new ControlledPublishingMccfRegulationLinkService($this->pdo);
        foreach ($tokens as $tokenRow) {
            $resolved = $regSvc->resolveEasaNode(
                (string)$tokenRow['token'],
                $tokenRow['prefix'] ?? null,
                (string)($tokenRow['role'] ?? 'PRIMARY'),
                (string)($tokenRow['citation'] ?? '')
            );
            if ($resolved === null) {
                continue;
            }
            $detail = $this->nodeDetailFromIds(
                (int)($resolved['batch_id'] ?? 0),
                (string)($resolved['node_uid'] ?? '')
            );
            if ($detail !== null) {
                return $detail;
            }
        }

        foreach ($tokens as $tokenRow) {
            $variants = ControlledPublishingMccfRegulationLinkService::ruleTokenSearchVariants(
                (string)$tokenRow['token'],
                (string)($tokenRow['role'] ?? 'PRIMARY'),
                (string)($tokenRow['citation'] ?? '')
            );
            foreach ($variants as $variant) {
                $hit = $this->searchStagingByToken($variant);
                if ($hit === null) {
                    continue;
                }
                $detail = $this->nodeDetailFromIds(
                    (int)($hit['batch_id'] ?? 0),
                    (string)($hit['node_uid'] ?? '')
                );
                if ($detail !== null) {
                    return $detail;
                }
            }
        }

        return null;
    }

    /**
     * @return array{ok:true,node:array<string,mixed>}|null
     */
    private function nodeDetailFromIds(int $batchId, string $nodeUid): ?array
    {
        if ($batchId <= 0 || $nodeUid === '') {
            return null;
        }

        $detail = rl_easa_api_node_detail_build($this->pdo, $batchId, $nodeUid);
        if (!is_array($detail) || empty($detail['ok']) || !is_array($detail['node'] ?? null)) {
            return null;
        }

        return $detail;
    }

    /**
     * @return array{batch_id:int,node_uid:string}|null
     */
    private function searchStagingByToken(string $token): ?array
    {
        $token = strtoupper(trim($token));
        $token = preg_replace('/\s+/u', ' ', $token) ?? $token;
        if ($token === '') {
            return null;
        }

        try {
            $stmt = $this->pdo->prepare("
                SELECT batch_id, node_uid
                FROM easa_erules_import_nodes_staging
                WHERE title LIKE :like
                   OR source_erules_id LIKE :like
                   OR breadcrumb LIKE :like
                ORDER BY
                  CASE WHEN UPPER(title) LIKE :prefix THEN 0 ELSE 1 END,
                  depth ASC,
                  id ASC
                LIMIT 1
            ");
            $like = '%' . $token . '%';
            $stmt->execute(array(
                ':like' => $like,
                ':prefix' => strtoupper($token) . '%',
            ));
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return is_array($row) ? $row : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param list<array{token:string,role:string,prefix:?string}> $tokens
     */
    private function pickHighlightToken(array $tokens, string $ruleTokenHint): string
    {
        if ($ruleTokenHint !== '') {
            return ControlledPublishingMccfRegulationLinkService::normalizeRuleToken($ruleTokenHint);
        }

        $best = '';
        $bestDepth = -1;
        foreach ($tokens as $tokenRow) {
            $token = (string)($tokenRow['token'] ?? '');
            $depth = substr_count($token, '(');
            if ($depth > $bestDepth) {
                $bestDepth = $depth;
                $best = $token;
            }
        }
        if ($best !== '') {
            return $best;
        }

        foreach ($tokens as $tokenRow) {
            if (($tokenRow['role'] ?? '') === 'PRIMARY') {
                return (string)$tokenRow['token'];
            }
        }

        return (string)($tokens[0]['token'] ?? '');
    }

    private function tokensMatch(string $a, string $b): bool
    {
        $a = ControlledPublishingMccfRegulationLinkService::normalizeRuleToken($a);
        $b = ControlledPublishingMccfRegulationLinkService::normalizeRuleToken($b);
        if ($a === '' || $b === '') {
            return false;
        }

        return $a === $b || str_starts_with($a, $b) || str_starts_with($b, $a);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function requirement(int $requirementId): ?array
    {
        if ($requirementId <= 0) {
            return null;
        }

        return (new ControlledPublishingMccfBrowserService($this->pdo))->getRequirement($requirementId);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function linkedExcerpts(int $requirementId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT e.excerpt_key, e.title, e.section_ref, e.manual_part, e.body_text
            FROM ipca_canonical_requirement_excerpt_links l
            INNER JOIN ipca_canonical_excerpts e
              ON e.id = l.excerpt_id AND e.source_status = 'active'
            WHERE l.requirement_id = :requirement_id
              AND l.source_status = 'active'
            ORDER BY e.manual_part, e.section_ref, e.excerpt_key
        ");
        $stmt->execute(array(':requirement_id' => $requirementId));

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function allRegulationLinks(int $requirementId): array
    {
        if (!ControlledPublishingMccfRegulationLinkService::regulationLinksTablePresent($this->pdo)) {
            return array();
        }

        $stmt = $this->pdo->prepare("
            SELECT *
            FROM ipca_canonical_requirement_regulation_links
            WHERE requirement_id = :requirement_id
              AND source_status = 'active'
            ORDER BY FIELD(link_role, 'PRIMARY', 'AMC', 'GM', 'SUPPORTING'), rule_token
        ");
        $stmt->execute(array(':requirement_id' => $requirementId));

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    /**
     * @param array<string,mixed> $excerpt
     * @return array{html:string,scroll_anchor:string}
     */
    private function renderExcerptPreview(
        int $bookVersionId,
        array $excerpt,
        string $manualCode,
        string $versionLabel
    ): array {
        $sectionRef = trim((string)($excerpt['section_ref'] ?? ''));
        $scrollAnchor = '';
        $html = '';

        if ($bookVersionId > 0 && $sectionRef !== '') {
            $match = $this->findBookSectionForRef($bookVersionId, $sectionRef);
            if ($match !== null) {
                $scrollAnchor = (string)($match['scroll_anchor'] ?? '');
                $html = $this->renderBookSectionHtml($bookVersionId, (int)$match['section_id'], $scrollAnchor, $sectionRef);
            }
        }

        if ($html === '') {
            $body = trim((string)($excerpt['body_text'] ?? ''));
            $title = trim((string)($excerpt['title'] ?? ''));
            $label = '§' . $sectionRef . ($title !== '' ? (' — ' . $title) : '');
            $scrollAnchor = 'excerpt-' . preg_replace('/[^a-z0-9]+/i', '-', $sectionRef);
            $html = '<article class="mccf-reader-fallback" id="' . h($scrollAnchor) . '">'
                . '<h4>' . h($label) . '</h4>'
                . '<div class="mccf-reader-fallback-body">' . ControlledPublishingMccfEasaPreviewRenderer::plainTextHtml($body) . '</div>'
                . '</article>';
        }

        return array(
            'html' => '<div class="mccf-reader-section" data-book="' . h($manualCode . ' ' . $versionLabel) . '">' . $html . '</div>',
            'scroll_anchor' => $scrollAnchor,
        );
    }

    /**
     * @return array{section_id:int,scroll_anchor:string}|null
     */
    private function findBookSectionForRef(int $bookVersionId, string $sectionRef): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT b.section_id, b.stable_anchor, b.payload_json
            FROM ipca_publishing_book_blocks b
            WHERE b.book_version_id = :version_id
            ORDER BY b.section_id, b.sort_order, b.id
        ");
        $stmt->execute(array(':version_id' => $bookVersionId));

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row) {
            $payload = json_decode((string)($row['payload_json'] ?? ''), true);
            if (!is_array($payload)) {
                continue;
            }
            $canonRef = trim((string)($payload['canonical_section_ref'] ?? ''));
            if ($canonRef !== '' && strcasecmp(rtrim($canonRef, '.'), rtrim($sectionRef, '.')) === 0) {
                return array(
                    'section_id' => (int)$row['section_id'],
                    'scroll_anchor' => (string)($row['stable_anchor'] ?? ''),
                );
            }
        }

        return null;
    }

    private function renderBookSectionHtml(
        int $bookVersionId,
        int $sectionId,
        string $scrollAnchor,
        string $sectionRef
    ): string {
        $foundation = new ControlledPublishingFoundationService($this->pdo);
        $sections = new ControlledPublishingSectionService($this->pdo);
        $blocks = new ControlledPublishingBlockService($this->pdo);
        $renderer = new ControlledPublishingBookRenderer();
        $styleSvc = new ControlledPublishingBookStyleService($this->pdo);
        $numberSvc = new ControlledPublishingSectionNumberService($this->pdo, $blocks);
        $revision = new ControlledPublishingRevisionService($this->pdo);

        $version = $foundation->getVersion($bookVersionId);
        $section = $sections->getSection($bookVersionId, $sectionId);
        if ($version === null || $section === null) {
            return '';
        }

        $bookStyles = $styleSvc->resolveFromVersion($version);
        $renderer->setBookStyles($bookStyles, $styleSvc);
        $computed = $numberSvc->computeForVersion($bookVersionId, (string)($version['manual_code'] ?? ''));
        $renderer->setSectionNumbers($computed['display'], $computed['suggested_regulatory_refs'], $numberSvc);

        $sectionBlocks = $revision->annotateChangeStatus($bookVersionId, $blocks->listSectionBlocks($sectionId));
        $blocksHtml = $renderer->renderBlocks($sectionBlocks, ControlledPublishingBookRenderer::MODE_READ);
        $blocksHtml = $this->markScrollTarget($blocksHtml, $scrollAnchor, $sectionRef);

        return '<div class="mccf-reader-document cpb-editor-root">'
            . '<div class="mccf-reader-section-head"><strong>' . h((string)($section['title'] ?? 'Manual section')) . '</strong></div>'
            . '<div class="cpb-page-canvas mccf-reader-canvas">' . $blocksHtml . '</div>'
            . '</div>';
    }

    private function markScrollTarget(string $html, string $scrollAnchor, string $sectionRef): string
    {
        if ($scrollAnchor !== '') {
            $pattern = '/(\sid="' . preg_quote($scrollAnchor, '/') . '")/i';
            if (preg_match($pattern, $html)) {
                return preg_replace(
                    $pattern,
                    ' id="' . $scrollAnchor . '" data-mccf-highlight="1"',
                    $html,
                    1
                ) ?? $html;
            }
            $pattern = '/(data-stable-anchor="' . preg_quote($scrollAnchor, '/') . '")/i';
            if (preg_match($pattern, $html)) {
                return preg_replace(
                    $pattern,
                    ' data-stable-anchor="' . $scrollAnchor . '" data-mccf-highlight="1"',
                    $html,
                    1
                ) ?? $html;
            }
        }

        if ($sectionRef !== '') {
            return ControlledPublishingMccfEasaPreviewRenderer::highlightToken($html, $sectionRef);
        }

        return $html;
    }

    /**
     * @param array<string,mixed> $requirement
     * @return array<string,mixed>
     */
    private function requirementSummary(array $requirement): array
    {
        return array(
            'subject' => trim((string)($requirement['subject'] ?? '')),
            'requirement_text' => trim((string)($requirement['requirement_text'] ?? '')),
            'applicable' => trim((string)($requirement['applicable'] ?? '')),
            'regulation_ref' => trim((string)($requirement['regulation_ref'] ?? '')),
            'manual_section_ref' => trim((string)($requirement['manual_section_ref'] ?? '')),
            'item_ref' => ControlledPublishingMccfBrowserService::formatItemRef($requirement),
        );
    }

    /**
     * @param array<string,mixed> $requirement
     * @return array<string,mixed>
     */
    private function integrityForRequirement(int $requirementId, array $requirement): array
    {
        $excerpts = $this->linkedExcerpts($requirementId);
        $regLinks = $this->allRegulationLinks($requirementId);
        $manualCode = strtoupper(trim((string)($requirement['manual_code'] ?? 'OM')));
        $versionLabel = $manualCode === 'OMM' ? '4.0' : '6.0';
        $bookVersionId = $this->resolveBookVersionId($manualCode, $versionLabel);
        $score = (new ControlledPublishingMccfIntegrityService($this->pdo))
            ->scoreRequirement($requirement, $excerpts, $regLinks, $bookVersionId);

        return array(
            'score' => (int)($score['score'] ?? 0),
            'label' => (string)($score['label'] ?? ''),
            'tone' => (string)($score['tone'] ?? 'muted'),
            'reasons' => is_array($score['reasons'] ?? null) ? $score['reasons'] : array(),
        );
    }

    private function resolveBookVersionId(string $manualCode, string $versionLabel): int
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT bv.id
                FROM ipca_publishing_book_versions bv
                INNER JOIN ipca_publishing_books b ON b.id = bv.book_id
                WHERE b.book_key = :book_key
                  AND bv.version_label = :version_label
                ORDER BY bv.id DESC
                LIMIT 1
            ");
            $stmt->execute(array(
                ':book_key' => strtoupper(trim($manualCode)),
                ':version_label' => $versionLabel,
            ));

            return (int)$stmt->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }
}
