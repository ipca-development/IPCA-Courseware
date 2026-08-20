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
        $this->plans->updatePlan($planId, array(
            'stage' => 'review',
            'status' => $issues === array() ? 'ready_for_review' : 'blocked',
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
            'SELECT * FROM ipca_manual_ai_architect_review_findings
             WHERE plan_id=? AND review_id=?',
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
        $unresolved = 0;
        $hardBlockers = 0;
        foreach ($findings as $finding) {
            $class = (string)$finding['finding_class'];
            $counts[$class] = ($counts[$class] ?? 0) + 1;
            if (!in_array((string)$finding['status'], array('closed', 'verified'), true)
                && (int)$finding['blocking'] === 1) {
                $unresolved++;
                if ($class === self::HARD_INTEGRITY_BLOCKER) {
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
                (string)$patch['status'],
                array('proposed', 'adjustment_requested', 'accepted'),
                true
            )
        ));
        $cycles = $this->rows(
            'SELECT * FROM ipca_manual_ai_architect_review_cycles
             WHERE plan_id=? AND review_id=?',
            array($planId, $reviewId)
        );
        $diverged = $cycles !== array()
            && (string)$cycles[array_key_last($cycles)]['status'] === 'REVIEW_DIVERGENCE_DETECTED';
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
            'findings' => $findings,
            'questions' => $questions,
            'answers' => $answers,
            'pending_question' => $pendingQuestions[0] ?? null,
            'patches' => $patches,
            'convergence_cycles' => $cycles,
            'review_divergence_detected' => $diverged,
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
        $plan = $this->plans->getPlan($planId);
        if ((int)($plan['owner_id'] ?? 0) !== $actorUserId
            || (string)($plan['stage'] ?? '') !== 'review') {
            throw new RuntimeException('Targeted corrections may only be proposed during Independent Review.');
        }
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
            if ((string)($existing['status'] ?? '') === 'adjustment_requested') {
                throw new RuntimeException('The regenerated correction did not address the requested adjustment.');
            }
            return $existing;
        }
        $this->pdo->prepare(
            "UPDATE ipca_manual_ai_architect_review_patches
             SET status='rejected'
             WHERE plan_id=? AND status='adjustment_requested'"
        )->execute(array($planId));
        $patchId = $this->plans->save('review_patches', $planId, array(
            'patch_uuid' => $this->uuid(),
            'baseline_id' => $baselineId,
            'parent_draft_id' => $parentDraftId,
            'finding_ids_json' => $findingIds,
            'scope_json' => array('sections' => $patchResult['scope_sections']),
            'before_payload_json' => array_map(
                static fn(array $change): mixed => $change['before'] ?? array(),
                (array)$patchResult['changed_sections']
            ),
            'proposed_payload_json' => $patchResult,
            'unchanged_fingerprints_json' => $patchResult['unchanged_section_fingerprints'],
            'patch_fingerprint' => $fingerprint,
            'status' => 'proposed',
            'proposed_by' => $actorUserId,
        ));
        $this->plans->appendEvent($planId, 'INDEPENDENT_REVIEW_TARGETED_PATCH_PROPOSED', 12, array(
            'patch_id' => $patchId,
            'finding_ids' => $findingIds,
            'scope_sections' => $patchResult['scope_sections'],
            'patch_fingerprint' => $fingerprint,
            'stage_preserved' => 'review',
        ), $actorUserId);
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
        if ($patch === null || !in_array((string)$patch['status'], array('proposed', 'adjustment_requested'), true)) {
            throw new RuntimeException('Targeted correction is not available for acceptance.');
        }
        $patchPayload = (array)$patch['proposed_payload_json'];
        $candidate = (array)($patchPayload['candidate_proposal'] ?? array());
        $parentDraft = $this->row(
            'SELECT * FROM ipca_manual_ai_architect_drafts WHERE id=? AND plan_id=? LIMIT 1',
            array((int)$patch['parent_draft_id'], $planId)
        );
        if ($parentDraft === null) {
            throw new RuntimeException('The accepted draft baseline for this correction is unavailable.');
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
        $candidate['wizard_status'] = 'accepted';
        $candidate['targeted_patch'] = array(
            'patch_id' => $patchId,
            'parent_draft_id' => (int)$patch['parent_draft_id'],
            'finding_ids' => (array)$patch['finding_ids_json'],
            'accepted_by' => $actorUserId,
            'accepted_at' => gmdate(DATE_ATOM),
        );
        $verification = $reviewer->verifyTargetedPatch(
            $parentPayload,
            $candidate,
            array_values((array)($patch['scope_json']['sections'] ?? array()))
        );
        $versionStmt = $this->pdo->prepare(
            'SELECT COALESCE(MAX(draft_version),0)+1
             FROM ipca_manual_ai_architect_drafts WHERE plan_id=?'
        );
        $versionStmt->execute(array($planId));
        $draftVersion = max(1, (int)$versionStmt->fetchColumn());
        $encoded = $this->json($candidate);
        $findingIds = array_values(array_map('intval', (array)$patch['finding_ids_json']));
        $this->pdo->beginTransaction();
        try {
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
            $verified = (string)($verification['status'] ?? '') === 'VERIFIED';
            $this->pdo->prepare(
                'UPDATE ipca_manual_ai_architect_review_patches
                 SET status=?,resulting_draft_id=?,verification_json=?,accepted_by=?,
                     accepted_at=CURRENT_TIMESTAMP(3),verified_at=?
                 WHERE id=? AND plan_id=?'
            )->execute(array(
                $verified ? 'verified' : 'accepted',
                $draftId,
                $this->json($verification),
                $actorUserId,
                $verified ? gmdate('Y-m-d H:i:s.v') : null,
                $patchId,
                $planId,
            ));
            if ($findingIds !== array()) {
                $placeholders = implode(',', array_fill(0, count($findingIds), '?'));
                $this->pdo->prepare(
                    "UPDATE ipca_manual_ai_architect_review_findings
                     SET status=?,resolution_json=?,resolved_at=?
                     WHERE plan_id=? AND id IN ({$placeholders})"
                )->execute(array_merge(array(
                    $verified ? 'verified' : 'blocked',
                    $this->json(array(
                        'patch_id' => $patchId,
                        'resulting_draft_id' => $draftId,
                        'verification' => $verification,
                    )),
                    $verified ? gmdate('Y-m-d H:i:s.v') : null,
                    $planId,
                ), $findingIds));
            }
            $this->plans->appendEvent($planId, 'INDEPENDENT_REVIEW_TARGETED_PATCH_ACCEPTED', 12, array(
                'patch_id' => $patchId,
                'parent_draft_id' => (int)$patch['parent_draft_id'],
                'resulting_draft_id' => $draftId,
                'verification_status' => $verification['status'],
                'unaffected_wording_unchanged' => $verification['unaffected_sections_byte_unchanged'],
                'accepted_structure_preserved' => $verification['accepted_structure_preserved'],
                'stage_preserved' => 'review',
            ), $actorUserId);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        $reviewId = (int)($this->row(
            'SELECT review_id FROM ipca_manual_ai_architect_review_findings
             WHERE plan_id=? AND id=?',
            array($planId, $findingIds[0] ?? 0)
        )['review_id'] ?? 0);
        $newBaseline = $this->freezeBaseline($planId, $reviewId, $actorUserId);
        $this->recordCycle($planId, $reviewId, (int)$newBaseline['id']);
        return $this->state($planId, $reviewId);
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
        $this->pdo->prepare(
            "UPDATE ipca_manual_ai_architect_review_patches
             SET status='adjustment_requested',verification_json=? WHERE id=? AND plan_id=?"
        )->execute(array($this->json(array('adjustment_reason' => trim($reason))), $patchId, $planId));
        $this->plans->appendEvent($planId, 'INDEPENDENT_REVIEW_PATCH_ADJUSTMENT_REQUESTED', 12, array(
            'patch_id' => $patchId,
            'reason' => trim($reason),
            'stage_preserved' => 'review',
        ), $actorUserId);
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
                    return (string)$finding['finding_class'] === self::POTENTIAL_SCOPE_DEFECT;
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

    private function recordCycle(int $planId, int $reviewId, int $baselineId): void
    {
        $state = $this->state($planId, $reviewId);
        $prior = $this->rows(
            'SELECT * FROM ipca_manual_ai_architect_review_cycles
             WHERE plan_id=? AND review_id=?',
            array($planId, $reviewId)
        );
        $cycleNumber = count($prior) + 1;
        $before = $prior === array()
            ? (int)$state['unresolved_material_findings']
            : (int)$prior[array_key_last($prior)]['unresolved_after'];
        $after = (int)$state['unresolved_material_findings'];
        $diverged = count($prior) >= 2
            && $after > (int)$prior[array_key_last($prior)]['unresolved_after']
            && (int)$prior[array_key_last($prior)]['unresolved_after']
                > (int)$prior[array_key_last($prior) - 1]['unresolved_after'];
        $status = $diverged
            ? 'REVIEW_DIVERGENCE_DETECTED'
            : ($after === 0 ? 'READY_TO_APPLY' : ($after < $before ? 'CONVERGING' : 'OPEN'));
        $metrics = array(
            'unresolved_before' => $before,
            'unresolved_after' => $after,
            'counts' => $state['counts'],
            'questions_answered' => count((array)$state['answers']),
            'patches' => count((array)$state['patches']),
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
                static fn(array $patch): bool => in_array((string)$patch['status'], array('accepted', 'verified'), true)
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
