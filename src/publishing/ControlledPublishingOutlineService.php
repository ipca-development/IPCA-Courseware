<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingFoundationService.php';
require_once __DIR__ . '/ControlledPublishingManualStructureService.php';
require_once __DIR__ . '/ControlledPublishingPart0PageService.php';
require_once __DIR__ . '/ControlledPublishingSectionService.php';

/**
 * Author-editable PART / MAIN-chapter outline. Cover, Part 0, and Annexes stay locked.
 */
final class ControlledPublishingOutlineService
{
    /** @var list<string> */
    public const PART_KEYS = array('part_1', 'part_2', 'part_3', 'part_4');

    /** @var list<string> */
    public const LOCKED_NAV_LABELS = array(
        'Cover Page',
        ControlledPublishingPart0PageService::PART_TITLE,
        'ANNEXES',
    );

    /** @var list<string> */
    private const PROTECTED_KEYS = array(
        'cover',
        'toc',
        'lep',
        'revision_system',
        'amendment_list',
        'distribution_list',
        'abbreviations',
        'definitions',
        'highlights',
        'annexes',
        'annexes_register',
        'annexes_cross_ref',
        'annexes_highlights',
    );

    public function __construct(
        private PDO $pdo,
        private ControlledPublishingFoundationService $foundation,
        private ControlledPublishingSectionService $sections,
        private ControlledPublishingManualStructureService $manualStructure
    ) {
    }

    public static function isProtectedSectionKey(string $sectionKey): bool
    {
        $sectionKey = strtolower(trim($sectionKey));
        if ($sectionKey === '') {
            return true;
        }
        if (in_array($sectionKey, self::PROTECTED_KEYS, true)) {
            return true;
        }

        return str_starts_with($sectionKey, 'annexes_');
    }

    public static function isOutlineLocked(array $row): bool
    {
        $meta = self::decodeMeta($row);

        return !empty($meta['outline_locked']);
    }

    /**
     * Sidebar / TOC / header label for a PART row.
     */
    public static function partNavTitle(array $row, string $fallback = ''): string
    {
        $title = trim((string)($row['title'] ?? ''));
        $meta = self::decodeMeta($row);
        $nav = trim((string)($meta['nav_label'] ?? ''));
        $raw = $nav !== '' ? $nav : $title;
        if ($raw === '') {
            $raw = $fallback;
        }
        $partNumber = self::partNumberFromRow($row);
        if ($partNumber > 0) {
            return self::formatPartNavTitle($partNumber, $raw);
        }

        $formatted = ControlledPublishingPart0PageService::formatPartLabel($raw);
        return $formatted !== '' ? $formatted : $fallback;
    }

    public static function formatPartNavTitle(int $partNumber, string $raw): string
    {
        $name = self::stripPartNumberPrefix($raw);
        if ($name === '') {
            $name = 'Part ' . $partNumber;
        }

        return 'PART ' . $partNumber . ' – ' . mb_strtoupper($name, 'UTF-8');
    }

    public static function formatChapterNavTitle(int $chapterNumber, string $raw): string
    {
        $name = self::stripChapterNumberPrefix($raw);
        if ($name === '') {
            $name = 'Chapter ' . $chapterNumber;
        }

        return $chapterNumber . '. ' . $name;
    }

