<?php
declare(strict_types=1);

/**
 * Builds a governed, read-only canonical operation package.
 *
 * This service deliberately has no apply method. The package must receive
 * separate human approval before a working-revision transaction may consume it.
 */
final class BooksManualsChangeOperationService
{
    private const SECTIONS = array('3.3', '4.2', '5.6', '5.7', '8.1');

    /**
     * @param array<string,mixed> $canonicalSnapshot
     * @param array<string,mixed> $structureProposal
     * @param array<string,mixed> $amendmentProposal
     * @param array<string,mixed> $review
     * @return array<string,mixed>
     */
    public function buildPackage(
        array $canonicalSnapshot,
        array $structureProposal,
        array $amendmentProposal,
        array $review
    ): array {
        if (($review['status'] ?? '') !== 'READY_FOR_HUMAN_REVIEW'
            || ($review['issues'] ?? array()) !== array()
            || ($amendmentProposal['guardrails']['production_applied'] ?? true) !== false) {
            throw new RuntimeException('Canonical operations require a clean independent Reviewer READY result.');
        }
        $manual = (array)($canonicalSnapshot['manual'] ?? array());
        $targets = (array)($canonicalSnapshot['targets'] ?? array());
        $drafts = (array)($amendmentProposal['section_drafts'] ?? array());
        $areas = (array)($structureProposal['areas'] ?? array());
        $expected = self::SECTIONS;
        if (array_keys($targets) !== $expected || array_keys($drafts) !== $expected) {
            throw new RuntimeException('Canonical targets and accepted amendment sections must match exactly.');
        }
        if ((int)($manual['book_version_id'] ?? 0) <= 0
            || (string)($manual['lifecycle_status'] ?? '') !== 'in_review') {
            throw new RuntimeException('The source snapshot must identify the reviewed canonical manual version.');
        }
        $areaByNumber = array();
        foreach ($areas as $area) {
            $areaByNumber[(string)($area['section_number'] ?? '')] = $area;
        }
        if (array_keys($areaByNumber) !== $expected) {
            throw new RuntimeException('Structure authorization differs from the accepted amendment scope.');
        }

        $contentOperations = array();
        $beforeAfter = array();
        foreach ($expected as $index => $sectionNumber) {
            $target = (array)$targets[$sectionNumber];
            $draft = (array)$drafts[$sectionNumber];
            $this->assertTarget($sectionNumber, $target);
            $nodes = (array)($draft['nodes'] ?? array());
            if ($nodes === array()) {
                throw new RuntimeException("Section {$sectionNumber} has no complete replacement wording.");
            }
            $afterFingerprint = hash('sha256', $this->json(array(
                'section_number' => $sectionNumber,
                'nodes' => $nodes,
            )));
            $contentOperations[] = array(
                'sequence' => $index + 2,
                'operation_key' => 'content-' . str_replace('.', '-', $sectionNumber),
                'operation_type' => 'REPLACE_BLOCK_RANGE',
                'target' => array(
                    'source_version_id' => (int)$manual['book_version_id'],
                    'destination_version_id' => null,
                    'section_number' => $sectionNumber,
                    'section_id' => (int)$target['section_id'],
                    'sort_from' => (int)$target['sort_from'],
                    'sort_to' => (int)$target['sort_to'],
                    'source_block_ids' => array_values(array_map('intval', (array)$target['block_ids'])),
                    'first_anchor' => (string)$target['first_anchor'],
                    'last_anchor' => (string)$target['last_anchor'],
                ),
                'preconditions' => array(
                    'source_version_status' => (string)$manual['lifecycle_status'],
                    'expected_range_fingerprint' => (string)$target['range_fingerprint'],
                    'expected_first_block_hash' => (string)$target['first_block_hash'],
                    'expected_last_block_hash' => (string)$target['last_block_hash'],
                    'block_count' => count((array)$target['block_ids']),
                    'destination_is_fresh_clone_of_source' => true,
                ),
                'replacement' => array(
                    'section_title' => (string)($draft['title'] ?? ''),
                    'treatment' => (string)($draft['treatment'] ?? ''),
                    'nodes' => $nodes,
                    'content_fingerprint' => $afterFingerprint,
                ),
                'authorized_by_human_accepted_impact' => true,
                'applied' => false,
            );
            $beforeAfter[] = array(
                'section_number' => $sectionNumber,
                'before' => array(
                    'block_count' => count((array)$target['block_ids']),
                    'range_fingerprint' => (string)$target['range_fingerprint'],
                    'first_anchor' => (string)$target['first_anchor'],
                    'last_anchor' => (string)$target['last_anchor'],
                ),
                'after' => array(
                    'manual_node_count' => count($nodes),
                    'content_fingerprint' => $afterFingerprint,
                    'treatment' => (string)($draft['treatment'] ?? ''),
                ),
            );
        }

        $primaryArea = (array)$areaByNumber['5.6'];
        $structuralOperation = array(
            'sequence' => 1,
            'operation_key' => 'structure-5-6',
            'operation_type' => 'REPLACE_SECTION_STRUCTURE',
            'target' => array(
                'source_version_id' => (int)$manual['book_version_id'],
                'destination_version_id' => null,
                'section_number' => '5.6',
                'section_id' => (int)$targets['5.6']['section_id'],
            ),
            'preconditions' => array(
                'expected_range_fingerprint' => (string)$targets['5.6']['range_fingerprint'],
                'destination_is_fresh_clone_of_source' => true,
                'current_tree_fingerprint' => hash('sha256', $this->json($primaryArea['current'] ?? array())),
            ),
            'before_tree' => (array)($primaryArea['current'] ?? array()),
            'after_tree' => (array)($primaryArea['future'] ?? array()),
            'after_tree_fingerprint' => hash('sha256', $this->json($primaryArea['future'] ?? array())),
            'authorized_by_human_accepted_impact' => true,
            'applied' => false,
        );

        $sourceSnapshotFingerprint = hash('sha256', $this->json(array(
            'manual' => $manual,
            'range_fingerprints' => array_map(
                static fn(array $target): string => (string)$target['range_fingerprint'],
                $targets
            ),
        )));
        $package = array(
            'schema' => 'ipca.manual-change-operation-package.v1',
            'status' => 'READY_FOR_HUMAN_REVIEW',
            'human_approval_status' => 'pending',
            'source_manual' => $manual,
            'source_snapshot_fingerprint' => $sourceSnapshotFingerprint,
            'reviewer' => array(
                'status' => (string)$review['status'],
                'issues' => (array)$review['issues'],
                'target_state_coverage_complete' =>
                    (array)($review['lifecycle_governance_gaps'] ?? array()) === array(),
                'unsupported_claims' => (array)($review['unsupported_capability_claims'] ?? array()),
                'change_plan_terms_in_content' =>
                    !(bool)($review['readability']['final_quality_checks']['no Change Plan governance terminology in canonical content'] ?? false),
                'legacy_reference_status' => (array)($review['legacy_reference_status'] ?? array()),
            ),
            'structural_operations' => array($structuralOperation),
            'content_operations' => $contentOperations,
            'before_after_summary' => $beforeAfter,
            'operation_count' => 1 + count($contentOperations),
            'guardrails' => array(
                'apply_authorized' => false,
                'applied' => false,
                'production_mutated' => false,
                'destination_version_id_unassigned' => true,
                'apply_requires_separate_human_approval' => true,
                'apply_requires_fresh_working_revision_clone' => true,
                'all_preconditions_must_be_revalidated' => true,
                'transactional_all_or_nothing' => true,
            ),
        );
        $package['operation_package_fingerprint'] = hash('sha256', $this->json($package));
        return $package;
    }

