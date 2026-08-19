<?php
declare(strict_types=1);

require_once __DIR__ . '/BooksManualsChangeArchitectService.php';
require_once __DIR__ . '/BooksManualsChangeAuthorService.php';
require_once __DIR__ . '/BooksManualsChangeReviewerService.php';
require_once __DIR__ . '/BooksManualsChangeStructureService.php';
require_once __DIR__ . '/BooksManualsChangeOperationService.php';

/**
 * Role-separated Change Plan orchestration.
 *
 * Each returned envelope is a distinct job contract. UI/API queue integration
 * can persist and execute these envelopes without merging role prompts.
 */
final class BooksManualsChangePlanOrchestratorService
{
    public function __construct(
        private PDO $pdo,
        private ?BooksManualsChangeArchitectService $architect = null,
        private ?BooksManualsChangeStructureService $structure = null,
        private ?BooksManualsChangeAuthorService $author = null,
        private ?BooksManualsChangeReviewerService $reviewer = null,
        private ?BooksManualsChangeOperationService $operations = null
    ) {
        $this->architect ??= new BooksManualsChangeArchitectService($pdo);
        $this->structure ??= new BooksManualsChangeStructureService($pdo);
        $this->author ??= new BooksManualsChangeAuthorService($pdo);
        $this->reviewer ??= new BooksManualsChangeReviewerService($pdo);
        $this->operations ??= new BooksManualsChangeOperationService();
    }

    /**
     * @param list<array<string,mixed>|string> $evidence
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function runArchitectJob(
        int $planId,
        array $evidence,
        array $options,
        int $actorUserId
    ): array {
        return array(
            'job_role' => 'ARCHITECT',
            'prompt_version' => 'manual-change-architect-checkpoint-v1',
            'result' => $this->architect->runCheckpoint(
                $planId,
                $evidence,
                $options,
                $actorUserId
            ),
        );
    }

    /** @return array<string,mixed> */
    public function prepareAuthorJob(int $planId): array
    {
        return array(
            'job_role' => 'AMENDMENT_AUTHOR',
            'prompt_version' => BooksManualsChangeAuthorService::PROMPT_VERSION,
            'input' => $this->author->buildAuthorizedBrief($planId),
        );
    }

    /**
     * @param array<string,array<string,mixed>> $sectionDrafts
     * @param list<array<string,mixed>> $lifecycle
     * @return array<string,mixed>
     */
    public function runAuthorProposalJob(
        int $planId,
        array $sectionDrafts,
        array $lifecycle,
        array $validationEvidence = array()
    ): array {
        $brief = $this->author->buildAuthorizedBrief($planId);
        return array(
            'job_role' => 'AMENDMENT_AUTHOR',
            'prompt_version' => BooksManualsChangeAuthorService::PROMPT_VERSION,
            'result' => $this->author->assembleAmendmentProposal(
                $brief,
                $sectionDrafts,
                $lifecycle,
                $validationEvidence
            ),
        );
    }

    /** @param array<string,mixed> $proposal @return array<string,mixed> */
    public function runAmendmentReviewJob(array $proposal): array
    {
        return array(
            'job_role' => 'INDEPENDENT_REVIEWER',
            'prompt_version' => BooksManualsChangeReviewerService::PROMPT_VERSION,
            'result' => $this->reviewer->verifyAmendmentProposal($proposal),
        );
    }

    /** @param array<string,mixed> $proposal @return array<string,mixed> */
    public function persistStructureJob(
        int $planId,
        array $proposal,
        int $actorUserId
    ): array {
        return array(
            'job_role' => 'STRUCTURE_ARCHITECT',
            'prompt_version' => 'manual-change-structure-v1',
            'structure_proposal_id' => $this->structure->persistProposal(
                $planId,
                $proposal,
                $actorUserId
            ),
        );
    }

    /** @return array<string,mixed> */
    public function runReviewerGateJob(int $planId): array
    {
        return array(
            'job_role' => 'INDEPENDENT_REVIEWER',
            'prompt_version' => BooksManualsChangeReviewerService::PROMPT_VERSION,
            'result' => $this->reviewer->evaluateGovernanceGate($planId),
        );
    }

    /**
     * @param array<string,mixed> $canonicalSnapshot
     * @param array<string,mixed> $structureProposal
     * @param array<string,mixed> $amendmentProposal
     * @param array<string,mixed> $review
     * @return array<string,mixed>
     */
    public function runOperationPackageJob(
        array $canonicalSnapshot,
        array $structureProposal,
        array $amendmentProposal,
        array $review
    ): array {
        return array(
            'job_role' => 'CANONICAL_OPERATION_ARCHITECT',
            'prompt_version' => 'manual-change-operations-v1',
            'result' => $this->operations->buildPackage(
                $canonicalSnapshot,
                $structureProposal,
                $amendmentProposal,
                $review
            ),
        );
    }

    /**
     * @param array<string,mixed> $canonicalSnapshot
     * @param array<string,mixed> $structureProposal
     * @param array<string,mixed> $amendmentProposal
     * @param array<string,mixed> $amendmentReview
     * @param array<string,mixed> $minimalPlan
     * @return array<string,mixed>
     */
    public function runMinimalOperationPackageJob(
        array $canonicalSnapshot,
        array $structureProposal,
        array $amendmentProposal,
        array $amendmentReview,
        array $minimalPlan
    ): array {
        $provisional = $this->operations->buildMinimalPackage(
            $canonicalSnapshot,
            $structureProposal,
            $amendmentProposal,
            $amendmentReview,
            $minimalPlan
        );
        $operationReview = $this->reviewer->verifyMinimalOperationPackage(
            $provisional,
            $amendmentProposal
        );
        return array(
            'job_role' => 'CANONICAL_OPERATION_ARCHITECT',
            'prompt_version' => 'manual-change-minimal-operations-v1',
            'result' => $this->operations->sealReviewedPackage(
                $provisional,
                $operationReview
            ),
        );
    }
}
