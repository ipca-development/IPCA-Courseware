<?php
declare(strict_types=1);

require_once __DIR__ . '/BooksManualsChangePlanService.php';

/**
 * Monotonic Independent Review resolution.
 *
 * This service owns review metadata only. It never calls the Architect,
 * supersedes accepted baselines, or mutates controlled publishing content.
 */
final class BooksManualsChangeReviewResolutionService
{
    public const INFORMATIONAL = 'INFORMATIONAL';
    public const MECHANICAL_FIX = 'MECHANICAL_FIX';
    public const HUMAN_DECISION_REQUIRED = 'HUMAN_DECISION_REQUIRED';
    public const TARGETED_AUTHOR_CORRECTION = 'TARGETED_AUTHOR_CORRECTION';
    public const HARD_INTEGRITY_BLOCKER = 'HARD_INTEGRITY_BLOCKER';
    public const POTENTIAL_SCOPE_DEFECT = 'POTENTIAL_SCOPE_DEFECT';

    public function __construct(
        private PDO $pdo,
        private ?BooksManualsChangePlanService $plans = null
    ) {
        $this->plans ??= new BooksManualsChangePlanService($pdo);
    }

    /** @param array<string,mixed> $prepared @return array<string,mixed> */
    public function initializeReview(
        int $planId,
        int $reviewId,
        array $prepared,
        int $actorUserId
    ): array {
        $plan = $this->plans->getPlan($planId);
        if ((int)($plan['owner_id'] ?? 0) !== $actorUserId) {
            throw new RuntimeException('Only the Change Plan owner can establish the review baseline.');
        }
        $baseline = $this->freezeBaseline($planId, $reviewId, $actorUserId);
        $reviewChecks = array_values(array_filter(
            (array)($prepared['review_checks'] ?? array()),
            'is_array'
        ));
        if ($reviewChecks !== array()) {
            foreach ($reviewChecks as $check) {
                $this->persistReviewCheck(
                    $planId,
                    $reviewId,
                    (int)$baseline['id'],
                    $check,
                    $prepared,
                    $actorUserId
                );
            }
        } else {
            $issues = array_values(array_unique(array_filter(array_map(
                static fn(mixed $issue): string => trim(is_string($issue) ? $issue : ''),
                (array)($prepared['issues'] ?? array())
            ))));
            foreach ($issues as $issue) {
                $this->persistFinding(
                    $planId,
                    $reviewId,
                    (int)$baseline['id'],
                    $issue,
                    $prepared,
                    $actorUserId
                );
            }
        }
        $failed = count(array_filter(
            $reviewChecks,
            static fn(array $check): bool => (string)($check['status'] ?? '') === 'FAIL'
        ));
        $this->plans->updatePlan($planId, array(
            'stage' => 'review',
            'status' => ($reviewChecks !== array() ? $failed === 0 : ($issues ?? array()) === array())
                ? 'ready_for_review'
                : 'blocked',
            'updated_by' => $actorUserId,
        ));
        $this->recordCycle($planId, $reviewId, (int)$baseline['id']);
        $state = $this->state($planId, $reviewId);
        $this->plans->appendEvent($planId, 'INDEPENDENT_REVIEW_RESOLUTION_INITIALIZED', 12, array(
            'review_id' => $reviewId,
            'baseline_id' => (int)$baseline['id'],
            'baseline_fingerprint' => (string)$baseline['baseline_fingerprint'],
            'finding_counts' => $state['counts'],
            'stage_preserved' => 'review',
        ), $actorUserId);
        return $state;
    }

    /** @return array<string,mixed> */
    public function state(int $planId, ?int $reviewId = null): array
    {
        if ($reviewId === null || $reviewId <= 0) {
            $stmt = $this->pdo->prepare(
                'SELECT MAX(id) FROM ipca_manual_ai_architect_reviews WHERE plan_id=?'
            );
            $stmt->execute(array($planId));
            $reviewId = (int)$stmt->fetchColumn();
        }
        $baseline = $this->row(
            'SELECT * FROM ipca_manual_ai_architect_review_baselines
             WHERE id=(
                 SELECT MAX(id) FROM ipca_manual_ai_architect_review_baselines
                 WHERE plan_id=? AND review_id=?
             )',
            array($planId, $reviewId)
        );
        $findings = $this->rows(
            'SELECT f.*,m.check_id,m.check_version,m.category,m.severity,
                    m.review_status,m.resolution_status,m.affected_nodes_json,
                    m.required_invariant,m.observed_state,m.evidence_references_json,
                    m.allowed_repair_scope_json,m.known_limitations_json,
                    m.first_seen_at,m.last_verified_at,m.verified_at check_verified_at
             FROM ipca_manual_ai_architect_review_findings f
             LEFT JOIN ipca_manual_ai_architect_review_check_metadata m ON m.finding_id=f.id
             WHERE f.plan_id=? AND f.review_id=?',
            array($planId, $reviewId)
        );
        $questions = $this->rows(
            'SELECT q.* FROM ipca_manual_ai_architect_review_questions q
             JOIN ipca_manual_ai_architect_review_findings f ON f.id=q.finding_id
             WHERE q.plan_id=? AND f.review_id=?',
            array($planId, $reviewId)
        );
        $answers = $this->rows(
            'SELECT a.* FROM ipca_manual_ai_architect_review_answers a
             JOIN ipca_manual_ai_architect_review_findings f ON f.id=a.finding_id
             WHERE a.plan_id=? AND f.review_id=?',
            array($planId, $reviewId)
        );
        $patches = $this->rows(
            'SELECT p.* FROM ipca_manual_ai_architect_review_patches p
             JOIN ipca_manual_ai_architect_review_baselines b ON b.id=p.baseline_id
             WHERE p.plan_id=? AND b.review_id=?',
            array($planId, $reviewId)
        );
        $counts = array_fill_keys(array(
            self::INFORMATIONAL,
            self::MECHANICAL_FIX,
            self::HUMAN_DECISION_REQUIRED,
            self::TARGETED_AUTHOR_CORRECTION,
            self::HARD_INTEGRITY_BLOCKER,
            self::POTENTIAL_SCOPE_DEFECT,
        ), 0);
        $reviewStatusCounts = array('PASS' => 0, 'FAIL' => 0, 'INFORMATIONAL' => 0);
        $resolutionCounts = array('UNRESOLVED' => 0, 'VERIFIED' => 0, 'BLOCKED' => 0);
        $unresolved = 0;
        $hardBlockers = 0;
        foreach ($findings as $finding) {
            $class = (string)$finding['finding_class'];
            $counts[$class] = ($counts[$class] ?? 0) + 1;
            $reviewStatus = strtoupper((string)($finding['review_status'] ?? ''));
            $resolutionStatus = strtoupper((string)($finding['resolution_status'] ?? ''));
            if (isset($reviewStatusCounts[$reviewStatus])) {
                $reviewStatusCounts[$reviewStatus]++;
            }
            if (isset($resolutionCounts[$resolutionStatus])) {
                $resolutionCounts[$resolutionStatus]++;
            }
            $isUnresolved = $resolutionStatus !== ''
                ? in_array($resolutionStatus, array('UNRESOLVED', 'BLOCKED'), true)
                : !in_array((string)$finding['status'], array('closed', 'verified'), true);
            if ($isUnresolved && (int)$finding['blocking'] === 1) {
                $unresolved++;
                if ($resolutionStatus === 'BLOCKED' || $class === self::HARD_INTEGRITY_BLOCKER) {
                    $hardBlockers++;
                }
            }
        }
        $pendingQuestions = array_values(array_filter(
            $questions,
            static fn(array $question): bool => (string)$question['status'] === 'pending'
        ));
        $pendingPatches = array_values(array_filter(
            $patches,
            static fn(array $patch): bool => in_array(
                strtoupper((string)$patch['status']),
                array('PROPOSED', 'ADJUSTMENT_REQUESTED', 'HUMAN_ACCEPTED_PENDING_VERIFICATION'),
                true
            )
        ));
        $cycles = $this->rows(
            'SELECT * FROM ipca_manual_ai_architect_review_cycles
             WHERE plan_id=? AND review_id=?',
            array($planId, $reviewId)
        );
        $diverged = false;
        foreach ($cycles as $cycle) {
            $cycleStatus = (string)($cycle['status'] ?? '');
            if ($cycleStatus === 'REVIEW_DIVERGENCE_DETECTED') {
                $diverged = true;
            } elseif ($cycleStatus === 'REVIEW_DIVERGENCE_RESOLVED') {
                $diverged = false;
            }
        }
        $ready = $baseline !== null
            && $reviewId > 0
            && $unresolved === 0
            && $pendingQuestions === array()
            && $pendingPatches === array()
            && !$diverged;
        return array(
            'schema' => 'ipca.manual-change-convergent-review-state.v1',
            'plan_id' => $planId,
            'review_id' => $reviewId,
            'baseline_id' => (int)($baseline['id'] ?? 0),
            'baseline_fingerprint' => (string)($baseline['baseline_fingerprint'] ?? ''),
            'outcome' => $ready ? 'READY_TO_APPLY'
                : ($pendingQuestions !== array() ? 'HUMAN_CLARIFICATION_REQUIRED' : 'CONFIRMED_DEFECT'),
            'ready_to_apply' => $ready,
            'unresolved_material_findings' => $unresolved,
            'hard_blockers' => $hardBlockers,
            'counts' => $counts,
            'check_counts' => $reviewStatusCounts,
            'resolution_counts' => $resolutionCounts,
            'findings' => $findings,
            'questions' => $questions,
            'answers' => $answers,
            'pending_question' => $pendingQuestions[0] ?? null,
            'patches' => $patches,
            'convergence_cycles' => $cycles,
            'review_divergence_detected' => $diverged,
            'repair_groups' => $this->repairGroups($findings),
        );
    }

