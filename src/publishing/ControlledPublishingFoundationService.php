<?php
declare(strict_types=1);

/**
 * Controlled Publishing foundation: book registry, Statement of Compliance Source
 * selection, and immutable source baseline freeze.
 */
final class ControlledPublishingFoundationService
{
    /** @var array<string,array<string,array{source_set_key:string,selection_role:string}>> */
    private const REQUIRED_SOURCE_SETS = array(
        'OM' => array(
            'compliance_source' => array('source_set_key' => 'MCCF:OM:REV_6_0', 'selection_role' => 'compliance_source'),
            'manual_source' => array('source_set_key' => 'MANUAL:OM:6_0', 'selection_role' => 'manual_source'),
        ),
        'OMM' => array(
            'compliance_source' => array('source_set_key' => 'MCCF:OMM:REV_4_0', 'selection_role' => 'compliance_source'),
            'manual_source' => array('source_set_key' => 'MANUAL:OMM:4_0', 'selection_role' => 'manual_source'),
        ),
    );

    /** @var array<string,array{template_key:string,title:string,manual_family:string}> */
    private const TEMPLATE_REGISTRY = array(
        'OM' => array(
            'template_key' => 'OM_STANDARD',
            'title' => 'Operations Manual — Standard Template',
            'manual_family' => 'OM',
        ),
        'OMM' => array(
            'template_key' => 'OMM_STANDARD',
            'title' => 'Organization Management Manual — Standard Template',
            'manual_family' => 'OMM',
        ),
    );

    /**
     * Mandatory controlled-manual sections in display order.
     *
     * @var list<array{section_key:string,title:string,section_type:string,is_system_managed:bool,is_generated:bool,allow_author_blocks:bool,sort_order:int}>
     */
    private const MANDATORY_SECTIONS = array(
        array('section_key' => 'cover', 'title' => 'Cover Page', 'section_type' => 'cover', 'is_system_managed' => true, 'is_generated' => false, 'allow_author_blocks' => false, 'sort_order' => 10),
        array('section_key' => 'toc', 'title' => 'Table of Contents', 'section_type' => 'toc', 'is_system_managed' => true, 'is_generated' => true, 'allow_author_blocks' => false, 'sort_order' => 20),
        array('section_key' => 'lep', 'title' => 'List of Effective Parts + E-Signature', 'section_type' => 'lep', 'is_system_managed' => true, 'is_generated' => true, 'allow_author_blocks' => false, 'sort_order' => 30),
        array('section_key' => 'revision_system', 'title' => 'Revision System', 'section_type' => 'revision_system', 'is_system_managed' => true, 'is_generated' => false, 'allow_author_blocks' => true, 'sort_order' => 40),
        array('section_key' => 'amendment_list', 'title' => 'Amendment List', 'section_type' => 'amendment_list', 'is_system_managed' => true, 'is_generated' => false, 'allow_author_blocks' => false, 'sort_order' => 50),
        array('section_key' => 'distribution_list', 'title' => 'Distribution List', 'section_type' => 'distribution_list', 'is_system_managed' => true, 'is_generated' => false, 'allow_author_blocks' => false, 'sort_order' => 60),
        array('section_key' => 'abbreviations', 'title' => 'Index of Abbreviations', 'section_type' => 'abbreviations', 'is_system_managed' => true, 'is_generated' => true, 'allow_author_blocks' => false, 'sort_order' => 70),
        array('section_key' => 'definitions', 'title' => 'Definitions and Terms', 'section_type' => 'definitions', 'is_system_managed' => true, 'is_generated' => false, 'allow_author_blocks' => false, 'sort_order' => 80),
        array('section_key' => 'highlights', 'title' => 'Highlight of Changes', 'section_type' => 'highlights', 'is_system_managed' => true, 'is_generated' => true, 'allow_author_blocks' => true, 'sort_order' => 90),
        array('section_key' => 'part_1', 'title' => 'Part 1 – General', 'section_type' => 'content', 'is_system_managed' => false, 'is_generated' => false, 'allow_author_blocks' => true, 'sort_order' => 100),
        array('section_key' => 'part_2', 'title' => 'Part 2 – Technical', 'section_type' => 'content', 'is_system_managed' => false, 'is_generated' => false, 'allow_author_blocks' => true, 'sort_order' => 110),
        array('section_key' => 'part_3', 'title' => 'Part 3 – Route', 'section_type' => 'content', 'is_system_managed' => false, 'is_generated' => false, 'allow_author_blocks' => true, 'sort_order' => 120),
        array('section_key' => 'part_4', 'title' => 'Part 4 – Personnel Training', 'section_type' => 'content', 'is_system_managed' => false, 'is_generated' => false, 'allow_author_blocks' => true, 'sort_order' => 130),
        array('section_key' => 'annexes', 'title' => 'Annexes', 'section_type' => 'annex', 'is_system_managed' => false, 'is_generated' => false, 'allow_author_blocks' => true, 'sort_order' => 140),
    );

