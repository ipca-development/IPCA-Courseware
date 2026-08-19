<?php
declare(strict_types=1);

require_once __DIR__ . '/BooksManualsChangePlanService.php';

/**
 * Versioned CURRENT vs FUTURE structure proposals.
 *
 * The returned operation primitives describe structure only. They do not
 * mutate controlled publishing content and cannot authorize drafting.
 */
final class BooksManualsChangeStructureService
{
    private const NODE_ACTIONS = array('ADD', 'MOVE', 'RENAME', 'MERGE', 'SPLIT', 'PRESERVE', 'REMOVE');

    public function __construct(
        private PDO $pdo,
        private ?BooksManualsChangePlanService $plans = null
    ) {
        $this->plans ??= new BooksManualsChangePlanService($pdo);
    }

    /**
     * @param list<array<string,mixed>> $areas
     * @return array<string,mixed>
     */
    public function buildProposal(
        string $title,
        string $rationale,
        string $sourceFingerprint,
        array $areas
    ): array {
        $title = trim($title);
        $rationale = trim($rationale);
        if ($title === '' || $rationale === '' || !preg_match('/^[a-f0-9]{64}$/', $sourceFingerprint)) {
            throw new InvalidArgumentException('Title, rationale, and a canonical source fingerprint are required.');
        }
        if ($areas === array()) {
            throw new InvalidArgumentException('At least one human-accepted amendment area is required.');
        }
        $normalizedAreas = array();
        $operations = array();
        $primaryCount = 0;
        foreach ($areas as $areaIndex => $area) {
            $sectionNumber = trim((string)($area['section_number'] ?? ''));
            $sectionTitle = trim((string)($area['section_title'] ?? ''));
            $treatment = trim((string)($area['treatment'] ?? ''));
            if ($sectionNumber === '' || $sectionTitle === ''
                || !in_array($treatment, array('AMEND', 'RESTRUCTURE'), true)
                || empty($area['human_accepted'])) {
                throw new InvalidArgumentException('Every structure area must be a human-accepted AMEND or RESTRUCTURE impact.');
            }
            if ($treatment === 'RESTRUCTURE') {
                $primaryCount++;
            }
            $current = $this->normalizeTree((array)($area['current'] ?? array()), 'current');
            $future = $this->normalizeTree((array)($area['future'] ?? array()), 'future');
            if ($current === array() || $future === array()) {
                throw new InvalidArgumentException('Each amendment area requires CURRENT and FUTURE structure trees.');
            }
            $areaKey = 'area-' . preg_replace('/[^a-z0-9]+/i', '-', strtolower($sectionNumber));
            $normalized = array(
                'area_key' => trim((string)$areaKey, '-'),
                'section_number' => $sectionNumber,
                'section_title' => $sectionTitle,
                'source_section_id' => isset($area['source_section_id']) ? (int)$area['source_section_id'] : null,
                'source_anchor' => trim((string)($area['source_anchor'] ?? '')),
                'source_content_fingerprint' => trim((string)($area['source_content_fingerprint'] ?? $sourceFingerprint)),
                'treatment' => $treatment,
                'human_accepted' => true,
                'current' => $current,
                'future' => $future,
                'dependencies' => array_values((array)($area['dependencies'] ?? array())),
                'reasoning' => trim((string)($area['reasoning'] ?? '')),
            );
            $normalizedAreas[] = $normalized;
            $operations = array_merge(
                $operations,
                $this->structureOperations($normalized, $areaIndex)
            );
        }
        if ($primaryCount !== 1) {
            throw new InvalidArgumentException('Exactly one accepted area must be the primary RESTRUCTURE.');
        }
        $proposal = array(
            'schema' => 'ipca.manual-change-structure-proposal.v1',
            'title' => $title,
            'rationale' => $rationale,
            'status' => 'proposed',
            'source_fingerprint' => $sourceFingerprint,
            'areas' => $normalizedAreas,
            'operation_primitives' => $operations,
            'guardrails' => array(
                'human_accepted_impacts_only' => true,
                'draft_wording_present' => false,
                'publishing_content_mutated' => false,
                'expected_source_fingerprint_required' => true,
            ),
        );
        $proposal['structure_fingerprint'] = hash('sha256', $this->json($proposal));
        return $proposal;
    }

