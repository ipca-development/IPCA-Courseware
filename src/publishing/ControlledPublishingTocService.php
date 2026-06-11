<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingBookStyleService.php';
require_once __DIR__ . '/ControlledPublishingBlockService.php';
require_once __DIR__ . '/ControlledPublishingDocxReader.php';
require_once __DIR__ . '/ControlledPublishingSectionNumberService.php';
require_once __DIR__ . '/ControlledPublishingPart0PageService.php';

/**
 * Builds the Table of Contents section from Title / Subtitle paragraph styles.
 */
final class ControlledPublishingTocService
{
    /** @var array<string,string> */
    public const STYLE_SETTING_KEYS = array(
        'title' => 'include_title',
        'subtitle_1' => 'include_subtitle_1',
        'subtitle_2' => 'include_subtitle_2',
        'subtitle_3' => 'include_subtitle_3',
        'subtitle_4' => 'include_subtitle_4',
    );

    /** @var array<string,string> */
    public const STYLE_LABELS = array(
        'title' => 'Title',
        'subtitle_1' => 'Subtitle 1',
        'subtitle_2' => 'Subtitle 2',
        'subtitle_3' => 'Subtitle 3',
        'subtitle_4' => 'Subtitle 4',
    );

    public function __construct(
        private PDO $pdo,
        private ControlledPublishingBlockService $blocks
    ) {
    }