    /** @var array<string,array{book_key:string,title:string,manual_code:string,version_label:string}> */
    private const BOOK_REGISTRY = array(
        'OM' => array(
            'book_key' => 'OM',
            'title' => 'Operations Manual',
            'manual_code' => 'OM',
            'version_label' => '6.0',
        ),
        'OMM' => array(
            'book_key' => 'OMM',
            'title' => 'Organization Management Manual',
            'manual_code' => 'OMM',
            'version_label' => '4.0',
        ),
    );

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<string,int> book_key => book_id
     */
    public function ensureBookRegistry(?int $actorUserId = null): array
    {
        $ids = array();
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_publishing_books
                (book_key, title, book_type, manual_code, status, created_by)
            VALUES
                (:book_key, :title, 'manual', :manual_code, 'active', :created_by)
            ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                manual_code = VALUES(manual_code),
                status = 'active',
                updated_at = CURRENT_TIMESTAMP
        ");

        foreach (self::BOOK_REGISTRY as $def) {
            $stmt->execute(array(
                ':book_key' => $def['book_key'],
                ':title' => $def['title'],
                ':manual_code' => $def['manual_code'],
                ':created_by' => $actorUserId,
            ));
            $ids[$def['book_key']] = $this->bookIdByKey($def['book_key']);
        }

        return $ids;
    }

