<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingMccfBrowserService.php';
require_once __DIR__ . '/ControlledPublishingMccfRegulationLinkService.php';
require_once __DIR__ . '/ControlledPublishingFoundationService.php';
require_once __DIR__ . '/ControlledPublishingSectionService.php';
require_once __DIR__ . '/ControlledPublishingBlockService.php';
require_once __DIR__ . '/ControlledPublishingBookRenderer.php';
require_once __DIR__ . '/ControlledPublishingBookStyleService.php';
require_once __DIR__ . '/ControlledPublishingSectionNumberService.php';
require_once __DIR__ . '/ControlledPublishingRevisionService.php';

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

        $ruleToken = ControlledPublishingMccfRegulationLinkService::normalizeRuleToken(
            $ruleToken !== '' ? $ruleToken : (string)($requirement['regulation_ref'] ?? '')
        );
        if ($ruleToken === '') {
            return array('ok' => false, 'error' => 'No regulation reference on this requirement.');
        }

        $link = $this->resolveRegulationLink($requirementId, $ruleToken);
        $node = null;
        if ($link !== null) {
            $node = $this->loadEasaNode(
                (int)($link['target_batch_id'] ?? 0),
                (string)($link['target_node_uid'] ?? '')
            );
        }
        if ($node === null) {
            $regSvc = new ControlledPublishingMccfRegulationLinkService($this->pdo);
            $resolved = $regSvc->resolveEasaNode($ruleToken);
            if ($resolved !== null) {
                $node = $this->loadEasaNode(
                    (int)($resolved['batch_id'] ?? 0),
                    (string)($resolved['node_uid'] ?? '')
                );
            }
        }

        $title = $ruleToken;
        $body = '';
        if ($node !== null) {
            $title = trim((string)($node['title'] ?? $ruleToken));
            $body = trim((string)($node['plain_text'] ?? ''));
            if ($body === '') {
                $body = trim((string)($node['breadcrumb'] ?? ''));
            }
        }
        if ($body === '') {
            $body = 'Regulation source text is not available in the EASA staging library yet.';
        }

        return array(
            'ok' => true,
            'title' => $title,
            'subtitle' => (string)($requirement['regulation_ref'] ?? $ruleToken),
            'html' => $this->highlightPlainText($body, $ruleToken),
            'highlight' => $ruleToken,
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

        $excerpts = $this->linkedExcerpts($requirementId);
        if ($excerpts === array()) {
            return array(
                'ok' => false,
                'error' => 'No linked OM section for this requirement.',
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
                'label' => 'OM ' . $versionLabel . ' Part ' . trim((string)($excerpt['manual_part'] ?? '')) . ' §' . $sectionRef,
                'html' => $rendered['html'],
                'scroll_anchor' => $rendered['scroll_anchor'],
                'highlight' => $sectionRef,
            );
        }

        return array(
            'ok' => true,
            'book_label' => $manualCode . ' Rev ' . $versionLabel,
            'sections' => $sections,
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
            'regulation' => $reg,
            'manual' => $manual,
        );
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
     * @return array<string,mixed>|null
     */
    private function resolveRegulationLink(int $requirementId, string $ruleToken): ?array
    {
        if (!ControlledPublishingMccfRegulationLinkService::regulationLinksTablePresent($this->pdo)) {
            return null;
        }

        $stmt = $this->pdo->prepare("
            SELECT *
            FROM ipca_canonical_requirement_regulation_links
            WHERE requirement_id = :requirement_id
              AND rule_token = :rule_token
              AND source_status = 'active'
            LIMIT 1
        ");
        $stmt->execute(array(
            ':requirement_id' => $requirementId,
            ':rule_token' => $ruleToken,
        ));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadEasaNode(int $batchId, string $nodeUid): ?array
    {
        if ($batchId <= 0 || $nodeUid === '') {
            return null;
        }

        try {
            $stmt = $this->pdo->prepare("
                SELECT batch_id, node_uid, title, breadcrumb, plain_text, source_erules_id
                FROM easa_erules_import_nodes_staging
                WHERE batch_id = :batch_id AND node_uid = :node_uid
                LIMIT 1
            ");
            $stmt->execute(array(
                ':batch_id' => $batchId,
                ':node_uid' => $nodeUid,
            ));
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return is_array($row) ? $row : null;
        } catch (Throwable) {
            return null;
        }
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
                . '<div class="mccf-reader-fallback-body">' . $this->highlightPlainText($body, $sectionRef) . '</div>'
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
            return $this->highlightHtmlToken($html, $sectionRef);
        }

        return $html;
    }

    private function highlightPlainText(string $text, string $token): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $lines = preg_split('/\R/u', $escaped) ?: array($escaped);
        $out = array();
        $tokenNorm = strtoupper(trim($token));
        foreach ($lines as $line) {
            if ($tokenNorm !== '' && stripos(html_entity_decode($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $tokenNorm) !== false) {
                $out[] = '<p class="mccf-reader-line mccf-hl-line">' . $this->wrapToken($line, $token) . '</p>';
            } else {
                $out[] = '<p class="mccf-reader-line">' . $line . '</p>';
            }
        }

        return implode("\n", $out);
    }

    private function wrapToken(string $escapedLine, string $token): string
    {
        if ($token === '') {
            return $escapedLine;
        }

        return preg_replace(
            '/(' . preg_quote($token, '/') . ')/iu',
            '<mark class="mccf-hl">$1</mark>',
            $escapedLine
        ) ?? $escapedLine;
    }

    private function highlightHtmlToken(string $html, string $token): string
    {
        if ($token === '') {
            return $html;
        }

        return preg_replace(
            '/(' . preg_quote($token, '/') . ')/iu',
            '<mark class="mccf-hl">$1</mark>',
            $html
        ) ?? $html;
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
