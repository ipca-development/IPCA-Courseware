<?php
declare(strict_types=1);

/**
 * Reads Apple Pages / Word DOCX exports into ordered content nodes for manual import.
 */
final class ControlledPublishingDocxReader
{
    /** Max top-level chapter number (e.g. "12. INTRODUCTION") in Parts 1–4. */
    public const MAX_CHAPTER_NUMBER = 30;

    private const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const R_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const A_NS = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    private const WP_NS = 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing';

    /** @var list<string> */
    private const TOC_STYLE_PREFIXES = array('TOC', 'toc');

    /** @var array<string,string> */
    private array $relationships = array();

    /** @var array<string,string> */
    private array $styleNames = array();

    /** @var ZipArchive|null */
    private ?ZipArchive $zip = null;

    /**
     * @return array{
     *   manual_part:int,
     *   nodes:list<array<string,mixed>>,
     *   warnings:list<string>
     * }
     */
    public function parseFile(string $path, ?int $manualPart = null): array
    {
        if (!is_readable($path)) {
            throw new RuntimeException('DOCX file is not readable: ' . $path);
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Could not open DOCX archive: ' . basename($path));
        }

        if ($manualPart === null || $manualPart < 0) {
            $manualPart = self::detectManualPartFromFilename($path);
        }

        $this->zip = $zip;
        $this->relationships = $this->loadRelationships('word/_rels/document.xml.rels');
        $this->styleNames = $this->loadStyleNames();

        $documentXml = $zip->getFromName('word/document.xml');
        if (!is_string($documentXml) || $documentXml === '') {
            $zip->close();
            throw new RuntimeException('Missing word/document.xml in DOCX.');
        }

        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        if (@$dom->loadXML($documentXml) !== true) {
            $zip->close();
            throw new RuntimeException('Invalid document.xml in DOCX.');
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', self::W_NS);
        $xpath->registerNamespace('a', self::A_NS);
        $xpath->registerNamespace('wp', self::WP_NS);
        $xpath->registerNamespace('r', self::R_NS);

        $body = $xpath->query('/w:document/w:body')->item(0);
        if (!$body instanceof DOMElement) {
            $zip->close();
            throw new RuntimeException('DOCX body element not found.');
        }

        $warnings = array();
        $nodes = array();
        $pastToc = false;

        foreach ($body->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            if ($child->namespaceURI !== self::W_NS) {
                continue;
            }

            if ($child->localName === 'p') {
                $paragraph = $this->parseParagraph($child, $xpath, $manualPart);
                if ($paragraph === null) {
                    continue;
                }

                if (!$pastToc) {
                    if ($this->isTocParagraph($paragraph)) {
                        continue;
                    }
                    if ($this->isRealContentStart($paragraph)) {
                        $pastToc = true;
                    } else {
                        continue;
                    }
                }

                if (!empty($paragraph['images'])) {
                    foreach ($paragraph['images'] as $image) {
                        $nodes[] = array(
                            'type' => 'image',
                            'bytes' => $image['bytes'],
                            'mime' => $image['mime'],
                            'ext' => $image['ext'],
                            'alt' => $image['alt'],
                            'width_pct' => $image['width_pct'],
                        );
                    }
                }

                if (($paragraph['text'] ?? '') !== '') {
                    $nodes[] = array(
                        'type' => 'paragraph',
                        'text' => $paragraph['text'],
                        'style_id' => $paragraph['style_id'],
                        'style_name' => $paragraph['style_name'],
                        'is_bullet' => $paragraph['is_bullet'],
                        'section_ref' => $paragraph['section_ref'],
                        'section_title' => $paragraph['section_title'],
                        'paragraph_style' => $paragraph['paragraph_style'],
                    );
                }
                continue;
            }

            if ($child->localName === 'tbl') {
                if (!$pastToc) {
                    continue;
                }
                $table = $this->parseTable($child, $xpath);
                if ($table !== null) {
                    $nodes[] = $table;
                }
                continue;
            }

            if ($child->localName === 'sectPr') {
                continue;
            }

            $warnings[] = 'Skipped unsupported body element: ' . $child->localName;
        }

        $zip->close();
        $this->zip = null;

        return array(
            'manual_part' => $manualPart,
            'nodes' => $nodes,
            'warnings' => $warnings,
        );
    }

    public static function detectManualPartFromFilename(string $path): int
    {
        $name = basename($path);
        if (preg_match('/Part\s*(\d+)/i', $name, $m) === 1) {
            return (int)$m[1];
        }
        return -1;
    }

    /**
     * @param array<string,mixed> $paragraph
     */
    private function isTocParagraph(array $paragraph): bool
    {
        $styleId = (string)($paragraph['style_id'] ?? '');
        $styleName = (string)($paragraph['style_name'] ?? '');
        foreach (self::TOC_STYLE_PREFIXES as $prefix) {
            if (str_starts_with($styleId, $prefix) || str_starts_with($styleName, $prefix)) {
                return true;
            }
        }
        if (str_contains(strtolower($styleId), 'toc') || str_contains(strtolower($styleName), 'toc')) {
            return true;
        }
        return false;
    }

    /**
     * @param array<string,mixed> $paragraph
     */
    private function isRealContentStart(array $paragraph): bool
    {
        $styleId = strtolower((string)($paragraph['style_id'] ?? ''));
        $styleName = strtolower((string)($paragraph['style_name'] ?? ''));
        if ($styleId === 'titel' || $styleName === 'titel' || $styleName === 'title') {
            return true;
        }
        if (str_starts_with($styleId, 'koptekst') || str_starts_with($styleName, 'koptekst')) {
            return true;
        }
        if (str_starts_with($styleName, 'heading') || str_starts_with($styleId, 'heading')) {
            return true;
        }
        if (($paragraph['section_ref'] ?? '') !== '') {
            return true;
        }
        return false;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function parseParagraph(DOMElement $p, DOMXPath $xpath, int $manualPart): ?array
    {
        $styleId = '';
        $styleName = '';
        $isBullet = false;

        $pPr = null;
        foreach ($p->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'pPr' && $child->namespaceURI === self::W_NS) {
                $pPr = $child;
                break;
            }
        }

        if ($pPr instanceof DOMElement) {
            foreach ($pPr->childNodes as $child) {
                if (!$child instanceof DOMElement || $child->namespaceURI !== self::W_NS) {
                    continue;
                }
                if ($child->localName === 'pStyle') {
                    $styleId = trim((string)$child->getAttribute('w:val'));
                    if ($styleId === '') {
                        $styleId = trim((string)$child->getAttribute('val'));
                    }
                    $styleName = $this->styleNames[$styleId] ?? $styleId;
                }
                if ($child->localName === 'numPr') {
                    $isBullet = true;
                }
            }
        }

        if (!$isBullet) {
            $styleLower = strtolower($styleName . ' ' . $styleId);
            if (str_contains($styleLower, 'opsomming') || str_contains($styleLower, 'bullet') || str_contains($styleLower, 'list paragraph')) {
                $isBullet = true;
            }
        }

        $text = $this->extractParagraphText($p);
        $images = $this->extractParagraphImages($p, $xpath, $text);

        $sectionRef = '';
        $sectionTitle = '';
        $paragraphStyle = 'body';
        if ($text !== '' && !$isBullet) {
            $parsed = self::parseSectionHeading($text);
            if ($parsed !== null && self::isPlausibleManualSectionRef($parsed['section_ref'], $parsed['title'], $manualPart)) {
                $sectionRef = $parsed['section_ref'];
                $sectionTitle = $parsed['title'];
                $paragraphStyle = self::sectionRefToParagraphStyle($sectionRef);
            }
        }

        if ($text === '' && $images === array()) {
            return null;
        }

        return array(
            'text' => $text,
            'style_id' => $styleId,
            'style_name' => $styleName,
            'is_bullet' => $isBullet,
            'section_ref' => $sectionRef,
            'section_title' => $sectionTitle,
            'paragraph_style' => $paragraphStyle,
            'images' => $images,
        );
    }

    /**
     * @return array{section_ref:string,title:string}|null
     */
    public static function parseSectionHeading(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        if (preg_match('/^(\d+(?:\.\d+)*)\.?\s+(.+)$/u', $text, $m) !== 1) {
            return null;
        }
        $ref = trim($m[1], '.');
        $title = self::sanitizeSectionTitle(trim($m[2]));
        if ($ref === '' || $title === '') {
            return null;
        }
        if (self::isLikelyTableOrMeasurementExcerpt($ref, $title)) {
            return null;
        }
        return array(
            'section_ref' => $ref,
            'title' => $title,
        );
    }

    /**
     * Table rows and height/speed minima lines (e.g. "51. FT – 100 FT | 400 M") are not manual sections.
     */
    public static function isLikelyTableOrMeasurementExcerpt(string $sectionRef, string $title): bool
    {
        $sectionRef = trim($sectionRef);
        $title = trim($title);
        if ($sectionRef === '' && $title === '') {
            return false;
        }

        if (self::isLikelyMeasurementOrIdLine($sectionRef, $title)) {
            return true;
        }

        if (preg_match('/^\d+(?:\.\d+)*\.?\s+\d+\s*ft\b/iu', $sectionRef . ' ' . $title)) {
            return true;
        }
        if (preg_match('/\b\d+\s*ft\b\s*[|–—-]/iu', $title)) {
            return true;
        }
        if (preg_match('/\b\d+\s*M\s*\(\s*\d+\s*M\b/i', $title)) {
            return true;
        }

        if (preg_match('/^(\d+)\.(\d+)$/', $sectionRef, $m)) {
            $leaf = (int)$m[2];
            if ($leaf > 11 && !self::isChapterLevelTitle($title)) {
                return true;
            }
        }
        if (preg_match('/^(\d+)$/', $sectionRef, $m) && (int)$m[1] > self::MAX_CHAPTER_NUMBER) {
            return true;
        }

        return false;
    }

    /**
     * Strip Word field/bookmark noise and page-number suffixes from heading titles.
     */
    public static function sanitizeSectionTitle(string $title): string
    {
        $title = trim($title);
        if ($title === '') {
            return '';
        }

        // Word bookmark / cross-ref suffixes such as "Electronic Mass & Balance-92178-".
        $title = preg_replace('/-\d{4,}-?\s*$/u', '', $title) ?? $title;
        $title = preg_replace('/-\d{4,}-(?=\s|$)/u', ' ', $title) ?? $title;

        // Trailing page numbers separated by whitespace (e.g. "INTRODUCTION 12"), not model codes like G1000.
        $title = preg_replace('/\s+\d{1,3}$/u', '', $title) ?? $title;
        $title = preg_replace('/\s+-\s*$/u', '', $title) ?? $title;

        return self::sanitizeImportedText($title);
    }

    /**
     * Remove Word field/bookmark artifacts and orphan numeric IDs from imported body text.
     */
    public static function sanitizeImportedText(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        // Trailing cross-ref suffixes such as "**-116740251973" or "-116740251973".
        $text = preg_replace('/\*\*-?\d{8,}\s*$/u', '**', $text) ?? $text;
        $text = preg_replace('/(?<=\S)-\d{8,}(?=\s*$)/u', '', $text) ?? $text;

        // Standalone paragraphs that are only a long numeric Word internal id.
        if (preg_match('/^\d{10,}\s*$/u', $text)) {
            return '';
        }

        // Trailing orphan ids separated by whitespace from real content.
        $text = preg_replace('/\s+\d{10,}\s*$/u', '', $text) ?? $text;

        return trim($text);
    }

    public static function isPlausibleManualSectionRef(string $sectionRef, string $title, int $manualPart = -1): bool
    {
        $sectionRef = trim($sectionRef);
        $title = trim($title);
        if ($sectionRef === '' || $title === '') {
            return false;
        }

        if (preg_match('/^\d+\.\d{3,}$/', $sectionRef)) {
            return false;
        }
        if (preg_match('/^\d{4,}$/', $sectionRef)) {
            return false;
        }

        $segments = explode('.', $sectionRef);
        foreach ($segments as $segment) {
            if ($segment === '' || !ctype_digit($segment)) {
                return false;
            }
            if (strlen($segment) > 3 || (int)$segment > 999) {
                return false;
            }
        }

        $top = (int)($segments[0] ?? 0);
        $isPart0Ref = $top === 0 || str_starts_with($sectionRef, '0.');

        if ($manualPart === 0 || ($manualPart < 0 && $isPart0Ref)) {
            return $isPart0Ref;
        }

        if ($manualPart > 0) {
            if ($top === 0 || $isPart0Ref) {
                return false;
            }
            if (count($segments) === 1) {
                if ($top < 1 || $top > self::MAX_CHAPTER_NUMBER) {
                    return false;
                }
                if (!preg_match('/\p{L}/u', $title)) {
                    return false;
                }
                if (preg_match('/^[\d\s.,\-\/]+$/u', $title)) {
                    return false;
                }
                if (!self::isChapterLevelTitle($title)) {
                    return false;
                }
                if (!self::isPlausibleSubtitleTitle($title)) {
                    return false;
                }
            }
        }

        if (!$isPart0Ref && count($segments) >= 2) {
            $leaf = (int)$segments[count($segments) - 1];
            if ($leaf <= 0 || $leaf > 30) {
                return false;
            }
            // Subtitle 1 entries are chapter.N (e.g. 6.1). Values like 6.12 are usually table rows.
            if ($manualPart > 0 && count($segments) === 2 && $leaf > 11) {
                return false;
            }
            if (count($segments) === 2 && $leaf > 15 && !self::isChapterLevelTitle($title)) {
                return false;
            }
            if (!self::isPlausibleSubtitleTitle($title)) {
                return false;
            }
        }

        if (preg_match('/^\d+\.\d+\s*Kg$/iu', $title) || preg_match('/^Kg$/iu', $title)) {
            return false;
        }
        if (preg_match('/^\d+\s*ft\b/iu', $title)) {
            return false;
        }
        if (self::isLikelyMeasurementOrIdLine($sectionRef, $title)) {
            return false;
        }
        if (self::isLikelyTableOrMeasurementExcerpt($sectionRef, $title)) {
            return false;
        }

        return true;
    }

    /**
     * Chapter titles in OM/OMM use ALL CAPS (e.g. "1. INTRODUCTION").
     * Numbered list items like "1. Personal Data" are rejected.
     */
    public static function isChapterLevelTitle(string $title): bool
    {
        $title = trim($title);
        if ($title === '') {
            return false;
        }
        if (preg_match('/^[a-z]/u', $title)) {
            return false;
        }
        $letters = preg_replace('/[^\p{L}]/u', '', $title) ?? '';
        if ($letters === '') {
            return false;
        }

        return mb_strtoupper($letters, 'UTF-8') === $letters;
    }

    /**
     * Reject table rows and instrument data mis-parsed as subtitle headings.
     */
    public static function isPlausibleSubtitleTitle(string $title): bool
    {
        $title = trim($title);
        if ($title === '') {
            return false;
        }
        if (str_contains($title, '|')) {
            return false;
        }
        if (preg_match('/\b(DEGREES|FEATHERED|LOW PITCH|START LOCK|PITCH LOCK)\b/i', $title)) {
            return false;
        }
        if (preg_match('/\(\s*(LOW PITCH|START LOCK|FEATHERED|PITCH LOCK)\s*\)/i', $title)) {
            return false;
        }
        if (preg_match('/^\d+\s*ft\b/iu', $title)) {
            return false;
        }
        if (preg_match('/\b\d+\s*ft\b\s*[|–—-]/iu', $title)) {
            return false;
        }
        if (preg_match('/\b\d+\s*M\s*\(\s*\d+\s*M\b/i', $title)) {
            return false;
        }
        if (preg_match('/\(\s*\d+\s*M\b/i', $title)) {
            return false;
        }
        if (preg_match('/^[\d\s.,\-\/|()]+$/u', $title)) {
            return false;
        }
        if (!preg_match('/\p{L}/u', $title)) {
            return false;
        }

        return true;
    }

    private static function isLikelyMeasurementOrIdLine(string $sectionRef, string $title): bool
    {
        $combined = $sectionRef . ' ' . $title;
        if (str_contains($title, '|')) {
            return true;
        }
        if (preg_match('/\b(MHz|kHz|ft|KG|Kg|kg|NM|kt|VOR|ILS|DME|NOTAM|DEGREES|FEATHERED)\b/iu', $combined)) {
            return true;
        }
        if (preg_match('/\(\s*\d+\s*M\b/i', $title)) {
            return true;
        }
        if (preg_match('/^\d{10,}$/u', $sectionRef)) {
            return true;
        }
        if (preg_match('/\+?\d[\d\s\-()]{8,}/u', $title)) {
            return true;
        }

        return false;
    }

    public static function sectionRefToParagraphStyle(string $sectionRef): string
    {
        $depth = substr_count($sectionRef, '.') + 1;
        return match ($depth) {
            1 => 'title',
            2 => 'subtitle_1',
            3 => 'subtitle_2',
            4 => 'subtitle_3',
            default => 'subtitle_4',
        };
    }

    private function extractParagraphText(DOMElement $p): string
    {
        $text = $this->extractParagraphTextRaw($p);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return self::sanitizeImportedText($text);
    }

    private function extractParagraphTextRaw(DOMElement $p): string
    {
        $parts = array();
        $this->collectTextFromNode($p, $parts);

        return implode('', $parts);
    }

    /**
     * @param list<string> $parts
     */
    private function collectTextFromNode(DOMNode $node, array &$parts): void
    {
        if ($node instanceof DOMText) {
            $parts[] = $node->data;
            return;
        }
        if (!$node instanceof DOMElement) {
            return;
        }

        if ($node->namespaceURI === self::W_NS && $node->localName === 'tab') {
            $parts[] = "\t";
            return;
        }
        if ($node->namespaceURI === self::W_NS && $node->localName === 'br') {
            $parts[] = "\n";
            return;
        }
        if ($node->namespaceURI === self::W_NS && $node->localName === 't') {
            $parts[] = $node->textContent;
            return;
        }
        if ($node->namespaceURI === self::W_NS && in_array($node->localName, array(
            'instrText',
            'fldChar',
            'bookmarkStart',
            'bookmarkEnd',
            'commentReference',
            'delText',
            'softHyphen',
        ), true)) {
            return;
        }

        foreach ($node->childNodes as $child) {
            $this->collectTextFromNode($child, $parts);
        }
    }

    /**
     * @return list<array{bytes:string,mime:string,ext:string,alt:string,width_pct:int}>
     */
    private function extractParagraphImages(DOMElement $p, DOMXPath $xpath, string $altText): array
    {
        $images = array();
        $blips = $xpath->query('.//a:blip', $p);
        if ($blips === false) {
            return $images;
        }

        foreach ($blips as $blip) {
            if (!$blip instanceof DOMElement) {
                continue;
            }
            $embed = $blip->getAttributeNS(self::R_NS, 'embed');
            if ($embed === '') {
                $embed = $blip->getAttribute('r:embed');
            }
            if ($embed === '') {
                continue;
            }
            $loaded = $this->loadEmbeddedImage($embed, $p, $xpath);
            if ($loaded === null) {
                continue;
            }
            $loaded['alt'] = $altText !== '' ? $altText : 'Manual figure';
            $images[] = $loaded;
        }

        return $images;
    }

    /**
     * @return array{bytes:string,mime:string,ext:string,alt:string,width_pct:int}|null
     */
    private function loadEmbeddedImage(string $relationshipId, DOMElement $context, DOMXPath $xpath): ?array
    {
        if ($this->zip === null) {
            return null;
        }

        $target = $this->relationships[$relationshipId] ?? '';
        if ($target === '') {
            return null;
        }
        $mediaPath = str_starts_with($target, 'word/') ? $target : 'word/' . ltrim($target, '/');
        $bytes = $this->zip->getFromName($mediaPath);
        if (!is_string($bytes) || $bytes === '') {
            return null;
        }

        $ext = strtolower(pathinfo($mediaPath, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => '',
        };
        if ($mime === '') {
            return null;
        }

        $widthPct = 100;
        $extent = $xpath->query('.//wp:extent', $context)->item(0);
        if ($extent instanceof DOMElement) {
            $cx = (int)$extent->getAttribute('cx');
            if ($cx > 0) {
                $widthInches = $cx / 914400;
                $widthPct = max(20, min(100, (int)round(($widthInches / 6.5) * 100)));
            }
        }

        return array(
            'bytes' => $bytes,
            'mime' => $mime,
            'ext' => $ext === 'jpeg' ? 'jpg' : $ext,
            'alt' => '',
            'width_pct' => $widthPct,
        );
    }

    /**
     * @return array{type:string,rows:list<list<string>>}|null
     */
    private function parseTable(DOMElement $tbl, DOMXPath $xpath): ?array
    {
        unset($xpath);
        $gridColCount = $this->tableGridColumnCount($tbl);
        $rows = array();

        foreach ($this->directWordChildElements($tbl, 'tr') as $tr) {
            $cells = $this->parseTableRowCells($tr);
            if ($cells === array()) {
                continue;
            }
            $cells = $this->expandTabSeparatedRowCells($cells, $gridColCount);
            $rows[] = $cells;
        }

        if ($rows === array()) {
            return null;
        }

        $rows = $this->normalizeTableRowWidths($rows, $gridColCount);

        return array(
            'type' => 'table',
            'rows' => $rows,
        );
    }

    /**
     * @return list<DOMElement>
     */
    private function directWordChildElements(DOMElement $parent, string $localName): array
    {
        $elements = array();
        foreach ($parent->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            if ($child->namespaceURI === self::W_NS && $child->localName === $localName) {
                $elements[] = $child;
            }
        }

        return $elements;
    }

    /**
     * @return list<DOMElement>
     */
    private function tableRowCellElements(DOMElement $tr): array
    {
        $cells = array();
        foreach ($tr->childNodes as $child) {
            if (!$child instanceof DOMElement || $child->namespaceURI !== self::W_NS) {
                continue;
            }
            if ($child->localName === 'tc') {
                $cells[] = $child;
                continue;
            }
            if ($child->localName === 'sdt') {
                foreach ($child->getElementsByTagNameNS(self::W_NS, 'tc') as $tc) {
                    if ($tc instanceof DOMElement) {
                        $cells[] = $tc;
                    }
                }
            }
        }

        return $cells;
    }

    private function tableGridColumnCount(DOMElement $tbl): int
    {
        foreach ($this->directWordChildElements($tbl, 'tblGrid') as $grid) {
            $count = count($this->directWordChildElements($grid, 'gridCol'));
            if ($count > 0) {
                return $count;
            }
        }

        return 0;
    }

    /**
     * @return list<string>
     */
    private function parseTableRowCells(DOMElement $tr): array
    {
        $cells = array();
        foreach ($this->tableRowCellElements($tr) as $tc) {
            if ($this->tableCellVMerge($tc) === 'continue') {
                continue;
            }

            $text = self::sanitizeImportedText($this->extractTableCellText($tc));
            $gridSpan = $this->tableCellGridSpan($tc);
            $cells[] = $text;
            for ($i = 1; $i < $gridSpan; $i++) {
                $cells[] = '';
            }
        }

        return $cells;
    }

    private function extractTableCellText(DOMElement $tc): string
    {
        $cellParts = array();
        foreach ($this->directWordChildElements($tc, 'p') as $p) {
            $line = trim($this->extractParagraphTextRaw($p));
            if ($line !== '') {
                $cellParts[] = $line;
            }
        }
        if ($cellParts === array()) {
            $fallback = trim((string)$tc->textContent);
            if ($fallback !== '') {
                return preg_replace('/[ \x{00A0}]+/u', ' ', $fallback) ?? $fallback;
            }
        }

        return trim(implode("\n", $cellParts));
    }

    private function tableCellGridSpan(DOMElement $tc): int
    {
        foreach ($this->directWordChildElements($tc, 'tcPr') as $tcPr) {
            foreach ($this->directWordChildElements($tcPr, 'gridSpan') as $gridSpan) {
                $value = (int)$this->wordIntAttribute($gridSpan, 'val');
                return max(1, $value);
            }
        }

        return 1;
    }

    /**
     * @return 'continue'|'restart'|''
     */
    private function tableCellVMerge(DOMElement $tc): string
    {
        foreach ($this->directWordChildElements($tc, 'tcPr') as $tcPr) {
            foreach ($this->directWordChildElements($tcPr, 'vMerge') as $vMerge) {
                $value = strtolower(trim($this->wordAttribute($vMerge, 'val')));
                return $value === 'restart' ? 'restart' : 'continue';
            }
        }

        return '';
    }

    private function wordAttribute(DOMElement $element, string $name): string
    {
        $value = trim($element->getAttributeNS(self::W_NS, $name));
        if ($value !== '') {
            return $value;
        }

        return trim($element->getAttribute('w:' . $name));
    }

    private function wordIntAttribute(DOMElement $element, string $name): int
    {
        return (int)$this->wordAttribute($element, $name);
    }

    /**
     * @param list<string> $cells
     * @return list<string>
     */
    private function expandTabSeparatedRowCells(array $cells, int $gridColCount): array
    {
        if ($cells === array()) {
            return $cells;
        }

        $expectedCols = $gridColCount > 0 ? $gridColCount : 0;
        if (count($cells) === 1) {
            $parts = preg_split('/\t/u', (string)$cells[0]) ?: array();
            $parts = array_map('trim', $parts);
            $nonEmptyParts = array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
            if (count($nonEmptyParts) > 1 || (count($parts) > 1 && count($nonEmptyParts) > 0)) {
                if ($expectedCols > 0) {
                    return array_pad(array_slice($parts, 0, $expectedCols), $expectedCols, '');
                }

                return $parts;
            }

            if ($expectedCols > 1 && str_contains((string)$cells[0], '  ')) {
                $spaceParts = preg_split('/\s{2,}/u', trim((string)$cells[0])) ?: array();
                $spaceParts = array_values(array_filter(array_map('trim', $spaceParts), static fn(string $part): bool => $part !== ''));
                if (count($spaceParts) >= $expectedCols || count($spaceParts) > 1) {
                    return array_pad(array_slice($spaceParts, 0, $expectedCols), $expectedCols, '');
                }
            }
        }

        return $cells;
    }

    /**
     * @param list<list<string>> $rows
     * @return list<list<string>>
     */
    private function normalizeTableRowWidths(array $rows, int $gridColCount): array
    {
        $maxCols = $gridColCount;
        foreach ($rows as $row) {
            $maxCols = max($maxCols, count($row));
        }
        if ($maxCols <= 0) {
            $maxCols = 1;
        }

        $normalized = array();
        foreach ($rows as $row) {
            $normalized[] = array_pad(array_slice($row, 0, $maxCols), $maxCols, '');
        }

        return $normalized;
    }

    /**
     * @return array<string,string>
     */
    private function loadRelationships(string $relsPath): array
    {
        if ($this->zip === null) {
            return array();
        }
        $xml = $this->zip->getFromName($relsPath);
        if (!is_string($xml) || $xml === '') {
            return array();
        }
        $dom = new DOMDocument();
        if (@$dom->loadXML($xml) !== true) {
            return array();
        }
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
        $map = array();
        $nodes = $xpath->query('//r:Relationship');
        if ($nodes === false) {
            return $map;
        }
        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $id = $node->getAttribute('Id');
            $target = $node->getAttribute('Target');
            if ($id !== '' && $target !== '') {
                $map[$id] = $target;
            }
        }
        return $map;
    }

    /**
     * @return array<string,string>
     */
    private function loadStyleNames(): array
    {
        if ($this->zip === null) {
            return array();
        }
        $xml = $this->zip->getFromName('word/styles.xml');
        if (!is_string($xml) || $xml === '') {
            return array();
        }
        $dom = new DOMDocument();
        if (@$dom->loadXML($xml) !== true) {
            return array();
        }
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', self::W_NS);
        $map = array();
        $styles = $xpath->query('//w:style');
        if ($styles === false) {
            return $map;
        }
        foreach ($styles as $style) {
            if (!$style instanceof DOMElement) {
                continue;
            }
            $styleId = $style->getAttribute('w:styleId');
            if ($styleId === '') {
                $styleId = $style->getAttribute('styleId');
            }
            $nameNode = null;
            foreach ($style->childNodes as $child) {
                if ($child instanceof DOMElement && $child->localName === 'name' && $child->namespaceURI === self::W_NS) {
                    $nameNode = $child;
                    break;
                }
            }
            $name = '';
            if ($nameNode instanceof DOMElement) {
                $name = $nameNode->getAttribute('w:val');
                if ($name === '') {
                    $name = $nameNode->getAttribute('val');
                }
            }
            if ($styleId !== '') {
                $map[$styleId] = $name !== '' ? $name : $styleId;
            }
        }
        return $map;
    }
}
