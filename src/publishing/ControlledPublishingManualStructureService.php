<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingFoundationService.php';
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

    public function __construct(
        private PDO $pdo,
        private ControlledPublishingFoundationService $foundation,
        private ControlledPublishingSectionService $sections
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
        $partsSynced = 0;
        $created = 0;
        $updated = 0;
        $removed = 0;

        foreach (self::PART_SECTION_KEYS[$bookKey] ?? self::PART_SECTION_KEYS['OM'] as $partIndex => $partKey) {
            $parentId = $this->resolvePartParentSectionId($versionId, $partKey);
            if ($parentId <= 0) {
                continue;
            }

            $chapters = $this->listChaptersForPart($manualCode, $sourceSetId, $partIndex + 1);
            if ($chapters === array()) {
                continue;
            }

            $result = $this->syncChaptersUnderParent(
                $versionId,
                $parentId,
                $partKey,
                $chapters,
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
            if ($ref === '') {
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
                'nav_label' => $navLabel,
                'synced_from_canonical' => true,
            );

            if (isset($existingByNumber[$number])) {
                $row = $existingByNumber[$number];
                $needsUpdate = ((string)($row['title'] ?? '') !== $title)
                    || ((string)($row['section_key'] ?? '') !== $sectionKey)
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
        $raw = trim($raw);
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
     * Sidebar label for a chapter subsection.
     *
     * @param array<string,mixed> $row
     */
    public static function navLabelForSection(array $row): string
    {
        $meta = $row['metadata_json'] ?? null;
        if (is_string($meta)) {
            $meta = json_decode($meta, true);
        }
        if (is_array($meta) && trim((string)($meta['nav_label'] ?? '')) !== '') {
            return trim((string)$meta['nav_label']);
        }

        $title = trim((string)($row['title'] ?? ''));
        $key = (string)($row['section_key'] ?? '');
        if (preg_match('/_chapter_(\d+)$/', $key, $m) && $title !== '') {
            return $m[1] . '. ' . $title;
        }

        return $title;
    }
}
