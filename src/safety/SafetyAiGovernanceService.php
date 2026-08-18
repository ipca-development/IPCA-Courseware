<?php
declare(strict_types=1);

require_once __DIR__ . '/SafetySupport.php';
require_once __DIR__ . '/SafetyAccessService.php';
require_once __DIR__ . '/SafetyAuditEventService.php';
require_once __DIR__ . '/SafetyFeatureConfigService.php';

/**
 * Governance boundary for optional AI assistance.
 *
 * This service prepares de-identified payloads and records outputs. It does not
 * call a provider and it never applies an output to an SMS business record.
 */
final class SafetyAiGovernanceService
{
    private const USE_CASES = array(
        'taxonomy_suggestions',
        'duplicate_candidates',
        'summary',
        'trend_candidates',
        'missing_field_prompts',
    );

    private const FORBIDDEN_KEYS = array(
        'blame',
        'culpability',
        'just_culture',
        'just_culture_decision',
        'risk_acceptance',
        'accept_risk',
        'reportability',
        'reportability_decision',
        'action_approval',
        'approve_action',
        'closure',
        'closure_decision',
        'close_report',
    );

    private const IDENTITY_KEYS = array(
        'name',
        'first_name',
        'last_name',
        'full_name',
        'email',
        'phone',
        'reporter',
        'reporter_identity',
        'reporter_user_id',
        'user_id',
        'device_id',
        'device_uuid',
        'ip_address',
        'identity_ciphertext',
        'key_reference',
        'report_number',
        'aircraft_registration',
        'registration',
        'tail_number',
        'location_text',
    );

    public function __construct(
        private PDO $pdo,
        private SafetyAccessService $access,
        private SafetyFeatureConfigService $config,
        private SafetyAuditEventService $events
    ) {
    }

    /**
     * @param array<string,mixed> $session
     * @param array<string,mixed> $input
     * @param array<string,mixed> $provenance
     * @return array<string,mixed>
     */
    public function request(
        array $session,
        string $useCase,
        string $subjectType,
        int $subjectId,
        string $provider,
        string $model,
        string $templateVersion,
        array $input,
        array $provenance
    ): array {
        $this->access->requirePermission($session, 'ai.assist');
        $org = SafetySupport::organizationId($session);
        $this->config->requireEnabled($org, 'ai_enabled');
        $useCase = strtolower(trim($useCase));
        if (!in_array($useCase, self::USE_CASES, true)) {
            throw new SafetyException('ai_use_case_blocked', 'This AI use case is not permitted for safety work.', 403);
        }
        if ($subjectId < 1) {
            throw new SafetyException('validation_error', 'A persisted safety subject is required.', 400);
        }
        $this->assertSubjectScoped($org, $subjectType, $subjectId);
        if ($this->containsFreeText($input)
            && (($provenance['human_deidentification_reviewed'] ?? false) !== true
                || trim((string)($provenance['deidentification_reviewed_at_utc'] ?? '')) === '')) {
            throw new SafetyException(
                'human_deidentification_review_required',
                'Free-text safety data requires a recorded human de-identification review before AI assistance.',
                409
            );
        }
        $deidentified = self::deidentify($input);
        if ($deidentified === array()) {
            throw new SafetyException('validation_error', 'No de-identified input remains for AI assistance.', 400);
        }
        $provenance = $this->normalizeProvenance($provenance);
        $uuid = SafetySupport::uuid();
        $inputDigest = SafetySupport::digest(SafetySupport::json($deidentified));
        $this->pdo->prepare(
            "INSERT INTO ipca_safety_ai_runs
             (organization_id, run_uuid, use_case, subject_type, subject_id, provider, model,
              prompt_template_version, input_digest, data_classification, deidentification_version,
              provenance_json, status, requested_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'deidentified', 'safety-deid-v1', ?, 'requested', ?)"
        )->execute(array(
            $org, $uuid, $useCase, SafetySupport::cleanText($subjectType, 48, 'subject_type'), $subjectId,
            SafetySupport::cleanText($provider, 64, 'provider'),
            SafetySupport::cleanText($model, 96, 'model'),
            SafetySupport::cleanText($templateVersion, 64, 'template_version'),
            $inputDigest, SafetySupport::json($provenance), (int)$session['user']['id'],
        ));
        $runId = (int)$this->pdo->lastInsertId();
        $this->events->append($org, 'ai_run', $runId, 'ai.assistance_requested',
            'user', (int)$session['user']['id'], null, array(
                'use_case' => $useCase,
                'input_digest' => $inputDigest,
                'deidentification_version' => 'safety-deid-v1',
            ));
        return array(
            'run_uuid' => $uuid,
            'use_case' => $useCase,
            'deidentified_input' => $deidentified,
            'input_digest' => $inputDigest,
            'human_review_required' => true,
            'advisory_only' => true,
            'prohibited_decisions' => self::FORBIDDEN_KEYS,
        );
    }

