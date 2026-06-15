<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingBlockService.php';
require_once __DIR__ . '/ControlledPublishingSectionService.php';
require_once __DIR__ . '/ControlledPublishingSectionNumberService.php';
require_once __DIR__ . '/ControlledPublishingDocxReader.php';

/**
 * Index live published manual content from ipca_publishing_book_blocks.
 * Single source of truth for MCCF manual browsing, linking, previews, and integrity text.
 */
final class ControlledPublishingBookSectionIndexService
{
    /** @var array<string,string> */
    private const VERSION_LABELS = array(
        'OM' => '6.0',
        'OMM' => '4.0',
    );

    /** @var array<string,list<array<string,mixed>>> */
    private static array $chapterCache = array();

    /** @var array<string,string> */
    private static array $plainTextCache = array();

    private ControlledPublishingBlockService $blocks;
    private ControlledPublishingSectionService $sections;

    public function __construct(private PDO $pdo)
    {
        $this->blocks = new ControlledPublishingBlockService($this->pdo);
        $this->sections = new ControlledPublishingSectionService($this->pdo);
    }

    public function versionLabelForManual(string $manualCode): string
    {
        $manualCode = strtoupper(trim($manualCode));

        return self::VERSION_LABELS[$manualCode] ?? '6.0';
    }