    /**
     * @return array{
     *   locked:list<array{kind:string,title:string}>,
     *   parts:list<array<string,mixed>>
     * }
     */
    public function getOutline(int $versionId): array
    {
        $this->requireVersion($versionId);
        $flat = $this->sections->listFlatSections($versionId);
        $byKey = array();
        $childrenByParent = array();
        foreach ($flat as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = (string)($row['section_key'] ?? '');
            $byKey[$key] = $row;
            $parentId = $row['parent_section_id'] !== null ? (int)$row['parent_section_id'] : 0;
            $childrenByParent[$parentId][] = $row;
        }

        $version = $this->requireVersion($versionId);
        $manualCode = strtoupper(trim((string)(($version['manual_code'] ?? '') !== '' ? $version['manual_code'] : ($version['book_key'] ?? ''))));
        $sectionNumberDisplay = $this->manualStructure->computeSectionNumberDisplay($versionId, $manualCode);

        $parts = array();
        foreach (self::PART_KEYS as $partKey) {
            $row = $byKey[$partKey] ?? null;
            if ($partKey === 'part_1' && $row === null) {
                $row = $byKey['main_content'] ?? null;
            }
            if (!is_array($row)) {
                continue;
            }
            $partNumber = self::partNumberFromRow($row);
            $chapterParentId = $this->manualStructure->resolvePartParentSectionId($versionId, $partKey);
            if ($chapterParentId <= 0) {
                $chapterParentId = (int)($row['id'] ?? 0);
            }
            $partNav = self::partNavTitle($row, 'PART ' . $partNumber);
            $chapters = array();
            foreach ($childrenByParent[$chapterParentId] ?? array() as $child) {
                if (!$this->manualStructure->isValidChapterNavEntry($child)) {
                    continue;
                }
                if (self::isProtectedSectionKey((string)($child['section_key'] ?? ''))) {
                    continue;
                }
                $chapterNumber = $this->chapterNumberFromRow($child);
                $sectionId = (int)($child['id'] ?? 0);
                $meta = self::decodeMeta($child);
                $lockedLabel = ControlledPublishingManualStructureService::navLabelForSection($child, true);
                $importedLabel = !empty($meta['outline_locked'])
                    ? ''
                    : $this->manualStructure->chapterTitleFromImportedBlocks($sectionId, $chapterNumber);
                $label = $importedLabel !== ''
                    ? ControlledPublishingManualStructureService::uppercaseNavLabel($importedLabel)
                    : $lockedLabel;
                $title = self::stripChapterNumberPrefix($label);
                $chapters[] = array(
                    'section_id' => $sectionId,
                    'chapter_number' => $chapterNumber,
                    'title' => $title,
                    'nav_label' => $label,
                    'block_count' => (int)($child['block_count'] ?? 0),
                    'sort_order' => (int)($child['sort_order'] ?? 0),
                    'headings' => self::headingTreeFromNavItems(
                        $this->manualStructure->listNavSubsectionsFromChapterBlocks(
                            $sectionId,
                            $sectionNumberDisplay,
                            $partNumber
                        )
                    ),
                );
            }
            usort($chapters, static function (array $a, array $b): int {
                return ($a['chapter_number'] <=> $b['chapter_number']) ?: ($a['sort_order'] <=> $b['sort_order']);
            });
            $parts[] = array(
                'section_id' => (int)($row['id'] ?? 0),
                'chapter_parent_id' => $chapterParentId,
                'section_key' => (string)($row['section_key'] ?? $partKey),
                'part_number' => $partNumber,
                'title' => self::stripPartNumberPrefix($partNav),
                'nav_label' => $partNav,
                'chapters' => $chapters,
            );
        }

        return array(
            'locked' => array(
                array('kind' => 'cover', 'title' => 'Cover Page'),
                array('kind' => 'part0', 'title' => ControlledPublishingPart0PageService::PART_TITLE),
                array('kind' => 'annexes', 'title' => 'ANNEXES'),
            ),
            'parts' => $parts,
        );
    }

    public function renamePart(int $versionId, int $sectionId, string $title, ?int $actorUserId = null): void
    {
        $row = $this->requireEditableOutlineSection($versionId, $sectionId);
        if (!$this->isPartRow($row)) {
            throw new RuntimeException('Only PART titles can be renamed here.');
        }
        $partNumber = self::partNumberFromRow($row);
        $navLabel = self::formatPartNavTitle($partNumber, $title);
        $this->updateOutlineSection($sectionId, $navLabel, $navLabel, $this->lockedMeta($row, array(
            'manual_part' => $partNumber,
        )));
        unset($actorUserId);
    }

    public function renameChapter(int $versionId, int $sectionId, string $title, ?int $actorUserId = null): void
    {
        $row = $this->requireEditableOutlineSection($versionId, $sectionId);
        $chapterNumber = $this->chapterNumberFromRow($row);
        if ($chapterNumber <= 0 || $this->isPartRow($row)) {
            throw new RuntimeException('Only MAIN chapter titles can be renamed here.');
        }
        $name = self::stripChapterNumberPrefix($title);
        $navLabel = self::formatChapterNavTitle($chapterNumber, $name);
        $this->updateOutlineSection($sectionId, $name, $navLabel, $this->lockedMeta($row, array(
            'chapter_number' => $chapterNumber,
        )));
        unset($actorUserId);
    }

