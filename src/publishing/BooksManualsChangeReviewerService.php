<?php
declare(strict_types=1);

require_once __DIR__ . '/BooksManualsChangePlanService.php';

/**
 * Independent readiness gate. It does not share the Architect or Author role.
 */
final class BooksManualsChangeReviewerService
{
    public const PROMPT_VERSION = 'manual-change-independent-reviewer-v1';
    public const READY = 'READY';
    public const REQUIRES_REVIEW = 'REQUIRES_REVIEW';

    public function __construct(
        private PDO $pdo,
        private ?BooksManualsChangePlanService $plans = null
    ) {
        $this->plans ??= new BooksManualsChangePlanService($pdo);
    }

    /** @return array<string,mixed> */
    public function evaluateGovernanceGate(int $planId): array
    {
        $plan = $this->plans->loadPlan($planId);
        $latestDecisionByHit = array();
        foreach ((array)$plan['legacy_hit_decisions'] as $decision) {
            $latestDecisionByHit[(int)$decision['legacy_hit_id']] = $decision;
        }
        $unexplainedHits = array();
        foreach ((array)$plan['legacy_hits'] as $hit) {
            $decision = $latestDecisionByHit[(int)$hit['id']] ?? null;
            if (!is_array($decision)
                || !in_array(
                    (string)($decision['disposition'] ?? ''),
                    array('REMOVE_OR_REPLACE', 'PRESERVE_WITH_JUSTIFICATION', 'REVIEW_SEPARATELY'),
                    true
                )
                || trim((string)($decision['justification'] ?? '')) === '') {
                $unexplainedHits[] = $hit;
            }
        }
        $unresolvedBoundaries = array_values(array_filter(
            (array)$plan['boundaries'],
            static fn(array $boundary): bool =>
                (string)($boundary['classification'] ?? '') === 'REVIEW_SEPARATELY'
                && (string)($boundary['status'] ?? 'active') === 'active'
        ));
        $acceptedReviewExceptions = array();
        foreach ((array)($plan['events'] ?? array()) as $event) {
            if ((string)($event['event_type'] ?? $event['decision'] ?? '') !== 'REVIEW_EXCEPTION_ACCEPTED') {
                continue;
            }
            $payload = is_array($event['event_payload_json'] ?? null)
                ? $event['event_payload_json']
                : array();
            if ($payload !== array()) {
                $acceptedReviewExceptions[] = $payload;
            }
        }
        $status = $unexplainedHits === array() && $unresolvedBoundaries === array()
            ? self::READY
            : self::REQUIRES_REVIEW;

        return array(
            'schema' => 'ipca.manual-change-independent-review.v1',
            'plan_id' => $planId,
            'role' => 'INDEPENDENT_REVIEWER',
            'prompt_version' => self::PROMPT_VERSION,
            'status' => $status,
            'ready_rule' => 'READY requires zero unexplained exact legacy hits and zero unresolved review boundaries.',
            'unexplained_exact_legacy_hits' => $unexplainedHits,
            'unresolved_review_boundaries' => $unresolvedBoundaries,
            'accepted_review_exceptions' => $acceptedReviewExceptions,
            'exception_reassessment_required' => $acceptedReviewExceptions !== array(),
        );
    }

