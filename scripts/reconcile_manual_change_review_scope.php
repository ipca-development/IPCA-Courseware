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
require_once __DIR__ . '/../src/publishing/BooksManualsChangeReviewerService.php';
require_once __DIR__ . '/../src/publishing/BooksManualsChangeReviewResolutionService.php';

$options = getopt('', array('plan-id:', 'actor-id:', 'reconcile'));
$planId = (int)($options['plan-id'] ?? 0);
$actorId = (int)($options['actor-id'] ?? 0);
if ($planId <= 0 || !array_key_exists('reconcile', $options)) {
    throw new InvalidArgumentException(
        'Use --plan-id=<id> --reconcile to project Step 5 checks onto the accepted scope.'
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

$count = static function (PDO $pdo, string $table, int $planId): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE plan_id=?");
    $stmt->execute(array($planId));
    return (int)$stmt->fetchColumn();
};
$before = array(
    'drafts' => $count($pdo, 'ipca_manual_ai_architect_drafts', $planId),
    'review_baselines' => $count(
        $pdo,
        'ipca_manual_ai_architect_review_baselines',
        $planId
    ),
    'operations' => $count($pdo, 'ipca_manual_ai_architect_operations', $planId),
);
$service = new BooksManualsChangeReviewResolutionService($pdo, $plans);
$state = $service->reconcileAcceptedReviewScope(
    $planId,
    $reviewId,
    $actorId,
    new BooksManualsChangeReviewerService($pdo, $plans)
);
$after = array(
    'drafts' => $count($pdo, 'ipca_manual_ai_architect_drafts', $planId),
    'review_baselines' => $count(
        $pdo,
        'ipca_manual_ai_architect_review_baselines',
        $planId
    ),
    'operations' => $count($pdo, 'ipca_manual_ai_architect_operations', $planId),
);

echo json_encode(array(
    'schema' => 'ipca.manual-change-accepted-scope-reconciliation-report.v1',
    'plan_id' => $planId,
    'review_id' => $reviewId,
    'check_counts' => (array)($state['check_counts'] ?? array()),
    'resolution_counts' => (array)($state['resolution_counts'] ?? array()),
    'hard_blockers' => (int)($state['hard_blockers'] ?? 0),
    'ready_to_apply' => !empty($state['ready_to_apply']),
    'drafts_unchanged' => $before['drafts'] === $after['drafts'],
    'review_baselines_unchanged' =>
        $before['review_baselines'] === $after['review_baselines'],
    'operations_unchanged' => $before['operations'] === $after['operations'],
    'architect_rerun_performed' => false,
    'manual_content_mutated' => false,
    'production_applied' => false,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
    . PHP_EOL;
