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
        $lock = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? ''
            : ' FOR UPDATE';

        // A successful operation is the authoritative idempotent response.
        // Check it before rebuilding mutable preflight state, because a real
        // application necessarily changes the original section fingerprints.
        $this->pdo->beginTransaction();
        try {
            $lockedPlan = $this->latestRow(
                'SELECT owner_id,stage FROM ipca_manual_ai_architect_plans
                 WHERE id=?' . $lock,
                array($planId)
            );
            if ((int)($lockedPlan['owner_id'] ?? 0) !== $actorUserId
                || (string)($lockedPlan['stage'] ?? '') !== 'operations') {
                throw new RuntimeException(
                    'The Change Plan changed before the Wizard replay was checked.'
                );
            }
            $versionLock = $this->latestRow(
                'SELECT id FROM ipca_publishing_book_versions WHERE id=?' . $lock,
                array($versionId)
            );
            if ((int)($versionLock['id'] ?? 0) !== $versionId) {
                throw new RuntimeException('The Wizard application manual version is unavailable.');
            }
            $succeeded = $this->latestRow(
                "SELECT * FROM ipca_manual_ai_architect_operations
                 WHERE plan_id=? AND operation_type='apply_accepted_wizard_changes'
                   AND status='succeeded'
                 ORDER BY id DESC LIMIT 1" . $lock,
                array($planId),
                false
            );
            if ($succeeded !== null) {
                $result = $this->decode($succeeded['result_json'] ?? null);
                if ((int)($result['book_version_id'] ?? 0) !== $versionId) {
                    throw new RuntimeException(
                        'The completed Wizard application has invalid version provenance.'
                    );
                }
                $this->pdo->commit();
                $result['derived_refresh_warnings'] = $this->refreshPostCommitArtifacts(
                    $versionId,
                    $actorUserId,
                    'manual_change_architect_apply_replay'
                );
                return $result;
            }
            $this->pdo->commit();
        } catch (Throwable $replayError) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $replayError;
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
        if (strtoupper((string)($reviewPayload['status'] ?? '')) !== 'READY'
            || (string)($reviewPayload['outcome'] ?? '') !== 'READY_TO_APPLY') {
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
        $operationPayload = $this->json(array(
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
        ));

        $this->pdo->beginTransaction();
        try {
            $lockedPlan = $this->latestRow(
                'SELECT owner_id,stage FROM ipca_manual_ai_architect_plans
                 WHERE id=?' . $lock,
                array($planId)
            );
            if ((int)($lockedPlan['owner_id'] ?? 0) !== $actorUserId
                || (string)($lockedPlan['stage'] ?? '') !== 'operations') {
                throw new RuntimeException(
                    'The Change Plan changed before the Wizard application was claimed.'
                );
            }
            // Lock in the same order as the mutation transaction. If an older
            // worker is active, this waits for its definitive operation status.
            $this->lockEditableVersion($versionId);
            $operationsStmt = $this->pdo->prepare(
                "SELECT * FROM ipca_manual_ai_architect_operations
                 WHERE plan_id=? AND operation_type='apply_accepted_wizard_changes'
                 ORDER BY id DESC" . $lock
            );
            $operationsStmt->execute(array($planId));
            $priorOperations = $operationsStmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
            foreach ($priorOperations as $priorOperation) {
                $priorStatus = (string)($priorOperation['status'] ?? '');
                if ($priorStatus === 'succeeded') {
                    $result = $this->decode($priorOperation['result_json'] ?? null);
                    $this->pdo->commit();
                    $result['derived_refresh_warnings'] = $this->refreshPostCommitArtifacts(
                        $versionId,
                        $actorUserId,
                        'manual_change_architect_apply_replay'
                    );
                    return $result;
                }
                if ($priorStatus !== 'running') {
                    continue;
                }
                $startedRaw = trim((string)($priorOperation['started_at'] ?? ''));
                $startedAt = $startedRaw === ''
                    ? false
                    : strtotime($startedRaw . ' UTC');
                if ($startedAt !== false && $startedAt > time() - 300) {
                    throw new RuntimeException('This Wizard application is already running.');
                }
                $this->pdo->prepare(
                    "UPDATE ipca_manual_ai_architect_operations
                     SET status='failed',
                         error_message='Stale Wizard application claim recovered safely.',
                         completed_at=CURRENT_TIMESTAMP
                     WHERE id=? AND status='running'"
                )->execute(array((int)$priorOperation['id']));
                $this->plans->appendEvent(
                    $planId,
                    'WIZARD_APPLICATION_STALE_CLAIM_RECOVERED',
                    13,
                    array(
                        'operation_id' => (int)$priorOperation['id'],
                        'stale_after_seconds' => 300,
                        'manual_content_mutated' => false,
                    ),
                    $actorUserId
                );
            }
            $attemptNumber = count($priorOperations) + 1;
            $operationId = $this->plans->save('operations', $planId, array(
                'operation_uuid' => $this->uuid(),
                'draft_id' => (int)$draft['id'],
                'review_id' => (int)$review['id'],
                'operation_type' => 'apply_accepted_wizard_changes',
                'idempotency_key' => hash(
                    'sha256',
                    'wizard-apply|' . $planId . '|' . $requestFingerprint
                        . '|attempt:' . $attemptNumber
                ),
                'status' => 'running',
                'request_fingerprint' => $requestFingerprint,
                'operation_payload_json' => $operationPayload,
                'requested_by' => $actorUserId,
                'started_at' => gmdate('Y-m-d H:i:s.v'),
            ));
            $this->pdo->commit();
        } catch (Throwable $claimError) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $claimError;
        }

        try {
            $this->pdo->beginTransaction();
            $mutationPlan = $this->latestRow(
                'SELECT owner_id,stage FROM ipca_manual_ai_architect_plans
                 WHERE id=?' . $lock,
                array($planId)
            );
            if ((int)($mutationPlan['owner_id'] ?? 0) !== $actorUserId
                || (string)($mutationPlan['stage'] ?? '') !== 'operations') {
                throw new RuntimeException(
                    'The Change Plan changed before the Wizard content mutation.'
                );
            }
            $version = $this->lockEditableVersion($versionId);
            $claimedOperation = $this->latestRow(
                "SELECT id,status,request_fingerprint
                 FROM ipca_manual_ai_architect_operations WHERE id=?" . $lock,
                array($operationId)
            );
            if ((string)($claimedOperation['status'] ?? '') !== 'running'
                || !hash_equals(
                    $requestFingerprint,
                    (string)($claimedOperation['request_fingerprint'] ?? '')
                )) {
                throw new RuntimeException(
                    'The Wizard application claim expired before content mutation.'
                );
            }
            $lockedPreflightPackage = $this->buildPreflightPackage($planId, true);
            if (!hash_equals(
                (string)($reviewedPackage['package_fingerprint'] ?? ''),
                (string)($lockedPreflightPackage['package_fingerprint'] ?? '')
            )) {
                throw new RuntimeException(
                    'The independently reviewed operation package changed before mutation.'
                );
            }
            $before = $this->snapshotsForTargets($targets, true);
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
                'review_guidance' => $this->reviewGuidance($reviewPayload),
                'applied_at' => gmdate(DATE_ATOM),
                'applied_by' => $actorUserId,
                'undo_available' => true,
            );
            $resultFingerprint = hash('sha256', $this->json($result));
            $this->pdo->prepare(
                "UPDATE ipca_manual_ai_architect_operations
                 SET status='succeeded',result_json=?,result_fingerprint=?,
                     completed_at=CURRENT_TIMESTAMP
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
                 SET status='failed',error_message=?,completed_at=CURRENT_TIMESTAMP
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
    public function buildPreflightPackage(int $planId, bool $forUpdate = false): array
    {
        if ($forUpdate && !$this->pdo->inTransaction()) {
            throw new RuntimeException('A locked operation package requires an active transaction.');
        }
        $lockClause = $forUpdate
            && (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite'
            ? ' FOR UPDATE'
            : '';
        if ($forUpdate) {
            $this->latestRow(
                'SELECT id FROM ipca_manual_ai_architect_plans WHERE id=?' . $lockClause,
                array($planId)
            );
        }
        $plan = $this->plans->getPlan($planId);
        $versionId = $this->plans->primaryVersionId($plan);
        if ($versionId <= 0) {
            throw new RuntimeException('The Change Plan does not identify its original manual version.');
        }
        $version = $this->latestRow(
            'SELECT id,version_label,lifecycle_status
             FROM ipca_publishing_book_versions WHERE id=?' . $lockClause,
            array($versionId)
        );
        if (!in_array((string)($version['lifecycle_status'] ?? ''), array('draft', 'in_review'), true)) {
            throw new RuntimeException('Wizard changes may only target a Draft or Draft Review manual.');
        }
        $draft = $this->latestRow(
            "SELECT id,content_fingerprint,draft_payload_json
             FROM ipca_manual_ai_architect_drafts
             WHERE id=(
                 SELECT MAX(id) FROM ipca_manual_ai_architect_drafts
                 WHERE plan_id=? AND status='generated'
             )"
                . $lockClause,
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
        $decisionRows = (array)($proposal['decisions'] ?? array());
        foreach ((array)($proposal['section_drafts'] ?? array()) as $sectionNumber => $_section) {
            if ((string)($decisionRows[$sectionNumber]['decision'] ?? '') !== 'accepted') {
                throw new RuntimeException(
                    'Every independently reviewed amendment must remain individually accepted.'
                );
            }
        }
        $targets = $this->buildTargets($planId, $versionId, $sectionDrafts, $forUpdate);
        $snapshots = $this->snapshotsForTargets($targets, $forUpdate);
        $this->assertTargetsUnchanged($targets, $snapshots);
        $titles = $this->structureTitles($planId, $forUpdate);
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
     * Return the latest Wizard application as independently reversible Editor
     * items. An item is one physical controlled section; reverting it never
     * restores or overwrites another affected section.
     *
     * @return array<string,mixed>|null
     */
    public function editorChanges(int $versionId, ?int $actorUserId = null): ?array
    {
        $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = "SELECT o.*,p.title plan_title,p.owner_id plan_owner_id
                FROM ipca_manual_ai_architect_operations o
                JOIN ipca_manual_ai_architect_plans p ON p.id=o.plan_id
                WHERE o.operation_type='apply_accepted_wizard_changes'
                  AND o.status='succeeded'";
        $params = array();
        if ($driver === 'mysql') {
            $sql .= " AND CAST(JSON_UNQUOTE(JSON_EXTRACT(
                          o.result_json,'$.book_version_id'
                      )) AS UNSIGNED)=?";
            $params[] = $versionId;
        }
        $sql .= ' ORDER BY o.id DESC';
        if ($driver === 'mysql') {
            $sql .= ' LIMIT 100';
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $applications = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row) {
            $candidate = $this->decode($row['result_json'] ?? null);
            if ((int)($candidate['book_version_id'] ?? 0) === $versionId) {
                $before = (array)($candidate['before_sections'] ?? array());
                $after = (array)($candidate['after_sections'] ?? array());
                if ($before !== array() && $after !== array()) {
                    $applications[] = array(
                        'operation' => $row,
                        'result' => $candidate,
                        'payload' => $this->decode($row['operation_payload_json'] ?? null),
                        'before' => $before,
                        'after' => $after,
                    );
                }
            }
        }
        if ($applications === array()) {
            return null;
        }

        $planIds = array_values(array_unique(array_map(
            static fn(array $application): int =>
                (int)$application['operation']['plan_id'],
            $applications
        )));
        $revertedItems = array();
        if ($planIds !== array()) {
            $placeholders = implode(',', array_fill(0, count($planIds), '?'));
            $revertStmt = $this->pdo->prepare(
                "SELECT operation_payload_json
                 FROM ipca_manual_ai_architect_operations
                 WHERE plan_id IN ({$placeholders})
                   AND operation_type='revert_wizard_change_item'
                   AND status='succeeded' ORDER BY id"
            );
            $revertStmt->execute($planIds);
            foreach ($revertStmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $revertRow) {
                $revertPayload = $this->decode($revertRow['operation_payload_json'] ?? null);
                $key = (int)($revertPayload['original_operation_id'] ?? 0)
                    . '-' . (int)($revertPayload['section_id'] ?? 0);
                $revertedItems[$key] = true;
            }
        }

        $sectionIds = array();
        foreach ($applications as $application) {
            foreach (array_keys((array)$application['after']) as $sectionId) {
                $sectionIds[(int)$sectionId] = true;
            }
        }
        $sectionIds = array_keys($sectionIds);
        $current = $this->snapshotsForSectionIds($sectionIds);
        $version = $this->latestRow(
            'SELECT lifecycle_status FROM ipca_publishing_book_versions WHERE id=?',
            array($versionId)
        );
        $editable = in_array(
            (string)($version['lifecycle_status'] ?? ''),
            array('draft', 'in_review'),
            true
        );
        $titleStmt = $this->pdo->prepare(
            'SELECT title FROM ipca_publishing_book_sections WHERE id=? AND book_version_id=?'
        );
        $items = array();
        $guidance = array();
        $applicationSummaries = array();
        $newerSectionSeen = array();
        foreach ($applications as $application) {
            $operation = (array)$application['operation'];
            $result = (array)$application['result'];
            $before = (array)$application['before'];
            $after = (array)$application['after'];
            $numbersBySection = array();
            foreach ((array)($application['payload']['target_contexts'] ?? array()) as $target) {
                if (!is_array($target)) {
                    continue;
                }
                $targetSectionId = (int)($target['section_id'] ?? 0);
                $number = trim((string)($target['section_number'] ?? ''));
                if ($targetSectionId > 0 && $number !== '') {
                    $numbersBySection[$targetSectionId][$number] = true;
                }
            }
            $applicationSummaries[] = array(
                'operation_id' => (int)$operation['id'],
                'plan_id' => (int)$operation['plan_id'],
                'plan_title' => (string)($operation['plan_title'] ?? 'Manual Change Wizard'),
                'applied_at' => (string)($result['applied_at'] ?? $operation['completed_at'] ?? ''),
            );
            foreach ((array)($result['review_guidance'] ?? array()) as $note) {
                if (!is_array($note)
                    || (array_key_exists('show_in_editor', $note) && empty($note['show_in_editor']))) {
                    continue;
                }
                $note['operation_id'] = (int)$operation['id'];
                $note['plan_id'] = (int)$operation['plan_id'];
                $note['plan_title'] = (string)($operation['plan_title'] ?? 'Manual Change Wizard');
                $guidanceKey = 'answer:' . (int)($note['answer_id'] ?? 0);
                if (!isset($guidance[$guidanceKey])) {
                    $guidance[$guidanceKey] = $note;
                }
            }
            foreach (array_keys($after) as $rawSectionId) {
                $sectionId = (int)$rawSectionId;
                $titleStmt->execute(array($sectionId, $versionId));
                $title = (string)($titleStmt->fetchColumn() ?: 'Manual section');
                $changeId = (int)$operation['id'] . '-' . $sectionId;
                $reverted = isset($revertedItems[$changeId]);
                $superseded = isset($newerSectionSeen[$sectionId]);
                $afterFingerprint = (string)($after[$sectionId]['fingerprint'] ?? '');
                $currentFingerprint = (string)($current[$sectionId]['fingerprint'] ?? '');
                $modified = !$reverted && !$superseded && ($afterFingerprint === ''
                    || !hash_equals($afterFingerprint, $currentFingerprint));
                $ownedByActor = $actorUserId === null
                    || (int)($operation['plan_owner_id'] ?? 0) === $actorUserId;
                $numbers = array_keys((array)($numbersBySection[$sectionId] ?? array()));
                sort($numbers, SORT_NATURAL);
                $differencePreviews = $this->snapshotDifferencePreviews(
                    (array)$before[$sectionId],
                    (array)$after[$sectionId]
                );
                $items[] = array(
                    'change_id' => $changeId,
                    'operation_id' => (int)$operation['id'],
                    'plan_id' => (int)$operation['plan_id'],
                    'plan_title' => (string)($operation['plan_title'] ?? 'Manual Change Wizard'),
                    'applied_at' => (string)($result['applied_at'] ?? $operation['completed_at'] ?? ''),
                    'section_id' => $sectionId,
                    'section_numbers' => $numbers,
                    'title' => $title,
                    'status' => $superseded
                        ? 'SUPERSEDED'
                        : ($reverted ? 'REVERTED' : ($modified ? 'MANUALLY_EDITED' : 'APPLIED')),
                    'manual_edits_detected' => $modified,
                    'can_revert' => $editable && $ownedByActor && !$reverted && !$superseded,
                    'current_fingerprint' => $currentFingerprint,
                    'original_preview' => $differencePreviews['before'],
                    'applied_preview' => $differencePreviews['after'],
                );
                if (!$reverted) {
                    $newerSectionSeen[$sectionId] = true;
                }
            }
        }
        $latest = (array)$applications[0];
        $latestOperation = (array)$latest['operation'];
        $latestResult = (array)$latest['result'];
        return array(
            'operation_id' => (int)$latestOperation['id'],
            'plan_id' => (int)$latestOperation['plan_id'],
            'plan_title' => count($applications) === 1
                ? (string)($latestOperation['plan_title'] ?? 'Manual Change Wizard')
                : 'Manual Change Wizard history',
            'applied_at' => (string)(
                $latestResult['applied_at']
                ?? $latestOperation['completed_at']
                ?? ''
            ),
            'applications' => $applicationSummaries,
            'items' => $items,
            'review_guidance' => array_values($guidance),
        );
    }

    /**
     * Carry governed Independent Review answers into the Editor without
     * generating or applying another Author patch.
     *
     * @return list<array<string,mixed>>
     */
    private function reviewGuidance(array $reviewPayload): array
    {
        $state = (array)($reviewPayload['review_state'] ?? array());
        $guidance = array();
        foreach ((array)($state['answers'] ?? array()) as $answer) {
            if (!is_array($answer)) {
                continue;
            }
            $question = is_array($answer['question_snapshot_json'] ?? null)
                ? $answer['question_snapshot_json']
                : $this->decode($answer['question_snapshot_json'] ?? null);
            $selected = is_array($answer['selected_answer_json'] ?? null)
                ? $answer['selected_answer_json']
                : $this->decode($answer['selected_answer_json'] ?? null);
            $fact = is_array($answer['governed_fact_json'] ?? null)
                ? $answer['governed_fact_json']
                : $this->decode($answer['governed_fact_json'] ?? null);
            $sections = is_array($answer['affected_sections_json'] ?? null)
                ? $answer['affected_sections_json']
                : $this->decode($answer['affected_sections_json'] ?? null);
            $consequence = (string)($answer['consequence'] ?? '');
            $checkIds = array_values(array_unique(array_map(
                'strval',
                (array)($fact['check_ids'] ?? array())
            )));
            sort($checkIds, SORT_STRING);
            $guidanceKey = 'answer:' . (int)($answer['id'] ?? 0);
            $guidance[$guidanceKey] = array(
                'answer_id' => (int)($answer['id'] ?? 0),
                'question_id' => (int)($answer['question_id'] ?? 0),
                'title' => (string)($question['title'] ?? 'Independent Review guidance'),
                'prompt' => (string)($question['prompt'] ?? ''),
                'selected_labels' => array_values(array_filter(array_map(
                    static fn(mixed $choice): string =>
                        is_array($choice) ? (string)($choice['label'] ?? '') : '',
                    (array)$selected
                ))),
                'instruction' => trim((string)($answer['explanation'] ?? '')),
                'governed_fact' => (string)($fact['statement'] ?? ''),
                'check_ids' => $checkIds,
                'affected_sections' => array_values(array_map('strval', (array)$sections)),
                'consequence' => $consequence,
                'show_in_editor' => true,
                'advisory' => true,
                'editor_action_required' => false,
                'manual_change_indicated' => $consequence !== 'NO_MANUAL_CHANGE_REQUIRED',
            );
        }
        return array_values($guidance);
    }

    /**
     * @return array<string,mixed>
     */
    public function undo(int $versionId, int $operationId, int $actorUserId): array
    {
        $operationReference = $this->latestRow(
            "SELECT plan_id,result_json FROM ipca_manual_ai_architect_operations
             WHERE id=? AND operation_type='apply_accepted_wizard_changes'",
            array($operationId)
        );
        $referenceResult = $this->decode($operationReference['result_json'] ?? null);
        if ((int)($referenceResult['book_version_id'] ?? 0) !== $versionId) {
            throw new RuntimeException('This Wizard edit does not belong to the open manual version.');
        }
        $this->pdo->beginTransaction();
        try {
            $lock = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
                ? ''
                : ' FOR UPDATE';
            $planOwner = $this->latestRow(
                'SELECT owner_id FROM ipca_manual_ai_architect_plans WHERE id=?' . $lock,
                array((int)$operationReference['plan_id'])
            );
            if ((int)($planOwner['owner_id'] ?? 0) !== $actorUserId) {
                throw new RuntimeException(
                    'Only the Change Plan owner can undo its Wizard changes.'
                );
            }
            $this->lockEditableVersion($versionId);
            $operation = $this->latestRow(
                "SELECT * FROM ipca_manual_ai_architect_operations
                 WHERE id=? AND operation_type='apply_accepted_wizard_changes'" . $lock,
                array($operationId)
            );
            if ((int)$operation['plan_id'] !== (int)$operationReference['plan_id']
                || (string)($operation['status'] ?? '') !== 'succeeded') {
                throw new RuntimeException('This Wizard edit has already been undone or is unavailable.');
            }
            $result = $this->decode($operation['result_json'] ?? null);
            if ((int)($result['book_version_id'] ?? 0) !== $versionId) {
                throw new RuntimeException('This Wizard edit changed before undo.');
            }
            $before = (array)($result['before_sections'] ?? array());
            $after = (array)($result['after_sections'] ?? array());
            if ($before === array() || $after === array()) {
                throw new RuntimeException('The Wizard edit does not contain a complete recovery snapshot.');
            }

            $current = $this->snapshotsForSectionIds(
                array_map('intval', array_keys($after)),
                true
            );
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
     * Revert one Wizard-applied section. If that same section was edited after
     * application, the caller must explicitly confirm the exact current
     * fingerprint; changes in all other sections remain untouched.
     *
     * @return array<string,mixed>
     */
    public function revertEditorChange(
        int $versionId,
        int $operationId,
        int $sectionId,
        int $actorUserId,
        bool $force = false,
        string $expectedCurrentFingerprint = ''
    ): array {
        if ($versionId <= 0 || $operationId <= 0 || $sectionId <= 0) {
            throw new InvalidArgumentException(
                'version_id, operation_id and section_id are required.'
            );
        }
        $operationReference = $this->latestRow(
            "SELECT plan_id,result_json FROM ipca_manual_ai_architect_operations
             WHERE id=? AND operation_type='apply_accepted_wizard_changes'",
            array($operationId)
        );
        $referenceResult = $this->decode($operationReference['result_json'] ?? null);
        if ((int)($referenceResult['book_version_id'] ?? 0) !== $versionId) {
            throw new RuntimeException(
                'This Wizard change does not belong to the open manual version.'
            );
        }
        if (!isset(
            ((array)($referenceResult['before_sections'] ?? array()))[$sectionId],
            ((array)($referenceResult['after_sections'] ?? array()))[$sectionId]
        )) {
            throw new RuntimeException('This section is not part of the Wizard application.');
        }
        $this->pdo->beginTransaction();
        try {
            $lock = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
                ? ''
                : ' FOR UPDATE';
            $planOwner = $this->latestRow(
                'SELECT owner_id FROM ipca_manual_ai_architect_plans WHERE id=?' . $lock,
                array((int)$operationReference['plan_id'])
            );
            if ((int)($planOwner['owner_id'] ?? 0) !== $actorUserId) {
                throw new RuntimeException(
                    'Only the Change Plan owner can revert its Wizard changes.'
                );
            }
            $lockedVersion = $this->latestRow(
                'SELECT * FROM ipca_publishing_book_versions WHERE id=?' . $lock,
                array($versionId)
            );
            $operation = $this->latestRow(
                "SELECT * FROM ipca_manual_ai_architect_operations
                 WHERE id=? AND operation_type='apply_accepted_wizard_changes'"
                    . $lock,
                array($operationId)
            );
            if ((int)$operation['plan_id'] !== (int)$operationReference['plan_id']) {
                throw new RuntimeException(
                    'This Wizard change has invalid plan provenance.'
                );
            }
            $result = $this->decode($operation['result_json'] ?? null);
            if ((int)($result['book_version_id'] ?? 0) !== $versionId) {
                throw new RuntimeException('This Wizard change changed before revert.');
            }
            $existingStmt = $this->pdo->prepare(
                "SELECT id,operation_payload_json,result_json
                 FROM ipca_manual_ai_architect_operations
                 WHERE plan_id=? AND operation_type='revert_wizard_change_item'
                   AND status='succeeded' ORDER BY id" . $lock
            );
            $existingStmt->execute(array((int)$operation['plan_id']));
            foreach ($existingStmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $existingRow) {
                $existingPayload = $this->decode($existingRow['operation_payload_json'] ?? null);
                if ((int)($existingPayload['original_operation_id'] ?? 0) === $operationId
                    && (int)($existingPayload['section_id'] ?? 0) === $sectionId) {
                    $existingResult = $this->decode($existingRow['result_json'] ?? null);
                    $this->pdo->commit();
                    $existingResult['derived_refresh_warnings'] =
                        $this->refreshPostCommitArtifacts(
                            $versionId,
                            $actorUserId,
                            'manual_change_architect_revert_item_replay'
                        );
                    return $existingResult;
                }
            }
            if ((string)($operation['status'] ?? '') !== 'succeeded') {
                throw new RuntimeException(
                    'This Wizard change has already been undone or is unavailable.'
                );
            }
            if (!in_array(
                (string)($lockedVersion['lifecycle_status'] ?? ''),
                array('draft', 'in_review'),
                true
            )) {
                throw new RuntimeException(
                    'Wizard changes may only be reverted in Draft or Draft Review manuals.'
                );
            }
            $before = (array)($result['before_sections'] ?? array());
            $after = (array)($result['after_sections'] ?? array());
            if (!isset($before[$sectionId], $after[$sectionId])) {
                throw new RuntimeException('This Wizard section changed before revert.');
            }
            $newerApplications = $this->pdo->prepare(
                "SELECT id,result_json
                 FROM ipca_manual_ai_architect_operations
                 WHERE id>? AND operation_type='apply_accepted_wizard_changes'
                   AND status='succeeded' ORDER BY id" . $lock
            );
            $newerApplications->execute(array($operationId));
            foreach ($newerApplications->fetchAll(PDO::FETCH_ASSOC) ?: array() as $newer) {
                $newerResult = $this->decode($newer['result_json'] ?? null);
                if ((int)($newerResult['book_version_id'] ?? 0) === $versionId
                    && isset(((array)($newerResult['after_sections'] ?? array()))[$sectionId])) {
                    throw new RuntimeException(
                        'This Wizard change was superseded by a later application to the same section.'
                    );
                }
            }

            $current = $this->snapshotsForSectionIds(array($sectionId), true);
            $currentSnapshot = (array)($current[$sectionId] ?? array());
            $currentFingerprint = (string)($currentSnapshot['fingerprint'] ?? '');
            $appliedFingerprint = (string)($after[$sectionId]['fingerprint'] ?? '');
            $manualEdits = $appliedFingerprint === ''
                || !hash_equals($appliedFingerprint, $currentFingerprint);
            if ($manualEdits && !$force) {
                throw new RuntimeException(
                    'CONFIRM_REVERT: This section was edited after the Wizard application. '
                    . 'Reverting it will replace those later edits, while all other sections remain unchanged.',
                    409
                );
            }
            if ($manualEdits && ($expectedCurrentFingerprint === ''
                || !hash_equals($currentFingerprint, $expectedCurrentFingerprint))) {
                throw new RuntimeException(
                    'The section changed again before the confirmed revert. Review it and try again.',
                    409
                );
            }

            $requestFingerprint = hash('sha256', $this->json(array(
                'original_operation_id' => $operationId,
                'section_id' => $sectionId,
                'current_fingerprint' => $currentFingerprint,
                'restore_fingerprint' => (string)$before[$sectionId]['fingerprint'],
            )));
            $revertOperationId = $this->plans->save('operations', (int)$operation['plan_id'], array(
                'operation_uuid' => $this->uuid(),
                'draft_id' => (int)($operation['draft_id'] ?? 0) ?: null,
                'review_id' => (int)($operation['review_id'] ?? 0) ?: null,
                'operation_type' => 'revert_wizard_change_item',
                'idempotency_key' => hash(
                    'sha256',
                    'wizard-revert-item|' . $operationId . '|' . $sectionId
                ),
                'status' => 'running',
                'request_fingerprint' => $requestFingerprint,
                'operation_payload_json' => $this->json(array(
                    'schema' => 'ipca.manual-change-editor-revert.v1',
                    'original_operation_id' => $operationId,
                    'book_version_id' => $versionId,
                    'section_id' => $sectionId,
                    'manual_edits_overwrite_confirmed' => $manualEdits && $force,
                    'current_fingerprint' => $currentFingerprint,
                    'restore_fingerprint' => (string)$before[$sectionId]['fingerprint'],
                )),
                'requested_by' => $actorUserId,
                'started_at' => gmdate('Y-m-d H:i:s.v'),
            ));

            $this->restoreSectionSnapshot(
                $sectionId,
                (array)$before[$sectionId],
                $actorUserId
            );
            $restored = $this->snapshotsForSectionIds(array($sectionId));
            $restoredFingerprint = (string)($restored[$sectionId]['fingerprint'] ?? '');
            if (!hash_equals(
                (string)$before[$sectionId]['fingerprint'],
                $restoredFingerprint
            )) {
                throw new RuntimeException('The section did not restore to its exact pre-Wizard state.');
            }
            $highlightsRefresh = (new ControlledPublishingRevisionService(
                $this->pdo
            ))->regenerateHighlightsSection(
                $versionId,
                $actorUserId
            );
            $tocRefresh = (new ControlledPublishingTocService(
                $this->pdo
            ))->regenerateTocSection(
                $versionId,
                $actorUserId
            );
            (new BooksManualsWorkflowService($this->pdo))->syncUpdateIdentity($versionId);
            $this->appendSectionEditEvents(
                (int)$operation['plan_id'],
                $revertOperationId,
                'revert',
                array($sectionId => $currentSnapshot),
                array($sectionId => (array)$restored[$sectionId]),
                $actorUserId
            );

            $revertResult = array(
                'schema' => 'ipca.manual-change-editor-revert-result.v1',
                'original_operation_id' => $operationId,
                'revert_operation_id' => $revertOperationId,
                'book_version_id' => $versionId,
                'section_id' => $sectionId,
                'restored_fingerprint' => $restoredFingerprint,
                'overwritten_section' => $currentSnapshot,
                'restored_section' => (array)$restored[$sectionId],
                'manual_edits_overwritten' => $manualEdits,
                'other_authored_sections_changed' => false,
                'derived_sections_refreshed' => array_values(array_unique(array_filter(array(
                    (int)($highlightsRefresh['section_id'] ?? 0),
                    (int)($tocRefresh['section_id'] ?? 0),
                )))),
                'reverted_at' => gmdate(DATE_ATOM),
                'reverted_by' => $actorUserId,
            );
            $this->pdo->prepare(
                "UPDATE ipca_manual_ai_architect_operations
                 SET status='succeeded',result_json=?,result_fingerprint=?,
                     completed_at=CURRENT_TIMESTAMP
                 WHERE id=? AND status='running'"
            )->execute(array(
                $this->json($revertResult),
                hash('sha256', $this->json($revertResult)),
                $revertOperationId,
            ));
            $this->plans->appendEvent(
                (int)$operation['plan_id'],
                'WIZARD_EDITOR_CHANGE_REVERTED',
                13,
                array(
                    'original_operation_id' => $operationId,
                    'revert_operation_id' => $revertOperationId,
                    'book_version_id' => $versionId,
                    'section_id' => $sectionId,
                    'manual_edits_overwritten' => $manualEdits,
                    'other_authored_sections_changed' => false,
                    'derived_sections_refreshed' => $revertResult['derived_sections_refreshed'],
                ),
                $actorUserId
            );
            $this->pdo->commit();
            $revertResult['derived_refresh_warnings'] = $this->refreshPostCommitArtifacts(
                $versionId,
                $actorUserId,
                'manual_change_architect_editor_revert'
            );
            $this->recordGlobalAudit(
                'manual_change_architect.revert_wizard_change',
                (int)$operation['plan_id'],
                $revertOperationId,
                $versionId,
                $actorUserId,
                $revertResult
            );
            return $revertResult;
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
    private function buildTargets(
        int $planId,
        int $versionId,
        array $sectionDrafts,
        bool $forUpdate = false
    ): array {
        $lock = $forUpdate
            && (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite'
                ? ' FOR UPDATE'
                : '';
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
             ORDER BY id" . $lock
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
            $range = $this->resolveContextRange(
                $versionId,
                $sectionId,
                $contextKey,
                $forUpdate
            );
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
    private function resolveContextRange(
        int $versionId,
        int $sectionId,
        string $contextKey,
        bool $forUpdate = false
    ): array {
        $lock = $forUpdate
            && (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite'
                ? ' FOR UPDATE'
                : '';
        $section = $this->latestRow(
            'SELECT id FROM ipca_publishing_book_sections
             WHERE id=? AND book_version_id=? LIMIT 1' . $lock,
            array($sectionId, $versionId)
        );
        if ((int)$section['id'] !== $sectionId) {
            throw new RuntimeException('A canonical target no longer belongs to the selected manual version.');
        }
        $blocksStmt = $this->pdo->prepare(
            'SELECT * FROM ipca_publishing_book_blocks
             WHERE section_id=? ORDER BY sort_order,id' . $lock
        );
        $blocksStmt->execute(array($sectionId));
        $blocks = $blocksStmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
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
    private function snapshotsForTargets(array $targets, bool $forUpdate = false): array
    {
        return $this->snapshotsForSectionIds(array_values(array_unique(array_map(
            static fn(array $target): int => (int)$target['section_id'],
            $targets
        ))), $forUpdate);
    }

    /**
     * @param list<int> $sectionIds
     * @return array<int,array<string,mixed>>
     */
    private function snapshotsForSectionIds(array $sectionIds, bool $forUpdate = false): array
    {
        $snapshots = array();
        $lock = $forUpdate
            && (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite'
                ? ' FOR UPDATE'
                : '';
        foreach ($sectionIds as $sectionId) {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM ipca_publishing_book_blocks
                 WHERE section_id=? ORDER BY sort_order,id'
                    . $lock
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
    private function structureTitles(int $planId, bool $forUpdate = false): array
    {
        $lock = $forUpdate
            && (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite'
                ? ' FOR UPDATE'
                : '';
        $stmt = $this->pdo->prepare(
            "SELECT n.title
             FROM ipca_manual_ai_architect_structure_nodes n
             JOIN ipca_manual_ai_architect_structure_proposals p
               ON p.id=n.structure_proposal_id
             WHERE p.plan_id=? AND p.status='approved'
               AND n.decision_status='accepted'
             ORDER BY n.sort_order,n.id" . $lock
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
        $this->pdo->prepare(
            'UPDATE ipca_publishing_book_blocks
             SET updated_by=?,updated_at=CURRENT_TIMESTAMP
             WHERE section_id=?'
        )->execute(array($actorUserId, $sectionId));
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
        $lock = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? ''
            : ' FOR UPDATE';
        $version = $this->latestRow(
            'SELECT * FROM ipca_publishing_book_versions WHERE id=?' . $lock,
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
     * Show the authored rows that actually differ, rather than the beginning
     * of a potentially long physical section.
     *
     * @return array{before:string,after:string}
     */
    private function snapshotDifferencePreviews(array $before, array $after): array
    {
        $beforeById = array();
        foreach ((array)($before['rows'] ?? array()) as $row) {
            $beforeById[(int)($row['id'] ?? 0)] = $row;
        }
        $afterById = array();
        foreach ((array)($after['rows'] ?? array()) as $row) {
            $afterById[(int)($row['id'] ?? 0)] = $row;
        }
        $changedBefore = array();
        $changedAfter = array();
        foreach (array_values(array_unique(array_merge(
            array_keys($beforeById),
            array_keys($afterById)
        ))) as $blockId) {
            $beforeRow = $beforeById[(int)$blockId] ?? null;
            $afterRow = $afterById[(int)$blockId] ?? null;
            $sameAuthoredContent = is_array($beforeRow)
                && is_array($afterRow)
                && (string)($beforeRow['block_type'] ?? '')
                    === (string)($afterRow['block_type'] ?? '')
                && (string)($beforeRow['payload_json'] ?? '')
                    === (string)($afterRow['payload_json'] ?? '')
                && (string)($beforeRow['content_hash'] ?? '')
                    === (string)($afterRow['content_hash'] ?? '');
            if ($sameAuthoredContent) {
                continue;
            }
            if (is_array($beforeRow)) {
                $changedBefore[] = $beforeRow;
            }
            if (is_array($afterRow)) {
                $changedAfter[] = $afterRow;
            }
        }
        $sortRows = static function (array &$rows): void {
            usort(
                $rows,
                static fn(array $left, array $right): int =>
                    ((int)($left['sort_order'] ?? 0) <=> (int)($right['sort_order'] ?? 0))
                    ?: ((int)($left['id'] ?? 0) <=> (int)($right['id'] ?? 0))
            );
        };
        $sortRows($changedBefore);
        $sortRows($changedAfter);
        return array(
            'before' => $this->snapshotPreview(array(
                'rows' => $changedBefore !== array()
                    ? $changedBefore
                    : (array)($before['rows'] ?? array()),
            )),
            'after' => $this->snapshotPreview(array(
                'rows' => $changedAfter !== array()
                    ? $changedAfter
                    : (array)($after['rows'] ?? array()),
            )),
        );
    }

    private function snapshotPreview(array $snapshot): string
    {
        $parts = array();
        foreach ((array)($snapshot['rows'] ?? array()) as $row) {
            $payload = $this->decode($row['payload_json'] ?? null);
            $html = (string)($payload['html'] ?? '');
            if ($html === '') {
                continue;
            }
            $text = trim((string)preg_replace(
                '/\s+/u',
                ' ',
                html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')
            ));
            if ($text !== '') {
                $parts[] = $text;
            }
        }
        $preview = implode("\n", $parts);
        return mb_strlen($preview) > 900
            ? mb_substr($preview, 0, 897) . '…'
            : $preview;
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