    public function addChapter(int $versionId, int $partSectionId, string $title, ?int $actorUserId = null): int
    {
        $part = $this->requireEditableOutlineSection($versionId, $partSectionId);
        if (!$this->isPartRow($part)) {
            throw new RuntimeException('MAIN chapters can only be added under a PART.');
        }
        $partNumber = self::partNumberFromRow($part);
        $nextNumber = 1;
        foreach ($this->listChapterChildren($versionId, $partSectionId) as $child) {
            $nextNumber = max($nextNumber, $this->chapterNumberFromRow($child) + 1);
        }
        $name = self::stripChapterNumberPrefix($title);
        if ($name === '') {
            $name = 'Chapter ' . $nextNumber;
        }
        $sectionId = $this->manualStructure->ensureChapterSection(
            $versionId,
            $partNumber,
            $nextNumber,
            $name,
            $actorUserId
        );
        $row = $this->sections->getSection($versionId, $sectionId);
        if ($row === null) {
            throw new RuntimeException('Could not create MAIN chapter.');
        }
        $navLabel = self::formatChapterNavTitle($nextNumber, $name);
        $this->updateOutlineSection($sectionId, $name, $navLabel, $this->lockedMeta($row, array(
            'chapter_number' => $nextNumber,
            'manual_part' => $partNumber,
        )));

        return $sectionId;
    }

    public function deleteChapter(int $versionId, int $sectionId, ?int $actorUserId = null): void
    {
        $row = $this->requireEditableOutlineSection($versionId, $sectionId);
        if ($this->isPartRow($row) || $this->chapterNumberFromRow($row) <= 0) {
            throw new RuntimeException('Only MAIN chapters can be removed from the outline.');
        }
        $parentId = (int)($row['parent_section_id'] ?? 0);
        $stmt = $this->pdo->prepare('DELETE FROM ipca_publishing_book_sections WHERE id = :id AND book_version_id = :version_id');
        $stmt->execute(array(
            ':id' => $sectionId,
            ':version_id' => $versionId,
        ));
        unset($actorUserId);
        if ($parentId > 0) {
            $this->renumberChapters($versionId, $parentId);
        }
    }

    /**
     * Lift a nested heading (4.1, 1.1, …) onto the PART as a MAIN chapter.
     */
    public function promoteHeading(
        int $versionId,
        int $sectionId,
        string $sectionRef,
        int $insertBeforeSectionId = 0,
        ?int $actorUserId = null
    ): int {
        $source = $this->requireEditableOutlineSection($versionId, $sectionId);
        $parentId = (int)($source['parent_section_id'] ?? 0);
        if ($parentId <= 0 || $this->chapterNumberFromRow($source) <= 0) {
            throw new RuntimeException('Headings can only be promoted from a MAIN chapter.');
        }
        $sectionRef = trim($sectionRef);
        if ($sectionRef === '' || preg_match('/^\d+(?:\.\d+)+$/', $sectionRef) !== 1) {
            throw new RuntimeException('Choose a heading under a MAIN chapter.');
        }

        $chapters = $this->listChapterChildren($versionId, $parentId);
        $insertAt = count($chapters);
        if ($insertBeforeSectionId > 0) {
            foreach ($chapters as $index => $child) {
                if ((int)($child['id'] ?? 0) === $insertBeforeSectionId) {
                    $insertAt = $index;
                    break;
                }
            }
        } else {
            foreach ($chapters as $index => $child) {
                if ((int)($child['id'] ?? 0) !== $sectionId) {
                    continue;
                }
                $headingIndex = 0;
                foreach (self::headingTreeFromNavItems(
                    $this->manualStructure->listNavSubsectionsFromChapterBlocks($sectionId, array())
                ) as $siblingIndex => $heading) {
                    if ((string)($heading['section_ref'] ?? '') === $sectionRef) {
                        $headingIndex = (int)$siblingIndex;
                        break;
                    }
                }
                $insertAt = $index + $headingIndex;
                break;
            }
        }

        $newId = $this->manualStructure->promoteHeadingToMainChapter(
            $versionId,
            $sectionId,
            $sectionRef,
            $actorUserId
        );
        if ($newId <= 0) {
            throw new RuntimeException('Could not make that heading a MAIN chapter.');
        }

        $ordered = array();
        foreach ($this->listChapterChildren($versionId, $parentId) as $child) {
            if ((int)($child['id'] ?? 0) === $newId) {
                continue;
            }
            $ordered[] = $child;
        }
        $newRow = $this->sections->getSection($versionId, $newId);
        if (!is_array($newRow)) {
            throw new RuntimeException('Promoted MAIN chapter could not be loaded.');
        }
        array_splice($ordered, max(0, min($insertAt, count($ordered))), 0, array($newRow));
        $this->writeChapterOrder($versionId, $parentId, $ordered);

        $remaining = $this->manualStructure->listNavSubsectionsFromChapterBlocks($sectionId, array());
        $sourceRow = $this->sections->getSection($versionId, $sectionId);
        $blockCount = is_array($sourceRow) ? (int)($sourceRow['block_count'] ?? 0) : 1;
        if ($remaining === array() && $blockCount <= 0) {
            $this->deleteChapter($versionId, $sectionId, $actorUserId);
        }

        return $newId;
    }

