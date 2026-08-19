<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingFoundationService.php';
require_once __DIR__ . '/BooksManualsAnnexBookService.php';

final class BooksManualsWorkflowService
{
    private const TRANSITIONS = array(
        'draft' => array('publish_review' => 'in_review'),
        'in_review' => array('revert_draft' => 'draft', 'ready_approval' => 'approved'),
        'approved' => array(
            'revert_review' => 'in_review',
            'submit_authority' => 'approved',
            'manual_approve' => 'released',
        ),
        'released' => array(),
    );

    public function __construct(
        private PDO $pdo,
        private ?ControlledPublishingFoundationService $foundation = null
    ) {
        $this->foundation ??= new ControlledPublishingFoundationService($pdo);
    }

    public function tablesPresent(): bool
    {
        try {
            $stmt = $this->pdo->query("SHOW TABLES LIKE 'ipca_publishing_version_workflow'");
            return (bool)$stmt->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    public function annexMapPresent(): bool
    {
        try {
            $stmt = $this->pdo->query("SHOW TABLES LIKE 'ipca_publishing_annex_book_map'");
            return (bool)$stmt->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listLibrary(bool $annexesOnly = false): array
    {
        $annexMapPresent = $this->annexMapPresent();
        $annexFilter = $annexesOnly
            ? ($annexMapPresent
                ? "(b.book_type = 'annex_book' OR m.id IS NOT NULL)"
                : 'al.id IS NOT NULL')
            : ($annexMapPresent
                ? "(b.book_type NOT IN ('annex', 'annex_book') AND m.id IS NULL AND al.id IS NULL)"
                : 'al.id IS NULL');
        $mapJoin = $annexMapPresent
            ? "LEFT JOIN ipca_publishing_annex_book_map m
              ON m.annex_book_id = b.id AND m.status = 'active'"
            : 'LEFT JOIN (SELECT NULL AS id, NULL AS annex_book_id) m ON 1 = 0';
        $sql = "
            SELECT
              b.id AS book_id,
              b.book_key,
              b.title AS book_title,
              b.book_type,
              b.manual_code,
              b.status AS book_status,
              bv.id AS version_id,
              bv.version_label,
              bv.lifecycle_status,
              bv.effective_date,
              bv.updated_at AS version_updated_at,
              bv.supersedes_version_id,
              bp.manual_type,
              bp.approval_route,
              bp.authority_code,
              bp.approved_reader_policy,
              vw.update_sequence,
              vw.update_code,
              vw.source_fingerprint,
              vw.page_map_hash,
              vw.manifest_hash,
              updater.name AS updated_by_name,
              COALESCE(aud.audience_count, 0) AS audience_count,
              COALESCE(reviewers.reviewer_count, 0) AS reviewer_count,
              audit.status AS audit_status,
              audit.coverage_percent,
              COALESCE(m.parent_book_id, al.parent_book_id) AS parent_book_id,
              al.legacy_section_id,
              COALESCE(m.status, al.migration_status) AS migration_status
            FROM ipca_publishing_books b
            LEFT JOIN ipca_publishing_book_versions bv
              ON bv.id = (
                SELECT MAX(latest.id)
                FROM ipca_publishing_book_versions latest
                WHERE latest.book_id = b.id
              )
            LEFT JOIN ipca_publishing_book_profiles bp ON bp.book_id = b.id
            LEFT JOIN ipca_publishing_version_workflow vw ON vw.book_version_id = bv.id
            LEFT JOIN users updater ON updater.id = vw.last_transition_by
            LEFT JOIN (
              SELECT book_version_id, COUNT(*) AS audience_count
              FROM ipca_publishing_version_audiences
              GROUP BY book_version_id
            ) aud ON aud.book_version_id = bv.id
            LEFT JOIN (
              SELECT book_id, COUNT(*) AS reviewer_count
              FROM ipca_publishing_book_reviewers
              GROUP BY book_id
            ) reviewers ON reviewers.book_id = b.id
            LEFT JOIN ipca_publishing_audit_snapshots audit
              ON audit.id = (
                SELECT MAX(a2.id)
                FROM ipca_publishing_audit_snapshots a2
                WHERE a2.book_version_id = bv.id
              )
            {$mapJoin}
            LEFT JOIN ipca_publishing_annex_book_links al ON al.annex_book_id = b.id
            WHERE b.status = 'active'
              AND {$annexFilter}
            ORDER BY b.title, bv.id DESC
        ";
        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: array();
        foreach ($rows as &$row) {
            if ((int)($row['version_id'] ?? 0) > 0) {
                $identity = $this->syncUpdateIdentity((int)$row['version_id']);
                $row = array_merge($row, $identity);
            }
            $status = (string)($row['lifecycle_status'] ?? 'draft');
            $isAnnexBook = BooksManualsAnnexBookService::isAnnexBookVersion($row)
                || BooksManualsAnnexBookService::isAnnexBookType((string)($row['book_type'] ?? ''));
            $row['is_annex_book'] = $isAnnexBook;
            $row['phase_label'] = $isAnnexBook ? self::annexPhaseLabel($status) : self::phaseLabel($status);
            $row['phase_tone'] = $isAnnexBook ? self::annexPhaseTone($status) : self::phaseTone($status);
            $row['actions'] = $isAnnexBook ? self::annexActionsFor($status) : self::actionsFor($status);
        }
        unset($row);
        return $rows;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getVersionDetail(int $versionId): ?array
    {
        $version = $this->foundation->getVersion($versionId);
        if ($version === null) {
            return null;
        }
        $profile = $this->profile((int)$version['book_id']);
        $identity = $this->syncUpdateIdentity($versionId);
        $version = array_merge($version, $profile, $identity);
        $isAnnexBook = BooksManualsAnnexBookService::isAnnexBookVersion($version);
        $version['is_annex_book'] = $isAnnexBook;
        $version['phase_label'] = $isAnnexBook
            ? self::annexPhaseLabel((string)$version['lifecycle_status'])
            : self::phaseLabel((string)$version['lifecycle_status']);
        $version['phase_tone'] = $isAnnexBook
            ? self::annexPhaseTone((string)$version['lifecycle_status'])
            : self::phaseTone((string)$version['lifecycle_status']);
        $version['actions'] = $isAnnexBook
            ? self::annexActionsFor((string)$version['lifecycle_status'])
            : self::actionsFor((string)$version['lifecycle_status']);
        $version['audiences'] = $this->audiences($versionId);
        $version['reviewers'] = $this->bookReviewers((int)$version['book_id']);
        $version['latest_audit'] = $this->latestAudit($versionId);
        return $version;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listPreviousVersions(int $bookId, int $currentVersionId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
               bv.id AS version_id,
               bv.version_label,
               bv.title,
               bv.lifecycle_status,
               bv.effective_date,
               bv.released_at,
               bv.created_at,
               bv.updated_at,
               vw.update_code,
               releaser.name AS approved_by_name,
               releaser.email AS approved_by_email,
               audit.status AS audit_status,
               audit.coverage_percent
             FROM ipca_publishing_book_versions bv
             LEFT JOIN ipca_publishing_version_workflow vw
               ON vw.book_version_id = bv.id
             LEFT JOIN users releaser ON releaser.id = bv.released_by
             LEFT JOIN ipca_publishing_audit_snapshots audit
               ON audit.id = (
                 SELECT MAX(a2.id)
                 FROM ipca_publishing_audit_snapshots a2
                 WHERE a2.book_version_id = bv.id
               )
             WHERE bv.book_id = ?
               AND bv.id <> ?
             ORDER BY bv.id DESC"
        );
        $stmt->execute(array($bookId, $currentVersionId));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
        foreach ($rows as &$row) {
            $row['version_id'] = (int)$row['version_id'];
            $row['phase_label'] = self::phaseLabel((string)$row['lifecycle_status']);
            $row['phase_tone'] = self::phaseTone((string)$row['lifecycle_status']);
        }
        unset($row);
        return $rows;
    }

    /**
     * @return array{book_id:int,version_id:int,book_key:string,version_label:string,copy_content:bool}
     */
    public function createBlankManual(
        int $sourceVersionId,
        string $bookKey,
        string $title,
        string $versionLabel,
        string $manualType,
        string $approvalRoute,
        ?string $authorityCode,
        int $actorUserId
    ): array {
        $created = $this->foundation->createManualFromVersion(
            $sourceVersionId,
            $bookKey,
            $title,
            $versionLabel,
            false,
            $actorUserId
        );
        $this->saveProfile(
            (int)$created['book_id'],
            $manualType,
            $approvalRoute,
            $authorityCode,
            $actorUserId
        );
        $this->syncUpdateIdentity((int)$created['version_id']);
        $this->recordEvent((int)$created['version_id'], '', 'draft', 'create_manual', null, $actorUserId);
        return $created;
    }

    /**
     * @return array{book_id:int,version_id:int,book_key:string,version_label:string,copy_content:bool}
     */
    public function createStandaloneAnnex(
        int $parentBookId,
        int $sourceVersionId,
        string $annexKey,
        string $bookKey,
        string $title,
        string $versionLabel,
        string $revisionDate,
        int $actorUserId
    ): array {
        unset($sourceVersionId, $annexKey, $bookKey, $title, $versionLabel, $revisionDate);
        require_once __DIR__ . '/BooksManualsAnnexBookService.php';
        return (new BooksManualsAnnexBookService($this->pdo, $this->foundation))
            ->ensureAnnexBookForParent($parentBookId, $actorUserId);
    }

    /**
     * @return array{version_id:int,version_label:string}
     */
    public function createRevision(
        int $releasedVersionId,
        int $actorUserId,
        ?string $requestedVersionLabel = null
    ): array {
        $source = $this->foundation->getVersion($releasedVersionId);
        if ($source === null || (string)$source['lifecycle_status'] !== 'released') {
            throw new RuntimeException('Only an approved manual can create a revision.');
        }
        if (BooksManualsAnnexBookService::isAnnexBookVersion($source)) {
            throw new RuntimeException('Annex Books are published or unpublished. They do not use the manual revision cycle.');
        }
        $label = trim((string)$requestedVersionLabel);
        if ($label === '') {
            $label = $this->foundation->suggestNextVersionLabel((string)$source['version_label']);
        }
        $authorization = $this->foundation->authorizeNextDraftVersionCreation(
            $releasedVersionId,
            $label,
            $actorUserId
        );
        $created = $this->foundation->createNextDraftVersion(
            $releasedVersionId,
            $label,
            $actorUserId,
            $authorization
        );
        $this->syncUpdateIdentity((int)$created['version_id']);
        $this->recordEvent(
            (int)$created['version_id'],
            'released',
            'draft',
            'create_revision',
            'Copied from version ' . (string)$source['version_label'],
            $actorUserId
        );
        $this->refreshRevisionHighlights((int)$created['version_id'], $actorUserId);
        $this->queueInitialPageMap((int)$created['version_id'], $actorUserId);
        return $created;
    }

    /**
     * Record a one-time, immutable legacy-approval override and create the next
     * draft from the governed source version.
     *
     * @return array{version_id:int,version_label:string,override_uuid:string}
     */
    public function createRevisionWithBulkOverride(
        int $sourceVersionId,
        int $actorUserId,
        string $rationale
    ): array {
        $rationale = trim($rationale);
        if (strlen($rationale) < 20) {
            throw new InvalidArgumentException(
                'Provide a rationale of at least 20 characters explaining the legacy approval.'
            );
        }
        if (strlen($rationale) > 4000) {
            throw new InvalidArgumentException('The override rationale must not exceed 4,000 characters.');
        }
        $source = $this->foundation->getVersion($sourceVersionId);
        if ($source === null) {
            throw new RuntimeException('Manual version not found.');
        }
        if ((string)$source['lifecycle_status'] === 'released') {
            $created = $this->createRevision($sourceVersionId, $actorUserId);
            $created['override_uuid'] = '';
            return $created;
        }
        $tablePresent = (int)$this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'ipca_publishing_compliance_overrides'"
        )->fetchColumn() === 1;
        if (!$tablePresent) {
            throw new RuntimeException('Install the compliance override migration first.');
        }
        $this->ensureOverridePageMapStylesCurrent($source, $actorUserId);

        require_once __DIR__ . '/BooksManualsAuditService.php';
        $coverage = (new BooksManualsAuditService($this->pdo, $this->foundation))
            ->liveCoverage($sourceVersionId);
        $gapItems = array();
        foreach ((array)($coverage['requirements'] ?? array()) as $requirement) {
            if (($requirement['coverage_state'] ?? '') === 'covered') {
                continue;
            }
            $gapItems[] = array(
                'requirement_id' => (int)($requirement['id'] ?? 0),
                'requirement_key' => (string)($requirement['requirement_key'] ?? ''),
                'coverage_state' => (string)($requirement['coverage_state'] ?? 'missing'),
                'score' => isset($requirement['score']) ? (int)$requirement['score'] : null,
                'reasons' => (array)($requirement['reasons'] ?? array()),
            );
        }
        $blockers = array(
            'schema_version' => 1,
            'captured_at' => gmdate('c'),
            'previous_lifecycle_status' => (string)$source['lifecycle_status'],
            'override_scope' => 'all_release_blockers',
            'coverage_percent' => (float)($coverage['coverage_percent'] ?? 0),
            'insufficient_count' => (int)($coverage['insufficient_count'] ?? 0),
            'missing_count' => (int)($coverage['missing_count'] ?? 0),
            'source_baseline_ok' => !empty($coverage['source_baseline_ok']),
            'authoritative_pagination_ok' => !empty($coverage['authoritative_pagination_ok']),
            'foundation_errors' => (array)($coverage['foundation_errors'] ?? array()),
            'gap_items' => $gapItems,
        );
        if (empty($source['source_baseline_id'])) {
            $this->foundation->freezeSourceBaseline($sourceVersionId, $actorUserId);
            $source = $this->foundation->getVersion($sourceVersionId);
            if ($source === null || empty($source['source_baseline_id'])) {
                throw new RuntimeException(
                    'The governed source baseline required for the override could not be created.'
                );
            }
        }
        $identity = $this->syncUpdateIdentity($sourceVersionId);
        $overrideUuid = $this->uuidV4();
        $from = (string)$source['lifecycle_status'];

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO ipca_publishing_compliance_overrides
                  (override_uuid, book_version_id, override_scope, rationale,
                   blockers_json, source_fingerprint, actor_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute(array(
                $overrideUuid,
                $sourceVersionId,
                'all_release_blockers',
                $rationale,
                json_encode(
                    $blockers,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                ),
                (string)$identity['source_fingerprint'],
                $actorUserId,
            ));
            $release = $this->pdo->prepare(
                "UPDATE ipca_publishing_book_versions
                 SET lifecycle_status = 'released',
                     released_at = CURRENT_TIMESTAMP,
                     released_by = ?,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND lifecycle_status = ?"
            );
            $release->execute(array($actorUserId, $sourceVersionId, $from));
            if ($release->rowCount() !== 1) {
                throw new RuntimeException('The manual phase changed before the override completed.');
            }
            $releasedSource = $this->foundation->getVersion($sourceVersionId);
            if ($releasedSource === null) {
                throw new RuntimeException('The released manual version could not be reloaded.');
            }
            $this->approveStoredPageMapUnderOverride(
                $releasedSource,
                $actorUserId,
                $overrideUuid
            );
            $this->recordEvent(
                $sourceVersionId,
                $from,
                'released',
                'bulk_override_legacy_approval',
                $rationale,
                $actorUserId
            );
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $created = $this->createRevision($sourceVersionId, $actorUserId);
        $created['override_uuid'] = $overrideUuid;
        return $created;
    }

    public function transition(int $versionId, string $action, int $actorUserId, ?string $note = null): string
    {
        $version = $this->foundation->getVersion($versionId);
        if ($version === null) {
            throw new RuntimeException('Manual version not found.');
        }
        if (BooksManualsAnnexBookService::isAnnexBookVersion($version)) {
            return $this->transitionAnnexBook($version, $action, $actorUserId, $note);
        }
        $from = (string)$version['lifecycle_status'];
        $to = self::TRANSITIONS[$from][$action] ?? null;
        if (!is_string($to)) {
            throw new RuntimeException('That lifecycle action is not available in the current phase.');
        }
        if ($action === 'submit_authority') {
            $profile = $this->profile((int)$version['book_id']);
            if (($profile['approval_route'] ?? '') !== 'authority') {
                throw new RuntimeException('This manual uses internal approval.');
            }
            $this->assertPassingCurrentAudit($versionId, 'authority submission');
            $this->recordEvent($versionId, $from, $to, $action, $note, $actorUserId);
            $this->syncUpdateIdentity($versionId);
            return $to;
        }
        if ($action === 'ready_approval') {
            require_once __DIR__ . '/BooksManualsAuditService.php';
            (new BooksManualsAuditService($this->pdo, $this->foundation))
                ->createSnapshot($versionId, $actorUserId);
        }
        if ($action === 'manual_approve') {
            $this->assertPassingCurrentAudit($versionId, 'approval');
            $this->foundation->releaseVersion($versionId, $actorUserId);
        } else {
            $stmt = $this->pdo->prepare(
                'UPDATE ipca_publishing_book_versions
                 SET lifecycle_status = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND lifecycle_status = ?'
            );
            $stmt->execute(array($to, $versionId, $from));
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('The manual phase changed before this action completed.');
            }
        }
        $this->recordEvent($versionId, $from, $to, $action, $note, $actorUserId);
        $this->syncUpdateIdentity($versionId);
        return $to;
    }

    public function saveProfile(
        int $bookId,
        string $manualType,
        string $approvalRoute,
        ?string $authorityCode,
        int $actorUserId,
        string $approvedReaderPolicy = 'all_readers'
    ): void {
        $manualType = strtolower(trim($manualType));
        $allowedTypes = array('operations', 'training', 'sop', 'course_book', 'handbook', 'other');
        if (!in_array($manualType, $allowedTypes, true)) {
            throw new InvalidArgumentException('Invalid manual type.');
        }
        $approvalRoute = strtolower(trim($approvalRoute));
        if (!in_array($approvalRoute, array('internal', 'authority'), true)) {
            throw new InvalidArgumentException('Invalid approval route.');
        }
        $authorityCode = $approvalRoute === 'authority'
            ? strtoupper(substr(trim((string)$authorityCode), 0, 64))
            : null;
        if ($approvalRoute === 'authority' && $authorityCode === '') {
            throw new InvalidArgumentException('Approval authority is required.');
        }
        $approvedReaderPolicy = strtolower(trim($approvedReaderPolicy));
        if (!in_array($approvedReaderPolicy, array('all_readers', 'selected_reviewers'), true)) {
            throw new InvalidArgumentException('Invalid approved reader policy.');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO ipca_publishing_book_profiles
              (book_id, manual_type, approval_route, authority_code,
               approved_reader_policy, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE manual_type = VALUES(manual_type),
               approval_route = VALUES(approval_route), authority_code = VALUES(authority_code),
               approved_reader_policy = VALUES(approved_reader_policy),
               updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP(3)'
        );
        $stmt->execute(array(
            $bookId,
            $manualType,
            $approvalRoute,
            $authorityCode,
            $approvedReaderPolicy,
            $actorUserId,
            $actorUserId,
        ));
    }

    /**
     * @return list<array{id:int,name:string,email:string,role:string}>
     */
    public function availableReviewers(): array
    {
        $stmt = $this->pdo->query(
            "SELECT id, name, email, role
             FROM users
             WHERE role IN ('instructor','supervisor','chief_instructor')
             ORDER BY name, email"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
        return array_map(
            static fn(array $row): array => array(
                'id' => (int)$row['id'],
                'name' => (string)($row['name'] ?? ''),
                'email' => (string)($row['email'] ?? ''),
                'role' => (string)($row['role'] ?? ''),
            ),
            $rows
        );
    }

    /**
     * @return list<array{id:int,name:string,email:string,role:string}>
     */
    public function bookReviewers(int $bookId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.name, u.email, u.role
             FROM ipca_publishing_book_reviewers br
             INNER JOIN users u ON u.id = br.reviewer_user_id
             WHERE br.book_id = ?
             ORDER BY u.name, u.email'
        );
        $stmt->execute(array($bookId));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
        return array_map(
            static fn(array $row): array => array(
                'id' => (int)$row['id'],
                'name' => (string)($row['name'] ?? ''),
                'email' => (string)($row['email'] ?? ''),
                'role' => (string)($row['role'] ?? ''),
            ),
            $rows
        );
    }

    /**
     * @param list<int> $reviewerUserIds
     */
    public function replaceBookReviewers(
        int $bookId,
        array $reviewerUserIds,
        int $actorUserId
    ): void {
        $reviewerUserIds = array_values(array_unique(array_filter(
            array_map('intval', $reviewerUserIds),
            static fn(int $id): bool => $id > 0
        )));
        $eligible = array_column($this->availableReviewers(), 'id');
        foreach ($reviewerUserIds as $reviewerUserId) {
            if (!in_array($reviewerUserId, $eligible, true)) {
                throw new InvalidArgumentException(
                    'Only instructor or instructor-supervisor accounts can review a manual.'
                );
            }
        }
        $this->pdo->beginTransaction();
        try {
            if ($reviewerUserIds !== array()) {
                $in = implode(',', array_fill(0, count($reviewerUserIds), '?'));
                $this->pdo->prepare(
                    "UPDATE users SET can_manual_reviewer = 1 WHERE id IN ({$in})"
                )->execute($reviewerUserIds);
            }
            $this->pdo->prepare(
                'DELETE FROM ipca_publishing_book_reviewers WHERE book_id = ?'
            )->execute(array($bookId));
            $insert = $this->pdo->prepare(
                'INSERT INTO ipca_publishing_book_reviewers
                  (book_id, reviewer_user_id, assigned_by)
                 VALUES (?, ?, ?)'
            );
            foreach ($reviewerUserIds as $reviewerUserId) {
                $insert->execute(array($bookId, $reviewerUserId, $actorUserId));
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param list<array{type:string,key:string}> $audiences
     */
    public function replaceAudiences(int $versionId, array $audiences, int $actorUserId): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'DELETE FROM ipca_publishing_version_audiences WHERE book_version_id = ?'
            )->execute(array($versionId));
            $insert = $this->pdo->prepare(
                'INSERT INTO ipca_publishing_version_audiences
                  (book_version_id, audience_type, audience_key, assigned_by)
                 VALUES (?, ?, ?, ?)'
            );
            foreach ($audiences as $audience) {
                $type = (string)($audience['type'] ?? '');
                $key = substr(trim((string)($audience['key'] ?? '')), 0, 128);
                if (!in_array($type, array('role', 'user'), true) || $key === '') {
                    continue;
                }
                $insert->execute(array($versionId, $type, $key, $actorUserId));
            }
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
    public function syncUpdateIdentity(int $versionId): array
    {
        $version = $this->foundation->getVersion($versionId);
        if ($version === null) {
            throw new RuntimeException('Manual version not found.');
        }
        $fingerprint = $this->sourceFingerprint($versionId);
        $stmt = $this->pdo->prepare(
            'SELECT update_sequence, update_code, source_fingerprint, page_map_hash, manifest_hash
             FROM ipca_publishing_version_workflow WHERE book_version_id = ? LIMIT 1'
        );
        $stmt->execute(array($versionId));
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        $sequence = max(1, (int)($current['update_sequence'] ?? 1));
        if (is_array($current)
            && (string)($current['source_fingerprint'] ?? '') !== ''
            && (string)$current['source_fingerprint'] !== $fingerprint) {
            $sequence++;
        }
        $code = sprintf(
            '%s-V%d-%s-U%06d',
            substr(strtoupper((string)$version['book_key']), 0, 48),
            $versionId,
            substr((string)$version['version_label'], 0, 64),
            $sequence
        );
        $hashes = $this->publicationHashes($version);
        $upsert = $this->pdo->prepare(
            'INSERT INTO ipca_publishing_version_workflow
              (book_version_id, update_sequence, update_code, source_fingerprint, page_map_hash, manifest_hash)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE update_sequence = VALUES(update_sequence),
               update_code = VALUES(update_code), source_fingerprint = VALUES(source_fingerprint),
               page_map_hash = VALUES(page_map_hash), manifest_hash = VALUES(manifest_hash)'
        );
        $upsert->execute(array(
            $versionId,
            $sequence,
            $code,
            $fingerprint,
            $hashes['page_map_hash'],
            $hashes['manifest_hash'],
        ));
        return array(
            'update_sequence' => $sequence,
            'update_code' => $code,
            'source_fingerprint' => $fingerprint,
            'page_map_hash' => $hashes['page_map_hash'],
            'manifest_hash' => $hashes['manifest_hash'],
        );
    }

    public static function phaseLabel(string $status): string
    {
        return match ($status) {
            'draft' => 'DRAFT',
            'in_review' => 'DRAFT REVIEW',
            'approved' => 'AWAITING APPROVAL',
            'released' => 'APPROVED',
            default => strtoupper(str_replace('_', ' ', $status)),
        };
    }

    public static function phaseTone(string $status): string
    {
        return match ($status) {
            'draft' => 'neutral',
            'in_review' => 'review',
            'approved' => 'warning',
            'released' => 'approved',
            default => 'neutral',
        };
    }

    /**
     * @return list<array{action:string,label:string,tone:string}>
     */
    public static function actionsFor(string $status): array
    {
        return match ($status) {
            'draft' => array(
                array('action' => 'publish_review', 'label' => 'Publish for Draft Review', 'tone' => 'primary'),
            ),
            'in_review' => array(
                array('action' => 'revert_draft', 'label' => 'Revert to Draft', 'tone' => 'secondary'),
                array('action' => 'ready_approval', 'label' => 'Ready for Approval', 'tone' => 'primary'),
            ),
            'approved' => array(
                array('action' => 'revert_review', 'label' => 'Revert to Draft Review', 'tone' => 'secondary'),
                array('action' => 'submit_authority', 'label' => 'Submit for Authority Approval', 'tone' => 'primary'),
                array('action' => 'manual_approve', 'label' => 'Manually Approve', 'tone' => 'primary'),
            ),
            default => array(),
        };
    }

    public static function annexPhaseLabel(string $status): string
    {
        return $status === 'released' ? 'PUBLISHED' : 'NOT PUBLISHED';
    }

    public static function annexPhaseTone(string $status): string
    {
        return $status === 'released' ? 'approved' : 'neutral';
    }

    /**
     * @return list<array{action:string,label:string,tone:string}>
     */
    public static function annexActionsFor(string $status): array
    {
        if ($status === 'released') {
            return array(
                array('action' => 'unpublish_annex', 'label' => 'Unpublish', 'tone' => 'secondary'),
            );
        }
        return array(
            array('action' => 'publish_annex', 'label' => 'Publish', 'tone' => 'primary'),
        );
    }

    /**
     * @param array<string,mixed> $version
     */
    private function transitionAnnexBook(
        array $version,
        string $action,
        int $actorUserId,
        ?string $note
    ): string {
        $versionId = (int)$version['id'];
        $from = (string)$version['lifecycle_status'];
        if ($action === 'publish_annex') {
            if ($from === 'released') {
                throw new RuntimeException('This Annex Book is already published.');
            }
            $this->foundation->releaseAnnexBookVersion($versionId, $actorUserId);
            $this->recordEvent($versionId, $from, 'released', $action, $note, $actorUserId);
            $this->syncUpdateIdentity($versionId);
            return 'released';
        }
        if ($action === 'unpublish_annex') {
            if ($from !== 'released') {
                throw new RuntimeException('Only a published Annex Book can be unpublished.');
            }
            $this->foundation->reopenVersionToDraft($versionId, $actorUserId);
            $this->recordEvent($versionId, $from, 'draft', $action, $note, $actorUserId);
            $this->syncUpdateIdentity($versionId);
            return 'draft';
        }
        throw new RuntimeException('Annex Books can only be Published or Unpublished.');
    }

    /**
     * @return array<string,mixed>
     */
    private function profile(int $bookId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT manual_type, approval_route, authority_code, approved_reader_policy
             FROM ipca_publishing_book_profiles WHERE book_id = ? LIMIT 1'
        );
        $stmt->execute(array($bookId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : array(
            'manual_type' => 'operations',
            'approval_route' => 'internal',
            'authority_code' => null,
            'approved_reader_policy' => 'all_readers',
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function audiences(int $versionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT audience_type, audience_key, assigned_at
             FROM ipca_publishing_version_audiences
             WHERE book_version_id = ? ORDER BY audience_type, audience_key'
        );
        $stmt->execute(array($versionId));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    /**
     * @return array<string,mixed>|null
     */
    private function latestAudit(int $versionId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ipca_publishing_audit_snapshots
             WHERE book_version_id = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(array($versionId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function assertPassingCurrentAudit(int $versionId, string $purpose): void
    {
        $audit = $this->latestAudit($versionId);
        if (($audit['status'] ?? '') !== 'passed') {
            throw new RuntimeException("A passing Compliance audit is required before {$purpose}.");
        }
        $identity = $this->syncUpdateIdentity($versionId);
        if (!hash_equals(
            (string)($audit['source_fingerprint'] ?? ''),
            (string)$identity['source_fingerprint']
        )) {
            throw new RuntimeException(
                "The manual changed after its audit snapshot. Create a new snapshot before {$purpose}."
            );
        }
    }

    private function sourceFingerprint(int $versionId): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.id, s.section_key, s.title, s.updated_at,
                    b.id AS block_id, b.stable_anchor, b.updated_at AS block_updated_at,
                    SHA2(COALESCE(b.payload_json, ?), 256) AS payload_hash
             FROM ipca_publishing_book_sections s
             LEFT JOIN ipca_publishing_book_blocks b ON b.section_id = s.id
             WHERE s.book_version_id = ?
             ORDER BY s.sort_order, s.id, b.sort_order, b.id'
        );
        $stmt->execute(array('', $versionId));
        return hash('sha256', json_encode(
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    /**
     * @param array<string,mixed> $version
     * @return array{page_map_hash:?string,manifest_hash:?string}
     */
    private function publicationHashes(array $version): array
    {
        $meta = $version['metadata_json'] ?? array();
        if (is_string($meta)) {
            $meta = json_decode($meta, true);
        }
        $meta = is_array($meta) ? $meta : array();
        $map = is_array($meta['reader_page_map'] ?? null) ? $meta['reader_page_map'] : array();
        $generation = is_array($map['generation'] ?? null) ? $map['generation'] : array();
        return array(
            'page_map_hash' => isset($generation['page_map_hash'])
                ? (string)$generation['page_map_hash']
                : null,
            'manifest_hash' => isset($generation['manifest_hash'])
                ? (string)$generation['manifest_hash']
                : null,
        );
    }

    private function recordEvent(
        int $versionId,
        string $from,
        string $to,
        string $action,
        ?string $note,
        int $actorUserId
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ipca_publishing_lifecycle_events
              (book_version_id, from_status, to_status, action_key, note, actor_user_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute(array($versionId, $from, $to, $action, $note, $actorUserId));
        $this->pdo->prepare(
            'UPDATE ipca_publishing_version_workflow
             SET last_transition_at = CURRENT_TIMESTAMP(3), last_transition_by = ?
             WHERE book_version_id = ?'
        )->execute(array($actorUserId, $versionId));
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20)
        );
    }

    private function queueInitialPageMap(int $versionId, int $actorUserId): void
    {
        try {
            require_once __DIR__ . '/ControlledPublishingLivePageMapService.php';
            (new ControlledPublishingLivePageMapService($this->pdo))->ensure(
                $versionId,
                $actorUserId,
                null,
                array(
                    'mutation_kind' => 'create_revision',
                    'layout_impact' => 'global',
                )
            );
        } catch (Throwable $e) {
            error_log(
                'Unable to queue initial revision page map for version '
                    . $versionId . ': ' . $e->getMessage()
            );
        }
    }

    private function refreshRevisionHighlights(int $versionId, int $actorUserId): void
    {
        require_once __DIR__ . '/ControlledPublishingRevisionService.php';
        (new ControlledPublishingRevisionService($this->pdo))
            ->regenerateHighlightsSection($versionId, $actorUserId);
    }

    /**
     * A legacy compliance override may waive approval blockers, but it cannot
     * bind frozen HTML to a different publication stylesheet. Queue a fresh
     * authoritative map first and let the administrator retry once it is current.
     *
     * @param array<string,mixed> $version
     */
    private function ensureOverridePageMapStylesCurrent(array $version, int $actorUserId): void
    {
        $metadata = $version['metadata_json'] ?? array();
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true);
        }
        $metadata = is_array($metadata) ? $metadata : array();
        $map = is_array($metadata['reader_page_map'] ?? null)
            ? $metadata['reader_page_map']
            : array();
        $generation = is_array($map['generation'] ?? null)
            ? $map['generation']
            : array();

        require_once __DIR__ . '/ControlledPublishingReaderService.php';
        $reader = new ControlledPublishingReaderService($this->pdo);
        $source = $reader->loadReaderPaginateSource($version);
        $package = $reader->paginationPublicationPackage($version, $source);
        $currentStyleHash = (string)($package['css']['hash'] ?? '');
        $frozenStyleHash = (string)($generation['style_hash'] ?? '');
        if (
            $currentStyleHash !== ''
            && $frozenStyleHash !== ''
            && hash_equals($currentStyleHash, $frozenStyleHash)
        ) {
            return;
        }

        require_once __DIR__ . '/ControlledPublishingLivePageMapService.php';
        (new ControlledPublishingLivePageMapService($this->pdo, $reader))->ensure(
            (int)$version['id'],
            $actorUserId,
            null,
            array(
                'mutation_kind' => 'legacy_override_style_refresh',
                'layout_impact' => 'global',
            )
        );
        throw new RuntimeException(
            'The authoritative page map is being refreshed for the current publication styles. '
            . 'Wait for generation to complete, then select Override blockers again.'
        );
    }

    /**
     * A bulk legacy override governs the exact stored page map even when its
     * historical layout fingerprint would fail today's ordinary approval gate.
     *
     * @param array<string,mixed> $version
     */
    private function approveStoredPageMapUnderOverride(
        array $version,
        int $actorUserId,
        string $overrideUuid
    ): void {
        $metadata = $version['metadata_json'] ?? array();
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true);
        }
        $metadata = is_array($metadata) ? $metadata : array();
        $map = $metadata['reader_page_map'] ?? null;
        if (!is_array($map) || (string)($map['status'] ?? '') !== 'draft') {
            return;
        }
        $profile = trim((string)($map['layout_profile'] ?? ''));
        if ($profile === '') {
            return;
        }
        $countStmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ipca_publishing_reader_page_maps
             WHERE book_version_id = ? AND layout_profile = ?'
        );
        $countStmt->execute(array((int)$version['id'], $profile));
        $pageCount = (int)$countStmt->fetchColumn();
        if ($pageCount <= 0) {
            return;
        }
        $generation = is_array($map['generation'] ?? null)
            ? $map['generation']
            : array();
        require_once __DIR__ . '/ControlledPublishingReaderService.php';
        $reader = new ControlledPublishingReaderService($this->pdo);
        $source = $reader->loadReaderPaginateSource($version);
        $package = $reader->paginationPublicationPackage($version, $source);
        $currentStyleHash = (string)($package['css']['hash'] ?? '');
        $frozenStyleHash = (string)($generation['style_hash'] ?? '');
        if (
            $frozenStyleHash !== ''
            && $currentStyleHash !== ''
            && !hash_equals($frozenStyleHash, $currentStyleHash)
        ) {
            throw new RuntimeException(
                'The stored page map uses different publication styles and cannot be overridden safely.'
            );
        }
        $generation['style_hash'] = $currentStyleHash;
        $generation['manifest_hash'] = (string)($package['manifest_hash'] ?? '');
        $metadata['reader_page_map'] = array_merge($map, array(
            'status' => 'approved',
            'generation' => $generation,
            'page_count' => $pageCount,
            'approved_at' => gmdate('Y-m-d H:i:s'),
            'approved_by_user_id' => $actorUserId,
            'approval_basis' => 'legacy_compliance_override',
            'approval_override_uuid' => $overrideUuid,
        ));
        $this->pdo->prepare(
            'UPDATE ipca_publishing_book_versions
             SET metadata_json = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        )->execute(array(
            json_encode(
                $metadata,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
            (int)$version['id'],
        ));
    }
}
