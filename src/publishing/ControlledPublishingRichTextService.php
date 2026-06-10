<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingBlockService.php';
require_once __DIR__ . '/ControlledPublishingSectionService.php';
require_once __DIR__ . '/ControlledPublishingHtmlSanitizer.php';

/**
 * Enriches manual body text with annex links and external URLs; detects callout prefixes.
 */
final class ControlledPublishingRichTextService
{
    /** @var array<int, array<int, int>> */
    private array $annexMapCache = array();

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{callout_type:string,title:string,text:string}|null
     */
    public static function parseLeadingCallout(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        if (preg_match('/^Note(?:\s+(\d+))?\s*:\s*(.*)$/uis', $text, $matches) === 1) {
            $number = trim((string)($matches[1] ?? ''));
            $body = trim((string)($matches[2] ?? ''));
            return array(
                'callout_type' => 'note',
                'title' => $number !== '' ? 'NOTE ' . $number : 'NOTE',
                'text' => $body,
            );
        }

        if (preg_match('/^WARNING\s*:\s*(.*)$/uis', $text, $matches) === 1) {
            return array(
                'callout_type' => 'warning',
                'title' => 'WARNING',
                'text' => trim((string)($matches[1] ?? '')),
            );
        }

        if (preg_match('/^CAUTION\s*:\s*(.*)$/uis', $text, $matches) === 1) {
            return array(
                'callout_type' => 'caution',
                'title' => 'CAUTION',
                'text' => trim((string)($matches[1] ?? '')),
            );
        }

        if (preg_match('/^INFO\s*:\s*(.*)$/uis', $text, $matches) === 1) {
            return array(
                'callout_type' => 'info',
                'title' => 'INFO',
                'text' => trim((string)($matches[1] ?? '')),
            );
        }

        return null;
    }

