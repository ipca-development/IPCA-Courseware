<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingAnnexService.php';
require_once __DIR__ . '/ControlledPublishingManualStructureService.php';
require_once __DIR__ . '/ControlledPublishingPart0PageService.php';
require_once __DIR__ . '/ControlledPublishingSectionService.php';

/**
 * Builds the hierarchical sidebar navigation for the book editor.
 */
final class ControlledPublishingEditorNavService
{
    /** @var array<string,list<array{section_key:string,title:string,sort_order:int}>> */
    private const BOOK_PARTS = array(
        'OM' => array(
            array('section_key' => 'part_1', 'title' => 'PART 1 – General', 'sort_order' => 100),
            array('section_key' => 'part_2', 'title' => 'PART 2 – Technical', 'sort_order' => 110),
            array('section_key' => 'part_3', 'title' => 'PART 3 – Route', 'sort_order' => 120),
            array('section_key' => 'part_4', 'title' => 'PART 4 – Personnel Training', 'sort_order' => 130),
        ),
        'OMM' => array(
            array('section_key' => 'part_1', 'title' => 'PART 1 – General', 'sort_order' => 100),
            array('section_key' => 'part_2', 'title' => 'PART 2 – Compliance Monitoring', 'sort_order' => 110),
            array('section_key' => 'part_3', 'title' => 'PART 3 – Safety Management', 'sort_order' => 120),
        ),
    );

    /** @var list<string> */
    private const PART0_AFTER_TOC_KEYS = array(
        'lep',
        'revision_system',
        'amendment_list',
        'distribution_list',
        'abbreviations',
        'definitions',
        'highlights',
    );