    /**
     * @param list<array{section_ref?:string,title?:string,nav_label?:string}> $items
     * @return list<array{section_ref:string,title:string,nav_label:string,can_promote:bool,headings:list<array<string,mixed>>}>
     */
    public static function headingTreeFromNavItems(array $items): array
    {
        $nodes = array();
        foreach ($items as $item) {
            $ref = trim((string)($item['section_ref'] ?? ''));
            if ($ref === '') {
                continue;
            }
            $title = trim((string)($item['title'] ?? ''));
            $nodes[$ref] = array(
                'section_ref' => $ref,
                'title' => $title,
                'nav_label' => trim((string)($item['nav_label'] ?? '')) !== ''
                    ? (string)$item['nav_label']
                    : $ref . ' ' . $title,
                'can_promote' => false,
                'headings' => array(),
            );
        }

        $childRefs = array();
        $rootRefs = array();
        foreach ($nodes as $ref => $node) {
            unset($node);
            $parent = self::parentHeadingRef((string)$ref);
            if ($parent !== '' && isset($nodes[$parent])) {
                $childRefs[$parent][] = (string)$ref;
            } else {
                $rootRefs[] = (string)$ref;
            }
        }

        $build = static function (string $ref) use (&$build, $nodes, $childRefs): array {
            $node = $nodes[$ref];
            $node['headings'] = array();
            foreach ($childRefs[$ref] ?? array() as $childRef) {
                $node['headings'][] = $build((string)$childRef);
            }
            return $node;
        };

        $roots = array();
        foreach ($rootRefs as $ref) {
            $node = $build((string)$ref);
            $node['can_promote'] = true;
            $roots[] = $node;
        }

        return $roots;
    }

    public static function parentHeadingRef(string $sectionRef): string
    {
        $sectionRef = trim($sectionRef);
        $pos = strrpos($sectionRef, '.');
        if ($pos === false) {
            return '';
        }

        return substr($sectionRef, 0, $pos);
    }

    public static function rewritePromotedSectionRef(string $oldRef, string $blockRef, int $newChapter): string
    {
        $oldRef = trim($oldRef);
        $blockRef = trim($blockRef);
        if ($oldRef === '' || $newChapter <= 0) {
            return $blockRef;
        }
        if ($blockRef === $oldRef) {
            return (string)$newChapter;
        }
        $prefix = $oldRef . '.';
        if (str_starts_with($blockRef, $prefix)) {
            $rest = substr($blockRef, strlen($prefix));

            return $rest === '' ? (string)$newChapter : $newChapter . '.' . $rest;
        }

        return $blockRef;
    }

