<?php
declare(strict_types=1);

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
            array('section_key' => 'part_1', 'title' => 'Part 1 – General', 'sort_order' => 100),
            array('section_key' => 'part_2', 'title' => 'Part 2 – Technical', 'sort_order' => 110),
            array('section_key' => 'part_3', 'title' => 'Part 3 – Route', 'sort_order' => 120),
            array('section_key' => 'part_4', 'title' => 'Part 4 – Personnel Training', 'sort_order' => 130),
        ),
        'OMM' => array(
            array('section_key' => 'part_1', 'title' => 'Part 1 – General', 'sort_order' => 100),
            array('section_key' => 'part_2', 'title' => 'Part 2 – Compliance Monitoring', 'sort_order' => 110),
            array('section_key' => 'part_3', 'title' => 'Part 3 – Safety Management', 'sort_order' => 120),
        ),
    );

    /** @var list<string> */
    private const PART0_SECTION_KEYS = array(
        'toc',
        'lep',
        'revision_system',
        'amendment_list',
        'distribution_list',
        'abbreviations',
        'definitions',
        'highlights',
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

    public function __construct(private ControlledPublishingSectionService $sections)
    {
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function buildNavTree(int $versionId, string $bookKey): array
    {
        $flat = $this->sections->listFlatSections($versionId);
        $byKey = array();
        $byId = array();
        $childrenByParent = array();
        foreach ($flat as $row) {
            $key = (string)($row['section_key'] ?? '');
            $id = (int)($row['id'] ?? 0);
            $byKey[$key] = $row;
            $byId[$id] = $row;
            $parentId = $row['parent_section_id'] !== null ? (int)$row['parent_section_id'] : 0;
            if (!isset($childrenByParent[$parentId])) {
                $childrenByParent[$parentId] = array();
            }
            $childrenByParent[$parentId][] = $row;
        }

        // Legacy: treat main_content as part_1 when part_1 section is absent.
        if (!isset($byKey['part_1']) && isset($byKey['main_content'])) {
            $byKey['part_1'] = $byKey['main_content'];
        }

        $tree = array();

        if (isset($byKey['cover'])) {
            $tree[] = $this->leafNode($byKey['cover'], 'Cover Page');
        }

        $tree[] = $this->separatorNode('after_cover');
        $part0Children = array();
        if (isset($byKey['toc'])) {
            $part0Children[] = $this->leafNode($byKey['toc'], 'Table of Contents');
        }
        foreach (self::PART0_AFTER_TOC_KEYS as $sectionKey) {
            if (!isset($byKey[$sectionKey])) {
                continue;
            }
            $label = $this->part0NavLabel($sectionKey);
            $part0Children[] = $this->leafNode($byKey[$sectionKey], $label);
        }
        $tree[] = $this->groupNode(
            'group_part0',
            ControlledPublishingPart0PageService::PART_TITLE,
            $part0Children
        );

        $tree[] = $this->separatorNode('after_part0');

        foreach (self::BOOK_PARTS[$bookKey] ?? self::BOOK_PARTS['OM'] as $partDef) {
            $partKey = (string)$partDef['section_key'];
            if (!isset($byKey[$partKey]) && !($partKey === 'part_1' && isset($byKey['main_content']))) {
                continue;
            }
            $partRow = $byKey[$partKey] ?? $byKey['main_content'];
            $partId = $this->resolvePartParentId($partKey, $byKey, $childrenByParent);
            $subsections = $this->formatSubsections($childrenByParent[$partId] ?? array());
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
                $tree[] = $this->groupNode('group_annexes', 'Annexes', $annexChildren);
            } else {
                $tree[] = $this->leafNode($annexRow, 'Annexes');
            }
        }

        return $tree;
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
    private function formatSubsections(array $rows): array
    {
        $nodes = array();
        foreach ($rows as $row) {
            $label = ControlledPublishingManualStructureService::navLabelForSection($row);
            $nodes[] = $this->leafNode($row, $label);
        }
        return $nodes;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function formatAnnexSubsections(array $rows): array
    {
        $nodes = array();
        $index = 1;
        foreach ($rows as $row) {
            $label = 'Annex ' . str_pad((string)$index, 2, '0', STR_PAD_LEFT);
            $title = trim((string)($row['title'] ?? ''));
            if ($title !== '' && stripos($title, 'annex') === false) {
                $label .= ' – ' . $title;
            } elseif ($title !== '') {
                $label = $title;
            }
            $nodes[] = $this->leafNode($row, $label);
            $index++;
        }
        return $nodes;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function leafNode(array $row, string $title): array
    {
        $id = (int)($row['id'] ?? 0);
        return array(
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
        );
    }

    /**
     * @param list<array<string,mixed>> $children
     * @return array<string,mixed>
     */
    private function groupNode(string $groupKey, string $title, array $children): array
    {
        return array(
            'id' => 0,
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
            'is_navigable' => false,
            'children' => $children,
        );
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
}
