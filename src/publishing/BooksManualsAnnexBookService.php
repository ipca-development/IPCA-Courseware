<?php
declare(strict_types=1);

require_once __DIR__ . '/BooksManualsWorkflowService.php';
require_once __DIR__ . '/ControlledPublishingAnnexService.php';
require_once __DIR__ . '/ControlledPublishingBlockService.php';
require_once __DIR__ . '/ControlledPublishingBookStyleService.php';
require_once __DIR__ . '/ControlledPublishingCoverPageService.php';
require_once __DIR__ . '/ControlledPublishingFoundationService.php';
require_once __DIR__ . '/ControlledPublishingSectionService.php';

/**
 * One Annex Book per parent manual: Cover (cloned from parent) → Register → Annexes.
 */
final class BooksManualsAnnexBookService
{
    public const BOOK_TYPE = 'annex_book';
    public const LEGACY_BOOK_TYPE = 'annex';

    public function __construct(
        private PDO $pdo,
        private ?ControlledPublishingFoundationService $foundation = null
    ) {
        $this->foundation ??= new ControlledPublishingFoundationService($pdo);
    }

    public function tablesPresent(): bool
    {
        try {
            $stmt = $this->pdo->query("SHOW TABLES LIKE 'ipca_publishing_annex_book_map'");
            return (bool)$stmt->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    public static function isAnnexBookType(string $bookType): bool
    {
        return $bookType === self::BOOK_TYPE;
    }

    /**
     * @param array<string,mixed> $version
     */
    public static function isAnnexBookVersion(array $version): bool
    {
        return self::isAnnexBookType((string)($version['book_type'] ?? ''))
            || (string)($version['book_type'] ?? '') === self::LEGACY_BOOK_TYPE;
    }

    /**
     * Annex Books skip the manual review cycle. Released means Published.
     *
     * @param array<string,mixed> $row
     */
    public static function allowsReleasedEdits(array $row): bool
    {
        return self::isAnnexBookVersion($row);
    }

    public static function annexBookKey(string $parentBookKey): string
    {
        $parentBookKey = strtoupper(trim($parentBookKey));
        $key = 'ANNEXES_' . $parentBookKey;
        $key = preg_replace('/[^A-Z0-9_-]+/', '_', $key) ?: 'ANNEXES_BOOK';
        return substr($key, 0, 32);
    }

    public static function annexBookTitle(string $parentBookKey, string $parentTitle = ''): string
    {
        $parentBookKey = strtoupper(trim($parentBookKey));
        $parentTitle = trim($parentTitle);
        if ($parentTitle !== '' && !preg_match('/\bAnnexes\b/i', $parentTitle)) {
            return 'Annexes Manual ' . $parentBookKey;
        }
        return 'Annexes Manual ' . $parentBookKey;
    }

    public static function annexCoverTitle(string $parentBookKey): string
    {
        return 'Annexes Manual ' . strtoupper(trim($parentBookKey));
    }

    /**
     * @return array<string,mixed>|null
     */
    public function mapForParent(int $parentBookId): ?array
    {
        if ($parentBookId <= 0 || !$this->tablesPresent()) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            "SELECT * FROM ipca_publishing_annex_book_map
             WHERE parent_book_id = ? AND status = 'active' LIMIT 1"
        );
        $stmt->execute(array($parentBookId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function mapForAnnexBook(int $annexBookId): ?array
    {
        if ($annexBookId <= 0 || !$this->tablesPresent()) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            "SELECT * FROM ipca_publishing_annex_book_map
             WHERE annex_book_id = ? AND status = 'active' LIMIT 1"
        );
        $stmt->execute(array($annexBookId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function annexBookIdForParent(int $parentBookId): int
    {
        $map = $this->mapForParent($parentBookId);
        return $map !== null ? (int)$map['annex_book_id'] : 0;
    }

    public function parentHasAnnexBook(int $parentBookId): bool
    {
        return $this->annexBookIdForParent($parentBookId) > 0;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listAnnexBooks(): array
    {
        if (!$this->tablesPresent()) {
            return array();
        }
        $sql = "
            SELECT
              m.id AS map_id,
              m.parent_book_id,
              m.annex_book_id,
              m.status AS map_status,
              parent.book_key AS parent_book_key,
              parent.title AS parent_book_title,
              annex.book_key,
              annex.title AS book_title,
              annex.book_type,
              annex.manual_code,
              bv.id AS version_id,
              bv.version_label,
              bv.lifecycle_status,
              bv.effective_date,
              bv.updated_at AS version_updated_at
            FROM ipca_publishing_annex_book_map m
            INNER JOIN ipca_publishing_books parent ON parent.id = m.parent_book_id
            INNER JOIN ipca_publishing_books annex ON annex.id = m.annex_book_id
            LEFT JOIN ipca_publishing_book_versions bv
              ON bv.id = (
                SELECT MAX(latest.id)
                FROM ipca_publishing_book_versions latest
                WHERE latest.book_id = annex.id
              )
            WHERE m.status = 'active'
              AND annex.status = 'active'
            ORDER BY parent.book_key, annex.title
        ";
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listParentManualsWithoutAnnexBook(): array
    {
        $sql = "
            SELECT
              b.id AS book_id,
              b.book_key,
              b.title AS book_title,
              bv.id AS version_id,
              bv.version_label
            FROM ipca_publishing_books b
            LEFT JOIN ipca_publishing_book_versions bv
              ON bv.id = (
                SELECT MAX(latest.id)
                FROM ipca_publishing_book_versions latest
                WHERE latest.book_id = b.id
              )
            LEFT JOIN ipca_publishing_annex_book_map m
              ON m.parent_book_id = b.id AND m.status = 'active'
            LEFT JOIN ipca_publishing_annex_book_links legacy
              ON legacy.annex_book_id = b.id
            WHERE b.status = 'active'
              AND b.book_type NOT IN ('annex', 'annex_book')
              AND m.id IS NULL
              AND legacy.id IS NULL
            ORDER BY b.book_key, b.title
        ";
        if (!$this->tablesPresent()) {
            $sql = str_replace(
                "LEFT JOIN ipca_publishing_annex_book_map m
              ON m.parent_book_id = b.id AND m.status = 'active'",
                'LEFT JOIN (SELECT NULL AS id, NULL AS parent_book_id) m ON 1 = 0',
                $sql
            );
        }
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    /**
     * Create or return the 1:1 Annex Book for a parent manual.
     *
     * @return array{book_id:int,version_id:int,book_key:string,version_label:string,created:bool,parent_book_id:int}
     */
    public function ensureAnnexBookForParent(int $parentBookId, int $actorUserId): array
    {
        if ($parentBookId <= 0) {
            throw new RuntimeException('Parent manual not found.');
        }
        if (!$this->tablesPresent()) {
            throw new RuntimeException('Install the Annex Book structure migration first.');
        }

        $parent = $this->bookById($parentBookId);
        if ($parent === null || (string)$parent['status'] !== 'active') {
            throw new RuntimeException('Parent manual not found.');
        }
        if (self::isAnnexBookType((string)$parent['book_type'])
            || (string)$parent['book_type'] === self::LEGACY_BOOK_TYPE) {
            throw new RuntimeException('An Annex Book cannot be the parent of another Annex Book.');
        }

        $existing = $this->mapForParent($parentBookId);
        if ($existing !== null) {
            $version = $this->latestVersion((int)$existing['annex_book_id']);
            if ($version === null) {
                throw new RuntimeException('Annex Book version is missing.');
            }
            $this->scaffoldAnnexBookSections((int)$version['id'], $actorUserId);
            $this->syncCoverFromParent((int)$version['id'], $actorUserId);
            return array(
                'book_id' => (int)$existing['annex_book_id'],
                'version_id' => (int)$version['id'],
                'book_key' => (string)$version['book_key'],
                'version_label' => (string)$version['version_label'],
                'created' => false,
                'parent_book_id' => $parentBookId,
            );
        }

        $parentVersion = $this->latestVersion($parentBookId);
        if ($parentVersion === null) {
            throw new RuntimeException('Parent manual has no version to clone the cover from.');
        }

        $bookKey = self::annexBookKey((string)$parent['book_key']);
        $title = self::annexBookTitle((string)$parent['book_key'], (string)$parent['title']);
        $versionLabel = '1.0';

        $this->pdo->beginTransaction();
        try {
            $created = $this->insertAnnexBook(
                $bookKey,
                $title,
                (string)($parent['manual_code'] !== '' ? $parent['manual_code'] : $parent['book_key']),
                $versionLabel,
                $actorUserId
            );
            $this->insertMap($parentBookId, $created['book_id'], $actorUserId);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->copyParentVisuals((int)$parentVersion['id'], $created['version_id'], $actorUserId);
        $this->scaffoldAnnexBookSections($created['version_id'], $actorUserId);
        $this->syncCoverFromParent($created['version_id'], $actorUserId);

        $workflow = new BooksManualsWorkflowService($this->pdo, $this->foundation);
        $workflow->saveProfile($created['book_id'], 'handbook', 'internal', null, $actorUserId);
        $workflow->syncUpdateIdentity($created['version_id']);

        return array(
            'book_id' => $created['book_id'],
            'version_id' => $created['version_id'],
            'book_key' => $created['book_key'],
            'version_label' => $created['version_label'],
            'created' => true,
            'parent_book_id' => $parentBookId,
        );
    }

    public function scaffoldAnnexBookSections(int $versionId, ?int $actorUserId = null): void
    {
        $version = $this->foundation->getVersion($versionId);
        if ($version === null) {
            throw new RuntimeException('Annex Book version not found.');
        }
        if (!self::isAnnexBookVersion($version)) {
            return;
        }
        if ((string)$version['lifecycle_status'] === 'released') {
            return;
        }

        $bookKey = (string)$version['book_key'];
        $versionLabel = (string)$version['version_label'];
        $coverId = $this->ensureTopLevelSection(
            $versionId,
            'cover',
            'Cover Page',
            'cover',
            10,
            $bookKey,
            $versionLabel,
            $actorUserId,
            false
        );
        unset($coverId);

        $parentId = $this->ensureTopLevelSection(
            $versionId,
            ControlledPublishingAnnexService::PARENT_SECTION_KEY,
            'Annexes',
            'annex',
            20,
            $bookKey,
            $versionLabel,
            $actorUserId,
            false
        );

        $annex = new ControlledPublishingAnnexService(
            $this->pdo,
            $this->foundation,
            new ControlledPublishingSectionService($this->pdo),
            new ControlledPublishingBlockService($this->pdo)
        );
        $annex->ensureAnnexInfrastructure($versionId, $actorUserId);
        unset($parentId);
        $annex->regenerateRegister($versionId, $actorUserId);
    }

    public function syncCoverFromParent(int $annexVersionId, ?int $actorUserId = null): array
    {
        $version = $this->foundation->getVersion($annexVersionId);
        if ($version === null || !self::isAnnexBookVersion($version)) {
            throw new RuntimeException('Annex Book version not found.');
        }
        $map = $this->mapForAnnexBook((int)$version['book_id']);
        if ($map === null) {
            throw new RuntimeException('Annex Book is not linked to a parent manual.');
        }
        $parentVersion = $this->latestVersion((int)$map['parent_book_id']);
        if ($parentVersion === null) {
            throw new RuntimeException('Parent manual version not found.');
        }

        $coverSvc = new ControlledPublishingCoverPageService($this->pdo);
        $parentCover = $coverSvc->resolveFromVersion($parentVersion);
        $parentBookKey = (string)($parentVersion['book_key'] ?? '');
        $parentCover['manual_title'] = self::annexCoverTitle($parentBookKey);
        return $coverSvc->saveForVersion($annexVersionId, $parentCover, $actorUserId);
    }

    public function syncCoverForParentBook(int $parentBookId, ?int $actorUserId = null): void
    {
        $annexBookId = $this->annexBookIdForParent($parentBookId);
        if ($annexBookId <= 0) {
            return;
        }
        $version = $this->latestVersion($annexBookId);
        if ($version === null || (string)$version['lifecycle_status'] === 'released') {
            return;
        }
        $this->syncCoverFromParent((int)$version['id'], $actorUserId);
    }

    /**
     * @return array{book_id:int,version_id:int,book_key:string,version_label:string}
     */
    private function insertAnnexBook(
        string $bookKey,
        string $title,
        string $manualCode,
        string $versionLabel,
        int $actorUserId
    ): array {
        $exists = $this->pdo->prepare(
            'SELECT id FROM ipca_publishing_books WHERE book_key = :book_key LIMIT 1'
        );
        $exists->execute(array(':book_key' => $bookKey));
        $existingId = (int)$exists->fetchColumn();
        if ($existingId > 0) {
            $this->pdo->prepare(
                "UPDATE ipca_publishing_books
                 SET book_type = ?, title = ?, manual_code = ?, status = 'active',
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?"
            )->execute(array(self::BOOK_TYPE, $title, $manualCode, $existingId));
            $version = $this->latestVersion($existingId);
            if ($version === null) {
                $versionId = $this->foundation->createDraftVersion($existingId, $versionLabel, $actorUserId);
                $version = $this->foundation->getVersion($versionId);
            }
            if ($version === null) {
                throw new RuntimeException('Could not resolve the Annex Book version.');
            }
            return array(
                'book_id' => $existingId,
                'version_id' => (int)$version['id'],
                'book_key' => $bookKey,
                'version_label' => (string)$version['version_label'],
            );
        }

        $bookIns = $this->pdo->prepare("
            INSERT INTO ipca_publishing_books
                (book_key, title, book_type, manual_code, status, created_by)
            VALUES
                (:book_key, :title, :book_type, :manual_code, 'active', :created_by)
        ");
        $bookIns->execute(array(
            ':book_key' => $bookKey,
            ':title' => $title,
            ':book_type' => self::BOOK_TYPE,
            ':manual_code' => $manualCode,
            ':created_by' => $actorUserId,
        ));
        $bookId = (int)$this->pdo->lastInsertId();
        $versionId = $this->foundation->createDraftVersion($bookId, $versionLabel, $actorUserId);
        return array(
            'book_id' => $bookId,
            'version_id' => $versionId,
            'book_key' => $bookKey,
            'version_label' => $versionLabel,
        );
    }

    private function insertMap(int $parentBookId, int $annexBookId, int $actorUserId): void
    {
        $this->pdo->prepare(
            "INSERT INTO ipca_publishing_annex_book_map
              (parent_book_id, annex_book_id, status, migration_json, created_by)
             VALUES (?, ?, 'active', ?, ?)
             ON DUPLICATE KEY UPDATE
               annex_book_id = VALUES(annex_book_id),
               status = 'active',
               updated_at = CURRENT_TIMESTAMP(3)"
        )->execute(array(
            $parentBookId,
            $annexBookId,
            json_encode(array(
                'topology' => 'one_annex_book_per_parent',
                'outline' => array('cover', 'annexes_register', 'annexes'),
            ), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $actorUserId,
        ));
    }

    private function copyParentVisuals(int $parentVersionId, int $annexVersionId, int $actorUserId): void
    {
        $parent = $this->foundation->getVersion($parentVersionId);
        $annex = $this->foundation->getVersion($annexVersionId);
        if ($parent === null || $annex === null) {
            return;
        }
        $parentMeta = $this->decodeMeta($parent);
        $annexMeta = $this->decodeMeta($annex);
        foreach (array('page_header', 'page_footer', 'styles', 'toc') as $key) {
            if (isset($parentMeta[$key])) {
                $annexMeta[$key] = $parentMeta[$key];
            }
        }
        $this->pdo->prepare(
            'UPDATE ipca_publishing_book_versions
             SET metadata_json = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        )->execute(array(
            json_encode($annexMeta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $annexVersionId,
        ));

        try {
            (new ControlledPublishingBookStyleService($this->pdo))->copyStylesFromVersion(
                $annexVersionId,
                $parentVersionId,
                $actorUserId
            );
        } catch (Throwable) {
            // Styles may already match after the metadata copy.
        }
    }

    private function ensureTopLevelSection(
        int $versionId,
        string $sectionKey,
        string $title,
        string $sectionType,
        int $sortOrder,
        string $bookKey,
        string $versionLabel,
        ?int $actorUserId,
        bool $generated
    ): int {
        $existing = $this->sectionIdByKey($versionId, $sectionKey);
        if ($existing > 0) {
            $this->pdo->prepare(
                "UPDATE ipca_publishing_book_sections
                 SET is_system_managed = 1,
                     section_type = ?,
                     title = ?,
                     sort_order = ?,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND book_version_id = ?"
            )->execute(array($sectionType, $title, $sortOrder, $existing, $versionId));
            return $existing;
        }

        $anchor = strtoupper($bookKey) . '-' . str_replace('.', '_', $versionLabel)
            . '-' . strtoupper(str_replace('_', '-', $sectionKey));
        $this->pdo->prepare("
            INSERT INTO ipca_publishing_book_sections
                (book_version_id, parent_section_id, section_key, stable_anchor, title, section_type,
                 is_system_managed, is_generated, sort_order, created_by)
            VALUES
                (?, NULL, ?, ?, ?, ?, 1, ?, ?, ?)
        ")->execute(array(
            $versionId,
            $sectionKey,
            $anchor,
            $title,
            $sectionType,
            $generated ? 1 : 0,
            $sortOrder,
            $actorUserId,
        ));
        return (int)$this->pdo->lastInsertId();
    }

    private function sectionIdByKey(int $versionId, string $sectionKey): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM ipca_publishing_book_sections
             WHERE book_version_id = ? AND section_key = ? LIMIT 1'
        );
        $stmt->execute(array($versionId, $sectionKey));
        return (int)$stmt->fetchColumn();
    }

    /**
     * @return array<string,mixed>|null
     */
    private function bookById(int $bookId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_publishing_books WHERE id = ? LIMIT 1');
        $stmt->execute(array($bookId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function latestVersion(int $bookId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM ipca_publishing_book_versions
             WHERE book_id = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(array($bookId));
        $versionId = (int)$stmt->fetchColumn();
        return $versionId > 0 ? $this->foundation->getVersion($versionId) : null;
    }

    /**
     * @param array<string,mixed> $version
     * @return array<string,mixed>
     */
    private function decodeMeta(array $version): array
    {
        $raw = $version['metadata_json'] ?? null;
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || trim($raw) === '') {
            return array();
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return array();
        }
        return is_array($decoded) ? $decoded : array();
    }
}
