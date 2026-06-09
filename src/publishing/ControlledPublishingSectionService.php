<?php
declare(strict_types=1);

/**
 * Section tree and nested subsection management for the book editor.
 */
final class ControlledPublishingSectionService
{
    /** Section types that may contain author-created subsections. */
    private const NESTABLE_SECTION_KEYS = array('part_1', 'part_2', 'part_3', 'part_4', 'main_content', 'annexes');

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listFlatSections(int $versionId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
              s.*,
              COALESCE(ts.allow_author_blocks, 0) AS allow_author_blocks,
              (SELECT COUNT(*) FROM ipca_publishing_book_blocks b WHERE b.section_id = s.id) AS block_count
            FROM ipca_publishing_book_sections s
            LEFT JOIN ipca_publishing_book_template_sections ts ON ts.id = s.template_section_id
            WHERE s.book_version_id = :version_id
            ORDER BY s.sort_order, s.id
        ");
        $stmt->execute(array(':version_id' => $versionId));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    /**
     * Nested section tree for the editor sidebar.
     *
     * @return list<array<string,mixed>>
     */
    public function listSectionTree(int $versionId): array
    {
        $flat = $this->listFlatSections($versionId);
        $byParent = array();
        foreach ($flat as $row) {
            $parentId = $row['parent_section_id'] !== null ? (int)$row['parent_section_id'] : 0;
            if (!isset($byParent[$parentId])) {
                $byParent[$parentId] = array();
            }
            $byParent[$parentId][] = $row;
        }

        return $this->buildTreeNodes($byParent, 0);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getSection(int $versionId, int $sectionId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT
              s.*,
              COALESCE(ts.allow_author_blocks, 0) AS allow_author_blocks
            FROM ipca_publishing_book_sections s
            LEFT JOIN ipca_publishing_book_template_sections ts ON ts.id = s.template_section_id
            WHERE s.id = :id AND s.book_version_id = :version_id
            LIMIT 1
        ");
        $stmt->execute(array(':id' => $sectionId, ':version_id' => $versionId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function createSubsection(
        int $versionId,
        int $parentSectionId,
        string $title,
        ?int $actorUserId = null
    ): int {
        $version = $this->getVersionRow($versionId);
        if ($version === null) {
            throw new RuntimeException('Book version not found.');
        }
        if ((string)$version['lifecycle_status'] === 'released') {
            throw new RuntimeException('Released versions cannot be edited.');
        }

        $parent = $this->getSection($versionId, $parentSectionId);
        if ($parent === null) {
            throw new RuntimeException('Parent section not found.');
        }
        if (!$this->parentAllowsSubsections($parent)) {
            throw new RuntimeException('Subsections are only allowed under manual parts and annexes.');
        }

        $title = trim($title);
        if ($title === '') {
            throw new RuntimeException('Subsection title is required.');
        }

        $bookKey = (string)$version['book_key'];
        $versionLabel = (string)$version['version_label'];
        $parentKey = (string)$parent['section_key'];
        $sectionKey = $this->uniqueSectionKey($versionId, $parentKey, $title);
        $stableAnchor = $this->childAnchor(
            (string)$parent['stable_anchor'],
            $sectionKey
        );
        $sortOrder = $this->nextChildSortOrder($versionId, $parentSectionId);

        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_publishing_book_sections
                (book_version_id, parent_section_id, section_key, stable_anchor, title, section_type,
                 is_system_managed, is_generated, sort_order, created_by)
            VALUES
                (:book_version_id, :parent_section_id, :section_key, :stable_anchor, :title, 'content',
                 0, 0, :sort_order, :created_by)
        ");
        $stmt->execute(array(
            ':book_version_id' => $versionId,
            ':parent_section_id' => $parentSectionId,
            ':section_key' => $sectionKey,
            ':stable_anchor' => $stableAnchor,
            ':title' => $title,
            ':sort_order' => $sortOrder,
            ':created_by' => $actorUserId,
        ));

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $parent
     */
    private function parentAllowsSubsections(array $parent): bool
    {
        $key = (string)($parent['section_key'] ?? '');
        if (in_array($key, self::NESTABLE_SECTION_KEYS, true)) {
            return true;
        }
        if (!empty($parent['parent_section_id'])) {
            return true;
        }
        return false;
    }

    /**
     * @param array<int,list<array<string,mixed>>> $byParent
     * @return list<array<string,mixed>>
     */
    private function buildTreeNodes(array $byParent, int $parentId): array
    {
        $nodes = array();
        foreach ($byParent[$parentId] ?? array() as $row) {
            $id = (int)$row['id'];
            $nodes[] = array(
                'id' => $id,
                'parent_section_id' => $row['parent_section_id'] !== null ? (int)$row['parent_section_id'] : null,
                'section_key' => (string)$row['section_key'],
                'title' => (string)$row['title'],
                'section_type' => (string)$row['section_type'],
                'stable_anchor' => (string)$row['stable_anchor'],
                'allow_author_blocks' => !empty($row['allow_author_blocks']),
                'is_system_managed' => !empty($row['is_system_managed']),
                'is_generated' => !empty($row['is_generated']),
                'block_count' => (int)($row['block_count'] ?? 0),
                'children' => $this->buildTreeNodes($byParent, $id),
            );
        }
        return $nodes;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function getVersionRow(int $versionId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT bv.*, b.book_key, b.title AS book_title
            FROM ipca_publishing_book_versions bv
            INNER JOIN ipca_publishing_books b ON b.id = bv.book_id
            WHERE bv.id = :id
            LIMIT 1
        ");
        $stmt->execute(array(':id' => $versionId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function uniqueSectionKey(int $versionId, string $parentKey, string $title): string
    {
        $base = $parentKey . '_' . $this->slug($title);
        $key = $base;
        $n = 2;
        while ($this->sectionKeyExists($versionId, $key)) {
            $key = $base . '_' . $n;
            $n++;
        }
        return substr($key, 0, 120);
    }

    private function sectionKeyExists(int $versionId, string $sectionKey): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT 1 FROM ipca_publishing_book_sections
            WHERE book_version_id = :version_id AND section_key = :section_key
            LIMIT 1
        ");
        $stmt->execute(array(':version_id' => $versionId, ':section_key' => $sectionKey));
        return (bool)$stmt->fetchColumn();
    }

    private function childAnchor(string $parentAnchor, string $sectionKey): string
    {
        $suffix = strtoupper(str_replace('_', '-', $sectionKey));
        if (strlen($parentAnchor . '-' . $suffix) > 191) {
            $suffix = strtoupper(substr(md5($sectionKey), 0, 12));
        }
        return $parentAnchor . '-' . $suffix;
    }

    private function nextChildSortOrder(int $versionId, int $parentSectionId): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COALESCE(MAX(sort_order), 0)
            FROM ipca_publishing_book_sections
            WHERE book_version_id = :version_id AND parent_section_id = :parent_id
        ");
        $stmt->execute(array(':version_id' => $versionId, ':parent_id' => $parentSectionId));
        return ((int)$stmt->fetchColumn()) + 10;
    }

    private function slug(string $title): string
    {
        $slug = strtolower(trim($title));
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?? '';
        $slug = trim($slug, '_');
        return $slug !== '' ? $slug : 'section';
    }
}
