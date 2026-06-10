<?php
declare(strict_types=1);

/**
 * Reads Apple Pages / Word DOCX exports into ordered content nodes for manual import.
 */
final class ControlledPublishingDocxReader
{
    /** Max top-level chapter number (e.g. "12. INTRODUCTION") in Parts 1–4. */
    private const MAX_CHAPTER_NUMBER = 30;

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
        if ($text !== '') {
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
        $title = trim($m[2]);
        $title = preg_replace('/(\d+)$/u', '', $title) ?? $title;
        $title = trim($title);
        if ($ref === '' || $title === '') {
            return null;
        }
        return array(
            'section_ref' => $ref,
            'title' => $title,
        );
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

        return true;
    }

    private static function isLikelyMeasurementOrIdLine(string $sectionRef, string $title): bool
    {
        $combined = $sectionRef . ' ' . $title;
        if (preg_match('/\b(MHz|kHz|ft|KG|Kg|kg|NM|kt|VOR|ILS|DME|NOTAM)\b/iu', $combined)) {
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
        $parts = array();
        $this->collectTextFromNode($p, $parts);
        $text = implode('', $parts);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
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
        $rows = array();
        foreach ($tbl->getElementsByTagNameNS(self::W_NS, 'tr') as $tr) {
            if (!$tr instanceof DOMElement) {
                continue;
            }
            $cells = array();
            foreach ($tr->getElementsByTagNameNS(self::W_NS, 'tc') as $tc) {
                if (!$tc instanceof DOMElement) {
                    continue;
                }
                $cellParts = array();
                foreach ($tc->getElementsByTagNameNS(self::W_NS, 'p') as $p) {
                    if (!$p instanceof DOMElement) {
                        continue;
                    }
                    $line = $this->extractParagraphText($p);
                    if ($line !== '') {
                        $cellParts[] = $line;
                    }
                }
                $cells[] = trim(implode("\n", $cellParts));
            }
            if ($cells !== array()) {
                $rows[] = $cells;
            }
        }

        if ($rows === array()) {
            return null;
        }

        return array(
            'type' => 'table',
            'rows' => $rows,
        );
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