    public function resolveBookVersionId(string $manualCode): int
    {
        $manualCode = strtoupper(trim($manualCode));
        if ($manualCode === '') {
            return 0;
        }

        $versionLabel = $this->versionLabelForManual($manualCode);
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
                ':book_key' => $manualCode,
                ':version_label' => $versionLabel,
            ));

            return (int)$stmt->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    public function manualCodeForBookVersionId(int $bookVersionId): string
    {
        if ($bookVersionId <= 0) {
            return '';
        }

        try {
            $stmt = $this->pdo->prepare("
                SELECT b.book_key
                FROM ipca_publishing_book_versions bv
                INNER JOIN ipca_publishing_books b ON b.id = bv.book_id
                WHERE bv.id = :id
                LIMIT 1
            ");
            $stmt->execute(array(':id' => $bookVersionId));
            $bookKey = strtoupper(trim((string)$stmt->fetchColumn()));

            return $bookKey !== '' ? $bookKey : '';
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * @return list<array{part:string,label:string}>
     */
    public function listParts(int $bookVersionId): array
    {
        if ($bookVersionId <= 0) {
            return array();
        }

        $seen = array();
        foreach ($this->sections->listFlatSections($bookVersionId) as $row) {
            if (!$this->isChapterSection($row)) {
                continue;
            }
            $meta = $this->decodeMeta($row);
            $part = trim((string)($meta['manual_part'] ?? ''));
            if ($part === '' || isset($seen[$part])) {
                continue;
            }
            $seen[$part] = true;
        }

        $parts = array_keys($seen);
        usort($parts, static function (string $a, string $b): int {
            $na = ctype_digit($a) ? (int)$a : 0;
            $nb = ctype_digit($b) ? (int)$b : 0;
            if ($na !== $nb) {
                return $na <=> $nb;
            }

            return strnatcasecmp($a, $b);
        });

        $out = array();
        foreach ($parts as $part) {
            $out[] = array(
                'part' => $part,
                'label' => 'Part ' . $part,
            );
        }

        return $out;
    }

    /**
     * @return list<array{chapter_number:int,chapter:string,title:string,label:string,section_id:int}>
     */
    public function listChapters(int $bookVersionId, int $manualPart): array
    {
        if ($bookVersionId <= 0 || $manualPart <= 0) {
            return array();
        }

        $chapters = array();
        foreach ($this->sections->listFlatSections($bookVersionId) as $row) {
            if (!$this->isChapterSection($row)) {
                continue;
            }
            $meta = $this->decodeMeta($row);
            if ((int)($meta['manual_part'] ?? 0) !== $manualPart) {
                continue;
            }
            $number = (int)($meta['chapter_number'] ?? 0);
            if ($number <= 0) {
                continue;
            }
            $title = ControlledPublishingDocxReader::sanitizeSectionTitle(trim((string)($row['title'] ?? '')));
            $label = 'Chapter ' . $number;
            if ($title !== '' && !preg_match('/^Chapter\s+' . $number . '$/i', $title)) {
                $label .= ' — ' . $title;
            }
            $chapters[$number] = array(
                'chapter_number' => $number,
                'chapter' => (string)$number,
                'title' => $title,
                'label' => $label,
                'section_id' => (int)($row['id'] ?? 0),
            );
        }

        ksort($chapters, SORT_NUMERIC);

        return array_values($chapters);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listSections(
        int $bookVersionId,
        int $manualPart,
        string $chapter,
        ?string $parentSectionRef = null
    ): array {
        $chapter = trim($chapter);
        if ($bookVersionId <= 0 || $manualPart <= 0 || $chapter === '' || !ctype_digit($chapter)) {
            return array();
        }

        $all = $this->chapterSectionIndex($bookVersionId, $manualPart, (int)$chapter);
        if ($all === array()) {
            return array();
        }

        $parentSectionRef = $parentSectionRef !== null ? trim($parentSectionRef) : null;
        $sections = array();

        foreach ($all as $row) {
            $ref = (string)$row['section_ref'];
            if (!$this->isDirectChild($ref, $chapter, $parentSectionRef)) {
                continue;
            }

            $childPattern = $ref === $chapter
                ? '/^' . preg_quote($chapter, '/') . '\.\d+$/'
                : '/^' . preg_quote($ref, '/') . '\.\d+$/';
            $hasChildren = false;
            foreach ($all as $candidate) {
                if (preg_match($childPattern, (string)$candidate['section_ref'])) {
                    $hasChildren = true;
                    break;
                }
            }

            $title = trim((string)($row['title'] ?? ''));
            $label = '§' . $ref;
            if ($title !== '') {
                $label .= ' — ' . $title;
            }

            $sections[] = array(
                'id' => self::sectionPickerId($bookVersionId, $manualPart, $ref),
                'section_ref' => $ref,
                'title' => $title,
                'label' => $label,
                'has_children' => $hasChildren,
                'depth' => self::sectionDepth($ref, $chapter),
                'preview' => mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($row['preview'] ?? '')) ?? ''), 0, 220),
                'excerpt_key' => self::makeLinkKey(
                    (string)($row['manual_code'] ?? 'OM'),
                    (string)($row['version_label'] ?? '6.0'),
                    (string)$manualPart,
                    $ref
                ),
                'stable_anchor' => (string)($row['stable_anchor'] ?? ''),
                'section_id' => (int)($row['chapter_section_id'] ?? 0),
            );
        }

        usort($sections, static function (array $a, array $b): int {
            return self::compareSectionRefs((string)$a['section_ref'], (string)$b['section_ref']);
        });

        return $sections;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getSection(int $bookVersionId, int $manualPart, string $sectionRef): ?array
    {
        $sectionRef = trim($sectionRef);
        if ($bookVersionId <= 0 || $sectionRef === '') {
            return null;
        }

        if (preg_match('/^(\d+)(?:\.|$)/', $sectionRef, $m)) {
            foreach ($this->chapterSectionIndex($bookVersionId, $manualPart, (int)$m[1]) as $row) {
                if ((string)$row['section_ref'] === rtrim($sectionRef, '.')) {
                    return $row;
                }
            }
        }

        return null;
    }

    /**
     * @param list<string> $sectionRefs
     */
    public function plainTextForSectionRefs(int $bookVersionId, array $sectionRefs, bool $includeDescendants = true): string
    {
        if ($bookVersionId <= 0 || $sectionRefs === array()) {
            return '';
        }

        sort($sectionRefs);
        $cacheKey = $bookVersionId . '|' . ($includeDescendants ? '1' : '0') . '|' . implode(',', $sectionRefs);
        if (isset(self::$plainTextCache[$cacheKey])) {
            return self::$plainTextCache[$cacheKey];
        }

        $refsWanted = array();
        foreach ($sectionRefs as $ref) {
            $ref = rtrim(trim((string)$ref), '.');
            if ($ref === '') {
                continue;
            }
            // Prefix keys so numeric refs like "10" are not stored as int array keys.
            $refsWanted['=' . strtolower($ref)] = true;
            if ($includeDescendants) {
                $refsWanted['__prefix__' . strtolower($ref)] = true;
            }
        }

        $chunks = array();
        foreach ($this->sections->listFlatSections($bookVersionId) as $sectionRow) {
            if (!$this->isChapterSection($sectionRow)) {
                continue;
            }
            $sectionId = (int)($sectionRow['id'] ?? 0);
            if ($sectionId <= 0) {
                continue;
            }

            $sectionText = array();
            $includeSection = false;
            foreach ($this->blocks->listSectionBlocks($sectionId) as $block) {
                $payload = $this->blocks->decodePayload($block);
                $canonRef = strtolower(rtrim(trim((string)($payload['canonical_section_ref'] ?? '')), '.'));
                if ($canonRef !== '' && $this->refMatchesWanted($canonRef, $refsWanted, $includeDescendants)) {
                    $includeSection = true;
                }

                if ($includeSection) {
                    $text = $this->plainTextFromBlock($block, $payload);
                    if ($text !== '') {
                        $sectionText[] = $text;
                    }
                }
            }

            if ($includeSection && $sectionText !== array()) {
                $chunks[] = trim(implode("\n", $sectionText));
            }
        }

        return self::$plainTextCache[$cacheKey] = trim(implode("\n\n", array_unique(array_filter($chunks))));
    }

    /**
     * @return array{section_id:int,stable_anchor:string,scroll_anchor:string}|null
     */
    public function findScrollTarget(int $bookVersionId, string $sectionRef): ?array
    {
        $sectionRef = rtrim(trim($sectionRef), '.');
        if ($bookVersionId <= 0 || $sectionRef === '') {
            return null;
        }

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
            $canonRef = rtrim(trim((string)($payload['canonical_section_ref'] ?? '')), '.');
            if ($canonRef !== '' && strcasecmp($canonRef, $sectionRef) === 0) {
                return array(
                    'section_id' => (int)$row['section_id'],
                    'stable_anchor' => (string)($row['stable_anchor'] ?? ''),
                    'scroll_anchor' => (string)($row['stable_anchor'] ?? ''),
                );
            }
        }

        if (preg_match('/^(\d+)$/', $sectionRef, $m)) {
            foreach ($this->sections->listFlatSections($bookVersionId) as $sectionRow) {
                if (!$this->isChapterSection($sectionRow)) {
                    continue;
                }
                $meta = $this->decodeMeta($sectionRow);
                if ((int)($meta['chapter_number'] ?? 0) === (int)$m[1]) {
                    return array(
                        'section_id' => (int)($sectionRow['id'] ?? 0),
                        'stable_anchor' => (string)($sectionRow['stable_anchor'] ?? ''),
                        'scroll_anchor' => (string)($sectionRow['stable_anchor'] ?? ''),
                    );
                }
            }
        }

        return null;
    }

    public static function makeLinkKey(string $manualCode, string $versionLabel, string $part, string $sectionRef): string
    {
        return 'BOOK|'
            . strtoupper(trim($manualCode)) . '|'
            . trim($versionLabel) . '|P'
            . trim($part) . '|'
            . rtrim(trim($sectionRef), '.');
    }

    public static function parseLinkKey(string $linkKey): ?array
    {
        $linkKey = trim($linkKey);
        if (!str_starts_with($linkKey, 'BOOK|')) {
            return null;
        }
        $parts = explode('|', $linkKey);
        if (count($parts) < 5) {
            return null;
        }
        $part = $parts[3];
        if (str_starts_with($part, 'P')) {
            $part = substr($part, 1);
        }

        return array(
            'manual_code' => strtoupper($parts[1]),
            'version_label' => $parts[2],
            'manual_part' => $part,
            'section_ref' => $parts[4],
        );
    }

    public static function sectionPickerId(int $bookVersionId, int $manualPart, string $sectionRef): string
    {
        return 'bv' . $bookVersionId . '-p' . $manualPart . '-' . str_replace('.', '_', rtrim(trim($sectionRef), '.'));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function chapterSectionIndex(int $bookVersionId, int $manualPart, int $chapterNumber): array
    {
        $cacheKey = $bookVersionId . '|' . $manualPart . '|' . $chapterNumber;
        if (isset(self::$chapterCache[$cacheKey])) {
            return self::$chapterCache[$cacheKey];
        }

        $chapterSectionId = 0;
        foreach ($this->sections->listFlatSections($bookVersionId) as $row) {
            if (!$this->isChapterSection($row)) {
                continue;
            }
            $meta = $this->decodeMeta($row);
            if ((int)($meta['manual_part'] ?? 0) !== $manualPart) {
                continue;
            }
            if ((int)($meta['chapter_number'] ?? 0) !== $chapterNumber) {
                continue;
            }
            $chapterSectionId = (int)($row['id'] ?? 0);
            break;
        }

        if ($chapterSectionId <= 0) {
            return self::$chapterCache[$cacheKey] = array();
        }

        $version = $this->pdo->prepare('
            SELECT bv.version_label, bv.book_id, b.book_key
            FROM ipca_publishing_book_versions bv
            INNER JOIN ipca_publishing_books b ON b.id = bv.book_id
            WHERE bv.id = :id
            LIMIT 1
        ');
        $version->execute(array(':id' => $bookVersionId));
        $versionRow = $version->fetch(PDO::FETCH_ASSOC) ?: array();
        $manualCode = strtoupper(trim((string)($versionRow['book_key'] ?? 'OM')));
        if ($manualCode === '') {
            $manualCode = 'OM';
        }
        $versionLabel = trim((string)($versionRow['version_label'] ?? $this->versionLabelForManual($manualCode)));

        $numberSvc = new ControlledPublishingSectionNumberService($this->pdo, $this->blocks);
        $computed = $numberSvc->computeForVersion($bookVersionId, $manualCode);
        $displayNumbers = $computed['display'];

        $items = array();
        $seen = array();
        $bodyBuffers = array();
        $chapterTitle = '';

        foreach ($this->blocks->listSectionBlocks($chapterSectionId) as $block) {
            $payload = $this->blocks->decodePayload($block);
            $blockId = (int)($block['id'] ?? 0);
            $style = $this->resolveParagraphStyle((string)($block['block_type'] ?? ''), $payload);
            $depth = ControlledPublishingSectionNumberService::NUMBERED_STYLE_DEPTHS[$style] ?? 0;
            $text = $this->plainTextFromBlock($block, $payload);

            $canonRef = trim((string)($payload['canonical_section_ref'] ?? ''));
            $displayNumber = trim((string)($displayNumbers[$blockId] ?? ''));
            $sectionRef = rtrim($canonRef !== '' ? $canonRef : $displayNumber, '.');

            if ($depth === 1 && $sectionRef === (string)$chapterNumber) {
                $chapterTitle = $this->stripLeadingSectionRef($text, $sectionRef);
            }

            if ($sectionRef !== '') {
                if (!isset($bodyBuffers[$sectionRef])) {
                    $bodyBuffers[$sectionRef] = array();
                }
                if ($text !== '') {
                    $bodyBuffers[$sectionRef][] = $text;
                }
            }

            if ($depth < 1 || $sectionRef === '' || isset($seen[$sectionRef])) {
                continue;
            }

            $title = $this->stripLeadingSectionRef($text, $sectionRef);
            if ($title === '' && $depth === 1) {
                $title = $chapterTitle;
            }

            $seen[$sectionRef] = true;
            $items[] = array(
                'section_ref' => $sectionRef,
                'title' => $title,
                'preview' => $text,
                'stable_anchor' => (string)($block['stable_anchor'] ?? ''),
                'heading_block_id' => $blockId,
                'chapter_section_id' => $chapterSectionId,
                'manual_code' => $manualCode,
                'version_label' => $versionLabel,
            );
        }

        foreach ($items as $idx => $item) {
            $ref = (string)$item['section_ref'];
            $body = trim(implode("\n", $bodyBuffers[$ref] ?? array()));
            $items[$idx]['body_text'] = $body !== '' ? $body : (string)($item['preview'] ?? '');
            $items[$idx]['preview'] = mb_substr($body !== '' ? $body : (string)($item['preview'] ?? ''), 0, 280);
        }

        if (!isset($seen[(string)$chapterNumber])) {
            array_unshift($items, array(
                'section_ref' => (string)$chapterNumber,
                'title' => $chapterTitle,
                'preview' => '',
                'body_text' => trim(implode("\n", $bodyBuffers[(string)$chapterNumber] ?? array())),
                'stable_anchor' => '',
                'heading_block_id' => 0,
                'chapter_section_id' => $chapterSectionId,
                'manual_code' => $manualCode,
                'version_label' => $versionLabel,
            ));
        }

        usort($items, static function (array $a, array $b): int {
            return self::compareSectionRefs((string)$a['section_ref'], (string)$b['section_ref']);
        });

        return self::$chapterCache[$cacheKey] = $items;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function isChapterSection(array $row): bool
    {
        if ((string)($row['section_type'] ?? '') !== 'content') {
            return false;
        }
        $meta = $this->decodeMeta($row);
        $chapterNumber = (int)($meta['chapter_number'] ?? 0);

        return $chapterNumber > 0 && $chapterNumber <= ControlledPublishingDocxReader::MAX_CHAPTER_NUMBER;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function decodeMeta(array $row): array
    {
        $raw = $row['metadata_json'] ?? null;
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : array();
        }

        return is_array($raw) ? $raw : array();
    }

    /**
     * @param array<string,mixed> $block
     * @param array<string,mixed> $payload
     */
    private function plainTextFromBlock(array $block, array $payload): string
    {
        $type = (string)($block['block_type'] ?? $payload['type'] ?? '');
        if ($type === 'heading' || $type === 'paragraph') {
            $html = (string)($payload['html'] ?? $payload['text_html'] ?? $payload['text'] ?? '');

            return trim(preg_replace('/\s+/u', ' ', strip_tags($html)) ?? '');
        }
        if ($type === 'list') {
            $items = is_array($payload['items'] ?? null) ? $payload['items'] : array();
            $lines = array();
            foreach ($items as $item) {
                if (is_string($item)) {
                    $lines[] = trim(strip_tags($item));
                } elseif (is_array($item)) {
                    $lines[] = trim(strip_tags((string)($item['text'] ?? $item['text_html'] ?? '')));
                }
            }

            return trim(implode("\n", array_filter($lines)));
        }
        if ($type === 'callout') {
            return trim(strip_tags((string)($payload['title'] ?? '')) . "\n" . strip_tags((string)($payload['text'] ?? '')));
        }

        return '';
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function resolveParagraphStyle(string $blockType, array $payload): string
    {
        if ($blockType === 'heading') {
            return (string)($payload['level'] ?? 'subtitle_1');
        }

        return (string)($payload['paragraph_style'] ?? 'body');
    }

    private function stripLeadingSectionRef(string $text, string $sectionRef): string
    {
        $text = trim($text);
        $sectionRef = rtrim(trim($sectionRef), '.');
        if ($text === '' || $sectionRef === '') {
            return $text;
        }

        return trim(preg_replace('/^' . preg_quote($sectionRef, '/') . '(?:\.|\s)+/u', '', $text) ?? $text);
    }

    private function isDirectChild(string $ref, string $chapter, ?string $parentSectionRef): bool
    {
        if ($parentSectionRef === null || $parentSectionRef === '') {
            return $ref === $chapter || (bool)preg_match('/^' . preg_quote($chapter, '/') . '\.\d+$/', $ref);
        }

        return $ref === $parentSectionRef
            || (bool)preg_match('/^' . preg_quote($parentSectionRef, '/') . '\.\d+$/', $ref);
    }

    private static function sectionDepth(string $ref, string $chapter): int
    {
        if ($ref === $chapter || !str_starts_with($ref, $chapter . '.')) {
            return $ref === $chapter ? 0 : 0;
        }

        return substr_count($ref, '.');
    }

    /**
     * @param array<string,bool> $refsWanted
     */
    private function refMatchesWanted(string $ref, array $refsWanted, bool $includeDescendants): bool
    {
        $ref = strtolower(rtrim(trim($ref), '.'));
        if ($ref === '') {
            return false;
        }
        if (isset($refsWanted['=' . $ref])) {
            return true;
        }
        if (!$includeDescendants) {
            return false;
        }
        foreach ($refsWanted as $key => $_true) {
            $key = (string)$key;
            if (!str_starts_with($key, '__prefix__')) {
                continue;
            }
            $prefix = substr($key, strlen('__prefix__'));
            if ($ref === $prefix || str_starts_with($ref, $prefix . '.')) {
                return true;
            }
        }

        return false;
    }

    private static function compareSectionRefs(string $a, string $b): int
    {
        $aParts = array_map('intval', explode('.', $a));
        $bParts = array_map('intval', explode('.', $b));
        $len = max(count($aParts), count($bParts));
        for ($i = 0; $i < $len; $i++) {
            $av = $aParts[$i] ?? 0;
            $bv = $bParts[$i] ?? 0;
            if ($av !== $bv) {
                return $av <=> $bv;
            }
        }

        return 0;
    }
}
