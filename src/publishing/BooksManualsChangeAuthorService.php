<?php
declare(strict_types=1);

require_once __DIR__ . '/BooksManualsChangePlanService.php';

/**
 * Strict authorization boundary for amendment drafting.
 */
final class BooksManualsChangeAuthorService
{
    public const PROMPT_VERSION = 'manual-change-author-v1';

    public function __construct(
        private PDO $pdo,
        private ?BooksManualsChangePlanService $plans = null
    ) {
        $this->plans ??= new BooksManualsChangePlanService($pdo);
    }

    /** @return array<string,mixed> */
    public function buildAuthorizedBrief(int $planId): array
    {
        $plan = $this->plans->loadPlan($planId);
        $approvedImpacts = array_values(array_filter(
            (array)$plan['impacts'],
            static fn(array $impact): bool =>
                (string)($impact['status'] ?? '') === 'approved'
                && (string)($impact['boundary_classification'] ?? '') === 'MUST_CHANGE'
        ));
        $approvedProposalIds = array_fill_keys(array_map(
            static fn(array $proposal): int => (int)$proposal['id'],
            array_filter(
                (array)$plan['structure_proposals'],
                static fn(array $proposal): bool =>
                    (string)($proposal['status'] ?? '') === 'approved'
            )
        ), true);
        $acceptedNodes = array_values(array_filter(
            (array)$plan['structure_nodes'],
            static fn(array $node): bool =>
                isset($approvedProposalIds[(int)($node['structure_proposal_id'] ?? 0)])
                && (string)($node['decision_status'] ?? '') === 'accepted'
        ));

        if ($approvedImpacts === array()) {
            throw new RuntimeException('The Amendment Author requires at least one human-approved impact.');
        }
        if ($acceptedNodes === array()) {
            throw new RuntimeException('The Amendment Author requires individually accepted structure nodes.');
        }

        return array(
            'schema' => 'ipca.manual-change-author-brief.v1',
            'plan_id' => $planId,
            'role' => 'AMENDMENT_AUTHOR',
            'prompt_version' => self::PROMPT_VERSION,
            'authorization' => array(
                'human_approved_impacts_only' => true,
                'accepted_structure_nodes_only' => true,
                'architect_candidates_authorize_drafting' => false,
                'semantic_relevance_authorizes_drafting' => false,
            ),
            'approved_impacts' => $approvedImpacts,
            'accepted_structure_nodes' => $acceptedNodes,
            'instruction' => 'Draft only the authorized areas. Preserve all protected logic and do not expand scope.',
        );
    }

