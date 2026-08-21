<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingBlockService.php';
require_once __DIR__ . '/ControlledPublishingBookStyleService.php';
require_once __DIR__ . '/ControlledPublishingDocxReader.php';
require_once __DIR__ . '/ControlledPublishingFoundationService.php';
require_once __DIR__ . '/ControlledPublishingOutlineService.php';
require_once __DIR__ . '/ControlledPublishingPart0PageService.php';
require_once __DIR__ . '/ControlledPublishingSectionNumberService.php';
require_once __DIR__ . '/ControlledPublishingSectionService.php';

/**
 * Derives manual part chapter outlines from canonical excerpts and syncs
 * publishing subsections under part_1..part_4 (or legacy main_content).
 */
final class ControlledPublishingManualStructureService
{
    /** @var array<string,list<string>> */
    private const PART_SECTION_KEYS = array(
        'OM' => array('part_1', 'part_2', 'part_3', 'part_4'),
        'OMM' => array('part_1', 'part_2', 'part_3'),
    );

    /** @var array<string,string> */
    private const LEGACY_PART_PARENT = array(
        'part_1' => 'main_content',
    );

    /** @var array<int,string> */
    private const OM_DEFAULT_CHAPTER_TITLES = array(
        1 => 'Introduction',
    );

    /** @var array<string,string> */
    private const PART_TITLES = array(
        'part_1' => 'PART 1 – General',
        'main_content' => 'PART 1 – General',
        'part_2' => 'PART 2 – Technical',
        'part_3' => 'PART 3 – Route',
        'part_4' => 'PART 4 – Personnel Training',
        'annexes' => 'Annexes',
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

    public function __construct(
        private PDO $pdo,
        private ControlledPublishingFoundationService $foundation,
        private ControlledPublishingSectionService $sections,
        private ?ControlledPublishingBlockService $blocks = null
    ) {
    }

    /**
     * Ensure top-level part sections exist and chapter subsections match canonical structure.
     *
     * @return array{parts_synced:int,chapters_created:int,chapters_updated:int,chapters_removed:int}
     */
    public function syncVersionStructure(int $versionId, ?int $actorUserId = null, bool $pruneStaleChapters = true): array
    {
        $version = $this->foundation->getVersion($versionId);
        if ($version === null) {
            throw new RuntimeException('Book version not found.');
        }
        if ((string)($version['lifecycle_status'] ?? '') === 'released') {
            throw new RuntimeException('Released versions cannot be restructured.');
        }

        $this->foundation->ensureTemplates($actorUserId);
        $this->foundation->scaffoldVersionSections($versionId, $actorUserId);
        $this->repairNestedMainChapterSections($versionId, $actorUserId);

        $bookKey = strtoupper(trim((string)($version['book_key'] ?? 'OM')));
        $sourceSetId = $this->resolveManualSourceSetId($versionId);
        if ($sourceSetId <= 0) {
            throw new RuntimeException('No manual canonical source set is linked to this version.');
        }

        $manualCode = strtoupper(trim((string)($version['manual_code'] ?? $bookKey)));
        $invalidExcerptsRetired = $this->retireInvalidCanonicalExcerpts($sourceSetId, $manualCode);
        $partsSynced = 0;
        $created = 0;
        $updated = 0;
        $removed = 0;
        $canonicalChaptersFound = 0;

        foreach (self::PART_SECTION_KEYS[$bookKey] ?? self::PART_SECTION_KEYS['OM'] as $partIndex => $partKey) {
            $parentId = $this->resolvePartParentSectionId($versionId, $partKey);
            if ($parentId <= 0) {
                continue;
            }

            $chapters = $this->listChaptersForPart($manualCode, $sourceSetId, $partIndex + 1);
            if ($chapters === array()) {
                continue;
            }

            $canonicalChaptersFound += count($chapters);
            $result = $this->syncChaptersUnderParent(
                $versionId,
                $parentId,
                $partKey,
                $chapters,
                $partIndex + 1,
                $actorUserId,
                $pruneStaleChapters && !in_array($manualCode, array('OM', 'OMM'), true)
            );
            $partsSynced++;
            $created += $result['created'];
            $updated += $result['updated'];
            $removed += $result['removed'];
        }

        $created += $this->promoteGenericWrapperChapters($versionId, $actorUserId);
        $updated += $this->overlayChapterTitlesFromImportedBlocks($versionId);

        return array(
            'parts_synced' => $partsSynced,
            'chapters_created' => $created,
            'chapters_updated' => $updated,
            'chapters_removed' => $removed,
            'invalid_excerpts_retired' => $invalidExcerptsRetired,
            'source_set_id' => $sourceSetId,
            'canonical_chapters_found' => $canonicalChaptersFound,
        );
    }

    /**
     * Repair legacy imports where a MAIN chapter shell was nested under the
     * preceding chapter while its rich blocks remained in that parent.
     *
     * @return int Number of repaired chapter sections.
     */
    public function repairNestedMainChapterSections(
        int $versionId,
        ?int $actorUserId = null,
        bool $replaceCanonicalTargets = false
    ): int {
        if ($this->blocks === null || $versionId <= 0) {
            return 0;
        }

        $flat = $this->sections->listFlatSections($versionId);
        $byId = array();
        foreach ($flat as $row) {
            if (is_array($row)) {
                $byId[(int)($row['id'] ?? 0)] = $row;
            }
        }

        $repaired = 0;
        foreach ($flat as $nested) {
            if (!is_array($nested)) {
                continue;
            }
            $nestedId = (int)($nested['id'] ?? 0);
            $parentId = (int)($nested['parent_section_id'] ?? 0);
            $parent = $byId[$parentId] ?? null;
            if ($nestedId <= 0 || !is_array($parent) || $this->chapterNumberFromSection($parent) <= 0) {
                continue;
            }
            $partId = (int)($parent['parent_section_id'] ?? 0);
            $part = $byId[$partId] ?? null;
            if (!is_array($part)) {
                continue;
            }
            $number = $this->nestedChapterNumber($nested);
            if ($number <= 0) {
                continue;
            }

            $target = null;
            foreach ($flat as $candidate) {
                if (!is_array($candidate)
                    || (int)($candidate['parent_section_id'] ?? 0) !== $partId
                    || (int)($candidate['id'] ?? 0) === $parentId) {
                    continue;
                }
                if ($this->chapterNumberFromSection($candidate) === $number) {
                    $target = $candidate;
                    break;
                }
            }
            if (is_array($target) && !$replaceCanonicalTargets) {
                continue;
            }

            $parentBlocks = $this->blocks->listSectionBlocks($parentId);
            $start = $this->nestedChapterBlockStart($parentBlocks, (string)($nested['title'] ?? ''));
            if ($start < 0) {
                continue;
            }
            $end = count($parentBlocks);
            $otherTitles = array();
            foreach ($flat as $otherNested) {
                if (!is_array($otherNested)
                    || (int)($otherNested['id'] ?? 0) === $nestedId
                    || (int)($otherNested['parent_section_id'] ?? 0) !== $parentId) {
                    continue;
                }
                $otherTitle = $this->comparableChapterTitle((string)($otherNested['title'] ?? ''));
                if ($otherTitle !== '') {
                    $otherTitles[$otherTitle] = true;
                }
            }
            for ($index = $start + 1; $index < $end; $index++) {
                $block = $parentBlocks[$index] ?? null;
                if (!is_array($block)) {
                    continue;
                }
                $payload = $this->blocks->decodePayload($block);
                $text = $this->navBlockEntryText((string)($block['block_type'] ?? ''), $payload);
                if (isset($otherTitles[$this->comparableChapterTitle($text)])) {
                    $end = $index;
                    break;
                }
            }

            if (!is_array($target)) {
                $partKey = (string)($part['section_key'] ?? ('part_' . max(1, $this->manualPartForSection($parent, $flat))));
                $title = ControlledPublishingOutlineService::stripChapterNumberPrefix(
                    (string)($nested['title'] ?? '')
                );
                $meta = $this->decodeMeta($nested);
                $meta['chapter_number'] = $number;
                $meta['manual_part'] = $this->manualPartForSection($parent, $flat);
                $meta['nav_label'] = $number . '. ' . $title;
                $meta['synced_from_canonical'] = true;
                $stmt = $this->pdo->prepare(
                    'UPDATE ipca_publishing_book_sections
                     SET parent_section_id = :parent_id,
                         section_key = :section_key,
                         title = :title,
                         metadata_json = :metadata_json,
                         sort_order = :sort_order,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id'
                );
                $stmt->execute(array(
                    ':parent_id' => $partId,
                    ':section_key' => $this->chapterSectionKey($partKey, $number),
                    ':title' => $title,
                    ':metadata_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    ':sort_order' => $number * 10,
                    ':id' => $nestedId,
                ));
                $target = array_merge($nested, array('id' => $nestedId));
            } else {
                $targetMeta = $this->decodeMeta($target);
                if (empty($targetMeta['synced_from_canonical'])) {
                    continue;
                }
                foreach ($this->blocks->listSectionBlocks((int)$target['id']) as $block) {
                    $this->blocks->deleteBlock((int)($block['id'] ?? 0), $actorUserId);
                }
            }

            $targetId = (int)($target['id'] ?? 0);
            $sortOrder = 10;
            for ($index = $start; $index < $end; $index++) {
                $block = $parentBlocks[$index];
                if (!is_array($block)) {
                    continue;
                }
                $payload = $this->blocks->decodePayload($block);
                if ($index === $start) {
                    $payload['paragraph_style'] = 'title';
                    $payload['canonical_section_ref'] = (string)$number;
                }
                $this->blocks->relocateBlock(
                    (int)($block['id'] ?? 0),
                    $targetId,
                    $sortOrder,
                    $payload,
                    $actorUserId
                );
                $sortOrder += 10;
            }
            if ($targetId !== $nestedId) {
                $this->deleteSection($nestedId);
            }
            $repaired++;
        }

        return $repaired;
    }

    /**
     * Sync when a part has no canonical chapter subsections yet.
     */
    public function ensureVersionStructure(int $versionId, ?int $actorUserId = null): array
    {
        if (!$this->needsStructureSync($versionId)) {
            return $this->refreshImportedChapterTitles($versionId, $actorUserId);
        }

        $result = $this->syncVersionStructure($versionId, $actorUserId);
        $result['skipped'] = false;
        return $result;
    }

    /**
     * Refresh MAIN chapter titles from imported heading blocks even when the
     * chapter shells already exist (typical after New Manual cloned OM titles).
     *
     * @return array{parts_synced:int,chapters_created:int,chapters_updated:int,chapters_removed:int,skipped:bool}
     */
    private function refreshImportedChapterTitles(int $versionId, ?int $actorUserId = null): array
    {
        $created = $this->promoteGenericWrapperChapters($versionId, $actorUserId);
        $updated = $this->overlayChapterTitlesFromImportedBlocks($versionId);

        return array(
            'parts_synced' => 0,
            'chapters_created' => $created,
            'chapters_updated' => $updated,
            'chapters_removed' => 0,
            'skipped' => $created === 0 && $updated === 0,
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function resolveVersion(int $versionId): ?array
    {
        return $this->foundation->getVersion($versionId);
    }

    public function parentHasAnnexBook(int $parentBookId): bool
    {
        return $this->foundation->annexBookIdForParent($parentBookId) > 0;
    }

    public function resolveManualSourceSetIdPublic(int $versionId): int
    {
        return $this->resolveManualSourceSetId($versionId);
    }

    public function needsStructureSync(int $versionId): bool
    {
        $version = $this->foundation->getVersion($versionId);
        if ($version === null) {
            return false;
        }

        $bookKey = strtoupper(trim((string)($version['book_key'] ?? 'OM')));
        foreach (self::PART_SECTION_KEYS[$bookKey] ?? self::PART_SECTION_KEYS['OM'] as $partKey) {
            $parentId = $this->resolvePartParentSectionId($versionId, $partKey);
            if ($parentId <= 0) {
                continue;
            }
            $children = $this->listChildSections($versionId, $parentId);
            if ($children === array()) {
                return true;
            }
            if (count($children) === 1 && !$this->isCanonicalChapterSection($children[0])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{chapter_number:int,title:string,nav_label:string}>
     */
    public function listChaptersFromBookVersion(int $versionId, int $manualPart): array
    {
        if ($versionId <= 0 || $manualPart <= 0) {
            return array();
        }

        $chapters = array();
        foreach ($this->sections->listFlatSections($versionId) as $row) {
            if (!$this->isCanonicalChapterSection($row)) {
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
            $title = $this->formatChapterTitle((string)($row['title'] ?? ''));
            $chapters[$number] = $this->chapterDef($number, $title);
        }

        ksort($chapters, SORT_NUMERIC);

        return array_values($chapters);
    }

    /**
     * @return list<array{chapter_number:int,title:string,nav_label:string}>
     */
    public function listChaptersForPart(string $manualCode, int $sourceSetId, int $manualPart): array
    {
        $manualCode = strtoupper(trim($manualCode));
        if ($manualCode === 'OMM') {
            return $this->listOmmChapters($manualCode, $sourceSetId, $manualPart);
        }

        return $this->listOmChapters($manualCode, $sourceSetId, $manualPart);
    }

    /**
     * @return list<array{chapter_number:int,title:string,nav_label:string}>
     */
    private function listOmmChapters(string $manualCode, int $sourceSetId, int $manualPart): array
    {
        $stmt = $this->pdo->prepare("
            SELECT section_ref, title
            FROM ipca_canonical_excerpts
            WHERE source_set_id = :source_set_id
              AND manual_code = :manual_code
              AND manual_part = :manual_part
              AND source_status = 'active'
              AND section_ref REGEXP '^[0-9]+$'
              AND section_ref <> '0'
              AND UPPER(title) <> 'OUTLINE'
            ORDER BY CAST(section_ref AS UNSIGNED), id
        ");
        $stmt->execute(array(
            ':source_set_id' => $sourceSetId,
            ':manual_code' => $manualCode,
            ':manual_part' => (string)$manualPart,
        ));

        $chapters = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row) {
            $number = (int)($row['section_ref'] ?? 0);
            if ($number <= 0) {
                continue;
            }
            $title = $this->formatChapterTitle((string)($row['title'] ?? ''));
            $chapters[] = $this->chapterDef($number, $title);
        }

        return $chapters;
    }

    /**
     * @return list<array{chapter_number:int,title:string,nav_label:string}>
     */
    private function listOmChapters(string $manualCode, int $sourceSetId, int $manualPart): array
    {
        $stmt = $this->pdo->prepare("
            SELECT section_ref, title, body_text
            FROM ipca_canonical_excerpts
            WHERE source_set_id = :source_set_id
              AND manual_code = :manual_code
              AND manual_part = :manual_part
              AND source_status = 'active'
            ORDER BY section_ref, id
        ");
        $stmt->execute(array(
            ':source_set_id' => $sourceSetId,
            ':manual_code' => $manualCode,
            ':manual_part' => (string)$manualPart,
        ));

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
        if ($rows === array()) {
            return array();
        }

        $chapterNumbers = array();
        $headingTitles = array();
        $embeddedChapterTitles = $this->collectEmbeddedOmChapterTitles($rows);

        foreach ($rows as $row) {
            $ref = trim((string)($row['section_ref'] ?? ''));
            if ($ref === '') {
                continue;
            }
            if (preg_match('/^(\d+)$/', $ref, $chapterMatch)) {
                $chapterNum = (int)$chapterMatch[1];
                $titleCandidate = trim((string)($row['title'] ?? ''));
                if ($chapterNum <= 0 || $chapterNum > ControlledPublishingDocxReader::MAX_CHAPTER_NUMBER) {
                    continue;
                }
                if (ControlledPublishingDocxReader::isLikelyTableOrMeasurementExcerpt($ref, $titleCandidate)) {
                    continue;
                }
                $chapterNumbers[$chapterNum] = true;
                if ($titleCandidate !== '' && strcasecmp($titleCandidate, 'outline') !== 0) {
                    $headingTitles[$chapterNum] = $this->formatChapterTitle($titleCandidate);
                }
                continue;
            }
            if ($this->isSkippableCanonicalExcerpt($ref, (string)($row['title'] ?? ''), '')) {
                continue;
            }
            if (preg_match('/^(\d+)\.\d/', $ref, $m)) {
                $chapterNumbers[(int)$m[1]] = true;
            }

            $text = str_replace('\\n', "\n", (string)($row['body_text'] ?? ''));
            if (preg_match_all('/(?:^|\n\n+|\n)(\d{1,2})\.\s+([A-Z][A-Z0-9 ,\-\/&()]+)\s*(?:\n|$)/', $text, $headingMatches, PREG_SET_ORDER)) {
                foreach ($headingMatches as $headingMatch) {
                    $candidate = trim($headingMatch[2]);
                    if ($candidate === '' || preg_match('/[a-z]/', $candidate)) {
                        continue;
                    }
                    $headingNum = (int)$headingMatch[1];
                    if ($headingNum <= 0 || $headingNum > ControlledPublishingDocxReader::MAX_CHAPTER_NUMBER) {
                        continue;
                    }
                    $refStr = (string)$headingNum;
                    if ($this->isSkippableCanonicalExcerpt($refStr, $candidate, '')) {
                        continue;
                    }
                    if (!ControlledPublishingDocxReader::isChapterLevelTitle($candidate)) {
                        continue;
                    }
                    $headingTitles[$headingNum] = $this->formatChapterTitle($candidate);
                    $chapterNumbers[$headingNum] = true;
                }
            }
        }

        if ($chapterNumbers === array()) {
            return array();
        }

        ksort($chapterNumbers, SORT_NUMERIC);
        $chapters = array();
        foreach (array_keys($chapterNumbers) as $number) {
            $number = (int)$number;
            if (isset($headingTitles[$number])) {
                $title = $headingTitles[$number];
            } elseif (isset($embeddedChapterTitles[$number])) {
                $title = $embeddedChapterTitles[$number];
            } elseif ($manualCode === 'OM' && isset(self::OM_DEFAULT_CHAPTER_TITLES[$number])) {
                $title = self::OM_DEFAULT_CHAPTER_TITLES[$number];
            } else {
                $title = $this->resolveOmChapterTitleFromRows($number, $rows);
            }
            if ($title === '' || preg_match('/^Chapter\s+' . $number . '$/i', $title)) {
                $title = 'Chapter ' . $number;
            }
            $chapters[] = $this->chapterDef($number, $title);
        }

        return $chapters;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<int,string>
     */
    private function collectEmbeddedOmChapterTitles(array $rows): array
    {
        $titles = array();
        foreach ($rows as $row) {
            $text = str_replace('\\n', "\n", (string)($row['body_text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $patterns = array(
                '/(?:^|\n\n+|\n)(\d{1,2})\.\s+([A-Z][A-Z0-9 ,\-\/&()]+)\s*(?:\n|$)/',
                '/\n(\d{1,2})\.\s+([A-Z][A-Z0-9 ,\-\/&()]+)\s*$/',
            );
            foreach ($patterns as $pattern) {
                if (!preg_match_all($pattern, $text, $headingMatches, PREG_SET_ORDER)) {
                    continue;
                }
                foreach ($headingMatches as $headingMatch) {
                    $candidate = trim((string)($headingMatch[2] ?? ''));
                    $headingNum = (int)($headingMatch[1] ?? 0);
                    if ($headingNum <= 0 || $headingNum > ControlledPublishingDocxReader::MAX_CHAPTER_NUMBER) {
                        continue;
                    }
                    if ($candidate === '' || !ControlledPublishingDocxReader::isChapterLevelTitle($candidate)) {
                        continue;
                    }
                    if ($this->isSkippableCanonicalExcerpt((string)$headingNum, $candidate, '')) {
                        continue;
                    }
                    $titles[$headingNum] = $this->formatChapterTitle($candidate);
                }
            }
        }

        return $titles;
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function resolveOmChapterTitleFromRows(int $chapterNumber, array $rows): string
    {
        $chapterRef = (string)$chapterNumber;
        foreach ($rows as $row) {
            $ref = trim((string)($row['section_ref'] ?? ''));
            if ($ref !== $chapterRef) {
                continue;
            }
            $title = $this->resolveCanonicalExcerptTitle(
                (string)($row['title'] ?? ''),
                (string)($row['body_text'] ?? '')
            );
            if ($title !== '' && !preg_match('/^Chapter\s+' . $chapterNumber . '$/i', $title)) {
                return $this->formatChapterTitle($title);
            }
        }

        return 'Chapter ' . $chapterNumber;
    }

    /**
     * @param list<array{chapter_number:int,title:string,nav_label:string}> $chapters
     * @return array{created:int,updated:int,removed:int}
     */
    private function syncChaptersUnderParent(
        int $versionId,
        int $parentSectionId,
        string $partKey,
        array $chapters,
        int $manualPart,
        ?int $actorUserId,
        bool $pruneStaleChapters = false
    ): array {
        $existing = $this->listChildSections($versionId, $parentSectionId);
        $existingByNumber = array();
        $orphans = array();

        foreach ($existing as $row) {
            $number = $this->chapterNumberFromSection($row);
            if ($number > 0) {
                $existingByNumber[$number] = $row;
            } else {
                $orphans[] = $row;
            }
        }

        $created = 0;
        $updated = 0;
        $removed = 0;
        $parent = $this->sections->getSection($versionId, $parentSectionId);
        if ($parent === null) {
            throw new RuntimeException('Parent section not found.');
        }
        $parentAnchor = (string)($parent['stable_anchor'] ?? '');

        foreach ($chapters as $chapter) {
            $number = (int)$chapter['chapter_number'];
            $title = (string)$chapter['title'];
            $navLabel = (string)$chapter['nav_label'];
            $sectionKey = $this->chapterSectionKey($partKey, $number);
            $metadata = array(
                'chapter_number' => $number,
                'manual_part' => $manualPart,
                'nav_label' => $navLabel,
                'synced_from_canonical' => true,
            );

            if (isset($existingByNumber[$number])) {
                $row = $existingByNumber[$number];
                $meta = $this->decodeMeta($row);
                $existingNav = trim((string)($meta['nav_label'] ?? ''));
                $outlineLocked = !empty($meta['outline_locked']);
                $needsUpdate = ((string)($row['section_key'] ?? '') !== $sectionKey)
                    || (int)($meta['manual_part'] ?? 0) !== $manualPart
                    || !$this->isCanonicalChapterSection($row);
                if (!$outlineLocked) {
                    $needsUpdate = $needsUpdate
                        || ((string)($row['title'] ?? '') !== $title)
                        || ($existingNav !== $navLabel);
                }
                if ($needsUpdate) {
                    $saveTitle = $title;
                    $saveNav = $navLabel;
                    $saveMeta = $metadata;
                    if ($outlineLocked) {
                        $saveTitle = (string)($row['title'] ?? $title);
                        $saveNav = $existingNav !== '' ? $existingNav : $navLabel;
                        $saveMeta['nav_label'] = $saveNav;
                        $saveMeta['outline_locked'] = true;
                    }
                    $this->updateChapterSection(
                        (int)$row['id'],
                        $sectionKey,
                        $saveTitle,
                        $number * 10,
                        $saveMeta
                    );
                    $updated++;
                }
                unset($existingByNumber[$number]);
                continue;
            }

            $this->insertChapterSection(
                $versionId,
                $parentSectionId,
                $parentAnchor,
                $sectionKey,
                $title,
                $number * 10,
                $metadata,
                $actorUserId
            );
            $created++;
        }

        foreach ($existingByNumber as $row) {
            // Keep chapters that already contain imported blocks (promoted 1.1 → 2, etc.).
            if ((int)($row['block_count'] ?? 0) > 0) {
                continue;
            }
            if (!empty($this->decodeMeta($row)['outline_locked'])) {
                continue;
            }
            if ($pruneStaleChapters || $this->canRemoveChapterSection($row)) {
                $this->deleteSection((int)$row['id']);
                $removed++;
            }
        }

        foreach ($orphans as $row) {
            if ((int)($row['block_count'] ?? 0) > 0) {
                continue;
            }
            if (!empty($this->decodeMeta($row)['outline_locked'])) {
                continue;
            }
            if ($pruneStaleChapters || $this->canRemoveChapterSection($row)) {
                $this->deleteSection((int)$row['id']);
                $removed++;
            }
        }

        return array(
            'created' => $created,
            'updated' => $updated,
            'removed' => $removed,
        );
    }

    /**
     * @param array<string,mixed> $row
     */
    private function canRemoveChapterSection(array $row): bool
    {
        if ($this->chapterNumberFromSection($row) > 0 && !$this->isValidChapterNavEntry($row)) {
            return true;
        }

        $meta = $this->decodeMeta($row);
        $chapterNumber = (int)($meta['chapter_number'] ?? 0);
        if ($this->isCanonicalChapterSection($row) && $chapterNumber > 0) {
            if ($chapterNumber > ControlledPublishingDocxReader::MAX_CHAPTER_NUMBER) {
                return true;
            }
            $title = trim((string)($row['title'] ?? ''));
            $navLabel = trim((string)($meta['nav_label'] ?? ''));
            if (ControlledPublishingDocxReader::isLikelyTableOrMeasurementExcerpt(
                (string)$chapterNumber,
                $navLabel !== '' ? $navLabel : $title
            )) {
                return true;
            }
        }

        if ((int)($row['block_count'] ?? 0) > 0) {
            return false;
        }
        if ($this->isCanonicalChapterSection($row)) {
            return true;
        }
        $title = trim((string)($row['title'] ?? ''));
        return preg_match('/^\d+\s+General$/i', $title) === 1
            || preg_match('/^Part\s+\d+/i', $title) === 1;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function isCanonicalChapterSection(array $row): bool
    {
        $meta = $this->decodeMeta($row);
        return !empty($meta['synced_from_canonical']);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function chapterNumberFromSection(array $row): int
    {
        $meta = $this->decodeMeta($row);
        if (!empty($meta['chapter_number'])) {
            return (int)$meta['chapter_number'];
        }
        $key = (string)($row['section_key'] ?? '');
        if (preg_match('/_chapter_(\d+)$/', $key, $m)) {
            return (int)$m[1];
        }
        return 0;
    }

    public function resolvePartParentSectionId(int $versionId, string $partKey): int
    {
        if ($partKey === 'part_1') {
            $mainContentId = $this->sectionIdByKey($versionId, 'main_content');
            $part1Id = $this->sectionIdByKey($versionId, 'part_1');
            if ($mainContentId > 0 && $part1Id <= 0) {
                return $mainContentId;
            }
            if ($mainContentId > 0 && $part1Id > 0) {
                $mainChildren = $this->listChildSections($versionId, $mainContentId);
                $part1Children = $this->listChildSections($versionId, $part1Id);
                if ($mainChildren !== array() && $part1Children === array()) {
                    return $mainContentId;
                }
                return $part1Id;
            }
        }

        $id = $this->sectionIdByKey($versionId, $partKey);
        if ($id > 0) {
            return $id;
        }

        if (isset(self::LEGACY_PART_PARENT[$partKey])) {
            return $this->sectionIdByKey($versionId, self::LEGACY_PART_PARENT[$partKey]);
        }

        return 0;
    }

    private function sectionIdByKey(int $versionId, string $sectionKey): int
    {
        $stmt = $this->pdo->prepare("
            SELECT id FROM ipca_publishing_book_sections
            WHERE book_version_id = :version_id AND section_key = :section_key
            LIMIT 1
        ");
        $stmt->execute(array(
            ':version_id' => $versionId,
            ':section_key' => $sectionKey,
        ));
        return (int)$stmt->fetchColumn();
    }

    private function resolveManualSourceSetId(int $versionId): int
    {
        $stmt = $this->pdo->prepare("
            SELECT ss.id
            FROM ipca_publishing_book_version_source_sets vss
            INNER JOIN ipca_canonical_source_sets ss ON ss.id = vss.source_set_id
            WHERE vss.book_version_id = :version_id
              AND (
                vss.selection_role = 'manual_source'
                OR ss.source_family = 'manual'
              )
            ORDER BY
              CASE WHEN vss.selection_role = 'manual_source' THEN 0 ELSE 1 END,
              vss.id
            LIMIT 1
        ");
        $stmt->execute(array(':version_id' => $versionId));
        return (int)$stmt->fetchColumn();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function listChildSections(int $versionId, int $parentId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
              s.*,
              (SELECT COUNT(*) FROM ipca_publishing_book_blocks b WHERE b.section_id = s.id) AS block_count
            FROM ipca_publishing_book_sections s
            WHERE s.book_version_id = :version_id
              AND s.parent_section_id = :parent_id
            ORDER BY s.sort_order, s.id
        ");
        $stmt->execute(array(
            ':version_id' => $versionId,
            ':parent_id' => $parentId,
        ));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    /**
     * Find or create a MAIN chapter section so flattened 1.2 → chapter 2 imports have a home.
     */
    public function ensureChapterSection(
        int $versionId,
        int $manualPart,
        int $chapterNumber,
        string $title,
        ?int $actorUserId = null
    ): int {
        $partKey = 'part_' . max(1, $manualPart);
        $sectionKey = $this->chapterSectionKey($partKey, $chapterNumber);
        $title = $this->formatChapterTitle($title !== '' ? $title : ('Chapter ' . $chapterNumber));
        $navLabel = $chapterNumber . '. ' . $title;
        $metadata = array(
            'chapter_number' => $chapterNumber,
            'manual_part' => $manualPart,
            'nav_label' => $navLabel,
            'synced_from_canonical' => true,
        );

        $existing = $this->chapterSectionIdForPart($versionId, $manualPart, $chapterNumber);
        if ($existing <= 0) {
            $existing = $this->sectionIdByKey($versionId, $sectionKey);
        }
        if ($existing > 0) {
            $this->updateChapterSection($existing, $sectionKey, $title, $chapterNumber * 10, $metadata);
            return $existing;
        }

        $parentId = $this->resolvePartParentSectionId($versionId, $partKey);
        if ($parentId <= 0) {
            throw new RuntimeException('Missing PART ' . $manualPart . ' section for chapter ' . $chapterNumber . '.');
        }
        $parent = $this->sections->getSection($versionId, $parentId);
        if ($parent === null) {
            throw new RuntimeException('PART ' . $manualPart . ' section could not be loaded.');
        }

        try {
            return $this->insertChapterSection(
                $versionId,
                $parentId,
                (string)($parent['stable_anchor'] ?? ''),
                $sectionKey,
                $title,
                $chapterNumber * 10,
                $metadata,
                $actorUserId
            );
        } catch (Throwable $e) {
            $retry = $this->sectionIdByKey($versionId, $sectionKey);
            if ($retry > 0) {
                $this->updateChapterSection($retry, $sectionKey, $title, $chapterNumber * 10, $metadata);
                return $retry;
            }
            throw new RuntimeException(
                'Could not create part ' . $manualPart . ' chapter ' . $chapterNumber . ': ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    public function chapterSectionIdForPart(int $versionId, int $manualPart, int $chapterNumber): int
    {
        foreach ($this->sections->listFlatSections($versionId) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $meta = $this->decodeMeta($row);
            if ((int)($meta['manual_part'] ?? 0) !== $manualPart) {
                continue;
            }
            if ((int)($meta['chapter_number'] ?? 0) === $chapterNumber) {
                return (int)($row['id'] ?? 0);
            }
        }

        return 0;
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function insertChapterSection(
        int $versionId,
        int $parentSectionId,
        string $parentAnchor,
        string $sectionKey,
        string $title,
        int $sortOrder,
        array $metadata,
        ?int $actorUserId
    ): int {
        $stableAnchor = $this->childAnchor($parentAnchor, $sectionKey);
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_publishing_book_sections
                (book_version_id, parent_section_id, section_key, stable_anchor, title, section_type,
                 is_system_managed, is_generated, sort_order, metadata_json, created_by)
            VALUES
                (:book_version_id, :parent_section_id, :section_key, :stable_anchor, :title, 'content',
                 0, 0, :sort_order, :metadata_json, :created_by)
        ");
        $stmt->execute(array(
            ':book_version_id' => $versionId,
            ':parent_section_id' => $parentSectionId,
            ':section_key' => $sectionKey,
            ':stable_anchor' => $stableAnchor,
            ':title' => $title,
            ':sort_order' => $sortOrder,
            ':metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ':created_by' => $actorUserId,
        ));

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function updateChapterSection(
        int $sectionId,
        string $sectionKey,
        string $title,
        int $sortOrder,
        array $metadata
    ): void {
        $stmt = $this->pdo->prepare("
            UPDATE ipca_publishing_book_sections
            SET section_key = :section_key,
                title = :title,
                sort_order = :sort_order,
                metadata_json = :metadata_json,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute(array(
            ':section_key' => $sectionKey,
            ':title' => $title,
            ':sort_order' => $sortOrder,
            ':metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ':id' => $sectionId,
        ));
    }

    private function deleteSection(int $sectionId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ipca_publishing_book_sections WHERE id = :id');
        $stmt->execute(array(':id' => $sectionId));
    }

    private function chapterSectionKey(string $partKey, int $chapterNumber): string
    {
        return substr($partKey . '_chapter_' . $chapterNumber, 0, 120);
    }

    /**
     * @return array{chapter_number:int,title:string,nav_label:string}
     */
    private function chapterDef(int $number, string $title): array
    {
        $title = trim($title);
        return array(
            'chapter_number' => $number,
            'title' => $title,
            'nav_label' => $number . '. ' . $title,
        );
    }

    private function formatChapterTitle(string $raw): string
    {
        $raw = ControlledPublishingDocxReader::sanitizeSectionTitle(trim($raw));
        if ($raw === '') {
            return '';
        }
        if ($raw !== strtoupper($raw) || !preg_match('/[A-Z]/', $raw)) {
            return $raw;
        }

        $lower = strtolower($raw);
        $smallWords = array('and', 'or', 'of', 'the', 'in', 'for', 'to', 'a', 'an');
        $words = preg_split('/\s+/', $lower) ?: array();
        foreach ($words as $index => &$word) {
            if ($index > 0 && in_array($word, $smallWords, true)) {
                continue;
            }
            $word = ucfirst($word);
        }
        unset($word);

        $formatted = implode(' ', $words);
        return preg_replace_callback('/\(([a-z]+)\)/', static fn(array $m): string => '(' . strtoupper($m[1]) . ')', $formatted) ?? $formatted;
    }

    private function childAnchor(string $parentAnchor, string $sectionKey): string
    {
        $suffix = strtoupper(str_replace('_', '-', $sectionKey));
        if (strlen($parentAnchor . '-' . $suffix) > 191) {
            $suffix = strtoupper(substr(md5($sectionKey), 0, 12));
        }
        return $parentAnchor . '-' . $suffix;
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
     * Import canonical excerpt text into chapter section blocks.
     *
     * @return array{sections_imported:int,blocks_created:int,skipped:bool}
     */
    public function importVersionContent(int $versionId, ?int $actorUserId = null, bool $force = false): array
    {
        if ($this->blocks === null) {
            throw new RuntimeException('Block service is required for content import.');
        }

        $version = $this->foundation->getVersion($versionId);
        if ($version === null) {
            throw new RuntimeException('Book version not found.');
        }
        if ((string)($version['lifecycle_status'] ?? '') === 'released') {
            throw new RuntimeException('Released versions cannot import content.');
        }

        $sourceSetId = $this->resolveManualSourceSetId($versionId);
        if ($sourceSetId <= 0) {
            throw new RuntimeException('No manual canonical source set is linked to this version.');
        }

        $manualCode = strtoupper(trim((string)($version['manual_code'] ?? '')));
        $sectionsImported = 0;
        $blocksCreated = 0;

        foreach ($this->sections->listFlatSections($versionId) as $sectionRow) {
            if (!$this->isCanonicalChapterSection($sectionRow)) {
                continue;
            }
            $meta = $this->decodeMeta($sectionRow);
            $manualPart = (int)($meta['manual_part'] ?? 0);
            $chapterNumber = (int)($meta['chapter_number'] ?? 0);
            if ($manualPart <= 0 || $chapterNumber <= 0) {
                continue;
            }

            $sectionId = (int)($sectionRow['id'] ?? 0);
            $existingBlocks = $this->blocks->listSectionBlocks($sectionId);
            if ($existingBlocks !== array() && !$force) {
                continue;
            }

            if ($existingBlocks !== array() && $force) {
                foreach ($existingBlocks as $block) {
                    if (empty($block['is_system_managed'])) {
                        $this->blocks->deleteBlock((int)$block['id'], $actorUserId);
                    }
                }
            }

            $created = $this->importChapterContent(
                $versionId,
                $sectionId,
                $sectionRow,
                $sourceSetId,
                $manualCode,
                $manualPart,
                $chapterNumber,
                $actorUserId
            );
            if ($created > 0) {
                $sectionsImported++;
                $blocksCreated += $created;
            }
        }

        return array(
            'sections_imported' => $sectionsImported,
            'blocks_created' => $blocksCreated,
            'skipped' => $sectionsImported === 0 && $blocksCreated === 0,
        );
    }

    public function needsContentImport(int $versionId): bool
    {
        if ($this->blocks === null) {
            return false;
        }
        foreach ($this->sections->listFlatSections($versionId) as $sectionRow) {
            if (!$this->isCanonicalChapterSection($sectionRow)) {
                continue;
            }
            if ($this->blocks->listSectionBlocks((int)$sectionRow['id']) === array()) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array{sections_imported:int,blocks_created:int,skipped:bool}
     */
    public function ensureVersionContent(int $versionId, ?int $actorUserId = null): array
    {
        if (!$this->needsContentImport($versionId)) {
            return array(
                'sections_imported' => 0,
                'blocks_created' => 0,
                'skipped' => true,
            );
        }
        $result = $this->importVersionContent($versionId, $actorUserId, false);
        $result['skipped'] = false;
        return $result;
    }

    /**
     * @return array<int,string> block_id => display number (e.g. 5.1)
     */
    public function computeSectionNumberDisplay(int $versionId, string $manualCode = ''): array
    {
        if ($this->blocks === null) {
            return array();
        }

        $numberSvc = new ControlledPublishingSectionNumberService($this->pdo, $this->blocks);
        $numbering = $numberSvc->computeForVersion($versionId, strtoupper(trim($manualCode)));

        return is_array($numbering['display'] ?? null) ? $numbering['display'] : array();
    }

    /**
     * Subsection nav entries from styled blocks on a chapter page (same source as the TOC).
     *
     * @param array<int,string> $sectionNumberDisplay
     * @return list<array{block_id:int,section_ref:string,title:string,nav_label:string}>
     */
    public function listNavSubsectionsFromChapterBlocks(
        int $sectionId,
        array $sectionNumberDisplay,
        int $manualPart = -1
    ): array {
        if ($this->blocks === null || $sectionId <= 0) {
            return array();
        }

        $items = array();
        $seen = array();
        foreach ($this->blocks->listSectionBlocks($sectionId) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $blockId = (int)($row['id'] ?? 0);
            if ($blockId <= 0) {
                continue;
            }

            $payload = $this->blocks->decodePayload($row);
            $blockType = (string)($row['block_type'] ?? '');
            $style = $this->navBlockParagraphStyle($blockType, $payload);
            $depth = ControlledPublishingSectionNumberService::NUMBERED_STYLE_DEPTHS[$style] ?? 0;
            if ($depth < 2) {
                continue;
            }

            $text = $this->navBlockEntryText($blockType, $payload);
            if ($text === '') {
                continue;
            }

            $canonRef = trim((string)($payload['canonical_section_ref'] ?? ''));
            $displayNumber = trim((string)($sectionNumberDisplay[$blockId] ?? ''));
            $sectionRef = rtrim($canonRef !== '' ? $canonRef : $displayNumber, '.');
            if ($sectionRef === '' || !str_contains($sectionRef, '.')) {
                continue;
            }
            if (isset($seen[$sectionRef])) {
                continue;
            }
            if ($this->isSkippableNavExcerpt($sectionRef, $text, $manualPart)) {
                continue;
            }

            $title = $this->stripLeadingSectionRef($text, $sectionRef);
            if ($title === '') {
                $title = $text;
            }
            $numberLabel = $displayNumber !== '' ? rtrim($displayNumber, '.') : $sectionRef;
            $seen[$sectionRef] = true;
            $items[] = array(
                'block_id' => $blockId,
                'section_ref' => $sectionRef,
                'title' => $title,
                'nav_label' => $numberLabel . ' ' . $title,
            );
        }

        return $items;
    }

    /**
     * MAIN chapter title taken from the imported depth-1 heading block on that page.
     */
    public function chapterTitleFromImportedBlocks(int $sectionId, int $chapterNumber): string
    {
        $title = $this->importedChapterTitleFromBlocks($sectionId, $chapterNumber);
        if ($title === '') {
            return '';
        }

        return $chapterNumber . '. ' . $title;
    }

    /**
     * Split a generic "1. GENERAL" chapter whose blocks are numbered 1.1, 1.2, 1.3
     * into real MAIN chapters 1, 2, 3. Repairs manuals imported before flatten ran.
     */
    private function promoteGenericWrapperChapters(int $versionId, ?int $actorUserId = null): int
    {
        if ($this->blocks === null || $versionId <= 0) {
            return 0;
        }

        $byPart = array();
        $flat = $this->sections->listFlatSections($versionId);
        foreach ($flat as $row) {
            if (!is_array($row) || $this->chapterNumberFromSection($row) <= 0) {
                continue;
            }
            $manualPart = $this->manualPartForSection($row, $flat);
            if ($manualPart <= 0) {
                continue;
            }
            $byPart[$manualPart][] = $row;
        }

        $created = 0;
        foreach ($byPart as $manualPart => $chapters) {
            try {
                $created += $this->promoteGenericWrapperInPart(
                    $versionId,
                    (int)$manualPart,
                    $chapters,
                    $actorUserId
                );
            } catch (Throwable $e) {
                unset($e);
            }
        }

        return $created;
    }

    /**
     * @param list<array<string,mixed>> $chapters
     */
    private function promoteGenericWrapperInPart(
        int $versionId,
        int $manualPart,
        array $chapters,
        ?int $actorUserId
    ): int {
        if ($this->blocks === null || $chapters === array()) {
            return 0;
        }

        $created = 0;
        foreach ($chapters as $chapter) {
            $sectionId = (int)($chapter['id'] ?? 0);
            if ($sectionId <= 0) {
                continue;
            }
            if ($this->partHasPopulatedSiblingChapters($chapters, $sectionId)) {
                continue;
            }

            $blocks = $this->blocks->listSectionBlocks($sectionId);
            $nodes = $this->flattenNodesFromSectionBlocks($blocks);
            $prefix = ControlledPublishingDocxReader::genericPartFlattenPrefix($nodes);
            if ($prefix === '') {
                continue;
            }

            $promotedTitles = ControlledPublishingDocxReader::promotedMainChaptersFromNodes($nodes, $manualPart);
            if (count($promotedTitles) < 2) {
                continue;
            }

            $flattened = ControlledPublishingDocxReader::flattenGenericPartChapters($nodes, $manualPart);
            $groups = $this->groupFlattenedBlockNodes($flattened);
            if (count($groups) < 2) {
                continue;
            }

            foreach ($nodes as $node) {
                if ((string)($node['section_ref'] ?? '') !== $prefix) {
                    continue;
                }
                $headingTitle = trim((string)($node['section_title'] ?? ''));
                if (!ControlledPublishingDocxReader::isGenericPartWrapperTitle($headingTitle)) {
                    continue;
                }
                $blockId = (int)($node['block_id'] ?? 0);
                if ($blockId <= 0) {
                    continue;
                }
                try {
                    $this->blocks->deleteBlock($blockId, $actorUserId);
                } catch (Throwable $e) {
                    unset($e);
                }
            }

            foreach ($groups as $chapterNumber => $group) {
                $title = trim((string)($promotedTitles[$chapterNumber] ?? $group['title'] ?? ''));
                $targetId = $this->ensureChapterSection(
                    $versionId,
                    $manualPart,
                    (int)$chapterNumber,
                    $title,
                    $actorUserId
                );
                if ($targetId <= 0) {
                    continue;
                }
                $created++;
                $sortOrder = 10;
                foreach ($group['nodes'] as $node) {
                    $blockId = (int)($node['block_id'] ?? 0);
                    if ($blockId <= 0) {
                        continue;
                    }
                    $payload = is_array($node['payload'] ?? null) ? $node['payload'] : array();
                    $newRef = trim((string)($node['section_ref'] ?? ''));
                    $style = strtolower(trim((string)($node['paragraph_style'] ?? '')));
                    if ($style !== '') {
                        $payload['paragraph_style'] = $style;
                    }
                    if ($newRef !== '' && preg_match('/^\d+(?:\.\d+)*$/', $newRef) === 1) {
                        $payload['canonical_section_ref'] = $newRef;
                    } else {
                        unset($payload['canonical_section_ref']);
                    }
                    $this->blocks->relocateBlock($blockId, $targetId, $sortOrder, $payload, $actorUserId);
                    $sortOrder += 10;
                }
            }
        }

        return $created;
    }

    /**
     * Move one nested heading and the blocks under it onto a new MAIN chapter.
     */
    public function promoteHeadingToMainChapter(
        int $versionId,
        int $sourceSectionId,
        string $sectionRef,
        ?int $actorUserId = null,
        int $sourceBlockId = 0
    ): int {
        if ($this->blocks === null || $sourceSectionId <= 0) {
            throw new RuntimeException('Could not move that heading.');
        }
        $sectionRef = trim($sectionRef);
        $source = $this->sections->getSection($versionId, $sourceSectionId);
        if (!is_array($source) || $this->chapterNumberFromSection($source) <= 0) {
            throw new RuntimeException('Headings can only be promoted from a MAIN chapter.');
        }
        $flat = $this->sections->listFlatSections($versionId);
        $manualPart = $this->manualPartForSection($source, $flat);
        if ($manualPart <= 0) {
            throw new RuntimeException('That heading is not under a PART.');
        }

        $nodes = $this->flattenNodesFromSectionBlocks($this->blocks->listSectionBlocks($sourceSectionId));
        $slice = ControlledPublishingOutlineService::headingBlockSlice(
            $nodes,
            $sectionRef,
            $sourceBlockId
        );
        if ($slice === array()) {
            throw new RuntimeException('That heading was not found in this chapter.');
        }
        $title = ControlledPublishingOutlineService::stripChapterNumberPrefix(
            trim((string)($slice[0]['section_title'] ?? ''))
        );
        if ($title === '') {
            $title = 'Chapter';
        }

        $parentId = (int)($source['parent_section_id'] ?? 0);
        $nextNumber = 1;
        foreach ($flat as $row) {
            if (!is_array($row) || (int)($row['parent_section_id'] ?? 0) !== $parentId) {
                continue;
            }
            $nextNumber = max($nextNumber, $this->chapterNumberFromSection($row) + 1);
        }
        $newId = $this->ensureChapterSection($versionId, $manualPart, $nextNumber, $title, $actorUserId);

        $sortOrder = 10;
        foreach ($slice as $index => $node) {
            $blockId = (int)($node['block_id'] ?? 0);
            if ($blockId <= 0) {
                continue;
            }
            $payload = is_array($node['payload'] ?? null) ? $node['payload'] : array();
            $oldRef = trim((string)($node['section_ref'] ?? ''));
            $newRef = $oldRef !== ''
                ? ControlledPublishingOutlineService::rewritePromotedSectionRef($sectionRef, $oldRef, $nextNumber)
                : '';
            $style = strtolower(trim((string)($node['paragraph_style'] ?? '')));
            if ($newRef !== '' && preg_match('/^\d+(?:\.\d+)*$/', $newRef) === 1) {
                $payload['canonical_section_ref'] = $newRef;
                $payload['paragraph_style'] = ControlledPublishingDocxReader::sectionRefToParagraphStyle($newRef);
            } elseif ($style !== '') {
                $payload['paragraph_style'] = $style;
            }
            if ($index === 0) {
                $payload['paragraph_style'] = 'title';
                $payload['canonical_section_ref'] = (string)$nextNumber;
                if (isset($payload['text']) && is_string($payload['text'])) {
                    $payload['text'] = $title;
                }
                if (isset($payload['html']) && is_string($payload['html'])) {
                    $payload['html'] = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                }
            }
            $this->blocks->relocateBlock($blockId, $newId, $sortOrder, $payload, $actorUserId);
            $sortOrder += 10;
        }

        $this->deleteLeftoverGenericWrapperHeading($sourceSectionId, $actorUserId);

        return $newId;
    }

    /**
     * Move a MAIN chapter and all of its content under an earlier MAIN chapter.
     */
    public function demoteMainChapterToSubchapter(
        int $versionId,
        int $sourceSectionId,
        int $targetSectionId,
        ?int $actorUserId = null
    ): void {
        if ($this->blocks === null || $sourceSectionId <= 0 || $targetSectionId <= 0) {
            throw new RuntimeException('Could not convert that MAIN chapter.');
        }
        if ($sourceSectionId === $targetSectionId) {
            throw new RuntimeException('Choose a different target MAIN chapter.');
        }
        $source = $this->sections->getSection($versionId, $sourceSectionId);
        $target = $this->sections->getSection($versionId, $targetSectionId);
        $sourceChapter = is_array($source) ? $this->chapterNumberFromSection($source) : 0;
        $targetChapter = is_array($target) ? $this->chapterNumberFromSection($target) : 0;
        if (!is_array($source) || !is_array($target)
            || $sourceChapter <= 0 || $targetChapter <= 0
            || (int)($source['parent_section_id'] ?? 0) !== (int)($target['parent_section_id'] ?? 0)) {
            throw new RuntimeException('Both chapters must be MAIN chapters in the same PART.');
        }
        if ($targetChapter >= $sourceChapter) {
            throw new RuntimeException('A MAIN chapter can only be moved under an earlier MAIN chapter.');
        }

        $nextSubchapter = 1;
        foreach ($this->listNavSubsectionsFromChapterBlocks($targetSectionId, array()) as $item) {
            $ref = trim((string)($item['section_ref'] ?? ''));
            if (preg_match('/^' . preg_quote((string)$targetChapter, '/') . '\.(\d+)$/', $ref, $match) === 1) {
                $nextSubchapter = max($nextSubchapter, (int)$match[1] + 1);
            }
        }
        $targetRef = $targetChapter . '.' . $nextSubchapter;
        $targetBlocks = $this->blocks->listSectionBlocks($targetSectionId);
        $sortOrder = 10;
        foreach ($targetBlocks as $block) {
            $sortOrder = max($sortOrder, (int)($block['sort_order'] ?? 0) + 10);
        }

        $sourceBlocks = $this->blocks->listSectionBlocks($sourceSectionId);
        if ($sourceBlocks === array()) {
            throw new RuntimeException('The MAIN chapter has no content to move.');
        }
        $hasTitleBlock = false;
        foreach ($sourceBlocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $candidatePayload = $this->blocks->decodePayload($block);
            if (strtolower(trim((string)($candidatePayload['paragraph_style'] ?? ''))) === 'title') {
                $hasTitleBlock = true;
                break;
            }
        }
        if (!$hasTitleBlock) {
            throw new RuntimeException('The MAIN chapter title block could not be identified.');
        }
        $titleMoved = false;
        foreach ($sourceBlocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $blockId = (int)($block['id'] ?? 0);
            if ($blockId <= 0) {
                continue;
            }
            $payload = $this->blocks->decodePayload($block);
            $style = strtolower(trim((string)($payload['paragraph_style'] ?? '')));
            $oldRef = trim((string)($payload['canonical_section_ref'] ?? ''));
            if (!$titleMoved && $style === 'title') {
                $payload['paragraph_style'] = 'subtitle_1';
                $payload['canonical_section_ref'] = $targetRef;
                $titleMoved = true;
            } elseif ($oldRef !== '') {
                $newRef = ControlledPublishingOutlineService::rewriteDemotedSectionRef(
                    $sourceChapter,
                    $oldRef,
                    $targetRef
                );
                $payload['canonical_section_ref'] = $newRef;
                if ($newRef === $targetRef) {
                    $payload['paragraph_style'] = 'subtitle_1';
                } elseif ($newRef !== $oldRef) {
                    $payload['paragraph_style'] = ControlledPublishingDocxReader::sectionRefToParagraphStyle($newRef);
                }
            }
            $this->blocks->relocateBlock(
                $blockId,
                $targetSectionId,
                $sortOrder,
                $payload,
                $actorUserId
            );
            $sortOrder += 10;
        }
    }

    public function renumberMainChapterContent(
        int $versionId,
        int $sectionId,
        int $oldChapter,
        int $newChapter,
        ?int $actorUserId = null
    ): void {
        if ($this->blocks === null || $sectionId <= 0 || $oldChapter <= 0
            || $newChapter <= 0 || $oldChapter === $newChapter) {
            return;
        }
        $section = $this->sections->getSection($versionId, $sectionId);
        if (!is_array($section) || $this->chapterNumberFromSection($section) !== $oldChapter) {
            throw new RuntimeException('Could not renumber MAIN chapter content.');
        }
        foreach ($this->blocks->listSectionBlocks($sectionId) as $block) {
            if (!is_array($block)) {
                continue;
            }
            $blockId = (int)($block['id'] ?? 0);
            if ($blockId <= 0) {
                continue;
            }
            $payload = $this->blocks->decodePayload($block);
            $oldRef = trim((string)($payload['canonical_section_ref'] ?? ''));
            $newRef = ControlledPublishingOutlineService::rewritePromotedSectionRef(
                (string)$oldChapter,
                $oldRef,
                $newChapter
            );
            if ($newRef === $oldRef) {
                continue;
            }
            $payload['canonical_section_ref'] = $newRef;
            $payload['paragraph_style'] = ControlledPublishingDocxReader::sectionRefToParagraphStyle($newRef);
            $this->blocks->updateBlock($blockId, $payload, $actorUserId);
        }
    }

    private function deleteLeftoverGenericWrapperHeading(int $sectionId, ?int $actorUserId): void
    {
        if ($this->blocks === null || $sectionId <= 0) {
            return;
        }
        $remaining = $this->listNavSubsectionsFromChapterBlocks($sectionId, array());
        if ($remaining !== array()) {
            return;
        }
        foreach ($this->blocks->listSectionBlocks($sectionId) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $payload = $this->blocks->decodePayload($row);
            $text = $this->navBlockEntryText((string)($row['block_type'] ?? ''), $payload);
            if ($text === '') {
                continue;
            }
            if (!ControlledPublishingDocxReader::isGenericPartWrapperTitle($text)
                && !ControlledPublishingDocxReader::isStrayPartHeadingTitle($text)
            ) {
                return;
            }
        }
        foreach ($this->blocks->listSectionBlocks($sectionId) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $blockId = (int)($row['id'] ?? 0);
            if ($blockId <= 0) {
                continue;
            }
            try {
                $this->blocks->deleteBlock($blockId, $actorUserId);
            } catch (Throwable $e) {
                unset($e);
            }
        }
    }

    /**
     * @param list<array<string,mixed>> $chapters
     */
    private function partHasPopulatedSiblingChapters(array $chapters, int $wrapperSectionId): bool
    {
        foreach ($chapters as $row) {
            if ((int)($row['id'] ?? 0) === $wrapperSectionId) {
                continue;
            }
            if ($this->chapterHasImportedContent($row)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function chapterHasImportedContent(array $row): bool
    {
        if ((int)($row['block_count'] ?? 0) <= 0 || $this->blocks === null) {
            return false;
        }

        foreach ($this->blocks->listSectionBlocks((int)($row['id'] ?? 0)) as $block) {
            if (!is_array($block)) {
                continue;
            }
            $payload = $this->blocks->decodePayload($block);
            $text = $this->navBlockEntryText((string)($block['block_type'] ?? ''), $payload);
            if ($text === '') {
                continue;
            }
            if (preg_match('/^Chapter\s+\d+$/i', $text) === 1) {
                continue;
            }
            if (ControlledPublishingDocxReader::isGenericPartWrapperTitle($text)) {
                continue;
            }
            return true;
        }

        return false;
    }

    /**
     * @param list<array<string,mixed>> $blocks
     * @return list<array<string,mixed>>
     */
    private function flattenNodesFromSectionBlocks(array $blocks): array
    {
        $nodes = array();
        foreach ($blocks as $row) {
            if (!is_array($row)) {
                continue;
            }
            $payload = $this->blocks !== null ? $this->blocks->decodePayload($row) : array();
            $text = $this->navBlockEntryText((string)($row['block_type'] ?? ''), $payload);
            $ref = $this->importedBlockSectionRef($payload);
            $title = $text;
            $parsed = $text !== '' ? ControlledPublishingDocxReader::parseSectionHeading($text) : null;
            if ($parsed !== null) {
                $title = (string)$parsed['title'];
                if ($ref === '') {
                    $ref = (string)$parsed['section_ref'];
                }
            } elseif ($ref !== '') {
                $title = $this->stripLeadingSectionRef($text, $ref);
                if ($title === '') {
                    $title = $text;
                }
            }
            $nodes[] = array(
                'block_id' => (int)($row['id'] ?? 0),
                'payload' => $payload,
                'section_ref' => $ref,
                'section_title' => $title,
                'text' => $text,
                'paragraph_style' => $this->navBlockParagraphStyle((string)($row['block_type'] ?? ''), $payload),
            );
        }

        return $nodes;
    }

    /**
     * @param array<string,mixed> $section
     */
    private function nestedChapterNumber(array $section): int
    {
        $number = $this->chapterNumberFromSection($section);
        if ($number > 0) {
            return $number;
        }
        $title = trim((string)($section['title'] ?? ''));
        return preg_match('/^(\d+)(?:\.|\s|[-–—])/u', $title, $match) === 1
            ? (int)$match[1]
            : 0;
    }

    /**
     * @param list<array<string,mixed>> $blocks
     */
    private function nestedChapterBlockStart(array $blocks, string $chapterTitle): int
    {
        $wanted = $this->comparableChapterTitle($chapterTitle);
        if ($wanted === '') {
            return -1;
        }
        foreach ($blocks as $index => $block) {
            if (!is_array($block)) {
                continue;
            }
            $payload = $this->blocks !== null ? $this->blocks->decodePayload($block) : array();
            $text = $this->navBlockEntryText((string)($block['block_type'] ?? ''), $payload);
            if ($this->comparableChapterTitle($text) === $wanted) {
                return (int)$index;
            }
        }
        return -1;
    }

    private function comparableChapterTitle(string $title): string
    {
        $title = ControlledPublishingOutlineService::stripChapterNumberPrefix($title);
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
        return mb_strtolower($title, 'UTF-8');
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function importedBlockSectionRef(array $payload): string
    {
        $canon = rtrim(trim((string)($payload['canonical_section_ref'] ?? '')), '.');
        if ($canon !== '' && preg_match('/^\d+(?:\.\d+)*$/', $canon) === 1) {
            return $canon;
        }

        return '';
    }

    /**
     * @param list<array<string,mixed>> $nodes
     * @return array<int, array{title:string, nodes:list<array<string,mixed>>}>
     */
    private function groupFlattenedBlockNodes(array $nodes): array
    {
        $groups = array();
        $current = 0;
        $preamble = array();
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $ref = trim((string)($node['section_ref'] ?? ''));
            if (preg_match('/^(\d+)/', $ref, $m) === 1) {
                $chapterNumber = (int)$m[1];
                if ($chapterNumber > 0) {
                    $current = $chapterNumber;
                    if ($preamble !== array()) {
                        $groups[$current]['nodes'] = array_merge(
                            $preamble,
                            $groups[$current]['nodes'] ?? array()
                        );
                        $preamble = array();
                    }
                    if (preg_match('/^\d+$/', $ref) === 1) {
                        $groups[$current]['title'] = trim((string)($node['section_title'] ?? ''));
                    }
                }
            }
            if ($current <= 0) {
                $preamble[] = $node;
                continue;
            }
            $groups[$current]['nodes'][] = $node;
            if (!isset($groups[$current]['title'])) {
                $groups[$current]['title'] = trim((string)($node['section_title'] ?? ''));
            }
        }

        return $groups;
    }

    /**
     * Rewrite cloned OM MAIN titles from imported heading blocks (title / 1. Title).
     */
    private function overlayChapterTitlesFromImportedBlocks(int $versionId): int
    {
        if ($this->blocks === null || $versionId <= 0) {
            return 0;
        }

        $updated = 0;
        foreach ($this->sections->listFlatSections($versionId) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sectionId = (int)($row['id'] ?? 0);
            $number = $this->chapterNumberFromSection($row);
            if ($sectionId <= 0 || $number <= 0) {
                continue;
            }

            $importedTitle = $this->importedChapterTitleFromBlocks($sectionId, $number);
            if ($importedTitle === '') {
                continue;
            }

            $navLabel = $number . '. ' . $importedTitle;
            $meta = $this->decodeMeta($row);
            if (!empty($meta['outline_locked'])) {
                continue;
            }
            $currentTitle = trim((string)($row['title'] ?? ''));
            $currentNav = trim((string)($meta['nav_label'] ?? ''));
            if ($currentTitle === $importedTitle && $currentNav === $navLabel) {
                continue;
            }

            $partKey = $this->partKeyFromSection($row);
            $manualPart = (int)($meta['manual_part'] ?? 0);
            $sectionKey = (string)($row['section_key'] ?? '');
            if ($partKey !== '' && $sectionKey === '') {
                $sectionKey = $this->chapterSectionKey($partKey, $number);
            } elseif ($sectionKey === '') {
                $sectionKey = $this->chapterSectionKey('part_' . max(1, $manualPart), $number);
            }

            $meta['chapter_number'] = $number;
            if ($manualPart > 0) {
                $meta['manual_part'] = $manualPart;
            }
            $meta['nav_label'] = $navLabel;
            $meta['synced_from_canonical'] = true;

            $this->updateChapterSection(
                $sectionId,
                $sectionKey,
                $importedTitle,
                (int)($row['sort_order'] ?? ($number * 10)),
                $meta
            );
            $updated++;
        }

        return $updated;
    }

    private function importedChapterTitleFromBlocks(int $sectionId, int $chapterNumber): string
    {
        if ($this->blocks === null || $sectionId <= 0 || $chapterNumber <= 0) {
            return '';
        }

        $chapterRef = (string)$chapterNumber;
        foreach ($this->blocks->listSectionBlocks($sectionId) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $payload = $this->blocks->decodePayload($row);
            $style = $this->navBlockParagraphStyle((string)($row['block_type'] ?? ''), $payload);
            $depth = ControlledPublishingSectionNumberService::NUMBERED_STYLE_DEPTHS[$style] ?? 0;
            $text = $this->navBlockEntryText((string)($row['block_type'] ?? ''), $payload);
            if ($text === '') {
                continue;
            }

            $canonRef = rtrim(trim((string)($payload['canonical_section_ref'] ?? '')), '.');
            if ($depth === 1 && $canonRef === $chapterRef) {
                $title = $this->stripLeadingSectionRef($text, $chapterRef);
                $title = $this->formatChapterTitle($title !== '' ? $title : $text);
                if ($title !== ''
                    && !preg_match('/^Chapter\s+' . $chapterNumber . '$/i', $title)
                    && !ControlledPublishingDocxReader::isGenericPartWrapperTitle($title)
                    && !ControlledPublishingDocxReader::isStrayPartHeadingTitle($title)
                ) {
                    return $title;
                }
            }

            $parsed = ControlledPublishingDocxReader::parseSectionHeading($text);
            if ($parsed !== null && (string)$parsed['section_ref'] === $chapterRef) {
                $title = $this->formatChapterTitle((string)$parsed['title']);
                if ($title !== ''
                    && !preg_match('/^Chapter\s+' . $chapterNumber . '$/i', $title)
                    && !ControlledPublishingDocxReader::isGenericPartWrapperTitle($title)
                    && !ControlledPublishingDocxReader::isStrayPartHeadingTitle($title)
                ) {
                    return $title;
                }
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $row
     */
    private function partKeyFromSection(array $row): string
    {
        $key = (string)($row['section_key'] ?? '');
        if (preg_match('/^(part_\d+|main_content)_chapter_\d+$/', $key, $m) === 1) {
            return (string)$m[1];
        }

        return '';
    }

    /**
     * All subsection refs under a chapter for sidebar navigation (5.1, 5.1.1, …).
     *
     * @return list<array{section_ref:string,title:string,nav_label:string}>
     */
    public function listNavSubsectionsForChapter(
        string $manualCode,
        int $sourceSetId,
        int $manualPart,
        int $chapterNumber
    ): array {
        unset($manualCode, $sourceSetId, $manualPart, $chapterNumber);

        return array();
    }

    /**
     * @return list<array{section_ref:string,title:string,nav_label:string}>
     */
    public function listSubtitle1ForChapter(
        string $manualCode,
        int $sourceSetId,
        int $manualPart,
        int $chapterNumber
    ): array {
        return $this->listNavSubsectionsForChapter($manualCode, $sourceSetId, $manualPart, $chapterNumber);
    }

    public function isValidChapterNavEntry(array $sectionRow): bool
    {
        $meta = $this->decodeMeta($sectionRow);
        $chapterNumber = (int)($meta['chapter_number'] ?? 0);
        if ($chapterNumber <= 0 || $chapterNumber > ControlledPublishingDocxReader::MAX_CHAPTER_NUMBER) {
            return false;
        }
        // A locked outline row is an explicit author decision. Do not hide it
        // because its title contains an aviation acronym also seen in tables.
        if (!empty($meta['outline_locked'])) {
            return true;
        }

        $title = ControlledPublishingDocxReader::sanitizeSectionTitle(trim((string)($sectionRow['title'] ?? '')));
        $navLabel = trim((string)($meta['nav_label'] ?? ''));
        $label = $navLabel !== '' ? $navLabel : ($chapterNumber . '. ' . $title);

        if (ControlledPublishingDocxReader::isLikelyTableOrMeasurementExcerpt((string)$chapterNumber, $label)) {
            return false;
        }
        if ($title !== '' && ControlledPublishingDocxReader::isLikelyTableOrMeasurementExcerpt((string)$chapterNumber, $title)) {
            return false;
        }

        return true;
    }

    /**
     * Remove chapter sections that fail nav validation (table fragments, instrument labels, etc.).
     *
     * @return array{sections_removed:int,invalid_excerpts_retired:int}
     */
    public function pruneInvalidChapterSections(int $versionId): array
    {
        $version = $this->foundation->getVersion($versionId);
        if ($version === null) {
            throw new RuntimeException('Book version not found.');
        }
        if ((string)($version['lifecycle_status'] ?? '') === 'released') {
            throw new RuntimeException('Released versions cannot be cleaned up.');
        }

        $bookKey = strtoupper(trim((string)($version['book_key'] ?? 'OM')));
        $invalidExcerptsRetired = $this->pruneInvalidCanonicalExcerpts($versionId);
        $removed = 0;

        foreach (self::PART_SECTION_KEYS[$bookKey] ?? self::PART_SECTION_KEYS['OM'] as $partKey) {
            $parentId = $this->resolvePartParentSectionId($versionId, $partKey);
            if ($parentId <= 0) {
                continue;
            }
            foreach ($this->listChildSections($versionId, $parentId) as $row) {
                if ($this->chapterNumberFromSection($row) <= 0) {
                    continue;
                }
                if ($this->isValidChapterNavEntry($row)) {
                    continue;
                }
                $this->deleteSection((int)$row['id']);
                $removed++;
            }
        }

        return array(
            'sections_removed' => $removed,
            'invalid_excerpts_retired' => $invalidExcerptsRetired,
        );
    }

    private function isSkippableNavExcerpt(string $sectionRef, string $title, int $manualPart): bool
    {
        $title = ControlledPublishingDocxReader::sanitizeSectionTitle(trim($title));
        if (ControlledPublishingDocxReader::isLikelyTableOrMeasurementExcerpt($sectionRef, $title)) {
            return true;
        }
        if ($title === '') {
            return false;
        }
        if (!ControlledPublishingDocxReader::isPlausibleManualSectionRef($sectionRef, $title, $manualPart)) {
            return true;
        }

        return $this->isSkippableCanonicalExcerpt($sectionRef, $title, '', $manualPart);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function navBlockParagraphStyle(string $blockType, array $payload): string
    {
        $style = strtolower(trim((string)($payload['paragraph_style'] ?? '')));
        $style = ControlledPublishingBookStyleService::LEGACY_PARAGRAPH_STYLE_ALIASES[$style] ?? $style;
        if ($style === '' && $blockType === 'heading') {
            $level = max(1, min(6, (int)($payload['level'] ?? 2)));
            return $level <= 1 ? 'subtitle_2' : ($level === 2 ? 'subtitle_3' : 'subtitle_4');
        }

        return $style;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function navBlockEntryText(string $blockType, array $payload): string
    {
        if ($blockType === 'heading') {
            return trim((string)($payload['text'] ?? ''));
        }
        if ($blockType === 'paragraph') {
            $html = (string)($payload['html'] ?? '');
            $text = trim(strip_tags($html));
            return $text !== '' ? $text : '';
        }

        return '';
    }

    /**
     * Resolve the running-header part title for a section.
     *
     * @param list<array<string,mixed>> $flatSections
     */
    public function resolvePartTitleForSection(array $section, array $flatSections): string
    {
        $key = (string)($section['section_key'] ?? '');
        if (isset(self::PART_TITLES[$key])) {
            return $this->resolvedPartTitleRow($section, $flatSections);
        }
        if (in_array($key, self::PART0_SECTION_KEYS, true)) {
            return ControlledPublishingPart0PageService::PART_TITLE;
        }

        $byId = array();
        foreach ($flatSections as $row) {
            $byId[(int)($row['id'] ?? 0)] = $row;
        }

        $parentId = $section['parent_section_id'] !== null ? (int)$section['parent_section_id'] : 0;
        while ($parentId > 0) {
            $parent = $byId[$parentId] ?? null;
            if (!is_array($parent)) {
                break;
            }
            $parentKey = (string)($parent['section_key'] ?? '');
            if (isset(self::PART_TITLES[$parentKey])) {
                return $this->resolvedPartTitleRow($parent, $flatSections);
            }
            if (in_array($parentKey, self::PART0_SECTION_KEYS, true)) {
                return ControlledPublishingPart0PageService::PART_TITLE;
            }
            $parentId = $parent['parent_section_id'] !== null ? (int)$parent['parent_section_id'] : 0;
        }

        return '';
    }

    /**
     * Prefer the canonical PART 1 label when an older manual still keeps its
     * chapters under the legacy main_content container.
     *
     * @param list<array<string,mixed>> $flatSections
     */
    private function resolvedPartTitleRow(array $row, array $flatSections): string
    {
        $key = (string)($row['section_key'] ?? '');
        $fallback = self::PART_TITLES[$key] ?? '';
        if ($key !== 'main_content') {
            return ControlledPublishingOutlineService::partNavTitle($row, $fallback);
        }

        foreach ($flatSections as $candidate) {
            if ((string)($candidate['section_key'] ?? '') === 'part_1') {
                return ControlledPublishingOutlineService::partNavTitle(
                    $candidate,
                    self::PART_TITLES['part_1']
                );
            }
        }

        $legacyTitle = ControlledPublishingOutlineService::partNavTitle($row, $fallback);
        return $legacyTitle === 'PART 1 – MAIN CONTENT'
            ? ControlledPublishingOutlineService::formatPartNavTitle(1, 'General')
            : $legacyTitle;
    }

    /**
     * @param array<string,mixed> $section
     */
    public function manualPartForSection(array $section, array $flatSections): int
    {
        $meta = $this->decodeMeta($section);
        if (!empty($meta['manual_part'])) {
            return (int)$meta['manual_part'];
        }

        $byId = array();
        foreach ($flatSections as $row) {
            $byId[(int)($row['id'] ?? 0)] = $row;
        }

        $parentId = $section['parent_section_id'] !== null ? (int)$section['parent_section_id'] : 0;
        while ($parentId > 0) {
            $parent = $byId[$parentId] ?? null;
            if (!is_array($parent)) {
                break;
            }
            $parentKey = (string)($parent['section_key'] ?? '');
            if (preg_match('/^part_(\d+)$/', $parentKey, $m)) {
                return (int)$m[1];
            }
            if ($parentKey === 'main_content') {
                return 1;
            }
            $parentId = $parent['parent_section_id'] !== null ? (int)$parent['parent_section_id'] : 0;
        }

        return 0;
    }

    /**
     * Sidebar label for a chapter subsection.
     *
     * @param array<string,mixed> $row
     */
    public static function navLabelForSection(array $row, bool $uppercase = false): string
    {
        $meta = $row['metadata_json'] ?? null;
        if (is_string($meta)) {
            $meta = json_decode($meta, true);
        }
        $label = '';
        if (is_array($meta) && trim((string)($meta['nav_label'] ?? '')) !== '') {
            $label = trim((string)$meta['nav_label']);
        } else {
            $title = trim((string)($row['title'] ?? ''));
            $key = (string)($row['section_key'] ?? '');
            if (preg_match('/_chapter_(\d+)$/', $key, $m) && $title !== '') {
                $label = $m[1] . '. ' . $title;
            } else {
                $label = $title;
            }
        }

        return $uppercase ? self::uppercaseNavLabel($label) : $label;
    }

    public static function uppercaseNavLabel(string $label): string
    {
        $label = trim($label);
        if ($label === '') {
            return '';
        }
        if (preg_match('/^(\d+(?:\.\d+)*\.?\s+)(.+)$/u', $label, $m)) {
            return $m[1] . mb_strtoupper($m[2], 'UTF-8');
        }
        return mb_strtoupper($label, 'UTF-8');
    }

    private function importChapterContent(
        int $versionId,
        int $sectionId,
        array $sectionRow,
        int $sourceSetId,
        string $manualCode,
        int $manualPart,
        int $chapterNumber,
        ?int $actorUserId
    ): int {
        $blocks = $this->blocks;
        if ($blocks === null) {
            return 0;
        }

        $created = 0;
        $chapterTitle = trim((string)($sectionRow['title'] ?? 'Chapter ' . $chapterNumber));
        $blocks->createBlock($versionId, $sectionId, 'paragraph', array(
            'html' => '<p>' . htmlspecialchars($chapterTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>',
            'paragraph_style' => 'title',
            'canonical_section_ref' => (string)$chapterNumber,
        ), $actorUserId);
        $created++;

        $stmt = $this->pdo->prepare("
            SELECT section_ref, title, body_text
            FROM ipca_canonical_excerpts
            WHERE source_set_id = :source_set_id
              AND manual_code = :manual_code
              AND manual_part = :manual_part
              AND source_status = 'active'
              AND section_ref REGEXP :pattern
            ORDER BY section_ref
        ");
        $stmt->execute(array(
            ':source_set_id' => $sourceSetId,
            ':manual_code' => $manualCode,
            ':manual_part' => (string)$manualPart,
            ':pattern' => '^' . $chapterNumber . '\\.[0-9]',
        ));

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row) {
            $ref = trim((string)($row['section_ref'] ?? ''));
            if ($ref === '' || !str_contains($ref, '.')) {
                continue;
            }
            if ($this->isSkippableCanonicalExcerpt($ref, (string)($row['title'] ?? ''), (string)($row['body_text'] ?? ''))) {
                continue;
            }
            $style = $this->sectionRefToParagraphStyle($ref);
            if ($style === 'title') {
                continue;
            }

            $parsed = $this->parseExcerptBody((string)($row['body_text'] ?? ''), $ref);
            $headingText = $parsed['heading'] !== '' ? $parsed['heading'] : trim((string)($row['title'] ?? ''));
            $headingText = $this->stripLeadingSectionRef($headingText, $ref);

            if ($headingText !== '') {
                $blocks->createBlock($versionId, $sectionId, 'paragraph', array(
                    'html' => '<p>' . htmlspecialchars($headingText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>',
                    'paragraph_style' => $style,
                    'canonical_section_ref' => $ref,
                ), $actorUserId);
                $created++;
            }

            if ($parsed['body'] !== '') {
                $blocks->createBlock($versionId, $sectionId, 'paragraph', array(
                    'html' => $this->bodyTextToHtml($parsed['body']),
                    'paragraph_style' => 'body',
                ), $actorUserId);
                $created++;
            }
        }

        return $created;
    }

    private function sectionRefToParagraphStyle(string $sectionRef): string
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

    /**
     * @return array{heading:string,body:string}
     */
    private function parseExcerptBody(string $bodyText, string $sectionRef): array
    {
        $text = str_replace('\\n', "\n", trim($bodyText));
        if ($text === '') {
            return array('heading' => '', 'body' => '');
        }

        $parts = preg_split("/\r\n|\n/", $text, 2) ?: array($text);
        $heading = trim((string)($parts[0] ?? ''));
        $body = trim((string)($parts[1] ?? ''));

        if ($heading !== '' && $this->stripLeadingSectionRef($heading, $sectionRef) === '') {
            $heading = trim((string)($parts[1] ?? ''));
            $bodyParts = preg_split("/\r\n|\n/", $text, 3) ?: array();
            $body = trim((string)($bodyParts[2] ?? ''));
        }

        return array(
            'heading' => $this->stripLeadingSectionRef($heading, $sectionRef),
            'body' => $body,
        );
    }

    private function stripLeadingSectionRef(string $text, string $sectionRef): string
    {
        $text = trim($text);
        $pattern = '/^' . preg_quote($sectionRef, '/') . '(?:\.|\s)+/u';
        $stripped = preg_replace($pattern, '', $text);
        return is_string($stripped) ? trim($stripped) : $text;
    }

    private function bodyTextToHtml(string $body): string
    {
        $paragraphs = preg_split("/\n\s*\n/", $body) ?: array($body);
        $html = '';
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }
            $lines = preg_split("/\r\n|\n/", $paragraph) ?: array($paragraph);
            $inner = implode('<br>', array_map(
                static fn(string $line): string => htmlspecialchars(trim($line), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                $lines
            ));
            $html .= '<p>' . $inner . '</p>';
        }
        if ($html === '') {
            $html = '<p></p>';
        }
        return $html;
    }

    /**
     * OCR / table fragments mis-tagged as section excerpts (e.g. "1.000 Kg" under Part 3).
     */
    private function isSkippableCanonicalExcerpt(string $sectionRef, string $title, string $bodyText, int $manualPart = -1): bool
    {
        $sectionRef = trim($sectionRef);
        $title = $this->resolveCanonicalExcerptTitle($title, $bodyText);
        $bodyText = trim($bodyText);

        if (ControlledPublishingDocxReader::isLikelyTableOrMeasurementExcerpt($sectionRef, $title)) {
            return true;
        }

        if ($title === '') {
            return false;
        }

        $allowTitleCaseChapter = preg_match('/^\d+$/', $sectionRef) === 1
            && ControlledPublishingDocxReader::isPlausibleSubtitleTitle($title);
        if (!ControlledPublishingDocxReader::isPlausibleManualSectionRef(
            $sectionRef,
            $title,
            $manualPart,
            $allowTitleCaseChapter
        )) {
            return true;
        }

        if (preg_match('/^\d+\.\d{3,}$/', $sectionRef)) {
            return true;
        }
        if (preg_match('/^\d{3,}$/', $sectionRef)) {
            return true;
        }
        if (preg_match('/^\d+\.\d+\s*Kg$/i', $title) || preg_match('/^Kg$/i', $title)) {
            return true;
        }
        if (preg_match('/^\d+\s*ft\b/i', $title)) {
            return true;
        }
        if (str_contains($title, '|')) {
            return true;
        }
        if (preg_match('/\b(DEGREES|FEATHERED|LOW PITCH|START LOCK|PITCH LOCK)\b/i', $title)) {
            return true;
        }
        if (preg_match('/\(\s*(LOW PITCH|START LOCK|FEATHERED|PITCH LOCK)\s*\)/i', $title)) {
            return true;
        }
        if (preg_match('/^\d+\.\d+$/', $sectionRef)) {
            $leaf = (int)substr($sectionRef, strrpos($sectionRef, '.') + 1);
            if ($leaf > 11) {
                return true;
            }
        }
        if (preg_match('/Chapter\s+\d+,\s*Page\s+\d+/i', $bodyText) && strlen($bodyText) < 120) {
            return true;
        }
        if ($manualPart === 2 || $manualPart === 3) {
            if (preg_match('/Take\s+Off/i', $title . ' ' . $bodyText) && !preg_match('/Route|Planning|NCO\.OP/i', $title . ' ' . $bodyText)) {
                return true;
            }
            if (preg_match('/Cruise\s+performance|Landing\s+performance|Enroute\s+\/\s+Cruise/i', $title . ' ' . $bodyText)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Retire canonical rows that fail manual section validation (table rows, instrument data, etc.).
     */
    public function pruneInvalidCanonicalExcerpts(int $versionId): int
    {
        $version = $this->foundation->getVersion($versionId);
        if ($version === null) {
            return 0;
        }
        $sourceSetId = $this->resolveManualSourceSetId($versionId);
        if ($sourceSetId <= 0) {
            return 0;
        }
        $manualCode = strtoupper(trim((string)($version['manual_code'] ?? $version['book_key'] ?? 'OM')));

        return $this->retireInvalidCanonicalExcerpts($sourceSetId, $manualCode);
    }

    /**
     * Retire canonical rows that fail manual section validation (table rows, instrument data, etc.).
     */
    private function retireInvalidCanonicalExcerpts(int $sourceSetId, string $manualCode): int
    {
        $stmt = $this->pdo->prepare("
            SELECT id, section_ref, title, body_text, manual_part
            FROM ipca_canonical_excerpts
            WHERE source_set_id = :source_set_id
              AND manual_code = :manual_code
              AND source_status = 'active'
        ");
        $stmt->execute(array(
            ':source_set_id' => $sourceSetId,
            ':manual_code' => strtoupper(trim($manualCode)),
        ));

        $retired = 0;
        $update = $this->pdo->prepare("
            UPDATE ipca_canonical_excerpts
            SET source_status = 'retired',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row) {
            $ref = trim((string)($row['section_ref'] ?? ''));
            $body = trim((string)($row['body_text'] ?? ''));
            $part = (int)($row['manual_part'] ?? 0);
            $title = $this->resolveCanonicalExcerptTitle((string)($row['title'] ?? ''), $body);
            if ($ref === '' || !$this->isSkippableCanonicalExcerpt($ref, $title, $body, $part)) {
                continue;
            }
            $update->execute(array(':id' => (int)$row['id']));
            $retired++;
        }

        return $retired;
    }

    private function resolveCanonicalExcerptTitle(string $title, string $bodyText): string
    {
        $title = ControlledPublishingDocxReader::sanitizeSectionTitle(trim($title));
        if ($title !== '') {
            return $title;
        }

        $bodyText = trim(str_replace('\\n', "\n", $bodyText));
        if ($bodyText === '') {
            return '';
        }

        $firstLine = trim(strtok($bodyText, "\n") ?: '');
        if ($firstLine === '') {
            return '';
        }

        $parsed = ControlledPublishingDocxReader::parseSectionHeading($firstLine);
        if ($parsed !== null) {
            return ControlledPublishingDocxReader::sanitizeSectionTitle((string)($parsed['title'] ?? ''));
        }

        return ControlledPublishingDocxReader::sanitizeImportedText($firstLine);
    }
}
