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
require_once __DIR__ . '/../src/publishing/BooksManualsChangeAuthorService.php';

$options = getopt('', array('plan-id:', 'check-id:', 'actor-id:', 'prepare'));
$planId = (int)($options['plan-id'] ?? 0);
$checkId = trim((string)($options['check-id'] ?? ''));
$actorId = (int)($options['actor-id'] ?? 0);
if ($planId <= 0 || $checkId === '' || !array_key_exists('prepare', $options)) {
    throw new InvalidArgumentException(
        'Use --plan-id=<id> --check-id=<stable-check-id> --prepare.'
    );
}

$pdo = cw_db();
$plans = new BooksManualsChangePlanService($pdo);
$plan = $plans->loadPlan($planId);
if ($actorId <= 0) {
    $actorId = (int)($plan['owner_id'] ?? 0);
}
$resolution = new BooksManualsChangeReviewResolutionService($pdo, $plans);
$state = $resolution->state($planId);
if (!empty($state['review_divergence_detected']) || (int)($state['hard_blockers'] ?? 0) > 0) {
    throw new RuntimeException('Resolve review integrity blockers before preparing content corrections.');
}
$findings = array_values(array_filter(
    (array)($state['findings'] ?? array()),
    static fn(array $finding): bool =>
        (string)($finding['check_id'] ?? '') === $checkId
        && strtoupper((string)($finding['resolution_status'] ?? '')) === 'UNRESOLVED'
        && (string)($finding['finding_class'] ?? '')
            === BooksManualsChangeReviewResolutionService::TARGETED_AUTHOR_CORRECTION
));
if (count($findings) !== 1) {
    throw new RuntimeException('Exactly one unresolved targeted check must match the preview request.');
}
$baselineId = (int)($state['baseline_id'] ?? 0);
$drafts = array_values(array_filter(
    (array)($plan['drafts'] ?? array()),
    static function (array $draft): bool {
        $payload = (array)($draft['draft_payload_json'] ?? array());
        return (string)($draft['status'] ?? '') === 'generated'
            && (string)($payload['wizard_status'] ?? '') === 'accepted';
    }
));
$draft = $drafts === array() ? array() : $drafts[array_key_last($drafts)];
if ($baselineId <= 0 || $draft === array()) {
    throw new RuntimeException('The current frozen review candidate is unavailable.');
}
$governedFacts = array_values(array_map(
    static fn(array $answer): array => (array)($answer['governed_fact_json'] ?? array()),
    (array)($state['answers'] ?? array())
));
$author = new BooksManualsChangeAuthorService($pdo, $plans);
$patchResult = $author->generateTargetedPatch(
    $planId,
    $actorId,
    (array)$draft['draft_payload_json'],
    $findings,
    $governedFacts
);
$patch = $resolution->persistTargetedPatch(
    $planId,
    $baselineId,
    (int)$draft['id'],
    array((int)$findings[0]['id']),
    $patchResult,
    $actorId
);
$preview = array();
foreach ((array)($patchResult['changed_sections'] ?? array()) as $section => $change) {
    $preview[(string)$section] = array(
        'changed_nodes' => array_values((array)($change['changed_nodes'] ?? array())),
        'current_accepted_wording' => (array)($change['before']['nodes'] ?? array()),
        'proposed_minimal_correction' => (array)($change['after']['nodes'] ?? array()),
        'why_required' => (string)($change['reason'] ?? ''),
    );
}

echo json_encode(array(
    'schema' => 'ipca.manual-change-content-correction-preview.v1',
    'plan_id' => $planId,
    'patch_id' => (int)($patch['id'] ?? 0),
    'patch_status' => (string)($patch['status'] ?? ''),
    'parent_draft_id' => (int)$draft['id'],
    'check_ids_expected_to_resolve' =>
        array_values((array)($patchResult['failed_check_ids'] ?? array())),
    'allowed_repair_nodes' =>
        array_values((array)($patchResult['allowed_repair_nodes'] ?? array())),
    'preview' => $preview,
    'frozen_unaffected_node_count' =>
        count((array)($patchResult['frozen_node_fingerprints'] ?? array())),
    'accepted_structure_unchanged' =>
        !empty($patchResult['accepted_structure_nodes_unchanged']),
    'lifecycle_unchanged' => !empty($patchResult['lifecycle_unchanged']),
    'human_acceptance_required' => true,
    'architect_rerun_performed' => false,
    'manual_content_mutated' => false,
    'production_applied' => false,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
    . PHP_EOL;
