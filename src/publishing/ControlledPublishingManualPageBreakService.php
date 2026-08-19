<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingReaderLayoutProfile.php';
require_once __DIR__ . '/ControlledPublishingReaderPageMapStore.php';
require_once __DIR__ . '/BooksManualsVersionEditPolicy.php';

/**
 * Additive manual page-break instructions anchored before stable source blocks.
 */
final class ControlledPublishingManualPageBreakService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listForVersion(int $bookVersionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT pb.id, pb.book_version_id, pb.before_block_anchor,
                    pb.created_by_user_id, pb.created_at, pb.updated_at,
                    b.block_type, b.sort_order, s.id AS section_id,
                    s.title AS section_title, s.stable_anchor AS section_anchor
               FROM ipca_publishing_manual_page_breaks pb
               LEFT JOIN ipca_publishing_book_blocks b
                 ON b.stable_anchor = pb.before_block_anchor
               LEFT JOIN ipca_publishing_book_sections s
                 ON s.id = b.section_id AND s.book_version_id = pb.book_version_id
              WHERE pb.book_version_id = ?
              ORDER BY s.sort_order ASC, b.sort_order ASC, pb.id ASC'
        );
        $stmt->execute(array($bookVersionId));

        return array_map(static function (array $row): array {
            return array(
                'id' => (int)$row['id'],
                'book_version_id' => (int)$row['book_version_id'],
                'before_block_anchor' => (string)$row['before_block_anchor'],
                'block_type' => $row['block_type'] !== null ? (string)$row['block_type'] : null,
                'section_id' => $row['section_id'] !== null ? (int)$row['section_id'] : null,
                'section_title' => $row['section_title'] !== null ? (string)$row['section_title'] : '',
                'section_anchor' => $row['section_anchor'] !== null ? (string)$row['section_anchor'] : '',
                'is_orphaned' => $row['block_type'] === null,
                'created_by_user_id' => $row['created_by_user_id'] !== null
                    ? (int)$row['created_by_user_id'] : null,
                'created_at' => (string)$row['created_at'],
                'updated_at' => (string)$row['updated_at'],
            );
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array());
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listBlockCandidates(int $bookVersionId, ?int $sectionId = null): array
    {
        $sectionFilter = $sectionId !== null && $sectionId > 0
            ? ' AND s.id = ?'
            : '';
        $stmt = $this->pdo->prepare(
            'SELECT b.id AS block_id, b.stable_anchor, b.block_type, b.sort_order,
                    s.id AS section_id, s.title AS section_title,
                    s.stable_anchor AS section_anchor
               FROM ipca_publishing_book_blocks b
               INNER JOIN ipca_publishing_book_sections s ON s.id = b.section_id
              WHERE s.book_version_id = ?
                AND b.stable_anchor IS NOT NULL
                AND b.stable_anchor <> \'\''
              . $sectionFilter .
             '
              ORDER BY s.sort_order ASC, b.sort_order ASC, b.id ASC'
        );
        $params = array($bookVersionId);
        if ($sectionFilter !== '') {
            $params[] = $sectionId;
        }
        $stmt->execute($params);

        return array_map(static fn(array $row): array => array(
            'block_id' => (int)$row['block_id'],
            'stable_anchor' => (string)$row['stable_anchor'],
            'block_type' => (string)$row['block_type'],
            'section_id' => (int)$row['section_id'],
            'section_title' => (string)$row['section_title'],
            'section_anchor' => (string)$row['section_anchor'],
        ), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array());
    }

    /**
     * @return array<string,mixed>
     */
    public function insertBefore(
        int $bookVersionId,
        string $beforeBlockAnchor,
        int $createdByUserId
    ): array {
        $this->assertEditableVersion($bookVersionId);
        $anchor = trim($beforeBlockAnchor);
        if ($anchor === '') {
            throw new RuntimeException('A stable block anchor is required.');
        }
        $this->requireBlockAnchor($bookVersionId, $anchor);
        $stmt = $this->pdo->prepare(
            'INSERT INTO ipca_publishing_manual_page_breaks (
                book_version_id, before_block_anchor, created_by_user_id
             ) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE
                created_by_user_id = VALUES(created_by_user_id),
                updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute(array($bookVersionId, $anchor, $createdByUserId > 0 ? $createdByUserId : null));
        $this->invalidatePageMap($bookVersionId);

        return $this->findByAnchor($bookVersionId, $anchor);
    }

    public function remove(int $bookVersionId, int $breakId): void
    {
        $this->assertEditableVersion($bookVersionId);
        $stmt = $this->pdo->prepare(
            'DELETE FROM ipca_publishing_manual_page_breaks
              WHERE id = ? AND book_version_id = ?'
        );
        $stmt->execute(array($breakId, $bookVersionId));
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Manual page break not found.');
        }
        $this->invalidatePageMap($bookVersionId);
    }

    /**
     * @return array<string,mixed>
     */
    public function move(
        int $bookVersionId,
        int $breakId,
        string $beforeBlockAnchor,
        int $actorUserId
    ): array {
        $this->assertEditableVersion($bookVersionId);
        $anchor = trim($beforeBlockAnchor);
        $this->requireBlockAnchor($bookVersionId, $anchor);
        $stmt = $this->pdo->prepare(
            'UPDATE ipca_publishing_manual_page_breaks
                SET before_block_anchor = ?, created_by_user_id = ?, updated_at = CURRENT_TIMESTAMP
              WHERE id = ? AND book_version_id = ?'
        );
        $stmt->execute(array(
            $anchor,
            $actorUserId > 0 ? $actorUserId : null,
            $breakId,
            $bookVersionId,
        ));
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Manual page break not found or unchanged.');
        }
        $this->invalidatePageMap($bookVersionId);

        return $this->findByAnchor($bookVersionId, $anchor);
    }

    /**
     * @param list<array<string,mixed>> $sections
     * @return list<array<string,mixed>>
     */
    public function applyToSections(int $bookVersionId, array $sections): array
    {
        $anchors = array_fill_keys(
            array_map(
                static fn(array $row): string => (string)$row['before_block_anchor'],
                $this->listForVersion($bookVersionId)
            ),
            true
        );
        if ($anchors === array()) {
            return $sections;
        }
        foreach ($sections as &$section) {
            if (!is_array($section['units'] ?? null)) {
                continue;
            }
            foreach ($section['units'] as &$unit) {
                $anchor = trim((string)($unit['stable_anchor'] ?? ''));
                if ($anchor !== '' && isset($anchors[$anchor])) {
                    $unit['force_break_before'] = true;
                    $unit['manual_page_break_before'] = true;
                }
            }
            unset($unit);
        }
        unset($section);

        return $sections;
    }

    /**
     * @return array{count:int,hash:string,anchors:list<string>}
     */
    public function identity(int $bookVersionId): array
    {
        $anchors = array_map(
            static fn(array $row): string => (string)$row['before_block_anchor'],
            $this->listForVersion($bookVersionId)
        );
        sort($anchors, SORT_STRING);

        return array(
            'count' => count($anchors),
            'hash' => hash('sha256', json_encode($anchors, JSON_UNESCAPED_SLASHES)),
            'anchors' => $anchors,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function findByAnchor(int $bookVersionId, string $anchor): array
    {
        foreach ($this->listForVersion($bookVersionId) as $row) {
            if ((string)$row['before_block_anchor'] === $anchor) {
                return $row;
            }
        }
        throw new RuntimeException('Manual page break could not be loaded.');
    }

    private function requireBlockAnchor(int $bookVersionId, string $anchor): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT b.id
               FROM ipca_publishing_book_blocks b
               INNER JOIN ipca_publishing_book_sections s ON s.id = b.section_id
              WHERE s.book_version_id = ? AND b.stable_anchor = ?
              LIMIT 1'
        );
        $stmt->execute(array($bookVersionId, $anchor));
        if ($stmt->fetchColumn() === false) {
            throw new RuntimeException('The target stable block anchor does not exist in this revision.');
        }
    }

    private function assertEditableVersion(int $bookVersionId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT bv.lifecycle_status, b.book_type
               FROM ipca_publishing_book_versions bv
               JOIN ipca_publishing_books b ON b.id = bv.book_id
              WHERE bv.id = ?
              LIMIT 1'
        );
        $stmt->execute(array($bookVersionId));
        $version = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($version)) {
            throw new RuntimeException('Manual version not found.');
        }
        $status = (string)($version['lifecycle_status'] ?? '');
        if (in_array($status, array('draft', 'in_review', 'approved'), true)) {
            return;
        }
        if ($status === 'released'
            && BooksManualsVersionEditPolicy::allowsMutation(
                $version,
                BooksManualsVersionEditPolicy::ANNEX_PAGINATION
            )) {
            return;
        }
        throw new RuntimeException('Released pagination is immutable. Create or reopen a draft revision.');
    }

    private function invalidatePageMap(int $bookVersionId): void
    {
        (new ControlledPublishingReaderPageMapStore($this->pdo))->invalidate(
            $bookVersionId,
            ControlledPublishingReaderLayoutProfile::profileKey()
        );
    }
}