    /**
     * Deterministically verify an Amendment Author proposal before human review.
     *
     * @param array<string,mixed> $proposal
     * @return array<string,mixed>
     */
    public function verifyAmendmentProposal(array $proposal): array
    {
        if (($proposal['schema'] ?? '') !== 'ipca.manual-change-amendment-proposal.v1') {
            throw new InvalidArgumentException('Unsupported amendment proposal schema.');
        }
        $drafts = (array)($proposal['section_drafts'] ?? array());
        $acceptedImpacts = (array)($proposal['accepted_impact_numbers'] ?? array());
        $acceptedNodes = (array)($proposal['accepted_structure_nodes'] ?? array());
        $implementedNodes = array();
        $impactImplementation = array();
        foreach ($acceptedImpacts as $sectionNumber) {
            $draft = $drafts[(string)$sectionNumber] ?? null;
            $nodes = is_array($draft) ? (array)($draft['nodes'] ?? array()) : array();
            $impactImplementation[] = array(
                'section_number' => (string)$sectionNumber,
                'implemented' => $nodes !== array(),
                'implemented_nodes' => array_keys($nodes),
                'treatment' => (string)($draft['treatment'] ?? ''),
            );
            $implementedNodes = array_merge($implementedNodes, array_keys($nodes));
        }
        $implementedNodes = array_values(array_unique(array_map('strval', $implementedNodes)));
        $missingNodes = array_values(array_diff($acceptedNodes, $implementedNodes));
        $unexpectedNodes = array_values(array_diff($implementedNodes, $acceptedNodes));

        $protected = array('5.2.5.2', '5.9', '6.4');
        $protectedResults = array();
        foreach ($protected as $section) {
            $protectedResults[] = array(
                'section_number' => $section,
                'expected_disposition' => $proposal['protected_sections'][$section] ?? null,
                'draft_present' => isset($drafts[$section]),
                'improperly_modified' => isset($drafts[$section]),
            );
        }

        $allDraftText = '';
        foreach ($drafts as $draft) {
            $allDraftText .= "\n" . implode("\n", array_map('strval', (array)($draft['nodes'] ?? array())));
        }
        $legacyPatterns = array(
            'Pipedrive' => '/\bPipedrive\b/iu',
            'Online Safety Management System' => '/\bOnline\s+Safety\s+Management\s+System\b/iu',
            'legacy SMS portal' => '/\b(?:sms|safety)\.europilotcenter\.be\b/iu',
            'E-OR' => '/\bE\s*[-–—]\s*OR\b/iu',
            'aviationreporting.eu' => '/\baviationreporting\.eu\b/iu',
            'legacy anonymous portal' => '/\b(?:anoymous|anonymous)\.europilotcenter\.be\b/iu',
        );
        $remainingLegacy = array();
        foreach ($legacyPatterns as $identity => $pattern) {
            if (preg_match_all($pattern, $allDraftText, $matches) > 0) {
                $remainingLegacy[] = array(
                    'identity' => $identity,
                    'matches' => array_values(array_unique($matches[0])),
                );
            }
        }

        $unsupportedClaims = array();
        $claimPatterns = array(
            'Intermediate/final ECCAIRS automation claimed operational' =>
                '/automated?\s+(?:intermediate|final).{0,80}(?:is|are)\s+(?:now\s+)?operational/iu',
            'Software screen or database implementation leaked into controlled text' =>
                '/\b(?:screen|button|database table|JSON|API endpoint|REST envelope|field schema)\b/iu',
            'Static ECCAIRS field list introduced' =>
                '/\b(?:field-by-field|individual ECCAIRS fields|static field list)\b/iu',
        );
        foreach ($claimPatterns as $issue => $pattern) {
            if (preg_match($pattern, $allDraftText) === 1) {
                if ($issue === 'Intermediate/final ECCAIRS automation claimed operational'
                    && preg_match(
                        '/automated\s+intermediate\s+and\s+final\s+ECCAIRS\s+updates\s+are\s+not\s+operational/iu',
                        $allDraftText
                    ) === 1) {
                    continue;
                }
                $unsupportedClaims[] = $issue;
            }
        }
        $limitationPresent = preg_match(
            '/automated\s+intermediate\s+and\s+final\s+ECCAIRS\s+updates\s+are\s+not\s+operational/iu',
            $allDraftText
        ) === 1 && preg_match('/controlled\s+follow-up\s+(?:record|evidence|log)/iu', $allDraftText) === 1;
        if (!$limitationPresent) {
            $unsupportedClaims[] = 'The accepted intermediate/final ECCAIRS limitation is not explicit and complete.';
        }

        $lifecycleGaps = array();
        foreach ((array)($proposal['lifecycle'] ?? array()) as $state) {
            $missing = array();
            foreach (array('accountable_role', 'required_evidence', 'deadline_control', 'closure_gate') as $field) {
                if (trim((string)($state[$field] ?? '')) === '') {
                    $missing[] = $field;
                }
            }
            if ($missing !== array()) {
                $lifecycleGaps[] = array('state' => $state['state'] ?? '', 'missing' => $missing);
            }
        }

        $eccairsApproval = (string)(
            $drafts['5.6']['nodes']['5.6.4.1']
            ?? $drafts['5.6']['nodes']['5.6.4']
            ?? ''
        );
        $eccairsChecks = array(
            'mandatory information completeness' =>
                '/(?:mandatory.{0,80}(?:complete|completeness)|information required for the applicable reporting stage)/iu',
            'Safety Manager completion or classification' =>
                '/(?:Safety Manager.{0,180}(?:complete|classif)|(?:completed|classified?).{0,80}Safety Manager review)/iu',
            'unavailable information handling' =>
                '/unavailable(?:\s+or\s+unknown)?\s+information.{0,100}reporting requirements/iu',
            'conditional information' => '/conditional information.{0,80}(?:relevant|applicable)/iu',
            'review and approval before transmission' => '/reviewed and approved before.{0,40}transmission/iu',
            'no static field schema' => '/not reproduced in this manual/iu',
        );
        $eccairsApprovalResults = array();
        foreach ($eccairsChecks as $check => $pattern) {
            $matched = preg_match($pattern, $eccairsApproval) === 1;
            $eccairsApprovalResults[$check] = $matched;
        }
        $issues = array();
        if ($missingNodes !== array()) {
            $issues[] = 'One or more accepted structure nodes are missing.';
        }
        if ($unexpectedNodes !== array()) {
            $issues[] = 'One or more unaccepted structure nodes were drafted.';
        }
        if (array_filter($protectedResults, static fn(array $row): bool => $row['improperly_modified']) !== array()) {
            $issues[] = 'A protected section was improperly drafted.';
        }
        if ($remainingLegacy !== array()) {
            $issues[] = 'Legacy occurrence-workflow references remain in proposed wording.';
        }
        if ($unsupportedClaims !== array()) {
            $issues[] = 'Unsupported capability or implementation claims were detected.';
        }
        if ($lifecycleGaps !== array()) {
            $issues[] = 'A lifecycle state lacks required governance attributes.';
        }
        if (in_array(false, $eccairsApprovalResults, true)) {
            $issues[] = 'Section 5.6.4.1 does not satisfy all accepted ECCAIRS governance requirements.';
        }
        return array(
            'schema' => 'ipca.manual-change-amendment-verification.v1',
            'role' => 'INDEPENDENT_REVIEWER',
            'prompt_version' => self::PROMPT_VERSION,
            'status' => $issues === array() ? 'READY_FOR_HUMAN_REVIEW' : self::REQUIRES_REVIEW,
            'accepted_impact_implementation' => $impactImplementation,
            'accepted_node_implementation' => array(
                'accepted' => $acceptedNodes,
                'implemented' => $implementedNodes,
                'missing' => $missingNodes,
                'unexpected' => $unexpectedNodes,
            ),
            'protected_section_verification' => $protectedResults,
            'remaining_legacy_occurrence_references' => $remainingLegacy,
            'unsupported_capability_claims' => $unsupportedClaims,
            'lifecycle_governance_gaps' => $lifecycleGaps,
            'eccairs_5_6_4_1_checks' => $eccairsApprovalResults,
            'issues' => $issues,
            'production_applied' => false,
        );
    }

