<?php
declare(strict_types=1);

/**
 * Schema-tolerant persistence for the Manual Change Architect checkpoint.
 *
 * The migration owns column definitions. This repository deliberately writes
 * only columns present in the installed schema so the reasoning service can be
 * deployed independently while the architect tables evolve.
 */
final class BooksManualsChangePlanService
{
    public const TABLE_PREFIX = 'ipca_manual_ai_architect_';

    /** @var array<string,string> */
    private const TABLES = array(
        'plans' => 'ipca_manual_ai_architect_plans',
        'evidence' => 'ipca_manual_ai_architect_evidence',
        'change_intents' => 'ipca_manual_ai_architect_change_intents',
        'target_components' => 'ipca_manual_ai_architect_target_components',
        'coverage' => 'ipca_manual_ai_architect_target_coverage',
        'impacts' => 'ipca_manual_ai_architect_impacts',
        'impact_dependencies' => 'ipca_manual_ai_architect_impact_dependencies',
        'boundaries' => 'ipca_manual_ai_architect_scope_boundaries',
        'legacy_hits' => 'ipca_manual_ai_architect_legacy_hits',
        'legacy_hit_decisions' => 'ipca_manual_ai_architect_legacy_hit_decisions',
        'structure_proposals' => 'ipca_manual_ai_architect_structure_proposals',
        'structure_nodes' => 'ipca_manual_ai_architect_structure_nodes',
        'events' => 'ipca_manual_ai_architect_decision_events',
        'edit_events' => 'ipca_manual_ai_architect_edit_events',
        'cross_manual_links' => 'ipca_manual_ai_architect_cross_manual_links',
        'drafts' => 'ipca_manual_ai_architect_drafts',
        'reviews' => 'ipca_manual_ai_architect_reviews',
        'operations' => 'ipca_manual_ai_architect_operations',
        'review_baselines' => 'ipca_manual_ai_architect_review_baselines',
        'review_findings' => 'ipca_manual_ai_architect_review_findings',
        'review_questions' => 'ipca_manual_ai_architect_review_questions',
        'review_answers' => 'ipca_manual_ai_architect_review_answers',
        'review_patches' => 'ipca_manual_ai_architect_review_patches',
        'review_cycles' => 'ipca_manual_ai_architect_review_cycles',
        'review_check_metadata' => 'ipca_manual_ai_architect_review_check_metadata',
    );

    /** @var array<string,list<string>> */
    private array $columnCache = array();

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string,bool> */
    public function tableStatus(): array
    {
        $status = array();
        foreach (self::TABLES as $key => $table) {
            $status[$key] = $this->columns($table) !== array();
        }
        return $status;
    }

    public function tablesPresent(): bool
    {
        return !in_array(false, $this->tableStatus(), true);
    }

    /**
     * Create a plan for exactly one primary publishing version.
     *
     * @param array<string,mixed> $attributes
     * @return array<string,mixed>
     */
    public function createPlan(int $primaryManualVersionId, array $attributes = array(), ?int $actorUserId = null): array
    {
        if ($primaryManualVersionId <= 0) {
            throw new InvalidArgumentException('A primary manual version is required.');
        }
        if (($actorUserId ?? 0) <= 0) {
            throw new InvalidArgumentException('A plan owner is required.');
        }
        if (!$this->tablesPresent()) {
            throw new RuntimeException('The Manual Change Architect tables are not available.');
        }
        $version = $this->row(
            'SELECT v.id,v.book_id,v.version_label,v.lifecycle_status,v.content_hash,b.book_key,b.title book_title
             FROM ipca_publishing_book_versions v
             JOIN ipca_publishing_books b ON b.id=v.book_id
             WHERE v.id=? LIMIT 1',
            array($primaryManualVersionId)
        );
        if ($version === null) {
            throw new RuntimeException('Primary manual version not found.');
        }

        $uuid = $this->uuid();
        $title = trim((string)($attributes['title'] ?? 'Manual Change Architect checkpoint'));
        $request = trim((string)($attributes['change_request'] ?? $attributes['objective'] ?? $title));
        $sourceFingerprint = trim((string)($attributes['source_fingerprint'] ?? $version['content_hash'] ?? ''));
        if ($sourceFingerprint === '') {
            $sourceFingerprint = hash('sha256', $this->json($version));
        }
        $planFingerprint = hash('sha256', $this->json(array(
            'primary_book_version_id' => $primaryManualVersionId,
            'source_fingerprint' => $sourceFingerprint,
            'change_request' => $request,
        )));
        $data = $attributes + array(
            'plan_uuid' => $uuid,
            'uuid' => $uuid,
            'title' => $title,
            'change_request' => $request,
            'objective' => trim((string)($attributes['objective'] ?? $request)),
            'primary_manual_version_id' => $primaryManualVersionId,
            'primary_book_version_id' => $primaryManualVersionId,
            'book_version_id' => $primaryManualVersionId,
            'stage' => 'intake',
            'status' => 'active',
            'checkpoint_stage' => 1,
            'source_fingerprint' => $sourceFingerprint,
            'plan_fingerprint' => $planFingerprint,
            'owner_id' => $actorUserId,
            'created_by' => $actorUserId,
            'updated_by' => $actorUserId,
            'metadata_json' => $this->json(array('primary_version' => $version)),
        );
        $planId = $this->insert('plans', $data);
        $this->appendEvent($planId, 'PLAN_CREATED', 1, array(
            'primary_manual_version_id' => $primaryManualVersionId,
            'version' => $version,
        ), $actorUserId);
        return $this->getPlan($planId);
    }

    /** @return array<string,mixed> */
    public function getPlan(int $planId): array
    {
        $plan = $this->row('SELECT * FROM ' . self::TABLES['plans'] . ' WHERE id=? LIMIT 1', array($planId));
        if ($plan === null) {
            throw new RuntimeException('Manual Change Architect plan not found.');
        }
        return $this->decodeRow($plan);
    }

