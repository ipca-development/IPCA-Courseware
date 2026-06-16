<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingReaderLayoutProfile.php';

/**
 * Persistence and approval workflow for frozen reader page maps.
 */
final class ControlledPublishingReaderPageMapStore
{
    private const META_KEY = 'reader_page_map';

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<string,mixed>|null
     */
    public function approvalMeta(int $bookVersionId): ?array
    {
        $meta = $this->loadVersionMetadata($bookVersionId);
        $map = $meta[self::META_KEY] ?? null;

        return is_array($map) ? $map : null;
    }

    public function isApproved(int $bookVersionId, ?string $layoutProfile = null): bool
    {
        $approval = $this->approvalMeta($bookVersionId);
        if ($approval === null || (string)($approval['status'] ?? '') !== 'approved') {
            return false;
        }
        if ($layoutProfile !== null && (string)($approval['layout_profile'] ?? '') !== $layoutProfile) {
            return false;
        }

        return $this->pageCount($bookVersionId, (string)($approval['layout_profile'] ?? '')) > 0;
    }

    public function pageCount(int $bookVersionId, string $layoutProfile): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ipca_publishing_reader_page_maps
              WHERE book_version_id = ? AND layout_profile = ?'
        );
        $stmt->execute(array($bookVersionId, $layoutProfile));

        return (int)$stmt->fetchColumn();
    }

    /**
     * @param list<array<string,mixed>> $pages
     */
    public function replaceDraftPages(
        int $bookVersionId,
        string $layoutProfile,
        string $layoutHash,
        array $pages,
        int $generatedByUserId
    ): int {
        $this->pdo->beginTransaction();
        try {
            $this->deletePages($bookVersionId, $layoutProfile);

            $insert = $this->pdo->prepare(
                'INSERT INTO ipca_publishing_reader_page_maps (
                    book_version_id, layout_profile, layout_hash, page_number,
                    section_id, stable_anchor, page_type,
                    is_cover, is_section_start, is_major_section_start,
                    page_html, thumbnail_html, metadata_json, generated_by_user_id
                 ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );

            foreach ($pages as $page) {
                $meta = $page['metadata'] ?? array();
                if (!is_array($meta)) {
                    $meta = array();
                }
                $insert->execute(array(
                    $bookVersionId,
                    $layoutProfile,
                    $layoutHash,
                    (int)($page['page_number'] ?? 0),
                    isset($page['section_id']) ? (int)$page['section_id'] : null,
                    isset($page['stable_anchor']) ? (string)$page['stable_anchor'] : null,
                    (string)($page['page_type'] ?? 'content'),
                    !empty($page['is_cover']) ? 1 : 0,
                    !empty($page['is_section_start']) ? 1 : 0,
                    !empty($page['is_major_section_start']) ? 1 : 0,
                    (string)($page['page_html'] ?? ''),
                    isset($page['thumbnail_html']) ? (string)$page['thumbnail_html'] : null,
                    json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $generatedByUserId > 0 ? $generatedByUserId : null,
                ));
            }

            $this->setDraftMeta($bookVersionId, $layoutProfile, $layoutHash, count($pages), $generatedByUserId);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return count($pages);
    }

    public function approve(int $bookVersionId, int $approvedByUserId, ?string $layoutProfile = null): array
    {
        $layoutProfile = $layoutProfile ?? ControlledPublishingReaderLayoutProfile::profileKey();
        $count = $this->pageCount($bookVersionId, $layoutProfile);
        if ($count <= 0) {
            throw new RuntimeException('No page map rows to approve. Run generate first.');
        }

        $draft = $this->approvalMeta($bookVersionId);
        if ($draft === null || (string)($draft['status'] ?? '') !== 'draft') {
            throw new RuntimeException('Page map is not in draft status.');
        }
        if ((string)($draft['layout_profile'] ?? '') !== $layoutProfile) {
            throw new RuntimeException('Layout profile mismatch.');
        }

        $expectedHash = ControlledPublishingReaderLayoutProfile::layoutHash();
        if ((string)($draft['layout_hash'] ?? '') !== $expectedHash) {
            throw new RuntimeException('Layout hash mismatch — regenerate before approval.');
        }

        $now = gmdate('Y-m-d H:i:s');
        $meta = $this->loadVersionMetadata($bookVersionId);
        $meta[self::META_KEY] = array(
            'status' => 'approved',
            'layout_profile' => $layoutProfile,
            'layout_hash' => $expectedHash,
            'page_count' => $count,
            'generated_at' => (string)($draft['generated_at'] ?? $now),
            'generated_by_user_id' => (int)($draft['generated_by_user_id'] ?? 0),
            'approved_at' => $now,
            'approved_by_user_id' => $approvedByUserId,
        );
        $this->saveVersionMetadata($bookVersionId, $meta);

        return $meta[self::META_KEY];
    }

    public function invalidate(int $bookVersionId, ?string $layoutProfile = null): void
    {
        $layoutProfile = $layoutProfile ?? ControlledPublishingReaderLayoutProfile::profileKey();
        $this->pdo->beginTransaction();
        try {
            $this->deletePages($bookVersionId, $layoutProfile);
            $meta = $this->loadVersionMetadata($bookVersionId);
            unset($meta[self::META_KEY]);
            $this->saveVersionMetadata($bookVersionId, $meta);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function loadPageMapSummary(int $bookVersionId, string $layoutProfile): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT page_number, section_id, stable_anchor, page_type,
                    is_cover, is_section_start, is_major_section_start,
                    thumbnail_html, metadata_json
               FROM ipca_publishing_reader_page_maps
              WHERE book_version_id = ? AND layout_profile = ?
              ORDER BY page_number ASC'
        );
        $stmt->execute(array($bookVersionId, $layoutProfile));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $pages = array();
        foreach ($rows as $row) {
            $meta = json_decode((string)($row['metadata_json'] ?? '{}'), true);
            $pages[] = array(
                'page_number' => (int)$row['page_number'],
                'section_id' => $row['section_id'] !== null ? (int)$row['section_id'] : null,
                'stable_anchor' => $row['stable_anchor'],
                'page_type' => (string)$row['page_type'],
                'is_cover' => (bool)$row['is_cover'],
                'is_section_start' => (bool)$row['is_section_start'],
                'is_major_section_start' => (bool)$row['is_major_section_start'],
                'section_title' => is_array($meta) ? (string)($meta['section_title'] ?? '') : '',
                'thumbnail_html' => $row['thumbnail_html'],
            );
        }

        $approval = $this->approvalMeta($bookVersionId);

        return array(
            'layout_profile' => $layoutProfile,
            'layout_hash' => ControlledPublishingReaderLayoutProfile::layoutHash(),
            'layout' => ControlledPublishingReaderLayoutProfile::spec(),
            'page_count' => count($pages),
            'approval' => $approval,
            'pages' => $pages,
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function loadPage(int $bookVersionId, string $layoutProfile, int $pageNumber): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT page_number, section_id, stable_anchor, page_type,
                    is_cover, is_section_start, is_major_section_start,
                    page_html, thumbnail_html, metadata_json
               FROM ipca_publishing_reader_page_maps
              WHERE book_version_id = ? AND layout_profile = ? AND page_number = ?
              LIMIT 1'
        );
        $stmt->execute(array($bookVersionId, $layoutProfile, $pageNumber));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $meta = json_decode((string)($row['metadata_json'] ?? '{}'), true);

        return array(
            'page_number' => (int)$row['page_number'],
            'section_id' => $row['section_id'] !== null ? (int)$row['section_id'] : null,
            'stable_anchor' => $row['stable_anchor'],
            'page_type' => (string)$row['page_type'],
            'is_cover' => (bool)$row['is_cover'],
            'is_section_start' => (bool)$row['is_section_start'],
            'is_major_section_start' => (bool)$row['is_major_section_start'],
            'page_html' => (string)$row['page_html'],
            'thumbnail_html' => $row['thumbnail_html'],
            'section_title' => is_array($meta) ? (string)($meta['section_title'] ?? '') : '',
            'page_count' => $this->pageCount($bookVersionId, $layoutProfile),
        );
    }

    /**
     * @return array<int,int> section_id => first page number
     */
    public function sectionPageIndex(int $bookVersionId, string $layoutProfile): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT section_id, MIN(page_number) AS first_page
               FROM ipca_publishing_reader_page_maps
              WHERE book_version_id = ? AND layout_profile = ? AND section_id IS NOT NULL
              GROUP BY section_id'
        );
        $stmt->execute(array($bookVersionId, $layoutProfile));
        $map = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(int)$row['section_id']] = (int)$row['first_page'];
        }

        return $map;
    }

    private function deletePages(int $bookVersionId, string $layoutProfile): void
    {
        $this->pdo->prepare(
            'DELETE FROM ipca_publishing_reader_page_maps
              WHERE book_version_id = ? AND layout_profile = ?'
        )->execute(array($bookVersionId, $layoutProfile));
    }

    private function setDraftMeta(
        int $bookVersionId,
        string $layoutProfile,
        string $layoutHash,
        int $pageCount,
        int $generatedByUserId
    ): void {
        $meta = $this->loadVersionMetadata($bookVersionId);
        $meta[self::META_KEY] = array(
            'status' => 'draft',
            'layout_profile' => $layoutProfile,
            'layout_hash' => $layoutHash,
            'page_count' => $pageCount,
            'generated_at' => gmdate('Y-m-d H:i:s'),
            'generated_by_user_id' => $generatedByUserId,
        );
        $this->saveVersionMetadata($bookVersionId, $meta);
    }

    /**
     * @return array<string,mixed>
     */
    private function loadVersionMetadata(int $bookVersionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT metadata_json FROM ipca_publishing_book_versions WHERE id = ? LIMIT 1'
        );
        $stmt->execute(array($bookVersionId));
        $raw = $stmt->fetchColumn();
        if ($raw === false || $raw === null) {
            return array();
        }
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string)$raw, true);

        return is_array($decoded) ? $decoded : array();
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function saveVersionMetadata(int $bookVersionId, array $meta): void
    {
        $this->pdo->prepare(
            'UPDATE ipca_publishing_book_versions SET metadata_json = ? WHERE id = ?'
        )->execute(array(
            json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $bookVersionId,
        ));
    }
}