    /**
     * Build the minimal canonical-write package from explicitly governed deltas.
     *
     * @param array<string,mixed> $canonicalSnapshot
     * @param array<string,mixed> $structureProposal
     * @param array<string,mixed> $amendmentProposal
     * @param array<string,mixed> $amendmentReview
     * @param array<string,mixed> $minimalPlan
     * @return array<string,mixed>
     */
    public function buildMinimalPackage(
        array $canonicalSnapshot,
        array $structureProposal,
        array $amendmentProposal,
        array $amendmentReview,
        array $minimalPlan
    ): array {
        if (($amendmentReview['status'] ?? '') !== 'READY_FOR_HUMAN_REVIEW'
            || ($amendmentReview['issues'] ?? array()) !== array()
            || ($amendmentProposal['guardrails']['production_applied'] ?? true) !== false) {
            throw new RuntimeException('Minimal operations require a clean independent amendment review.');
        }
        $manual = (array)($canonicalSnapshot['manual'] ?? array());
        $targets = (array)($canonicalSnapshot['targets'] ?? array());
        $drafts = (array)($amendmentProposal['section_drafts'] ?? array());
        $planOperations = array_values((array)($minimalPlan['operations'] ?? array()));
        $minimality = (array)($minimalPlan['minimality'] ?? array());
        if (array_keys($targets) !== self::SECTIONS
            || array_keys($drafts) !== self::SECTIONS
            || array_keys($minimality) !== self::SECTIONS
            || $planOperations === array()) {
            throw new RuntimeException('Minimal operation scope differs from the five accepted amendment areas.');
        }
        foreach ($targets as $number => $target) {
            $this->assertTarget((string)$number, (array)$target);
        }
        $areaByNumber = array();
        foreach ((array)($structureProposal['areas'] ?? array()) as $area) {
            $areaByNumber[(string)($area['section_number'] ?? '')] = $area;
        }
        if (array_keys($areaByNumber) !== self::SECTIONS) {
            throw new RuntimeException('Minimal operations lack the accepted structure authorization.');
        }
        usort(
            $planOperations,
            static fn(array $left, array $right): int =>
                (int)($left['sequence'] ?? 0) <=> (int)($right['sequence'] ?? 0)
        );

        $operations = array();
        foreach ($planOperations as $index => $planned) {
            $section = (string)($planned['section_number'] ?? '');
            if (!isset($targets[$section]) || (int)($planned['section_id'] ?? 0) !== (int)$targets[$section]['section_id']) {
                throw new RuntimeException('A minimal operation targets an unauthorized canonical section.');
            }
            $operation = $planned;
            $operation['sequence'] = $index + 1;
            $operation['source_version_id'] = (int)$manual['book_version_id'];
            $operation['destination_version_id'] = null;
            $operation['authorized_by_human_accepted_impact'] = true;
            $operation['applied'] = false;
            if (($operation['operation_type'] ?? '') === 'RESTRUCTURE_SECTION_WITH_CONTENT') {
                if ($section !== '5.6' || empty($operation['atomic'])) {
                    throw new RuntimeException('Only the accepted Section 5.6 restructure may replace a complete range.');
                }
                $futureTree = (array)$areaByNumber['5.6']['future'];
                $nodes = (array)$drafts['5.6']['nodes'];
                $generatedBlocks = array();
                foreach ($nodes as $number => $content) {
                    $key = str_replace('.', '-', (string)$number);
                    $generatedBlocks[] = array(
                        'destination_key' => $key . '-heading',
                        'block_type' => 'paragraph',
                        'paragraph_style' => $number === '5.6' ? 'subtitle_1' : 'subtitle_2',
                        'heading_number' => (string)$number,
                    );
                    $generatedBlocks[] = array(
                        'destination_key' => $key . '-body',
                        'block_type' => 'paragraph',
                        'paragraph_style' => 'body',
                        'content' => (string)$content,
                    );
                }
                if (count($generatedBlocks) !== (int)$operation['expected_generated_block_count']) {
                    throw new RuntimeException('Section 5.6 generated-block count is not deterministic.');
                }
                $mapping = array();
                $walk = function (array $tree) use (&$walk, &$mapping): void {
                    foreach ($tree as $node) {
                        $mapping[] = array(
                            'future_number' => (string)$node['number'],
                            'action' => (string)$node['action'],
                            'source_references' => array_values((array)$node['source_references']),
                        );
                        $walk((array)$node['children']);
                    }
                };
                $walk($futureTree);
                $operation['future_tree'] = $futureTree;
                $operation['complete_resulting_content'] = $nodes;
                $operation['generated_blocks'] = $generatedBlocks;
                $operation['source_to_future_mapping'] = $mapping;
                $operation['future_tree_fingerprint'] = hash('sha256', $this->json($futureTree));
                $operation['resulting_content_fingerprint'] = hash('sha256', $this->json($nodes));
                $operation['atomic_result_fingerprint'] = hash('sha256', $this->json(array(
                    'future_tree' => $futureTree,
                    'generated_blocks' => $generatedBlocks,
                    'mapping' => $mapping,
                )));
            } else {
                $operation['operation_payload_fingerprint'] = hash('sha256', $this->json($planned));
            }
            $operations[] = $operation;
        }

        $preservedInvariant = $this->preservedBlockInvariant($targets, $operations, $minimality);
        $sourceSnapshotFingerprint = hash('sha256', $this->json(array(
            'manual' => $manual,
            'ranges' => array_map(
                static fn(array $target): string => (string)$target['range_fingerprint'],
                $targets
            ),
        )));
        $operationTypeCounts = array_count_values(array_map(
            static fn(array $operation): string => (string)$operation['operation_type'],
            $operations
        ));
        $package = array(
            'schema' => 'ipca.manual-change-minimal-operation-package.v1',
            'status' => 'PENDING_OPERATION_REVIEW',
            'human_approval_status' => 'pending',
            'source_manual' => $manual,
            'source_snapshot_fingerprint' => $sourceSnapshotFingerprint,
            'operations' => $operations,
            'operation_count' => count($operations),
            'operation_types' => array_keys($operationTypeCounts),
            'operation_type_counts' => $operationTypeCounts,
            'minimality_report' => $minimality,
            'preserved_block_invariant' => $preservedInvariant,
            'amendment_reviewer_status' => (string)$amendmentReview['status'],
            'approved_wording_fingerprints' => array_map(
                fn(array $draft): string => hash('sha256', $this->json((array)$draft['nodes'])),
                $drafts
            ),
            'legacy_reference_status' => (array)($amendmentReview['legacy_reference_status'] ?? array()),
            'guardrails' => array(
                'apply_authorized' => false,
                'applied' => false,
                'production_mutated' => false,
                'working_revision_cloned' => false,
                'destination_version_id_unassigned' => true,
                'section_5_6_atomic_all_or_nothing' => true,
                'all_preconditions_must_be_revalidated' => true,
                'preserved_blocks_must_retain_identity_and_payload' => true,
                'apply_requires_separate_human_approval' => true,
            ),
        );
        $package['provisional_package_fingerprint'] = hash('sha256', $this->json($package));
        return $package;
    }