    /**
     * Assemble author-drafted wording without touching controlled publishing.
     *
     * @param array<string,mixed> $authorization
     * @param array<string,array<string,mixed>> $sectionDrafts
     * @param list<array<string,mixed>> $lifecycle
     * @return array<string,mixed>
     */
    public function assembleAmendmentProposal(
        array $authorization,
        array $sectionDrafts,
        array $lifecycle,
        array $validationEvidence = array()
    ): array {
        if (($authorization['authorization']['human_approved_impacts_only'] ?? false) !== true
            || ($authorization['authorization']['accepted_structure_nodes_only'] ?? false) !== true) {
            throw new InvalidArgumentException('The Amendment Author requires the accepted authorization brief.');
        }
        $acceptedImpacts = array_values(array_unique(array_filter(array_map(
            static fn(array $impact): string => trim((string)($impact['section_number'] ?? '')),
            (array)($authorization['approved_impacts'] ?? array())
        ))));
        sort($acceptedImpacts, SORT_NATURAL);
        $draftSections = array_keys($sectionDrafts);
        sort($draftSections, SORT_NATURAL);
        if ($acceptedImpacts === array() || $draftSections !== $acceptedImpacts) {
            throw new RuntimeException('Draft sections must exactly equal the individually accepted impacts.');
        }
        $acceptedNodeNumbers = array_values(array_unique(array_filter(array_map(
            function (array $node): string {
                $number = trim((string)($node['number'] ?? ''));
                if ($number !== '') {
                    return $number;
                }
                preg_match('/^([A-Z]?\d+(?:\.\d+)*)\b/u', trim((string)($node['title'] ?? '')), $match);
                return (string)($match[1] ?? '');
            },
            (array)($authorization['accepted_structure_nodes'] ?? array())
        ))));
        if ($acceptedNodeNumbers === array()) {
            throw new RuntimeException('No individually accepted structure nodes were supplied.');
        }
        $implementedNodes = array();
        foreach ($sectionDrafts as $sectionNumber => &$draft) {
            $draft['section_number'] = $sectionNumber;
            $draft['status'] = 'proposed';
            $draft['production_applied'] = false;
            $nodes = (array)($draft['nodes'] ?? array());
            if ($nodes === array()) {
                throw new RuntimeException("Section {$sectionNumber} has no accepted-node implementation.");
            }
            foreach ($nodes as $number => $content) {
                if (!in_array((string)$number, $acceptedNodeNumbers, true)) {
                    throw new RuntimeException("Drafting was attempted for unaccepted structure node {$number}.");
                }
                if (trim((string)$content) === '') {
                    throw new RuntimeException("Accepted structure node {$number} has no drafted content.");
                }
                $implementedNodes[] = (string)$number;
            }
        }
        unset($draft);
        foreach ($acceptedNodeNumbers as $number) {
            if (!in_array($number, $implementedNodes, true)) {
                throw new RuntimeException("Accepted structure node {$number} was not implemented.");
            }
        }
        $requiredLifecycleStates = array(
            'INITIAL_REPORT',
            'TRIAGE',
            'REPORTABILITY_DECISION',
            'AUTHORITY_DEADLINE_CONTROL',
            'ECCAIRS_PREPARATION_APPROVAL',
            'TRANSMISSION',
            'INVESTIGATION',
            'ACTIONS',
            'RESIDUAL_RISK_ACCEPTANCE',
            'EFFECTIVENESS_REVIEW',
            'MONITORING_ESCALATION',
            'AUTHORITY_FOLLOW_UP',
            'CONTROLLED_CLOSURE',
        );
        $actualStates = array_column($lifecycle, 'state');
        if ($actualStates !== $requiredLifecycleStates) {
            throw new RuntimeException('The drafted lifecycle is incomplete or out of the accepted order.');
        }
        foreach ($lifecycle as $state) {
            foreach (array('accountable_role', 'required_evidence', 'deadline_control', 'closure_gate') as $field) {
                if (trim((string)($state[$field] ?? '')) === '') {
                    throw new RuntimeException("Lifecycle state {$state['state']} lacks {$field}.");
                }
            }
        }
        $proposal = array(
            'schema' => 'ipca.manual-change-amendment-proposal.v1',
            'role' => 'AMENDMENT_AUTHOR',
            'prompt_version' => self::PROMPT_VERSION,
            'accepted_impact_numbers' => $acceptedImpacts,
            'accepted_structure_nodes' => $acceptedNodeNumbers,
            'section_drafts' => $sectionDrafts,
            'lifecycle' => $lifecycle,
            'validation_evidence' => $validationEvidence,
            'protected_sections' => array(
                '6.4' => 'PRESERVE_WITH_JUSTIFICATION',
                '5.2.5.2' => 'REVIEW_SEPARATELY',
                '5.9' => 'REVIEW_SEPARATELY',
            ),
            'guardrails' => array(
                'production_applied' => false,
                'software_screens_or_database_structures_described' => false,
                'static_eccairs_field_schema_included' => false,
                'scope_expanded_beyond_accepted_plan' => false,
                'intermediate_final_eccairs_automation_claimed' => false,
            ),
        );
        $proposal['proposal_fingerprint'] = hash(
            'sha256',
            json_encode($proposal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
        return $proposal;
    }
}
