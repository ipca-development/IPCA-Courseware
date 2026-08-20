<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../src/RuntimeSecrets.php';
RuntimeSecrets::ensureCliEnvLoaded();
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/publishing/BooksManualsChangePlanService.php';
require_once __DIR__ . '/../src/publishing/BooksManualsChangeReviewResolutionService.php';
require_once __DIR__ . '/../src/publishing/BooksManualsChangeReviewerService.php';

$options = getopt('', array('plan-id:', 'patch-id:', 'actor-id:', 'repair'));
$planId = (int)($options['plan-id'] ?? 0);
$failedPatchId = (int)($options['patch-id'] ?? 0);
$actorId = (int)($options['actor-id'] ?? 0);
if ($planId <= 0 || $failedPatchId <= 0 || !array_key_exists('repair', $options)) {
    throw new InvalidArgumentException(
        'Use --plan-id=<id> --patch-id=<failed-patch-id> --repair for deterministic restoration.'
    );
}

$pdo = cw_db();
$plans = new BooksManualsChangePlanService($pdo);
$plan = $plans->getPlan($planId);
if ($actorId <= 0) {
    $actorId = (int)($plan['owner_id'] ?? 0);
}
$reviewStmt = $pdo->prepare(
    'SELECT MAX(id) FROM ipca_manual_ai_architect_reviews WHERE plan_id=?'
);
$reviewStmt->execute(array($planId));
$reviewId = (int)$reviewStmt->fetchColumn();
if ($reviewId <= 0 || $actorId <= 0) {
    throw new RuntimeException('Plan owner or Independent Review provenance is unavailable.');
}

$snapshot = static function (PDO $pdo, int $planId, int $versionId): array {
    $digest = static function (PDO $pdo, string $sql, array $params): array {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
        return array(
            'rows' => count($rows),
            'sha256' => hash(
                'sha256',
                json_encode(
                    $rows,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                )
            ),
        );
    };
    return array(
        'step_2' => $digest(
            $pdo,
            'SELECT * FROM ipca_manual_ai_architect_impacts WHERE plan_id=? ORDER BY id',
            array($planId)
        ),
        'step_3' => $digest(
            $pdo,
            'SELECT n.* FROM ipca_manual_ai_architect_structure_nodes n
             JOIN ipca_manual_ai_architect_structure_proposals p
               ON p.id=n.structure_proposal_id
             WHERE p.plan_id=? ORDER BY n.id',
            array($planId)
        ),
        'accepted_step_4' => $digest(
            $pdo,
            'SELECT * FROM ipca_manual_ai_architect_drafts
             WHERE plan_id=? AND draft_version=1 ORDER BY id',
            array($planId)
        ),
        'manual_version' => $digest(
            $pdo,
            'SELECT * FROM ipca_publishing_book_versions WHERE id=?',
            array($versionId)
        ),
        'manual_sections' => $digest(
            $pdo,
            'SELECT * FROM ipca_publishing_book_sections
             WHERE book_version_id=? ORDER BY sort_order,id',
            array($versionId)
        ),
        'manual_blocks' => $digest(
            $pdo,
            'SELECT b.* FROM ipca_publishing_book_blocks b
             JOIN ipca_publishing_book_sections s ON s.id=b.section_id
             WHERE s.book_version_id=?
             ORDER BY s.sort_order,s.id,b.sort_order,b.id',
            array($versionId)
        ),
    );
};

$versionId = (int)($plan['primary_manual_version_id']
    ?? $plan['primary_book_version_id']
    ?? $plan['book_version_id']
    ?? 0);
$before = $snapshot($pdo, $planId, $versionId);
$service = new BooksManualsChangeReviewResolutionService($pdo, $plans);
$result = $service->repairHistoricalPatchScope(
    $planId,
    $reviewId,
    $failedPatchId,
    $actorId,
    new BooksManualsChangeReviewerService($pdo, $plans),
    array('3.3.2', '5.6.1', '5.6.4', '5.7', '5.7.3')
);
$after = $snapshot($pdo, $planId, $versionId);
$patch = (array)$result['patch'];
$payload = (array)($patch['proposed_payload_json'] ?? array());
$verification = (array)($patch['verification_json'] ?? array());
$state = (array)$result['state'];

echo json_encode(array(
    'schema' => 'ipca.manual-change-historical-scope-repair-report.v1',
    'plan_id' => $planId,
    'repair_type' => 'HISTORICAL_SCOPE_REPAIR',
    'source_patch_id' => $failedPatchId,
    'patch_id' => (int)($patch['id'] ?? 0),
    'resulting_draft_id' => (int)($patch['resulting_draft_id'] ?? 0),
    'patch_status' => (string)($patch['status'] ?? ''),
    'restored_nodes' => array_values((array)($payload['allowed_repair_nodes'] ?? array())),
    'restored_node_fingerprints' =>
        (array)($payload['restored_node_fingerprints'] ?? array()),
    'reconciliation' => (array)($verification['reconciliation'] ?? array()),
    'check_counts' => (array)($state['check_counts'] ?? array()),
    'resolution_counts' => (array)($state['resolution_counts'] ?? array()),
    'hard_blockers' => (int)($state['hard_blockers'] ?? 0),
    'review_divergence_detected' => !empty($state['review_divergence_detected']),
    'accepted_steps_2_4_unchanged' =>
        hash_equals(
            hash('sha256', json_encode($before, JSON_THROW_ON_ERROR)),
            hash('sha256', json_encode($after, JSON_THROW_ON_ERROR))
        ),
    'source_manual_unchanged' =>
        $before['manual_version'] === $after['manual_version']
        && $before['manual_sections'] === $after['manual_sections']
        && $before['manual_blocks'] === $after['manual_blocks'],
    'architect_rerun_performed' => false,
    'manual_content_mutated' => false,
    'production_applied' => false,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
    . PHP_EOL;