    /**
     * @param array<string,mixed> $session
     * @param array<string,mixed> $output
     * @param array<string,mixed> $providerProvenance
     */
    public function complete(
        array $session,
        string $runUuid,
        array $output,
        array $providerProvenance
    ): array {
        $this->access->requirePermission($session, 'ai.assist');
        $org = SafetySupport::organizationId($session);
        $run = $this->findRun($org, $runUuid);
        if ((string)$run['status'] !== 'requested') {
            throw new SafetyException('workflow_gate_failed', 'Only a requested AI run can be completed.', 409);
        }
        $this->assertAdvisoryOutput((string)$run['use_case'], $output);
        $providerProvenance = $this->normalizeProvenance($providerProvenance);
        $outputJson = SafetySupport::json($output);
        $outputDigest = SafetySupport::digest($outputJson);
        $stmt = $this->pdo->prepare(
            "UPDATE ipca_safety_ai_runs
             SET output_json = ?, output_digest = ?, provider_provenance_json = ?,
               status = 'awaiting_review', completed_at_utc = CURRENT_TIMESTAMP(3)
             WHERE organization_id = ? AND id = ? AND status = 'requested'"
        );
        $stmt->execute(array(
            $outputJson, $outputDigest, SafetySupport::json($providerProvenance), $org, $run['id'],
        ));
        if ($stmt->rowCount() !== 1) {
            throw new SafetyException('workflow_gate_failed', 'AI run state changed before completion.', 409);
        }
        $this->events->append($org, 'ai_run', (int)$run['id'], 'ai.output_recorded',
            'user', (int)$session['user']['id'], null, array(
                'output_digest' => $outputDigest,
                'status' => 'awaiting_review',
            ));
        return array(
            'run_uuid' => $runUuid,
            'output_digest' => $outputDigest,
            'status' => 'awaiting_review',
            'human_review_required' => true,
        );
    }

