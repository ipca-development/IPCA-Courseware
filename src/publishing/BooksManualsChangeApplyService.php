<?php
declare(strict_types=1);

require_once __DIR__ . '/BooksManualsChangePlanService.php';
require_once __DIR__ . '/ControlledPublishingBlockService.php';
require_once __DIR__ . '/ControlledPublishingRevisionService.php';
require_once __DIR__ . '/ControlledPublishingTocService.php';
require_once __DIR__ . '/BooksManualsWorkflowService.php';
require_once __DIR__ . '/BooksManualsAuditService.php';
require_once __DIR__ . '/ControlledPublishingLivePageMapService.php';
require_once dirname(__DIR__) . '/AuditEventService.php';

/**
 * Applies accepted Wizard wording to the selected editable version in place.
 *
 * No book version is created or transitioned here. The operation record retains
 * exact before/after section snapshots so a guarded, server-authoritative undo
 * can restore the pre-Wizard rows without overwriting later human edits.
 */
final class BooksManualsChangeApplyService
{
    public function __construct(
        private PDO $pdo,
        private BooksManualsChangePlanService $plans
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function apply(int $planId, int $actorUserId): array
    {
        $plan = $this->plans->getPlan($planId);
        if ((int)($plan['owner_id'] ?? 0) !== $actorUserId) {
            throw new RuntimeException('Only the Change Plan owner can apply accepted Wizard changes.');
        }
        if ((string)($plan['stage'] ?? '') !== 'operations') {
            throw new RuntimeException('Independent Review must be completed before Wizard changes can be applied.');
        }
        $versionId = $this->plans->primaryVersionId($plan);
        if ($versionId <= 0) {
            throw new RuntimeException('The Change Plan does not identify its original manual version.');
        }

        $draft = $this->latestRow(
            'SELECT * FROM ipca_manual_ai_architect_drafts
             WHERE plan_id=? AND status=\'generated\' ORDER BY id DESC LIMIT 1',
            array($planId)
        );
        $draftPayload = $this->decode($draft['draft_payload_json'] ?? null);
        if ((string)($draftPayload['wizard_status'] ?? '') !== 'accepted') {
            throw new RuntimeException('The proposed wording has not been accepted.');
        }
        $proposal = $draftPayload;
        $sectionDrafts = array_values(array_filter(
            (array)($proposal['section_drafts'] ?? array()),
            'is_array'
        ));
        if ($sectionDrafts === array()) {
            throw new RuntimeException('The accepted wording package contains no manual amendments.');
        }

        $review = $this->latestRow(
            'SELECT * FROM ipca_manual_ai_architect_reviews
             WHERE plan_id=? AND status=\'approved\' ORDER BY id DESC LIMIT 1',
            array($planId)
        );
        $reviewPayload = $this->decode($review['review_payload_json'] ?? null);
        if (strtoupper((string)($reviewPayload['status'] ?? '')) !== 'READY') {
            throw new RuntimeException('The independently reviewed wording is not approved for application.');
        }
        $reviewedPackage = (array)($reviewPayload['operation_package'] ?? array());
        $preflightPackage = $this->buildPreflightPackage($planId);
        if (!hash_equals(
            (string)($reviewedPackage['package_fingerprint'] ?? ''),
            (string)($preflightPackage['package_fingerprint'] ?? '')
        )) {
            throw new RuntimeException(
                'The in-place operation package differs from the independently reviewed package.'
            );
        }

        $existing = $this->latestRow(
            'SELECT * FROM ipca_manual_ai_architect_operations
             WHERE plan_id=? AND operation_type=\'apply_accepted_wizard_changes\'
               AND status IN (\'running\',\'succeeded\')
             ORDER BY id DESC LIMIT 1',
            array($planId),
            false
        );
        if ($existing !== null) {
            $result = $this->decode($existing['result_json'] ?? null);
            if ((string)($existing['status'] ?? '') === 'succeeded') {
                return $result;
            }
            throw new RuntimeException('This Wizard application is already running.');
        }

        $targets = $this->buildTargets($planId, $versionId, $sectionDrafts);
        $requestFingerprint = hash('sha256', $this->json(array(
            'plan_id' => $planId,
            'version_id' => $versionId,
            'draft_id' => (int)$draft['id'],
            'review_id' => (int)$review['id'],
            'draft_fingerprint' => (string)($draft['content_fingerprint'] ?? ''),
            'reviewed_package_fingerprint' => (string)$preflightPackage['package_fingerprint'],
            'targets' => array_map(
                static fn(array $target): array => array(
                    'section_number' => $target['section_number'],
                    'context_hash' => $target['context_hash'],
                ),
                $targets
            ),
        )));
        $operationUuid = $this->uuid();
        $operationId = $this->plans->save('operations', $planId, array(
            'operation_uuid' => $operationUuid,
            'draft_id' => (int)$draft['id'],
            'review_id' => (int)$review['id'],
            'operation_type' => 'apply_accepted_wizard_changes',
            'idempotency_key' => hash('sha256', 'wizard-apply|' . $planId . '|' . $requestFingerprint),
            'status' => 'running',
            'request_fingerprint' => $requestFingerprint,
            'operation_payload_json' => $this->json(array(
                'schema' => 'ipca.manual-change-in-place-apply.v1',
                'plan_id' => $planId,
                'book_version_id' => $versionId,
                'revision_creation_allowed' => false,
                'lifecycle_transition_allowed' => false,
                'independently_reviewed_package' => $preflightPackage,
                'target_contexts' => array_map(
                    static fn(array $target): array => array(
                        'section_number' => $target['section_number'],
                        'section_id' => $target['section_id'],
                        'context_key' => $target['context_key'],
                        'context_hash' => $target['context_hash'],
                    ),
                    $targets
                ),
            )),
            'requested_by' => $actorUserId,
            'started_at' => gmdate('Y-m-d H:i:s.v'),
        ));

        try {
            $this->pdo->beginTransaction();
            $version = $this->lockEditableVersion($versionId);
            $before = $this->snapshotsForTargets($targets);
            $this->assertTargetsUnchanged($targets, $before);
            $this->replaceTargetRanges(
                $planId,
                $versionId,
                $targets,
                $sectionDrafts,
                $this->structureTitles($planId),
                $actorUserId
            );
            $after = $this->snapshotsForSectionIds(array_keys($before));

            // Derived controlled content is regenerated inside the transaction.
            (new ControlledPublishingRevisionService($this->pdo))->regenerateHighlightsSection(
                $versionId,
                $actorUserId
            );
            (new ControlledPublishingTocService($this->pdo))->regenerateTocSection(
                $versionId,
                $actorUserId
            );
            (new BooksManualsWorkflowService($this->pdo))->syncUpdateIdentity($versionId);
            $this->appendSectionEditEvents(
                $planId,
                $operationId,
                'replace',
                $before,
                $after,
                $actorUserId
            );

            $result = array(
                'schema' => 'ipca.manual-change-in-place-result.v1',
                'plan_id' => $planId,
                'operation_id' => $operationId,
                'book_version_id' => $versionId,
                'version_label' => (string)($version['version_label'] ?? ''),
                'lifecycle_status' => (string)($version['lifecycle_status'] ?? ''),
                'revision_created' => false,
                'revision_number_changed' => false,
                'lifecycle_changed' => false,
                'before_sections' => $before,
                'after_sections' => $after,
                'affected_section_numbers' => array_values(array_map(
                    static fn(array $target): string => (string)$target['section_number'],
                    $targets
                )),
                'applied_at' => gmdate(DATE_ATOM),
                'applied_by' => $actorUserId,
                'undo_available' => true,
            );
            $resultFingerprint = hash('sha256', $this->json($result));
            $this->pdo->prepare(
                "UPDATE ipca_manual_ai_architect_operations
                 SET status='succeeded',result_json=?,result_fingerprint=?,
                     completed_at=CURRENT_TIMESTAMP(3)
                 WHERE id=? AND plan_id=? AND status='running'"
            )->execute(array($this->json($result), $resultFingerprint, $operationId, $planId));
            $this->plans->appendEvent($planId, 'WIZARD_CHANGES_APPLIED_IN_PLACE', 13, array(
                'operation_id' => $operationId,
                'book_version_id' => $versionId,
                'revision_created' => false,
                'revision_number_changed' => false,
                'lifecycle_changed' => false,
                'affected_section_numbers' => $result['affected_section_numbers'],
                'before_fingerprint' => hash('sha256', $this->json($before)),
                'after_fingerprint' => hash('sha256', $this->json($after)),
            ), $actorUserId);
            $this->pdo->commit();
            $result['derived_refresh_warnings'] = $this->refreshPostCommitArtifacts(
                $versionId,
                $actorUserId,
                'manual_change_architect_apply_in_place'
            );
            $this->recordGlobalAudit(
                'manual_change_architect.apply_in_place',
                $planId,
                $operationId,
                $versionId,
                $actorUserId,
                $result
            );
            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->pdo->prepare(
                "UPDATE ipca_manual_ai_architect_operations
                 SET status='failed',error_message=?,completed_at=CURRENT_TIMESTAMP(3)
                 WHERE id=? AND status='running'"
            )->execute(array($e->getMessage(), $operationId));
            throw $e;
        }
    }

    /**
     * Build the immutable, read-only operation package shown to Independent Review.
     *
     * @return array<string,mixed>
     */
    public function buildPreflightPackage(int $planId): array
    {
        $plan = $this->plans->getPlan($planId);
        $versionId = $this->plans->primaryVersionId($plan);
        if ($versionId <= 0) {
            throw new RuntimeException('The Change Plan does not identify its original manual version.');
        }
        $version = $this->latestRow(
            'SELECT id,version_label,lifecycle_status FROM ipca_publishing_book_versions WHERE id=?',
            array($versionId)
        );
        if (!in_array((string)($version['lifecycle_status'] ?? ''), array('draft', 'in_review'), true)) {
            throw new RuntimeException('Wizard changes may only target a Draft or Draft Review manual.');
        }
        $draft = $this->latestRow(
            "SELECT id,content_fingerprint,draft_payload_json
             FROM ipca_manual_ai_architect_drafts
             WHERE plan_id=? AND status='generated' ORDER BY id DESC LIMIT 1",
            array($planId)
        );
        $proposal = $this->decode($draft['draft_payload_json'] ?? null);
        if ((string)($proposal['wizard_status'] ?? '') !== 'accepted') {
            throw new RuntimeException('The proposed wording has not been accepted.');
        }
        $sectionDrafts = array_values(array_filter(
            (array)($proposal['section_drafts'] ?? array()),
            'is_array'
        ));
        $targets = $this->buildTargets($planId, $versionId, $sectionDrafts);
        $snapshots = $this->snapshotsForTargets($targets);
        $this->assertTargetsUnchanged($targets, $snapshots);
        $titles = $this->structureTitles($planId);
        $replacements = array();
        foreach ($sectionDrafts as $sectionDraft) {
            $generated = $this->generatedParagraphs($sectionDraft, $titles);
            $number = (string)($sectionDraft['section_number'] ?? '');
            $replacements[$number] = array(
                'generated_block_count' => count($generated),
                'replacement_fingerprint' => hash('sha256', $this->json($generated)),
            );
        }
        $package = array(
            'schema' => 'ipca.manual-change-in-place-operation-package.v1',
            'plan_id' => $planId,
            'book_version_id' => $versionId,
            'version_label' => (string)($version['version_label'] ?? ''),
            'lifecycle_status' => (string)($version['lifecycle_status'] ?? ''),
            'draft_id' => (int)$draft['id'],
            'draft_fingerprint' => (string)($draft['content_fingerprint'] ?? ''),
            'revision_creation_allowed' => false,
            'revision_number_change_allowed' => false,
            'lifecycle_transition_allowed' => false,
            'transactional_all_or_nothing' => true,
            'target_contexts' => array_map(
                static fn(array $target): array => array(
                    'section_number' => (string)$target['section_number'],
                    'section_id' => (int)$target['section_id'],
                    'context_key' => (string)$target['context_key'],
                    'context_hash' => (string)$target['context_hash'],
                    'source_block_ids' => array_map('intval', (array)$target['block_ids']),
                ),
                $targets
            ),
            'pre_apply_section_fingerprints' => array_map(
                static fn(array $snapshot): string => (string)$snapshot['fingerprint'],
                $snapshots
            ),
            'replacements' => $replacements,
        );
        $package['package_fingerprint'] = hash('sha256', $this->json($package));
        return $package;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function availableUndo(int $versionId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT o.*,p.title plan_title
             FROM ipca_manual_ai_architect_operations o
             JOIN ipca_manual_ai_architect_plans p ON p.id=o.plan_id
             WHERE o.operation_type='apply_accepted_wizard_changes' AND o.status='succeeded'
               AND CAST(JSON_UNQUOTE(JSON_EXTRACT(o.result_json,'$.book_version_id')) AS UNSIGNED)=?
             ORDER BY o.id DESC LIMIT 1"
        );
        $stmt->execute(array($versionId));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row) {
            $result = $this->decode($row['result_json'] ?? null);
            if ((int)($result['book_version_id'] ?? 0) !== $versionId) {
                continue;
            }
            return array(
                'operation_id' => (int)$row['id'],
                'plan_id' => (int)$row['plan_id'],
                'plan_title' => (string)($row['plan_title'] ?? 'Manual Change Wizard'),
                'applied_at' => (string)($result['applied_at'] ?? $row['completed_at'] ?? ''),
                'affected_section_numbers' => (array)($result['affected_section_numbers'] ?? array()),
            );
        }
        return null;
    }

    /**
     * @return array<string,mixed>
     */
    public function undo(int $versionId, int $operationId, int $actorUserId): array
    {
        $this->pdo->beginTransaction();
        try {
            $operation = $this->latestRow(
                "SELECT * FROM ipca_manual_ai_architect_operations
                 WHERE id=? AND operation_type='apply_accepted_wizard_changes'
                   AND status='succeeded' FOR UPDATE",
                array($operationId)
            );
            $result = $this->decode($operation['result_json'] ?? null);
            if ((int)($result['book_version_id'] ?? 0) !== $versionId) {
                throw new RuntimeException('This Wizard edit does not belong to the open manual version.');
            }
            $this->lockEditableVersion($versionId);
            $before = (array)($result['before_sections'] ?? array());
            $after = (array)($result['after_sections'] ?? array());
            if ($before === array() || $after === array()) {
                throw new RuntimeException('The Wizard edit does not contain a complete recovery snapshot.');
            }

            $current = $this->snapshotsForSectionIds(array_map('intval', array_keys($after)));
            foreach ($after as $sectionId => $snapshot) {
                $actual = (array)($current[(int)$sectionId] ?? array());
                if (!hash_equals(
                    (string)($snapshot['fingerprint'] ?? ''),
                    (string)($actual['fingerprint'] ?? '')
                )) {
                    throw new RuntimeException(
                        'Undo refused: manual content changed after the Wizard application. '
                        . 'No later editor changes were overwritten.'
                    );
                }
            }

            foreach ($before as $sectionId => $snapshot) {
                $this->restoreSectionSnapshot((int)$sectionId, (array)$snapshot, $actorUserId);
            }
            (new ControlledPublishingRevisionService($this->pdo))->regenerateHighlightsSection(
                $versionId,
                $actorUserId
            );
            (new ControlledPublishingTocService($this->pdo))->regenerateTocSection(
                $versionId,
                $actorUserId
            );
            (new BooksManualsWorkflowService($this->pdo))->syncUpdateIdentity($versionId);
            $restored = $this->snapshotsForSectionIds(array_map('intval', array_keys($before)));
            $this->appendSectionEditEvents(
                (int)$operation['plan_id'],
                $operationId,
                'replace',
                $after,
                $restored,
                $actorUserId
            );
            $this->pdo->prepare(
                "UPDATE ipca_manual_ai_architect_operations
                 SET status='rolled_back' WHERE id=? AND status='succeeded'"
            )->execute(array($operationId));
            $this->plans->appendEvent((int)$operation['plan_id'], 'WIZARD_EDIT_UNDONE', 13, array(
                'operation_id' => $operationId,
                'book_version_id' => $versionId,
                'restored_section_ids' => array_map('intval', array_keys($before)),
                'restored_snapshot_fingerprint' => hash('sha256', $this->json($before)),
                'later_manual_edits_overwritten' => false,
            ), $actorUserId);
            $this->pdo->commit();
            $response = array(
                'book_version_id' => $versionId,
                'operation_id' => $operationId,
                'restored' => true,
            );
            $response['derived_refresh_warnings'] = $this->refreshPostCommitArtifacts(
                $versionId,
                $actorUserId,
                'manual_change_architect_undo'
            );
            $this->recordGlobalAudit(
                'manual_change_architect.undo_wizard_edit',
                (int)$operation['plan_id'],
                $operationId,
                $versionId,
                $actorUserId,
                $response
            );
            return $response;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param list<array<string,mixed>> $sectionDrafts
     * @return list<array<string,mixed>>
     */
    private function buildTargets(int $planId, int $versionId, array $sectionDrafts): array
    {
        $draftByNumber = array();
        foreach ($sectionDrafts as $draft) {
            $number = trim((string)($draft['section_number'] ?? ''));
            if ($number !== '') {
                $draftByNumber[$number] = $draft;
            }
        }
        $stmt = $this->pdo->prepare(
            "SELECT section_id,section_number,canonical_evidence_json
             FROM ipca_manual_ai_architect_impacts
             WHERE plan_id=? AND status='approved'
             ORDER BY id"
        );
        $stmt->execute(array($planId));
        $targets = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $impact) {
            $number = trim((string)$impact['section_number']);
            if (!isset($draftByNumber[$number])) {
                throw new RuntimeException("Accepted impact {$number} has no accepted wording.");
            }
            $evidence = $this->decode($impact['canonical_evidence_json'] ?? null);
            $sectionId = (int)($evidence['section_id'] ?? $impact['section_id'] ?? 0);
            $contextKey = trim((string)($evidence['context_key'] ?? 'section-' . $sectionId));
            $contextHash = trim((string)($evidence['context_hash'] ?? ''));
            if ($sectionId <= 0 || $contextHash === '') {
                throw new RuntimeException("Accepted impact {$number} lacks a canonical application boundary.");
            }
            $range = $this->resolveContextRange($versionId, $sectionId, $contextKey);
            $targets[] = array(
                'section_number' => $number,
                'section_id' => $sectionId,
                'context_key' => $contextKey,
                'context_hash' => $contextHash,
                'block_ids' => array_column($range, 'id'),
            );
            unset($draftByNumber[$number]);
        }
        if ($targets === array() || $draftByNumber !== array()) {
            throw new RuntimeException('Accepted wording and approved impact scope do not match exactly.');
        }
        return $targets;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function resolveContextRange(int $versionId, int $sectionId, string $contextKey): array
    {
        $section = $this->latestRow(
            'SELECT id FROM ipca_publishing_book_sections WHERE id=? AND book_version_id=? LIMIT 1',
            array($sectionId, $versionId)
        );
        if ((int)$section['id'] !== $sectionId) {
            throw new RuntimeException('A canonical target no longer belongs to the selected manual version.');
        }
        $blocks = (new ControlledPublishingBlockService($this->pdo))->listSectionBlocks($sectionId);
        if ($blocks === array()) {
            throw new RuntimeException('A canonical target section has no controlled blocks.');
        }
        if ($contextKey === 'section-' . $sectionId) {
            return $blocks;
        }

        $headings = array();
        foreach ($blocks as $index => $block) {
            $payload = $this->decode($block['payload_json'] ?? null);
            if ((string)($payload['paragraph_style'] ?? '') === 'subtitle_1') {
                $headings[] = $index;
            }
        }
        $suffix = 'section-' . $sectionId . '-subsection-';
        if (str_starts_with($contextKey, $suffix)) {
            $ordinal = (int)substr($contextKey, strlen($suffix));
            $index = $ordinal - 1;
            $start = $index >= 0 ? ($headings[$index] ?? null) : null;
            if ($start === null) {
                throw new RuntimeException('A canonical subsection boundary is no longer available.');
            }
            $next = $headings[$index + 1] ?? count($blocks);
            return array_values(array_slice($blocks, $start, $next - $start));
        }
        if ($contextKey === 'section-' . $sectionId . '-overview') {
            $end = $headings[0] ?? count($blocks);
            return array_values(array_slice($blocks, 0, $end));
        }
        throw new RuntimeException('Unsupported canonical amendment boundary.');
    }

    /**
     * @param list<array<string,mixed>> $targets
     * @return array<int,array<string,mixed>>
     */
    private function snapshotsForTargets(array $targets): array
    {
        return $this->snapshotsForSectionIds(array_values(array_unique(array_map(
            static fn(array $target): int => (int)$target['section_id'],
            $targets
        ))));
    }

    /**
     * @param list<int> $sectionIds
     * @return array<int,array<string,mixed>>
     */
    private function snapshotsForSectionIds(array $sectionIds): array
    {
        $snapshots = array();
        foreach ($sectionIds as $sectionId) {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM ipca_publishing_book_blocks
                 WHERE section_id=? ORDER BY sort_order,id'
            );
            $stmt->execute(array($sectionId));
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
            $snapshots[(int)$sectionId] = array(
                'section_id' => (int)$sectionId,
                'rows' => $rows,
                'fingerprint' => $this->sectionFingerprint($rows),
            );
        }
        return $snapshots;
    }

    /**
     * @param list<array<string,mixed>> $targets
     * @param array<int,array<string,mixed>> $snapshots
     */
    private function assertTargetsUnchanged(array $targets, array $snapshots): void
    {
        foreach ($targets as $target) {
            $snapshot = (array)($snapshots[(int)$target['section_id']] ?? array());
            $rows = (array)($snapshot['rows'] ?? array());
            $wanted = array_fill_keys(array_map('intval', (array)$target['block_ids']), true);
            $range = array_values(array_filter(
                $rows,
                static fn(array $row): bool => isset($wanted[(int)$row['id']])
            ));
            $contextHash = hash('sha256', $this->json(array_map(
                static fn(array $row): array => array((int)$row['id'], (string)$row['content_hash']),
                $range
            )));
            if (!hash_equals((string)$target['context_hash'], $contextHash)) {
                throw new RuntimeException(
                    'The selected manual changed after impact analysis. Re-analyze before applying Wizard changes.'
                );
            }
        }
    }

    /**
     * @param list<array<string,mixed>> $targets
     * @param list<array<string,mixed>> $sectionDrafts
     * @param array<string,string> $structureTitles
     */
    private function replaceTargetRanges(
        int $planId,
        int $versionId,
        array $targets,
        array $sectionDrafts,
        array $structureTitles,
        int $actorUserId
    ): void {
        $draftByNumber = array();
        foreach ($sectionDrafts as $draft) {
            $draftByNumber[(string)$draft['section_number']] = $draft;
        }
        $targetsByPhysicalSection = array();
        foreach ($targets as $target) {
            $targetsByPhysicalSection[(int)$target['section_id']][] = $target;
        }
        foreach ($targetsByPhysicalSection as $sectionId => $sectionTargets) {
            $blocks = (new ControlledPublishingBlockService($this->pdo))->listSectionBlocks($sectionId);
            $targetAtFirstId = array();
            $targetedIds = array();
            foreach ($sectionTargets as $target) {
                $ids = array_map('intval', (array)$target['block_ids']);
                $draft = (array)$draftByNumber[(string)$target['section_number']];
                $target['replacement_entries'] = $this->assignExistingBlockIdentities(
                    $this->generatedParagraphs($draft, $structureTitles),
                    $ids
                );
                $targetAtFirstId[$ids[0]] = $target;
                foreach ($ids as $id) {
                    $targetedIds[$id] = true;
                }
            }

            $final = array();
            foreach ($blocks as $block) {
                $id = (int)$block['id'];
                if (isset($targetAtFirstId[$id])) {
                    $target = $targetAtFirstId[$id];
                    foreach ((array)$target['replacement_entries'] as $generated) {
                        $final[] = $generated;
                    }
                }
                if (!isset($targetedIds[$id])) {
                    $final[] = array('existing_id' => $id);
                }
            }
            if ($final === array()) {
                throw new RuntimeException('Wizard application would leave a controlled section empty.');
            }
            $reusedIds = array();
            foreach ($final as $entry) {
                if (isset($entry['reused_id'])) {
                    $reusedIds[(int)$entry['reused_id']] = true;
                }
            }
            $idsToDelete = array_values(array_filter(
                array_keys($targetedIds),
                static fn(int $id): bool => !isset($reusedIds[$id])
            ));
            if ($idsToDelete !== array()) {
                $placeholders = implode(',', array_fill(0, count($idsToDelete), '?'));
                $this->pdo->prepare(
                    "DELETE FROM ipca_publishing_book_blocks WHERE section_id=? AND id IN ({$placeholders})"
                )->execute(array_merge(array($sectionId), $idsToDelete));
            }

            foreach ($final as $index => &$entry) {
                if (isset($entry['existing_id'])) {
                    continue;
                }
                $payloadJson = $this->json($entry['payload']);
                $hashPayload = json_encode(
                    $entry['payload'],
                    JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                );
                $contentHash = hash('sha256', 'paragraph|' . $hashPayload);
                if (isset($entry['reused_id'])) {
                    $this->pdo->prepare(
                        "UPDATE ipca_publishing_book_blocks
                         SET block_type='paragraph',payload_json=?,content_hash=?,
                             is_system_managed=0,updated_by=?,updated_at=CURRENT_TIMESTAMP
                         WHERE id=? AND section_id=? AND book_version_id=?"
                    )->execute(array(
                        $payloadJson,
                        $contentHash,
                        $actorUserId,
                        (int)$entry['reused_id'],
                        $sectionId,
                        $versionId,
                    ));
                    $entry = array('existing_id' => (int)$entry['reused_id']);
                    continue;
                }
                $identity = 'WIZ-' . $planId . '-' . $this->slug((string)$entry['identity'])
                    . '-' . str_pad((string)($index + 1), 3, '0', STR_PAD_LEFT);
                $stmt = $this->pdo->prepare(
                    'INSERT INTO ipca_publishing_book_blocks
                     (book_version_id,section_id,block_key,stable_anchor,block_type,sort_order,
                      payload_json,content_hash,is_system_managed,created_by,updated_by)
                     VALUES (?,?,?,?,\'paragraph\',?,?,?,?,?,?)'
                );
                $stmt->execute(array(
                    $versionId,
                    $sectionId,
                    $identity,
                    $identity,
                    100000 + $index,
                    $payloadJson,
                    $contentHash,
                    0,
                    $actorUserId,
                    $actorUserId,
                ));
                $entry = array('existing_id' => (int)$this->pdo->lastInsertId());
            }
            unset($entry);

            $updateSort = $this->pdo->prepare(
                'UPDATE ipca_publishing_book_blocks
                 SET sort_order=?,updated_by=?,updated_at=CURRENT_TIMESTAMP
                 WHERE id=? AND section_id=?'
            );
            foreach ($final as $index => $entry) {
                $updateSort->execute(array(
                    ($index + 1) * 10,
                    $actorUserId,
                    (int)$entry['existing_id'],
                    $sectionId,
                ));
            }
        }
    }

    /**
     * Preserve source block identities required by immutable Architect evidence.
     *
     * @param list<array{identity:string,payload:array<string,mixed>}> $generated
     * @param list<int> $sourceIds
     * @return list<array<string,mixed>>
     */
    private function assignExistingBlockIdentities(array $generated, array $sourceIds): array
    {
        if ($sourceIds === array()) {
            return $generated;
        }
        $placeholders = implode(',', array_fill(0, count($sourceIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT block_id FROM ipca_manual_ai_architect_legacy_hits
             WHERE block_id IN ({$placeholders}) ORDER BY block_id"
        );
        $stmt->execute($sourceIds);
        $required = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: array());
        if (count($required) > count($generated)) {
            throw new RuntimeException(
                'The accepted replacement cannot preserve all governed source block identities.'
            );
        }
        $selected = array_fill_keys($required, true);
        foreach ($sourceIds as $sourceId) {
            if (count($selected) >= min(count($sourceIds), count($generated))) {
                break;
            }
            $selected[(int)$sourceId] = true;
        }
        $orderedSelected = array_values(array_filter(
            $sourceIds,
            static fn(int $id): bool => isset($selected[$id])
        ));
        foreach ($generated as $index => &$entry) {
            if (isset($orderedSelected[$index])) {
                $entry['reused_id'] = (int)$orderedSelected[$index];
            }
        }
        unset($entry);
        return $generated;
    }

    /**
     * @return list<array{identity:string,payload:array<string,mixed>}>
     */
    private function generatedParagraphs(array $draft, array $structureTitles): array
    {
        $nodes = (array)($draft['nodes'] ?? array());
        if ($nodes === array()) {
            throw new RuntimeException('An accepted amendment contains no complete resulting content.');
        }
        $paragraphs = array();
        foreach ($nodes as $number => $content) {
            $number = trim((string)$number);
            $content = trim((string)$content);
            $title = trim((string)($structureTitles[$number] ?? ''));
            if ($title === '' && $number === (string)($draft['section_number'] ?? '')) {
                $title = trim((string)($draft['section_title'] ?? ''));
            }
            if ($number === '' || $title === '' || $content === '') {
                throw new RuntimeException('Accepted Wizard wording contains an incomplete manual node.');
            }
            $depth = max(1, substr_count($number, '.'));
            $paragraphs[] = array(
                'identity' => (string)($draft['section_number'] ?? '') . '-heading-' . $number,
                'payload' => $this->paragraphPayload(
                    trim($number . ' ' . preg_replace('/^\s*' . preg_quote($number, '/') . '\s*/', '', $title)),
                    $depth <= 1 ? 'subtitle_1' : 'subtitle_2'
                ),
            );
            $paragraphs[] = array(
                'identity' => (string)($draft['section_number'] ?? '') . '-body-' . $number,
                'payload' => $this->paragraphPayload($content, 'body'),
            );
        }
        return $paragraphs;
    }

    /**
     * @return array<string,string>
     */
    private function structureTitles(int $planId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT n.title
             FROM ipca_manual_ai_architect_structure_nodes n
             JOIN ipca_manual_ai_architect_structure_proposals p
               ON p.id=n.structure_proposal_id
             WHERE p.plan_id=? AND p.status='approved'
               AND n.decision_status='accepted'
             ORDER BY n.sort_order,n.id"
        );
        $stmt->execute(array($planId));
        $titles = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row) {
            $fullTitle = trim((string)($row['title'] ?? ''));
            if (preg_match('/^(\d+(?:\.\d+)*)\s+(.+)$/u', $fullTitle, $match) !== 1) {
                continue;
            }
            $titles[(string)$match[1]] = trim((string)$match[2]);
        }
        if ($titles === array()) {
            throw new RuntimeException('The accepted structure node titles are unavailable.');
        }
        return $titles;
    }

    /**
     * @return array<string,mixed>
     */
    private function paragraphPayload(string $text, string $style): array
    {
        $lines = preg_split('/\R/u', trim($text)) ?: array();
        $html = '';
        $listOpen = false;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                if ($listOpen) {
                    $html .= '</ul>';
                    $listOpen = false;
                }
                continue;
            }
            if (str_starts_with($line, '• ')) {
                if (!$listOpen) {
                    $html .= '<ul>';
                    $listOpen = true;
                }
                $html .= '<li>' . htmlspecialchars(mb_substr($line, 2), ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</li>';
                continue;
            }
            if ($listOpen) {
                $html .= '</ul>';
                $listOpen = false;
            }
            $html .= '<p>' . htmlspecialchars($line, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>';
        }
        if ($listOpen) {
            $html .= '</ul>';
        }
        return array(
            'html' => $html,
            'text_align' => 'left',
            'indent_level' => 0,
            'paragraph_style' => $style,
        );
    }

    private function restoreSectionSnapshot(int $sectionId, array $snapshot, int $actorUserId): void
    {
        $rows = (array)($snapshot['rows'] ?? array());
        $beforeById = array();
        foreach ($rows as $row) {
            $beforeById[(int)$row['id']] = $row;
        }
        $stmt = $this->pdo->prepare(
            'SELECT id FROM ipca_publishing_book_blocks WHERE section_id=?'
        );
        $stmt->execute(array($sectionId));
        $currentIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: array());
        $addedIds = array_values(array_filter(
            $currentIds,
            static fn(int $id): bool => !isset($beforeById[$id])
        ));
        if ($addedIds !== array()) {
            $placeholders = implode(',', array_fill(0, count($addedIds), '?'));
            $this->pdo->prepare(
                "DELETE FROM ipca_publishing_book_blocks
                 WHERE section_id=? AND id IN ({$placeholders})"
            )->execute(array_merge(array($sectionId), $addedIds));
        }
        if ($rows === array()) {
            return;
        }
        $columns = array_keys((array)$rows[0]);
        $quoted = implode(',', array_map(
            static fn(string $column): string => '`' . str_replace('`', '``', $column) . '`',
            $columns
        ));
        $placeholders = implode(',', array_fill(0, count($columns), '?'));
        $stmt = $this->pdo->prepare(
            "INSERT INTO ipca_publishing_book_blocks ({$quoted}) VALUES ({$placeholders})"
        );
        $updateColumns = array_values(array_filter(
            $columns,
            static fn(string $column): bool => $column !== 'id'
        ));
        $assignments = implode(',', array_map(
            static fn(string $column): string =>
                '`' . str_replace('`', '``', $column) . '`=?',
            $updateColumns
        ));
        $update = $this->pdo->prepare(
            "UPDATE ipca_publishing_book_blocks SET {$assignments} WHERE id=?"
        );
        $current = array_fill_keys($currentIds, true);
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            if (isset($current[$id])) {
                $values = array();
                foreach ($updateColumns as $column) {
                    $values[] = $row[$column] ?? null;
                }
                $values[] = $id;
                $update->execute($values);
                continue;
            }
            $values = array();
            foreach ($columns as $column) {
                $values[] = $row[$column] ?? null;
            }
            $stmt->execute($values);
        }
    }

