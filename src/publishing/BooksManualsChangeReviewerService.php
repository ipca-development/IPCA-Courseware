<?php
declare(strict_types=1);

require_once __DIR__ . '/BooksManualsChangePlanService.php';

/**
 * Independent readiness gate. It does not share the Architect or Author role.
 */
final class BooksManualsChangeReviewerService
{
    public const PROMPT_VERSION = 'manual-change-independent-reviewer-v1';
    public const CHECK_VERSION = '3';
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
        $verification['legacy_regex_diagnostics'] = array(
            'readability' => $verification['readability'],
            'readability_issues' => $readabilityIssues,
            'issues' => array_values(array_unique(array_merge(
                (array)$verification['issues'],
                $readabilityIssues
            ))),
        );
        $reviewChecks = $this->buildStructuredReviewChecks($proposal, $verification);
        $verification['review_checks'] = $reviewChecks;
        $verification['readability'] = $this->structuredReadabilityProjection($reviewChecks, count($nodes56));
        $verification['readability_issues'] = array_values(array_map(
            static fn(array $check): string => (string)$check['human_explanation'],
            array_filter(
                $reviewChecks,
                static fn(array $check): bool => (string)$check['status'] === 'FAIL'
                    && !in_array((string)$check['category'], array('STRUCTURE', 'INTEGRITY'), true)
            )
        ));
        $verification['issues'] = array_values(array_map(
            static fn(array $check): string => (string)$check['human_explanation'],
            array_filter(
                $reviewChecks,
                static fn(array $check): bool => (string)$check['status'] === 'FAIL'
            )
        ));
        $verification['status'] = $verification['issues'] === array()
            ? 'READY_FOR_HUMAN_REVIEW'
            : self::REQUIRES_REVIEW;
        return $verification;
    }

    /**
     * Stable, semantic checks are the authoritative Independent Review result.
     * Regex-only results above are retained as diagnostics for old review rows.
     *
     * @param array<string,mixed> $proposal
     * @param array<string,mixed> $legacyVerification
     * @return list<array<string,mixed>>
     */
    private function buildStructuredReviewChecks(array $proposal, array $legacyVerification): array
    {
        $drafts = (array)($proposal['section_drafts'] ?? array());
        $nodes = array();
        $allText = '';
        foreach ($drafts as $section => $draft) {
            foreach ((array)($draft['nodes'] ?? array()) as $number => $content) {
                $nodes[(string)$number] = (string)$content;
                $allText .= "\n" . (string)$content;
            }
        }
        $text56 = implode("\n", array_map('strval', (array)($drafts['5.6']['nodes'] ?? array())));
        $followUp = (string)($nodes['5.6.7'] ?? '');
        $closure = (string)($nodes['5.6.9'] ?? '');
        $initial = (string)($nodes['5.6.4'] ?? $nodes['5.6.4.1'] ?? '');
        $checks = array();

        $acceptedNodes = array_values(array_unique(array_map(
            'strval',
            (array)($proposal['accepted_structure_nodes'] ?? array())
        )));
        $acceptedNodeSet = array_fill_keys($acceptedNodes, true);
        $acceptedImpactSet = array_fill_keys(array_values(array_unique(array_map(
            'strval',
            (array)($proposal['accepted_impact_numbers'] ?? array())
        ))), true);
        $implementedNodes = array_keys($nodes);
        $missingNodes = array_values(array_diff($acceptedNodes, $implementedNodes));
        $unexpectedNodes = array_values(array_diff($implementedNodes, $acceptedNodes));
        $checks[] = $this->reviewCheck(
            'structure.accepted-nodes.complete',
            'STRUCTURE',
            'HARD',
            $missingNodes === array(),
            array_keys($drafts),
            $missingNodes,
            'Every accepted structure node is implemented once.',
            $missingNodes === array() ? 'All accepted nodes are present.' : 'Missing nodes: ' . implode(', ', $missingNodes),
            'One or more accepted structure nodes are missing.',
            array_keys($drafts),
            array('accepted_structure_nodes')
        );
        $checks[] = $this->reviewCheck(
            'structure.unaccepted-nodes.absent',
            'STRUCTURE',
            'HARD',
            $unexpectedNodes === array(),
            array_keys($drafts),
            $unexpectedNodes,
            'No node outside the accepted structure is drafted.',
            $unexpectedNodes === array() ? 'No unaccepted nodes are present.' : 'Unexpected nodes: ' . implode(', ', $unexpectedNodes),
            'One or more unaccepted structure nodes were drafted.',
            array(),
            array('accepted_structure_nodes')
        );
        foreach ((array)($proposal['accepted_impact_numbers'] ?? array()) as $section) {
            $section = (string)$section;
            $implemented = (array)($drafts[$section]['nodes'] ?? array()) !== array();
            $checks[] = $this->reviewCheck(
                'impact.' . $this->checkSlug($section) . '.implemented',
                'SCOPE',
                'HARD',
                $implemented,
                array($section),
                array_keys((array)($drafts[$section]['nodes'] ?? array())),
                "The accepted amendment area {$section} is implemented.",
                $implemented ? "Section {$section} contains accepted-node wording." : "Section {$section} has no accepted-node wording.",
                "Accepted amendment area {$section} is not implemented.",
                array($section),
                array('accepted_impact_numbers')
            );
        }
        foreach (array('5.2.5.2', '5.9', '6.4') as $section) {
            $preserved = !isset($drafts[$section]);
            $checks[] = $this->reviewCheck(
                'integrity.protected-section.' . $this->checkSlug($section),
                'INTEGRITY',
                'HARD',
                $preserved,
                array($section),
                array($section),
                "Protected Section {$section} remains outside the amendment package.",
                $preserved ? 'The protected section is absent from proposed amendments.' : 'The protected section was drafted.',
                "Protected Section {$section} was improperly drafted.",
                array(),
                array('protected_sections')
            );
        }
        $legacyPatterns = array(
            'pipedrive' => '/\bPipedrive\b/iu',
            'online-sms' => '/\bOnline\s+Safety\s+Management\s+System\b/iu',
            'legacy-sms-portal' => '/\b(?:sms|safety)\.europilotcenter\.be\b/iu',
            'e-or' => '/\bE\s*[-–—]\s*OR\b/iu',
            'aviationreporting-eu' => '/\baviationreporting\.eu\b/iu',
            'legacy-anonymous-portal' => '/\b(?:anoymous|anonymous)\.europilotcenter\.be\b/iu',
        );
        foreach ($legacyPatterns as $identity => $pattern) {
            $absent = preg_match($pattern, $allText) !== 1;
            $checks[] = $this->reviewCheck(
                'integrity.legacy-reference.' . $identity . '.absent',
                'INTEGRITY',
                'HARD',
                $absent,
                array_keys($drafts),
                array_keys($nodes),
                "The obsolete {$identity} reference does not appear inside accepted amendment scope.",
                $absent ? 'No obsolete reference was found.' : 'The obsolete reference is still present.',
                'Legacy occurrence-workflow references remain in proposed wording.',
                array_keys($drafts),
                array('legacy_reference_scan')
            );
        }
        $checks[] = $this->reviewCheck(
            'integrity.unsupported-automation-claim.absent',
            'INTEGRITY',
            'HARD',
            preg_match('/automated?\s+(?:intermediate|final).{0,100}(?:is|are)\s+(?:now\s+)?operational/iu', $allText) !== 1
                || preg_match('/(?:until|while).{0,120}(?:automatic|automated).{0,120}operational|automated?.{0,120}not\s+operational/isu', $allText) === 1,
            array_keys($drafts),
            array_keys($nodes),
            'The amendment does not claim unsupported automated ECCAIRS follow-up capability.',
            'No affirmative unsupported automation claim was detected.',
            'Unsupported ECCAIRS automation was represented as operational.',
            array_keys($drafts),
            array('known_system_limitations')
        );
        $checks[] = $this->reviewCheck(
            'integrity.implementation-detail.absent',
            'INTEGRITY',
            'HARD',
            preg_match('/\b(?:screen|button|database table|JSON|API endpoint|REST envelope|field schema)\b/iu', $allText) !== 1,
            array_keys($drafts),
            array_keys($nodes),
            'Controlled wording contains no software implementation internals.',
            'No implementation-internal terminology was detected.',
            'Software implementation detail leaked into controlled wording.',
            array_keys($drafts),
            array('controlled_manual_boundary')
        );
        $checks[] = $this->reviewCheck(
            'integrity.static-eccairs-field-schema.absent',
            'INTEGRITY',
            'HARD',
            preg_match('/\b(?:field-by-field|individual ECCAIRS fields|static field list)\b/iu', $allText) !== 1,
            array('5.6'),
            array('5.6.4'),
            'The manual governs information controls without freezing an ECCAIRS field schema.',
            'No static ECCAIRS field schema was detected.',
            'A static ECCAIRS field schema was introduced.',
            array('5.6.4'),
            array('known_system_limitations')
        );
        foreach ((array)($proposal['lifecycle'] ?? array()) as $state) {
            if (!is_array($state)) {
                continue;
            }
            $name = (string)($state['state'] ?? 'unknown');
            $missing = array();
            foreach (array('accountable_role', 'required_evidence', 'deadline_control', 'closure_gate') as $field) {
                if (trim((string)($state[$field] ?? '')) === '') {
                    $missing[] = $field;
                }
            }
            $checks[] = $this->reviewCheck(
                'lifecycle.' . $this->checkSlug($name) . '.governed',
                'INTEGRITY',
                'HARD',
                $missing === array(),
                array_keys($drafts),
                array(),
                "Lifecycle state {$name} defines role, evidence, deadline and closure controls.",
                $missing === array() ? 'All lifecycle governance attributes are present.' : 'Missing: ' . implode(', ', $missing),
                "Lifecycle state {$name} lacks required governance attributes.",
                array_keys($drafts),
                array('accepted_lifecycle')
            );
        }

        $requiredHeadings = array(
            '5.6', '5.6.1', '5.6.2', '5.6.3', '5.6.4',
            '5.6.5', '5.6.6', '5.6.7', '5.6.8', '5.6.9',
        );
        $checks[] = $this->reviewCheck(
            'structure.5-6.accepted-hierarchy',
            'STRUCTURE',
            'HARD',
            array_keys((array)($drafts['5.6']['nodes'] ?? array())) === $requiredHeadings,
            array('5.6'),
            $requiredHeadings,
            'Section 5.6 uses the accepted consolidated hierarchy without additions or omissions.',
            'Compared the resulting 5.6 node sequence with the accepted structure.',
            'Section 5.6 does not use the accepted consolidated hierarchy.',
            array(),
            array('accepted_structure_nodes')
        );
        foreach (array('3.3', '4.2', '5.6', '5.7', '8.1') as $section) {
            $checkId = 'evidence.section.' . $this->checkSlug($section) . '.change-accounting';
            if (!isset($acceptedImpactSet[$section])) {
                $checks[] = $this->informationalReviewCheck(
                    $checkId,
                    'INTEGRITY',
                    array($section),
                    array(),
                    "Section {$section} records preserved, replaced and added content when it is within the accepted amendment scope.",
                    "Section {$section} is outside the human-accepted amendment scope.",
                    array('accepted_impact_numbers', 'accepted_structure_nodes')
                );
                continue;
            }
            $draft = (array)($drafts[$section] ?? array());
            $complete = (array)($draft['current_preserved'] ?? array()) !== array()
                && array_key_exists('current_removed_replaced', $draft)
                && (array)($draft['new_content_added'] ?? array()) !== array();
            $checks[] = $this->reviewCheck(
                $checkId,
                'INTEGRITY',
                'HARD',
                $complete,
                array($section),
                array_keys((array)($draft['nodes'] ?? array())),
                "Section {$section} records preserved, replaced and added content.",
                $complete ? 'Change-accounting evidence is complete.' : 'Change-accounting evidence is incomplete.',
                "Section {$section} lacks explicit preservation/change evidence.",
                array($section),
                array('draft_change_evidence')
            );
        }

        $preservationChecks = array(
            'regulation-376-2014' => array('Regulation (EU) No 376/2014', array('/Regulation\s*\(EU\)\s*No\s*376\/2014/iu')),
            'regulation-2015-1018' => array('Implementing Regulation (EU) 2015/1018', array('/Implementing Regulation\s*\(EU\)\s*2015\/1018/iu')),
            'bcaa-mas-01' => array('BCAA Circular MAS-01', array('/BCAA\s+Circular\s+MAS-01/iu')),
            'initial-72-hour-deadline' => array('the initial 72-hour deadline', array('/(?:not later than|within)\s+72\s+hours/iu')),
            'preliminary-30-day-deadline' => array('the preliminary 30-day deadline', array('/(?:within|not later than)\s+30\s+days/iu')),
            'final-three-month-timing' => array('the final three-month timing', array('/(?:not later than|within)\s+three\s+months/iu')),
            'mandatory-voluntary-distinction' => array('the mandatory and voluntary reporting distinction', array('/mandatory(?:\s+occurrence)?[-\s]reporting/iu', '/voluntary(?:\s+occurrence)?[-\s]reporting/iu')),
            'reporter-protection-just-culture' => array('reporter protection and just culture', array('/(?:reporter\s+protection|protect(?:s|ed|ing)?\s+(?:the\s+)?reporter|reporter.{0,30}protect)/iu', '/just[-\s]culture/iu')),
            'causal-contributing-factors' => array('causal and contributing factors', array('/causal\s+(?:and|or)\s+contributing\s+factors/iu')),
        );
        foreach ($preservationChecks as $key => [$label, $patterns]) {
            $passed = $this->matchesEvery($text56, $patterns);
            $checks[] = $this->reviewCheck(
                'preservation.' . $key,
                'PRESERVATION',
                'MATERIAL',
                $passed,
                array('5.6'),
                array_keys((array)($drafts['5.6']['nodes'] ?? array())),
                "The resulting procedure preserves {$label}.",
                $passed ? "The {$label} control is present." : "The {$label} control was not found.",
                "Existing valid requirement was not preserved: {$label}.",
                array_keys((array)($drafts['5.6']['nodes'] ?? array())),
                array('accepted_impact_baseline', 'canonical_source')
            );
        }

        $semantic = array(
            'eccairs.initial.stage-information' => array(
                'ECCAIRS_INITIAL', 'MATERIAL', $initial . "\n" . (string)($nodes['5.6.3'] ?? ''), array('5.6.4'), array('5.6.3', '5.6.4'),
                array('/(?:initial|applicable)\s+reporting\s+stage/iu', '/(?:unknown|unavailable|not-yet-confirmed)/iu', '/(?:shall not.{0,30}delay|without\s+delay)/isu', '/(?:applicable|reporting)\s+deadline/iu'),
                'Initial ECCAIRS information is governed by the applicable stage and unavailable information does not delay the deadline.',
                'Initial ECCAIRS information wording is not appropriately stage-based.'
            ),
            'eccairs.initial.governance-complete' => array(
                'ECCAIRS_INITIAL', 'MATERIAL', $initial, array('5.6.4'), array('5.6.4'),
                array('/Safety Manager/iu', '/(?:information required|required information)/iu', '/(?:unknown|unavailable)/iu', '/review.{0,60}approv|approv.{0,60}transmission/isu', '/(?:retain.{0,240}(?:submission|authority|evidence)|(?:submission|authority|evidence).{0,240}retain)/isu'),
                'Initial ECCAIRS preparation covers required information, unavailable information, Safety Manager approval and retained evidence.',
                'Initial ECCAIRS governance requirements are incomplete.'
            ),
            'eccairs.follow-up.initial-not-completion' => array(
                'ECCAIRS_FOLLOW_UP', 'MATERIAL', $text56, array('5.6'), array('5.6.3', '5.6.7'),
                array('/(?:submission|submitting).{0,60}(?:initial\s+(?:occurrence|report)).{0,100}(?:does not|shall not|cannot).{0,60}(?:complete|completion)/isu'),
                'Initial occurrence submission is explicitly not treated as completion of authority reporting.',
                'Intermediate/final ECCAIRS control is incomplete: initial submission is not process completion.'
            ),
            'eccairs.follow-up.findings-conclusions' => array(
                'INVESTIGATION', 'MATERIAL', $followUp, array('5.6'), array('5.6.7'),
                array('/findings/iu', '/conclusions/iu'),
                'Required follow-up can include both findings and conclusions.',
                'Intermediate/final ECCAIRS control is incomplete: findings and conclusions.'
            ),
            'eccairs.follow-up.causal-contributing' => array(
                'INVESTIGATION', 'MATERIAL', $followUp, array('5.6'), array('5.6.7'),
                array('/causal(?:\s+and\s+contributing)?\s+(?:factors?|causes?)/iu', '/contributing\s+factors?/iu'),
                'Required follow-up can include causal and contributing factors.',
                'Intermediate/final ECCAIRS control is incomplete: causal and contributing factors.'
            ),
            'eccairs.follow-up.actions-evidence-effectiveness' => array(
                'CORRECTIVE_ACTION', 'MATERIAL', $followUp, array('5.6'), array('5.6.7'),
                array('/corrective\s+or\s+mitigating\s+actions?/iu', '/implementation\s+(?:information|evidence)/iu', '/effectiveness\s+(?:information|evidence|review)/iu'),
                'Required follow-up can include actions, implementation evidence and effectiveness information.',
                'Intermediate/final ECCAIRS control is incomplete: actions and implementation/effectiveness.'
            ),
            'eccairs.follow-up.approved-authority-process' => array(
                'ECCAIRS_FOLLOW_UP', 'MATERIAL', $followUp, array('5.6'), array('5.6.7'),
                array('/(?:enter(?:ed|ing)?|performed)\s+(?:required\s+[^.]{0,80})?directly\s+in(?:to)?\s+ECCAIRS/iu', '/(?:competent authority|authority reporting|Article 13|applicable authority)/iu', '/Safety Manager/iu'),
                'Required intermediate and final updates use the applicable authority process under Safety Manager control.',
                'Intermediate/final ECCAIRS control is incomplete: approved authority follow-up process.'
            ),
            'eccairs.follow-up.preliminary-30-day-risk-trigger' => array(
                'ECCAIRS_FOLLOW_UP', 'MATERIAL', (string)($nodes['5.6.3'] ?? '') . "\n" . $followUp, array('5.6'), array('5.6.3', '5.6.7'),
                array('/actual\s+or\s+potential\s+(?:aviation\s+)?safety\s+risk/iu', '/preliminary\s+results?/iu', '/(?:within|not later than)\s+30\s+days/iu'),
                'A preliminary report is required within 30 days when analysis identifies actual or potential aviation safety risk.',
                'The preliminary 30-day reporting control does not retain its substantive risk trigger.'
            ),
            'eccairs.follow-up.intermediate-separated' => array(
                'ECCAIRS_FOLLOW_UP', 'MATERIAL', $text56, array('5.6'), array('5.6.3', '5.6.7'),
                array('/additional\s+intermediate\s+information/iu', '/preliminary\s+(?:results?|reporting)|30-day\s+(?:preliminary|reporting)/iu'),
                'Other intermediate updates remain distinct from the preliminary 30-day report.',
                'Intermediate updates are not clearly separated from the preliminary 30-day rule.'
            ),
            'eccairs.follow-up.final-three-month' => array(
                'ECCAIRS_FOLLOW_UP', 'MATERIAL', $text56, array('5.6'), array('5.6.3', '5.6.7'),
                array('/final\s+(?:results?|reporting|information)/iu', '/(?:not later than|within)\s+three\s+months/iu'),
                'Required final results are reported as soon as available and in principle within three months.',
                'The final three-month reporting control is incomplete.'
            ),
            'eccairs.limitation.manual-control' => array(
                'KNOWN_LIMITATION', 'HARD', $allText, array('3.3', '4.2', '5.6', '5.7'), array('3.3.2', '4.2', '5.6.7', '5.6.8', '5.6.9', '5.7.3'),
                array('/(?:until|while).{0,100}(?:automatic|automated).{0,100}(?:operational|available)|(?:automatic|automated).{0,100}(?:not operational|unavailable)/isu', '/Safety Manager/iu', '/directly\s+in(?:to)?\s+ECCAIRS/iu', '/(?:evidence|follow-up control log)/iu', '/(?:closure.{0,160}ECCAIRS|ECCAIRS.{0,160}closure)/isu'),
                'The manual states that automated intermediate/final follow-up is unavailable, assigns direct authority follow-up to the Safety Manager, retains evidence and prevents premature closure.',
                'The accepted intermediate/final ECCAIRS limitation is not explicit and complete.'
            ),
            'closure.authority-follow-up-gate' => array(
                'CLOSURE', 'MATERIAL', $closure, array('5.6'), array('5.6.9'),
                array('/closure\s+shall\s+not\s+be\s+approved|shall\s+not\s+be\s+closed|before\s+approving.{0,120}closure|does\s+not\s+permit\s+closure/isu', '/intermediate.{0,80}final.{0,100}ECCAIRS|ECCAIRS\s+follow-up/isu', '/(?:(?:submission|acceptance).{0,100}evidence|evidence.{0,100}(?:submission|acceptance))/isu'),
                'Required authority follow-up and evidence are explicit prerequisites to closure.',
                'Investigation completion can bypass required ECCAIRS follow-up.'
            ),
            'closure.no-further-update-determination' => array(
                'CLOSURE', 'MATERIAL', $closure, array('5.6'), array('5.6.9'),
                array('/documented\s+(?:Safety Manager\s+)?(?:justification|determination).{0,100}no further ECCAIRS update/isu'),
                'A no-further-update disposition is documented before closure.',
                'The no-further-ECCAIRS-update determination is not documented.'
            ),
            'monitoring.occurrence-level' => array(
                'MONITORING', 'MATERIAL', (string)($nodes['5.6.8'] ?? ''), array('5.6'), array('5.6.8'),
                array('/(?:monitor|review)\s+open\s+(?:reportable\s+)?occurrences|Follow-up Control Log.{0,100}(?:reportable\s+)?occurrences/isu', '/(?:deadline|reporting stage)/iu', '/actions?/iu', '/(?:evidence|investigation)/iu'),
                'Occurrence-level deadlines, investigations, actions and evidence remain monitored in Section 5.6.',
                'Occurrence-level monitoring is incomplete in 5.6.8.'
            ),
            'monitoring.aggregate-assurance' => array(
                'MONITORING', 'MATERIAL', (string)($nodes['5.7.3'] ?? ''), array('5.7'), array('5.7.3'),
                array('/(?:aggregate|systemic|quarterly|periodic.{0,40}reconciliation)/isu', '/(?:trends?|discrepanc)/iu', '/(?:individual occurrences|reportable occurrences|occurrence(?:-management)? records)/iu'),
                'Section 5.7 provides aggregate/systemic assurance without replacing occurrence-level control.',
                'Aggregate assurance is incomplete in Section 5.7.'
            ),
            'roles.safety-manager.residual-risk-authority' => array(
                'RESPONSIBILITY', 'MATERIAL', (string)($nodes['3.3.2'] ?? ''), array('3.3'), array('3.3.2'),
                array('/Safety Manager/iu', '/residual[-\s]risk/iu', '/acceptance\s+where\s+required|authorized\s+level/iu'),
                'Safety Manager duties align residual-risk review with required acceptance authority.',
                'Safety Manager authority is inconsistent with the residual-risk gate.'
            ),
            'records.occurrence-evidence-complete' => array(
                'RECORDS', 'MATERIAL', (string)($nodes['4.2'] ?? ''), array('4.2'), array('4.2'),
                array('/(?:authority|ECCAIRS|external)\s+(?:submissions?|status|updates?)/iu', '/(?:(?:acknowledgement|acceptance|follow-up).{0,180}evidence|evidence.{0,180}(?:acknowledgement|acceptance|follow-up))/isu', '/investigation\s+(?:records?|evidence|content)|findings/iu', '/implementation\s+(?:status|evidence)/iu', '/closure\s+(?:rationale|approval|authorization|evidence)/iu'),
                'Control of Records retains authority, investigation, action and closure evidence.',
                'Section 4.2 does not control all required occurrence evidence.'
            ),
            'training.corrective-action-competence' => array(
                'TRAINING', 'MATERIAL', (string)($nodes['8.1'] ?? ''), array('8.1'), array('8.1'),
                array('/corrective\s+(?:or|and)\s+mitigating\s+action/iu', '/Action Owners?/iu', '/implementation|completion\s+evidence/iu', '/effectiveness/iu'),
                'Training covers action ownership, implementation evidence and effectiveness control.',
                'Section 8.1 does not include corrective-action competence.'
            ),
            'reporting.missing-information-deadline' => array(
                'ECCAIRS_INITIAL', 'MATERIAL', $text56, array('5.6'), array('5.6.3', '5.6.4'),
                array('/(?:absence|unknown|unavailable|not-yet-confirmed).{0,120}information/isu', '/(?:shall not|without)\s+(?:by itself\s+)?delay/iu', '/(?:applicable\s+)?(?:reporting\s+)?deadline/iu'),
                'Unavailable information is identified and does not itself delay the applicable deadline.',
                'Missing-information deadline wording is not adequately qualified.'
            ),
            'eccairs.follow-up.article-13-conditioned' => array(
                'ECCAIRS_FOLLOW_UP', 'MATERIAL', $text56, array('5.6'), array('5.6.3', '5.6.7'),
                array('/(?:Article\s+13|other\s+reportable\s+occurrences)/iu', '/(?:where|required by).{0,120}(?:competent authority|authority reporting condition)|competent authority.{0,120}(?:where|required)/isu'),
                'Other Article 13 follow-up remains conditioned by the competent authority.',
                'Other Article 13 follow-up is not authority-conditioned.'
            ),
        );
        foreach ($semantic as $id => [$category, $severity, $text, $sections, $affectedNodes, $patterns, $invariant, $failure]) {
            $inAcceptedScope = array_values(array_filter(
                array_map('strval', $affectedNodes),
                static fn(string $node): bool => isset($acceptedNodeSet[$node])
            )) !== array()
                || array_values(array_filter(
                    array_map('strval', $sections),
                    static fn(string $section): bool => isset($acceptedImpactSet[$section])
                )) !== array();
            if (!$inAcceptedScope) {
                $checks[] = $this->informationalReviewCheck(
                    $id,
                    $category,
                    $sections,
                    array(),
                    $invariant,
                    'This invariant is outside the human-accepted amendment scope.',
                    array('accepted_impact_numbers', 'accepted_structure_nodes')
                );
                continue;
            }
            $passed = $this->matchesEvery($text, $patterns);
            $checks[] = $this->reviewCheck(
                $id,
                $category,
                $severity,
                $passed,
                $sections,
                $affectedNodes,
                $invariant,
                $passed ? 'The candidate wording contains every required control concept.' : 'One or more required control concepts are absent or unclear.',
                $failure,
                $affectedNodes !== array() ? $affectedNodes : $sections,
                array('accepted_impact_baseline', 'canonical_source', 'known_system_limitations')
            );
        }

        $negativeChecks = array(
            'content.validation-labels.absent' => array('/\b(?:accountable_role|required_evidence|closure_gate)\b/iu', 'Validation-matrix labels do not appear in controlled wording.', 'Validation-matrix implementation labels leaked into controlled manual wording.'),
            'content.omission-placeholders.absent' => array('/\[PRESERVE|existing approved content remains unchanged|all existing approved .* remains unchanged/iu', 'No omission placeholder substitutes for complete wording.', 'The final proposed wording still contains an omission placeholder.'),
            'content.change-plan-terminology.absent' => array('/\b(?:REVIEW_SEPARATELY|PRESERVE_WITH_JUSTIFICATION|accepted scope|legacy disposition|Change Plan|human-approved impact|structure node)\b/iu', 'Internal Change Plan terminology does not appear in controlled wording.', 'Change Plan governance terminology leaked into proposed canonical content.'),
            'training.no-duplicated-lifecycle-catalogue' => array('/reportability and authority-deadline control.{0,400}closure authorization/isu', 'Section 3.3.3 does not duplicate the complete Section 8.1 lifecycle catalogue.', 'Section 3.3.3 unnecessarily duplicates the full role-based training catalogue in Section 8.1.'),
        );
        foreach ($negativeChecks as $id => [$pattern, $invariant, $failure]) {
            $target = $id === 'training.no-duplicated-lifecycle-catalogue'
                ? (string)($nodes['3.3.3'] ?? '')
                : $allText;
            $passed = preg_match($pattern, $target) !== 1;
            $checks[] = $this->reviewCheck(
                $id,
                'READABILITY',
                'MATERIAL',
                $passed,
                array_keys($drafts),
                $id === 'training.no-duplicated-lifecycle-catalogue' ? array('3.3.3') : array_keys($nodes),
                $invariant,
                $passed ? 'No prohibited wording pattern was detected.' : 'A prohibited wording pattern was detected.',
                $failure,
                $id === 'training.no-duplicated-lifecycle-catalogue' ? array('3.3.3') : array_keys($nodes),
                array('controlled_manual_boundary')
            );
        }
        $legacyStatus = (array)($proposal['validation_evidence']['legacy_status'] ?? array());
        $legacyAccounted = (int)($legacyStatus['remaining_within_accepted_scope'] ?? -1) === 0
            && (int)($legacyStatus['outside_scope']['count'] ?? 0) >= 1;
        $checks[] = $this->reviewCheck(
            'integrity.legacy-scope.accounted',
            'INTEGRITY',
            'HARD',
            $legacyAccounted,
            array_keys($drafts),
            array(),
            'Legacy references are separated between accepted amendment scope and governed outside scope.',
            $legacyAccounted ? 'No accepted-scope legacy reference remains and outside-scope items are retained.' : 'Legacy-reference scope accounting is incomplete.',
            'Legacy-reference status is not separated between accepted and outside scope.',
            array(),
            array('legacy_reference_status')
        );
        $checks[] = $this->reviewCheck(
            'eccairs.follow-up.no-universal-30-day-deadline',
            'ECCAIRS_FOLLOW_UP',
            'MATERIAL',
            preg_match('/(?:all|each)\s+intermediate.{0,100}(?:within|not later than)\s+30\s+days|intermediate.{0,80}universally.{0,80}30\s+days/isu', $text56) !== 1,
            array('5.6'),
            array('5.6.3', '5.6.7'),
            'The 30-day preliminary deadline is not applied universally to every intermediate update.',
            'No universal 30-day intermediate deadline was detected.',
            'The 30-day preliminary requirement was incorrectly applied to all intermediate updates.',
            array('5.6.3', '5.6.7'),
            array('canonical_source')
        );

        return array_values($checks);
    }

    /** @param list<array<string,mixed>> $checks @return array<string,mixed> */
    private function structuredReadabilityProjection(array $checks, int $nodeCount): array
    {
        $byId = array_column($checks, null, 'check_id');
        $result = static fn(string $id): bool => (string)($byId[$id]['status'] ?? '') === 'PASS';
        return array(
            'section_5_6_node_count' => $nodeCount,
            'maximum_node_count' => 10,
            'preservation_checks' => array(
                'Regulation (EU) No 376/2014' => $result('preservation.regulation-376-2014'),
                'Implementing Regulation (EU) 2015/1018' => $result('preservation.regulation-2015-1018'),
                'BCAA Circular MAS-01' => $result('preservation.bcaa-mas-01'),
                'initial 72-hour deadline' => $result('preservation.initial-72-hour-deadline'),
                'intermediate 30-day deadline' => $result('preservation.preliminary-30-day-deadline'),
                'final three-month timing' => $result('preservation.final-three-month-timing'),
                'mandatory and voluntary distinction' => $result('preservation.mandatory-voluntary-distinction'),
                'reporter protection and just culture' => $result('preservation.reporter-protection-just-culture'),
                'investigation causal and contributing factors' => $result('preservation.causal-contributing-factors'),
            ),
            'eccairs_stage_information_wording' => $result('eccairs.initial.stage-information'),
            'intermediate_final_follow_up_checks' => array(
                'initial submission is not process completion' => $result('eccairs.follow-up.initial-not-completion'),
                'findings and conclusions' => $result('eccairs.follow-up.findings-conclusions'),
                'causal and contributing factors' => $result('eccairs.follow-up.causal-contributing'),
                'actions and implementation/effectiveness' => $result('eccairs.follow-up.actions-evidence-effectiveness'),
                'direct ECCAIRS follow-up' => $result('eccairs.follow-up.approved-authority-process'),
            ),
            'validation_matrix_kept_out_of_manual' => $result('content.validation-labels.absent'),
            'final_quality_checks' => array(
                'preliminary 30-day rule preserved' => $result('eccairs.follow-up.preliminary-30-day-risk-trigger'),
                'intermediate updates separated from 30-day rule' => $result('eccairs.follow-up.intermediate-separated'),
                'final three-month rule preserved exactly' => $result('eccairs.follow-up.final-three-month'),
                'investigation completion cannot bypass ECCAIRS follow-up' => $result('closure.authority-follow-up-gate'),
                'no-further-update determination is documented' => $result('closure.no-further-update-determination'),
                'occurrence-level monitoring remains in 5.6.8' => $result('monitoring.occurrence-level'),
                'aggregate assurance remains in 5.7' => $result('monitoring.aggregate-assurance'),
                'Safety Manager authority agrees with residual-risk gate' => $result('roles.safety-manager.residual-risk-authority'),
                '4.2 controls Section 5.6 evidence' => $result('records.occurrence-evidence-complete'),
                '8.1 includes corrective-action competence' => $result('training.corrective-action-competence'),
                'missing-information deadline wording is qualified' => $result('reporting.missing-information-deadline'),
                'other Article 13 follow-up remains authority-conditioned' => $result('eccairs.follow-up.article-13-conditioned'),
                'no universal 30-day intermediate deadline' => $result('eccairs.follow-up.no-universal-30-day-deadline'),
                'complete wording contains no omission placeholders' => $result('content.omission-placeholders.absent'),
                'no Change Plan governance terminology in canonical content' => $result('content.change-plan-terminology.absent'),
                '3.3.3 avoids duplicating the full 8.1 lifecycle catalogue' => $result('training.no-duplicated-lifecycle-catalogue'),
            ),
        );
    }

    /**
     * @param list<string> $sections
     * @param list<string> $nodes
     * @param list<string> $repairScope
     * @param list<string> $evidence
     * @return array<string,mixed>
     */
    private function reviewCheck(
        string $checkId,
        string $category,
        string $severity,
        bool $passed,
        array $sections,
        array $nodes,
        string $invariant,
        string $observed,
        string $failureExplanation,
        array $repairScope,
        array $evidence
    ): array {
        return array(
            'check_id' => $checkId,
            'check_version' => self::CHECK_VERSION,
            'category' => $category,
            'severity' => $severity,
            'status' => $passed ? 'PASS' : 'FAIL',
            'affected_sections' => array_values(array_unique(array_map('strval', $sections))),
            'affected_nodes' => array_values(array_unique(array_map('strval', $nodes))),
            'required_invariant' => $invariant,
            'observed_state' => $observed,
            'evidence_references' => array_values(array_unique(array_map('strval', $evidence))),
            'human_explanation' => $passed ? $invariant : $failureExplanation,
            'allowed_repair_scope' => array_values(array_unique(array_map('strval', $repairScope))),
            'known_limitations' => str_contains($checkId, 'eccairs')
                ? array('Automated intermediate/final ECCAIRS amendment functionality is not operational.')
                : array(),
        );
    }

    /**
     * Retain a stable check identity without treating a human-dismissed
     * amendment area as missing content.
     *
     * @param list<string> $sections
     * @param list<string> $nodes
     * @param list<string> $evidence
     * @return array<string,mixed>
     */
    private function informationalReviewCheck(
        string $checkId,
        string $category,
        array $sections,
        array $nodes,
        string $invariant,
        string $observed,
        array $evidence
    ): array {
        return array(
            'check_id' => $checkId,
            'check_version' => self::CHECK_VERSION,
            'category' => $category,
            'severity' => 'INFORMATIONAL',
            'status' => 'INFORMATIONAL',
            'affected_sections' => array_values(array_unique(array_map('strval', $sections))),
            'affected_nodes' => array_values(array_unique(array_map('strval', $nodes))),
            'required_invariant' => $invariant,
            'observed_state' => $observed,
            'evidence_references' => array_values(array_unique(array_map('strval', $evidence))),
            'human_explanation' => $observed,
            'allowed_repair_scope' => array(),
            'known_limitations' => array(),
        );
    }

    /** @param list<string> $patterns */
    private function matchesEvery(string $text, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text) !== 1) {
                return false;
            }
        }
        return true;
    }

    /**
     * Select checks that can be affected by a node-scoped correction.
     *
     * @param list<array<string,mixed>> $checks
     * @param list<string> $scopeSections
     * @param list<string> $scopeNodes
     * @param list<string> $alwaysInclude
     * @return list<string>
     */
    public function scopedCheckIds(
        array $checks,
        array $scopeSections,
        array $scopeNodes,
        array $alwaysInclude = array()
    ): array {
        $sectionSet = array_fill_keys(array_map('strval', $scopeSections), true);
        $nodeSet = array_fill_keys(array_map('strval', $scopeNodes), true);
        $selected = array_fill_keys(array_map('strval', $alwaysInclude), true);
        foreach ($checks as $check) {
            $checkId = (string)($check['check_id'] ?? '');
            if ($checkId === '') {
                continue;
            }
            $affectedNodes = array_values(array_map(
                'strval',
                (array)($check['affected_nodes'] ?? array())
            ));
            $affectedSections = array_values(array_map(
                'strval',
                (array)($check['affected_sections'] ?? array())
            ));
            $nodeIntersection = array_values(array_filter(
                $affectedNodes,
                static fn(string $node): bool => isset($nodeSet[$node])
            ));
            $sectionIntersection = array_values(array_filter(
                $affectedSections,
                static fn(string $section): bool => isset($sectionSet[$section])
            ));
            if ($nodeIntersection !== array()
                || ($affectedNodes === array() && $sectionIntersection !== array())
                || ($affectedNodes === array() && $affectedSections === array())) {
                $selected[$checkId] = true;
            }
        }
        return array_keys($selected);
    }

    private function checkSlug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        return trim($slug, '-');
    }

    /**
     * Verify a targeted correction without reopening accepted scope or
     * structure. Whole-proposal deterministic checks may run, but only the
     * authorized section scope may differ from the accepted baseline.
     *
     * @param array<string,mixed> $acceptedProposal
     * @param array<string,mixed> $candidateProposal
     * @param list<string> $scopeSections
     * @return array<string,mixed>
     */
    public function verifyTargetedPatch(
        array $acceptedProposal,
        array $candidateProposal,
        array $scopeSections,
        array $scopeNodes = array(),
        array $targetCheckIds = array()
    ): array {
        $scope = array_fill_keys(array_map('strval', $scopeSections), true);
        $allowedNodes = array_fill_keys(array_map('strval', $scopeNodes), true);
        $before = (array)($acceptedProposal['section_drafts'] ?? array());
        $after = (array)($candidateProposal['section_drafts'] ?? array());
        $unchanged = array();
        $unchangedNodes = array();
        $scopeViolations = array();
        foreach ($before as $section => $draft) {
            $beforeJson = $this->json($draft);
            $afterJson = $this->json($after[$section] ?? null);
            $matches = hash_equals(hash('sha256', $beforeJson), hash('sha256', $afterJson));
            if (!isset($scope[(string)$section])) {
                $unchanged[(string)$section] = $matches;
            }
            if (!isset($scope[(string)$section]) && !$matches) {
                $scopeViolations[] = (string)$section;
            }
            foreach ((array)($draft['nodes'] ?? array()) as $number => $content) {
                if (isset($allowedNodes[(string)$number])) {
                    continue;
                }
                $nodeMatches = hash_equals(
                    hash('sha256', $this->json($content)),
                    hash('sha256', $this->json($after[$section]['nodes'][$number] ?? null))
                );
                $unchangedNodes[(string)$number] = $nodeMatches;
                if (!$nodeMatches) {
                    $scopeViolations[] = (string)$number;
                }
            }
        }
        $scopeViolations = array_values(array_unique($scopeViolations));
        $structureBefore = array_map(
            static fn(array $draft): array => array_keys((array)($draft['nodes'] ?? array())),
            $before
        );
        $structureAfter = array_map(
            static fn(array $draft): array => array_keys((array)($draft['nodes'] ?? array())),
            $after
        );
        $structurePreserved = $structureBefore === $structureAfter;
        $changedNodes = array();
        $changedSections = array();
        foreach (array_keys($allowedNodes) as $number) {
            foreach ($before as $section => $draft) {
                if (!array_key_exists($number, (array)($draft['nodes'] ?? array()))) {
                    continue;
                }
                if ($this->json($draft['nodes'][$number] ?? null)
                    !== $this->json($after[$section]['nodes'][$number] ?? null)) {
                    $changedNodes[] = $number;
                    $changedSections[] = (string)$section;
                }
                break;
            }
        }
        $changedNodes = array_values(array_unique($changedNodes));
        $changedSections = array_values(array_unique($changedSections));
        $verification = $this->verifyReadableAmendmentProposal($candidateProposal);
        $parentVerification = $this->verifyReadableAmendmentProposal($acceptedProposal);
        $reviewChecks = array_values((array)($verification['review_checks'] ?? array()));
        $reverifiedCheckIds = $this->scopedCheckIds(
            array_values((array)($parentVerification['review_checks'] ?? array())),
            $changedSections,
            $changedNodes,
            array_values(array_unique(array_map('strval', $targetCheckIds)))
        );
        $reverifiedSet = array_fill_keys($reverifiedCheckIds, true);
        $reconciliationChecks = array_values(array_filter(
            $reviewChecks,
            static fn(array $check): bool =>
                isset($reverifiedSet[(string)($check['check_id'] ?? '')])
        ));
        $issues = array_values(array_map(
            static fn(array $check): string => (string)($check['human_explanation'] ?? ''),
            array_filter(
                $reconciliationChecks,
                static fn(array $check): bool =>
                    (string)($check['status'] ?? '') === 'FAIL'
            )
        ));
        if ($scopeViolations !== array()) {
            $issues[] = 'Targeted correction changed unrelated accepted wording.';
        }
        if (!$structurePreserved) {
            $issues[] = 'Targeted correction changed the accepted structure.';
        }
        $checkMap = array_column($reviewChecks, null, 'check_id');
        $targetedResults = array();
        foreach (array_values(array_unique(array_map('strval', $targetCheckIds))) as $checkId) {
            $targetedResults[$checkId] = $checkMap[$checkId] ?? array(
                'check_id' => $checkId,
                'check_version' => self::CHECK_VERSION,
                'status' => 'FAIL',
                'category' => 'INTEGRITY',
                'severity' => 'HARD',
                'required_invariant' => 'The targeted check remains independently verifiable.',
                'observed_state' => 'The check identity was absent from reverification.',
                'human_explanation' => "Targeted review check {$checkId} was not reverified.",
                'affected_sections' => array_keys($scope),
                'affected_nodes' => array_keys($allowedNodes),
                'allowed_repair_scope' => array_keys($allowedNodes),
                'evidence_references' => array('targeted_patch'),
                'known_limitations' => array(),
            );
        }
        $preservationCheck = array(
            'check_id' => 'integrity.targeted-patch.frozen-nodes',
            'check_version' => self::CHECK_VERSION,
            'category' => 'INTEGRITY',
            'severity' => 'HARD',
            'status' => $scopeViolations === array() ? 'PASS' : 'FAIL',
            'affected_sections' => array_keys($scope),
            'affected_nodes' => array_keys($allowedNodes),
            'required_invariant' => 'A targeted correction changes only explicitly authorized nodes.',
            'observed_state' => $scopeViolations === array()
                ? 'Every frozen node and unaffected section remained byte-identical.'
                : 'Changed outside repair scope: ' . implode(', ', $scopeViolations),
            'evidence_references' => array('parent_draft', 'candidate_draft'),
            'human_explanation' => $scopeViolations === array()
                ? 'Targeted patch preservation passed.'
                : 'Targeted correction changed unrelated accepted wording.',
            'allowed_repair_scope' => array_keys($allowedNodes),
            'known_limitations' => array(),
        );
        $structureCheck = array(
            'check_id' => 'integrity.targeted-patch.structure-preserved',
            'check_version' => self::CHECK_VERSION,
            'category' => 'INTEGRITY',
            'severity' => 'HARD',
            'status' => $structurePreserved ? 'PASS' : 'FAIL',
            'affected_sections' => array_keys($scope),
            'affected_nodes' => array_keys($allowedNodes),
            'required_invariant' => 'A targeted correction preserves the accepted structure.',
            'observed_state' => $structurePreserved
                ? 'The node hierarchy is unchanged.'
                : 'The candidate node hierarchy differs from the accepted structure.',
            'evidence_references' => array('accepted_structure'),
            'human_explanation' => $structurePreserved
                ? 'Accepted structure remains unchanged.'
                : 'Targeted correction changed the accepted structure.',
            'allowed_repair_scope' => array(),
            'known_limitations' => array(),
        );
        $reviewChecks[] = $preservationCheck;
        $reviewChecks[] = $structureCheck;
        $reconciliationChecks[] = $preservationCheck;
        $reconciliationChecks[] = $structureCheck;
        return array(
            'schema' => 'ipca.manual-change-targeted-reverification.v1',
            'status' => $issues === array() ? 'VERIFIED' : self::REQUIRES_REVIEW,
            'scope_sections' => array_keys($scope),
            'scope_nodes' => array_keys($allowedNodes),
            'targeted_check_ids' => array_keys($targetedResults),
            'targeted_check_results' => $targetedResults,
            'reverified_check_ids' => array_values(array_unique(array_merge(
                $reverifiedCheckIds,
                array(
                    'integrity.targeted-patch.frozen-nodes',
                    'integrity.targeted-patch.structure-preserved',
                )
            ))),
            'reconciliation_checks' => $reconciliationChecks,
            'review_checks' => $reviewChecks,
            'unaffected_sections_byte_unchanged' => !in_array(false, $unchanged, true),
            'unaffected_section_results' => $unchanged,
            'frozen_nodes_byte_unchanged' => !in_array(false, $unchangedNodes, true),
            'frozen_node_results' => $unchangedNodes,
            'accepted_structure_preserved' => $structurePreserved,
            'known_limitations_checked' =>
                (array)($verification['unsupported_capability_claims'] ?? array()) === array(),
            'preservation_boundaries_checked' =>
                (array)($verification['readability']['preservation_checks'] ?? array()),
            'issues' => array_values(array_unique($issues)),
            'full_integrity_result' => $verification,
            'architect_rerun_performed' => false,
            'production_applied' => false,
        );
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

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