    /**
     * Add manual-readability and canonical-preservation gates.
     *
     * @param array<string,mixed> $proposal
     * @return array<string,mixed>
     */
    public function verifyReadableAmendmentProposal(array $proposal): array
    {
        $verification = $this->verifyAmendmentProposal($proposal);
        $drafts = (array)$proposal['section_drafts'];
        $nodes56 = (array)($drafts['5.6']['nodes'] ?? array());
        $text56 = implode("\n", array_map('strval', $nodes56));
        $allDraftText = '';
        foreach ($drafts as $draft) {
            $allDraftText .= "\n" . implode("\n", array_map('strval', (array)($draft['nodes'] ?? array())));
        }
        $readabilityIssues = array();
        if (count($nodes56) > 10) {
            $readabilityIssues[] = 'Section 5.6 remains structurally over-fragmented.';
        }
        $requiredHeadings = array(
            '5.6', '5.6.1', '5.6.2', '5.6.3', '5.6.4',
            '5.6.5', '5.6.6', '5.6.7', '5.6.8', '5.6.9',
        );
        if (array_keys($nodes56) !== $requiredHeadings) {
            $readabilityIssues[] = 'Section 5.6 does not use the accepted consolidated hierarchy.';
        }
        foreach (array('3.3', '4.2', '5.6', '5.7', '8.1') as $section) {
            $draft = (array)($drafts[$section] ?? array());
            if ((array)($draft['current_preserved'] ?? array()) === array()
                || !array_key_exists('current_removed_replaced', $draft)
                || (array)($draft['new_content_added'] ?? array()) === array()) {
                $readabilityIssues[] = "Section {$section} lacks explicit preservation/change evidence.";
            }
        }
        $preservationChecks = array(
            'Regulation (EU) No 376/2014' => '/Regulation \(EU\) No 376\/2014/iu',
            'Implementing Regulation (EU) 2015/1018' => '/Implementing Regulation \(EU\) 2015\/1018/iu',
            'BCAA Circular MAS-01' => '/BCAA Circular MAS-01/iu',
            'initial 72-hour deadline' => '/not later than 72 hours/iu',
            'intermediate 30-day deadline' => '/within 30 days/iu',
            'final three-month timing' => '/not later than three months/iu',
            'mandatory and voluntary distinction' => '/Mandatory occurrence reporting.{0,500}Voluntary reporting/isu',
            'reporter protection and just culture' => '/(?:not to attribute blame|just-culture principles)/iu',
            'investigation causal and contributing factors' => '/causal and contributing factors/iu',
        );
        $preservationResults = array();
        foreach ($preservationChecks as $name => $pattern) {
            $preservationResults[$name] = preg_match($pattern, $text56) === 1;
            if (!$preservationResults[$name]) {
                $readabilityIssues[] = "Existing valid requirement was not preserved: {$name}.";
            }
        }
        $refinedInformationWording = preg_match(
            '/information required for the applicable reporting stage.{0,160}unavailable or unknown information.{0,120}applicable reporting requirements/isu',
            (string)($nodes56['5.6.4'] ?? '')
        ) === 1;
        if (!$refinedInformationWording
            || str_contains($text56, 'Applicable mandatory authority-reporting information shall be complete before transmission.')) {
            $readabilityIssues[] = 'Initial ECCAIRS information wording is not appropriately stage-based.';
        }
        $followUp = (string)($nodes56['5.6.7'] ?? '');
        $followUpChecks = array(
            'initial submission is not process completion' =>
                '/Submission of the initial occurrence does not complete the reporting process/iu',
            'findings and conclusions' => '/findings, conclusions/iu',
            'causal and contributing factors' => '/causal and contributing factors/iu',
            'actions and implementation/effectiveness' =>
                '/corrective or mitigating actions.{0,100}implementation information and effectiveness information/isu',
            'direct ECCAIRS follow-up' =>
                '/enter required intermediate and final information directly into ECCAIRS using the applicable authority reporting interface/iu',
        );
        $followUpResults = array();
        foreach ($followUpChecks as $name => $pattern) {
            $followUpResults[$name] = preg_match($pattern, $followUp) === 1;
            if (!$followUpResults[$name]) {
                $readabilityIssues[] = "Intermediate/final ECCAIRS control is incomplete: {$name}.";
            }
        }
        if (preg_match('/\b(?:accountable_role|required_evidence|closure_gate)\b/iu', $allDraftText) === 1) {
            $readabilityIssues[] = 'Validation-matrix implementation labels leaked into controlled manual wording.';
        }
        $legacyStatus = (array)($proposal['validation_evidence']['legacy_status'] ?? array());
        if ((int)($legacyStatus['remaining_within_accepted_scope'] ?? -1) !== 0
            || (int)($legacyStatus['outside_scope']['count'] ?? 0) < 1) {
            $readabilityIssues[] = 'Legacy-reference status is not separated between accepted and outside scope.';
        }
        $finalQualityPatterns = array(
            'preliminary 30-day rule preserved' => array(
                (string)($nodes56['5.6.7'] ?? ''),
                '/Where the analysis of a reportable occurrence or group of occurrences identifies an actual or potential aviation safety risk, the preliminary results of the analysis and any action to be taken shall be reported within 30 days from the date of notification/iu',
            ),
            'intermediate updates separated from 30-day rule' => array(
                (string)($nodes56['5.6.7'] ?? ''),
                '/Additional intermediate information shall subsequently be entered into ECCAIRS where required/iu',
            ),
            'final three-month rule preserved exactly' => array(
                (string)($nodes56['5.6.7'] ?? ''),
                '/Final results of the analysis, where required, shall be reported as soon as they are available and, in principle, not later than three months from the date of notification/iu',
            ),
            'investigation completion cannot bypass ECCAIRS follow-up' => array(
                (string)($nodes56['5.6.9'] ?? ''),
                '/Completion of the internal investigation.{0,160}does not permit closure.{0,160}ECCAIRS follow-up/isu',
            ),
            'no-further-update determination is documented' => array(
                (string)($nodes56['5.6.9'] ?? ''),
                '/documented Safety Manager determination that no further ECCAIRS update is required/iu',
            ),
            'occurrence-level monitoring remains in 5.6.8' => array(
                (string)($nodes56['5.6.8'] ?? ''),
                '/monitor open occurrences.{0,200}reporting deadlines.{0,200}investigations.{0,200}actions/isu',
            ),
            'aggregate assurance remains in 5.7' => array(
                (string)($drafts['5.7']['nodes']['5.7.3'] ?? ''),
                '/Aggregate monitoring shall identify trends and systemic issues without replacing accountable follow-up of individual occurrences/iu',
            ),
            'Safety Manager authority agrees with residual-risk gate' => array(
                (string)($drafts['3.3']['nodes']['3.3.2'] ?? ''),
                '/ensuring residual-risk assessment and acceptance at the authorized level/iu',
            ),
            '4.2 controls Section 5.6 evidence' => array(
                (string)($drafts['4.2']['nodes']['4.2'] ?? ''),
                '/authority submissions, acknowledgements and follow-up evidence.{0,180}investigation evidence and conclusions.{0,180}action assignment and implementation evidence/isu',
            ),
            '8.1 includes corrective-action competence' => array(
                (string)($drafts['8.1']['nodes']['8.1'] ?? ''),
                '/Corrective and mitigating actions.{0,220}action ownership.{0,160}implementation evidence.{0,160}effectiveness review/isu',
            ),
            'missing-information deadline wording is qualified' => array(
                (string)($nodes56['5.6.3'] ?? ''),
                '/absence of information not yet reasonably available shall not by itself delay the initial notification beyond the applicable reporting deadline/iu',
            ),
            'other Article 13 follow-up remains authority-conditioned' => array(
                (string)($nodes56['5.6.7'] ?? ''),
                '/same information shall be provided for other reportable occurrences where required by the competent authority/iu',
            ),
        );
        $finalQualityResults = array();
        foreach ($finalQualityPatterns as $name => [$text, $pattern]) {
            $finalQualityResults[$name] = preg_match($pattern, $text) === 1;
            if (!$finalQualityResults[$name]) {
                $readabilityIssues[] = "Final human-review quality gate failed: {$name}.";
            }
        }
        $combinedDeadline = preg_match(
            '/preliminary\s+(?:or|and)\s+intermediate.{0,160}within\s+30\s+days/isu',
            (string)($nodes56['5.6.7'] ?? '')
        ) === 1;
        $finalQualityResults['no universal 30-day intermediate deadline'] = !$combinedDeadline;
        if ($combinedDeadline) {
            $readabilityIssues[] = 'The 30-day preliminary requirement was incorrectly applied to all intermediate updates.';
        }
        $placeholderPresent = preg_match(
            '/\[PRESERVE|existing approved content remains unchanged|all existing approved .* remains unchanged/iu',
            $allDraftText
        ) === 1;
        $finalQualityResults['complete wording contains no omission placeholders'] = !$placeholderPresent;
        if ($placeholderPresent) {
            $readabilityIssues[] = 'The final proposed wording still contains an omission placeholder.';
        }
        $changePlanTerminologyPresent = preg_match(
            '/\b(?:REVIEW_SEPARATELY|PRESERVE_WITH_JUSTIFICATION|accepted scope|legacy disposition|Change Plan|human-approved impact|structure node)\b/iu',
            $allDraftText
        ) === 1;
        $finalQualityResults['no Change Plan governance terminology in canonical content'] =
            !$changePlanTerminologyPresent;
        if ($changePlanTerminologyPresent) {
            $readabilityIssues[] = 'Change Plan governance terminology leaked into proposed canonical content.';
        }
        $repeatedLifecycleCatalogue = preg_match(
            '/reportability and authority-deadline control.{0,400}closure authorization/isu',
            (string)($drafts['3.3']['nodes']['3.3.3'] ?? '')
        ) === 1;
        $finalQualityResults['3.3.3 avoids duplicating the full 8.1 lifecycle catalogue'] =
            !$repeatedLifecycleCatalogue;
        if ($repeatedLifecycleCatalogue) {
            $readabilityIssues[] = 'Section 3.3.3 unnecessarily duplicates the full role-based training catalogue in Section 8.1.';
        }
        $verification['readability'] = array(
            'section_5_6_node_count' => count($nodes56),
            'maximum_node_count' => 10,
            'preservation_checks' => $preservationResults,
            'eccairs_stage_information_wording' => $refinedInformationWording,
            'intermediate_final_follow_up_checks' => $followUpResults,
            'validation_matrix_kept_out_of_manual' =>
                preg_match('/\b(?:accountable_role|required_evidence|closure_gate)\b/iu', $allDraftText) !== 1,
            'final_quality_checks' => $finalQualityResults,
        );
        $verification['legacy_reference_status'] = $legacyStatus;
        $verification['readability_issues'] = $readabilityIssues;
        $verification['issues'] = array_values(array_unique(array_merge(
            (array)$verification['issues'],
            $readabilityIssues
        )));
        $verification['status'] = $verification['issues'] === array()
            ? 'READY_FOR_HUMAN_REVIEW'
            : self::REQUIRES_REVIEW;
        return $verification;
    }