    /**
     * Load a plan and every checkpoint collection.
     *
     * @return array<string,mixed>
     */
    public function loadPlan(int $planId): array
    {
        $plan = $this->getPlan($planId);
        foreach (array_keys(self::TABLES) as $key) {
            if ($key === 'plans') {
                continue;
            }
            $plan[$key] = array_map(
                fn(array $row): array => $this->decodeRow($row),
                $this->planRows($key, $planId)
            );
        }
        return $plan;
    }

    /**
     * Persist an architect entity, filtering against installed columns.
     *
     * @param array<string,mixed> $data
     */
    public function save(string $collection, int $planId, array $data): int
    {
        if ($collection === 'plans' || !isset(self::TABLES[$collection])) {
            throw new InvalidArgumentException('Unsupported architect collection.');
        }
        $data = $this->withPlanForeignKey(self::TABLES[$collection], $planId, $data);
        return $this->insert($collection, $data);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<int>
     */
    public function replaceCollection(string $collection, int $planId, array $rows): array
    {
        if (in_array($collection, array('plans', 'events'), true) || !isset(self::TABLES[$collection])) {
            throw new InvalidArgumentException('Unsupported replaceable architect collection.');
        }
        $this->deletePlanRows($collection, $planId);
        $ids = array();
        foreach ($rows as $row) {
            $ids[] = $this->save($collection, $planId, $row);
        }
        return $ids;
    }

    /**
     * Clear generated checkpoint rows without touching the plan or event log.
     */
    public function clearCheckpointData(int $planId): void
    {
        foreach (array(
            'cross_manual_links', 'impact_dependencies', 'coverage', 'legacy_hits',
            'boundaries', 'impacts', 'target_components', 'change_intents', 'evidence',
        ) as $collection) {
            $this->deletePlanRows($collection, $planId);
        }
    }

    public function clearPostImpactData(int $planId): void
    {
        foreach (array(
            'operations', 'reviews', 'drafts', 'structure_nodes', 'structure_proposals',
        ) as $collection) {
            $this->deletePlanRows($collection, $planId);
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function appendEvent(
        int $planId,
        string $eventType,
        int $stage,
        array $payload = array(),
        ?int $actorUserId = null
    ): int {
        return $this->save('events', $planId, array(
            'event_uuid' => $this->uuid(),
            'aggregate_type' => 'plan',
            'aggregate_id' => $planId,
            'event_type' => $eventType,
            'event_key' => $eventType,
            'stage' => $stage,
            'checkpoint_stage' => $stage,
            'payload_json' => $this->json($payload),
            'event_json' => $this->json($payload),
            'event_payload_json' => $this->json($payload),
            'decision' => $eventType,
            'event_fingerprint' => hash('sha256', $this->json(array(
                'plan_id' => $planId,
                'event_type' => $eventType,
                'stage' => $stage,
                'payload' => $payload,
                'nonce' => bin2hex(random_bytes(8)),
            ))),
            'actor_id' => $actorUserId,
            'created_by' => $actorUserId,
        ));
    }

    /**
     * @param array<string,mixed> $attributes
     */
    public function updatePlan(int $planId, array $attributes): void
    {
        $columns = $this->columns(self::TABLES['plans']);
        $attributes = $this->filterData($attributes, $columns);
        unset($attributes['id']);
        if ($attributes === array()) {
            return;
        }
        if (in_array('updated_at', $columns, true) && !isset($attributes['updated_at'])) {
            $attributes['updated_at'] = gmdate('Y-m-d H:i:s');
        }
        $sets = array();
        $params = array();
        foreach ($attributes as $column => $value) {
            $sets[] = $this->quoteIdentifier($column) . '=?';
            $params[] = $this->databaseValue($value, $column);
        }
        $params[] = $planId;
        $this->pdo->prepare(
            'UPDATE ' . self::TABLES['plans'] . ' SET ' . implode(',', $sets) . ' WHERE id=?'
        )->execute($params);
    }

    /**
     * Return the persisted, complete stage 2-10 checkpoint report.
     *
     * @return array<string,mixed>
     */
    public function completeCheckpointReport(int $planId): array
    {
        $report = $this->loadPlan($planId);
        $report['schema'] = 'ipca.manual-change-architect-checkpoint.v1';
        $report['checkpoint_range'] = array('from' => 2, 'to' => 10);
        $report['primary_manual_version_id'] = $this->primaryVersionId($report);
        $report['read_only_publishing_analysis'] = true;
        $report['contains_drafting_or_proposals'] = false;
        $report['counts'] = array();
        foreach (array_keys(self::TABLES) as $key) {
            if ($key !== 'plans') {
                $report['counts'][$key] = count((array)($report[$key] ?? array()));
            }
        }
        return $report;
    }

    /**
     * Record an immutable human disposition and update the current projection.
     *
     * @return array<string,mixed>
     */
    public function recordLegacyHitDecision(
        int $planId,
        int $legacyHitId,
        string $disposition,
        string $justification,
        int $actorUserId
    ): array {
        $this->assertPlanOwnerAtStage($planId, $actorUserId, 'scope');
        $allowed = array(
            'REMOVE_OR_REPLACE',
            'PRESERVE_WITH_JUSTIFICATION',
            'REVIEW_SEPARATELY',
        );
        if (!in_array($disposition, $allowed, true)) {
            throw new InvalidArgumentException('Unsupported legacy-reference disposition.');
        }
        $justification = trim($justification);
        if ($justification === '' || $actorUserId <= 0) {
            throw new InvalidArgumentException('A rationale and accountable actor are required.');
        }
        $hit = $this->row(
            'SELECT * FROM ' . self::TABLES['legacy_hits'] . ' WHERE id=? AND plan_id=? LIMIT 1',
            array($legacyHitId, $planId)
        );
        if ($hit === null) {
            throw new RuntimeException('Legacy reference not found in this Change Plan.');
        }
        $previous = $this->row(
            'SELECT * FROM ' . self::TABLES['legacy_hit_decisions']
            . ' WHERE legacy_hit_id=? ORDER BY decided_at DESC,id DESC LIMIT 1',
            array($legacyHitId)
        );
        $fingerprint = hash('sha256', $this->json(array(
            'plan_id' => $planId,
            'legacy_hit_id' => $legacyHitId,
            'previous_decision_id' => $previous['id'] ?? null,
            'disposition' => $disposition,
            'justification' => $justification,
            'decided_by' => $actorUserId,
        )));
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $decisionId = $this->insert('legacy_hit_decisions', array(
                'decision_uuid' => $this->uuid(),
                'plan_id' => $planId,
                'legacy_hit_id' => $legacyHitId,
                'previous_decision_id' => $previous['id'] ?? null,
                'disposition' => $disposition,
                'justification' => $justification,
                'decision_fingerprint' => $fingerprint,
                'decided_by' => $actorUserId,
            ));
            $this->pdo->prepare(
                'UPDATE ' . self::TABLES['legacy_hits']
                . ' SET disposition=?,disposition_justification=?,status=? WHERE id=? AND plan_id=?'
            )->execute(array($disposition, $justification, 'decided', $legacyHitId, $planId));
            if ($disposition === 'PRESERVE_WITH_JUSTIFICATION') {
                $boundaryFingerprint = hash('sha256', $this->json(array(
                    'section_id' => (int)$hit['section_id'],
                    'classification' => 'MUST_PRESERVE',
                    'rationale' => $justification,
                    'decision_fingerprint' => $fingerprint,
                )));
                $this->pdo->prepare(
                    'UPDATE ' . self::TABLES['boundaries']
                    . ' SET classification=?,rationale=?,boundary_fingerprint=?'
                    . ' WHERE plan_id=? AND section_id=? AND classification=?'
                )->execute(array(
                    'MUST_PRESERVE',
                    $justification,
                    $boundaryFingerprint,
                    $planId,
                    (int)$hit['section_id'],
                    'REVIEW_SEPARATELY',
                ));
            }
            $this->appendEvent($planId, 'LEGACY_HIT_DECIDED', 10, array(
                'legacy_hit_id' => $legacyHitId,
                'decision_id' => $decisionId,
                'disposition' => $disposition,
                'justification' => $justification,
                'create_related_plan' => false,
            ), $actorUserId);
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return array(
            'decision_id' => $decisionId,
            'legacy_hit_id' => $legacyHitId,
            'disposition' => $disposition,
            'justification' => $justification,
            'create_related_plan' => false,
        );
    }

    /**
     * Convert the Architect's visible legacy/boundary recommendations into
     * governed decisions when the owner accepts the complete impact analysis.
     *
     * @return array{legacy_decisions:int,resolved_review_boundaries:int}
     */
    public function governAcceptedAnalysisDispositions(int $planId, int $actorUserId): array
    {
        $this->assertPlanOwnerAtStage($planId, $actorUserId, 'scope');
        $hits = $this->rows(
            'SELECT h.* FROM ' . self::TABLES['legacy_hits'] . ' h'
            . ' LEFT JOIN ' . self::TABLES['legacy_hit_decisions'] . ' d'
            . ' ON d.legacy_hit_id=h.id'
            . ' WHERE h.plan_id=? AND d.id IS NULL ORDER BY h.id',
            array($planId)
        );
        $accepted = 0;
        foreach ($hits as $hit) {
            $disposition = strtoupper(trim((string)($hit['disposition'] ?? '')));
            if (!in_array($disposition, array(
                'REMOVE_OR_REPLACE',
                'PRESERVE_WITH_JUSTIFICATION',
                'REVIEW_SEPARATELY',
            ), true)) {
                continue;
            }
            $justification = trim((string)($hit['disposition_justification'] ?? ''));
            if ($justification === '') {
                continue;
            }
            $this->recordLegacyHitDecision(
                $planId,
                (int)$hit['id'],
                $disposition,
                $justification . ' Accepted with the complete Impact Analysis.',
                $actorUserId
            );
            $accepted++;
        }
        $boundaryStmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLES['boundaries']
            . " SET status='resolved',updated_at=CURRENT_TIMESTAMP(3)"
            . " WHERE plan_id=? AND classification='REVIEW_SEPARATELY'"
            . " AND status='active' AND rationale IS NOT NULL AND TRIM(rationale)<>''"
        );
        $boundaryStmt->execute(array($planId));
        $resolved = $boundaryStmt->rowCount();
        if ($accepted > 0 || $resolved > 0) {
            $this->appendEvent($planId, 'ANALYSIS_DISPOSITIONS_GOVERNED', 10, array(
                'legacy_decisions' => $accepted,
                'resolved_review_boundaries' => $resolved,
                'authority' => 'human_acceptance_of_complete_impact_analysis',
            ), $actorUserId);
        }
        return array(
            'legacy_decisions' => $accepted,
            'resolved_review_boundaries' => $resolved,
        );
    }

    /**
     * Record the human decision governing one consolidated amendment area.
     *
     * @return array<string,mixed>
     */
    public function recordImpactDecision(
        int $planId,
        int $impactId,
        string $decision,
        string $note,
        int $actorUserId
    ): array {
        $decision = strtoupper(trim($decision));
        if (!in_array($decision, array('ACCEPT', 'MODIFY', 'REJECT'), true)) {
            throw new InvalidArgumentException('Unsupported amendment-area decision.');
        }
        $note = trim($note);
        if ($actorUserId <= 0) {
            throw new InvalidArgumentException('An accountable decision-maker is required.');
        }
        if ($decision !== 'ACCEPT' && mb_strlen($note) < 5) {
            throw new InvalidArgumentException('Record a rationale for modification or rejection.');
        }
        $this->assertPlanOwnerAtStage($planId, $actorUserId, 'scope');
        $impact = $this->row(
            'SELECT * FROM ' . self::TABLES['impacts'] . ' WHERE id=? AND plan_id=? LIMIT 1',
            array($impactId, $planId)
        );
        if ($impact === null) {
            throw new RuntimeException('Amendment area not found in this Change Plan.');
        }
        $status = match ($decision) {
            'ACCEPT' => 'approved',
            'MODIFY' => 'validated',
            'REJECT' => 'dismissed',
        };
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'UPDATE ' . self::TABLES['impacts']
                . ' SET status=?,updated_at=CURRENT_TIMESTAMP(3) WHERE id=? AND plan_id=?'
            )->execute(array($status, $impactId, $planId));
            $eventId = $this->appendEvent(
                $planId,
                match ($decision) {
                    'ACCEPT' => 'IMPACT_ACCEPTED',
                    'MODIFY' => 'IMPACT_MODIFICATION_REQUESTED',
                    'REJECT' => 'IMPACT_REJECTED',
                },
                10,
                array(
                    'impact_id' => $impactId,
                    'impact_key' => (string)($impact['impact_key'] ?? ''),
                    'section_number' => (string)($impact['section_number'] ?? ''),
                    'decision' => $decision,
                    'note' => $note,
                ),
                $actorUserId
            );
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return array(
            'impact_id' => $impactId,
            'decision' => $decision,
            'status' => $status,
            'note' => $note,
            'event_id' => $eventId,
        );
    }

    /**
     * Persist a governed human resolution for one active review blocker.
     *
     * @param array<string,mixed> $blocker
     * @param array<string,mixed> $area
     * @return array<string,mixed>
     */
    public function recordReviewBlockerResolution(
        int $planId,
        array $blocker,
        array $area,
        string $resolutionType,
        string $disposition,
        string $rationale,
        string $residualRisk,
        string $mergeTargetSection,
        int $actorUserId
    ): array {
        $this->assertPlanOwnerAtStage($planId, $actorUserId, 'scope');
        $resolutionType = strtoupper(trim($resolutionType));
        $disposition = strtoupper(trim($disposition));
        $rationale = trim($rationale);
        $residualRisk = trim($residualRisk);
        if (mb_strlen($rationale) < 20 || mb_strlen($rationale) > 10000) {
            throw new InvalidArgumentException('A specific governed rationale of at least 20 characters is required.');
        }
        if (!empty($blocker['integrity_blocker'])
            || (string)($blocker['severity'] ?? '') === 'NON_OVERRIDABLE_INTEGRITY') {
            throw new RuntimeException('Controlled-content integrity blockers cannot be resolved by disposition or exception.');
        }
        if ($resolutionType === 'HUMAN_DISPOSITION') {
            $allowed = array(
                'AMEND', 'PRESERVE_UNCHANGED', 'OUT_OF_SCOPE',
                'REVIEW_SEPARATELY', 'MERGE_WITH',
            );
            if (!in_array($disposition, $allowed, true)
                || !in_array('HUMAN_DISPOSITION', (array)($blocker['resolution_paths'] ?? array()), true)) {
                throw new InvalidArgumentException('This blocker does not permit the requested human disposition.');
            }
            if ($disposition === 'MERGE_WITH' && trim($mergeTargetSection) === '') {
                throw new InvalidArgumentException('A target amendment area is required for a merge disposition.');
            }
            $eventType = 'REVIEW_BLOCKER_DISPOSITION_RECORDED';
        } elseif ($resolutionType === 'REVIEW_EXCEPTION') {
            if (empty($blocker['exception_eligible'])
                || !in_array('REVIEW_EXCEPTION', (array)($blocker['resolution_paths'] ?? array()), true)) {
                throw new RuntimeException('This blocker is not eligible for a review exception.');
            }
            if (mb_strlen($residualRisk) < 10 || mb_strlen($residualRisk) > 5000) {
                throw new InvalidArgumentException('Describe the residual risk or uncertainty accepted by the reviewer.');
            }
            $eventType = 'REVIEW_EXCEPTION_ACCEPTED';
            $disposition = 'ACCEPT_REVIEW_EXCEPTION';
        } else {
            throw new InvalidArgumentException('Unsupported blocker-resolution path.');
        }
        $payload = array(
            'schema' => 'ipca.manual-change-review-resolution.v1',
            'blocker_id' => (string)($blocker['blocker_id'] ?? ''),
            'blocker' => $blocker,
            'original_architect_conclusion' => array(
                'why_affected' => (string)($area['why_affected'] ?? ''),
                'proposed_amendment_summary' => (string)($area['proposed_amendment_summary'] ?? ''),
                'must_preserve' => array_values((array)($area['must_preserve'] ?? array())),
                'treatment' => (string)($area['treatment'] ?? ''),
            ),
            'resolution_type' => $resolutionType,
            'disposition' => $disposition,
            'rationale' => $rationale,
            'residual_risk' => $residualRisk,
            'actor_user_id' => $actorUserId,
            'recorded_at' => gmdate(DATE_ATOM),
            'section_id' => (int)($area['section_id'] ?? 0),
            'section_number' => (string)($area['section_number'] ?? $blocker['section'] ?? ''),
            'section_title' => (string)($area['section_title'] ?? ''),
            'canonical_context' => $area['current_manual'] ?? $area['current_relevant_content'] ?? array(),
            'evidence_refs' => array_values((array)($area['evidence_refs'] ?? array())),
            'merge_target_section' => trim($mergeTargetSection),
            'resulting_architect_state' => array(
                'classification' => match ($disposition) {
                    'PRESERVE_UNCHANGED' => 'MUST_PRESERVE',
                    'OUT_OF_SCOPE' => 'OUT_OF_SCOPE',
                    'REVIEW_SEPARATELY' => 'REVIEW_SEPARATELY',
                    'MERGE_WITH' => 'MERGED',
                    'AMEND' => 'MUST_CHANGE',
                    default => 'REVIEW_EXCEPTION_ACCEPTED',
                },
                'quality_gate_must_rerun' => true,
            ),
        );
        $eventId = $this->appendEvent($planId, $eventType, 10, $payload, $actorUserId);
        $this->updatePlan($planId, array(
            'status' => 'ready_for_review',
            'stage' => 'scope',
            'updated_by' => $actorUserId,
        ));
        return array(
            'event_id' => $eventId,
            'blocker_id' => $payload['blocker_id'],
            'resolution_type' => $resolutionType,
            'disposition' => $disposition,
        );
    }

    /** @return array{accepted_count:int,stage:string} */
    public function acceptImpactAnalysis(
        int $planId,
        int $actorUserId,
        ?callable $afterApproval = null,
        ?array $approvedImpactIds = null
    ): array
    {
        $this->assertPlanOwnerAtStage($planId, $actorUserId, 'scope');
        $stmt = $this->pdo->prepare(
            "SELECT id,status FROM " . self::TABLES['impacts']
            . " WHERE plan_id=? AND treatment IN ('AMEND','REPLACE','RESTRUCTURE','ADD','REMOVE_OBSOLETE')"
        );
        $stmt->execute(array($planId));
        $allRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
        $approvedImpactIds = $approvedImpactIds === null
            ? null
            : array_values(array_unique(array_filter(array_map('intval', $approvedImpactIds))));
        $rows = $approvedImpactIds === null
            ? $allRows
            : array_values(array_filter(
                $allRows,
                static fn(array $row): bool => in_array((int)$row['id'], $approvedImpactIds, true)
            ));
        if ($rows === array()) {
            throw new RuntimeException('No governed amendment areas are available to accept.');
        }
        foreach ($rows as $row) {
            if ((string)($row['status'] ?? '') === 'dismissed') {
                throw new RuntimeException('Resolve rejected amendment areas before continuing.');
            }
        }
        $this->pdo->beginTransaction();
        try {
            $lock = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
                ? ''
                : ' FOR UPDATE';
            $lockedPlan = $this->row(
                'SELECT owner_id,stage FROM ' . self::TABLES['plans']
                . ' WHERE id=?' . $lock,
                array($planId)
            );
            if ((int)($lockedPlan['owner_id'] ?? 0) !== $actorUserId
                || (string)($lockedPlan['stage'] ?? '') !== 'scope') {
                throw new RuntimeException(
                    'Accepted Impact Analysis is frozen after Step 2 is completed.'
                );
            }
            $this->pdo->prepare(
                'UPDATE ' . self::TABLES['impacts']
                . " SET status='dismissed',updated_at=CURRENT_TIMESTAMP(3)"
                . " WHERE plan_id=? AND treatment IN ('AMEND','REPLACE','RESTRUCTURE','ADD','REMOVE_OBSOLETE')"
            )->execute(array($planId));
            $placeholders = implode(',', array_fill(0, count($rows), '?'));
            $this->pdo->prepare(
                'UPDATE ' . self::TABLES['impacts']
                . " SET status='approved',updated_at=CURRENT_TIMESTAMP(3)"
                . " WHERE plan_id=? AND id IN ({$placeholders})"
            )->execute(array_merge(array($planId), array_map('intval', array_column($rows, 'id'))));
            $governedDispositions = $this->governAcceptedAnalysisDispositions(
                $planId,
                $actorUserId
            );
            $this->updatePlan($planId, array('stage' => 'structure', 'updated_by' => $actorUserId));
            $continuation = $afterApproval !== null
                ? (array)$afterApproval($rows)
                : array();
            $this->appendEvent($planId, 'IMPACT_ANALYSIS_ACCEPTED', 10, array(
                'impact_ids' => array_map('intval', array_column($rows, 'id')),
                'dismissed_impact_ids' => array_values(array_diff(
                    array_map('intval', array_column($allRows, 'id')),
                    array_map('intval', array_column($rows, 'id'))
                )),
                'governed_dispositions' => $governedDispositions,
                'continuation' => $continuation,
            ), $actorUserId);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return array_merge(
            array('accepted_count' => count($rows), 'stage' => 'structure'),
            $continuation ?? array()
        );
    }

    /** @return array{proposal_id:int,node_count:int,stage:string} */
    public function acceptStructure(int $planId, int $actorUserId): array
    {
        $this->pdo->beginTransaction();
        try {
            $lock = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
                ? ''
                : ' FOR UPDATE';
            $plan = $this->row(
                'SELECT owner_id,stage FROM ' . self::TABLES['plans']
                . ' WHERE id=?' . $lock,
                array($planId)
            );
            if ((int)($plan['owner_id'] ?? 0) !== $actorUserId
                || (string)($plan['stage'] ?? '') !== 'structure') {
                throw new RuntimeException(
                    'Accepted Proposed Structure is frozen after Step 3 is completed.'
                );
            }
            $proposal = $this->row(
                'SELECT * FROM ' . self::TABLES['structure_proposals']
                . ' WHERE plan_id=? ORDER BY proposal_version DESC,id DESC LIMIT 1' . $lock,
                array($planId)
            );
            if ($proposal === null) {
                throw new RuntimeException('The proposed manual structure is not ready yet.');
            }
            $proposalId = (int)$proposal['id'];
            $this->pdo->prepare(
                'UPDATE ' . self::TABLES['structure_proposals']
                . " SET status='approved',updated_at=CURRENT_TIMESTAMP(3) WHERE id=? AND plan_id=?"
            )->execute(array($proposalId, $planId));
            $nodes = $this->pdo->prepare(
                'UPDATE ' . self::TABLES['structure_nodes']
                . " SET decision_status='accepted',decided_by=?,decided_at=CURRENT_TIMESTAMP(3)"
                . ' WHERE structure_proposal_id=?'
            );
            $nodes->execute(array($actorUserId, $proposalId));
            $this->updatePlan($planId, array('stage' => 'drafting', 'updated_by' => $actorUserId));
            $this->appendEvent($planId, 'STRUCTURE_ACCEPTED', 11, array(
                'structure_proposal_id' => $proposalId,
                'node_count' => $nodes->rowCount(),
            ), $actorUserId);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return array(
            'proposal_id' => $proposalId,
            'node_count' => $nodes->rowCount(),
            'stage' => 'drafting',
        );
    }

    /** @return array{draft_id:int,section_number:string,decision:string} */
    public function recordDraftDecision(
        int $planId,
        string $sectionNumber,
        string $decision,
        int $actorUserId
    ): array {
        $sectionNumber = trim($sectionNumber);
        $decision = strtolower(trim($decision));
        if ($sectionNumber === '' || !in_array(
            $decision,
            array('accepted', 'edit_requested', 'regenerate_requested', 'rejected'),
            true
        )) {
            throw new InvalidArgumentException('Unsupported draft decision.');
        }
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        $lock = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? ''
            : ' FOR UPDATE';
        try {
            $plan = $this->row(
                'SELECT owner_id,stage FROM ' . self::TABLES['plans']
                . ' WHERE id=?' . $lock,
                array($planId)
            );
            if ((int)($plan['owner_id'] ?? 0) !== $actorUserId
                || (string)($plan['stage'] ?? '') !== 'drafting') {
                throw new RuntimeException(
                    'Draft decisions are frozen after Step 4 is accepted.'
                );
            }
            $draft = $this->row(
                'SELECT * FROM ' . self::TABLES['drafts']
                . ' WHERE id=(
                    SELECT latest.id FROM (
                        SELECT id FROM ' . self::TABLES['drafts'] . '
                        WHERE plan_id=? ORDER BY draft_version DESC,id DESC LIMIT 1
                    ) latest
                )' . $lock,
                array($planId)
            );
            if ($draft === null || (string)($draft['status'] ?? '') !== 'generated') {
                throw new RuntimeException(
                    'The draft amendment package is not open for section decisions.'
                );
            }
            $payload = $this->decodeJson($draft['draft_payload_json'] ?? null);
            if ((string)($payload['wizard_status'] ?? '') === 'accepted') {
                throw new RuntimeException('Accepted Step 4 wording is frozen during review.');
            }
            $sections = (array)($payload['section_drafts'] ?? $payload['sections'] ?? array());
            if (!array_key_exists($sectionNumber, $sections)) {
                throw new RuntimeException('That amendment section is not part of the governed draft.');
            }
            $payload['decisions'] = is_array($payload['decisions'] ?? null)
                ? $payload['decisions']
                : array();
            $payload['decisions'][$sectionNumber] = array(
                'decision' => $decision,
                'actor_id' => $actorUserId,
                'decided_at' => gmdate('c'),
            );
            $draftId = (int)$draft['id'];
            $payloadJson = $this->json($payload);
            $this->pdo->prepare(
                'UPDATE ' . self::TABLES['drafts']
                . ' SET draft_payload_json=?,content_fingerprint=? WHERE id=? AND plan_id=?'
            )->execute(array(
                $payloadJson,
                $this->draftPayloadFingerprint($payload),
                $draftId,
                $planId,
            ));
            $this->appendEvent($planId, 'DRAFT_SECTION_DECIDED', 12, array(
                'draft_id' => $draftId,
                'section_number' => $sectionNumber,
                'decision' => $decision,
            ), $actorUserId);
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        return array(
            'draft_id' => $draftId,
            'section_number' => $sectionNumber,
            'decision' => $decision,
        );
    }

    /** @return array{draft_id:int,stage:string} */
    public function acceptDrafts(int $planId, int $actorUserId): array
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        $lock = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? ''
            : ' FOR UPDATE';
        try {
            $plan = $this->row(
                'SELECT owner_id,stage FROM ' . self::TABLES['plans']
                . ' WHERE id=?' . $lock,
                array($planId)
            );
            if ((int)($plan['owner_id'] ?? 0) !== $actorUserId) {
                throw new RuntimeException(
                    'Only the Change Plan owner can accept amendment wording.'
                );
            }
            $draft = $this->row(
                'SELECT * FROM ' . self::TABLES['drafts']
                . ' WHERE plan_id=? ORDER BY draft_version DESC,id DESC LIMIT 1' . $lock,
                array($planId)
            );
            if ($draft === null || (string)($draft['status'] ?? '') !== 'generated') {
                throw new RuntimeException(
                    'The proposed manual amendments are not ready for acceptance.'
                );
            }
            $payload = $this->decodeJson($draft['draft_payload_json'] ?? null);
            $draftId = (int)$draft['id'];
            $sections = (array)($payload['section_drafts'] ?? $payload['sections'] ?? array());
            $decisions = (array)($payload['decisions'] ?? array());
            if ((string)($plan['stage'] ?? '') === 'review'
                && (string)($payload['wizard_status'] ?? '') === 'accepted') {
                foreach (array_keys($sections) as $sectionNumber) {
                    if ((string)($decisions[$sectionNumber]['decision'] ?? '') !== 'accepted') {
                        throw new RuntimeException(
                            'Accepted Step 4 decisions no longer match the frozen review state.'
                        );
                    }
                }
                $storedFingerprint = (string)($draft['content_fingerprint'] ?? '');
                $canonicalFingerprint = $this->draftPayloadFingerprint($payload);
                if (!hash_equals($storedFingerprint, $canonicalFingerprint)
                    && !$this->matchesFrozenReviewDraft(
                        $planId,
                        $draftId,
                        $payload,
                        $storedFingerprint
                    )) {
                    throw new RuntimeException(
                        'Accepted Step 4 wording no longer matches its recorded fingerprint.'
                    );
                }
                if ($ownsTransaction) {
                    $this->pdo->commit();
                }
                return array('draft_id' => $draftId, 'stage' => 'review');
            }
            if ((string)($plan['stage'] ?? '') !== 'drafting') {
                throw new RuntimeException(
                    'The Change Plan is no longer awaiting amendment acceptance.'
                );
            }
            foreach (array_keys($sections) as $sectionNumber) {
                if ((string)($decisions[$sectionNumber]['decision'] ?? '') !== 'accepted') {
                    throw new RuntimeException(
                        'Every required amendment must be accepted before continuing.'
                    );
                }
            }
            $payload['wizard_status'] = 'accepted';
            $payloadJson = $this->json($payload);
            $this->pdo->prepare(
                'UPDATE ' . self::TABLES['drafts']
                . ' SET draft_payload_json=?,content_fingerprint=? WHERE id=? AND plan_id=?'
            )->execute(array(
                $payloadJson,
                $this->draftPayloadFingerprint($payload),
                $draftId,
                $planId,
            ));
            $this->updatePlan($planId, array('stage' => 'review', 'updated_by' => $actorUserId));
            $this->appendEvent($planId, 'DRAFT_AMENDMENTS_ACCEPTED', 12, array(
                'draft_id' => $draftId,
            ), $actorUserId);
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return array('draft_id' => $draftId, 'stage' => 'review');
    }

    /** @return array{review_id:int,status:string} */
    public function runIndependentReview(int $planId, int $actorUserId): array
    {
        $this->assertPlanOwner($planId, $actorUserId);
        $review = $this->row(
            'SELECT * FROM ' . self::TABLES['reviews']
            . ' WHERE plan_id=? ORDER BY id DESC LIMIT 1',
            array($planId)
        );
        if ($review === null) {
            throw new RuntimeException('The independent review package is not ready yet.');
        }
        $payload = $this->decodeJson($review['review_payload_json'] ?? null);
        $result = $payload['prepared_result'] ?? null;
        if (!is_array($result) || strtoupper((string)($result['status'] ?? '')) !== 'READY') {
            throw new RuntimeException('Independent Review requires further reviewer work.');
        }
        $reviewId = (int)$review['id'];
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'UPDATE ' . self::TABLES['reviews']
                . " SET status='approved',review_payload_json=?,reviewer_id=?,completed_at=CURRENT_TIMESTAMP(3)"
                . ' WHERE id=? AND plan_id=?'
            )->execute(array($this->json($result), $actorUserId, $reviewId, $planId));
            $this->appendEvent($planId, 'INDEPENDENT_REVIEW_COMPLETED', 12, array(
                'review_id' => $reviewId,
                'status' => 'READY',
            ), $actorUserId);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return array('review_id' => $reviewId, 'status' => 'READY');
    }

    /** @return array{stage:string} */
    public function continueToApply(int $planId, int $actorUserId): array
    {
        $this->assertPlanOwner($planId, $actorUserId);
        $review = $this->row(
            'SELECT * FROM ' . self::TABLES['reviews']
            . ' WHERE plan_id=? ORDER BY id DESC LIMIT 1',
            array($planId)
        );
        $payload = $review === null ? array() : $this->decodeJson($review['review_payload_json'] ?? null);
        $status = strtoupper((string)($payload['status'] ?? $review['status'] ?? ''));
        if ((string)($review['status'] ?? '') !== 'approved'
            || $status !== 'READY'
            || (string)($payload['outcome'] ?? '') !== 'READY_TO_APPLY') {
            throw new RuntimeException(
                'Independent Review must complete governed clarification before continuing.'
            );
        }
        $this->updatePlan($planId, array('stage' => 'operations', 'updated_by' => $actorUserId));
        $this->appendEvent($planId, 'REVIEW_ACCEPTED_FOR_APPLY', 13, array(
            'review_id' => (int)($review['id'] ?? 0),
        ), $actorUserId);
        return array('stage' => 'operations');
    }

    /**
     * Persist a node beneath a proposal after verifying plan ownership.
     *
     * @param array<string,mixed> $data
     */
    public function saveStructureNode(
        int $planId,
        int $structureProposalId,
        array $data
    ): int {
        $proposal = $this->row(
            'SELECT id FROM ' . self::TABLES['structure_proposals']
            . ' WHERE id=? AND plan_id=? LIMIT 1',
            array($structureProposalId, $planId)
        );
        if ($proposal === null) {
            throw new RuntimeException('Structure proposal not found in this Change Plan.');
        }
        $data['structure_proposal_id'] = $structureProposalId;
        return $this->insert('structure_nodes', $data);
    }

    /** @param array<string,mixed> $plan */
    public function primaryVersionId(array $plan): int
    {
        foreach (array('primary_manual_version_id', 'primary_book_version_id', 'book_version_id') as $key) {
            if ((int)($plan[$key] ?? 0) > 0) {
                return (int)$plan[$key];
            }
        }
        $metadata = $plan['metadata_json'] ?? null;
        return (int)($metadata['primary_version']['id'] ?? 0);
    }

    private function assertPlanOwner(int $planId, int $actorUserId): array
    {
        $plan = $this->getPlan($planId);
        if ($actorUserId <= 0 || (int)($plan['owner_id'] ?? 0) !== $actorUserId) {
            throw new RuntimeException('Only the Change Plan owner can continue this wizard.');
        }
        return $plan;
    }

    /** @return array<string,mixed> */
    private function assertPlanOwnerAtStage(
        int $planId,
        int $actorUserId,
        string $requiredStage
    ): array {
        $plan = $this->assertPlanOwner($planId, $actorUserId);
        if ((string)($plan['stage'] ?? '') !== $requiredStage) {
            throw new RuntimeException(
                'This accepted Wizard checkpoint is frozen at the current stage.'
            );
        }
        return $plan;
    }

    /** @return array<string,mixed> */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return array();
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : array();
    }

    /** @return list<array<string,mixed>> */
    private function planRows(string $collection, int $planId): array
    {
        $table = self::TABLES[$collection];
        if ($collection === 'structure_nodes') {
            return $this->rows(
                'SELECT n.* FROM ' . $table . ' n
                 JOIN ' . self::TABLES['structure_proposals'] . ' p
                   ON p.id=n.structure_proposal_id
                 WHERE p.plan_id=? ORDER BY n.structure_proposal_id,n.depth,n.sort_order,n.id',
                array($planId)
            );
        }
        $foreignKey = $this->planForeignKey($table);
        if ($foreignKey === null) {
            return array();
        }
        $rows = $this->rows(
            'SELECT * FROM ' . $table . ' WHERE ' . $this->quoteIdentifier($foreignKey) . '=?',
            array($planId)
        );
        usort(
            $rows,
            static fn(array $left, array $right): int =>
                (int)($left['id'] ?? 0) <=> (int)($right['id'] ?? 0)
        );
        return $rows;
    }

    private function deletePlanRows(string $collection, int $planId): void
    {
        $table = self::TABLES[$collection];
        $foreignKey = $this->planForeignKey($table);
        if ($foreignKey !== null) {
            $this->pdo->prepare(
                'DELETE FROM ' . $table . ' WHERE ' . $this->quoteIdentifier($foreignKey) . '=?'
            )->execute(array($planId));
        }
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function withPlanForeignKey(string $table, int $planId, array $data): array
    {
        $foreignKey = $this->planForeignKey($table);
        if ($foreignKey === null) {
            throw new RuntimeException($table . ' has no supported plan foreign key.');
        }
        $data[$foreignKey] = $planId;
        return $data;
    }

    private function planForeignKey(string $table): ?string
    {
        $columns = $this->columns($table);
        foreach (array('plan_id', 'architect_plan_id', 'change_plan_id') as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $data */
    private function insert(string $collection, array $data): int
    {
        $table = self::TABLES[$collection] ?? null;
        if ($table === null) {
            throw new InvalidArgumentException('Unsupported architect collection.');
        }
        $data = $this->filterData($data, $this->columns($table));
        unset($data['id']);
        if ($data === array()) {
            throw new RuntimeException('No compatible columns are available for ' . $table . '.');
        }
        $columns = array_keys($data);
        $sql = 'INSERT INTO ' . $table . ' (' . implode(',', array_map(
            fn(string $column): string => $this->quoteIdentifier($column),
            $columns
        )) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')';
        $this->pdo->prepare($sql)->execute(array_map(
            fn(string $column): mixed => $this->databaseValue($data[$column], $column),
            $columns
        ));
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $data
     * @param list<string> $columns
     * @return array<string,mixed>
     */
    private function filterData(array $data, array $columns): array
    {
        return array_intersect_key($data, array_fill_keys($columns, true));
    }

    private function databaseValue(mixed $value, string $column): mixed
    {
        if (is_array($value) || is_object($value)) {
            return $this->json($value);
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        return $value;
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        if (isset($this->columnCache[$table])) {
            return $this->columnCache[$table];
        }
        try {
            if ((string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $result = $this->pdo->query('PRAGMA table_info(' . $this->quoteIdentifier($table) . ')');
                $rows = $result !== false ? ($result->fetchAll(PDO::FETCH_ASSOC) ?: array()) : array();
                return $this->columnCache[$table] = array_values(array_map(
                    static fn(array $row): string => (string)$row['name'],
                    $rows
                ));
            }
            $stmt = $this->pdo->prepare(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION'
            );
            $stmt->execute(array($table));
            return $this->columnCache[$table] = array_values(array_map(
                static fn(array $row): string => (string)$row['COLUMN_NAME'],
                $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array()
            ));
        } catch (Throwable) {
            return $this->columnCache[$table] = array();
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function decodeRow(array $row): array
    {
        foreach ($row as $key => $value) {
            if (!str_ends_with((string)$key, '_json') || !is_string($value) || $value === '') {
                continue;
            }
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $row[$key] = $decoded;
            }
        }
        return $row;
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $sql, array $params = array()): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    /** @return array<string,mixed>|null */
    private function row(string $sql, array $params = array()): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier) !== 1) {
            throw new InvalidArgumentException('Invalid SQL identifier.');
        }
        return '`' . $identifier . '`';
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function draftPayloadFingerprint(mixed $payload): string
    {
        return hash('sha256', $this->json($this->canonicalizeJsonValue($payload)));
    }

    private function matchesFrozenReviewDraft(
        int $planId,
        int $draftId,
        array $currentPayload,
        string $storedFingerprint
    ): bool {
        $baseline = $this->row(
            'SELECT draft_baseline_json FROM ' . self::TABLES['review_baselines']
            . ' WHERE id=(
                SELECT MAX(id) FROM ' . self::TABLES['review_baselines'] . '
                WHERE plan_id=?
            )',
            array($planId)
        );
        if ($baseline === null) {
            return false;
        }
        $frozenDraft = $this->decodeJson($baseline['draft_baseline_json'] ?? null);
        $frozenPayload = $this->decodeJson($frozenDraft['draft_payload_json'] ?? null);
        return (int)($frozenDraft['id'] ?? 0) === $draftId
            && $storedFingerprint !== ''
            && hash_equals(
                (string)($frozenDraft['content_fingerprint'] ?? ''),
                $storedFingerprint
            )
            && hash_equals(
                $this->draftPayloadFingerprint($frozenPayload),
                $this->draftPayloadFingerprint($currentPayload)
            );
    }

    private function canonicalizeJsonValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(
                fn(mixed $item): mixed => $this->canonicalizeJsonValue($item),
                $value
            );
        }
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        $canonical = array();
        foreach ($keys as $key) {
            $canonical[$key] = $this->canonicalizeJsonValue($value[$key]);
        }
        return $canonical;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4),
            substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}
