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
     * Seed OM/OMM books, draft versions, and required source selections.
     *
     * @return array<string,mixed>
     */
    public function seedOmOmmFoundation(?int $actorUserId = null): array
    {
        $bookIds = $this->ensureBookRegistry($actorUserId);
        $result = array('books' => array(), 'versions' => array());

        foreach (self::BOOK_REGISTRY as $key => $def) {
            $bookId = $bookIds[$def['book_key']];
            $versionId = $this->createDraftVersion($bookId, $def['version_label'], $actorUserId);
            $this->applyRequiredSourceSetsForVersion($versionId, $actorUserId);
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