    /**
     * @return array<string,bool>
     */
    public function defaultTocSettings(): array
    {
        return array(
            'include_title' => true,
            'include_subtitle_1' => true,
            'include_subtitle_2' => true,
            'include_subtitle_3' => false,
            'include_subtitle_4' => false,
        );
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array<string,bool>
     */
    public function resolveTocSettingsFromMetadata(array $metadata): array
    {
        $defaults = $this->defaultTocSettings();
        $raw = is_array($metadata['toc_settings'] ?? null) ? $metadata['toc_settings'] : array();
        return $this->normalizeTocSettings($raw, $defaults);
    }

    /**
     * @param array<string,mixed> $version
     * @return array<string,bool>
     */
    public function resolveTocSettingsFromVersion(array $version): array
    {
        return $this->resolveTocSettingsFromMetadata($this->decodeMeta($version));
    }

    /**
     * @param array<string,mixed> $settings
     * @return array<string,bool>
     */
    public function saveTocSettingsForVersion(int $versionId, array $settings, ?int $actorUserId = null): array
    {
        $version = $this->requireVersion($versionId);
        $meta = $this->decodeMeta($version);
        $normalized = $this->normalizeTocSettings($settings, $this->defaultTocSettings());
        $meta['toc_settings'] = $normalized;

        $stmt = $this->pdo->prepare("
            UPDATE ipca_publishing_book_versions
            SET metadata_json = :metadata_json, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute(array(
            ':metadata_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ':id' => $versionId,
        ));

        return $normalized;
    }

    /**
     * @return array{section_id:int,entries_count:int,blocks_created:int,toc_settings:array<string,bool>}
     */
    public function regenerateTocSection(int $versionId, ?int $actorUserId = null): array
    {
        $sectionId = $this->tocSectionId($versionId);
        if ($sectionId <= 0) {
            throw new RuntimeException('Table of Contents section not found for this version.');
        }

        $version = $this->requireVersion($versionId);
        $settings = $this->resolveTocSettingsFromVersion($version);
        $numberSvc = new ControlledPublishingSectionNumberService($this->pdo, $this->blocks);
        $numbering = $numberSvc->computeForVersion($versionId);
        $entries = $this->collectTocEntries($versionId, $numbering['display'], $settings);
        $this->pdo->prepare("
            DELETE FROM ipca_publishing_book_blocks
            WHERE section_id = :section_id
        ")->execute(array(':section_id' => $sectionId));

        $section = $this->sectionRow($sectionId);
        $stableBase = (string)($section['stable_anchor'] ?? 'TOC');
        $sort = 10;
        $created = 0;

        $ins = $this->pdo->prepare("
            INSERT INTO ipca_publishing_book_blocks
                (book_version_id, section_id, block_key, stable_anchor, block_type, sort_order,
                 payload_json, content_hash, is_system_managed, created_by, updated_by)
            VALUES
                (:book_version_id, :section_id, :block_key, :stable_anchor, :block_type, :sort_order,
                 :payload_json, :content_hash, 1, :actor, :actor)
        ");

        $headingPayload = array(
            'text' => 'Table of Contents',
            'level' => 1,
            'paragraph_style' => 'title',
        );
        $this->insertTocBlock(
            $ins,
            $versionId,
            $sectionId,
            $stableBase,
            'toc_title',
            'heading',
            $headingPayload,
            $sort,
            $actorUserId
        );
        $sort += 10;
        $created++;

        if ($entries === array()) {
            $para = array(
                'html' => '<p>No TOC entries found. Apply Title or Subtitle paragraph styles to content blocks, then regenerate.</p>',
                'paragraph_style' => 'body',
            );
            $this->insertTocBlock($ins, $versionId, $sectionId, $stableBase, 'toc_empty', 'paragraph', $para, $sort, $actorUserId);
            return array(
                'section_id' => $sectionId,
                'entries_count' => 0,
                'blocks_created' => $created + 1,
                'toc_settings' => $settings,
            );
        }

        $tocPayload = array(
            'entries' => $entries,
        );
        $this->insertTocBlock($ins, $versionId, $sectionId, $stableBase, 'toc_entries', 'toc', $tocPayload, $sort, $actorUserId);
        $created++;

        return array(
            'section_id' => $sectionId,
            'entries_count' => count($entries),
            'blocks_created' => $created,
            'toc_settings' => $settings,
        );
    }

    /**
     * @param array<string,bool> $settings
     * @param array<int,string> $sectionNumberDisplay
     * @return list<array<string,mixed>>
     */
    private function collectTocEntries(int $versionId, array $sectionNumberDisplay, array $settings): array
    {
        $part0Svc = new ControlledPublishingPart0PageService($this->pdo, $this->blocks);
        $entries = $part0Svc->collectOutlineTocEntries($versionId);

        foreach ($this->loadChaptersGroupedByPart($versionId) as $partKey => $chapters) {
            $partTitle = $this->resolvePartTitle($versionId, $partKey);
            if ($partTitle !== '') {
                $entries[] = $this->partContainerEntry($partKey, $partTitle, $chapters);
            }
            foreach ($chapters as $chapter) {
                $entries = array_merge(
                    $entries,
                    $this->collectSectionTocEntries($versionId, $chapter, $sectionNumberDisplay, $settings)
                );
            }
        }

        return $entries;
    }

    /**
     * @return array<string,list<array<string,mixed>>>
     */
    private function loadChaptersGroupedByPart(int $versionId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, section_key, title, stable_anchor, sort_order
            FROM ipca_publishing_book_sections
            WHERE book_version_id = :version_id
              AND section_key REGEXP '^part_[0-9]+_chapter_[0-9]+$'
            ORDER BY section_key
        ");
        $stmt->execute(array(':version_id' => $versionId));
        $grouped = array();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $sectionKey = (string)($row['section_key'] ?? '');
            if (!preg_match('/^part_(\d+)_chapter_/', $sectionKey, $match)) {
                continue;
            }
            $partKey = 'part_' . $match[1];
            if (!isset($grouped[$partKey])) {
                $grouped[$partKey] = array();
            }
            $grouped[$partKey][] = $row;
        }

        foreach (array('part_1', 'part_2', 'part_3', 'part_4', 'annexes') as $partKey) {
            if (!isset($grouped[$partKey])) {
                continue;
            }
            usort($grouped[$partKey], static function (array $a, array $b): int {
                return strcmp((string)($a['section_key'] ?? ''), (string)($b['section_key'] ?? ''));
            });
        }

        return $grouped;
    }

    private function resolvePartTitle(int $versionId, string $partKey): string
    {
        $stmt = $this->pdo->prepare("
            SELECT title
            FROM ipca_publishing_book_sections
            WHERE book_version_id = :version_id
              AND section_key = :section_key
            LIMIT 1
        ");
        $stmt->execute(array(
            ':version_id' => $versionId,
            ':section_key' => $partKey,
        ));
        $title = trim((string)$stmt->fetchColumn());
        if ($title !== '') {
            return $title;
        }

        $defaults = array(
            'part_1' => 'Part 1 – General',
            'part_2' => 'Part 2 – Technical',
            'part_3' => 'Part 3 – Route',
            'part_4' => 'Part 4 – Personnel Training',
            'annexes' => 'Annexes',
        );

        return $defaults[$partKey] ?? '';
    }

    /**
     * @param list<array<string,mixed>> $chapters
     * @return array<string,mixed>
     */
    private function partContainerEntry(string $partKey, string $title, array $chapters): array
    {
        $anchor = '';
        $sectionId = 0;
        if ($chapters !== array()) {
            $first = $chapters[0];
            $sectionId = (int)($first['id'] ?? 0);
            $anchor = (string)($first['stable_anchor'] ?? '');
        }

        return array(
            'block_id' => 0,
            'section_id' => $sectionId,
            'target_anchor' => $anchor,
            'label' => $title,
            'text' => $title,
            'number' => '',
            'depth' => 0,
            'style' => 'title',
            'entry_type' => 'part_container',
            'page' => null,
            'part_key' => $partKey,
        );
    }

    /**
     * @param array<string,mixed> $chapter
     * @param array<int,string> $sectionNumberDisplay
     * @param array<string,bool> $settings
     * @return list<array<string,mixed>>
     */
    private function collectSectionTocEntries(
        int $versionId,
        array $chapter,
        array $sectionNumberDisplay,
        array $settings
    ): array {
        $sectionId = (int)($chapter['id'] ?? 0);
        if ($sectionId <= 0) {
            return array();
        }

        $stmt = $this->pdo->prepare("
            SELECT
              b.*,
              s.title AS section_title,
              s.section_key,
              s.parent_section_id,
              s.stable_anchor AS section_stable_anchor,
              s.sort_order AS section_sort
            FROM ipca_publishing_book_blocks b
            INNER JOIN ipca_publishing_book_sections s ON s.id = b.section_id
            WHERE b.book_version_id = :version_id
              AND b.section_id = :section_id
            ORDER BY b.sort_order, b.id
        ");
        $stmt->execute(array(
            ':version_id' => $versionId,
            ':section_id' => $sectionId,
        ));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();

        $entries = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $blockId = (int)($row['id'] ?? 0);
            $payload = $this->blocks->decodePayload($row);
            $style = $this->resolveParagraphStyle((string)($row['block_type'] ?? ''), $payload);
            if (!$this->isStyleIncluded($settings, $style)) {
                continue;
            }
            $text = $this->entryText((string)($row['block_type'] ?? ''), $payload);
            if ($text === '') {
                continue;
            }
            $blockAnchor = (string)($row['stable_anchor'] ?? '');
            $number = $sectionNumberDisplay[$blockId] ?? '';
            $label = $number !== '' ? $number . ' ' . $text : $text;
            if ($this->isSkippableTocEntry($number, $text, $label)) {
                continue;
            }
            $entries[] = array(
                'block_id' => $blockId,
                'section_id' => $sectionId,
                'target_anchor' => $blockAnchor,
                'label' => $label,
                'text' => $text,
                'number' => $number,
                'depth' => min(4, $this->styleDepth($style) + 1),
                'style' => $style,
                'entry_type' => 'block',
                'page' => null,
            );
        }

        return $entries;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,bool> $settings
     * @return array<string,mixed>|null
     */
    private function sectionTitleEntry(array $row, array $settings): ?array
    {
        if (!$this->isStyleIncluded($settings, 'title')) {
            return null;
        }
        $parentId = $row['parent_section_id'] ?? null;
        if ($parentId === null || (int)$parentId <= 0) {
            return null;
        }
        $title = trim((string)($row['section_title'] ?? ''));
        if ($title === '') {
            return null;
        }
        $sectionId = (int)($row['section_id'] ?? 0);
        $anchor = (string)($row['section_stable_anchor'] ?? '');
        return array(
            'block_id' => 0,
            'section_id' => $sectionId,
            'target_anchor' => $anchor,
            'label' => $title,
            'text' => $title,
            'number' => '',
            'depth' => 0,
            'style' => 'title',
            'entry_type' => 'section',
            'page' => null,
        );
    }

    /**
     * @param array<string,bool> $settings
     */
    public function isStyleIncluded(array $settings, string $style): bool
    {
        $style = ControlledPublishingBookStyleService::LEGACY_PARAGRAPH_STYLE_ALIASES[$style] ?? $style;
        if ($style === 'title') {
            return true;
        }
        $key = self::STYLE_SETTING_KEYS[$style] ?? null;
        if ($key === null) {
            return false;
        }
        return !empty($settings[$key]);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function entryText(string $blockType, array $payload): string
    {
        if ($blockType === 'heading') {
            return trim((string)($payload['text'] ?? ''));
        }
        if ($blockType === 'paragraph') {
            $html = (string)($payload['html'] ?? '');
            $text = trim(strip_tags($html));
            return $text !== '' ? $text : '';
        }
        if ($blockType === 'list') {
            $items = is_array($payload['items'] ?? null) ? $payload['items'] : array();
            return isset($items[0]) ? trim((string)$items[0]) : '';
        }
        return '';
    }

    private function resolveParagraphStyle(string $blockType, array $payload): string
    {
        $style = strtolower(trim((string)($payload['paragraph_style'] ?? '')));
        $style = ControlledPublishingBookStyleService::LEGACY_PARAGRAPH_STYLE_ALIASES[$style] ?? $style;
        if ($style === '' && $blockType === 'heading') {
            $level = max(1, min(6, (int)($payload['level'] ?? 2)));
            return $level <= 1 ? 'subtitle_2' : ($level === 2 ? 'subtitle_3' : 'subtitle_4');
        }
        if (!in_array($style, ControlledPublishingBookStyleService::TOC_PARAGRAPH_STYLE_KEYS, true)) {
            return '';
        }
        return $style;
    }

    private function styleDepth(string $style): int
    {
        $style = ControlledPublishingBookStyleService::LEGACY_PARAGRAPH_STYLE_ALIASES[$style] ?? $style;
        return match ($style) {
            'title' => 0,
            'subtitle_1' => 1,
            'subtitle_2' => 2,
            'subtitle_3' => 3,
            'subtitle_4' => 4,
            default => 0,
        };
    }

    /**
     * @return list<array{key:string,label:string,enabled:bool,locked:bool}>
     */
    public function tocSettingsForApi(array $settings): array
    {
        $out = array();
        foreach (self::STYLE_LABELS as $style => $label) {
            $key = self::STYLE_SETTING_KEYS[$style];
            $out[] = array(
                'key' => $key,
                'style' => $style,
                'label' => $label,
                'enabled' => $this->isStyleIncluded($settings, $style),
                'locked' => $style === 'title',
            );
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $raw
     * @param array<string,bool> $defaults
     * @return array<string,bool>
     */
    private function normalizeTocSettings(array $raw, array $defaults): array
    {
        $out = array();
        foreach ($defaults as $key => $default) {
            if ($key === 'include_title') {
                $out[$key] = true;
                continue;
            }
            $out[$key] = array_key_exists($key, $raw)
                ? !empty($raw[$key])
                : (bool)$default;
        }
        return $out;
    }

    private function tocSectionId(int $versionId): int
    {
        $stmt = $this->pdo->prepare("
            SELECT id FROM ipca_publishing_book_sections
            WHERE book_version_id = :version_id AND section_key = 'toc'
            LIMIT 1
        ");
        $stmt->execute(array(':version_id' => $versionId));
        return (int)$stmt->fetchColumn();
    }

    /**
     * @return array<string,mixed>
     */
    private function sectionRow(int $sectionId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_publishing_book_sections WHERE id = :id LIMIT 1');
        $stmt->execute(array(':id' => $sectionId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : array();
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function insertTocBlock(
        PDOStatement $ins,
        int $versionId,
        int $sectionId,
        string $stableBase,
        string $keySuffix,
        string $blockType,
        array $payload,
        int $sort,
        ?int $actorUserId
    ): void {
        $anchor = $stableBase . '-BLOCK-TOC-' . strtoupper($keySuffix);
        $hash = hash('sha256', $blockType . json_encode($payload, JSON_UNESCAPED_UNICODE));
        $ins->execute(array(
            ':book_version_id' => $versionId,
            ':section_id' => $sectionId,
            ':block_key' => 'toc_' . $keySuffix,
            ':stable_anchor' => substr($anchor, 0, 191),
            ':block_type' => $blockType,
            ':sort_order' => $sort,
            ':payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ':content_hash' => $hash,
            ':actor' => $actorUserId,
        ));
    }

    private function isSkippableTocEntry(string $number, string $text, string $label): bool
    {
        $number = trim($number);
        $text = trim($text);
        $label = trim($label);
        $ref = $number !== '' ? rtrim($number, '.') : '';
        if ($ref !== '' && ControlledPublishingDocxReader::isLikelyTableOrMeasurementExcerpt($ref, $text)) {
            return true;
        }
        if ($label !== '' && ControlledPublishingDocxReader::isLikelyTableOrMeasurementExcerpt($ref !== '' ? $ref : '0', $label)) {
            return true;
        }
        if (preg_match('/^\d{2,}$/', $ref) && (int)$ref > ControlledPublishingDocxReader::MAX_CHAPTER_NUMBER) {
            return true;
        }

        return false;
    }

    /**
     * @return array<string,mixed>
     */
    private function requireVersion(int $versionId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_publishing_book_versions WHERE id = :id LIMIT 1');
        $stmt->execute(array(':id' => $versionId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Version not found.');
        }
        return $row;
    }

    /**
     * @param array<string,mixed> $version
     * @return array<string,mixed>
     */
    private function decodeMeta(array $version): array
    {
        $raw = $version['metadata_json'] ?? '{}';
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string)$raw, true);
        return is_array($decoded) ? $decoded : array();
    }
}