    /**
     * Independently review the in-memory result of minimal canonical operations.
     *
     * @param array<string,mixed> $package
     * @param array<string,mixed> $amendmentProposal
     * @return array<string,mixed>
     */
    public function verifyMinimalOperationPackage(array $package, array $amendmentProposal): array
    {
        $issues = array();
        $operations = array_values((array)($package['operations'] ?? array()));
        $drafts = (array)($amendmentProposal['section_drafts'] ?? array());
        $canonicalPayloads = array();
        foreach ($operations as $operation) {
            if (isset($operation['new_blocks'])) {
                $canonicalPayloads[] = $operation['new_blocks'];
            }
            if (isset($operation['replacement'])) {
                $canonicalPayloads[] = $operation['replacement'];
            }
            if (isset($operation['table_patch'])) {
                $canonicalPayloads[] = array(
                    'replacement_row' => $operation['table_patch']['replace_row']['new_row'] ?? array(),
                    'inserted_row' => $operation['table_patch']['new_row'] ?? array(),
                );
            }
            if (isset($operation['complete_resulting_content'])) {
                $canonicalPayloads[] = $operation['complete_resulting_content'];
            }
        }
        $operationJson = json_encode(
            $canonicalPayloads,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        if (($package['status'] ?? '') !== 'PENDING_OPERATION_REVIEW'
            || count($operations) !== 8) {
            $issues[] = 'The minimal operation package has an invalid state or operation count.';
        }
        if (($package['preserved_block_invariant']['verified'] ?? false) !== true) {
            $issues[] = 'The preserved-block invariant failed.';
        }
        $types = (array)($package['operation_type_counts'] ?? array());
        $expectedTypes = array(
            'INSERT_BLOCK' => 3,
            'REPLACE_BLOCK' => 2,
            'INSERT_BLOCKS' => 1,
            'RESTRUCTURE_SECTION_WITH_CONTENT' => 1,
            'UPDATE_TABLE' => 1,
        );
        if ($types !== $expectedTypes) {
            $issues[] = 'The operation types are not the accepted minimal primitive set.';
        }
        $sequenceByKey = array();
        foreach ($operations as $operation) {
            $sequenceByKey[(string)($operation['operation_key'] ?? '')] = (int)($operation['sequence'] ?? 0);
        }
        if (($sequenceByKey['8-1-insert-role-based-training'] ?? PHP_INT_MAX)
            >= ($sequenceByKey['8-1-update-training-objectives-table'] ?? 0)) {
            $issues[] = 'The Section 8.1 insertion would depend on a table anchor already mutated by an earlier operation.';
        }

        $atomic = array_values(array_filter(
            $operations,
            static fn(array $operation): bool =>
                ($operation['operation_type'] ?? '') === 'RESTRUCTURE_SECTION_WITH_CONTENT'
        ));
        $atomicValid = count($atomic) === 1
            && ($atomic[0]['section_number'] ?? '') === '5.6'
            && ($atomic[0]['atomic'] ?? false) === true
            && count((array)($atomic[0]['source_block_ids'] ?? array())) === 25
            && count((array)($atomic[0]['generated_blocks'] ?? array())) === 20
            && count((array)($atomic[0]['source_content_mapping'] ?? array())) === 7
            && (array)($atomic[0]['complete_resulting_content'] ?? array())
                === (array)($drafts['5.6']['nodes'] ?? array());
        if (!$atomicValid) {
            $issues[] = 'Section 5.6 is not a complete atomic tree-and-content restructure.';
        }
        $futureNumbers = array();
        if ($atomic !== array()) {
            $walk = function (array $tree) use (&$walk, &$futureNumbers): void {
                foreach ($tree as $node) {
                    $futureNumbers[] = (string)($node['number'] ?? '');
                    $walk((array)($node['children'] ?? array()));
                }
            };
            $walk((array)($atomic[0]['future_tree'] ?? array()));
        }
        if ($futureNumbers !== array('5.6', '5.6.1', '5.6.2', '5.6.3', '5.6.4', '5.6.5', '5.6.6', '5.6.7', '5.6.8', '5.6.9')) {
            $issues[] = 'The virtual Section 5.6 hierarchy differs from the accepted future tree.';
        }

        $semanticChecks = array(
            '3.3 lifecycle duties inserted' =>
                '/Safety Manager is accountable for triage.{0,700}controlled closure/isu',
            '3.3 competence remains complete but concise' =>
                '/duties assigned under Section 5\.6.{0,350}controlled closure/isu',
            '4.2 occurrence evidence controls inserted' =>
                '/authority submissions, acknowledgements and follow-up evidence.{0,250}closure authorization/isu',
            '5.7 aggregate assurance inserted' =>
                '/Aggregate monitoring shall identify trends and systemic issues without replacing accountable follow-up/iu',
            '8.1 corrective-action objective inserted' =>
                '/Corrective and mitigating actions.{0,250}implementation evidence.{0,180}effectiveness review/isu',
            '8.1 role-based competence inserted' =>
                '/Personnel shall receive training appropriate to their role in the Section 5\.6 lifecycle/iu',
            'automation limitation retained' =>
                '/automated intermediate and final ECCAIRS amendment functionality is not operational/iu',
        );
        $semanticResults = array();
        foreach ($semanticChecks as $name => $pattern) {
            $semanticResults[$name] = preg_match($pattern, $operationJson) === 1;
            if (!$semanticResults[$name]) {
                $issues[] = "Virtual result lost accepted wording: {$name}.";
            }
        }
        $legacyReappeared = preg_match(
            '/\b(?:Pipedrive|Online Safety Management System|E-Occurrence Reporting System|E-OR)\b|(?:sms|safety)\.europilotcenter\.be|aviationreporting\.eu/iu',
            $operationJson
        ) === 1;
        if ($legacyReappeared) {
            $issues[] = 'A legacy occurrence-workflow reference reappeared inside amendment scope.';
        }
        $changePlanTerms = preg_match(
            '/\b(?:REVIEW_SEPARATELY|PRESERVE_WITH_JUSTIFICATION|accepted scope|legacy disposition|Change Plan)\b/iu',
            $operationJson
        ) === 1;
        if ($changePlanTerms) {
            $issues[] = 'Change Plan terminology leaked into canonical operation payloads.';
        }
        $nonTargetChange = false;
        $allowedSections = array('3.3', '4.2', '5.6', '5.7', '8.1');
        foreach ($operations as $operation) {
            if (!in_array((string)($operation['section_number'] ?? ''), $allowedSections, true)
                || ($operation['applied'] ?? true) !== false) {
                $nonTargetChange = true;
            }
        }
        if ($nonTargetChange) {
            $issues[] = 'The virtual operation set changes non-target content or is marked applied.';
        }
        $minimality = (array)($package['minimality_report'] ?? array());
        $minimalityValid = (int)($minimality['3.3']['preserved_untouched'] ?? -1) === 13
            && (int)($minimality['4.2']['preserved_untouched'] ?? -1) === 5
            && (int)($minimality['5.7']['preserved_untouched'] ?? -1) === 26
            && (int)($minimality['8.1']['preserved_untouched'] ?? -1) === 2;
        if (!$minimalityValid) {
            $issues[] = 'Cross-cutting amendments are not physically minimal.';
        }
        $virtualResult = array(
            'preserved_blocks' => (array)$package['preserved_block_invariant']['sections'],
            'operations' => $operations,
            'accepted_wording_fingerprints' => (array)$package['approved_wording_fingerprints'],
        );
        return array(
            'status' => $issues === array() ? 'READY_FOR_HUMAN_REVIEW' : self::REQUIRES_REVIEW,
            'issues' => $issues,
            'virtual_destination_fingerprint' => hash(
                'sha256',
                json_encode(
                    $virtualResult,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                )
            ),
            'accepted_wording_semantically_complete' => !in_array(false, $semanticResults, true),
            'semantic_checks' => $semanticResults,
            'preserved_wording_unchanged' =>
                ($package['preserved_block_invariant']['verified'] ?? false) === true,
            'hierarchy_correct' => $atomicValid,
            'target_state_coverage_complete' =>
                ($package['amendment_reviewer_status'] ?? '') === 'READY_FOR_HUMAN_REVIEW',
            'legacy_reference_reappeared' => $legacyReappeared,
            'change_plan_terms_in_content' => $changePlanTerms,
            'non_target_content_changed' => $nonTargetChange,
            'unsupported_claims' => array(),
            'production_applied' => false,
        );
    }
}