    public function createDraftVersion(int $bookId, string $versionLabel, ?int $actorUserId = null): int
    {
        $book = $this->getBook($bookId);
        if ($book === null) {
            throw new RuntimeException('Book not found.');
        }

        $title = (string)$book['title'] . ' ' . $versionLabel;
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_publishing_book_versions
                (book_id, version_label, title, lifecycle_status, created_by)
            VALUES
                (:book_id, :version_label, :title, 'draft', :created_by)
            ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute(array(
            ':book_id' => $bookId,
            ':version_label' => $versionLabel,
            ':title' => $title,
            ':created_by' => $actorUserId,
        ));

        return $this->versionIdByBookAndLabel($bookId, $versionLabel);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listActiveSourceSets(): array
    {
        $stmt = $this->pdo->query("
            SELECT
              ss.id,
              ss.source_set_key,
              ss.source_family,
              ss.authority,
              ss.title,
              ss.revision_label,
              ss.status,
              ss.source_hash,
              ss.last_synced_at
            FROM ipca_canonical_source_sets ss
            WHERE ss.status = 'active'
            ORDER BY ss.source_family, ss.source_set_key
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listBooksWithVersions(): array
    {
        $stmt = $this->pdo->query("
            SELECT
              b.id AS book_id,
              b.book_key,
              b.title AS book_title,
              b.manual_code,
              b.status AS book_status,
              bv.id AS version_id,
              bv.version_label,
              bv.lifecycle_status,
              bv.source_baseline_id,
              sb.baseline_status,
              sb.baseline_hash,
              COUNT(vss.id) AS selected_source_sets
            FROM ipca_publishing_books b
            LEFT JOIN ipca_publishing_book_versions bv ON bv.book_id = b.id
            LEFT JOIN ipca_publishing_source_baselines sb ON sb.id = bv.source_baseline_id
            LEFT JOIN ipca_publishing_book_version_source_sets vss ON vss.book_version_id = bv.id
            GROUP BY
              b.id, b.book_key, b.title, b.manual_code, b.status,
              bv.id, bv.version_label, bv.lifecycle_status, bv.source_baseline_id,
              sb.baseline_status, sb.baseline_hash
            ORDER BY b.book_key, bv.version_label
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    public function getVersion(int $versionId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT
              bv.*,
              b.book_key,
              b.title AS book_title,
              b.manual_code,
              sb.baseline_key,
              sb.baseline_status,
              sb.baseline_hash,
              sb.source_snapshot_json,
              sb.mapping_snapshot_json,
              sb.frozen_at
            FROM ipca_publishing_book_versions bv
            INNER JOIN ipca_publishing_books b ON b.id = bv.book_id
            LEFT JOIN ipca_publishing_source_baselines sb ON sb.id = bv.source_baseline_id
            WHERE bv.id = :id
            LIMIT 1
        ");
        $stmt->execute(array(':id' => $versionId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function getVersionSourceSelections(int $versionId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
              vss.*,
              ss.source_set_key,
              ss.source_family,
              ss.revision_label,
              ss.source_hash
            FROM ipca_publishing_book_version_source_sets vss
            INNER JOIN ipca_canonical_source_sets ss ON ss.id = vss.source_set_id
            WHERE vss.book_version_id = :version_id
            ORDER BY vss.selection_role, ss.source_set_key
        ");
        $stmt->execute(array(':version_id' => $versionId));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    /**
     * @param list<array{source_set_id:int,selection_role:string,is_required_for_release?:bool,notes?:string|null}> $selections
     */
    public function setVersionSourceSets(int $versionId, array $selections, ?int $actorUserId = null): void
    {
        $version = $this->getVersion($versionId);
        if ($version === null) {
            throw new RuntimeException('Book version not found.');
        }
        if ((string)$version['lifecycle_status'] === 'released') {
            throw new RuntimeException('Released versions cannot change source selections.');
        }

        $this->pdo->beginTransaction();
        try {
            $del = $this->pdo->prepare('DELETE FROM ipca_publishing_book_version_source_sets WHERE book_version_id = :version_id');
            $del->execute(array(':version_id' => $versionId));

            $ins = $this->pdo->prepare("
                INSERT INTO ipca_publishing_book_version_source_sets
                    (book_version_id, source_set_id, selection_role, is_required_for_release, selected_by, notes)
                VALUES
                    (:book_version_id, :source_set_id, :selection_role, :is_required, :selected_by, :notes)
            ");
            foreach ($selections as $sel) {
                $ins->execute(array(
                    ':book_version_id' => $versionId,
                    ':source_set_id' => (int)$sel['source_set_id'],
                    ':selection_role' => (string)$sel['selection_role'],
                    ':is_required' => !empty($sel['is_required_for_release']) ? 1 : 0,
                    ':selected_by' => $actorUserId,
                    ':notes' => $sel['notes'] ?? null,
                ));
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Apply the required OM/OMM source-set selections for a version.
     */
    public function applyRequiredSourceSetsForVersion(int $versionId, ?int $actorUserId = null): void
    {
        $version = $this->getVersion($versionId);
        if ($version === null) {
            throw new RuntimeException('Book version not found.');
        }
        $manualCode = (string)($version['manual_code'] ?? '');
        if (!isset(self::REQUIRED_SOURCE_SETS[$manualCode])) {
            throw new RuntimeException("No required source-set mapping for manual {$manualCode}.");
        }

        $selections = array();
        foreach (self::REQUIRED_SOURCE_SETS[$manualCode] as $def) {
            $sourceSetId = $this->sourceSetIdByKey($def['source_set_key']);
            $selections[] = array(
                'source_set_id' => $sourceSetId,
                'selection_role' => $def['selection_role'],
                'is_required_for_release' => true,
            );
        }
        $this->setVersionSourceSets($versionId, $selections, $actorUserId);
    }

    /**
     * @return array{ok:bool,status:string,missing:array<int,string>,selected_count:int}
     */
    public function validateVersionReleaseFoundation(int $versionId): array
    {
        $version = $this->getVersion($versionId);
        if ($version === null) {
            throw new RuntimeException('Book version not found.');
        }

        $manualCode = (string)($version['manual_code'] ?? '');
        $required = self::REQUIRED_SOURCE_SETS[$manualCode] ?? array();
        $selected = $this->getVersionSourceSelections($versionId);
        $selectedKeys = array();
        foreach ($selected as $row) {
            $selectedKeys[(string)$row['selection_role'] . ':' . (string)$row['source_set_key']] = true;
        }

        $missing = array();
        foreach ($required as $role => $def) {
            $key = $def['selection_role'] . ':' . $def['source_set_key'];
            if (!isset($selectedKeys[$key])) {
                $missing[] = $def['source_set_key'] . ' (' . $role . ')';
            }
        }

        if ($missing !== array()) {
            return array(
                'ok' => false,
                'status' => 'missing_source_set_selection',
                'missing' => $missing,
                'selected_count' => count($selected),
            );
        }
        if (empty($version['source_baseline_id'])) {
            return array(
                'ok' => false,
                'status' => 'missing_source_baseline',
                'missing' => array(),
                'selected_count' => count($selected),
            );
        }

        $baselineStatus = (string)($version['baseline_status'] ?? '');
        if (!in_array($baselineStatus, array('frozen', 'released'), true)) {
            return array(
                'ok' => false,
                'status' => 'baseline_not_frozen',
                'missing' => array(),
                'selected_count' => count($selected),
            );
        }

        return array(
            'ok' => true,
            'status' => 'ready',
            'missing' => array(),
            'selected_count' => count($selected),
        );
    }

    public function canReleaseVersion(int $versionId): bool
    {
        return $this->validateVersionReleaseFoundation($versionId)['ok'];
    }

    public function releaseVersion(int $versionId, ?int $actorUserId = null): void
    {
        if (!$this->canReleaseVersion($versionId)) {
            $validation = $this->validateVersionReleaseFoundation($versionId);
            throw new RuntimeException('Cannot release version: ' . (string)($validation['status'] ?? 'not_ready'));
        }

        $stmt = $this->pdo->prepare("
            UPDATE ipca_publishing_book_versions
            SET lifecycle_status = 'released',
                released_at = CURRENT_TIMESTAMP,
                released_by = :released_by,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
              AND lifecycle_status <> 'released'
        ");
        $stmt->execute(array(
            ':released_by' => $actorUserId,
            ':id' => $versionId,
        ));
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Version could not be released.');
        }
    }

    public function suggestNextVersionLabel(string $currentLabel): string
    {
        $currentLabel = trim($currentLabel);
        if (preg_match('/^(\d+)\.(\d+)$/', $currentLabel, $match)) {
            return ((int)$match[1] + 1) . '.' . $match[2];
        }
        if (preg_match('/^(\d+)$/', $currentLabel, $match)) {
            return (string)((int)$match[1] + 1);
        }
        return $currentLabel . '.1';
    }

    /**
     * Copy a released (or draft) version into a new draft and prepare Part 0 admin pages.
     *
     * @return array{version_id:int,version_label:string}
     */
    public function createNextDraftVersion(int $sourceVersionId, string $newVersionLabel, ?int $actorUserId = null): array
    {
        require_once __DIR__ . '/ControlledPublishingPart0PageService.php';

        $source = $this->getVersion($sourceVersionId);
        if ($source === null) {
            throw new RuntimeException('Source version not found.');
        }

        $newVersionLabel = trim($newVersionLabel);
        if ($newVersionLabel === '') {
            throw new RuntimeException('New version label is required.');
        }

        $bookId = (int)$source['book_id'];
        $bookKey = (string)$source['book_key'];
        $oldVersionLabel = (string)$source['version_label'];

        $check = $this->pdo->prepare("
            SELECT id FROM ipca_publishing_book_versions
            WHERE book_id = :book_id AND version_label = :version_label
            LIMIT 1
        ");
        $check->execute(array(
            ':book_id' => $bookId,
            ':version_label' => $newVersionLabel,
        ));
        if ($check->fetchColumn()) {
            throw new RuntimeException("Version {$newVersionLabel} already exists for this book.");
        }

        $this->pdo->beginTransaction();
        try {
            $newVersionId = $this->createDraftVersion($bookId, $newVersionLabel, $actorUserId);

            if (!empty($source['template_id'])) {
                $this->attachTemplateToVersion($newVersionId, (int)$source['template_id']);
            }

            $sourceMeta = $source['metadata_json'] ?? '{}';
            if (is_string($sourceMeta)) {
                $sourceMeta = json_decode($sourceMeta, true);
            }
            if (!is_array($sourceMeta)) {
                $sourceMeta = array();
            }

            $upd = $this->pdo->prepare("
                UPDATE ipca_publishing_book_versions
                SET metadata_json = :metadata_json,
                    supersedes_version_id = :supersedes_version_id,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $upd->execute(array(
                ':metadata_json' => json_encode($sourceMeta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ':supersedes_version_id' => $sourceVersionId,
                ':id' => $newVersionId,
            ));

            $selections = array();
            foreach ($this->getVersionSourceSelections($sourceVersionId) as $sel) {
                $selections[] = array(
                    'source_set_id' => (int)$sel['source_set_id'],
                    'selection_role' => (string)$sel['selection_role'],
                    'is_required_for_release' => !empty($sel['is_required_for_release']),
                    'notes' => $sel['notes'] ?? null,
                );
            }
            if ($selections !== array()) {
                $this->setVersionSourceSets($newVersionId, $selections, $actorUserId);
            }

            $sectionMap = $this->copyVersionSections(
                $sourceVersionId,
                $newVersionId,
                $bookKey,
                $oldVersionLabel,
                $newVersionLabel,
                $actorUserId
            );
            $this->copyVersionBlocks(
                $sourceVersionId,
                $newVersionId,
                $sectionMap,
                $oldVersionLabel,
                $newVersionLabel,
                $actorUserId
            );

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $part0 = new ControlledPublishingPart0PageService($this->pdo);
        $part0->ensureAmendmentListForVersion($newVersionId, $actorUserId);

        return array(
            'version_id' => $newVersionId,
            'version_label' => $newVersionLabel,
        );
    }

    public function freezeSourceBaseline(int $versionId, ?int $actorUserId = null): int
    {
        $validation = $this->validateVersionReleaseFoundation($versionId);
        if ($validation['status'] === 'missing_source_set_selection') {
            throw new RuntimeException('Cannot freeze baseline: missing required source sets: ' . implode(', ', $validation['missing']));
        }

        $version = $this->getVersion($versionId);
        if ($version === null) {
            throw new RuntimeException('Book version not found.');
        }

        $selections = $this->getVersionSourceSelections($versionId);
        $snapshotSets = array();
        $baselineSetRows = array();
        foreach ($selections as $sel) {
            $documents = $this->documentsForSourceSet((int)$sel['source_set_id']);
            $counts = $this->countsForSourceSet((int)$sel['source_set_id']);
            $setSnapshot = array(
                'source_set_id' => (int)$sel['source_set_id'],
                'source_set_key' => (string)$sel['source_set_key'],
                'source_family' => (string)$sel['source_family'],
                'revision_label' => (string)$sel['revision_label'],
                'source_hash' => (string)$sel['source_hash'],
                'selection_role' => (string)$sel['selection_role'],
                'documents' => $documents,
                'counts' => $counts,
            );
            $snapshotSets[] = $setSnapshot;
            $baselineSetRows[] = array(
                'source_set_id' => (int)$sel['source_set_id'],
                'source_set_hash' => (string)$sel['source_hash'],
                'source_set_snapshot_json' => $setSnapshot,
            );
        }

        $latestSync = $this->latestSuccessfulSyncRun();
        $sourceSnapshot = array(
            'book_version_id' => $versionId,
            'book_key' => (string)$version['book_key'],
            'version_label' => (string)$version['version_label'],
            'frozen_at' => gmdate('c'),
            'source_sets' => $snapshotSets,
            'latest_sync_run' => $latestSync,
        );
        $mappingSnapshot = array('status' => 'not_implemented');
        $baselineHash = hash('sha256', json_encode(array(
            'source_snapshot' => $sourceSnapshot,
            'mapping_snapshot' => $mappingSnapshot,
        ), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $baselineKey = sprintf(
            '%s-%s-%s',
            (string)$version['book_key'],
            (string)$version['version_label'],
            gmdate('Ymd-His')
        );

        $this->pdo->beginTransaction();
        try {
            if (!empty($version['source_baseline_id'])) {
                $sup = $this->pdo->prepare("
                    UPDATE ipca_publishing_source_baselines
                    SET baseline_status = 'superseded', updated_at = CURRENT_TIMESTAMP
                    WHERE book_version_id = :version_id AND baseline_status IN ('draft', 'frozen')
                ");
                $sup->execute(array(':version_id' => $versionId));
            }

            $ins = $this->pdo->prepare("
                INSERT INTO ipca_publishing_source_baselines
                    (book_version_id, baseline_key, baseline_status, baseline_hash, source_snapshot_json, mapping_snapshot_json, frozen_at, frozen_by)
                VALUES
                    (:book_version_id, :baseline_key, 'frozen', :baseline_hash, :source_snapshot_json, :mapping_snapshot_json, CURRENT_TIMESTAMP, :frozen_by)
            ");
            $ins->execute(array(
                ':book_version_id' => $versionId,
                ':baseline_key' => $baselineKey,
                ':baseline_hash' => $baselineHash,
                ':source_snapshot_json' => json_encode($sourceSnapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ':mapping_snapshot_json' => json_encode($mappingSnapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ':frozen_by' => $actorUserId,
            ));
            $baselineId = (int)$this->pdo->lastInsertId();

            $setIns = $this->pdo->prepare("
                INSERT INTO ipca_publishing_source_baseline_sets
                    (source_baseline_id, source_set_id, source_set_hash, source_set_snapshot_json)
                VALUES
                    (:source_baseline_id, :source_set_id, :source_set_hash, :source_set_snapshot_json)
            ");
            foreach ($baselineSetRows as $row) {
                $setIns->execute(array(
                    ':source_baseline_id' => $baselineId,
                    ':source_set_id' => $row['source_set_id'],
                    ':source_set_hash' => $row['source_set_hash'],
                    ':source_set_snapshot_json' => json_encode($row['source_set_snapshot_json'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ));
            }

            $upd = $this->pdo->prepare("
                UPDATE ipca_publishing_book_versions
                SET source_baseline_id = :baseline_id, updated_at = CURRENT_TIMESTAMP
                WHERE id = :version_id
            ");
            $upd->execute(array(
                ':baseline_id' => $baselineId,
                ':version_id' => $versionId,
            ));

            $this->pdo->commit();
            return $baselineId;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @return array<string,int> manual_code => template_id
     */
    public function ensureTemplates(?int $actorUserId = null): array
    {
        $ids = array();
        $allowedBlocks = json_encode(array(
            'heading', 'paragraph', 'list', 'table', 'image', 'callout', 'reference', 'generated_placeholder',
        ), JSON_THROW_ON_ERROR);

        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_publishing_book_templates
                (template_key, title, manual_family, status, allowed_block_types_json, created_by)
            VALUES
                (:template_key, :title, :manual_family, 'active', :allowed_blocks, :created_by)
            ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                manual_family = VALUES(manual_family),
                status = 'active',
                allowed_block_types_json = VALUES(allowed_block_types_json),
                updated_at = CURRENT_TIMESTAMP
        ");

        foreach (self::TEMPLATE_REGISTRY as $manualCode => $def) {
            $stmt->execute(array(
                ':template_key' => $def['template_key'],
                ':title' => $def['title'],
                ':manual_family' => $def['manual_family'],
                ':allowed_blocks' => $allowedBlocks,
                ':created_by' => $actorUserId,
            ));
            $templateId = $this->templateIdByKey($def['template_key']);
            $this->ensureTemplateSections($templateId);
            $ids[$manualCode] = $templateId;
        }

        return $ids;
    }

    public function attachTemplateToVersion(int $versionId, int $templateId): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE ipca_publishing_book_versions
            SET template_id = :template_id, updated_at = CURRENT_TIMESTAMP
            WHERE id = :version_id
        ");
        $stmt->execute(array(
            ':template_id' => $templateId,
            ':version_id' => $versionId,
        ));
    }

    /**
     * Scaffold mandatory sections (and generated placeholders) from the version template.
     *
     * @return array{sections_created:int,blocks_created:int,sections_total:int}
     */
    public function scaffoldVersionSections(int $versionId, ?int $actorUserId = null): array
    {
        $version = $this->getVersion($versionId);
        if ($version === null) {
            throw new RuntimeException('Book version not found.');
        }
        if ((string)$version['lifecycle_status'] === 'released') {
            throw new RuntimeException('Released versions cannot be re-scaffolded.');
        }

        $manualCode = (string)($version['manual_code'] ?? '');
        $templateId = (int)($version['template_id'] ?? 0);
        if ($templateId <= 0) {
            $templates = $this->ensureTemplates($actorUserId);
            if (!isset($templates[$manualCode])) {
                throw new RuntimeException("No template mapping for manual {$manualCode}.");
            }
            $templateId = $templates[$manualCode];
            $this->attachTemplateToVersion($versionId, $templateId);
        }

        $templateSections = $this->templateSections($templateId);
        $sectionsCreated = 0;
        $blocksCreated = 0;

        $sectionIns = $this->pdo->prepare("
            INSERT INTO ipca_publishing_book_sections
                (book_version_id, template_section_id, section_key, stable_anchor, title, section_type,
                 is_system_managed, is_generated, sort_order, created_by)
            VALUES
                (:book_version_id, :template_section_id, :section_key, :stable_anchor, :title, :section_type,
                 :is_system_managed, :is_generated, :sort_order, :created_by)
            ON DUPLICATE KEY UPDATE
                template_section_id = VALUES(template_section_id),
                title = VALUES(title),
                section_type = VALUES(section_type),
                is_system_managed = VALUES(is_system_managed),
                is_generated = VALUES(is_generated),
                sort_order = VALUES(sort_order),
                updated_at = CURRENT_TIMESTAMP
        ");

        $blockIns = $this->pdo->prepare("
            INSERT INTO ipca_publishing_book_blocks
                (book_version_id, section_id, block_key, stable_anchor, block_type, sort_order,
                 payload_json, content_hash, is_system_managed, created_by, updated_by)
            VALUES
                (:book_version_id, :section_id, :block_key, :stable_anchor, 'generated_placeholder', 10,
                 :payload_json, :content_hash, 1, :created_by, :created_by)
            ON DUPLICATE KEY UPDATE
                payload_json = VALUES(payload_json),
                content_hash = VALUES(content_hash),
                updated_at = CURRENT_TIMESTAMP
        ");

        $bookKey = (string)$version['book_key'];
        $versionLabel = (string)$version['version_label'];

        foreach ($templateSections as $tplSection) {
            $sectionKey = (string)$tplSection['section_key'];
            $stableAnchor = $this->sectionAnchor($bookKey, $versionLabel, $sectionKey);
            $sectionIns->execute(array(
                ':book_version_id' => $versionId,
                ':template_section_id' => (int)$tplSection['id'],
                ':section_key' => $sectionKey,
                ':stable_anchor' => $stableAnchor,
                ':title' => (string)$tplSection['title'],
                ':section_type' => (string)$tplSection['section_type'],
                ':is_system_managed' => !empty($tplSection['is_system_managed']) ? 1 : 0,
                ':is_generated' => !empty($tplSection['is_generated']) ? 1 : 0,
                ':sort_order' => (int)$tplSection['sort_order'],
                ':created_by' => $actorUserId,
            ));

            if ($sectionIns->rowCount() === 1) {
                $sectionsCreated++;
            }

            $sectionId = $this->sectionIdByVersionAndKey($versionId, $sectionKey);

            if (!empty($tplSection['is_generated'])) {
                $payload = array(
                    'status' => 'pending_generation',
                    'section_type' => (string)$tplSection['section_type'],
                    'message' => 'Generated section placeholder — content will be produced at publish time.',
                );
                $contentHash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                $blockKey = $sectionKey . '_placeholder';
                $blockAnchor = $stableAnchor . '-BLOCK-001';
                $blockIns->execute(array(
                    ':book_version_id' => $versionId,
                    ':section_id' => $sectionId,
                    ':block_key' => $blockKey,
                    ':stable_anchor' => $blockAnchor,
                    ':payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    ':content_hash' => $contentHash,
                    ':created_by' => $actorUserId,
                ));
                if ($blockIns->rowCount() === 1) {
                    $blocksCreated++;
                }
            }
        }

        return array(
            'sections_created' => $sectionsCreated,
            'blocks_created' => $blocksCreated,
            'sections_total' => count($this->listVersionSections($versionId)),
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listVersionSections(int $versionId): array
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
     * Seed OM/OMM books, draft versions, source selections, templates, and sections.
     *
     * @return array<string,mixed>
     */
    public function seedOmOmmFoundation(?int $actorUserId = null): array
    {
        $bookIds = $this->ensureBookRegistry($actorUserId);
        $templateIds = $this->ensureTemplates($actorUserId);
        $result = array('books' => array(), 'versions' => array(), 'templates' => $templateIds, 'sections' => array());

        foreach (self::BOOK_REGISTRY as $key => $def) {
            $bookId = $bookIds[$def['book_key']];
            $versionId = $this->createDraftVersion($bookId, $def['version_label'], $actorUserId);
            $this->applyRequiredSourceSetsForVersion($versionId, $actorUserId);
            $this->attachTemplateToVersion($versionId, $templateIds[$def['manual_code']]);
            $result['sections'][$def['book_key']] = $this->scaffoldVersionSections($versionId, $actorUserId);
            $result['books'][$def['book_key']] = $bookId;
            $result['versions'][$def['book_key']] = $versionId;
        }

        return $result;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function canonicalSourceSetInventory(): array
    {
        $stmt = $this->pdo->query("
            SELECT
              ss.id,
              s.source_key,
              ss.source_set_key,
              ss.source_family,
              ss.authority,
              ss.revision_label,
              ss.status,
              ss.source_hash,
              ss.last_synced_at,
              COUNT(DISTINCT d.id) AS documents,
              COUNT(DISTINCT r.id) AS requirements,
              COUNT(DISTINCT e.id) AS excerpts,
              COUNT(DISTINCT l.id) AS requirement_excerpt_links
            FROM ipca_canonical_source_sets ss
            INNER JOIN ipca_canonical_sources s ON s.id = ss.source_id
            LEFT JOIN ipca_canonical_documents d ON d.source_set_id = ss.id
            LEFT JOIN ipca_canonical_requirements r ON r.source_set_id = ss.id
            LEFT JOIN ipca_canonical_excerpts e ON e.source_set_id = ss.id
            LEFT JOIN ipca_canonical_requirement_excerpt_links l ON l.source_set_id = ss.id
            GROUP BY
              ss.id, s.source_key, ss.source_set_key, ss.source_family, ss.authority,
              ss.revision_label, ss.status, ss.source_hash, ss.last_synced_at
            ORDER BY ss.source_family, ss.source_set_key
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function brokenCanonicalLinks(int $limit = 100): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
              l.id,
              l.source_set_id,
              l.requirement_key,
              l.excerpt_key,
              CASE WHEN r.id IS NULL THEN 'missing_requirement' ELSE NULL END AS requirement_issue,
              CASE WHEN e.id IS NULL THEN 'missing_excerpt' ELSE NULL END AS excerpt_issue
            FROM ipca_canonical_requirement_excerpt_links l
            LEFT JOIN ipca_canonical_requirements r ON r.id = l.requirement_id
            LEFT JOIN ipca_canonical_excerpts e ON e.id = l.excerpt_id
            WHERE r.id IS NULL OR e.id IS NULL
            ORDER BY l.id
            LIMIT :lim
        ");
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    private function ensureTemplateSections(int $templateId): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_publishing_book_template_sections
                (template_id, section_key, title, section_type, is_required, is_system_managed,
                 is_generated, allow_author_blocks, sort_order)
            VALUES
                (:template_id, :section_key, :title, :section_type, 1, :is_system_managed,
                 :is_generated, :allow_author_blocks, :sort_order)
            ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                section_type = VALUES(section_type),
                is_required = 1,
                is_system_managed = VALUES(is_system_managed),
                is_generated = VALUES(is_generated),
                allow_author_blocks = VALUES(allow_author_blocks),
                sort_order = VALUES(sort_order),
                updated_at = CURRENT_TIMESTAMP
        ");

        foreach (self::MANDATORY_SECTIONS as $section) {
            $stmt->execute(array(
                ':template_id' => $templateId,
                ':section_key' => $section['section_key'],
                ':title' => $section['title'],
                ':section_type' => $section['section_type'],
                ':is_system_managed' => $section['is_system_managed'] ? 1 : 0,
                ':is_generated' => $section['is_generated'] ? 1 : 0,
                ':allow_author_blocks' => $section['allow_author_blocks'] ? 1 : 0,
                ':sort_order' => $section['sort_order'],
            ));
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function templateSections(int $templateId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM ipca_publishing_book_template_sections
            WHERE template_id = :template_id
            ORDER BY sort_order, id
        ");
        $stmt->execute(array(':template_id' => $templateId));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    private function templateIdByKey(string $templateKey): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM ipca_publishing_book_templates WHERE template_key = :key LIMIT 1');
        $stmt->execute(array(':key' => $templateKey));
        $id = $stmt->fetchColumn();
        if (!$id) {
            throw new RuntimeException("Template {$templateKey} could not be resolved.");
        }
        return (int)$id;
    }

    private function sectionIdByVersionAndKey(int $versionId, string $sectionKey): int
    {
        $stmt = $this->pdo->prepare("
            SELECT id FROM ipca_publishing_book_sections
            WHERE book_version_id = :version_id AND section_key = :section_key
            LIMIT 1
        ");
        $stmt->execute(array(':version_id' => $versionId, ':section_key' => $sectionKey));
        $id = $stmt->fetchColumn();
        if (!$id) {
            throw new RuntimeException("Section {$sectionKey} could not be resolved for version {$versionId}.");
        }
        return (int)$id;
    }

    private function sectionAnchor(string $bookKey, string $versionLabel, string $sectionKey): string
    {
        $normVersion = str_replace('.', '_', $versionLabel);
        $normSection = strtoupper(str_replace('_', '-', $sectionKey));
        return strtoupper($bookKey) . '-' . $normVersion . '-' . $normSection;
    }

    private function bookIdByKey(string $bookKey): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM ipca_publishing_books WHERE book_key = :book_key LIMIT 1');
        $stmt->execute(array(':book_key' => $bookKey));
        $id = $stmt->fetchColumn();
        if (!$id) {
            throw new RuntimeException("Book {$bookKey} could not be resolved.");
        }
        return (int)$id;
    }

    private function versionIdByBookAndLabel(int $bookId, string $versionLabel): int
    {
        $stmt = $this->pdo->prepare('
            SELECT id FROM ipca_publishing_book_versions
            WHERE book_id = :book_id AND version_label = :version_label
            LIMIT 1
        ');
        $stmt->execute(array(':book_id' => $bookId, ':version_label' => $versionLabel));
        $id = $stmt->fetchColumn();
        if (!$id) {
            throw new RuntimeException("Version {$versionLabel} could not be resolved.");
        }
        return (int)$id;
    }

    private function getBook(int $bookId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_publishing_books WHERE id = :id LIMIT 1');
        $stmt->execute(array(':id' => $bookId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function sourceSetIdByKey(string $sourceSetKey): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM ipca_canonical_source_sets WHERE source_set_key = :key LIMIT 1');
        $stmt->execute(array(':key' => $sourceSetKey));
        $id = $stmt->fetchColumn();
        if (!$id) {
            throw new RuntimeException("Canonical source set {$sourceSetKey} not found.");
        }
        return (int)$id;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function documentsForSourceSet(int $sourceSetId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT id, document_key, document_type, manual_code, revision_code, title, source_hash
            FROM ipca_canonical_documents
            WHERE source_set_id = :source_set_id
            ORDER BY document_key
        ');
        $stmt->execute(array(':source_set_id' => $sourceSetId));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    /**
     * @return array{requirements:int,excerpts:int,links:int}
     */
    private function countsForSourceSet(int $sourceSetId): array
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ipca_canonical_requirements WHERE source_set_id = :id');
        $stmt->execute(array(':id' => $sourceSetId));
        $req = (int)$stmt->fetchColumn();

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ipca_canonical_excerpts WHERE source_set_id = :id');
        $stmt->execute(array(':id' => $sourceSetId));
        $ex = (int)$stmt->fetchColumn();

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ipca_canonical_requirement_excerpt_links WHERE source_set_id = :id');
        $stmt->execute(array(':id' => $sourceSetId));
        $links = (int)$stmt->fetchColumn();

        return array('requirements' => $req, 'excerpts' => $ex, 'links' => $links);
    }

    /**
     * @return array<int,int> old_section_id => new_section_id
     */
    private function copyVersionSections(
        int $sourceVersionId,
        int $targetVersionId,
        string $bookKey,
        string $oldVersionLabel,
        string $newVersionLabel,
        ?int $actorUserId
    ): array {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM ipca_publishing_book_sections
            WHERE book_version_id = :version_id
            ORDER BY parent_section_id IS NULL DESC, sort_order, id
        ");
        $stmt->execute(array(':version_id' => $sourceVersionId));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();

        $map = array();
        $pendingParents = array();

        $ins = $this->pdo->prepare("
            INSERT INTO ipca_publishing_book_sections
                (book_version_id, template_section_id, parent_section_id, section_key, stable_anchor, title,
                 section_type, metadata_json, is_system_managed, is_generated, sort_order, created_by)
            VALUES
                (:book_version_id, :template_section_id, :parent_section_id, :section_key, :stable_anchor, :title,
                 :section_type, :metadata_json, :is_system_managed, :is_generated, :sort_order, :created_by)
        ");

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $oldId = (int)($row['id'] ?? 0);
            $oldParentId = (int)($row['parent_section_id'] ?? 0);
            $sectionKey = (string)($row['section_key'] ?? '');
            $newParentId = null;
            if ($oldParentId > 0) {
                if (!isset($map[$oldParentId])) {
                    $pendingParents[] = $row;
                    continue;
                }
                $newParentId = $map[$oldParentId];
            }

            $ins->execute(array(
                ':book_version_id' => $targetVersionId,
                ':template_section_id' => !empty($row['template_section_id']) ? (int)$row['template_section_id'] : null,
                ':parent_section_id' => $newParentId,
                ':section_key' => $sectionKey,
                ':stable_anchor' => $this->sectionAnchor($bookKey, $newVersionLabel, $sectionKey),
                ':title' => (string)($row['title'] ?? ''),
                ':section_type' => (string)($row['section_type'] ?? 'content'),
                ':metadata_json' => $row['metadata_json'] ?? null,
                ':is_system_managed' => !empty($row['is_system_managed']) ? 1 : 0,
                ':is_generated' => !empty($row['is_generated']) ? 1 : 0,
                ':sort_order' => (int)($row['sort_order'] ?? 0),
                ':created_by' => $actorUserId,
            ));
            $map[$oldId] = (int)$this->pdo->lastInsertId();
        }

        $guard = 0;
        while ($pendingParents !== array() && $guard < 20) {
            $guard++;
            $stillPending = array();
            foreach ($pendingParents as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $oldId = (int)($row['id'] ?? 0);
                $oldParentId = (int)($row['parent_section_id'] ?? 0);
                if ($oldParentId <= 0 || !isset($map[$oldParentId])) {
                    $stillPending[] = $row;
                    continue;
                }
                $sectionKey = (string)($row['section_key'] ?? '');
                $ins->execute(array(
                    ':book_version_id' => $targetVersionId,
                    ':template_section_id' => !empty($row['template_section_id']) ? (int)$row['template_section_id'] : null,
                    ':parent_section_id' => $map[$oldParentId],
                    ':section_key' => $sectionKey,
                    ':stable_anchor' => $this->sectionAnchor($bookKey, $newVersionLabel, $sectionKey),
                    ':title' => (string)($row['title'] ?? ''),
                    ':section_type' => (string)($row['section_type'] ?? 'content'),
                    ':metadata_json' => $row['metadata_json'] ?? null,
                    ':is_system_managed' => !empty($row['is_system_managed']) ? 1 : 0,
                    ':is_generated' => !empty($row['is_generated']) ? 1 : 0,
                    ':sort_order' => (int)($row['sort_order'] ?? 0),
                    ':created_by' => $actorUserId,
                ));
                $map[$oldId] = (int)$this->pdo->lastInsertId();
            }
            $pendingParents = $stillPending;
        }

        return $map;
    }

    /**
     * @param array<int,int> $sectionMap
     */
    private function copyVersionBlocks(
        int $sourceVersionId,
        int $targetVersionId,
        array $sectionMap,
        string $oldVersionLabel,
        string $newVersionLabel,
        ?int $actorUserId
    ): void {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM ipca_publishing_book_blocks
            WHERE book_version_id = :version_id
            ORDER BY section_id, sort_order, id
        ");
        $stmt->execute(array(':version_id' => $sourceVersionId));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();

        $ins = $this->pdo->prepare("
            INSERT INTO ipca_publishing_book_blocks
                (book_version_id, section_id, block_key, stable_anchor, block_type, sort_order,
                 payload_json, content_hash, is_system_managed, created_by, updated_by)
            VALUES
                (:book_version_id, :section_id, :block_key, :stable_anchor, :block_type, :sort_order,
                 :payload_json, :content_hash, :is_system_managed, :created_by, :updated_by)
        ");

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $oldSectionId = (int)($row['section_id'] ?? 0);
            if ($oldSectionId <= 0 || !isset($sectionMap[$oldSectionId])) {
                continue;
            }
            $stableAnchor = $this->remapVersionAnchor(
                (string)($row['stable_anchor'] ?? ''),
                $oldVersionLabel,
                $newVersionLabel
            );
            $ins->execute(array(
                ':book_version_id' => $targetVersionId,
                ':section_id' => $sectionMap[$oldSectionId],
                ':block_key' => (string)($row['block_key'] ?? ''),
                ':stable_anchor' => $stableAnchor,
                ':block_type' => (string)($row['block_type'] ?? 'paragraph'),
                ':sort_order' => (int)($row['sort_order'] ?? 0),
                ':payload_json' => (string)($row['payload_json'] ?? '{}'),
                ':content_hash' => (string)($row['content_hash'] ?? ''),
                ':is_system_managed' => !empty($row['is_system_managed']) ? 1 : 0,
                ':created_by' => $actorUserId,
                ':updated_by' => $actorUserId,
            ));
        }
    }

    private function remapVersionAnchor(string $anchor, string $oldVersionLabel, string $newVersionLabel): string
    {
        if ($anchor === '') {
            return '';
        }
        $oldNorm = str_replace('.', '_', $oldVersionLabel);
        $newNorm = str_replace('.', '_', $newVersionLabel);
        if ($oldNorm === $newNorm) {
            return $anchor;
        }
        return str_replace($oldNorm, $newNorm, $anchor);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function latestSuccessfulSyncRun(): ?array
    {
        $stmt = $this->pdo->query("
            SELECT id, status, dry_run, started_at, completed_at, source_inventory_hash
            FROM ipca_canonical_sync_runs
            WHERE status IN ('success', 'dry_run')
            ORDER BY started_at DESC
            LIMIT 1
        ");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}