    /** @param array<string,mixed> $proposal */
    public function persistProposal(int $planId, array $proposal, int $actorUserId): int
    {
        if (($proposal['schema'] ?? '') !== 'ipca.manual-change-structure-proposal.v1'
            || ($proposal['guardrails']['draft_wording_present'] ?? true) !== false
            || ($proposal['guardrails']['publishing_content_mutated'] ?? true) !== false
            || $actorUserId <= 0) {
            throw new InvalidArgumentException('Only a validated read-only structure proposal can be persisted.');
        }
        $plan = $this->plans->loadPlan($planId);
        $approvedImpactNumbers = array_fill_keys(array_map(
            static fn(array $impact): string => (string)($impact['section_number'] ?? ''),
            array_filter(
                (array)$plan['impacts'],
                static fn(array $impact): bool => (string)($impact['status'] ?? '') === 'approved'
            )
        ), true);
        foreach ((array)$proposal['areas'] as $area) {
            if (!isset($approvedImpactNumbers[(string)$area['section_number']])) {
                throw new RuntimeException('Structure proposals may only include human-approved impact areas.');
            }
        }
        $version = 1;
        foreach ((array)$plan['structure_proposals'] as $existing) {
            $version = max($version, (int)$existing['proposal_version'] + 1);
        }
        $proposalId = $this->plans->save('structure_proposals', $planId, array(
            'proposal_uuid' => $this->uuid(),
            'proposal_version' => $version,
            'title' => $proposal['title'],
            'rationale' => $proposal['rationale'],
            'status' => 'proposed',
            'structure_fingerprint' => $proposal['structure_fingerprint'],
            'proposed_by' => $actorUserId,
        ));
        $sort = 0;
        foreach ((array)$proposal['areas'] as $area) {
            $this->persistFutureNodes(
                $planId,
                $proposalId,
                (array)$area['future'],
                null,
                (int)($area['source_section_id'] ?? 0),
                0,
                $sort
            );
        }
        $this->plans->appendEvent($planId, 'STRUCTURE_PROPOSED', 11, array(
            'structure_proposal_id' => $proposalId,
            'proposal_version' => $version,
            'structure_fingerprint' => $proposal['structure_fingerprint'],
            'operation_count' => count((array)$proposal['operation_primitives']),
        ), $actorUserId);
        $this->plans->updatePlan($planId, array(
            'stage' => 'structure',
            'status' => 'ready_for_review',
            'updated_by' => $actorUserId,
        ));
        return $proposalId;
    }

    /** @param list<array<string,mixed>> $nodes @return list<array<string,mixed>> */
    private function normalizeTree(array $nodes, string $tree): array
    {
        $normalized = array();
        foreach ($nodes as $index => $node) {
            $number = trim((string)($node['number'] ?? ''));
            $title = trim((string)($node['title'] ?? ''));
            $action = strtoupper(trim((string)($node['action'] ?? ($tree === 'current' ? 'PRESERVE' : 'ADD'))));
            if ($number === '' || $title === '' || !in_array($action, self::NODE_ACTIONS, true)) {
                throw new InvalidArgumentException('Structure nodes require number, title, and a canonical action.');
            }
            $normalized[] = array(
                'node_key' => trim((string)($node['node_key'] ?? $tree . '-'
                    . preg_replace('/[^a-z0-9]+/i', '-', strtolower($number))), '-'),
                'number' => $number,
                'title' => $title,
                'node_type' => (string)($node['node_type'] ?? 'section'),
                'purpose' => trim((string)($node['purpose'] ?? '')),
                'action' => $action,
                'source_references' => array_values((array)($node['source_references'] ?? array())),
                'children' => $this->normalizeTree((array)($node['children'] ?? array()), $tree),
                'sort_order' => $index,
            );
        }
        return $normalized;
    }

    /** @param array<string,mixed> $area @return list<array<string,mixed>> */
    private function structureOperations(array $area, int $areaIndex): array
    {
        $operations = array();
        $walk = function (array $nodes, ?string $parentKey = null) use (
            &$walk,
            &$operations,
            $area,
            $areaIndex
        ): void {
            foreach ($nodes as $node) {
                $operations[] = array(
                    'operation_key' => 'structure-' . $areaIndex . '-' . $node['node_key'],
                    'operation_type' => $node['action'] . '_NODE',
                    'target_node_key' => $node['node_key'],
                    'parent_node_key' => $parentKey,
                    'section_number' => $area['section_number'],
                    'source_section_id' => $area['source_section_id'],
                    'source_anchor' => $area['source_anchor'],
                    'expected_source_fingerprint' => $area['source_content_fingerprint'],
                    'source_references' => $node['source_references'],
                    'authorized_by_accepted_impact' => true,
                );
                $walk($node['children'], $node['node_key']);
            }
        };
        $walk($area['future']);
        return $operations;
    }

    /** @param list<array<string,mixed>> $nodes */
    private function persistFutureNodes(
        int $planId,
        int $proposalId,
        array $nodes,
        ?int $parentId,
        int $sourceSectionId,
        int $depth,
        int &$sort
    ): void {
        foreach ($nodes as $node) {
            $fingerprint = hash('sha256', $this->json(array(
                'proposal_id' => $proposalId,
                'parent_id' => $parentId,
                'node' => $node,
            )));
            $nodeId = $this->plans->saveStructureNode($planId, $proposalId, array(
                'node_uuid' => $this->uuid(),
                'parent_node_id' => $parentId,
                'source_section_id' => $sourceSectionId > 0 ? $sourceSectionId : null,
                'node_key' => $node['node_key'],
                'node_type' => $node['node_type'],
                'title' => trim($node['number'] . ' ' . $node['title']),
                'purpose' => $node['purpose'],
                'action' => $node['action'],
                'decision_status' => 'proposed',
                'depth' => $depth,
                'sort_order' => $sort++,
                'node_fingerprint' => $fingerprint,
            ));
            $this->persistFutureNodes(
                $planId,
                $proposalId,
                $node['children'],
                $nodeId,
                $sourceSectionId,
                $depth + 1,
                $sort
            );
        }
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
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20)
        );
    }
}