    /** @param array<string,mixed> $session */
    public function review(array $session, string $runUuid, string $decision, string $notes): void
    {
        $this->access->requirePermission($session, 'ai.review');
        if (!in_array($decision, array('accepted_as_advisory', 'rejected', 'needs_revision'), true)) {
            throw new SafetyException('validation_error', 'Invalid AI review decision.', 400);
        }
        $org = SafetySupport::organizationId($session);
        $run = $this->findRun($org, $runUuid);
        if ((string)$run['status'] !== 'awaiting_review' || $run['output_digest'] === null) {
            throw new SafetyException('workflow_gate_failed', 'A recorded AI output must await review.', 409);
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'INSERT INTO ipca_safety_ai_reviews
                 (organization_id, ai_run_id, decision, reviewer_user_id, review_notes, reviewed_output_digest)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute(array(
                $org, $run['id'], $decision, (int)$session['user']['id'],
                SafetySupport::cleanText($notes, 12000, 'review_notes'), $run['output_digest'],
            ));
            $status = $decision === 'accepted_as_advisory' ? 'reviewed' : $decision;
            $this->pdo->prepare(
                'UPDATE ipca_safety_ai_runs SET status = ? WHERE organization_id = ? AND id = ?'
            )->execute(array($status, $org, $run['id']));
            $this->events->append($org, 'ai_run', (int)$run['id'], 'ai.output_human_reviewed',
                'user', (int)$session['user']['id'], null, array(
                    'decision' => $decision,
                    'reviewed_output_digest' => $run['output_digest'],
                    'advisory_only' => true,
                ));
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string,mixed> $session @return array<string,mixed> */
    public function get(array $session, string $runUuid): array
    {
        $this->access->requirePermission($session, 'ai.review');
        $org = SafetySupport::organizationId($session);
        $run = $this->findRun($org, $runUuid);
        $review = $this->pdo->prepare(
            'SELECT decision, reviewer_user_id, review_notes, reviewed_output_digest, reviewed_at_utc
             FROM ipca_safety_ai_reviews WHERE organization_id = ? AND ai_run_id = ?'
        );
        $review->execute(array($org, $run['id']));
        return array(
            'run_uuid' => $run['run_uuid'],
            'use_case' => $run['use_case'],
            'subject_type' => $run['subject_type'],
            'subject_id' => (int)$run['subject_id'],
            'provider' => $run['provider'],
            'model' => $run['model'],
            'prompt_template_version' => $run['prompt_template_version'],
            'input_digest' => $run['input_digest'],
            'output_digest' => $run['output_digest'],
            'output' => $run['output_json'] === null ? null : json_decode((string)$run['output_json'], true),
            'status' => $run['status'],
            'data_classification' => $run['data_classification'],
            'deidentification_version' => $run['deidentification_version'],
            'provenance' => json_decode((string)$run['provenance_json'], true),
            'provider_provenance' => $run['provider_provenance_json'] === null
                ? null : json_decode((string)$run['provider_provenance_json'], true),
            'review' => $review->fetch(PDO::FETCH_ASSOC) ?: null,
            'advisory_only' => true,
        );
    }

    /**
     * Deterministic de-identification for provider-bound payloads.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function deidentify(array $input): array
    {
        $clean = array();
        foreach ($input as $key => $value) {
            $normalizedKey = strtolower((string)$key);
            if (in_array($normalizedKey, self::IDENTITY_KEYS, true)
                || preg_match('/(_id|_uuid|_reference)$/', $normalizedKey)
                || preg_match('/(^|_)(identity|reporter|person|pilot|student|instructor|employee)(_|$)/', $normalizedKey)) {
                continue;
            }
            if (is_array($value)) {
                $clean[$key] = self::deidentify($value);
            } elseif (is_string($value)) {
                $clean[$key] = self::redactText($value);
            } elseif (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                $clean[$key] = $value;
            }
        }
        return $clean;
    }

    private static function redactText(string $text): string
    {
        $text = preg_replace('/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/i', '[REDACTED_EMAIL]', $text) ?? $text;
        $text = preg_replace('/(?<!\w)(?:\+?\d[\d\s().\-]{7,}\d)(?!\w)/', '[REDACTED_PHONE]', $text) ?? $text;
        $text = preg_replace('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', '[REDACTED_NETWORK]', $text) ?? $text;
        $text = preg_replace('/\bN[0-9]{1,5}[A-Z]{0,2}\b/i', '[REDACTED_AIRCRAFT]', $text) ?? $text;
        return $text;
    }

    private function assertSubjectScoped(int $organizationId, string $subjectType, int $subjectId): void
    {
        $tables = array(
            'report' => 'ipca_safety_reports',
            'occurrence' => 'ipca_safety_occurrences',
            'hazard' => 'ipca_safety_hazards',
            'investigation' => 'ipca_safety_investigations',
            'action' => 'ipca_safety_actions',
            'spi' => 'ipca_safety_spis',
        );
        $table = $tables[strtolower(trim($subjectType))] ?? null;
        if ($table === null) {
            throw new SafetyException('validation_error', 'Unsupported AI safety subject.', 400);
        }
        $stmt = $this->pdo->prepare('SELECT id FROM ' . $table . ' WHERE organization_id = ? AND id = ?');
        $stmt->execute(array($organizationId, $subjectId));
        if (!$stmt->fetchColumn()) {
            throw new SafetyException('not_found', 'AI safety subject not found in this organization.', 404);
        }
    }

    /** @param array<string,mixed> $input */
    private function containsFreeText(array $input): bool
    {
        foreach ($input as $value) {
            if (is_array($value) && $this->containsFreeText($value)) {
                return true;
            }
            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $output */
    private function assertAdvisoryOutput(string $useCase, array $output): void
    {
        if ($output === array()) {
            throw new SafetyException('validation_error', 'AI output cannot be empty.', 400);
        }
        $required = array(
            'taxonomy_suggestions' => 'suggestions',
            'duplicate_candidates' => 'candidates',
            'summary' => 'summary',
            'trend_candidates' => 'candidates',
            'missing_field_prompts' => 'prompts',
        );
        if (!array_key_exists($required[$useCase], $output)) {
            throw new SafetyException('validation_error', 'AI output does not match the approved use-case template.', 400);
        }
        $walk = function (array $node) use (&$walk): void {
            foreach ($node as $key => $value) {
                $normalized = strtolower((string)$key);
                if (in_array($normalized, self::FORBIDDEN_KEYS, true)
                    || preg_match('/(blame|culpab|just_culture|risk_accept|reportab|action_approv|closure_decision)/', $normalized)) {
                    throw new SafetyException(
                        'ai_decision_boundary_violation',
                        'AI output attempted to cross a reserved human decision boundary.',
                        403
                    );
                }
                if (is_array($value)) {
                    $walk($value);
                } elseif (is_string($value) && preg_match(
                    '/\b(at fault|to blame|culpable|just[- ]culture decision|'
                    . 'accept(?:ed)? (?:the )?risk|(?:is|was|deemed) (?:not )?reportable|'
                    . 'approve(?:d)? (?:the )?action|close(?:d)? (?:the )?(?:report|action))\b/i',
                    $value
                )) {
                    throw new SafetyException(
                        'ai_decision_boundary_violation',
                        'AI output contains a reserved human decision.',
                        403
                    );
                }
            }
        };
        $walk($output);
    }

    /** @param array<string,mixed> $provenance @return array<string,mixed> */
    private function normalizeProvenance(array $provenance): array
    {
        foreach (array('source', 'purpose') as $required) {
            if (!isset($provenance[$required]) || !is_string($provenance[$required]) || trim($provenance[$required]) === '') {
                throw new SafetyException('validation_error', 'AI provenance requires source and purpose.', 400);
            }
        }
        $encoded = strtolower(SafetySupport::json($provenance));
        if (preg_match('/"(name|email|phone|reporter|user_id|device_id|ip_address)"\s*:/', $encoded)) {
            throw new SafetyException('ai_data_classification_blocked', 'AI provenance must be de-identified.', 403);
        }
        $provenance['human_review_required'] = true;
        $provenance['advisory_only'] = true;
        $provenance['decision_boundary_version'] = 'sms-human-authority-v1';
        return $provenance;
    }

    /** @return array<string,mixed> */
    private function findRun(int $organizationId, string $runUuid): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ipca_safety_ai_runs WHERE organization_id = ? AND run_uuid = ?'
        );
        $stmt->execute(array($organizationId, strtolower(trim($runUuid))));
        $run = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($run)) {
            throw new SafetyException('not_found', 'AI run not found.', 404);
        }
        return $run;
    }
}