    /**
     * @param array<int,array<string,mixed>> $before
     * @param array<int,array<string,mixed>> $after
     */
    private function appendSectionEditEvents(
        int $planId,
        int $operationId,
        string $operation,
        array $before,
        array $after,
        int $actorUserId
    ): void {
        $sectionIds = array_values(array_unique(array_merge(
            array_map('intval', array_keys($before)),
            array_map('intval', array_keys($after))
        )));
        foreach ($sectionIds as $sectionId) {
            $priorHash = (string)($before[$sectionId]['fingerprint'] ?? '');
            $newHash = (string)($after[$sectionId]['fingerprint'] ?? '');
            $patch = array(
                'operation_id' => $operationId,
                'section_id' => $sectionId,
                'prior_fingerprint' => $priorHash,
                'new_fingerprint' => $newHash,
            );
            $fingerprint = hash('sha256', $this->json(array(
                'plan_id' => $planId,
                'operation_id' => $operationId,
                'section_id' => $sectionId,
                'operation' => $operation,
                'prior' => $priorHash,
                'new' => $newHash,
            )));
            $this->plans->save('edit_events', $planId, array(
                'event_uuid' => $this->uuid(),
                'aggregate_type' => 'publishing_section',
                'aggregate_id' => $sectionId,
                'field_path' => 'blocks',
                'operation' => $operation,
                'prior_value_hash' => $priorHash !== '' ? $priorHash : null,
                'new_value_hash' => $newHash !== '' ? $newHash : null,
                'patch_json' => $this->json($patch),
                'event_fingerprint' => $fingerprint,
                'actor_id' => $actorUserId,
            ));
        }
    }

