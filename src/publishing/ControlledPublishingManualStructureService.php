<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingBlockService.php';
require_once __DIR__ . '/ControlledPublishingDocxReader.php';
require_once __DIR__ . '/ControlledPublishingFoundationService.php';
require_once __DIR__ . '/ControlledPublishingPart0PageService.php';
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
        'part_1' => 'Part 1 – General',
        'main_content' => 'Part 1 – General',
        'part_2' => 'Part 2 – Technical',
        'part_3' => 'Part 3 – Route',
        'part_4' => 'Part 4 – Personnel Training',
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
    public function syncVersionStructure(int $versionId, ?int $actorUserId = null): array
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
                $actorUserId
            );
            $partsSynced++;
            $created += $result['created'];
            $updated += $result['updated'];
            $removed += $result['removed'];
        }

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
     * Sync when a part has no canonical chapter subsections yet.
     */
    public function ensureVersionStructure(int $versionId, ?int $actorUserId = null): array
    {
        if (!$this->needsStructureSync($versionId)) {
            return array(
                'parts_synced' => 0,
                'chapters_created' => 0,
                'chapters_updated' => 0,
                'chapters_removed' => 0,
                'skipped' => true,
            );
        }

        $result = $this->syncVersionStructure($versionId, $actorUserId);
        $result['skipped'] = false;
        return $result;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function resolveVersion(int $versionId): ?array
    {
        return $this->foundation->getVersion($versionId);
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
    public function listChaptersForPart(string $manualCode, int $sourceSetId, int $manualPart): array
    {
        $manualCode = strtoupper(trim($manualCode));
        if ($manualCode === 'OMM') {
            return $this->listOmmChapters($sourceSetId, $manualPart);
        }

        return $this->listOmChapters($sourceSetId, $manualPart);
    }

    /**
     * @return list<array{chapter_number:int,title:string,nav_label:string}>
     */
    private function listOmmChapters(int $sourceSetId, int $manualPart): array
    {
        $stmt = $this->pdo->prepare("
            SELECT section_ref, title
            FROM ipca_canonical_excerpts
            WHERE source_set_id = :source_set_id
              AND manual_code = 'OMM'
              AND manual_part = :manual_part
              AND source_status = 'active'
              AND section_ref REGEXP '^[0-9]+$'
              AND section_ref <> '0'
              AND UPPER(title) <> 'OUTLINE'
            ORDER BY CAST(section_ref AS UNSIGNED), id
        ");
        $stmt->execute(array(
            ':source_set_id' => $sourceSetId,
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
    private function listOmChapters(int $sourceSetId, int $manualPart): array
    {
        $stmt = $this->pdo->prepare("
            SELECT section_ref, title, body_text
            FROM ipca_canonical_excerpts
            WHERE source_set_id = :source_set_id
              AND manual_code = 'OM'
              AND manual_part = :manual_part
              AND source_status = 'active'
            ORDER BY section_ref, id
        ");
        $stmt->execute(array(
            ':source_set_id' => $sourceSetId,
            ':manual_part' => (string)$manualPart,
        ));

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
        if ($rows === array()) {
            return array();
        }

        $chapterNumbers = array();
        $headingTitles = array();

        foreach ($rows as $row) {
            $ref = trim((string)($row['section_ref'] ?? ''));
            if ($ref === '' || $this->isSkippableCanonicalExcerpt($ref, (string)($row['title'] ?? ''), '')) {
                continue;
            }
            if (preg_match('/^(\d+)$/', $ref, $chapterMatch)) {
                $chapterNum = (int)$chapterMatch[1];
                if ($chapterNum > 0) {
                    $chapterNumbers[$chapterNum] = true;
                    $titleCandidate = trim((string)($row['title'] ?? ''));
                    if ($titleCandidate !== '' && strcasecmp($titleCandidate, 'outline') !== 0) {
                        $headingTitles[$chapterNum] = $this->formatChapterTitle($titleCandidate);
                    }
                }
                continue;
            }
            if (preg_match('/^(\d+)\.\d/', $ref, $m)) {
                $chapterNumbers[(int)$m[1]] = true;
            }

            $text = str_replace('\\n', "\n", (string)($row['body_text'] ?? ''));
            if (preg_match_all('/(?:^|\n\n)(\d+)\.\s+([A-Z][A-Z0-9 ,\-\/&()]+)\s*(?:\n|$)/', $text, $headingMatches, PREG_SET_ORDER)) {
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
            if (isset(self::OM_DEFAULT_CHAPTER_TITLES[$number])) {
                $title = self::OM_DEFAULT_CHAPTER_TITLES[$number];
            } elseif (isset($headingTitles[$number])) {
                $title = $headingTitles[$number];
            } else {
                $title = 'Chapter ' . $number;
            }
            $chapters[] = $this->chapterDef($number, $title);
        }

        return $chapters;
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
        ?int $actorUserId
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
                $needsUpdate = ((string)($row['title'] ?? '') !== $title)
                    || ((string)($row['section_key'] ?? '') !== $sectionKey)
                    || (int)($meta['manual_part'] ?? 0) !== $manualPart
                    || !$this->isCanonicalChapterSection($row);
                if ($needsUpdate) {
                    $this->updateChapterSection(
                        (int)$row['id'],
                        $sectionKey,
                        $title,
                        $number * 10,
                        $metadata
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
            if ($this->canRemoveChapterSection($row)) {
                $this->deleteSection((int)$row['id']);
                $removed++;
            }
        }

        foreach ($orphans as $row) {
            if ($this->canRemoveChapterSection($row)) {
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

    private function resolvePartParentSectionId(int $versionId, string $partKey): int
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
              AND vss.selection_role = 'manual_source'
            ORDER BY vss.id
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
    ): void {
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
        $manualCode = strtoupper(trim($manualCode));
        $pattern = '^' . $chapterNumber . '\\.[0-9]+(\\.[0-9]+)*$';
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
            ':pattern' => $pattern,
        ));

        $items = array();
        $seen = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row) {
            $ref = trim((string)($row['section_ref'] ?? ''));
            $title = $this->resolveCanonicalExcerptTitle(
                (string)($row['title'] ?? ''),
                (string)($row['body_text'] ?? '')
            );
            if ($ref === '' || isset($seen[$ref])) {
                continue;
            }
            if ($this->isSkippableNavExcerpt($ref, $title, $manualPart)) {
                continue;
            }
            $seen[$ref] = true;
            $items[] = array(
                'section_ref' => $ref,
                'title' => $title,
                'nav_label' => $ref . ' ' . $title,
            );
        }

        return $items;
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
     * Resolve the running-header part title for a section.
     *
     * @param list<array<string,mixed>> $flatSections
     */
    public function resolvePartTitleForSection(array $section, array $flatSections): string
    {
        $key = (string)($section['section_key'] ?? '');
        if (isset(self::PART_TITLES[$key])) {
            return self::PART_TITLES[$key];
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
                return self::PART_TITLES[$parentKey];
            }
            if (in_array($parentKey, self::PART0_SECTION_KEYS, true)) {
                return ControlledPublishingPart0PageService::PART_TITLE;
            }
            $parentId = $parent['parent_section_id'] !== null ? (int)$parent['parent_section_id'] : 0;
        }

        return '';
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

        if (!ControlledPublishingDocxReader::isPlausibleManualSectionRef($sectionRef, $title, $manualPart)) {
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
        if (preg_match('/Take\s+Off/i', $title . ' ' . $bodyText) && !preg_match('/Route|Planning|NCO\.OP/i', $title . ' ' . $bodyText)) {
            return true;
        }
        if (preg_match('/Cruise\s+performance|Landing\s+performance|Enroute\s+\/\s+Cruise/i', $title . ' ' . $bodyText)) {
            return true;
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