    /**
     * @param list<array<string,mixed>> $nodes
     * @return list<array<string,mixed>>
     */
    public static function headingBlockSlice(array $nodes, string $sectionRef): array
    {
        $sectionRef = trim($sectionRef);
        $start = -1;
        foreach ($nodes as $index => $node) {
            if (!is_array($node)) {
                continue;
            }
            if (trim((string)($node['section_ref'] ?? '')) === $sectionRef) {
                $start = (int)$index;
                break;
            }
        }
        if ($start < 0) {
            return array();
        }

        $prefix = $sectionRef . '.';
        $slice = array();
        $count = count($nodes);
        for ($index = $start; $index < $count; $index++) {
            $node = $nodes[$index];
            if (!is_array($node)) {
                continue;
            }
            $ref = trim((string)($node['section_ref'] ?? ''));
            if ($index > $start && $ref !== '' && $ref !== $sectionRef && !str_starts_with($ref, $prefix)) {
                break;
            }
            $slice[] = $node;
        }

        return $slice;
    }

    public function moveChapter(int $versionId, int $sectionId, string $direction, ?int $actorUserId = null): void
    {
        $row = $this->requireEditableOutlineSection($versionId, $sectionId);
        $parentId = (int)($row['parent_section_id'] ?? 0);
        if ($parentId <= 0 || $this->chapterNumberFromRow($row) <= 0) {
            throw new RuntimeException('Only MAIN chapters can be reordered.');
        }
        $chapters = $this->listChapterChildren($versionId, $parentId);
        $index = -1;
        foreach ($chapters as $i => $child) {
            if ((int)($child['id'] ?? 0) === $sectionId) {
                $index = $i;
                break;
            }
        }
        if ($index < 0) {
            throw new RuntimeException('MAIN chapter not found in this PART.');
        }
        $swapWith = $direction === 'up' ? $index - 1 : $index + 1;
        if (!isset($chapters[$swapWith])) {
            return;
        }
        $a = $chapters[$index];
        $chapters[$index] = $chapters[$swapWith];
        $chapters[$swapWith] = $a;
        $this->writeChapterOrder($versionId, $parentId, $chapters);
        unset($actorUserId);
    }

    /**
     * @return array<string,mixed>
     */
    private function requireVersion(int $versionId): array
    {
        $version = $this->foundation->getVersion($versionId);
        if ($version === null) {
            throw new RuntimeException('Book version not found.');
        }
        return $version;
    }