    /**
     * Derived asynchronous work is best-effort after the content transaction.
     *
     * @return list<string>
     */
    private function refreshPostCommitArtifacts(
        int $versionId,
        int $actorUserId,
        string $mutationKind
    ): array {
        $warnings = array();
        try {
            (new ControlledPublishingLivePageMapService($this->pdo))->ensure(
                $versionId,
                $actorUserId,
                null,
                array('mutation_kind' => $mutationKind, 'layout_impact' => 'global')
            );
        } catch (Throwable $e) {
            error_log('Wizard pagination refresh failed: ' . $e->getMessage());
            $warnings[] = 'Pagination refresh: ' . $e->getMessage();
        }
        try {
            (new BooksManualsAuditService($this->pdo))->startIntegrityRefresh(
                $versionId,
                $actorUserId
            );
        } catch (Throwable $e) {
            error_log('Wizard MCCF refresh failed: ' . $e->getMessage());
            $warnings[] = 'MCCF refresh: ' . $e->getMessage();
        }
        return $warnings;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function recordGlobalAudit(
        string $eventType,
        int $planId,
        int $operationId,
        int $versionId,
        int $actorUserId,
        array $payload
    ): void {
        try {
            (new AuditEventService($this->pdo))->record(
                $eventType,
                'manual_change_plan',
                (string)$planId,
                null,
                array(
                    'operation_id' => $operationId,
                    'book_version_id' => $versionId,
                    'revision_created' => false,
                    'revision_number_changed' => false,
                    'lifecycle_changed' => false,
                    'payload_fingerprint' => hash('sha256', $this->json($payload)),
                ),
                $eventType === 'manual_change_architect.apply_in_place'
                    ? 'Accepted Wizard changes applied in place.'
                    : 'Wizard edit restored to its pre-application state.',
                'user',
                $actorUserId,
                null,
                null,
                1,
                'books_manuals_change_architect'
            );
        } catch (Throwable $e) {
            error_log('Wizard global audit recording failed: ' . $e->getMessage());
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function lockEditableVersion(int $versionId): array
    {
        $version = $this->latestRow(
            'SELECT * FROM ipca_publishing_book_versions WHERE id=? FOR UPDATE',
            array($versionId)
        );
        if (!in_array((string)($version['lifecycle_status'] ?? ''), array('draft', 'in_review'), true)) {
            throw new RuntimeException('Wizard changes may only be applied to Draft or Draft Review manuals.');
        }
        return $version;
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function sectionFingerprint(array $rows): string
    {
        return hash('sha256', $this->json(array_map(
            static fn(array $row): array => array(
                (int)$row['id'],
                (string)$row['block_key'],
                (string)$row['stable_anchor'],
                (string)$row['block_type'],
                (int)$row['sort_order'],
                (string)$row['payload_json'],
                (string)$row['content_hash'],
                (int)$row['is_system_managed'],
            ),
            $rows
        )));
    }

    /**
     * @return array<string,mixed>|null
     */
    private function latestRow(string $sql, array $params, bool $required = true): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            if ($required) {
                throw new RuntimeException('The governed Wizard package is incomplete.');
            }
            return null;
        }
        return $row;
    }

    /**
     * @return array<string,mixed>
     */
    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return array();
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : array();
    }

    private function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    private function slug(string $value): string
    {
        $value = strtoupper((string)preg_replace('/[^A-Za-z0-9]+/', '-', $value));
        return trim($value, '-');
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