    /** @param list<string> $selectedChoiceIds @return array<string,mixed> */
    public function answerQuestion(
        int $planId,
        int $questionId,
        array $selectedChoiceIds,
        string $explanation,
        int $actorUserId
    ): array {
        $plan = $this->plans->getPlan($planId);
        if ((int)($plan['owner_id'] ?? 0) !== $actorUserId) {
            throw new RuntimeException('Only the Change Plan owner can answer review questions.');
        }
        if ((string)($plan['stage'] ?? '') !== 'review') {
            throw new RuntimeException('Review questions can only be resolved while the plan remains in Independent Review.');
        }
        $question = $this->row(
            'SELECT q.*,f.review_id,f.finding_class,f.status finding_status
             FROM ipca_manual_ai_architect_review_questions q
             JOIN ipca_manual_ai_architect_review_findings f ON f.id=q.finding_id
             WHERE q.id=? AND q.plan_id=? LIMIT 1',
            array($questionId, $planId)
        );
        if ($question === null) {
            throw new RuntimeException('Review question not found.');
        }
        $existing = $this->row(
            'SELECT * FROM ipca_manual_ai_architect_review_answers WHERE question_id=? LIMIT 1',
            array($questionId)
        );
        if ($existing !== null) {
            return $this->state($planId, (int)$question['review_id']);
        }
        $choices = is_array($question['choices_json'] ?? null)
            ? $question['choices_json']
            : $this->decode((string)($question['choices_json'] ?? ''));
        $choiceMap = array();
        foreach ($choices as $choice) {
            if (is_array($choice)) {
                $choiceMap[(string)($choice['id'] ?? '')] = $choice;
            }
        }
        $selected = array();
        foreach (array_values(array_unique(array_map('strval', $selectedChoiceIds))) as $choiceId) {
            if (!isset($choiceMap[$choiceId])) {
                throw new InvalidArgumentException('Select one of the governed review choices.');
            }
            $selected[] = $choiceMap[$choiceId];
        }
        if ($selected === array()
            || ((string)$question['question_type'] !== 'MULTIPLE_CHOICE' && count($selected) !== 1)) {
            throw new InvalidArgumentException('This review question requires one governed answer.');
        }
        $consequences = array_values(array_unique(array_map(
            static fn(array $choice): string => (string)($choice['consequence'] ?? 'TARGETED_WORDING_CHANGE_REQUIRED'),
            $selected
        )));
        $consequence = count($consequences) === 1
            ? $consequences[0]
            : 'TARGETED_WORDING_CHANGE_REQUIRED';
        if (!in_array($consequence, array(
            'NO_MANUAL_CHANGE_REQUIRED',
            'TARGETED_WORDING_CHANGE_REQUIRED',
            'STRUCTURAL_CONSEQUENCE',
        ), true)) {
            throw new RuntimeException('The selected answer has an invalid manual consequence.');
        }
        $fact = array(
            'schema' => 'ipca.manual-change-governed-fact.v1',
            'question_id' => $questionId,
            'finding_id' => (int)$question['finding_id'],
            'statement' => implode('; ', array_map(
                static fn(array $choice): string => (string)($choice['governed_fact'] ?? $choice['label'] ?? ''),
                $selected
            )),
            'selected_choice_ids' => array_column($selected, 'id'),
            'affected_sections' => is_array($question['affected_sections_json'] ?? null)
                ? $question['affected_sections_json']
                : $this->decode((string)($question['affected_sections_json'] ?? '')),
            'evidence' => is_array($question['evidence_json'] ?? null)
                ? $question['evidence_json']
                : $this->decode((string)($question['evidence_json'] ?? '')),
            'actor_id' => $actorUserId,
            'recorded_at' => gmdate(DATE_ATOM),
        );
        $answerFingerprint = hash('sha256', $this->json(array(
            'question_fingerprint' => (string)$question['question_fingerprint'],
            'selected' => array_column($selected, 'id'),
            'explanation' => trim($explanation),
            'actor_id' => $actorUserId,
        )));
        $this->pdo->beginTransaction();
        try {
            $this->plans->save('review_answers', $planId, array(
                'answer_uuid' => $this->uuid(),
                'finding_id' => (int)$question['finding_id'],
                'question_id' => $questionId,
                'answer_fingerprint' => $answerFingerprint,
                'question_snapshot_json' => array(
                    'title' => $question['title'],
                    'prompt' => $question['prompt'],
                    'why_asking' => $question['why_asking'],
                ),
                'choices_snapshot_json' => $choices,
                'selected_answer_json' => $selected,
                'explanation' => trim($explanation),
                'affected_sections_json' => $fact['affected_sections'],
                'evidence_snapshot_json' => $fact['evidence'],
                'governed_fact_json' => $fact,
                'consequence' => $consequence,
                'wording_change_required' => $consequence !== 'NO_MANUAL_CHANGE_REQUIRED',
                'actor_id' => $actorUserId,
            ));
            $this->pdo->prepare(
                "UPDATE ipca_manual_ai_architect_review_questions SET status='answered' WHERE id=?"
            )->execute(array($questionId));
            $findingStatus = $consequence === 'NO_MANUAL_CHANGE_REQUIRED'
                ? 'closed'
                : ($consequence === 'STRUCTURAL_CONSEQUENCE' ? 'blocked' : 'patch_pending');
            $this->pdo->prepare(
                'UPDATE ipca_manual_ai_architect_review_findings
                 SET status=?,resolution_json=?,
                     resolved_at=CASE WHEN ?=\'closed\' THEN CURRENT_TIMESTAMP(3) ELSE NULL END
                 WHERE id=?'
            )->execute(array(
                $findingStatus,
                $this->json(array('governed_fact' => $fact, 'consequence' => $consequence)),
                $findingStatus,
                (int)$question['finding_id'],
            ));
            $this->plans->appendEvent($planId, 'INDEPENDENT_REVIEW_GOVERNED_FACT_RECORDED', 12, array(
                'finding_id' => (int)$question['finding_id'],
                'question_id' => $questionId,
                'answer_fingerprint' => $answerFingerprint,
                'governed_fact' => $fact,
                'consequence' => $consequence,
                'stage_preserved' => 'review',
            ), $actorUserId);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        $this->recordCycle(
            $planId,
            (int)$question['review_id'],
            $this->baselineIdForReview((int)$question['review_id'])
        );
        return $this->state($planId, (int)$question['review_id']);
    }

    /** @param array<string,mixed> $patchResult @param list<int> $findingIds */
    public function persistTargetedPatch(
        int $planId,
        int $baselineId,
        int $parentDraftId,
        array $findingIds,
        array $patchResult,
        int $actorUserId
    ): array {
        if (($patchResult['schema'] ?? '') !== 'ipca.manual-change-targeted-patch.v1'
            || empty($patchResult['accepted_structure_nodes_unchanged'])
            || empty($patchResult['lifecycle_unchanged'])
            || !empty($patchResult['production_applied'])) {
            throw new RuntimeException('The targeted correction violated the frozen review baseline.');
        }
        $findingIds = array_values(array_unique(array_filter(array_map('intval', $findingIds))));
        if ($findingIds === array()) {
            throw new InvalidArgumentException('A targeted correction must resolve a specific review finding.');
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'UPDATE ipca_manual_ai_architect_plans SET id=id WHERE id=?'
            )->execute(array($planId));
            $plan = $this->plans->getPlan($planId);
            if ((int)($plan['owner_id'] ?? 0) !== $actorUserId
                || (string)($plan['stage'] ?? '') !== 'review') {
                throw new RuntimeException(
                    'Targeted corrections may only be proposed during Independent Review.'
                );
            }
            $latestDraft = $this->latestAcceptedDraft($planId);
            if ($latestDraft === null || (int)$latestDraft['id'] !== $parentDraftId) {
                throw new RuntimeException(
                    'The targeted correction preview is stale because the review candidate changed.'
                );
            }
            $findingRows = $this->rows(
                'SELECT f.id,f.review_id,m.resolution_status
                 FROM ipca_manual_ai_architect_review_findings f
                 JOIN ipca_manual_ai_architect_review_check_metadata m ON m.finding_id=f.id
                 WHERE f.plan_id=? AND f.id IN ('
                    . implode(',', array_fill(0, count($findingIds), '?')) . ')',
                array_merge(array($planId), $findingIds)
            );
            $reviewIds = array_values(array_unique(array_map(
                static fn(array $row): int => (int)$row['review_id'],
                $findingRows
            )));
            if (count($findingRows) !== count($findingIds)
                || count($reviewIds) !== 1
                || array_values(array_filter(
                    $findingRows,
                    static fn(array $row): bool =>
                        strtoupper((string)$row['resolution_status']) !== 'UNRESOLVED'
                )) !== array()) {
                throw new RuntimeException(
                    'The targeted correction findings are no longer unresolved and patchable.'
                );
            }
            if ($baselineId !== $this->baselineIdForReview($reviewIds[0])) {
                throw new RuntimeException(
                    'The targeted correction preview is stale because the review baseline changed.'
                );
            }
            $fingerprint = hash('sha256', $this->json(array(
                'baseline_id' => $baselineId,
                'parent_draft_id' => $parentDraftId,
                'finding_ids' => $findingIds,
                'scope' => $patchResult['scope_sections'],
                'candidate' => $patchResult['candidate_proposal'],
            )));
            $existing = $this->row(
                'SELECT * FROM ipca_manual_ai_architect_review_patches
                 WHERE plan_id=? AND patch_fingerprint=? LIMIT 1',
                array($planId, $fingerprint)
            );
            if ($existing !== null) {
                if (strtoupper((string)($existing['status'] ?? '')) === 'ADJUSTMENT_REQUESTED') {
                    throw new RuntimeException(
                        'The regenerated correction did not address the requested adjustment.'
                    );
                }
                $this->pdo->commit();
                return $existing;
            }
            $this->pdo->prepare(
                "UPDATE ipca_manual_ai_architect_review_patches
                 SET status='SUPERSEDED'
                 WHERE plan_id=? AND UPPER(status)='ADJUSTMENT_REQUESTED'"
            )->execute(array($planId));
            $patchId = $this->plans->save('review_patches', $planId, array(
                'patch_uuid' => $this->uuid(),
                'baseline_id' => $baselineId,
                'parent_draft_id' => $parentDraftId,
                'finding_ids_json' => $findingIds,
                'scope_json' => array(
                    'sections' => $patchResult['scope_sections'],
                    'nodes' => (array)($patchResult['allowed_repair_nodes'] ?? array()),
                    'failed_check_ids' => (array)($patchResult['failed_check_ids'] ?? array()),
                ),
                'before_payload_json' => array_map(
                    static fn(array $change): mixed => $change['before'] ?? array(),
                    (array)$patchResult['changed_sections']
                ),
                'proposed_payload_json' => $patchResult,
                'unchanged_fingerprints_json' => $patchResult['unchanged_section_fingerprints'],
                'patch_fingerprint' => $fingerprint,
                'status' => 'PROPOSED',
                'proposed_by' => $actorUserId,
            ));
            $this->plans->appendEvent(
                $planId,
                'INDEPENDENT_REVIEW_TARGETED_PATCH_PROPOSED',
                12,
                array(
                    'patch_id' => $patchId,
                    'finding_ids' => $findingIds,
                    'scope_sections' => $patchResult['scope_sections'],
                    'patch_fingerprint' => $fingerprint,
                    'stage_preserved' => 'review',
                ),
                $actorUserId
            );
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        return (array)$this->row(
            'SELECT * FROM ipca_manual_ai_architect_review_patches WHERE id=?',
            array($patchId)
        );
    }

    /** @return array<string,mixed> */
    public function acceptTargetedPatch(
        int $planId,
        int $patchId,
        int $actorUserId,
        BooksManualsChangeReviewerService $reviewer
    ): array {
        $plan = $this->plans->getPlan($planId);
        if ((int)($plan['owner_id'] ?? 0) !== $actorUserId
            || (string)($plan['stage'] ?? '') !== 'review') {
            throw new RuntimeException('Targeted corrections may only be accepted during Independent Review.');
        }
        $patch = $this->row(
            'SELECT * FROM ipca_manual_ai_architect_review_patches WHERE id=? AND plan_id=? LIMIT 1',
            array($patchId, $planId)
        );
        if ($patch === null || !in_array(
            strtoupper((string)$patch['status']),
            array('PROPOSED', 'HUMAN_ACCEPTED_PENDING_VERIFICATION'),
            true
        )) {
            throw new RuntimeException('Targeted correction is not available for acceptance.');
        }
        $acceptanceAlreadyRecorded = strtoupper((string)$patch['status'])
            === 'HUMAN_ACCEPTED_PENDING_VERIFICATION';
        $patchPayload = (array)$patch['proposed_payload_json'];
        $candidate = (array)($patchPayload['candidate_proposal'] ?? array());
        $parentDraft = $this->row(
            'SELECT * FROM ipca_manual_ai_architect_drafts WHERE id=? AND plan_id=? LIMIT 1',
            array((int)$patch['parent_draft_id'], $planId)
        );
        if ($parentDraft === null) {
            throw new RuntimeException('The accepted draft baseline for this correction is unavailable.');
        }
        $latestAcceptedDraft = $this->latestAcceptedDraft($planId);
        if ($latestAcceptedDraft === null
            || (int)$latestAcceptedDraft['id'] !== (int)$parentDraft['id']) {
            throw new RuntimeException(
                'This correction preview is stale because another governed correction changed the review candidate.'
            );
        }
        $parentPayload = is_array($parentDraft['draft_payload_json'])
            ? $parentDraft['draft_payload_json']
            : $this->decode((string)$parentDraft['draft_payload_json']);
        foreach ((array)$patch['unchanged_fingerprints_json'] as $section => $fingerprint) {
            $candidateSection = $candidate['section_drafts'][$section] ?? null;
            if (!hash_equals((string)$fingerprint, hash('sha256', $this->json($candidateSection)))) {
                throw new RuntimeException('Unrelated accepted wording changed before patch acceptance.');
            }
        }
        foreach ((array)($patchPayload['frozen_node_fingerprints'] ?? array()) as $number => $fingerprint) {
            $candidateContent = null;
            foreach ((array)($candidate['section_drafts'] ?? array()) as $draft) {
                if (array_key_exists((string)$number, (array)($draft['nodes'] ?? array()))) {
                    $candidateContent = $draft['nodes'][(string)$number];
                    break;
                }
            }
            if (!hash_equals((string)$fingerprint, hash('sha256', $this->json($candidateContent)))) {
                throw new RuntimeException('A frozen unrelated node changed before patch acceptance.');
            }
        }
        $candidate['wizard_status'] = 'accepted';
        $candidate['targeted_patch'] = array(
            'patch_id' => $patchId,
            'parent_draft_id' => (int)$patch['parent_draft_id'],
            'finding_ids' => (array)$patch['finding_ids_json'],
            'accepted_by' => $actorUserId,
            'accepted_at' => gmdate(DATE_ATOM),
        );
        $findingIds = array_values(array_map('intval', (array)$patch['finding_ids_json']));
        $targetCheckIds = array_values(array_filter(array_map(
            static fn(array $row): string => (string)($row['check_id'] ?? ''),
            $findingIds === array() ? array() : $this->rows(
                'SELECT m.check_id FROM ipca_manual_ai_architect_review_check_metadata m
                 WHERE m.finding_id IN (' . implode(',', array_fill(0, count($findingIds), '?')) . ')',
                $findingIds
            )
        )));
        if (!$acceptanceAlreadyRecorded) {
            $this->pdo->beginTransaction();
            try {
                $this->pdo->prepare(
                    'UPDATE ipca_manual_ai_architect_plans SET id=id WHERE id=?'
                )->execute(array($planId));
                $acceptStmt = $this->pdo->prepare(
                    "UPDATE ipca_manual_ai_architect_review_patches
                     SET status='HUMAN_ACCEPTED_PENDING_VERIFICATION',accepted_by=?,
                         accepted_at=CURRENT_TIMESTAMP(3)
                     WHERE id=? AND plan_id=? AND UPPER(status)='PROPOSED'"
                );
                $acceptStmt->execute(array($actorUserId, $patchId, $planId));
                if ($acceptStmt->rowCount() !== 1) {
                    throw new RuntimeException(
                        'Targeted correction changed state before human acceptance could be recorded.'
                    );
                }
                $this->plans->appendEvent(
                    $planId,
                    'INDEPENDENT_REVIEW_PATCH_HUMAN_ACCEPTED',
                    12,
                    array(
                        'patch_id' => $patchId,
                        'parent_draft_id' => (int)$patch['parent_draft_id'],
                        'finding_ids' => $findingIds,
                        'check_ids' => $targetCheckIds,
                        'verification_pending' => true,
                        'stage_preserved' => 'review',
                    ),
                    $actorUserId
                );
                $this->pdo->commit();
            } catch (Throwable $error) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $error;
            }
        }
        $verification = $reviewer->verifyTargetedPatch(
            $parentPayload,
            $candidate,
            array_values((array)($patch['scope_json']['sections'] ?? array())),
            array_values((array)($patch['scope_json']['nodes'] ?? $patchPayload['allowed_repair_nodes'] ?? array())),
            $targetCheckIds
        );
        $encoded = $this->json($candidate);
        $reviewId = (int)($this->row(
            'SELECT review_id FROM ipca_manual_ai_architect_review_findings
             WHERE plan_id=? AND id=?',
            array($planId, $findingIds[0] ?? 0)
        )['review_id'] ?? 0);
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'UPDATE ipca_manual_ai_architect_plans SET id=id WHERE id=?'
            )->execute(array($planId));
            $lockedPatch = $this->row(
                'SELECT * FROM ipca_manual_ai_architect_review_patches
                 WHERE id=? AND plan_id=? LIMIT 1',
                array($patchId, $planId)
            );
            $lockedLatestDraft = $this->latestAcceptedDraft($planId);
            $lockedFindings = $findingIds === array() ? array() : $this->rows(
                'SELECT f.id,m.resolution_status
                 FROM ipca_manual_ai_architect_review_findings f
                 JOIN ipca_manual_ai_architect_review_check_metadata m ON m.finding_id=f.id
                 WHERE f.plan_id=? AND f.id IN ('
                    . implode(',', array_fill(0, count($findingIds), '?')) . ')',
                array_merge(array($planId), $findingIds)
            );
            $lockedStatus = strtoupper((string)($lockedPatch['status'] ?? ''));
            if (in_array($lockedStatus, array('VERIFIED', 'VERIFICATION_FAILED'), true)) {
                $this->pdo->commit();
                return $this->state($planId, $reviewId);
            }
            if ($lockedPatch === null
                || $lockedStatus !== 'HUMAN_ACCEPTED_PENDING_VERIFICATION') {
                throw new RuntimeException(
                    'Targeted correction is no longer pending verification.'
                );
            }
            $stale = $lockedLatestDraft === null
                || (int)$lockedLatestDraft['id'] !== (int)$parentDraft['id']
                || $this->baselineIdForReview($reviewId) !== (int)$patch['baseline_id']
                || count($lockedFindings) !== count($findingIds)
                || array_values(array_filter(
                    $lockedFindings,
                    static fn(array $row): bool =>
                        strtoupper((string)$row['resolution_status']) !== 'UNRESOLVED'
                )) !== array();
            if ($stale) {
                $this->pdo->prepare(
                    "UPDATE ipca_manual_ai_architect_review_patches
                     SET status='SUPERSEDED'
                     WHERE id=? AND plan_id=?
                       AND UPPER(status)='HUMAN_ACCEPTED_PENDING_VERIFICATION'"
                )->execute(array($patchId, $planId));
                $this->plans->appendEvent(
                    $planId,
                    'INDEPENDENT_REVIEW_STALE_PATCH_SUPERSEDED',
                    12,
                    array(
                        'patch_id' => $patchId,
                        'parent_draft_id' => (int)$patch['parent_draft_id'],
                        'stage_preserved' => 'review',
                    ),
                    $actorUserId
                );
                $this->pdo->commit();
                throw new RuntimeException(
                    'This accepted correction became stale and was superseded before verification.'
                );
            }
            $versionStmt = $this->pdo->prepare(
                'SELECT COALESCE(MAX(draft_version),0)+1
                 FROM ipca_manual_ai_architect_drafts WHERE plan_id=?'
            );
            $versionStmt->execute(array($planId));
            $draftVersion = max(1, (int)$versionStmt->fetchColumn());
            $beforeUnresolved = $this->unresolvedCheckIds($planId, $reviewId);
            $draftId = $this->plans->save('drafts', $planId, array(
                'draft_uuid' => $this->uuid(),
                'structure_proposal_id' => $parentDraft['structure_proposal_id'],
                'target_book_version_id' => $parentDraft['target_book_version_id'],
                'draft_version' => $draftVersion,
                'status' => 'generated',
                'source_fingerprint' => $parentDraft['source_fingerprint'],
                'content_fingerprint' => hash('sha256', $encoded),
                'draft_payload_json' => $candidate,
                'created_by' => $actorUserId,
            ));
            $newBaseline = $this->freezeBaseline($planId, $reviewId, $actorUserId);
            $reconciliation = $this->reconcileVerificationChecks(
                $planId,
                $reviewId,
                (int)$newBaseline['id'],
                (array)($verification['review_checks'] ?? array()),
                $actorUserId,
                array(
                    'patch_id' => $patchId,
                    'resulting_draft_id' => $draftId,
                )
            );
            $targetFailures = array_values(array_filter(
                (array)($verification['targeted_check_results'] ?? array()),
                static fn(array $check): bool => (string)($check['status'] ?? '') !== 'PASS'
            ));
            $integrityFailures = array_values(array_filter(
                (array)($verification['review_checks'] ?? array()),
                static fn(array $check): bool =>
                    str_starts_with((string)($check['check_id'] ?? ''), 'integrity.targeted-patch.')
                    && (string)($check['status'] ?? '') !== 'PASS'
            ));
            $verified = $targetFailures === array()
                && $integrityFailures === array()
                && (int)$reconciliation['new_checks'] === 0
                && (int)$reconciliation['regressed_checks'] === 0;
            $verification['reconciliation'] = $reconciliation;
            $verification['patch_verification_status'] = $verified ? 'VERIFIED' : 'VERIFICATION_FAILED';
            $this->pdo->prepare(
                'UPDATE ipca_manual_ai_architect_review_patches
                 SET status=?,resulting_draft_id=?,verification_json=?,accepted_by=?,
                     verified_at=?
                 WHERE id=? AND plan_id=?'
            )->execute(array(
                $verified ? 'VERIFIED' : 'VERIFICATION_FAILED',
                $draftId,
                $this->json($verification),
                $actorUserId,
                $verified ? gmdate('Y-m-d H:i:s.v') : null,
                $patchId,
                $planId,
            ));
            $this->pdo->prepare(
                "UPDATE ipca_manual_ai_architect_review_patches
                 SET status='SUPERSEDED'
                 WHERE plan_id=? AND id<>? AND parent_draft_id=?
                   AND UPPER(status)='PROPOSED'"
            )->execute(array($planId, $patchId, (int)$patch['parent_draft_id']));
            $this->plans->appendEvent($planId, 'INDEPENDENT_REVIEW_PATCH_VERIFICATION_COMPLETED', 12, array(
                'patch_id' => $patchId,
                'parent_draft_id' => (int)$patch['parent_draft_id'],
                'resulting_draft_id' => $draftId,
                'verification_status' => $verified ? 'VERIFIED' : 'VERIFICATION_FAILED',
                'reconciliation' => $reconciliation,
                'unaffected_wording_unchanged' => $verification['unaffected_sections_byte_unchanged'],
                'frozen_nodes_unchanged' => $verification['frozen_nodes_byte_unchanged'],
                'accepted_structure_preserved' => $verification['accepted_structure_preserved'],
                'stage_preserved' => 'review',
            ), $actorUserId);
            $afterUnresolved = $this->unresolvedCheckIds($planId, $reviewId);
            $this->recordCycle($planId, $reviewId, (int)$newBaseline['id'], array(
                'checks_before' => count($beforeUnresolved),
                'checks_fixed' => count(array_diff($beforeUnresolved, $afterUnresolved)),
                'checks_remaining' => count(array_intersect($beforeUnresolved, $afterUnresolved)),
                'new_checks' => count(array_diff($afterUnresolved, $beforeUnresolved)),
                'checks_after' => count($afterUnresolved),
                'patch_id' => $patchId,
            ));
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        return $this->state($planId, $reviewId);
    }

    /**
     * Rebuild structured check state from already-persisted review history.
     * No draft, accepted baseline, operation package or publishing content is
     * created or modified.
     *
     * @return array<string,mixed>
     */
    public function recoverHistoricalPatch(
        int $planId,
        int $reviewId,
        int $patchId,
        int $actorUserId,
        BooksManualsChangeReviewerService $reviewer
    ): array {
        $plan = $this->plans->getPlan($planId);
        if ((int)($plan['owner_id'] ?? 0) !== $actorUserId
            || (string)($plan['stage'] ?? '') !== 'review') {
            throw new RuntimeException('Historical review recovery is limited to the plan owner in Step 5.');
        }
        $patch = $this->row(
            'SELECT * FROM ipca_manual_ai_architect_review_patches
             WHERE id=? AND plan_id=? LIMIT 1',
            array($patchId, $planId)
        );
        if ($patch === null || (int)($patch['resulting_draft_id'] ?? 0) <= 0) {
            throw new RuntimeException('The accepted historical patch and resulting draft are unavailable.');
        }
        $existingVerification = is_array($patch['verification_json'])
            ? $patch['verification_json']
            : $this->decode((string)$patch['verification_json']);
        if (!empty($existingVerification['recovery_completed'])) {
            return $this->state($planId, $reviewId);
        }
        $parent = $this->row(
            'SELECT * FROM ipca_manual_ai_architect_drafts WHERE id=? AND plan_id=?',
            array((int)$patch['parent_draft_id'], $planId)
        );
        $result = $this->row(
            'SELECT * FROM ipca_manual_ai_architect_drafts WHERE id=? AND plan_id=?',
            array((int)$patch['resulting_draft_id'], $planId)
        );
        if ($parent === null || $result === null) {
            throw new RuntimeException('Historical draft provenance is incomplete.');
        }
        $parentPayload = is_array($parent['draft_payload_json'])
            ? $parent['draft_payload_json']
            : $this->decode((string)$parent['draft_payload_json']);
        $resultPayload = is_array($result['draft_payload_json'])
            ? $result['draft_payload_json']
            : $this->decode((string)$result['draft_payload_json']);
        $parentVerification = $reviewer->verifyReadableAmendmentProposal($parentPayload);
        $trueRepairNodes = array();
        foreach ((array)($parentVerification['review_checks'] ?? array()) as $check) {
            if (!is_array($check) || (string)($check['status'] ?? '') !== 'FAIL') {
                continue;
            }
            $trueRepairNodes = array_merge(
                $trueRepairNodes,
                array_map('strval', (array)($check['allowed_repair_scope'] ?? array()))
            );
        }
        $trueRepairNodes = array_values(array_unique($trueRepairNodes));

        $this->pdo->beginTransaction();
        try {
            foreach ((array)($parentVerification['review_checks'] ?? array()) as $check) {
                if (is_array($check)) {
                    $this->persistReviewCheck(
                        $planId,
                        $reviewId,
                        (int)$patch['baseline_id'],
                        $check,
                        $parentVerification,
                        $actorUserId
                    );
                }
            }
            $before = $this->unresolvedCheckIds($planId, $reviewId);
            $findingIds = array_values(array_map('intval', (array)$patch['finding_ids_json']));
            $targetCheckIds = $findingIds === array()
                ? array()
                : array_values(array_filter(array_map(
                    static fn(array $row): string => (string)$row['check_id'],
                    $this->rows(
                        'SELECT check_id FROM ipca_manual_ai_architect_review_check_metadata
                         WHERE finding_id IN ('
                            . implode(',', array_fill(0, count($findingIds), '?')) . ')',
                        $findingIds
                    )
                )));
            $scopeSections = array_values((array)($patch['scope_json']['sections'] ?? array()));
            $scopeNodes = array();
            foreach ($scopeSections as $section) {
                $scopeNodes = array_merge(
                    $scopeNodes,
                    array_keys((array)($parentPayload['section_drafts'][(string)$section]['nodes'] ?? array()))
                );
            }
            $verification = $reviewer->verifyTargetedPatch(
                $parentPayload,
                $resultPayload,
                $scopeSections,
                array_values(array_unique(array_map('strval', $scopeNodes))),
                $targetCheckIds
            );
            $changedOutsideTrueScope = array();
            $trueScope = array_fill_keys($trueRepairNodes, true);
            foreach ((array)($parentPayload['section_drafts'] ?? array()) as $section => $draft) {
                foreach ((array)($draft['nodes'] ?? array()) as $number => $content) {
                    if (isset($trueScope[(string)$number])) {
                        continue;
                    }
                    $candidateContent = $resultPayload['section_drafts'][$section]['nodes'][$number] ?? null;
                    if ($this->json($content) !== $this->json($candidateContent)) {
                        $changedOutsideTrueScope[] = (string)$number;
                    }
                }
            }
            $changedOutsideTrueScope = array_values(array_unique($changedOutsideTrueScope));
            if ($changedOutsideTrueScope !== array()) {
                $verification['review_checks'][] = array(
                    'check_id' => 'integrity.historical-patch.true-repair-scope',
                    'check_version' => BooksManualsChangeReviewerService::CHECK_VERSION,
                    'category' => 'INTEGRITY',
                    'severity' => 'HARD',
                    'status' => 'FAIL',
                    'affected_sections' => $scopeSections,
                    'affected_nodes' => $changedOutsideTrueScope,
                    'required_invariant' => 'A correction preserves wording outside the nodes required by unresolved semantic checks.',
                    'observed_state' => 'Draft 14 changed unrelated nodes: ' . implode(', ', $changedOutsideTrueScope),
                    'evidence_references' => array(
                        'parent_draft:' . (int)$patch['parent_draft_id'],
                        'resulting_draft:' . (int)$patch['resulting_draft_id'],
                    ),
                    'human_explanation' => 'The accepted correction rewrote wording outside the true semantic repair scope.',
                    'allowed_repair_scope' => $trueRepairNodes,
                    'known_limitations' => array(),
                );
                $verification['review_divergence_detected'] = true;
                $verification['historical_unrelated_changed_nodes'] = $changedOutsideTrueScope;
            }
            $reconciliation = $this->reconcileVerificationChecks(
                $planId,
                $reviewId,
                (int)$patch['baseline_id'],
                (array)($verification['review_checks'] ?? array()),
                $actorUserId,
                array(
                    'recovery' => true,
                    'patch_id' => $patchId,
                    'resulting_draft_id' => (int)$patch['resulting_draft_id'],
                )
            );
            $after = $this->unresolvedCheckIds($planId, $reviewId);
            $targetFailures = array_values(array_filter(
                (array)($verification['targeted_check_results'] ?? array()),
                static fn(array $check): bool => (string)($check['status'] ?? '') !== 'PASS'
            ));
            $hardFailures = array_values(array_filter(
                (array)($verification['review_checks'] ?? array()),
                static fn(array $check): bool =>
                    strtoupper((string)($check['severity'] ?? '')) === 'HARD'
                    && strtoupper((string)($check['status'] ?? '')) === 'FAIL'
            ));
            $verified = $targetFailures === array()
                && $hardFailures === array()
                && (int)$reconciliation['new_checks'] === 0
                && (int)$reconciliation['regressed_checks'] === 0
                && !empty($verification['unaffected_sections_byte_unchanged'])
                && !empty($verification['accepted_structure_preserved']);
            $verification['reconciliation'] = array(
                'checks_before' => count($before),
                'checks_fixed' => count(array_diff($before, $after)),
                'checks_remaining' => count(array_intersect($before, $after)),
                'new_checks' => count(array_diff($after, $before)),
                'regressed_checks' => (int)$reconciliation['regressed_checks'],
                'checks_after' => count($after),
            );
            $verification['patch_verification_status'] = $verified
                ? 'VERIFIED'
                : 'VERIFICATION_FAILED';
            $verification['recovery_completed'] = true;
            $this->pdo->prepare(
                'UPDATE ipca_manual_ai_architect_review_patches
                 SET status=?,verification_json=?,verified_at=?
                 WHERE id=? AND plan_id=?'
            )->execute(array(
                $verified ? 'VERIFIED' : 'VERIFICATION_FAILED',
                $this->json($verification),
                $verified ? gmdate('Y-m-d H:i:s.v') : null,
                $patchId,
                $planId,
            ));
            $this->plans->appendEvent($planId, 'INDEPENDENT_REVIEW_HISTORY_RECONSTRUCTED', 12, array(
                'review_id' => $reviewId,
                'patch_id' => $patchId,
                'parent_draft_id' => (int)$patch['parent_draft_id'],
                'resulting_draft_id' => (int)$patch['resulting_draft_id'],
                'patch_status' => $verified ? 'VERIFIED' : 'VERIFICATION_FAILED',
                'convergence' => $verification['reconciliation'],
                'architect_rerun_performed' => false,
                'manual_content_mutated' => false,
                'stage_preserved' => 'review',
            ), $actorUserId);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        $this->recordCycle(
            $planId,
            $reviewId,
            (int)$patch['baseline_id'],
            $verification['reconciliation'] + array('patch_id' => $patchId)
        );
        return $this->state($planId, $reviewId);
    }

    /**
     * Restore only the nodes that a failed historical patch changed outside
     * its true semantic repair scope. This is deterministic baseline
     * restoration, not newly authored amendment content.
     *
     * @return array{patch:array<string,mixed>,state:array<string,mixed>}
     */
    public function repairHistoricalPatchScope(
        int $planId,
        int $reviewId,
        int $failedPatchId,
        int $actorUserId,
        BooksManualsChangeReviewerService $reviewer,
        array $expectedRepairNodes
    ): array {
        $plan = $this->plans->getPlan($planId);
        if ((int)($plan['owner_id'] ?? 0) !== $actorUserId
            || (string)($plan['stage'] ?? '') !== 'review') {
            throw new RuntimeException('Historical scope repair is limited to the plan owner in Step 5.');
        }
        $expectedRepairNodes = array_values(array_unique(array_map(
            'strval',
            $expectedRepairNodes
        )));
        sort($expectedRepairNodes, SORT_NATURAL);
        $failedPatch = $this->row(
            'SELECT * FROM ipca_manual_ai_architect_review_patches
             WHERE id=? AND plan_id=? LIMIT 1',
            array($failedPatchId, $planId)
        );
        if ($failedPatch === null
            || strtoupper((string)($failedPatch['status'] ?? '')) !== 'VERIFICATION_FAILED'
            || (int)($failedPatch['resulting_draft_id'] ?? 0) <= 0) {
            throw new RuntimeException('The failed historical patch is unavailable for deterministic repair.');
        }
        foreach ((array)$this->state($planId, $reviewId)['patches'] as $existingPatch) {
            $existingPayload = (array)($existingPatch['proposed_payload_json'] ?? array());
            if ((string)($existingPayload['mode'] ?? '') === 'HISTORICAL_SCOPE_REPAIR'
                && (int)($existingPayload['source_patch_id'] ?? 0) === $failedPatchId
                && strtoupper((string)($existingPatch['status'] ?? '')) === 'VERIFIED') {
                $storedNodes = array_values(array_unique(array_map(
                    'strval',
                    (array)($existingPayload['allowed_repair_nodes'] ?? array())
                )));
                sort($storedNodes, SORT_NATURAL);
                $storedFingerprints = (array)(
                    $existingPayload['restored_node_fingerprints'] ?? array()
                );
                $existingVerification = (array)($existingPatch['verification_json'] ?? array());
                $sourceDraft = $this->row(
                    'SELECT * FROM ipca_manual_ai_architect_drafts
                     WHERE id=? AND plan_id=? LIMIT 1',
                    array((int)$failedPatch['parent_draft_id'], $planId)
                );
                $resultDraft = $this->row(
                    'SELECT * FROM ipca_manual_ai_architect_drafts
                     WHERE id=? AND plan_id=? LIMIT 1',
                    array((int)$existingPatch['resulting_draft_id'], $planId)
                );
                $parentBaseline = $this->row(
                    'SELECT * FROM ipca_manual_ai_architect_review_baselines
                     WHERE id=? AND plan_id=? AND review_id=? LIMIT 1',
                    array((int)$existingPatch['baseline_id'], $planId, $reviewId)
                );
                $resultBaseline = $this->row(
                    'SELECT * FROM ipca_manual_ai_architect_review_baselines
                     WHERE id=? AND plan_id=? AND review_id=? LIMIT 1',
                    array(
                        (int)($existingVerification['result_baseline_id'] ?? 0),
                        $planId,
                        $reviewId,
                    )
                );
                $fingerprintsValid = $sourceDraft !== null && $resultDraft !== null;
                foreach ($expectedRepairNodes as $node) {
                    $sourceContent = $this->nodeContent(
                        (array)($sourceDraft['draft_payload_json'] ?? array()),
                        $node
                    );
                    $resultContent = $this->nodeContent(
                        (array)($resultDraft['draft_payload_json'] ?? array()),
                        $node
                    );
                    $expectedFingerprint = hash('sha256', $this->json($sourceContent));
                    $fingerprintsValid = $fingerprintsValid
                        && $sourceContent !== null
                        && $resultContent === $sourceContent
                        && hash_equals(
                            $expectedFingerprint,
                            (string)($storedFingerprints[$node] ?? '')
                        );
                }
                if ($storedNodes !== $expectedRepairNodes
                    || !$fingerprintsValid
                    || $parentBaseline === null
                    || $resultBaseline === null
                    || (int)($existingVerification['parent_baseline_id'] ?? 0)
                        !== (int)$existingPatch['baseline_id']
                    || (int)($existingVerification['result_baseline_id'] ?? 0) <= 0) {
                    throw new RuntimeException(
                        'Existing historical scope repair failed idempotent provenance validation.'
                    );
                }
                return array(
                    'patch' => $existingPatch,
                    'state' => $this->state($planId, $reviewId),
                );
            }
        }
        $scopeFinding = $this->row(
            'SELECT f.*,m.check_id,m.review_status,m.resolution_status,m.affected_nodes_json
             FROM ipca_manual_ai_architect_review_findings f
             JOIN ipca_manual_ai_architect_review_check_metadata m ON m.finding_id=f.id
             WHERE f.plan_id=? AND f.review_id=?
               AND m.check_id=? AND m.resolution_status=? LIMIT 1',
            array(
                $planId,
                $reviewId,
                'integrity.historical-patch.true-repair-scope',
                'BLOCKED',
            )
        );
        if ($scopeFinding === null) {
            throw new RuntimeException('No unresolved historical patch scope blocker requires restoration.');
        }
        $acceptedStep4 = $this->row(
            'SELECT * FROM ipca_manual_ai_architect_drafts
             WHERE id=? AND plan_id=? LIMIT 1',
            array((int)$failedPatch['parent_draft_id'], $planId)
        );
        $currentDraft = $this->latestAcceptedDraft($planId);
        if ($acceptedStep4 === null || $currentDraft === null
            || (int)$currentDraft['id'] !== (int)$failedPatch['resulting_draft_id']) {
            throw new RuntimeException('Historical scope repair requires the untouched failed-patch candidate.');
        }
        $failedVerification = (array)($failedPatch['verification_json'] ?? array());
        $provenanceNodes = array_values(array_unique(array_map(
            'strval',
            (array)($failedVerification['historical_unrelated_changed_nodes'] ?? array())
        )));
        $blockedNodes = array_values(array_unique(array_map(
            'strval',
            (array)$scopeFinding['affected_nodes_json']
        )));
        sort($provenanceNodes, SORT_NATURAL);
        sort($blockedNodes, SORT_NATURAL);
        if ($provenanceNodes === array()
            || $blockedNodes !== $provenanceNodes
            || $expectedRepairNodes !== $provenanceNodes) {
            throw new RuntimeException(
                'Historical scope repair nodes do not match the explicitly governed repair set and failed-patch provenance.'
            );
        }
        $acceptedPayload = (array)$acceptedStep4['draft_payload_json'];
        $currentPayload = (array)$currentDraft['draft_payload_json'];
        if ((string)($acceptedPayload['wizard_status'] ?? '') !== 'accepted'
            || (string)($currentPayload['wizard_status'] ?? '') !== 'accepted') {
            throw new RuntimeException('Historical scope repair requires accepted review-candidate provenance.');
        }
        $repair = $this->buildHistoricalScopeRepairCandidate(
            $acceptedPayload,
            $currentPayload,
            $provenanceNodes
        );
        $candidate = $repair['candidate_proposal'];
        $candidate['wizard_status'] = 'accepted';
        $candidate['historical_scope_repair'] = array(
            'mode' => 'HISTORICAL_SCOPE_REPAIR',
            'source_patch_id' => $failedPatchId,
            'accepted_step4_draft_id' => (int)$acceptedStep4['id'],
            'parent_review_candidate_id' => (int)$currentDraft['id'],
            'restored_nodes' => $repair['restored_nodes'],
            'deterministic' => true,
            'architect_rerun_performed' => false,
            'production_applied' => false,
        );
        $verification = $reviewer->verifyTargetedPatch(
            $currentPayload,
            $candidate,
            $repair['scope_sections'],
            $repair['restored_nodes'],
            array()
        );
        $historicalCheck = array(
            'check_id' => 'integrity.historical-patch.true-repair-scope',
            'check_version' => BooksManualsChangeReviewerService::CHECK_VERSION,
            'category' => 'INTEGRITY',
            'severity' => 'HARD',
            'status' => 'PASS',
            'affected_sections' => $repair['scope_sections'],
            'affected_nodes' => $repair['restored_nodes'],
            'required_invariant' => 'A correction preserves wording outside the nodes required by unresolved semantic checks.',
            'observed_state' => 'Every unnecessary Patch 1 node was restored byte-for-byte from accepted draft 13.',
            'evidence_references' => array(
                'accepted_step4_draft:' . (int)$acceptedStep4['id'],
                'failed_patch:' . $failedPatchId,
                'failed_patch_draft:' . (int)$currentDraft['id'],
            ),
            'human_explanation' => 'Historical Patch 1 scope expansion was deterministically restored.',
            'allowed_repair_scope' => array(),
            'known_limitations' => array(),
        );
        $verification['review_checks'][] = $historicalCheck;
        $versionStmt = $this->pdo->prepare(
            'SELECT COALESCE(MAX(draft_version),0)+1
             FROM ipca_manual_ai_architect_drafts WHERE plan_id=?'
        );
        $versionStmt->execute(array($planId));
        $draftVersion = max(1, (int)$versionStmt->fetchColumn());
        $parentBaselineId = $this->baselineIdForReview($reviewId);
        $beforeUnresolved = $this->unresolvedCheckIds($planId, $reviewId);
        $patchPayload = array(
            'schema' => 'ipca.manual-change-historical-scope-repair.v1',
            'mode' => 'HISTORICAL_SCOPE_REPAIR',
            'source_patch_id' => $failedPatchId,
            'parent_baseline_id' => $parentBaselineId,
            'scope_sections' => $repair['scope_sections'],
            'allowed_repair_nodes' => $repair['restored_nodes'],
            'changed_sections' => $repair['changed_sections'],
            'candidate_proposal' => $candidate,
            'failed_check_ids' => array('integrity.historical-patch.true-repair-scope'),
            'repair_checks' => array($historicalCheck),
            'restored_node_fingerprints' => $repair['restored_node_fingerprints'],
            'frozen_node_fingerprints' => $repair['frozen_node_fingerprints'],
            'unchanged_section_fingerprints' => $repair['unchanged_section_fingerprints'],
            'accepted_structure_nodes_unchanged' => true,
            'lifecycle_unchanged' => true,
            'architect_rerun_performed' => false,
            'production_applied' => false,
        );
        $patchFingerprint = hash('sha256', $this->json(array(
            'mode' => 'HISTORICAL_SCOPE_REPAIR',
            'source_patch_id' => $failedPatchId,
            'accepted_step4_draft_id' => (int)$acceptedStep4['id'],
            'parent_draft_id' => (int)$currentDraft['id'],
            'restored_node_fingerprints' => $repair['restored_node_fingerprints'],
        )));
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'UPDATE ipca_manual_ai_architect_plans SET id=id WHERE id=?'
            )->execute(array($planId));
            $lockedCurrentDraft = $this->latestAcceptedDraft($planId);
            $lockedScopeFinding = $this->row(
                'SELECT m.resolution_status
                 FROM ipca_manual_ai_architect_review_check_metadata m
                 WHERE m.finding_id=? AND m.plan_id=? LIMIT 1',
                array((int)$scopeFinding['id'], $planId)
            );
            if ($lockedCurrentDraft === null
                || (int)$lockedCurrentDraft['id'] !== (int)$currentDraft['id']
                || strtoupper((string)($lockedScopeFinding['resolution_status'] ?? ''))
                    !== 'BLOCKED'
                || $this->baselineIdForReview($reviewId) !== $parentBaselineId) {
                throw new RuntimeException(
                    'Historical scope repair became stale before deterministic restoration.'
                );
            }
            $versionStmt->execute(array($planId));
            $draftVersion = max(1, (int)$versionStmt->fetchColumn());
            $draftId = $this->plans->save('drafts', $planId, array(
                'draft_uuid' => $this->uuid(),
                'structure_proposal_id' => $currentDraft['structure_proposal_id'],
                'target_book_version_id' => $currentDraft['target_book_version_id'],
                'draft_version' => $draftVersion,
                'status' => 'generated',
                'source_fingerprint' => $currentDraft['source_fingerprint'],
                'content_fingerprint' => hash('sha256', $this->json($candidate)),
                'draft_payload_json' => $candidate,
                'created_by' => $actorUserId,
            ));
            $newBaseline = $this->freezeBaseline($planId, $reviewId, $actorUserId);
            $baselineId = (int)$newBaseline['id'];
            $patchId = $this->plans->save('review_patches', $planId, array(
                'patch_uuid' => $this->uuid(),
                'baseline_id' => $parentBaselineId,
                'parent_draft_id' => (int)$currentDraft['id'],
                'resulting_draft_id' => $draftId,
                'finding_ids_json' => array((int)$scopeFinding['id']),
                'scope_json' => array(
                    'sections' => $repair['scope_sections'],
                    'nodes' => $repair['restored_nodes'],
                    'failed_check_ids' => array('integrity.historical-patch.true-repair-scope'),
                    'repair_type' => 'HISTORICAL_SCOPE_REPAIR',
                ),
                'before_payload_json' => array_map(
                    static fn(array $change): array => (array)$change['before'],
                    $repair['changed_sections']
                ),
                'proposed_payload_json' => $patchPayload,
                'unchanged_fingerprints_json' => $repair['unchanged_section_fingerprints'],
                'patch_fingerprint' => $patchFingerprint,
                'status' => 'VERIFIED',
                'verification_json' => array(),
                'proposed_by' => $actorUserId,
                'verified_at' => gmdate('Y-m-d H:i:s.v'),
            ));
            $reconciliation = $this->reconcileVerificationChecks(
                $planId,
                $reviewId,
                $baselineId,
                (array)$verification['review_checks'],
                $actorUserId,
                array(
                    'patch_id' => $patchId,
                    'repair_type' => 'HISTORICAL_SCOPE_REPAIR',
                    'resulting_draft_id' => $draftId,
                )
            );
            $historicalResult = array_values(array_filter(
                (array)$verification['review_checks'],
                static fn(array $check): bool =>
                    (string)($check['check_id'] ?? '')
                        === 'integrity.historical-patch.true-repair-scope'
            ));
            $verified = count($historicalResult) === 1
                && (string)($historicalResult[0]['status'] ?? '') === 'PASS'
                && (int)$reconciliation['checks_fixed'] === 1
                && (int)$reconciliation['new_checks'] === 0
                && (int)$reconciliation['regressed_checks'] === 0
                && !empty($verification['frozen_nodes_byte_unchanged'])
                && !empty($verification['accepted_structure_preserved']);
            if (!$verified) {
                throw new RuntimeException('Deterministic historical scope restoration did not pass scoped verification.');
            }
            $verification['reconciliation'] = $reconciliation;
            $verification['patch_verification_status'] = 'VERIFIED';
            $verification['repair_type'] = 'HISTORICAL_SCOPE_REPAIR';
            $verification['parent_baseline_id'] = $parentBaselineId;
            $verification['result_baseline_id'] = $baselineId;
            $verification['restored_node_fingerprints'] = $repair['restored_node_fingerprints'];
            $this->pdo->prepare(
                'UPDATE ipca_manual_ai_architect_review_patches
                 SET resulting_draft_id=?,verification_json=?,verified_at=?
                 WHERE id=? AND plan_id=?'
            )->execute(array(
                $draftId,
                $this->json($verification),
                gmdate('Y-m-d H:i:s.v'),
                $patchId,
                $planId,
            ));
            $this->plans->appendEvent(
                $planId,
                'INDEPENDENT_REVIEW_HISTORICAL_SCOPE_REPAIR_VERIFIED',
                12,
                array(
                    'patch_id' => $patchId,
                    'source_patch_id' => $failedPatchId,
                    'accepted_step4_draft_id' => (int)$acceptedStep4['id'],
                    'parent_review_candidate_id' => (int)$currentDraft['id'],
                    'resulting_draft_id' => $draftId,
                    'restored_nodes' => $repair['restored_nodes'],
                    'reconciliation' => $reconciliation,
                    'architect_rerun_performed' => false,
                    'manual_content_mutated' => false,
                    'stage_preserved' => 'review',
                ),
                $actorUserId
            );
            $afterUnresolved = $this->unresolvedCheckIds($planId, $reviewId);
            $this->recordCycle($planId, $reviewId, $baselineId, array(
                'checks_before' => count($beforeUnresolved),
                'checks_fixed' => count(array_diff($beforeUnresolved, $afterUnresolved)),
                'checks_remaining' => count(array_intersect($beforeUnresolved, $afterUnresolved)),
                'new_checks' => count(array_diff($afterUnresolved, $beforeUnresolved)),
                'checks_after' => count($afterUnresolved),
                'patch_id' => $patchId,
                'divergence_resolved' => true,
            ));
            $this->plans->updatePlan($planId, array(
                'stage' => 'review',
                'status' => $afterUnresolved === array() ? 'ready_for_review' : 'blocked',
                'updated_by' => $actorUserId,
            ));
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        return array(
            'patch' => (array)$this->row(
                'SELECT * FROM ipca_manual_ai_architect_review_patches WHERE id=?',
                array($patchId)
            ),
            'state' => $this->state($planId, $reviewId),
        );
    }

    public function requestPatchAdjustment(
        int $planId,
        int $patchId,
        string $reason,
        int $actorUserId
    ): void {
        if (mb_strlen(trim($reason)) < 10) {
            throw new InvalidArgumentException('Explain the requested targeted adjustment.');
        }
        $plan = $this->plans->getPlan($planId);
        if ((int)($plan['owner_id'] ?? 0) !== $actorUserId
            || (string)($plan['stage'] ?? '') !== 'review') {
            throw new RuntimeException('Targeted correction adjustments belong to Independent Review.');
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'UPDATE ipca_manual_ai_architect_plans SET id=id WHERE id=?'
            )->execute(array($planId));
            $stmt = $this->pdo->prepare(
                "UPDATE ipca_manual_ai_architect_review_patches
                 SET status='ADJUSTMENT_REQUESTED',verification_json=?
                 WHERE id=? AND plan_id=? AND UPPER(status)='PROPOSED'"
            );
            $stmt->execute(array(
                $this->json(array('adjustment_reason' => trim($reason))),
                $patchId,
                $planId,
            ));
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException(
                    'Only a proposed, unaccepted correction can be adjusted.'
                );
            }
            $this->plans->appendEvent(
                $planId,
                'INDEPENDENT_REVIEW_PATCH_ADJUSTMENT_REQUESTED',
                12,
                array(
                    'patch_id' => $patchId,
                    'reason' => trim($reason),
                    'stage_preserved' => 'review',
                ),
                $actorUserId
            );
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function explicitlyReopenBaseline(
        int $planId,
        string $target,
        string $rationale,
        int $actorUserId
    ): void {
        $target = strtoupper(trim($target));
        if (!in_array($target, array('IMPACT_ANALYSIS', 'PROPOSED_STRUCTURE'), true)) {
            throw new InvalidArgumentException('Only Impact Analysis or Proposed Structure can be explicitly reopened.');
        }
        if (mb_strlen(trim($rationale)) < 20) {
            throw new InvalidArgumentException('Explain why the accepted baseline must be reopened.');
        }
        $plan = $this->plans->getPlan($planId);
        if ((int)($plan['owner_id'] ?? 0) !== $actorUserId
            || (string)($plan['stage'] ?? '') !== 'review') {
            throw new RuntimeException('Only the Change Plan owner may explicitly reopen an accepted baseline from review.');
        }
        $state = $this->state($planId);
        $eligible = array_values(array_filter(
            (array)$state['findings'],
            static function (array $finding) use ($target): bool {
                if (in_array((string)$finding['status'], array('closed', 'verified'), true)) {
                    return false;
                }
                if ($target === 'IMPACT_ANALYSIS') {
                    return (string)$finding['finding_class'] === self::POTENTIAL_SCOPE_DEFECT
                        || (
                            (string)($finding['category'] ?? '') === 'SCOPE'
                            && (string)($finding['resolution_status'] ?? '') === 'BLOCKED'
                        );
                }
                if ((string)($finding['category'] ?? '') === 'STRUCTURE'
                    && (string)($finding['resolution_status'] ?? '') === 'BLOCKED') {
                    return true;
                }
                $resolution = is_array($finding['resolution_json'] ?? null)
                    ? $finding['resolution_json']
                    : array();
                return (string)($resolution['consequence'] ?? '') === 'STRUCTURAL_CONSEQUENCE';
            }
        ));
        if ($eligible === array()) {
            throw new RuntimeException('No demonstrated review finding authorizes reopening this accepted baseline.');
        }
        $baselineIds = array_values(array_unique(array_map(
            static fn(array $finding): int => (int)$finding['baseline_id'],
            $eligible
        )));
        $this->plans->appendEvent($planId, 'INDEPENDENT_REVIEW_BASELINE_VOIDED_BY_HUMAN', 12, array(
            'target' => $target,
            'rationale' => trim($rationale),
            'finding_ids' => array_map(static fn(array $finding): int => (int)$finding['id'], $eligible),
            'baseline_ids' => $baselineIds,
            'automatic' => false,
        ), $actorUserId);
        $this->plans->updatePlan($planId, array(
            'stage' => $target === 'IMPACT_ANALYSIS' ? 'scope' : 'structure',
            'status' => 'ready_for_review',
            'updated_by' => $actorUserId,
        ));
    }

    public function recordScopeDefectAsFollowUp(
        int $planId,
        int $findingId,
        string $rationale,
        int $actorUserId
    ): array {
        if (mb_strlen(trim($rationale)) < 20) {
            throw new InvalidArgumentException('Explain why this possible scope issue belongs in a separate follow-up.');
        }
        $plan = $this->plans->getPlan($planId);
        if ((int)($plan['owner_id'] ?? 0) !== $actorUserId
            || (string)($plan['stage'] ?? '') !== 'review') {
            throw new RuntimeException('Scope follow-up decisions belong to Independent Review.');
        }
        $finding = $this->row(
            'SELECT * FROM ipca_manual_ai_architect_review_findings
             WHERE id=? AND plan_id=? LIMIT 1',
            array($findingId, $planId)
        );
        if ($finding === null
            || (string)$finding['finding_class'] !== self::POTENTIAL_SCOPE_DEFECT
            || in_array((string)$finding['status'], array('closed', 'verified'), true)) {
            throw new RuntimeException('This finding is not an unresolved potential scope defect.');
        }
        $resolution = array(
            'disposition' => 'SEPARATE_FOLLOW_UP',
            'rationale' => trim($rationale),
            'actor_id' => $actorUserId,
            'recorded_at' => gmdate(DATE_ATOM),
            'accepted_baseline_changed' => false,
        );
        $this->pdo->prepare(
            "UPDATE ipca_manual_ai_architect_review_findings
             SET status='closed',blocking=0,resolution_json=?,resolved_at=CURRENT_TIMESTAMP(3)
             WHERE id=? AND plan_id=?"
        )->execute(array($this->json($resolution), $findingId, $planId));
        $this->plans->appendEvent($planId, 'INDEPENDENT_REVIEW_SCOPE_DEFECT_DEFERRED', 12, array(
            'finding_id' => $findingId,
            'finding_fingerprint' => $finding['finding_fingerprint'],
            'rationale' => trim($rationale),
            'accepted_baseline_changed' => false,
            'stage_preserved' => 'review',
        ), $actorUserId);
        return $this->state($planId, (int)$finding['review_id']);
    }

    /** @return array<string,mixed> */
    private function freezeBaseline(int $planId, int $reviewId, int $actorUserId): array
    {
        $plan = $this->plans->loadPlan($planId);
        $answers = array_values(array_filter((array)($plan['review_answers'] ?? array()), 'is_array'));
        $impactBaseline = array_values(array_filter(
            (array)$plan['impacts'],
            static fn(array $impact): bool => (string)($impact['status'] ?? '') === 'approved'
        ));
        $boundaryBaseline = array_values(array_filter(
            (array)$plan['boundaries'],
            static fn(array $boundary): bool => in_array(
                (string)($boundary['classification'] ?? ''),
                array('MUST_PRESERVE', 'OUT_OF_SCOPE', 'REVIEW_SEPARATELY'),
                true
            )
        ));
        $approvedStructures = array_values(array_filter(
            (array)$plan['structure_proposals'],
            static fn(array $proposal): bool => (string)($proposal['status'] ?? '') === 'approved'
        ));
        $approvedStructure = $approvedStructures === array()
            ? array()
            : $approvedStructures[array_key_last($approvedStructures)];
        $acceptedNodes = array_values(array_filter(
            (array)$plan['structure_nodes'],
            static fn(array $node): bool =>
                (int)($node['structure_proposal_id'] ?? 0) === (int)($approvedStructure['id'] ?? 0)
                && (string)($node['decision_status'] ?? '') === 'accepted'
        ));
        $acceptedDrafts = array_values(array_filter(
            (array)$plan['drafts'],
            static function (array $draft): bool {
                $payload = is_array($draft['draft_payload_json'] ?? null)
                    ? $draft['draft_payload_json']
                    : array();
                return (string)($draft['status'] ?? '') === 'generated'
                    && (string)($payload['wizard_status'] ?? '') === 'accepted';
            }
        ));
        $acceptedDraft = $acceptedDrafts === array()
            ? array()
            : $acceptedDrafts[array_key_last($acceptedDrafts)];
        if ($impactBaseline === array() || $approvedStructure === array() || $acceptedDraft === array()) {
            throw new RuntimeException('Accepted Steps 2, 3 and 4 must exist before Independent Review.');
        }
        $limitations = array();
        foreach ((array)$plan['change_intents'] as $intent) {
            $limitations = array_merge(
                $limitations,
                array_values((array)($intent['known_limitations_json'] ?? array()))
            );
        }
        $snapshot = array(
            'impacts' => $impactBaseline,
            'boundaries' => $boundaryBaseline,
            'structure' => array('proposal' => $approvedStructure, 'nodes' => $acceptedNodes),
            'draft' => $acceptedDraft,
            'target_state' => array_values((array)$plan['target_components']),
            'limitations' => array_values(array_unique(array_map('strval', $limitations))),
            'governed_facts' => array_values(array_map(
                static fn(array $answer): mixed => $answer['governed_fact_json'] ?? array(),
                $answers
            )),
        );
        $fingerprint = hash('sha256', $this->json($snapshot));
        $existing = $this->row(
            'SELECT * FROM ipca_manual_ai_architect_review_baselines
             WHERE plan_id=? AND baseline_fingerprint=? LIMIT 1',
            array($planId, $fingerprint)
        );
        if ($existing !== null) {
            return $existing;
        }
        $id = $this->plans->save('review_baselines', $planId, array(
            'baseline_uuid' => $this->uuid(),
            'review_id' => $reviewId,
            'impact_baseline_json' => $snapshot['impacts'],
            'boundary_baseline_json' => $snapshot['boundaries'],
            'structure_baseline_json' => $snapshot['structure'],
            'draft_baseline_json' => $snapshot['draft'],
            'target_state_baseline_json' => $snapshot['target_state'],
            'limitation_baseline_json' => $snapshot['limitations'],
            'governed_facts_baseline_json' => $snapshot['governed_facts'],
            'baseline_fingerprint' => $fingerprint,
            'created_by' => $actorUserId,
        ));
        return (array)$this->row(
            'SELECT * FROM ipca_manual_ai_architect_review_baselines WHERE id=?',
            array($id)
        );
    }

    /** @param array<string,mixed> $prepared */
    private function persistReviewCheck(
        int $planId,
        int $reviewId,
        int $baselineId,
        array $check,
        array $prepared,
        int $actorUserId
    ): int {
        $checkId = trim((string)($check['check_id'] ?? ''));
        if ($checkId === '') {
            throw new InvalidArgumentException('A structured review check requires a stable check_id.');
        }
        $reviewStatus = strtoupper((string)($check['status'] ?? 'FAIL'));
        if (!in_array($reviewStatus, array('PASS', 'FAIL', 'INFORMATIONAL'), true)) {
            throw new InvalidArgumentException("Review check {$checkId} has an invalid status.");
        }
        $severity = strtoupper((string)($check['severity'] ?? 'MATERIAL'));
        $category = strtoupper((string)($check['category'] ?? 'CONTENT'));
        $hard = $reviewStatus === 'FAIL'
            && $severity === 'HARD'
            && in_array($category, array('INTEGRITY', 'STRUCTURE', 'SCOPE'), true);
        $resolutionStatus = $reviewStatus === 'FAIL'
            ? ($hard ? 'BLOCKED' : 'UNRESOLVED')
            : 'VERIFIED';
        $findingStatus = $resolutionStatus === 'VERIFIED'
            ? 'verified'
            : ($resolutionStatus === 'BLOCKED' ? 'blocked' : 'patch_pending');
        $findingClass = $reviewStatus !== 'FAIL'
            ? self::INFORMATIONAL
            : ($hard ? self::HARD_INTEGRITY_BLOCKER : self::TARGETED_AUTHOR_CORRECTION);
        $sections = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => trim((string)$value),
            (array)($check['affected_sections'] ?? array())
        ))));
        $nodes = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => trim((string)$value),
            (array)($check['affected_nodes'] ?? array())
        ))));
        $explanation = trim((string)($check['human_explanation'] ?? ''));
        $invariant = trim((string)($check['required_invariant'] ?? ''));
        if ($explanation === '') {
            $explanation = $reviewStatus === 'FAIL' ? $invariant : 'Review check passed.';
        }

        $metadata = $this->row(
            'SELECT m.*,f.id finding_id
             FROM ipca_manual_ai_architect_review_check_metadata m
             JOIN ipca_manual_ai_architect_review_findings f ON f.id=m.finding_id
             WHERE m.plan_id=? AND m.review_id=? AND m.check_id=? LIMIT 1',
            array($planId, $reviewId, $checkId)
        );
        $findingId = (int)($metadata['finding_id'] ?? 0);
        if ($findingId <= 0) {
            $legacyExplanations = array_values(array_unique(array_merge(
                array($explanation),
                $this->legacyIssueAliases($checkId)
            )));
            $legacy = $this->row(
                'SELECT f.id FROM ipca_manual_ai_architect_review_findings f
                 LEFT JOIN ipca_manual_ai_architect_review_check_metadata m ON m.finding_id=f.id
                 WHERE f.plan_id=? AND f.review_id=? AND m.id IS NULL
                   AND f.human_explanation IN ('
                    . implode(',', array_fill(0, count($legacyExplanations), '?')) . ')
                 ORDER BY f.id LIMIT 1',
                array_merge(array($planId, $reviewId), $legacyExplanations)
            );
            $findingId = (int)($legacy['id'] ?? 0);
        }
        $fingerprint = hash('sha256', $this->json(array(
            'review_id' => $reviewId,
            'check_id' => $checkId,
            'check_version' => (string)($check['check_version'] ?? '1'),
        )));
        if ($findingId <= 0) {
            $findingId = $this->plans->save('review_findings', $planId, array(
                'finding_uuid' => $this->uuid(),
                'review_id' => $reviewId,
                'baseline_id' => $baselineId,
                'finding_fingerprint' => $fingerprint,
                'finding_class' => $findingClass,
                'outcome' => $reviewStatus === 'FAIL' ? 'CONFIRMED_DEFECT' : 'PASS',
                'status' => $findingStatus,
                'material' => $reviewStatus === 'FAIL',
                'blocking' => $reviewStatus === 'FAIL',
                'title' => $reviewStatus === 'FAIL'
                    ? ($hard ? 'Integrity defect must be corrected' : 'Targeted wording correction required')
                    : 'Independent review check passed',
                'human_explanation' => $explanation,
                'unresolved_fact' => '',
                'why_matters' => $invariant,
                'affected_sections_json' => $sections,
                'accepted_wording_json' => $this->acceptedWording($baselineId, $sections),
                'evidence_json' => array(
                    'check_id' => $checkId,
                    'evidence_references' => (array)($check['evidence_references'] ?? array()),
                    'source_review_status' => (string)($prepared['status'] ?? ''),
                ),
                'resolution_json' => array(
                    'check_id' => $checkId,
                    'review_status' => $reviewStatus,
                    'resolution_status' => $resolutionStatus,
                ),
                'resolved_at' => $resolutionStatus === 'VERIFIED' ? gmdate('Y-m-d H:i:s.v') : null,
            ));
        } else {
            $this->pdo->prepare(
                'UPDATE ipca_manual_ai_architect_review_findings
                 SET baseline_id=?,finding_fingerprint=?,finding_class=?,outcome=?,status=?,
                     material=?,blocking=?,title=?,human_explanation=?,why_matters=?,
                     affected_sections_json=?,evidence_json=?,resolution_json=?,resolved_at=?
                 WHERE id=? AND plan_id=?'
            )->execute(array(
                $baselineId,
                $fingerprint,
                $findingClass,
                $reviewStatus === 'FAIL' ? 'CONFIRMED_DEFECT' : 'PASS',
                $findingStatus,
                $reviewStatus === 'FAIL' ? 1 : 0,
                $reviewStatus === 'FAIL' ? 1 : 0,
                $reviewStatus === 'FAIL'
                    ? ($hard ? 'Integrity defect must be corrected' : 'Targeted wording correction required')
                    : 'Independent review check passed',
                $explanation,
                $invariant,
                $this->json($sections),
                $this->json(array(
                    'check_id' => $checkId,
                    'evidence_references' => (array)($check['evidence_references'] ?? array()),
                    'source_review_status' => (string)($prepared['status'] ?? ''),
                )),
                $this->json(array(
                    'check_id' => $checkId,
                    'review_status' => $reviewStatus,
                    'resolution_status' => $resolutionStatus,
                )),
                $resolutionStatus === 'VERIFIED' ? gmdate('Y-m-d H:i:s.v') : null,
                $findingId,
                $planId,
            ));
        }
        if ($metadata === null) {
            $this->plans->save('review_check_metadata', $planId, array(
                'check_uuid' => $this->uuid(),
                'review_id' => $reviewId,
                'finding_id' => $findingId,
                'check_id' => $checkId,
                'check_version' => (string)($check['check_version'] ?? '1'),
                'category' => $category,
                'severity' => $severity,
                'review_status' => $reviewStatus,
                'resolution_status' => $resolutionStatus,
                'affected_nodes_json' => $nodes,
                'required_invariant' => $invariant,
                'observed_state' => (string)($check['observed_state'] ?? ''),
                'evidence_references_json' => (array)($check['evidence_references'] ?? array()),
                'allowed_repair_scope_json' => (array)($check['allowed_repair_scope'] ?? array()),
                'known_limitations_json' => (array)($check['known_limitations'] ?? array()),
                'last_verified_at' => gmdate('Y-m-d H:i:s.v'),
                'verified_at' => $resolutionStatus === 'VERIFIED' ? gmdate('Y-m-d H:i:s.v') : null,
            ));
        } else {
            $this->pdo->prepare(
                'UPDATE ipca_manual_ai_architect_review_check_metadata
                 SET check_version=?,category=?,severity=?,review_status=?,resolution_status=?,
                     affected_nodes_json=?,required_invariant=?,observed_state=?,
                     evidence_references_json=?,allowed_repair_scope_json=?,
                     known_limitations_json=?,last_verified_at=?,verified_at=?
                 WHERE id=?'
            )->execute(array(
                (string)($check['check_version'] ?? '1'),
                $category,
                $severity,
                $reviewStatus,
                $resolutionStatus,
                $this->json($nodes),
                $invariant,
                (string)($check['observed_state'] ?? ''),
                $this->json((array)($check['evidence_references'] ?? array())),
                $this->json((array)($check['allowed_repair_scope'] ?? array())),
                $this->json((array)($check['known_limitations'] ?? array())),
                gmdate('Y-m-d H:i:s.v'),
                $resolutionStatus === 'VERIFIED' ? gmdate('Y-m-d H:i:s.v') : null,
                (int)$metadata['id'],
            ));
        }
        return $findingId;
    }

    /** @return list<string> */
    private function legacyIssueAliases(string $checkId): array
    {
        return match ($checkId) {
            'eccairs.limitation.manual-control' => array(
                'Unsupported capability or implementation claims were detected.',
            ),
            'eccairs.initial.governance-complete' => array(
                'Section 5.6.4.1 does not satisfy all accepted ECCAIRS governance requirements.',
            ),
            'preservation.mandatory-voluntary-distinction' => array(
                'Existing valid requirement was not preserved: mandatory and voluntary distinction.',
            ),
            'eccairs.initial.stage-information' => array(
                'Initial ECCAIRS information wording is not appropriately stage-based.',
            ),
            'eccairs.follow-up.initial-not-completion' => array(
                'Intermediate/final ECCAIRS control is incomplete: initial submission is not process completion.',
            ),
            'eccairs.follow-up.findings-conclusions' => array(
                'Intermediate/final ECCAIRS control is incomplete: findings and conclusions.',
            ),
            'eccairs.follow-up.causal-contributing' => array(
                'Intermediate/final ECCAIRS control is incomplete: causal and contributing factors.',
            ),
            'eccairs.follow-up.actions-evidence-effectiveness' => array(
                'Intermediate/final ECCAIRS control is incomplete: actions and implementation/effectiveness.',
            ),
            'eccairs.follow-up.approved-authority-process' => array(
                'Intermediate/final ECCAIRS control is incomplete: direct ECCAIRS follow-up.',
            ),
            'eccairs.follow-up.preliminary-30-day-risk-trigger' => array(
                'Final human-review quality gate failed: preliminary 30-day rule preserved.',
            ),
            'eccairs.follow-up.intermediate-separated' => array(
                'Final human-review quality gate failed: intermediate updates separated from 30-day rule.',
            ),
            'eccairs.follow-up.final-three-month' => array(
                'Final human-review quality gate failed: final three-month rule preserved exactly.',
            ),
            'closure.authority-follow-up-gate' => array(
                'Final human-review quality gate failed: investigation completion cannot bypass ECCAIRS follow-up.',
            ),
            'closure.no-further-update-determination' => array(
                'Final human-review quality gate failed: no-further-update determination is documented.',
            ),
            'monitoring.occurrence-level' => array(
                'Final human-review quality gate failed: occurrence-level monitoring remains in 5.6.8.',
            ),
            'monitoring.aggregate-assurance' => array(
                'Final human-review quality gate failed: aggregate assurance remains in 5.7.',
            ),
            'roles.safety-manager.residual-risk-authority' => array(
                'Final human-review quality gate failed: Safety Manager authority agrees with residual-risk gate.',
            ),
            'records.occurrence-evidence-complete' => array(
                'Final human-review quality gate failed: 4.2 controls Section 5.6 evidence.',
            ),
            'training.corrective-action-competence' => array(
                'Final human-review quality gate failed: 8.1 includes corrective-action competence.',
            ),
            'reporting.missing-information-deadline' => array(
                'Final human-review quality gate failed: missing-information deadline wording is qualified.',
            ),
            'eccairs.follow-up.article-13-conditioned' => array(
                'Final human-review quality gate failed: other Article 13 follow-up remains authority-conditioned.',
            ),
            default => array(),
        };
    }

    /**
     * @param list<array<string,mixed>> $findings
     * @return list<array<string,mixed>>
     */
    private function repairGroups(array $findings): array
    {
        $groups = array();
        foreach ($findings as $finding) {
            if (strtoupper((string)($finding['resolution_status'] ?? '')) !== 'UNRESOLVED') {
                continue;
            }
            $scope = array_values(array_unique(array_filter(array_map(
                'strval',
                (array)($finding['allowed_repair_scope_json'] ?? array())
            ))));
            if ($scope === array()) {
                $scope = array_values(array_unique(array_filter(array_map(
                    'strval',
                    (array)($finding['affected_nodes_json'] ?? array())
                ))));
            }
            if ($scope === array()) {
                $scope = array_values(array_unique(array_filter(array_map(
                    'strval',
                    (array)($finding['affected_sections_json'] ?? array())
                ))));
            }
            sort($scope, SORT_NATURAL);
            $key = implode('|', $scope);
            if ($key === '') {
                continue;
            }
            $groups[$key] ??= array(
                'group_id' => hash('sha256', $key),
                'label' => $this->repairGroupLabel(
                    (string)($finding['category'] ?? ''),
                    $scope
                ),
                'affected_nodes' => $scope,
                'finding_ids' => array(),
                'check_ids' => array(),
                'issues' => array(),
            );
            $groups[$key]['finding_ids'][] = (int)$finding['id'];
            $groups[$key]['check_ids'][] = (string)($finding['check_id'] ?? '');
            $groups[$key]['issues'][] = (string)$finding['human_explanation'];
        }
        return array_values($groups);
    }

    /** @param list<string> $scope */
    private function repairGroupLabel(string $category, array $scope): string
    {
        return match (strtoupper($category)) {
            'ECCAIRS_INITIAL', 'ECCAIRS_FOLLOW_UP', 'KNOWN_LIMITATION' => 'ECCAIRS Follow-up',
            'INVESTIGATION' => 'Investigation',
            'CORRECTIVE_ACTION' => 'Corrective Actions',
            'RESPONSIBILITY', 'RECORDS', 'MONITORING', 'TRAINING' => 'Cross-cutting alignment',
            default => 'Manual wording · ' . implode(', ', $scope),
        };
    }

    /** @param array<string,mixed> $prepared */
    private function persistFinding(
        int $planId,
        int $reviewId,
        int $baselineId,
        string $issue,
        array $prepared,
        int $actorUserId
    ): void {
        $classification = $this->classify($issue);
        $sections = $this->sectionNumbers($issue);
        $baselineRow = $this->row(
            'SELECT impact_baseline_json FROM ipca_manual_ai_architect_review_baselines WHERE id=?',
            array($baselineId)
        );
        $baselineImpacts = is_array($baselineRow['impact_baseline_json'] ?? null)
            ? $baselineRow['impact_baseline_json']
            : array();
        $acceptedImpactSections = array_values(array_filter(array_map(
            static fn(array $impact): string => trim((string)($impact['section_number'] ?? '')),
            array_values(array_filter($baselineImpacts, 'is_array'))
        )));
        $outsideAcceptedImpact = array_values(array_filter(
            $sections,
            static function (string $section) use ($acceptedImpactSections): bool {
                foreach ($acceptedImpactSections as $accepted) {
                    if ($section === $accepted || str_starts_with($section, $accepted . '.')) {
                        return false;
                    }
                }
                return true;
            }
        ));
        if ($outsideAcceptedImpact !== array()
            && $classification['class'] !== self::INFORMATIONAL) {
            $classification = array_replace($classification, array(
                'class' => self::POTENTIAL_SCOPE_DEFECT,
                'outcome' => 'POTENTIAL_SCOPE_DEFECT',
                'status' => 'blocked',
                'title' => 'Potential new scope issue',
                'explanation' => 'Independent Review identified a possible requirement outside the accepted Impact Analysis: '
                    . $issue,
                'unresolved_fact' => 'Whether sections ' . implode(', ', $outsideAcceptedImpact)
                    . ' belong in this Change Plan was not decided in the accepted Impact Analysis.',
                'why_matters' => 'The Reviewer cannot silently expand accepted amendment scope.',
                'resolution' => array(
                    'automatic_backward_transition_allowed' => false,
                    'questionnaire_override_allowed' => false,
                    'targeted_patch_allowed' => false,
                    'explicit_reopen_impact_analysis_allowed' => true,
                    'separate_follow_up_allowed' => true,
                ),
            ));
        }
        $acceptedWording = $this->acceptedWording($baselineId, $sections);
        $fingerprint = hash('sha256', $this->json(array(
            'baseline_id' => $baselineId,
            'normalized_issue' => $classification['class'] === self::POTENTIAL_SCOPE_DEFECT
                ? 'potential-scope:' . implode(',', $outsideAcceptedImpact)
                : mb_strtolower(trim($issue)),
            'class' => $classification['class'],
            'sections' => $sections,
        )));
        $existing = $this->row(
            'SELECT id FROM ipca_manual_ai_architect_review_findings
             WHERE plan_id=? AND finding_fingerprint=? LIMIT 1',
            array($planId, $fingerprint)
        );
        if ($existing !== null) {
            if ($classification['class'] === self::POTENTIAL_SCOPE_DEFECT) {
                $this->plans->appendEvent($planId, 'INDEPENDENT_REVIEW_FINDING_EVIDENCE_LINKED', 12, array(
                    'finding_id' => (int)$existing['id'],
                    'additional_review_issue' => $issue,
                    'affected_sections' => $sections,
                    'stage_preserved' => 'review',
                ), $actorUserId);
            }
            return;
        }
        $findingId = $this->plans->save('review_findings', $planId, array(
            'finding_uuid' => $this->uuid(),
            'review_id' => $reviewId,
            'baseline_id' => $baselineId,
            'finding_fingerprint' => $fingerprint,
            'finding_class' => $classification['class'],
            'outcome' => $classification['outcome'],
            'status' => $classification['status'],
            'material' => $classification['material'],
            'blocking' => $classification['blocking'],
            'title' => $classification['title'],
            'human_explanation' => $classification['explanation'],
            'unresolved_fact' => $classification['unresolved_fact'],
            'why_matters' => $classification['why_matters'],
            'affected_sections_json' => $sections,
            'accepted_wording_json' => $acceptedWording,
            'evidence_json' => array(
                'review_issue' => $issue,
                'checks' => (array)($prepared['checks'] ?? array()),
                'source_review_status' => (string)($prepared['status'] ?? ''),
            ),
            'resolution_json' => $classification['resolution'],
            'resolved_at' => $classification['status'] === 'closed' ? gmdate('Y-m-d H:i:s.v') : null,
        ));
        if ($classification['class'] === self::HUMAN_DECISION_REQUIRED) {
            $question = $this->questionFor($issue, $sections, $acceptedWording);
            if ($question !== null) {
                $questionFingerprint = hash('sha256', $this->json(array(
                    'finding_fingerprint' => $fingerprint,
                    'prompt' => $question['prompt'],
                    'choices' => $question['choices'],
                )));
                $this->plans->save('review_questions', $planId, array(
                    'question_uuid' => $this->uuid(),
                    'finding_id' => $findingId,
                    'question_fingerprint' => $questionFingerprint,
                    'question_type' => $question['type'],
                    'title' => $question['title'],
                    'prompt' => $question['prompt'],
                    'choices_json' => $question['choices'],
                    'recommendation_json' => $question['recommendation'],
                    'why_asking' => $question['why_asking'],
                    'affected_sections_json' => $sections,
                    'evidence_json' => array('review_issue' => $issue, 'accepted_wording' => $acceptedWording),
                    'status' => 'pending',
                ));
            }
        }
        $this->plans->appendEvent($planId, 'INDEPENDENT_REVIEW_FINDING_RECORDED', 12, array(
            'review_id' => $reviewId,
            'finding_id' => $findingId,
            'finding_fingerprint' => $fingerprint,
            'finding_class' => $classification['class'],
            'stage_preserved' => 'review',
        ), $actorUserId);
    }

    /** @return array<string,mixed> */
    private function classify(string $issue): array
    {
        $normalized = mb_strtolower($issue);
        $class = self::TARGETED_AUTHOR_CORRECTION;
        $outcome = 'CONFIRMED_DEFECT';
        $status = 'patch_pending';
        $blocking = true;
        $material = true;
        if (preg_match('/\b(?:observation|advisory|for information only|no manual change required)\b/iu', $issue)) {
            $class = self::INFORMATIONAL;
            $outcome = 'PASS';
            $status = 'closed';
            $blocking = false;
            $material = false;
        } elseif (preg_match('/\b(?:cross-reference|cross reference|numbering|governed terminology mismatch)\b/iu', $issue)) {
            $class = self::MECHANICAL_FIX;
            $status = 'patch_pending';
        } elseif (preg_match(
            '/\b(?:stale|wrong source revision|source\/destination mismatch|protected section|protected content|corrupt(?:ed)? operation|unsupported capability|automation claimed operational|legacy occurrence-workflow references remain|accepted mandatory .* (?:missing|disappeared))\b/iu',
            $issue
        )) {
            $class = self::HARD_INTEGRITY_BLOCKER;
            $status = 'blocked';
        } elseif (preg_match(
            '/\b(?:new material requirement|new evidence.*(?:scope|requirement)|not included in the accepted impact analysis)\b/iu',
            $issue
        )) {
            $class = self::POTENTIAL_SCOPE_DEFECT;
            $outcome = 'POTENTIAL_SCOPE_DEFECT';
            $status = 'blocked';
        } elseif (preg_match(
            '/\b(?:who (?:performs|assumes)|unavailability|delegat(?:e|ion)|organizational policy|factual operational capability|should the controlled manual specify|physical hosting|does .* retain the controlled)\b/iu',
            $issue
        ) && $this->isMaterialQuestion($issue)) {
            $class = self::HUMAN_DECISION_REQUIRED;
            $outcome = 'HUMAN_CLARIFICATION_REQUIRED';
            $status = 'question_pending';
        }
        return array(
            'class' => $class,
            'outcome' => $outcome,
            'status' => $status,
            'blocking' => $blocking,
            'material' => $material,
            'title' => $this->plainTitle($issue, $class),
            'explanation' => $this->plainExplanation($issue),
            'unresolved_fact' => $class === self::HUMAN_DECISION_REQUIRED ? $issue : '',
            'why_matters' => $class === self::HUMAN_DECISION_REQUIRED
                ? 'Different answers would materially change responsibility, required control, evidence, or controlled-manual wording.'
                : 'The accepted manual wording must pass this verification without changing accepted scope or structure.',
            'resolution' => array(
                'automatic_backward_transition_allowed' => false,
                'questionnaire_override_allowed' => $class === self::HUMAN_DECISION_REQUIRED,
                'targeted_patch_allowed' => in_array($class, array(
                    self::MECHANICAL_FIX,
                    self::TARGETED_AUTHOR_CORRECTION,
                    self::HARD_INTEGRITY_BLOCKER,
                ), true),
            ),
            'normalized_issue' => $normalized,
        );
    }

    private function isMaterialQuestion(string $issue): bool
    {
        return preg_match(
            '/\b(?:responsib|duty|duties|authority|delegat|evidence|control|operational|policy|retain|manual|report|closure|approval)\w*\b/iu',
            $issue
        ) === 1;
    }

    /** @param list<string> $sections @param array<string,mixed> $wording @return array<string,mixed>|null */
    private function questionFor(string $issue, array $sections, array $wording): ?array
    {
        if (preg_match('/\b(?:unavailability|delegat(?:e|ion)|who (?:performs|assumes))\b/iu', $issue)) {
            return array(
                'type' => 'SINGLE_CHOICE',
                'title' => 'Responsibility during Safety Manager unavailability',
                'prompt' => 'Who should perform the Safety Manager’s occurrence-reporting and ECCAIRS follow-up duties during prolonged unavailability?',
                'choices' => array(
                    $this->choice('accountable-manager', 'Accountable Manager', 'TARGETED_WORDING_CHANGE_REQUIRED', 'The Accountable Manager assumes the specified duties during prolonged Safety Manager unavailability.'),
                    $this->choice('compliance-monitoring-manager', 'Compliance Monitoring Manager', 'TARGETED_WORDING_CHANGE_REQUIRED', 'The Compliance Monitoring Manager assumes the specified duties during prolonged Safety Manager unavailability.'),
                    $this->choice('qualified-safety-manager', 'Another designated qualified Safety Manager', 'TARGETED_WORDING_CHANGE_REQUIRED', 'Another designated qualified Safety Manager assumes the specified duties during prolonged unavailability.'),
                    $this->choice('not-delegable', 'These duties cannot be delegated and remain pending', 'TARGETED_WORDING_CHANGE_REQUIRED', 'The specified duties cannot be delegated during Safety Manager unavailability.'),
                    $this->choice('other', 'Other', 'TARGETED_WORDING_CHANGE_REQUIRED', 'A human-specified continuity rule governs the duties.'),
                ),
                'recommendation' => null,
                'why_asking' => 'This determines whether the controlled manual requires an operational continuity provision.',
            );
        }
        if (preg_match('/\b(?:physical hosting|stored on|controlled electronic repository|hosting arrangement)\b/iu', $issue)) {
            return array(
                'type' => 'SINGLE_CHOICE',
                'title' => 'Technology-neutral record control',
                'prompt' => 'Should the controlled manual specify the physical hosting arrangement?',
                'choices' => array(
                    $this->choice('specify-hosting', 'Yes — specify the approved hosting arrangement', 'TARGETED_WORDING_CHANGE_REQUIRED', 'The controlled manual must identify the approved physical hosting arrangement.'),
                    $this->choice('technology-neutral', 'No — retain technology-neutral controlled-repository wording', 'NO_MANUAL_CHANGE_REQUIRED', 'The manual remains technology-neutral and requires an approved controlled electronic repository.'),
                    $this->choice('other', 'Other', 'TARGETED_WORDING_CHANGE_REQUIRED', 'A human-specified record-control rule applies.'),
                ),
                'recommendation' => null,
                'why_asking' => 'This determines whether implementation detail belongs in the controlled manual or should remain governed outside it.',
            );
        }
        return null;
    }

    /** @return array<string,string> */
    private function choice(string $id, string $label, string $consequence, string $fact): array
    {
        return array(
            'id' => $id,
            'label' => $label,
            'consequence' => $consequence,
            'governed_fact' => $fact,
        );
    }

    /** @return list<string> */
    private function sectionNumbers(string $text): array
    {
        preg_match_all('/\bSection\s+(\d+(?:\.\d+){0,3})\b/iu', $text, $labelledMatches);
        preg_match_all('/(?<![\d\/])\b(\d+\.\d+(?:\.\d+){0,2})\b/u', $text, $bareMatches);
        $sections = array_values(array_unique(array_map(
            'strval',
            array_merge(
                (array)($labelledMatches[1] ?? array()),
                (array)($bareMatches[1] ?? array())
            )
        )));
        if ($sections === array() && preg_match('/\b(?:ECCAIRS|occurrence|accepted consolidated hierarchy|mandatory and voluntary)\b/iu', $text)) {
            $sections[] = '5.6';
        }
        if (preg_match('/\bSafety Manager\b/iu', $text) && !in_array('3.3', $sections, true)) {
            $sections[] = '3.3';
        }
        return $sections;
    }

    /** @param list<string> $sections @return array<string,mixed> */
    private function acceptedWording(int $baselineId, array $sections): array
    {
        $baseline = $this->row(
            'SELECT draft_baseline_json FROM ipca_manual_ai_architect_review_baselines WHERE id=?',
            array($baselineId)
        );
        $draft = $baseline === null
            ? array()
            : (is_array($baseline['draft_baseline_json'] ?? null)
                ? $baseline['draft_baseline_json']
                : $this->decode((string)($baseline['draft_baseline_json'] ?? '')));
        $payload = is_array($draft['draft_payload_json'] ?? null)
            ? $draft['draft_payload_json']
            : $this->decode((string)($draft['draft_payload_json'] ?? ''));
        $drafts = (array)($payload['section_drafts'] ?? array());
        if ($sections === array()) {
            return array();
        }
        return array_intersect_key($drafts, array_fill_keys($sections, true));
    }

    private function plainTitle(string $issue, string $class): string
    {
        return match ($class) {
            self::INFORMATIONAL => 'Review observation',
            self::MECHANICAL_FIX => 'Mechanical correction required',
            self::HUMAN_DECISION_REQUIRED => 'A manual-owner decision is required',
            self::HARD_INTEGRITY_BLOCKER => 'Integrity defect must be corrected',
            self::POTENTIAL_SCOPE_DEFECT => 'Potential new scope issue',
            default => 'Targeted wording correction required',
        };
    }

    private function plainExplanation(string $issue): string
    {
        return preg_replace(
            '/\b(?:semantic projection defect|target-state assertion conflict|candidate eligibility|coverage matrix inconsistency|schema violation)\b/iu',
            'review inconsistency',
            $issue
        ) ?? $issue;
    }

    /**
     * @param list<string> $restoreNodes
     * @return array{
     *   candidate_proposal:array<string,mixed>,
     *   scope_sections:list<string>,
     *   restored_nodes:list<string>,
     *   changed_sections:array<string,array<string,mixed>>,
     *   restored_node_fingerprints:array<string,string>,
     *   frozen_node_fingerprints:array<string,string>,
     *   unchanged_section_fingerprints:array<string,string>
     * }
     */
    private function buildHistoricalScopeRepairCandidate(
        array $acceptedStep4,
        array $currentCandidate,
        array $restoreNodes
    ): array {
        $restoreNodes = array_values(array_unique(array_filter(array_map(
            static fn(mixed $node): string => trim((string)$node),
            $restoreNodes
        ))));
        if ($restoreNodes === array()) {
            throw new RuntimeException('Historical scope repair has no nodes to restore.');
        }
        $acceptedDrafts = (array)($acceptedStep4['section_drafts'] ?? array());
        $currentDrafts = (array)($currentCandidate['section_drafts'] ?? array());
        if (array_keys($acceptedDrafts) !== array_keys($currentDrafts)) {
            throw new RuntimeException('Historical scope repair cannot alter the accepted section structure.');
        }
        $candidate = $currentCandidate;
        $restoreSet = array_fill_keys($restoreNodes, true);
        $restored = array();
        $scopeSections = array();
        $changedSections = array();
        $restoredFingerprints = array();
        $frozenFingerprints = array();
        $unchangedSectionFingerprints = array();
        foreach ($currentDrafts as $section => $currentDraft) {
            $acceptedDraft = (array)($acceptedDrafts[$section] ?? array());
            $acceptedNodes = (array)($acceptedDraft['nodes'] ?? array());
            $currentNodes = (array)($currentDraft['nodes'] ?? array());
            if (array_keys($acceptedNodes) !== array_keys($currentNodes)) {
                throw new RuntimeException(
                    "Historical scope repair cannot alter accepted nodes in Section {$section}."
                );
            }
            $beforeNodes = array();
            $afterNodes = array();
            foreach ($currentNodes as $number => $currentContent) {
                $number = (string)$number;
                if (isset($restoreSet[$number])) {
                    if (!array_key_exists($number, $acceptedNodes)) {
                        throw new RuntimeException("Accepted Step 4 node {$number} is unavailable.");
                    }
                    if ($this->json($acceptedNodes[$number]) === $this->json($currentContent)) {
                        throw new RuntimeException(
                            "Historical scope repair node {$number} already matches accepted Step 4."
                        );
                    }
                    $candidate['section_drafts'][$section]['nodes'][$number] = $acceptedNodes[$number];
                    $beforeNodes[$number] = $currentContent;
                    $afterNodes[$number] = $acceptedNodes[$number];
                    $restored[] = $number;
                    $scopeSections[(string)$section] = true;
                    $restoredFingerprints[$number] = hash(
                        'sha256',
                        $this->json($acceptedNodes[$number])
                    );
                    continue;
                }
                $frozenFingerprints[$number] = hash('sha256', $this->json($currentContent));
            }
            if ($beforeNodes !== array()) {
                $changedSections[(string)$section] = array(
                    'reason' => 'Restore exact accepted Step 4 wording unintentionally changed by failed Patch 1.',
                    'repair_type' => 'HISTORICAL_SCOPE_REPAIR',
                    'changed_nodes' => array_keys($beforeNodes),
                    'before' => array('nodes' => $beforeNodes),
                    'after' => array('nodes' => $afterNodes),
                );
            } else {
                $unchangedSectionFingerprints[(string)$section] = hash(
                    'sha256',
                    $this->json($currentDraft)
                );
            }
        }
        sort($restored, SORT_NATURAL);
        $missing = array_values(array_diff($restoreNodes, $restored));
        if ($missing !== array()) {
            throw new RuntimeException(
                'Historical scope repair could not locate nodes: ' . implode(', ', $missing)
            );
        }
        return array(
            'candidate_proposal' => $candidate,
            'scope_sections' => array_keys($scopeSections),
            'restored_nodes' => $restored,
            'changed_sections' => $changedSections,
            'restored_node_fingerprints' => $restoredFingerprints,
            'frozen_node_fingerprints' => $frozenFingerprints,
            'unchanged_section_fingerprints' => $unchangedSectionFingerprints,
        );
    }

    private function nodeContent(array $proposal, string $node): ?string
    {
        foreach ((array)($proposal['section_drafts'] ?? array()) as $draft) {
            if (array_key_exists($node, (array)($draft['nodes'] ?? array()))) {
                return (string)$draft['nodes'][$node];
            }
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    private function latestAcceptedDraft(int $planId): ?array
    {
        foreach (array_reverse($this->rows(
            "SELECT * FROM ipca_manual_ai_architect_drafts
             WHERE plan_id=? AND status='generated' ORDER BY id DESC",
            array($planId)
        )) as $draft) {
            if ((string)($draft['draft_payload_json']['wizard_status'] ?? '') === 'accepted') {
                return $draft;
            }
        }
        return null;
    }

    /**
     * @param list<array<string,mixed>> $checks
     * @param array<string,mixed> $context
     * @return array{checks_before:int,checks_fixed:int,checks_remaining:int,new_checks:int,regressed_checks:int,checks_after:int}
     */
    private function reconcileVerificationChecks(
        int $planId,
        int $reviewId,
        int $baselineId,
        array $checks,
        int $actorUserId,
        array $context = array()
    ): array {
        $before = $this->unresolvedCheckIds($planId, $reviewId);
        $existingRows = $this->rows(
            'SELECT check_id,resolution_status FROM ipca_manual_ai_architect_review_check_metadata
             WHERE plan_id=? AND review_id=?',
            array($planId, $reviewId)
        );
        $existing = array();
        foreach ($existingRows as $row) {
            $existing[(string)$row['check_id']] = strtoupper((string)$row['resolution_status']);
        }
        $newFailures = array();
        $regressedChecks = array();
        foreach ($checks as $check) {
            if (!is_array($check) || trim((string)($check['check_id'] ?? '')) === '') {
                continue;
            }
            $checkId = (string)$check['check_id'];
            if ((string)($check['status'] ?? '') === 'FAIL') {
                if (!isset($existing[$checkId])) {
                    $newFailures[] = $checkId;
                } elseif ($existing[$checkId] === 'VERIFIED') {
                    $regressedChecks[] = $checkId;
                }
            }
            $this->persistReviewCheck(
                $planId,
                $reviewId,
                $baselineId,
                $check,
                array(
                    'status' => 'REVERIFIED',
                    'patch_context' => $context,
                ),
                $actorUserId
            );
        }
        $after = $this->unresolvedCheckIds($planId, $reviewId);
        return array(
            'checks_before' => count($before),
            'checks_fixed' => count(array_diff($before, $after)),
            'checks_remaining' => count(array_intersect($before, $after)),
            'new_checks' => count(array_values(array_unique($newFailures))),
            'regressed_checks' => count(array_values(array_unique($regressedChecks))),
            'checks_after' => count($after),
        );
    }

    /** @return list<string> */
    private function unresolvedCheckIds(int $planId, int $reviewId): array
    {
        return array_values(array_map(
            static fn(array $row): string => (string)$row['check_id'],
            $this->rows(
                "SELECT check_id FROM ipca_manual_ai_architect_review_check_metadata
                 WHERE plan_id=? AND review_id=?
                   AND resolution_status IN ('UNRESOLVED','BLOCKED')",
                array($planId, $reviewId)
            )
        ));
    }

    /** @param array<string,mixed> $metricsOverride */
    private function recordCycle(
        int $planId,
        int $reviewId,
        int $baselineId,
        array $metricsOverride = array()
    ): void
    {
        $state = $this->state($planId, $reviewId);
        $prior = $this->rows(
            'SELECT * FROM ipca_manual_ai_architect_review_cycles
             WHERE plan_id=? AND review_id=?',
            array($planId, $reviewId)
        );
        $cycleNumber = count($prior) + 1;
        $before = array_key_exists('checks_before', $metricsOverride)
            ? (int)$metricsOverride['checks_before']
            : ($prior === array()
                ? (int)$state['unresolved_material_findings']
                : (int)$prior[array_key_last($prior)]['unresolved_after']);
        $after = array_key_exists('checks_after', $metricsOverride)
            ? (int)$metricsOverride['checks_after']
            : (int)$state['unresolved_material_findings'];
        $newChecks = (int)($metricsOverride['new_checks'] ?? 0);
        $alreadyDiverged = false;
        foreach ($prior as $cycle) {
            $cycleStatus = (string)($cycle['status'] ?? '');
            if ($cycleStatus === 'REVIEW_DIVERGENCE_DETECTED') {
                $alreadyDiverged = true;
            } elseif ($cycleStatus === 'REVIEW_DIVERGENCE_RESOLVED') {
                $alreadyDiverged = false;
            }
        }
        $divergenceResolved = !empty($metricsOverride['divergence_resolved']);
        $diverged = !$divergenceResolved && ($alreadyDiverged
            || ($newChecks >= 3 || ($newChecks > 0 && $after > $before))
            || (count($prior) >= 2
                && $after > (int)$prior[array_key_last($prior)]['unresolved_after']
                && (int)$prior[array_key_last($prior)]['unresolved_after']
                    > (int)$prior[array_key_last($prior) - 1]['unresolved_after']));
        $status = $divergenceResolved
            ? 'REVIEW_DIVERGENCE_RESOLVED'
            : ($diverged
                ? 'REVIEW_DIVERGENCE_DETECTED'
                : ($after === 0 ? 'READY_TO_APPLY' : ($after < $before ? 'CONVERGING' : 'OPEN')));
        $metrics = array(
            'unresolved_before' => $before,
            'unresolved_after' => $after,
            'checks_before' => $before,
            'checks_fixed' => (int)($metricsOverride['checks_fixed'] ?? max(0, $before - $after)),
            'checks_remaining' => (int)($metricsOverride['checks_remaining'] ?? $after),
            'new_checks' => $newChecks,
            'checks_after' => $after,
            'divergence_resolved' => $divergenceResolved,
            'counts' => $state['counts'],
            'questions_answered' => count((array)$state['answers']),
            'patches' => count((array)$state['patches']),
            'patch_id' => (int)($metricsOverride['patch_id'] ?? 0),
        );
        $fingerprint = hash('sha256', $this->json(array(
            'review_id' => $reviewId,
            'cycle_number' => $cycleNumber,
            'metrics' => $metrics,
        )));
        $this->plans->save('review_cycles', $planId, array(
            'cycle_uuid' => $this->uuid(),
            'review_id' => $reviewId,
            'baseline_id' => $baselineId,
            'cycle_number' => $cycleNumber,
            'unresolved_before' => $before,
            'informational_count' => (int)($state['counts'][self::INFORMATIONAL] ?? 0),
            'mechanical_fix_count' => (int)($state['counts'][self::MECHANICAL_FIX] ?? 0),
            'human_question_count' => (int)($state['counts'][self::HUMAN_DECISION_REQUIRED] ?? 0),
            'governed_answer_count' => count((array)$state['answers']),
            'targeted_patch_count' => count((array)$state['patches']),
            'accepted_patch_count' => count(array_filter(
                (array)$state['patches'],
                static fn(array $patch): bool => in_array(
                    strtoupper((string)$patch['status']),
                    array('HUMAN_ACCEPTED_PENDING_VERIFICATION', 'VERIFIED', 'VERIFICATION_FAILED'),
                    true
                )
            )),
            'hard_blocker_count' => (int)$state['hard_blockers'],
            'unresolved_after' => $after,
            'status' => $status,
            'metrics_json' => $metrics,
            'cycle_fingerprint' => $fingerprint,
        ));
    }

    private function baselineIdForReview(int $reviewId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT MAX(id) FROM ipca_manual_ai_architect_review_baselines
             WHERE review_id=?'
        );
        $stmt->execute(array($reviewId));
        return (int)$stmt->fetchColumn();
    }

    /** @return array<string,mixed>|null */
    private function row(string $sql, array $params = array()): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->decodeRow($row) : null;
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $sql, array $params = array()): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = array_map(
            fn(array $row): array => $this->decodeRow($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array()
        );
        if ($rows !== array() && array_key_exists('id', $rows[0])) {
            usort(
                $rows,
                static fn(array $left, array $right): int =>
                    (int)($left['id'] ?? 0) <=> (int)($right['id'] ?? 0)
            );
        }
        return $rows;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function decodeRow(array $row): array
    {
        foreach ($row as $key => $value) {
            if (str_ends_with((string)$key, '_json') && is_string($value)) {
                $row[$key] = $this->decode($value);
            }
        }
        return $row;
    }

    /** @return array<mixed> */
    private function decode(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : array();
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
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