    /**
     * @return array<string,mixed>
     */
    private function requireEditableOutlineSection(int $versionId, int $sectionId): array
    {
        $version = $this->requireVersion($versionId);
        if ((string)($version['lifecycle_status'] ?? '') === 'released') {
            throw new RuntimeException('Released versions cannot be restructured.');
        }
        $row = $this->sections->getSection($versionId, $sectionId);
        if ($row === null) {
            throw new RuntimeException('Section not found.');
        }
        if (self::isProtectedSectionKey((string)($row['section_key'] ?? ''))) {
            throw new RuntimeException('Cover, Part 0, and Annexes cannot be edited in the outline.');
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function isPartRow(array $row): bool
    {
        $key = (string)($row['section_key'] ?? '');

        return in_array($key, self::PART_KEYS, true) || $key === 'main_content';
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function partNumberFromRow(array $row): int
    {
        $key = (string)($row['section_key'] ?? '');
        if ($key === 'main_content') {
            return 1;
        }
        if (preg_match('/^part_(\d+)$/', $key, $m) === 1) {
            return (int)$m[1];
        }
        $meta = self::decodeMeta($row);

        return (int)($meta['manual_part'] ?? 0);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function chapterNumberFromRow(array $row): int
    {
        $meta = self::decodeMeta($row);
        if (!empty($meta['chapter_number'])) {
            return (int)$meta['chapter_number'];
        }
        $key = (string)($row['section_key'] ?? '');
        if (preg_match('/_chapter_(\d+)$/', $key, $m) === 1) {
            return (int)$m[1];
        }

        return 0;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function listChapterChildren(int $versionId, int $parentId): array
    {
        $out = array();
        foreach ($this->sections->listFlatSections($versionId) as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ((int)($row['parent_section_id'] ?? 0) !== $parentId) {
                continue;
            }
            if ($this->chapterNumberFromRow($row) <= 0) {
                continue;
            }
            if (self::isProtectedSectionKey((string)($row['section_key'] ?? ''))) {
                continue;
            }
            $out[] = $row;
        }
        usort($out, function (array $a, array $b): int {
            $na = $this->chapterNumberFromRow($a);
            $nb = $this->chapterNumberFromRow($b);
            if ($na !== $nb) {
                return $na <=> $nb;
            }
            return ((int)($a['sort_order'] ?? 0)) <=> ((int)($b['sort_order'] ?? 0));
        });

        return $out;
    }

    private function renumberChapters(int $versionId, int $parentId): void
    {
        $this->writeChapterOrder($versionId, $parentId, $this->listChapterChildren($versionId, $parentId));
    }

    /**
     * @param list<array<string,mixed>> $chapters
     */
    private function writeChapterOrder(int $versionId, int $parentId, array $chapters): void
    {
        $parent = $this->sections->getSection($versionId, $parentId);
        $partNumber = is_array($parent) ? self::partNumberFromRow($parent) : 0;
        $partKey = is_array($parent) ? (string)($parent['section_key'] ?? 'part_1') : 'part_1';
        if ($partKey === 'main_content') {
            $partKey = 'part_1';
        }
        $pending = array();
        $number = 1;
        foreach ($chapters as $row) {
            $sectionId = (int)($row['id'] ?? 0);
            if ($sectionId <= 0) {
                continue;
            }
            $name = self::stripChapterNumberPrefix((string)($row['title'] ?? ''));
            if ($name === '') {
                $name = 'Chapter ' . $number;
            }
            $navLabel = self::formatChapterNavTitle($number, $name);
            $meta = $this->lockedMeta($row, array(
                'chapter_number' => $number,
                'manual_part' => $partNumber,
            ));
            $this->updateOutlineSection(
                $sectionId,
                $name,
                $navLabel,
                $meta,
                substr($partKey . '_chapter_tmp_' . $sectionId, 0, 120),
                $number * 10
            );
            $pending[] = array(
                'section_id' => $sectionId,
                'name' => $name,
                'nav_label' => $navLabel,
                'meta' => $meta,
                'number' => $number,
            );
            $number++;
        }
        foreach ($pending as $item) {
            $this->updateOutlineSection(
                (int)$item['section_id'],
                (string)$item['name'],
                (string)$item['nav_label'],
                $item['meta'],
                substr($partKey . '_chapter_' . (int)$item['number'], 0, 120),
                (int)$item['number'] * 10
            );
        }
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function lockedMeta(array $row, array $extra = array()): array
    {
        $meta = self::decodeMeta($row);
        foreach ($extra as $key => $value) {
            $meta[$key] = $value;
        }
        $meta['outline_locked'] = true;
        $meta['synced_from_canonical'] = true;

        return $meta;
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function updateOutlineSection(
        int $sectionId,
        string $title,
        string $navLabel,
        array $metadata,
        ?string $sectionKey = null,
        ?int $sortOrder = null
    ): void {
        $metadata['nav_label'] = $navLabel;
        $fields = 'title = :title, metadata_json = :metadata_json, updated_at = CURRENT_TIMESTAMP';
        $params = array(
            ':title' => $title,
            ':metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ':id' => $sectionId,
        );
        if ($sectionKey !== null) {
            $fields .= ', section_key = :section_key';
            $params[':section_key'] = $sectionKey;
        }
        if ($sortOrder !== null) {
            $fields .= ', sort_order = :sort_order';
            $params[':sort_order'] = $sortOrder;
        }
        $stmt = $this->pdo->prepare("UPDATE ipca_publishing_book_sections SET {$fields} WHERE id = :id");
        $stmt->execute($params);
    }

    public static function stripPartNumberPrefix(string $title): string
    {
        $title = trim($title);
        $title = preg_replace('/^PART\s+\d+\s*[–\-—:]\s*/iu', '', $title) ?? $title;

        return trim($title);
    }

    public static function stripChapterNumberPrefix(string $title): string
    {
        $title = trim($title);
        $title = preg_replace('/^\d+(?:\.\d+)*\.?\s+/u', '', $title) ?? $title;

        return trim($title);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function decodeMeta(array $row): array
    {
        $raw = $row['metadata_json'] ?? '{}';
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string)$raw, true);

        return is_array($decoded) ? $decoded : array();
    }
}
