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
     * @param array{
     *   include_front_matter?:bool,
     *   detect_part_from_filename?:bool
     * } $options
     * @return array{
     *   manual_part:int,
     *   nodes:list<array<string,mixed>>,
     *   warnings:list<string>,
     *   orientation:string
     * }
     */
    public function parseFile(string $path, ?int $manualPart = null, array $options = array()): array
    {
        if (!is_readable($path)) {
            throw new RuntimeException('DOCX file is not readable: ' . $path);
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Could not open DOCX archive: ' . basename($path));
        }

        $detectPartFromFilename = !array_key_exists('detect_part_from_filename', $options)
            || !empty($options['detect_part_from_filename']);
        if (($manualPart === null || $manualPart < 0) && $detectPartFromFilename) {
            $manualPart = self::detectManualPartFromFilename($path);
        }
        if ($manualPart === null) {
            $manualPart = -1;
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
        $orientation = 'portrait';
        $pastToc = !empty($options['include_front_matter']);

        foreach ($this->eachWordBlock($body) as $child) {
            if ($child->localName === 'sectPr') {
                if ($this->sectPrOrientation($child) === 'landscape') {
                    $orientation = 'landscape';
                }
                continue;
            }

            if ($child->localName === 'p') {
                $paragraphSectPr = $this->paragraphSectPr($child);
                if ($paragraphSectPr !== null && $this->sectPrOrientation($paragraphSectPr) === 'landscape') {
                    $orientation = 'landscape';
                }

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

            $warnings[] = 'Skipped unsupported body element: ' . $child->localName;
        }

        $zip->close();
        $this->zip = null;

        $nodes = self::promoteMissingChapterHeadings($nodes, $manualPart);

        return array(
            'manual_part' => $manualPart,
            'nodes' => $nodes,
            'warnings' => $warnings,
            'orientation' => $orientation === 'landscape' ? 'landscape' : 'portrait',
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
     * Flatten Word content-control wrappers so nested paragraphs and tables are imported.
     *
     * @return list<DOMElement>
     */
    private function eachWordBlock(DOMElement $parent): array
    {
        $elements = array();
        foreach ($parent->childNodes as $child) {
            if (!$child instanceof DOMElement || $child->namespaceURI !== self::W_NS) {
                continue;
            }
            $name = $child->localName;
            if (in_array($name, array('del', 'moveFrom'), true)) {
                continue;
            }
            if (in_array($name, array('sdt', 'customXml', 'ins', 'smartTag', 'moveTo'), true)) {
                $inner = $name === 'sdt' ? $this->contentControlBody($child) : $child;
                foreach ($this->eachWordBlock($inner) as $nested) {
                    $elements[] = $nested;
                }
                continue;
            }
            if ($name === 'sdtContent') {
                foreach ($this->eachWordBlock($child) as $nested) {
                    $elements[] = $nested;
                }
                continue;
            }
            $elements[] = $child;
        }

        return $elements;
    }

    private function contentControlBody(DOMElement $sdt): DOMElement
    {
        foreach ($this->directWordChildElements($sdt, 'sdtContent') as $content) {
            return $content;
        }

        return $sdt;
    }

    private function paragraphSectPr(DOMElement $paragraph): ?DOMElement
    {
        foreach ($this->directWordChildElements($paragraph, 'pPr') as $props) {
            foreach ($this->directWordChildElements($props, 'sectPr') as $sectPr) {
                return $sectPr;
            }
        }

        return null;
    }

    private function sectPrOrientation(DOMElement $sectPr): string
    {
        foreach ($this->directWordChildElements($sectPr, 'pgSz') as $pageSize) {
            if (strtolower(trim($this->wordAttribute($pageSize, 'orient'))) === 'landscape') {
                return 'landscape';
            }
            $width = $this->wordIntAttribute($pageSize, 'w');
            $height = $this->wordIntAttribute($pageSize, 'h');
            if ($width > 0 && $height > 0 && $width > $height) {
                return 'landscape';
            }
        }

        return 'portrait';
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
            $isHeadingStyle = self::isHeadingParagraphStyle($styleId, $styleName);
            if ($parsed !== null && self::isPlausibleManualSectionRef(
                $parsed['section_ref'],
                $parsed['title'],
                $manualPart,
                $isHeadingStyle
            )) {
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
     * Plain text extracted from stored inline HTML (TOC labels, nav titles, etc.).
     */
    public static function plainTextFromHtml(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (str_contains($text, '&')) {
            $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded !== $text) {
                $text = $decoded;
            }
        }

        return self::sanitizeImportedText($text);
    }

    /**
     * Normalize text for display in the Table of Contents (entity decode + Word suffix cleanup).
     */
    public static function normalizeTocLabel(string $label): string
    {
        $label = trim($label);
        if ($label === '') {
            return '';
        }
        $label = html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (str_contains($label, '&')) {
            $decoded = html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded !== $label) {
                $label = $decoded;
            }
        }

        return self::sanitizeSectionTitle($label);
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

        // Word bookmark / cross-ref suffixes such as "Electronic Mass & Balance-92178".
        $text = preg_replace('/-\d{4,}-?\s*$/u', '', $text) ?? $text;
        $text = preg_replace('/-\d{4,}-(?=\s|$)/u', ' ', $text) ?? $text;

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

    public static function isPlausibleManualSectionRef(
        string $sectionRef,
        string $title,
        int $manualPart = -1,
        bool $allowTitleCaseChapter = false
    ): bool {
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
                if (!self::isChapterLevelTitle($title) && !$allowTitleCaseChapter) {
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
     * Word / Pages heading styles used for real chapter titles (Titel, Heading 1, Koptekst).
     * Numbered body lists are not heading-styled, so Title Case chapter titles can be accepted
     * without treating "1. Personal Data" as a MAIN section.
     */
    public static function isHeadingParagraphStyle(string $styleId, string $styleName): bool
    {
        $id = strtolower(trim($styleId));
        $name = strtolower(trim($styleName));
        if ($id === '' && $name === '') {
            return false;
        }
        if (in_array($id, array('titel', 'title'), true) || in_array($name, array('titel', 'title'), true)) {
            return true;
        }
        if (str_starts_with($name, 'heading') || str_starts_with($id, 'heading')) {
            return true;
        }
        if (str_starts_with($name, 'koptekst') || str_starts_with($id, 'koptekst')) {
            return true;
        }

        return false;
    }

    /**
     * When a Word part has 1.1 / 1.1.1 headings but no ALL-CAPS "1. TITLE" line,
     * promote the first matching "1. Title" paragraph to a chapter heading.
     *
     * @param list<array<string,mixed>> $nodes
     * @return list<array<string,mixed>>
     */
    public static function promoteMissingChapterHeadings(array $nodes, int $manualPart): array
    {
        if ($manualPart <= 0 || $nodes === array()) {
            return $nodes;
        }

        $existingRefs = array();
        $chaptersWithSubsections = array();
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $ref = trim((string)($node['section_ref'] ?? ''));
            if ($ref === '') {
                continue;
            }
            $existingRefs[$ref] = true;
            if (preg_match('/^(\d+)\.\d/', $ref, $m) === 1) {
                $chaptersWithSubsections[(int)$m[1]] = true;
            }
        }

        if ($chaptersWithSubsections === array()) {
            return $nodes;
        }

        $promote = static function (array &$nodes, array &$existingRefs, array $chaptersWithSubsections, bool $headingStyleOnly): void {
            foreach ($nodes as &$node) {
                if (!is_array($node) || (string)($node['type'] ?? '') !== 'paragraph') {
                    continue;
                }
                if (trim((string)($node['section_ref'] ?? '')) !== '') {
                    continue;
                }
                $isHeadingStyle = self::isHeadingParagraphStyle(
                    (string)($node['style_id'] ?? ''),
                    (string)($node['style_name'] ?? '')
                );
                if ($headingStyleOnly !== $isHeadingStyle) {
                    continue;
                }
                $parsed = self::parseSectionHeading((string)($node['text'] ?? ''));
                if ($parsed === null) {
                    continue;
                }
                $ref = (string)$parsed['section_ref'];
                if (preg_match('/^\d+$/', $ref) !== 1) {
                    continue;
                }
                $number = (int)$ref;
                if ($number < 1 || $number > self::MAX_CHAPTER_NUMBER || isset($existingRefs[$ref])) {
                    continue;
                }
                if (empty($chaptersWithSubsections[$number])) {
                    continue;
                }
                $title = (string)$parsed['title'];
                if (!self::isPlausibleSubtitleTitle($title) || self::isLikelyTableOrMeasurementExcerpt($ref, $title)) {
                    continue;
                }
                $node['section_ref'] = $ref;
                $node['section_title'] = $title;
                $node['paragraph_style'] = 'title';
                $existingRefs[$ref] = true;
            }
            unset($node);
        };

        $promote($nodes, $existingRefs, $chaptersWithSubsections, true);
        $promote($nodes, $existingRefs, $chaptersWithSubsections, false);

        return self::promoteUnnumberedChapterTitles($nodes, $existingRefs, $chaptersWithSubsections);
    }

    /**
     * Pages/Word often emit an unnumbered Titel/Heading 1 ("Course Enrollment")
     * immediately before 1.1 / 1.1.1. Treat that as the MAIN chapter title.
     *
     * @param list<array<string,mixed>> $nodes
     * @param array<string,bool> $existingRefs
     * @param array<int,bool> $chaptersWithSubsections
     * @return list<array<string,mixed>>
     */
    private static function promoteUnnumberedChapterTitles(
        array $nodes,
        array $existingRefs,
        array $chaptersWithSubsections
    ): array {
        $firstSubsectionIndex = array();
        foreach ($nodes as $index => $node) {
            if (!is_array($node)) {
                continue;
            }
            $ref = trim((string)($node['section_ref'] ?? ''));
            if (preg_match('/^(\d+)\.\d/', $ref, $m) !== 1) {
                continue;
            }
            $chapter = (int)$m[1];
            if (!isset($firstSubsectionIndex[$chapter])) {
                $firstSubsectionIndex[$chapter] = $index;
            }
        }

        foreach ($chaptersWithSubsections as $chapter => $flag) {
            unset($flag);
            $chapter = (int)$chapter;
            $chapterRef = (string)$chapter;
            if ($chapter <= 0 || isset($existingRefs[$chapterRef]) || !isset($firstSubsectionIndex[$chapter])) {
                continue;
            }
            $limit = $firstSubsectionIndex[$chapter];
            $candidateIndex = -1;
            for ($i = $limit - 1; $i >= 0; $i--) {
                $node = $nodes[$i] ?? null;
                if (!is_array($node) || (string)($node['type'] ?? '') !== 'paragraph') {
                    continue;
                }
                if (trim((string)($node['section_ref'] ?? '')) !== '') {
                    break;
                }
                if (!self::isHeadingParagraphStyle(
                    (string)($node['style_id'] ?? ''),
                    (string)($node['style_name'] ?? '')
                )) {
                    continue;
                }
                $text = trim((string)($node['text'] ?? ''));
                if ($text === '' || self::parseSectionHeading($text) !== null) {
                    continue;
                }
                if (!self::isPlausibleSubtitleTitle($text) || self::isLikelyTableOrMeasurementExcerpt($chapterRef, $text)) {
                    continue;
                }
                $candidateIndex = $i;
                break;
            }
            if ($candidateIndex < 0) {
                continue;
            }
            $nodes[$candidateIndex]['section_ref'] = $chapterRef;
            $nodes[$candidateIndex]['section_title'] = trim((string)($nodes[$candidateIndex]['text'] ?? ''));
            $nodes[$candidateIndex]['paragraph_style'] = 'title';
            $existingRefs[$chapterRef] = true;
        }

        return $nodes;
    }

    /**
     * Parts often number MAIN sections as 1.1, 1.2 under a generic
     * "1. GENERAL" wrapper. Promote those to chapters 1, 2, 3 and rewrite
     * 1.1.1 → 1.1 so the editor sidebar matches the real manual.
     * OM-style files with real chapters 1 and 2 are left unchanged.
     *
     * @param list<array<string,mixed>> $nodes
     * @return list<array<string,mixed>>
     */
    public static function flattenGenericPartChapters(array $nodes, int $manualPart): array
    {
        if ($manualPart <= 0 || $nodes === array()) {
            return $nodes;
        }

        $prefix = self::genericPartFlattenPrefix($nodes);
        if ($prefix === '') {
            return $nodes;
        }

        $prefixDot = $prefix . '.';
        $out = array();
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $ref = trim((string)($node['section_ref'] ?? ''));
            $title = trim((string)($node['section_title'] ?? $node['text'] ?? ''));
            if ($ref === $prefix) {
                if (self::isGenericPartWrapperTitle($title)) {
                    continue;
                }
                $node['section_ref'] = '';
                if (strtolower(trim((string)($node['paragraph_style'] ?? ''))) === 'title') {
                    $node['paragraph_style'] = 'subtitle_1';
                }
                $out[] = $node;
                continue;
            }
            if ($ref !== '' && str_starts_with($ref, $prefixDot)) {
                $stripped = substr($ref, strlen($prefixDot));
                if ($stripped !== '' && preg_match('/^\d+(?:\.\d+)*$/', $stripped) === 1) {
                    $node['section_ref'] = $stripped;
                    $node['paragraph_style'] = self::sectionRefToParagraphStyle($stripped);
                }
            }
            $out[] = $node;
        }

        return $out;
    }

    /**
     * MAIN chapter number => title from the 1.1 / 1.2 headings under a generic wrapper.
     *
     * @param list<array<string,mixed>> $nodes
     * @return array<int,string>
     */
    public static function promotedMainChaptersFromNodes(array $nodes, int $manualPart): array
    {
        unset($manualPart);
        $prefix = self::genericPartFlattenPrefix($nodes);
        if ($prefix === '') {
            return array();
        }

        $prefixDot = $prefix . '.';
        $chapters = array();
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $ref = trim((string)($node['section_ref'] ?? ''));
            if ($ref === '' || !str_starts_with($ref, $prefixDot)) {
                continue;
            }
            $stripped = substr($ref, strlen($prefixDot));
            if (preg_match('/^\d+$/', $stripped) !== 1) {
                continue;
            }
            $title = trim((string)($node['section_title'] ?? $node['text'] ?? ''));
            $title = preg_replace('/^\d+(?:\.\d+)*\.?\s+/u', '', $title) ?? $title;
            $title = trim($title);
            if ($title === '' || self::isGenericPartWrapperTitle($title) || self::isStrayPartHeadingTitle($title)) {
                continue;
            }
            $chapters[(int)$stripped] = $title;
        }

        return $chapters;
    }

    /**
     * @param list<array<string,mixed>> $nodes
     */
    public static function genericPartFlattenPrefix(array $nodes): string
    {
        $depth1Titles = array();
        $depth2Prefixes = array();
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $ref = trim((string)($node['section_ref'] ?? ''));
            if ($ref === '' || preg_match('/^\d+(?:\.\d+)*$/', $ref) !== 1) {
                continue;
            }
            $segments = explode('.', $ref);
            if (count($segments) === 1) {
                $depth1Titles[$ref][] = trim((string)($node['section_title'] ?? $node['text'] ?? ''));
                continue;
            }
            if (count($segments) === 2) {
                $depth2Prefixes[$segments[0]][$segments[1]] = true;
            }
        }

        if (count($depth2Prefixes) !== 1) {
            return '';
        }
        $prefix = (string)array_key_first($depth2Prefixes);
        if ($prefix === '' || count($depth2Prefixes[$prefix] ?? array()) < 2) {
            return '';
        }

        foreach ($depth1Titles as $ref => $titles) {
            unset($titles);
            if ((string)$ref !== $prefix) {
                return '';
            }
        }

        foreach ($depth1Titles[$prefix] ?? array() as $title) {
            if (self::isGenericPartWrapperTitle($title) || self::isStrayPartHeadingTitle($title)) {
                continue;
            }
            return '';
        }

        return $prefix;
    }

    public static function isGenericPartWrapperTitle(string $title): bool
    {
        $title = trim($title);
        $title = preg_replace('/^\d+(?:\.\d+)*\.?\s+/u', '', $title) ?? $title;
        $normalized = strtolower(trim($title));
        if ($normalized === '') {
            return true;
        }
        if (preg_match('/^chapter\s+\d+$/i', $normalized) === 1) {
            return true;
        }

        return in_array($normalized, array(
            'general',
            'introduction',
            'outline',
            'part 1 – general',
            'part 1 - general',
            'part 1 general',
        ), true);
    }

    /**
     * Word sometimes emits a second "1. - LABEL" heading under the wrapper.
     * That is not a real MAIN chapter and must not block 1.1/1.2 promotion.
     */
    public static function isStrayPartHeadingTitle(string $title): bool
    {
        $title = trim($title);
        $title = preg_replace('/^\d+(?:\.\d+)*\.?\s+/u', '', $title) ?? $title;
        $title = trim($title);

        return $title !== '' && preg_match('/^[-–—•]\s*/u', $title) === 1;
    }

    /**
     * Chapter titles in OM/OMM use ALL CAPS (e.g. "1. INTRODUCTION").
     * Numbered list items like "1. Personal Data" are rejected unless they use a heading style.
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
     * @return array{type:string,rows:list<list<string>>,row_colspans:list<list<int>>}|null
     */
    private function parseTable(DOMElement $tbl, DOMXPath $xpath): ?array
    {
        $gridColCount = $this->tableGridColumnCount($tbl);
        $rows = array();
        $rowColspans = array();

        foreach ($this->directWordChildElements($tbl, 'tr') as $tr) {
            $parsedRow = $this->parseTableRow($tr, $xpath, $gridColCount);
            if ($parsedRow['cells'] === array()) {
                continue;
            }
            $rows[] = $parsedRow['cells'];
            $rowColspans[] = $parsedRow['colspans'];
        }

        if ($rows === array()) {
            return null;
        }

        $maxLogical = $gridColCount;
        foreach ($rowColspans as $spans) {
            $maxLogical = max($maxLogical, array_sum($spans));
        }
        if ($maxLogical <= 0) {
            $maxLogical = 1;
        }
        foreach ($rows as $index => $row) {
            $logical = array_sum($rowColspans[$index]);
            while ($logical < $maxLogical) {
                $rows[$index][] = '';
                $rowColspans[$index][] = 1;
                $logical++;
            }
        }

        return array(
            'type' => 'table',
            'rows' => $rows,
            'row_colspans' => $rowColspans,
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
     * @return array{cells:list<string>,colspans:list<int>}
     */
    private function parseTableRow(DOMElement $tr, DOMXPath $xpath, int $gridColCount): array
    {
        $cells = array();
        $colspans = array();
        foreach ($this->tableRowCellElements($tr) as $tc) {
            if ($this->tableCellVMerge($tc) === 'continue') {
                continue;
            }

            $text = self::sanitizeImportedText($this->extractTableCellText($tc, $xpath));
            $cells[] = $text;
            $colspans[] = $this->tableCellGridSpan($tc);
        }

        $expanded = $this->expandTabSeparatedRowCells($cells, $gridColCount);
        if ($expanded !== $cells) {
            $cells = $expanded;
            $colspans = array_fill(0, count($cells), 1);
        } elseif (count($colspans) !== count($cells)) {
            $colspans = array_fill(0, count($cells), 1);
        }

        return array(
            'cells' => $cells,
            'colspans' => $colspans,
        );
    }

    private function extractTableCellText(DOMElement $tc, DOMXPath $xpath): string
    {
        $cellParts = array();
        foreach ($this->eachWordBlock($tc) as $child) {
            if ($child->localName === 'p') {
                $line = trim($this->extractParagraphTextRaw($child));
                if ($line !== '') {
                    $cellParts[] = $line;
                }
                continue;
            }
            if ($child->localName !== 'tbl') {
                continue;
            }
            $nested = $this->parseTable($child, $xpath);
            if ($nested === null) {
                continue;
            }
            foreach ($nested['rows'] as $row) {
                $line = trim(implode("\t", array_map('trim', $row)));
                if ($line !== '') {
                    $cellParts[] = $line;
                }
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
