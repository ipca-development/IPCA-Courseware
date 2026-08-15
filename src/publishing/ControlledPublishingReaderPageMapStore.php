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

    /**
     * Stable status payload for admin preview/release screens.
     *
     * @return array<string,mixed>
     */
    public function getApprovalState(int $bookVersionId, string $layoutProfile): array
    {
        $approval = $this->approvalMeta($bookVersionId) ?? array();
        $storedProfile = (string)($approval['layout_profile'] ?? $layoutProfile);
        $pageCount = $this->pageCount($bookVersionId, $storedProfile);

        return array_merge(array(
            'status' => $pageCount > 0 ? 'draft' : 'not_generated',
            'layout_profile' => $storedProfile,
            'layout_hash' => '',
            'page_count' => $pageCount,
            'generation' => array(),
        ), $approval, array(
            'page_count' => $pageCount,
        ));
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
        int $generatedByUserId,
        array $generation = array()
    ): int {
        $this->assertVersionMutable($bookVersionId);
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

            $this->setDraftMeta(
                $bookVersionId,
                $layoutProfile,
                $layoutHash,
                count($pages),
                $generatedByUserId,
                $generation
            );
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
        $this->assertVersionMutable($bookVersionId);
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
        $meta[self::META_KEY] = array_merge($draft, array(
            'status' => 'approved',
            'layout_profile' => $layoutProfile,
            'layout_hash' => $expectedHash,
            'page_count' => $count,
            'generated_at' => (string)($draft['generated_at'] ?? $now),
            'generated_by_user_id' => (int)($draft['generated_by_user_id'] ?? 0),
            'approved_at' => $now,
            'approved_by_user_id' => $approvedByUserId,
        ));
        $this->saveVersionMetadata($bookVersionId, $meta);

        return $meta[self::META_KEY];
    }

    public function invalidate(int $bookVersionId, ?string $layoutProfile = null): void
    {
        $this->assertVersionMutable($bookVersionId);
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
        $generation = is_array($approval['generation'] ?? null)
            ? $approval['generation']
            : array();

        return array(
            'layout_profile' => $layoutProfile,
            'layout_hash' => ControlledPublishingReaderLayoutProfile::layoutHash(),
            'layout' => ControlledPublishingReaderLayoutProfile::spec(),
            'page_count' => count($pages),
            'page_map_hash' => (string)($generation['page_map_hash'] ?? ''),
            'source_hash' => (string)($generation['source_hash'] ?? ''),
            'style_hash' => (string)($generation['style_hash'] ?? ''),
            'manual_page_break_hash' => (string)($generation['manual_page_break_hash'] ?? ''),
            'header_footer_hash' => (string)($generation['header_footer_hash'] ?? ''),
            'manifest_hash' => (string)($generation['manifest_hash'] ?? ''),
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
     * Admin/release view of every stored authoritative page, including HTML.
     *
     * @return list<array<string,mixed>>
     */
    public function loadStoredPages(
        int $bookVersionId,
        string $layoutProfile,
        ?int $sectionId = null
    ): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT page_number, section_id, stable_anchor, page_type,
                    is_cover, is_section_start, is_major_section_start,
                    page_html, thumbnail_html, metadata_json
               FROM ipca_publishing_reader_page_maps
              WHERE book_version_id = ? AND layout_profile = ?
              ORDER BY page_number ASC'
        );
        $stmt->execute(array($bookVersionId, $layoutProfile));

        $pages = array_map(static function (array $row): array {
            $metadata = json_decode((string)($row['metadata_json'] ?? '{}'), true);
            if (!is_array($metadata)) {
                $metadata = array();
            }

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
                'section_title' => (string)($metadata['section_title'] ?? ''),
                'metadata' => $metadata,
            );
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array());
        if ($sectionId === null || $sectionId <= 0) {
            return $pages;
        }

        return array_values(array_filter(
            $pages,
            static fn(array $page): bool => self::pageContainsSection($page, $sectionId)
        ));
    }

    /**
     * @return array<int,int> section_id => first page number
     */
    public function sectionPageIndex(int $bookVersionId, string $layoutProfile): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT page_number, section_id, metadata_json
               FROM ipca_publishing_reader_page_maps
              WHERE book_version_id = ? AND layout_profile = ?
              ORDER BY page_number ASC'
        );
        $stmt->execute(array($bookVersionId, $layoutProfile));
        $map = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $metadata = json_decode((string)($row['metadata_json'] ?? '{}'), true);
            $sectionIds = self::coverageSectionIds(is_array($metadata) ? $metadata : array());
            if ($row['section_id'] !== null) {
                $sectionIds[] = (int)$row['section_id'];
            }
            foreach (array_unique($sectionIds) as $sectionId) {
                if ($sectionId > 0 && !isset($map[$sectionId])) {
                    $map[$sectionId] = (int)$row['page_number'];
                }
            }
        }

        return $map;
    }

    /**
     * @param array<string,mixed> $page
     */
    private static function pageContainsSection(array $page, int $sectionId): bool
    {
        if ((int)($page['section_id'] ?? 0) === $sectionId) {
            return true;
        }
        $metadata = is_array($page['metadata'] ?? null) ? $page['metadata'] : array();
        return in_array($sectionId, self::coverageSectionIds($metadata), true);
    }

    /**
     * @param array<string,mixed> $metadata
     * @return list<int>
     */
    private static function coverageSectionIds(array $metadata): array
    {
        $sectionIds = array();
        $coverage = is_array($metadata['coverage'] ?? null) ? $metadata['coverage'] : array();
        foreach ($coverage as $entry) {
            if (!is_array($entry) || !empty($entry['presentation_copy'])) {
                continue;
            }
            $sectionId = (int)($entry['section_id'] ?? 0);
            if ($sectionId > 0) {
                $sectionIds[] = $sectionId;
            }
        }
        return $sectionIds;
    }

    /**
     * Writes a candidate generation without changing the last valid production map.
     *
     * @param list<array<string,mixed>> $pages
     */
    public function replaceStagingPages(
        int $bookVersionId,
        string $layoutProfile,
        int $generationSeq,
        string $leaseToken,
        string $layoutHash,
        array $pages,
        int $generatedByUserId
    ): int {
        $this->assertVersionMutable($bookVersionId);
        $this->pdo->beginTransaction();
        try {
            $this->deleteStagingPages($bookVersionId, $layoutProfile, $generationSeq, $leaseToken);
            $insert = $this->pdo->prepare(
                'INSERT INTO ipca_publishing_reader_page_map_staging (
                    book_version_id, layout_profile, generation_seq, lease_token,
                    layout_hash, page_number,
                    section_id, stable_anchor, page_type, is_cover, is_section_start,
                    is_major_section_start, page_html, thumbnail_html, metadata_json,
                    generated_by_user_id
                 ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            foreach ($pages as $page) {
                $metadata = is_array($page['metadata'] ?? null) ? $page['metadata'] : array();
                $insert->execute(array(
                    $bookVersionId,
                    $layoutProfile,
                    $generationSeq,
                    $leaseToken,
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
                    json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $generatedByUserId > 0 ? $generatedByUserId : null,
                ));
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return count($pages);
    }

    /**
     * Promotes staging only while generation sequence, lease, and the complete
     * freshly re-read authoritative fingerprint still match.
     *
     * @param array<string,mixed> $generation
     * @param callable():array<string,mixed> $currentFingerprintLoader
     */
    public function promoteStagingPagesCas(
        int $bookVersionId,
        string $layoutProfile,
        int $generationSeq,
        string $leaseToken,
        string $layoutHash,
        int $generatedByUserId,
        array $generation,
        callable $currentFingerprintLoader
    ): bool {
        $this->assertVersionMutable($bookVersionId);
        $this->pdo->beginTransaction();
        try {
            $lockSuffix = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
                ? ' FOR UPDATE'
                : '';
            $stateStmt = $this->pdo->prepare(
                'SELECT generation_seq, status, requested_fingerprint_hash,
                        requested_fingerprint_json, lease_token,
                        pending_generation_seq, pending_fingerprint_hash,
                        pending_fingerprint_json, pending_requested_by_user_id
                   FROM ipca_publishing_page_map_generation_state
                  WHERE book_version_id = ? AND layout_profile = ?' . $lockSuffix
            );
            $stateStmt->execute(array($bookVersionId, $layoutProfile));
            $state = $stateStmt->fetch(PDO::FETCH_ASSOC);
            if (
                !is_array($state)
                || (int)$state['generation_seq'] !== $generationSeq
                || (string)$state['status'] !== 'generating'
                || !hash_equals((string)($state['lease_token'] ?? ''), $leaseToken)
            ) {
                $this->pdo->rollBack();
                return false;
            }

            $requested = json_decode((string)($state['requested_fingerprint_json'] ?? '{}'), true);
            $current = $currentFingerprintLoader();
            $currentJson = self::canonicalJson(is_array($current) ? $current : array());
            $currentHash = hash('sha256', $currentJson);
            $requestedJson = self::canonicalJson(is_array($requested) ? $requested : array());
            $fingerprintMatches = hash_equals(
                (string)$state['requested_fingerprint_hash'],
                $currentHash
            ) && hash_equals($requestedJson, $currentJson);

            if (!$fingerprintMatches) {
                $this->pdo->prepare(
                    "UPDATE ipca_publishing_page_map_generation_state
                        SET status = CASE
                                WHEN pending_generation_seq IS NULL THEN 'stale'
                                ELSE 'pending'
                            END,
                            generation_seq = COALESCE(pending_generation_seq, generation_seq),
                            requested_fingerprint_hash = COALESCE(
                                pending_fingerprint_hash, requested_fingerprint_hash
                            ),
                            requested_fingerprint_json = COALESCE(
                                pending_fingerprint_json, requested_fingerprint_json
                            ),
                            requested_by_user_id = COALESCE(
                                pending_requested_by_user_id, requested_by_user_id
                            ),
                            last_error_code = CASE
                                WHEN pending_generation_seq IS NULL THEN 'STALE_COMPLETION'
                                ELSE NULL
                            END,
                            last_error_message = CASE
                                WHEN pending_generation_seq IS NULL
                                THEN 'Authoritative fingerprint changed before promotion.'
                                ELSE NULL
                            END,
                            lease_token = NULL, lease_expires_at = NULL,
                            pending_generation_seq = NULL,
                            pending_fingerprint_hash = NULL,
                            pending_fingerprint_json = NULL,
                            pending_requested_by_user_id = NULL,
                            updated_at = CURRENT_TIMESTAMP
                      WHERE book_version_id = ? AND layout_profile = ?
                        AND generation_seq = ? AND lease_token = ?"
                )->execute(array($bookVersionId, $layoutProfile, $generationSeq, $leaseToken));
                $this->deleteStagingPages(
                    $bookVersionId,
                    $layoutProfile,
                    $generationSeq,
                    $leaseToken
                );
                $this->pdo->commit();
                return false;
            }

            $candidateCountStmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM ipca_publishing_reader_page_map_staging
                  WHERE book_version_id = ? AND layout_profile = ?
                    AND generation_seq = ? AND lease_token = ?'
            );
            $candidateCountStmt->execute(array(
                $bookVersionId,
                $layoutProfile,
                $generationSeq,
                $leaseToken,
            ));
            $candidateCount = (int)$candidateCountStmt->fetchColumn();
            if ($candidateCount <= 0 || $candidateCount !== (int)($generation['page_count'] ?? 0)) {
                throw new RuntimeException('Staging page map is incomplete.');
            }

            $cas = $this->pdo->prepare(
                "UPDATE ipca_publishing_page_map_generation_state
                    SET status = 'current', lease_token = NULL, lease_expires_at = NULL,
                        pending_generation_seq = NULL,
                        pending_fingerprint_hash = NULL,
                        pending_fingerprint_json = NULL,
                        pending_requested_by_user_id = NULL,
                        last_error_code = NULL, last_error_message = NULL,
                        updated_at = CURRENT_TIMESTAMP
                  WHERE book_version_id = ? AND layout_profile = ?
                    AND generation_seq = ? AND status = 'generating'
                    AND lease_token = ? AND requested_fingerprint_hash = ?"
            );
            $cas->execute(array(
                $bookVersionId,
                $layoutProfile,
                $generationSeq,
                $leaseToken,
                $currentHash,
            ));
            if ($cas->rowCount() !== 1) {
                $this->pdo->rollBack();
                return false;
            }

            $this->deletePages($bookVersionId, $layoutProfile);
            $this->pdo->prepare(
                'INSERT INTO ipca_publishing_reader_page_maps (
                    book_version_id, layout_profile, layout_hash, page_number,
                    section_id, stable_anchor, page_type, is_cover, is_section_start,
                    is_major_section_start, page_html, thumbnail_html, metadata_json,
                    generated_at, generated_by_user_id
                 )
                 SELECT book_version_id, layout_profile, layout_hash, page_number,
                        section_id, stable_anchor, page_type, is_cover, is_section_start,
                        is_major_section_start, page_html, thumbnail_html, metadata_json,
                        CURRENT_TIMESTAMP, generated_by_user_id
                   FROM ipca_publishing_reader_page_map_staging
                  WHERE book_version_id = ? AND layout_profile = ?
                    AND generation_seq = ? AND lease_token = ?
                  ORDER BY page_number'
            )->execute(array($bookVersionId, $layoutProfile, $generationSeq, $leaseToken));
            $this->setDraftMeta(
                $bookVersionId,
                $layoutProfile,
                $layoutHash,
                $candidateCount,
                $generatedByUserId,
                $generation
            );
            $this->deleteStagingPages($bookVersionId, $layoutProfile, $generationSeq, $leaseToken);
            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function deleteStagingPages(
        int $bookVersionId,
        string $layoutProfile,
        int $generationSeq,
        string $leaseToken
    ): void {
        $this->pdo->prepare(
            'DELETE FROM ipca_publishing_reader_page_map_staging
              WHERE book_version_id = ? AND layout_profile = ?
                AND generation_seq = ? AND lease_token = ?'
        )->execute(array($bookVersionId, $layoutProfile, $generationSeq, $leaseToken));
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
        int $generatedByUserId,
        array $generation
    ): void {
        $meta = $this->loadVersionMetadata($bookVersionId);
        $meta[self::META_KEY] = array(
            'status' => 'draft',
            'layout_profile' => $layoutProfile,
            'layout_hash' => $layoutHash,
            'page_count' => $pageCount,
            'generated_at' => gmdate('Y-m-d H:i:s'),
            'generated_by_user_id' => $generatedByUserId,
            'generation' => $generation,
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

    private function assertVersionMutable(int $bookVersionId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT lifecycle_status FROM ipca_publishing_book_versions WHERE id = ? LIMIT 1'
        );
        $stmt->execute(array($bookVersionId));
        $status = $stmt->fetchColumn();
        if ($status === false) {
            throw new RuntimeException('Manual version not found.');
        }
        if ((string)$status === 'released') {
            throw new RuntimeException('Released authoritative pagination is immutable.');
        }
    }

    /**
     * @param array<string,mixed> $value
     */
    private static function canonicalJson(array $value): string
    {
        $sort = static function (mixed $item) use (&$sort): mixed {
            if (!is_array($item)) {
                return $item;
            }
            if (!array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
            foreach ($item as $key => $child) {
                $item[$key] = $sort($child);
            }
            return $item;
        };

        return json_encode(
            $sort($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }
}