    /**
     * Seal a package after independent operation-result review.
     *
     * @param array<string,mixed> $package
     * @param array<string,mixed> $operationReview
     * @return array<string,mixed>
     */
    public function sealReviewedPackage(array $package, array $operationReview): array
    {
        if (($package['status'] ?? '') !== 'PENDING_OPERATION_REVIEW'
            || ($operationReview['status'] ?? '') !== 'READY_FOR_HUMAN_REVIEW'
            || ($operationReview['issues'] ?? array()) !== array()) {
            throw new RuntimeException('Only a clean independently reviewed operation package may be sealed.');
        }
        $package['status'] = 'READY_FOR_HUMAN_REVIEW';
        $package['operation_reviewer'] = $operationReview;
        $package['operation_package_fingerprint'] = hash('sha256', $this->json($package));
        return $package;
    }

    /**
     * @param array<string,array<string,mixed>> $targets
     * @param list<array<string,mixed>> $operations
     * @param array<string,array<string,int>> $minimality
     * @return array<string,mixed>
     */
    private function preservedBlockInvariant(array $targets, array $operations, array $minimality): array
    {
        $targeted = array_fill_keys(self::SECTIONS, array());
        foreach ($operations as $operation) {
            $section = (string)$operation['section_number'];
            if (($operation['operation_type'] ?? '') === 'RESTRUCTURE_SECTION_WITH_CONTENT') {
                $targeted[$section] = array_values(array_map('intval', (array)$operation['source_block_ids']));
            } elseif (isset($operation['block_id'])) {
                $targeted[$section][] = (int)$operation['block_id'];
            }
        }
        $sections = array();
        $allVerified = true;
        foreach (self::SECTIONS as $section) {
            $sourceIds = array_values(array_map('intval', (array)$targets[$section]['block_ids']));
            $changedIds = array_values(array_unique($targeted[$section]));
            $preservedIds = array_values(array_diff($sourceIds, $changedIds));
            $expectedPreserved = (int)$minimality[$section]['preserved_untouched'];
            $verified = count($preservedIds) === $expectedPreserved;
            $allVerified = $allVerified && $verified;
            $sections[$section] = array(
                'verified' => $verified,
                'preserved_block_ids' => $preservedIds,
                'explicitly_targeted_source_block_ids' => $changedIds,
                'preserved_identity_fields' => array('id', 'content_hash', 'stable_anchor', 'structured_metadata'),
            );
        }
        if (!$allVerified) {
            throw new RuntimeException('A preserved-block invariant or minimality count failed.');
        }
        return array(
            'verified' => true,
            'rule' => 'Every untargeted PRESERVED block retains ID, content hash, stable anchor, and structured metadata.',
            'sections' => $sections,
        );
    }

    /** @param array<string,mixed> $target */
    private function assertTarget(string $sectionNumber, array $target): void
    {
        if ((int)($target['section_id'] ?? 0) <= 0
            || (int)($target['sort_from'] ?? 0) <= 0
            || (int)($target['sort_to'] ?? 0) < (int)($target['sort_from'] ?? 0)
            || (array)($target['block_ids'] ?? array()) === array()) {
            throw new RuntimeException("Section {$sectionNumber} has an invalid canonical target range.");
        }
        foreach (array('range_fingerprint', 'first_block_hash', 'last_block_hash') as $field) {
            if (preg_match('/^[a-f0-9]{64}$/', (string)($target[$field] ?? '')) !== 1) {
                throw new RuntimeException("Section {$sectionNumber} lacks a valid {$field} precondition.");
            }
        }
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