    public function bodyParagraphHtml(string $text, int $versionId): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        return '<p>' . $this->enrichInlineHtml($text, $versionId) . '</p>';
    }

    public function calloutTextHtml(string $text, int $versionId): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        return $this->enrichInlineHtml($text, $versionId);
    }

    public function enrichInlineHtml(string $text, int $versionId): string
    {
        return $this->enrichPlainText($text, $versionId, 'both');
    }

    public function enrichPlainText(string $text, int $versionId, string $kind = 'both'): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        if (str_contains($text, '<')) {
            return $this->enrichHtmlField($text, $versionId, $kind);
        }

        $annexMap = $this->resolveAnnexSectionMap($versionId);
        $pattern = $this->enrichmentPattern($kind);
        if ($pattern === null) {
            return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts) || $parts === array()) {
            return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $html = '';
        foreach ($parts as $part) {
            $part = (string)$part;
            if ($part === '') {
                continue;
            }
            if ($this->shouldEnrichUrl($kind) && self::looksLikeUrl($part)) {
                $html .= $this->externalLinkHtml($part);
                continue;
            }
            $annexNumber = self::extractAnnexNumber($part);
            if ($this->shouldEnrichAnnex($kind) && $annexNumber > 0 && isset($annexMap[$annexNumber])) {
                $html .= $this->annexLinkHtml($part, $annexMap[$annexNumber], $versionId);
                continue;
            }
            $html .= htmlspecialchars($part, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return $html;
    }

    public function enrichHtmlField(string $html, int $versionId, string $kind = 'both'): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        if (!str_contains($html, '<')) {
            return $this->enrichPlainText($html, $versionId, $kind);
        }

        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $wrapped = '<?xml encoding="utf-8" ?><div>' . $html . '</div>';
        $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $root = $doc->getElementsByTagName('div')->item(0);
        if ($root === null) {
            return ControlledPublishingHtmlSanitizer::sanitizeInline(
                $this->enrichPlainText(strip_tags($html), $versionId, $kind)
            );
        }

        $this->enrichDomTextNodes($root, $versionId, $kind, $doc);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return ControlledPublishingHtmlSanitizer::sanitizeInline(trim($out));
    }

    /**
     * @return array{enriched:int,skipped?:bool}
     */
    public function enrichRichTextInSection(
        int $versionId,
        int $sectionId,
        ControlledPublishingBlockService $blocks,
        string $kind,
        ?int $actorUserId
    ): array {
        $section = $blocks->getSectionForEditing($versionId, $sectionId);
        if ($section === null || empty($section['allow_author_blocks'])) {
            return array('enriched' => 0, 'skipped' => true);
        }

        $enriched = 0;
        foreach ($blocks->listSectionBlocks($sectionId) as $block) {
            if ($this->enrichBlockPayload($versionId, $block, $blocks, $kind, $actorUserId)) {
                $enriched++;
            }
        }

        return array('enriched' => $enriched);
    }

    /**
     * @return array{enriched:int,sections_scanned:int,sections_updated:int}
     */
    public function enrichRichTextInVersion(
        int $versionId,
        ControlledPublishingBlockService $blocks,
        ControlledPublishingSectionService $sections,
        string $kind,
        ?int $actorUserId
    ): array {
        $enriched = 0;
        $sectionsScanned = 0;
        $sectionsUpdated = 0;

        foreach ($sections->listFlatSections($versionId) as $sectionRow) {
            $sectionId = (int)($sectionRow['id'] ?? 0);
            if ($sectionId <= 0) {
                continue;
            }
            $sectionsScanned++;
            $result = $this->enrichRichTextInSection($versionId, $sectionId, $blocks, $kind, $actorUserId);
            $count = (int)($result['enriched'] ?? 0);
            if ($count > 0) {
                $enriched += $count;
                $sectionsUpdated++;
            }
        }

        return array(
            'enriched' => $enriched,
            'sections_scanned' => $sectionsScanned,
            'sections_updated' => $sectionsUpdated,
        );
    }

    /**
     * @return array<int, int> annex number => section id
     */
    public function resolveAnnexSectionMap(int $versionId): array
    {
        if (isset($this->annexMapCache[$versionId])) {
            return $this->annexMapCache[$versionId];
        }

        $stmt = $this->pdo->prepare("
            SELECT id
            FROM ipca_publishing_book_sections
            WHERE book_version_id = :version_id
              AND section_key = 'annexes'
            LIMIT 1
        ");
        $stmt->execute(array(':version_id' => $versionId));
        $annexParentId = (int)$stmt->fetchColumn();
        if ($annexParentId <= 0) {
            $this->annexMapCache[$versionId] = array();
            return array();
        }

        $childStmt = $this->pdo->prepare("
            SELECT id, title, sort_order
            FROM ipca_publishing_book_sections
            WHERE book_version_id = :version_id
              AND parent_section_id = :parent_id
            ORDER BY sort_order, id
        ");
        $childStmt->execute(array(
            ':version_id' => $versionId,
            ':parent_id' => $annexParentId,
        ));

        $map = array();
        $index = 1;
        foreach ($childStmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row) {
            $sectionId = (int)($row['id'] ?? 0);
            $title = trim((string)($row['title'] ?? ''));
            if ($sectionId <= 0) {
                continue;
            }
            $parsed = self::extractAnnexNumber($title);
            if ($parsed > 0) {
                $map[$parsed] = $sectionId;
            } else {
                $map[$index] = $sectionId;
            }
            $index++;
        }

        $this->annexMapCache[$versionId] = $map;
        return $map;
    }

    private static function extractAnnexNumber(string $text): int
    {
        if (preg_match('/\bAnnex\s+(\d{1,3})\b/i', $text, $matches) !== 1) {
            return 0;
        }

        return (int)$matches[1];
    }

    private static function looksLikeUrl(string $text): bool
    {
        return preg_match('/^(?:https?:\/\/|www\.)\S+$/i', trim($text)) === 1;
    }

    private function externalLinkHtml(string $url): string
    {
        $url = trim($url);
        $href = $url;
        if (preg_match('/^www\./i', $href) === 1) {
            $href = 'https://' . $href;
        }
        if (!preg_match('/^https?:\/\//i', $href)) {
            return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '" class="cpb-external-link" target="_blank" rel="noopener noreferrer">'
            . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</a>';
    }

    private function annexLinkHtml(string $label, int $sectionId, int $versionId): string
    {
        $href = '/admin/compliance/controlled_book_editor.php?version_id=' . $versionId
            . '&section_id=' . $sectionId;

        return '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '" class="cpb-annex-link" data-section-id="' . $sectionId . '">'
            . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</a>';
    }

    public static function plainTextFromBlockHtml(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $html = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * @return array{converted:int,skipped?:bool}
     */
    public function detectCalloutsInSection(
        int $versionId,
        int $sectionId,
        ControlledPublishingBlockService $blocks,
        ?int $actorUserId
    ): array {
        $section = $blocks->getSectionForEditing($versionId, $sectionId);
        if ($section === null || empty($section['allow_author_blocks'])) {
            return array('converted' => 0, 'skipped' => true);
        }

        $converted = 0;
        while (true) {
            $sectionBlocks = $blocks->listSectionBlocks($sectionId);
            $match = $this->findNextCalloutParagraph($sectionBlocks, $blocks);
            if ($match === null) {
                break;
            }

            $blockId = $match['block_id'];
            $newId = $blocks->createBlock(
                $versionId,
                $sectionId,
                'callout',
                array(
                    'callout_type' => $match['callout']['callout_type'],
                    'title' => $match['callout']['title'],
                    'text' => $this->calloutTextHtml($match['callout']['text'], $versionId),
                ),
                $actorUserId
            );

            $newIds = array();
            foreach ($sectionBlocks as $row) {
                $id = (int)$row['id'];
                if ($id === $blockId) {
                    $newIds[] = $newId;
                } elseif ($id !== $newId) {
                    $newIds[] = $id;
                }
            }
            $blocks->reorderBlocks($sectionId, $newIds, $actorUserId);
            $blocks->deleteBlock($blockId, $actorUserId);
            $converted++;
        }

        return array('converted' => $converted);
    }

    /**
     * @return array{converted:int,sections_scanned:int,sections_updated:int}
     */
    public function detectCalloutsInVersion(
        int $versionId,
        ControlledPublishingBlockService $blocks,
        ControlledPublishingSectionService $sections,
        ?int $actorUserId
    ): array {
        $converted = 0;
        $sectionsScanned = 0;
        $sectionsUpdated = 0;

        foreach ($sections->listFlatSections($versionId) as $sectionRow) {
            $sectionId = (int)($sectionRow['id'] ?? 0);
            if ($sectionId <= 0) {
                continue;
            }
            $sectionsScanned++;
            $result = $this->detectCalloutsInSection($versionId, $sectionId, $blocks, $actorUserId);
            $count = (int)($result['converted'] ?? 0);
            if ($count > 0) {
                $converted += $count;
                $sectionsUpdated++;
            }
        }

        return array(
            'converted' => $converted,
            'sections_scanned' => $sectionsScanned,
            'sections_updated' => $sectionsUpdated,
        );
    }

    /**
     * @param list<array<string,mixed>> $sectionBlocks
     * @return array{block_id:int,callout:array{callout_type:string,title:string,text:string}}|null
     */
    private function findNextCalloutParagraph(array $sectionBlocks, ControlledPublishingBlockService $blocks): ?array
    {
        foreach ($sectionBlocks as $block) {
            if ((string)($block['block_type'] ?? '') !== 'paragraph') {
                continue;
            }
            if (!empty($block['is_system_managed'])) {
                continue;
            }

            $payload = $blocks->decodePayload($block);
            if (trim((string)($payload['canonical_section_ref'] ?? '')) !== '') {
                continue;
            }

            $style = strtolower(trim((string)($payload['paragraph_style'] ?? 'body')));
            if ($style !== '' && $style !== 'body') {
                continue;
            }

            $text = self::plainTextFromBlockHtml((string)($payload['html'] ?? ''));
            if ($text === '') {
                $text = trim(strip_tags((string)($payload['text'] ?? '')));
            }

            $callout = self::parseLeadingCallout($text);
            if ($callout === null) {
                continue;
            }

            return array(
                'block_id' => (int)$block['id'],
                'callout' => $callout,
            );
        }

        return null;
    }

    /**
     * @param array<string,mixed> $block
     */
    private function enrichBlockPayload(
        int $versionId,
        array $block,
        ControlledPublishingBlockService $blocks,
        string $kind,
        ?int $actorUserId
    ): bool {
        $blockType = (string)($block['block_type'] ?? '');
        $payload = $blocks->decodePayload($block);
        $updated = false;

        if ($blockType === 'paragraph') {
            $html = (string)($payload['html'] ?? '');
            if ($html !== '') {
                $nextHtml = $this->enrichHtmlField($html, $versionId, $kind);
                if ($nextHtml !== $html) {
                    $payload['html'] = $nextHtml;
                    $updated = true;
                }
            }
        } elseif ($blockType === 'callout') {
            $text = (string)($payload['text'] ?? '');
            if ($text !== '') {
                $nextText = $this->enrichHtmlField($text, $versionId, $kind);
                if ($nextText !== $text) {
                    $payload['text'] = $nextText;
                    $updated = true;
                }
            }
        } elseif ($blockType === 'table') {
            $updated = $this->enrichTablePayload($payload, $versionId, $kind);
        }

        if (!$updated) {
            return false;
        }

        $blocks->updateBlock((int)$block['id'], $payload, $actorUserId);
        return true;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function enrichTablePayload(array &$payload, int $versionId, string $kind): bool
    {
        $updated = false;

        $title = (string)($payload['title'] ?? '');
        if ($title !== '') {
            $nextTitle = $this->enrichHtmlField($title, $versionId, $kind);
            if ($nextTitle !== $title) {
                $payload['title'] = $nextTitle;
                $updated = true;
            }
        }

        if (is_array($payload['headers'] ?? null)) {
            foreach ($payload['headers'] as $index => $cell) {
                $cell = (string)$cell;
                if ($cell === '') {
                    continue;
                }
                $nextCell = $this->enrichHtmlField($cell, $versionId, $kind);
                if ($nextCell !== $cell) {
                    $payload['headers'][$index] = $nextCell;
                    $updated = true;
                }
            }
        }

        if (is_array($payload['rows'] ?? null)) {
            foreach ($payload['rows'] as $rowIndex => $row) {
                if (!is_array($row)) {
                    continue;
                }
                foreach ($row as $cellIndex => $cell) {
                    $cell = (string)$cell;
                    if ($cell === '') {
                        continue;
                    }
                    $nextCell = $this->enrichHtmlField($cell, $versionId, $kind);
                    if ($nextCell !== $cell) {
                        $payload['rows'][$rowIndex][$cellIndex] = $nextCell;
                        $updated = true;
                    }
                }
            }
        }

        return $updated;
    }

    private function enrichDomTextNodes(DOMNode $node, int $versionId, string $kind, DOMDocument $doc): void
    {
        if (!$node->hasChildNodes()) {
            return;
        }

        $textNodes = array();
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'a') {
                continue;
            }
            if ($child instanceof DOMText) {
                $textNodes[] = $child;
                continue;
            }
            if ($child instanceof DOMElement) {
                $this->enrichDomTextNodes($child, $versionId, $kind, $doc);
            }
        }

        foreach ($textNodes as $textNode) {
            $text = (string)($textNode->textContent ?? '');
            if ($text === '') {
                continue;
            }
            $enriched = $this->enrichPlainText($text, $versionId, $kind);
            $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if ($enriched === $escaped) {
                continue;
            }

            $fragment = $doc->createDocumentFragment();
            $tmp = new DOMDocument();
            $prev = libxml_use_internal_errors(true);
            $tmp->loadHTML(
                '<?xml encoding="utf-8" ?><div>' . $enriched . '</div>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
            );
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
            $tmpRoot = $tmp->getElementsByTagName('div')->item(0);
            if ($tmpRoot === null) {
                continue;
            }
            foreach ($tmpRoot->childNodes as $imported) {
                $fragment->appendChild($doc->importNode($imported, true));
            }
            $parent = $textNode->parentNode;
            if ($parent !== null) {
                $parent->replaceChild($fragment, $textNode);
            }
        }
    }

    private function enrichmentPattern(string $kind): ?string
    {
        $kind = strtolower(trim($kind));
        if ($kind === 'hyperlinks' || $kind === 'urls') {
            return '/((?:https?:\/\/|www\.)[^\s<>"\'\]]+)/iu';
        }
        if ($kind === 'annex_refs' || $kind === 'annex') {
            return '/(\b(?:OM|OMM)\s+Annex\s+\d{1,3}(?:\s*[–\-—]\s*[^\.,;\n]+)?|\bAnnex\s+\d{1,3}(?:\s*[–\-—]\s*[^\.,;\n]+)?)/iu';
        }
        if ($kind === 'both') {
            return '/('
                . '\b(?:OM|OMM)\s+Annex\s+\d{1,3}(?:\s*[–\-—]\s*[^\.,;\n]+)?'
                . '|\bAnnex\s+\d{1,3}(?:\s*[–\-—]\s*[^\.,;\n]+)?'
                . '|(?:https?:\/\/|www\.)[^\s<>"\'\]]+'
                . ')/iu';
        }

        return null;
    }

    private function shouldEnrichUrl(string $kind): bool
    {
        $kind = strtolower(trim($kind));
        return $kind === 'both' || $kind === 'hyperlinks' || $kind === 'urls';
    }

    private function shouldEnrichAnnex(string $kind): bool
    {
        $kind = strtolower(trim($kind));
        return $kind === 'both' || $kind === 'annex_refs' || $kind === 'annex';
    }
}