    public function __construct(
        private ControlledPublishingSectionService $sections,
        private ControlledPublishingManualStructureService $manualStructure
    ) {
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function buildNavTree(int $versionId, string $bookKey): array
    {
        $flat = $this->sections->listFlatSections($versionId);
        $byKey = array();
        $childrenByParent = array();
        foreach ($flat as $row) {
            $key = (string)($row['section_key'] ?? '');
            $id = (int)($row['id'] ?? 0);
            $byKey[$key] = $row;
            $parentId = $row['parent_section_id'] !== null ? (int)$row['parent_section_id'] : 0;
            if (!isset($childrenByParent[$parentId])) {
                $childrenByParent[$parentId] = array();
            }
            $childrenByParent[$parentId][] = $row;
        }

        if (!isset($byKey['part_1']) && isset($byKey['main_content'])) {
            $byKey['part_1'] = $byKey['main_content'];
        }

        $version = $this->manualStructure->resolveVersion($versionId);
        $manualCode = strtoupper(trim((string)(($version['manual_code'] ?? '') !== '' ? $version['manual_code'] : $bookKey)));
        $sectionNumberDisplay = $this->manualStructure->computeSectionNumberDisplay($versionId, $manualCode);

        $tree = array();

        if (isset($byKey['cover'])) {
            $tree[] = $this->leafNode($byKey['cover'], 'Cover Page');
        }

        $tree[] = $this->separatorNode('after_cover');
        $tree[] = $this->buildPart0Group($byKey);

        $tree[] = $this->separatorNode('after_part0');

        foreach (self::BOOK_PARTS[$bookKey] ?? self::BOOK_PARTS['OM'] as $partDef) {
            $partKey = (string)$partDef['section_key'];
            if (!isset($byKey[$partKey]) && !($partKey === 'part_1' && isset($byKey['main_content']))) {
                continue;
            }
            $partRow = $byKey[$partKey] ?? $byKey['main_content'];
            $partId = $this->resolvePartParentId($partKey, $byKey, $childrenByParent);
            $manualPart = $this->manualPartIndexFromKey($partKey);
            $subsections = $this->formatChapterSubsections(
                $childrenByParent[$partId] ?? array(),
                $manualPart,
                $sectionNumberDisplay
            );
            if ($subsections !== array()) {
                $tree[] = $this->groupNode('group_' . $partKey, (string)$partDef['title'], $subsections);
            } else {
                $tree[] = $this->leafNode($partRow, (string)$partDef['title']);
            }
        }

        $tree[] = $this->separatorNode('before_annexes');

        if (isset($byKey['annexes'])) {
            $annexRow = $byKey['annexes'];
            $annexId = (int)($annexRow['id'] ?? 0);
            $annexChildren = $this->formatAnnexSubsections($childrenByParent[$annexId] ?? array());
            if ($annexChildren !== array()) {
                $tree[] = $this->groupNode('group_annexes', 'ANNEXES', $annexChildren, array(
                    'label_style' => 'chapter_upper',
                ));
            } else {
                $tree[] = $this->leafNode($annexRow, 'ANNEXES');
            }
        }

        return $tree;
    }

    /**
     * @param array<string,array<string,mixed>> $byKey
     * @return array<string,mixed>
     */
    private function buildPart0Group(array $byKey): array
    {
        $outlineChildren = array();
        if (isset($byKey['toc'])) {
            $outlineChildren[] = $this->leafNode($byKey['toc'], 'Table of Contents', array(
                'label_style' => 'part0',
                'truncate' => true,
            ));
        }
        foreach (self::PART0_AFTER_TOC_KEYS as $sectionKey) {
            if (!isset($byKey[$sectionKey])) {
                continue;
            }
            $label = $this->part0NavLabel($sectionKey);
            $outlineChildren[] = $this->leafNode($byKey[$sectionKey], $label, array(
                'label_style' => 'part0',
                'truncate' => true,
            ));
        }

        $outlineGroup = $this->groupNode(
            'group_part0_outline',
            ControlledPublishingPart0PageService::OUTLINE_TITLE,
            $outlineChildren,
            array('label_style' => 'chapter_upper', 'truncate' => true)
        );

        return $this->groupNode(
            'group_part0',
            ControlledPublishingPart0PageService::PART_TITLE,
            array($outlineGroup)
        );
    }

    /**
     * @param array<string,array<string,mixed>> $byKey
     * @param array<int,list<array<string,mixed>>> $childrenByParent
     */
    private function resolvePartParentId(string $partKey, array $byKey, array $childrenByParent): int
    {
        if ($partKey === 'part_1') {
            $mainContent = $byKey['main_content'] ?? null;
            $part1 = $byKey['part_1'] ?? null;
            if (is_array($mainContent) && !is_array($part1)) {
                return (int)($mainContent['id'] ?? 0);
            }
            if (is_array($mainContent) && is_array($part1)) {
                $mainId = (int)($mainContent['id'] ?? 0);
                $partId = (int)($part1['id'] ?? 0);
                $mainChildren = $childrenByParent[$mainId] ?? array();
                $partChildren = $childrenByParent[$partId] ?? array();
                if ($mainChildren !== array() && $partChildren === array()) {
                    return $mainId;
                }
                return $partId;
            }
        }

        $partRow = $byKey[$partKey] ?? null;
        return is_array($partRow) ? (int)($partRow['id'] ?? 0) : 0;
    }

    private function manualPartIndexFromKey(string $partKey): int
    {
        if ($partKey === 'main_content') {
            return 1;
        }
        if (preg_match('/^part_(\d+)$/', $partKey, $m)) {
            return (int)$m[1];
        }
        return 0;
    }

    private function part0NavLabel(string $sectionKey): string
    {
        $registry = ControlledPublishingPart0PageService::PAGE_REGISTRY[$sectionKey] ?? null;
        if ($registry === null) {
            return $sectionKey;
        }
        if ($sectionKey === 'lep') {
            return '0.1 List of Effective Parts';
        }
        return $registry['number'] . ' ' . $registry['label'];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function formatChapterSubsections(
        array $rows,
        int $manualPart,
        array $sectionNumberDisplay
    ): array {
        $nodes = array();
        foreach ($rows as $row) {
            if (!$this->manualStructure->isValidChapterNavEntry($row)) {
                continue;
            }
            $label = ControlledPublishingManualStructureService::navLabelForSection($row, true);
            $meta = $this->decodeMeta($row);
            $chapterNumber = (int)($meta['chapter_number'] ?? 0);
            $sectionId = (int)($row['id'] ?? 0);

            $navItems = $this->manualStructure->listNavSubsectionsFromChapterBlocks(
                $sectionId,
                $sectionNumberDisplay,
                $manualPart
            );

            $subtitleChildren = $this->buildSubtitleNavTree($sectionId, $navItems);

            if ($subtitleChildren !== array()) {
                $nodes[] = $this->groupNode(
                    'chapter:' . $sectionId,
                    $label,
                    $subtitleChildren,
                    array(
                        'label_style' => 'chapter_upper',
                        'truncate' => true,
                        'section_id' => $sectionId,
                        'is_navigable' => true,
                    )
                );
            } else {
                $nodes[] = $this->leafNode($row, $label, array(
                    'label_style' => 'chapter_upper',
                    'truncate' => true,
                ));
            }
        }
        return $nodes;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function formatAnnexSubsections(array $rows): array
    {
        $register = null;
        $highlights = null;
        $content = array();

        foreach ($rows as $row) {
            $key = (string)($row['section_key'] ?? '');
            if ($key === ControlledPublishingAnnexService::REGISTER_SECTION_KEY) {
                $register = $row;
                continue;
            }
            if ($key === ControlledPublishingAnnexService::HIGHLIGHTS_SECTION_KEY) {
                $highlights = $row;
                continue;
            }
            if (str_starts_with($key, ControlledPublishingAnnexService::ANNEX_SECTION_PREFIX)) {
                $content[] = $row;
            }
        }

        usort($content, static function (array $a, array $b): int {
            return strcmp((string)($a['section_key'] ?? ''), (string)($b['section_key'] ?? ''));
        });

        $nodes = array();
        if (is_array($register)) {
            $nodes[] = $this->leafNode($register, 'Annex Register', array(
                'label_style' => 'part0',
                'truncate' => true,
            ));
        }
        if (is_array($highlights)) {
            $nodes[] = $this->leafNode($highlights, 'Annex Highlight of Changes', array(
                'label_style' => 'part0',
                'truncate' => true,
            ));
        }
        foreach ($content as $row) {
            $title = trim((string)($row['title'] ?? ''));
            $label = $title !== '' ? $title : 'Annex';
            $nodes[] = $this->leafNode($row, $label, array(
                'truncate' => true,
            ));
        }

        return $nodes;
    }

    /**
     * @param list<array{section_ref:string,title:string,nav_label:string}> $items
     * @return list<array<string,mixed>>
     */
    private function buildSubtitleNavTree(int $sectionId, array $items): array
    {
        if ($items === array()) {
            return array();
        }

        $nodes = array();
        foreach ($items as $item) {
            $ref = (string)($item['section_ref'] ?? '');
            if ($ref === '') {
                continue;
            }
            $nodes[$ref] = $this->virtualSubtitleNode(
                $sectionId,
                $ref,
                (string)($item['nav_label'] ?? $ref)
            );
        }

        foreach ($items as $item) {
            $ref = (string)($item['section_ref'] ?? '');
            if ($ref === '' || !isset($nodes[$ref])) {
                continue;
            }
            $parentRef = $this->parentNavSectionRef($ref);
            if ($parentRef !== '' && isset($nodes[$parentRef])) {
                $nodes[$parentRef]['children'][] = &$nodes[$ref];
                $nodes[$parentRef]['is_group'] = true;
                $nodes[$parentRef]['is_navigable'] = true;
            }
        }
        unset($item);

        $roots = array();
        foreach ($nodes as $ref => $node) {
            $parentRef = $this->parentNavSectionRef((string)$ref);
            if ($parentRef !== '' && isset($nodes[$parentRef])) {
                continue;
            }
            $roots[] = $node;
        }

        return $roots;
    }

    private function parentNavSectionRef(string $sectionRef): string
    {
        $sectionRef = trim($sectionRef);
        $pos = strrpos($sectionRef, '.');
        if ($pos === false) {
            return '';
        }
        $parent = substr($sectionRef, 0, $pos);
        if ($parent === '' || !str_contains($parent, '.')) {
            return '';
        }

        return $parent;
    }

    /**
     * @return array<string,mixed>
     */
    private function virtualSubtitleNode(int $sectionId, string $sectionRef, string $label): array
    {
        return array(
            'id' => $sectionId,
            'nav_id' => 'canonical:' . $sectionId . ':' . $sectionRef,
            'parent_section_id' => null,
            'section_key' => '',
            'title' => $label,
            'section_type' => 'nav_virtual',
            'stable_anchor' => '',
            'allow_author_blocks' => false,
            'is_system_managed' => true,
            'is_generated' => false,
            'block_count' => 0,
            'is_group' => false,
            'is_separator' => false,
            'is_navigable' => true,
            'scroll_section_ref' => $sectionRef,
            'label_style' => 'subtitle',
            'truncate' => true,
            'children' => array(),
        );
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function leafNode(array $row, string $title, array $extra = array()): array
    {
        $id = (int)($row['id'] ?? 0);
        return array_merge(array(
            'id' => $id,
            'nav_id' => 'section:' . $id,
            'parent_section_id' => $row['parent_section_id'] !== null ? (int)$row['parent_section_id'] : null,
            'section_key' => (string)($row['section_key'] ?? ''),
            'title' => $title,
            'section_type' => (string)($row['section_type'] ?? ''),
            'stable_anchor' => (string)($row['stable_anchor'] ?? ''),
            'allow_author_blocks' => !empty($row['allow_author_blocks']),
            'is_system_managed' => !empty($row['is_system_managed']),
            'is_generated' => !empty($row['is_generated']),
            'block_count' => (int)($row['block_count'] ?? 0),
            'is_group' => false,
            'is_separator' => false,
            'is_navigable' => $id > 0,
            'children' => array(),
        ), $extra);
    }

    /**
     * @param list<array<string,mixed>> $children
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function groupNode(string $groupKey, string $title, array $children, array $extra = array()): array
    {
        return array_merge(array(
            'id' => (int)($extra['section_id'] ?? 0),
            'nav_id' => $groupKey,
            'parent_section_id' => null,
            'section_key' => $groupKey,
            'title' => $title,
            'section_type' => 'nav_group',
            'stable_anchor' => '',
            'allow_author_blocks' => false,
            'is_system_managed' => true,
            'is_generated' => false,
            'block_count' => 0,
            'is_group' => true,
            'is_separator' => false,
            'is_navigable' => !empty($extra['is_navigable']),
            'children' => $children,
        ), $extra);
    }

    /**
     * @return array<string,mixed>
     */
    private function separatorNode(string $key): array
    {
        return array(
            'id' => 0,
            'nav_id' => 'separator:' . $key,
            'title' => '',
            'is_group' => false,
            'is_separator' => true,
            'is_navigable' => false,
            'children' => array(),
        );
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function decodeMeta(array $row): array
    {
        $raw = $row['metadata_json'] ?? '{}';
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string)$raw, true);
        return is_array($decoded) ? $decoded : array();
    }

    /**
     * Flat section rows in editor reading order (cover → Part 0 → parts → annexes).
     *
     * @param list<array<string,mixed>> $flatRows
     * @return list<array<string,mixed>>
     */
    public function flattenNavigableSectionRows(int $versionId, string $bookKey, array $flatRows): array
    {
        $byId = array();
        foreach ($flatRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int)($row['id'] ?? 0);
            if ($id > 0) {
                $byId[$id] = $row;
            }
        }

        $orderedIds = array();
        $this->collectNavigableSectionIds($this->buildNavTree($versionId, $bookKey), $orderedIds);

        $ordered = array();
        $seen = array();
        foreach ($orderedIds as $id) {
            if (isset($seen[$id]) || !isset($byId[$id])) {
                continue;
            }
            $seen[$id] = true;
            $ordered[] = $byId[$id];
        }

        return $ordered;
    }

    /**
     * @param list<array<string,mixed>> $nodes
     * @param list<int> $ids
     */
    private function collectNavigableSectionIds(array $nodes, array &$ids): void
    {
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            if (!empty($node['is_separator'])) {
                continue;
            }
            if (!empty($node['children']) && is_array($node['children'])) {
                $this->collectNavigableSectionIds($node['children'], $ids);
            }
            $id = (int)($node['id'] ?? 0);
            if ($id > 0 && empty($node['is_group']) && empty($node['is_separator']) && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
    }
}
