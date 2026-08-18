<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingFoundationService.php';
require_once __DIR__ . '/ControlledPublishingBookRenderer.php';
require_once __DIR__ . '/ControlledPublishingAnnexService.php';
require_once __DIR__ . '/ControlledPublishingBlockService.php';
require_once __DIR__ . '/ControlledPublishingSectionService.php';
require_once __DIR__ . '/BooksManualsAnnexBookService.php';
require_once __DIR__ . '/BooksManualsWorkflowService.php';

final class BooksManualsAnnexMigrationService
{
    public function __construct(
        private PDO $pdo,
        private ?ControlledPublishingFoundationService $foundation = null
    ) {
        $this->foundation ??= new ControlledPublishingFoundationService($pdo);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function inventory(): array
    {
        $stmt = $this->pdo->query(
            "SELECT
               b.id AS parent_book_id,
               b.book_key AS parent_book_key,
               b.title AS parent_book_title,
               bv.id AS source_version_id,
               bv.version_label AS source_version_label,
               s.id AS source_section_id,
               s.section_key,
               s.stable_anchor,
               s.title,
               s.metadata_json,
               s.updated_by,
               s.updated_at,
               link.id AS existing_link_id,
               link.annex_book_id,
               link.migration_status,
               annex_book.book_key AS annex_book_key,
               annex_version.id AS annex_version_id
             FROM ipca_publishing_books b
             INNER JOIN ipca_publishing_book_versions bv
               ON bv.id = (
                 SELECT MAX(latest.id)
                 FROM ipca_publishing_book_versions latest
                 WHERE latest.book_id = b.id
               )
             INNER JOIN ipca_publishing_book_sections annex_parent
               ON annex_parent.book_version_id = bv.id
              AND annex_parent.section_key = 'annexes'
             INNER JOIN ipca_publishing_book_sections s
               ON s.book_version_id = bv.id
              AND s.parent_section_id = annex_parent.id
              AND s.section_key LIKE 'annexes_annex_%'
             LEFT JOIN ipca_publishing_annex_book_links link
               ON link.legacy_book_version_id = bv.id
              AND link.legacy_section_id = s.id
             LEFT JOIN ipca_publishing_books annex_book ON annex_book.id = link.annex_book_id
             LEFT JOIN ipca_publishing_book_versions annex_version
               ON annex_version.id = (
                 SELECT MAX(av.id)
                 FROM ipca_publishing_book_versions av
                 WHERE av.book_id = annex_book.id
               )
             WHERE b.status = 'active'
             ORDER BY b.book_key, s.sort_order, s.id"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
        foreach ($rows as &$row) {
            $meta = $this->decodeJson($row['metadata_json'] ?? null);
            $annexMeta = is_array($meta['annex'] ?? null) ? $meta['annex'] : array();
            $row['annex_key'] = substr((string)$row['section_key'], strlen('annexes_annex_'));
            $row['revision'] = trim((string)($annexMeta['revision'] ?? '1.0')) ?: '1.0';
            $row['revision_date'] = trim((string)($annexMeta['revision_date'] ?? ''));
            $row['updated_by_user_id'] = (int)($annexMeta['updated_by_user_id'] ?? $row['updated_by'] ?? 0);
            $row['updated_by_name'] = (string)($annexMeta['updated_by_name'] ?? '');
            $row['section_metadata'] = $meta;
            $row['target_book_key'] = BooksManualsAnnexBookService::annexBookKey(
                (string)$row['parent_book_key']
            );
            $row['source_content_hash'] = $this->sectionContentHash(
                (int)$row['source_section_id']
            );
            $annexBook = (new BooksManualsAnnexBookService($this->pdo, $this->foundation))
                ->mapForParent((int)$row['parent_book_id']);
            $row['annex_book_map_id'] = $annexBook !== null ? (int)$annexBook['id'] : 0;
            $row['mapped_annex_book_id'] = $annexBook !== null ? (int)$annexBook['annex_book_id'] : 0;
            $alreadyInAnnexBook = $annexBook !== null
                && $this->targetHasSectionKey(
                    (int)$annexBook['annex_book_id'],
                    (string)$row['section_key']
                );
            $row['status'] = $alreadyInAnnexBook ? 'already_migrated' : 'planned';
            unset($row['metadata_json']);
        }
        unset($row);
        return $rows;
    }

    /**
     * @return array<string,mixed>
     */
    public function dryRunReport(): array
    {
        $items = $this->inventory();
        $planned = 0;
        $existing = 0;
        foreach ($items as &$item) {
            $item['validation'] = array(
                'source_has_content' => $this->blockCount((int)$item['source_section_id']) > 0,
                'source_content_hash' => (string)$item['source_content_hash'],
                'target_book_key_available' => $item['status'] === 'already_migrated'
                    || !$this->bookKeyExists((string)$item['target_book_key']),
            );
            $item['rollback'] = array(
                'legacy_section_preserved' => true,
                'annex_book_key' => (string)($item['annex_book_key'] ?? $item['target_book_key']),
                'link_source' => array(
                    'legacy_book_version_id' => (int)$item['source_version_id'],
                    'legacy_section_id' => (int)$item['source_section_id'],
                ),
            );
            if ($item['status'] === 'already_migrated') {
                $existing++;
            } else {
                $planned++;
            }
        }
        unset($item);
        $report = array(
            'schema_version' => 1,
            'mode' => 'dry-run',
            'generated_at' => gmdate('c'),
            'legacy_content_deleted' => false,
            'summary' => array(
                'inventory_count' => count($items),
                'planned_count' => $planned,
                'already_migrated_count' => $existing,
            ),
            'items' => $items,
        );
        $report['manifest_hash'] = $this->manifestHash($report);
        return $report;
    }

    /**
     * @param array<string,mixed> $approvedReport
     * @return array<string,mixed>
     */
    public function applyApprovedReport(array $approvedReport, int $actorUserId): array
    {
        if (($approvedReport['mode'] ?? '') !== 'dry-run') {
            throw new RuntimeException('Only a reviewed dry-run report can be applied.');
        }
        $providedHash = (string)($approvedReport['manifest_hash'] ?? '');
        $copy = $approvedReport;
        unset($copy['manifest_hash']);
        if ($providedHash === '' || !hash_equals($providedHash, $this->manifestHash($copy))) {
            throw new RuntimeException('The approved migration manifest hash is invalid.');
        }

        $current = $this->dryRunReport();
        $approvedSources = array();
        foreach ((array)($approvedReport['items'] ?? array()) as $item) {
            if (is_array($item)) {
                $approvedSources[$this->sourceKey($item)] = (string)($item['source_content_hash'] ?? '');
            }
        }
        foreach ((array)$current['items'] as $item) {
            $sourceKey = $this->sourceKey($item);
            if (!isset($approvedSources[$sourceKey])
                || !hash_equals($approvedSources[$sourceKey], (string)$item['source_content_hash'])) {
                throw new RuntimeException(
                    'Embedded annex inventory changed after review; create a new dry-run report.'
                );
            }
        }

        $results = array();
        foreach ((array)$current['items'] as $item) {
            $results[] = $this->migrateOne($item, $actorUserId);
        }
        return array(
            'schema_version' => 1,
            'mode' => 'apply',
            'applied_at' => gmdate('c'),
            'approved_manifest_hash' => $providedHash,
            'legacy_content_deleted' => false,
            'results' => $results,
        );
    }

    /**
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private function migrateOne(array $item, int $actorUserId): array
    {
        $annexBookSvc = new BooksManualsAnnexBookService($this->pdo, $this->foundation);
        $ensured = $annexBookSvc->ensureAnnexBookForParent(
            (int)$item['parent_book_id'],
            $actorUserId
        );
        $targetVersionId = (int)$ensured['version_id'];
        $targetBookId = (int)$ensured['book_id'];
        $existingTarget = $this->targetSection($targetVersionId, (string)$item['section_key']);
        if ($existingTarget !== null) {
            return array(
                'status' => 'already_migrated',
                'annex_book_id' => $targetBookId,
                'annex_version_id' => $targetVersionId,
                'annex_book_key' => (string)$ensured['book_key'],
                'validation_hash' => '',
                'rollback' => array(
                    'deactivate_annex_book_id' => $targetBookId,
                    'restore_embedded_section_id' => (int)$item['source_section_id'],
                    'legacy_section_preserved' => true,
                ),
            );
        }

        $sourceSection = $this->sourceSectionRow((int)$item['source_section_id']);
        if ($sourceSection === null) {
            throw new RuntimeException('Embedded annex section could not be resolved.');
        }

        $this->pdo->beginTransaction();
        try {
            $targetSectionId = $this->copySectionShell(
                $sourceSection,
                $targetVersionId,
                (string)$ensured['book_key'],
                (string)$ensured['version_label'],
                $actorUserId,
                $item
            );
            $sourceBlocks = $this->blocks((int)$item['source_section_id']);
            $this->copyBlocks(
                $sourceBlocks,
                $targetVersionId,
                $targetSectionId,
                (string)$item['parent_book_key'],
                (string)$item['source_version_label'],
                (string)$ensured['book_key'],
                (string)$ensured['version_label'],
                $actorUserId
            );

            $validation = $this->validateRenderedParity(
                $sourceBlocks,
                $this->blocks($targetSectionId),
                (string)$item['parent_book_key'],
                (string)$item['source_version_label'],
                (string)$ensured['book_key'],
                (string)$ensured['version_label']
            );
            if (!$validation['ok']) {
                throw new RuntimeException('Annex Book rendered-content validation failed.');
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $annexSvc = new ControlledPublishingAnnexService(
            $this->pdo,
            $this->foundation,
            new ControlledPublishingSectionService($this->pdo),
            new ControlledPublishingBlockService($this->pdo)
        );
        $annexSvc->recordAnnexRevision(
            $targetVersionId,
            $targetSectionId,
            'migrate',
            $actorUserId,
            false,
            'Imported from embedded annex'
        );
        $annexSvc->regenerateRegister($targetVersionId, $actorUserId);

        return array(
            'status' => 'validated',
            'annex_book_id' => $targetBookId,
            'annex_version_id' => $targetVersionId,
            'annex_book_key' => (string)$ensured['book_key'],
            'validation' => $validation,
            'rollback' => array(
                'deactivate_annex_book_id' => $targetBookId,
                'restore_embedded_section_id' => (int)$item['source_section_id'],
                'legacy_section_preserved' => true,
            ),
        );
    }

    /**
     * @param array<string,mixed> $item
     * @return array{book_id:int,version_id:int,book_key:string,version_label:string}
     */
    private function resolveOrCreateTarget(array $item, int $actorUserId): array
    {
        $bookKey = (string)$item['target_book_key'];
        $stmt = $this->pdo->prepare(
            'SELECT b.id AS book_id, b.book_key, bv.id AS version_id, bv.version_label
             FROM ipca_publishing_books b
             INNER JOIN ipca_publishing_book_versions bv
               ON bv.id = (SELECT MAX(v2.id) FROM ipca_publishing_book_versions v2 WHERE v2.book_id = b.id)
             WHERE b.book_key = ? LIMIT 1'
        );
        $stmt->execute(array($bookKey));
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($existing)) {
            return array(
                'book_id' => (int)$existing['book_id'],
                'version_id' => (int)$existing['version_id'],
                'book_key' => (string)$existing['book_key'],
                'version_label' => (string)$existing['version_label'],
            );
        }
        $created = $this->foundation->createManualFromVersion(
            (int)$item['source_version_id'],
            $bookKey,
            $this->cleanAnnexTitle((string)$item['title']),
            (string)$item['revision'],
            false,
            $actorUserId
        );
        return array(
            'book_id' => (int)$created['book_id'],
            'version_id' => (int)$created['version_id'],
            'book_key' => (string)$created['book_key'],
            'version_label' => (string)$created['version_label'],
        );
    }

    /**
     * @param list<array<string,mixed>> $sourceBlocks
     */
    private function copyBlocks(
        array $sourceBlocks,
        int $targetVersionId,
        int $targetSectionId,
        string $sourceBookKey,
        string $sourceVersion,
        string $targetBookKey,
        string $targetVersion,
        int $actorUserId
    ): void {
        $insert = $this->pdo->prepare(
            'INSERT INTO ipca_publishing_book_blocks
              (book_version_id, section_id, block_key, stable_anchor, block_type,
               sort_order, payload_json, content_hash, is_system_managed, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($sourceBlocks as $index => $block) {
            $payload = $this->remapPayload(
                $this->decodeJson($block['payload_json'] ?? null),
                $sourceBookKey,
                $sourceVersion,
                $targetBookKey,
                $targetVersion
            );
            $payloadJson = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
            $anchor = $this->remapString(
                (string)$block['stable_anchor'],
                $sourceBookKey,
                $sourceVersion,
                $targetBookKey,
                $targetVersion
            );
            $insert->execute(array(
                $targetVersionId,
                $targetSectionId,
                'annex-block-' . str_pad((string)($index + 1), 4, '0', STR_PAD_LEFT),
                $anchor,
                (string)$block['block_type'],
                (int)$block['sort_order'],
                $payloadJson,
                hash('sha256', $payloadJson),
                (int)$block['is_system_managed'],
                $actorUserId,
                $actorUserId,
            ));
        }
    }

    /**
     * @param list<array<string,mixed>> $sourceBlocks
     * @param list<array<string,mixed>> $targetBlocks
     * @return array{ok:bool,source_render_hash:string,target_render_hash:string}
     */
    private function validateRenderedParity(
        array $sourceBlocks,
        array $targetBlocks,
        string $sourceBookKey,
        string $sourceVersion,
        string $targetBookKey,
        string $targetVersion
    ): array {
        $renderer = new ControlledPublishingBookRenderer();
        $sourceHtml = $renderer->renderBlocks($sourceBlocks, ControlledPublishingBookRenderer::MODE_READ);
        $sourceHtml = $this->remapString(
            $sourceHtml,
            $sourceBookKey,
            $sourceVersion,
            $targetBookKey,
            $targetVersion
        );
        $targetHtml = $renderer->renderBlocks($targetBlocks, ControlledPublishingBookRenderer::MODE_READ);
        $sourceHash = hash('sha256', $this->normalizeRenderedHtml($sourceHtml));
        $targetHash = hash('sha256', $this->normalizeRenderedHtml($targetHtml));
        return array(
            'ok' => hash_equals($sourceHash, $targetHash),
            'source_render_hash' => $sourceHash,
            'target_render_hash' => $targetHash,
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function blocks(int $sectionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ipca_publishing_book_blocks
             WHERE section_id = ? ORDER BY sort_order, id'
        );
        $stmt->execute(array($sectionId));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    private function blockCount(int $sectionId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ipca_publishing_book_blocks WHERE section_id = ?'
        );
        $stmt->execute(array($sectionId));
        return (int)$stmt->fetchColumn();
    }

    private function sectionContentHash(int $sectionId): string
    {
        $rows = array();
        foreach ($this->blocks($sectionId) as $block) {
            $rows[] = array(
                'type' => (string)$block['block_type'],
                'sort' => (int)$block['sort_order'],
                'payload' => $this->decodeJson($block['payload_json'] ?? null),
            );
        }
        return hash('sha256', json_encode(
            $rows,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    /**
     * @return array<string,mixed>|null
     */
    private function targetSection(int $versionId, string $sectionKey): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ipca_publishing_book_sections
             WHERE book_version_id = ? AND section_key = ? LIMIT 1'
        );
        $stmt->execute(array($versionId, $sectionKey));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function targetHasSectionKey(int $annexBookId, string $sectionKey): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.id
             FROM ipca_publishing_book_versions bv
             INNER JOIN ipca_publishing_book_sections s
               ON s.book_version_id = bv.id AND s.section_key = ?
             WHERE bv.book_id = ?
             ORDER BY bv.id DESC LIMIT 1'
        );
        $stmt->execute(array($sectionKey, $annexBookId));
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function sourceSectionRow(int $sectionId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ipca_publishing_book_sections WHERE id = ? LIMIT 1'
        );
        $stmt->execute(array($sectionId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $sourceSection
     * @param array<string,mixed> $item
     */
    private function copySectionShell(
        array $sourceSection,
        int $targetVersionId,
        string $targetBookKey,
        string $targetVersionLabel,
        int $actorUserId,
        array $item
    ): int {
        $parent = $this->targetSection(
            $targetVersionId,
            ControlledPublishingAnnexService::PARENT_SECTION_KEY
        );
        if ($parent === null) {
            throw new RuntimeException('Annex Book is missing the Annexes parent section.');
        }

        $targetMetadata = is_array($item['section_metadata'] ?? null)
            ? $item['section_metadata']
            : $this->decodeJson($sourceSection['metadata_json'] ?? null);
        if (!is_array($targetMetadata['annex'] ?? null)) {
            $targetMetadata['annex'] = array();
        }
        $targetMetadata['annex']['canonical_excerpt_id'] = null;
        $targetMetadata['annex_book_origin'] = array(
            'parent_book_id' => (int)$item['parent_book_id'],
            'legacy_book_version_id' => (int)$item['source_version_id'],
            'legacy_section_id' => (int)$item['source_section_id'],
            'migrated_at' => gmdate('c'),
        );

        $sectionKey = (string)$sourceSection['section_key'];
        $anchor = strtoupper($targetBookKey) . '-'
            . str_replace('.', '_', $targetVersionLabel) . '-'
            . strtoupper(str_replace('_', '-', $sectionKey));
        $this->pdo->prepare(
            'INSERT INTO ipca_publishing_book_sections
              (book_version_id, parent_section_id, section_key, stable_anchor, title,
               section_type, metadata_json, is_system_managed, is_generated, sort_order,
               created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute(array(
            $targetVersionId,
            (int)$parent['id'],
            $sectionKey,
            $anchor,
            (string)$item['title'],
            (string)($sourceSection['section_type'] ?? 'annex_content'),
            json_encode(
                $targetMetadata,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
            (int)($sourceSection['is_system_managed'] ?? 0),
            (int)($sourceSection['is_generated'] ?? 0),
            (int)($sourceSection['sort_order'] ?? 100),
            $actorUserId,
            $actorUserId,
        ));
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @return array<string,mixed>|null
     */
    private function existingLink(int $sourceVersionId, int $sourceSectionId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ipca_publishing_annex_book_links
             WHERE legacy_book_version_id = ? AND legacy_section_id = ? LIMIT 1'
        );
        $stmt->execute(array($sourceVersionId, $sourceSectionId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private function rollbackMapping(array $existing, array $item): array
    {
        return array(
            'deactivate_annex_book_id' => (int)$existing['annex_book_id'],
            'restore_embedded_section_id' => (int)$item['source_section_id'],
            'legacy_section_preserved' => true,
        );
    }

    /**
     * @param array<string,mixed> $item
     */
    private function sourceKey(array $item): string
    {
        return (int)($item['source_version_id'] ?? 0) . ':'
            . (int)($item['source_section_id'] ?? 0);
    }

    private function targetBookKey(string $parentBookKey, string $annexKey = ''): string
    {
        unset($annexKey);
        return BooksManualsAnnexBookService::annexBookKey($parentBookKey);
    }

    private function cleanAnnexTitle(string $title): string
    {
        $title = preg_replace('/^ANNEX\s+[A-Z0-9.-]+\s*[–—:-]\s*/iu', '', trim($title)) ?: trim($title);
        return $title !== '' ? $title : 'Standalone Annex';
    }

    private function bookKeyExists(string $bookKey): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM ipca_publishing_books WHERE book_key = ? LIMIT 1'
        );
        $stmt->execute(array($bookKey));
        return (bool)$stmt->fetchColumn();
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : array();
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function remapPayload(
        array $payload,
        string $sourceBookKey,
        string $sourceVersion,
        string $targetBookKey,
        string $targetVersion
    ): array {
        array_walk_recursive(
            $payload,
            function (&$value) use (
                $sourceBookKey,
                $sourceVersion,
                $targetBookKey,
                $targetVersion
            ): void {
                if (is_string($value)) {
                    $value = $this->remapString(
                        $value,
                        $sourceBookKey,
                        $sourceVersion,
                        $targetBookKey,
                        $targetVersion
                    );
                }
            }
        );
        return $payload;
    }

    private function remapString(
        string $value,
        string $sourceBookKey,
        string $sourceVersion,
        string $targetBookKey,
        string $targetVersion
    ): string {
        return str_replace(
            array(
                $sourceBookKey . '-' . $sourceVersion . '-',
                'BOOK|' . $sourceBookKey . '|',
                'book=' . rawurlencode($sourceBookKey),
                '"book_key":"' . $sourceBookKey . '"',
            ),
            array(
                $targetBookKey . '-' . $targetVersion . '-',
                'BOOK|' . $targetBookKey . '|',
                'book=' . rawurlencode($targetBookKey),
                '"book_key":"' . $targetBookKey . '"',
            ),
            $value
        );
    }

    private function normalizeRenderedHtml(string $html): string
    {
        $html = preg_replace('/\s+/', ' ', trim($html)) ?: trim($html);
        return preg_replace('/data-block-id="[^"]*"/', 'data-block-id=""', $html) ?: $html;
    }

    /**
     * @param array<string,mixed> $report
     */
    private function manifestHash(array $report): string
    {
        unset($report['manifest_hash']);
        return hash('sha256', json_encode(
            $report,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }
}
