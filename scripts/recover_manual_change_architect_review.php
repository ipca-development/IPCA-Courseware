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

$options = getopt('', array('plan-id:', 'actor-id:', 'recover'));
$planId = (int)($options['plan-id'] ?? 0);
$actorId = (int)($options['actor-id'] ?? 0);
$recover = array_key_exists('recover', $options);
$pdo = cw_db();
$plans = new BooksManualsChangePlanService($pdo);

if ($planId <= 0) {
    $stmt = $pdo->query(
        "SELECT id FROM ipca_manual_ai_architect_plans
         WHERE title LIKE '%SMS%' OR change_request LIKE '%ECCAIRS%'
         ORDER BY updated_at DESC,id DESC LIMIT 1"
    );
    $planId = (int)$stmt->fetchColumn();
}
if ($planId <= 0) {
    throw new RuntimeException('No SMS/ECCAIRS Change Plan was found.');
}
$plan = $plans->loadPlan($planId);
if ($actorId <= 0) {
    $actorId = (int)($plan['owner_id'] ?? 0);
}

$acceptedImpacts = array_values(array_filter(
    (array)$plan['impacts'],
    static fn(array $row): bool => (string)($row['status'] ?? '') === 'approved'
));
$approvedStructures = array_values(array_filter(
    (array)$plan['structure_proposals'],
    static fn(array $row): bool => (string)($row['status'] ?? '') === 'approved'
));
$acceptedNodes = array_values(array_filter(
    (array)$plan['structure_nodes'],
    static fn(array $row): bool => (string)($row['decision_status'] ?? '') === 'accepted'
));
$acceptedDrafts = array_values(array_filter(
    (array)$plan['drafts'],
    static function (array $row): bool {
        $payload = is_array($row['draft_payload_json'] ?? null) ? $row['draft_payload_json'] : array();
        return (string)($row['status'] ?? '') === 'generated'
            && (string)($payload['wizard_status'] ?? '') === 'accepted';
    }
));
$reviews = array_values((array)$plan['reviews']);
$review = $reviews === array() ? array() : $reviews[array_key_last($reviews)];
$reviewPayload = is_array($review['review_payload_json'] ?? null)
    ? $review['review_payload_json']
    : array();
$prepared = is_array($reviewPayload['prepared_result'] ?? null)
    ? $reviewPayload['prepared_result']
    : $reviewPayload;

$snapshot = static function (
    array $impacts,
    array $structures,
    array $nodes,
    array $drafts
): array {
    $data = array(
        'step_2' => array(
            'impact_ids' => array_map('intval', array_column($impacts, 'id')),
            'statuses' => array_column($impacts, 'status', 'id'),
        ),
        'step_3' => array(
            'proposal_ids' => array_map('intval', array_column($structures, 'id')),
            'structure_fingerprints' => array_column($structures, 'structure_fingerprint', 'id'),
            'accepted_node_ids' => array_map('intval', array_column($nodes, 'id')),
            'node_fingerprints' => array_column($nodes, 'node_fingerprint', 'id'),
        ),
        'step_4' => array(
            'draft_ids' => array_map('intval', array_column($drafts, 'id')),
            'content_fingerprints' => array_column($drafts, 'content_fingerprint', 'id'),
        ),
    );
    $data['fingerprint'] = hash(
        'sha256',
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
    );
    return $data;
};

$before = $snapshot($acceptedImpacts, $approvedStructures, $acceptedNodes, $acceptedDrafts);
$regressions = array();
if ($acceptedImpacts === array()) {
    $regressions[] = 'No approved Step 2 impact baseline remains.';
}
if ($approvedStructures === array() || $acceptedNodes === array()) {
    $regressions[] = 'The accepted Step 3 structure baseline is missing or was superseded.';
}
if ($acceptedDrafts === array()) {
    $regressions[] = 'The accepted Step 4 wording baseline is missing, abandoned, or overwritten.';
}
if ($review === array()) {
    $regressions[] = 'No Independent Review record is available to migrate.';
}

$state = array();
if ($recover && $regressions === array()) {
    $resolution = new BooksManualsChangeReviewResolutionService($pdo, $plans);
    $state = $resolution->initializeReview(
        $planId,
        (int)$review['id'],
        $prepared,
        $actorId
    );
}

$freshPlan = $plans->loadPlan($planId);
$after = $snapshot(
    array_values(array_filter((array)$freshPlan['impacts'], static fn(array $row): bool => (string)($row['status'] ?? '') === 'approved')),
    array_values(array_filter((array)$freshPlan['structure_proposals'], static fn(array $row): bool => (string)($row['status'] ?? '') === 'approved')),
    array_values(array_filter((array)$freshPlan['structure_nodes'], static fn(array $row): bool => (string)($row['decision_status'] ?? '') === 'accepted')),
    array_values(array_filter((array)$freshPlan['drafts'], static function (array $row): bool {
        $payload = is_array($row['draft_payload_json'] ?? null) ? $row['draft_payload_json'] : array();
        return (string)($row['status'] ?? '') === 'generated'
            && (string)($payload['wizard_status'] ?? '') === 'accepted';
    }))
);

echo json_encode(array(
    'schema' => 'ipca.manual-change-review-recovery-report.v1',
    'plan_id' => $planId,
    'mode' => $recover ? 'RECOVER_REVIEW_METADATA' : 'REPORT_ONLY',
    'manual_content_mutated' => false,
    'architect_rerun_performed' => false,
    'accepted_baseline_before' => $before,
    'accepted_baseline_after' => $after,
    'accepted_baselines_preserved' => hash_equals($before['fingerprint'], $after['fingerprint']),
    'regressions_detected_before_mutation' => $regressions,
    'review_state' => $state,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

if ($regressions !== array()) {
    exit(2);
}
