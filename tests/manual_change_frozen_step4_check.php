<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/publishing/BooksManualsChangePlanService.php';
require_once dirname(__DIR__) . '/src/publishing/BooksManualsChangeReviewResolutionService.php';

function frozenStep4Assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec(
    'CREATE TABLE ipca_manual_ai_architect_plans (
        id INTEGER PRIMARY KEY,
        owner_id INTEGER NOT NULL,
        stage TEXT NOT NULL,
        status TEXT,
        updated_by INTEGER
    )'
);
$db->exec(
    'CREATE TABLE ipca_manual_ai_architect_drafts (
        id INTEGER PRIMARY KEY,
        plan_id INTEGER NOT NULL,
        draft_version INTEGER NOT NULL,
        status TEXT NOT NULL,
        source_fingerprint TEXT,
        content_fingerprint TEXT,
        draft_payload_json TEXT NOT NULL
    )'
);
$db->exec(
    'CREATE TABLE ipca_manual_ai_architect_decision_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        plan_id INTEGER NOT NULL,
        event_type TEXT
    )'
);
$db->exec(
    'CREATE TABLE ipca_manual_ai_architect_structure_proposals (
        id INTEGER PRIMARY KEY,
        plan_id INTEGER NOT NULL,
        proposal_version INTEGER NOT NULL,
        status TEXT NOT NULL
    )'
);
$db->exec(
    'CREATE TABLE ipca_manual_ai_architect_structure_nodes (
        id INTEGER PRIMARY KEY,
        structure_proposal_id INTEGER NOT NULL,
        decision_status TEXT
    )'
);
$db->exec(
    'CREATE TABLE ipca_manual_ai_architect_review_baselines (
        id INTEGER PRIMARY KEY,
        plan_id INTEGER NOT NULL,
        review_id INTEGER NOT NULL,
        draft_baseline_json TEXT NOT NULL
    )'
);

$plans = new BooksManualsChangePlanService($db);
$payload = array(
    'section_drafts' => array(
        '5.6' => array('section_number' => '5.6', 'nodes' => array('5.6.3' => 'Text')),
    ),
    'decisions' => array(
        '5.6' => array('decision' => 'accepted', 'actor_id' => 7),
    ),
    'wizard_status' => 'accepted',
);
$payloadJson = json_encode(
    $payload,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
);
$db->prepare(
    'INSERT INTO ipca_manual_ai_architect_plans (id,owner_id,stage,status)
     VALUES (20,7,\'review\',\'ready_for_review\')'
)->execute();
$db->prepare(
    'INSERT INTO ipca_manual_ai_architect_drafts
     (id,plan_id,draft_version,status,source_fingerprint,content_fingerprint,draft_payload_json)
     VALUES (15,20,15,\'generated\',\'source\',?,?)'
)->execute(array($plans->draftPayloadFingerprint($payload), $payloadJson));
$db->exec(
    "INSERT INTO ipca_manual_ai_architect_structure_proposals
     (id,plan_id,proposal_version,status) VALUES (3,20,1,'approved')"
);
$db->exec(
    "INSERT INTO ipca_manual_ai_architect_structure_nodes
     (id,structure_proposal_id,decision_status) VALUES (4,3,'accepted')"
);

$structureBlocked = false;
try {
    $plans->acceptStructure(20, 7);
} catch (RuntimeException $error) {
    $structureBlocked = str_contains($error->getMessage(), 'frozen');
}
frozenStep4Assert(
    $structureBlocked
        && (string)$db->query(
            'SELECT stage FROM ipca_manual_ai_architect_plans WHERE id=20'
        )->fetchColumn() === 'review',
    'A stale Step 3 acceptance endpoint reopened the accepted Wizard baseline.'
);
$before = $db->query(
    'SELECT draft_payload_json,content_fingerprint
     FROM ipca_manual_ai_architect_drafts WHERE id=15'
)->fetch(PDO::FETCH_ASSOC);
$blocked = false;
try {
    $plans->recordDraftDecision(20, '5.6', 'rejected', 7);
} catch (RuntimeException $error) {
    $blocked = str_contains($error->getMessage(), 'frozen');
}
$after = $db->query(
    'SELECT draft_payload_json,content_fingerprint
     FROM ipca_manual_ai_architect_drafts WHERE id=15'
)->fetch(PDO::FETCH_ASSOC);
frozenStep4Assert(
    $blocked && $before === $after,
    'Step 4 decisions remained mutable after Independent Review began.'
);

$mysqlNormalizedPayload = array(
    'decisions' => array(
        '5.6' => array('actor_id' => 7, 'decision' => 'accepted'),
    ),
    'section_drafts' => array(
        '5.6' => array('nodes' => array('5.6.3' => 'Text'), 'section_number' => '5.6'),
    ),
    'wizard_status' => 'accepted',
);
$mysqlNormalizedJson = json_encode(
    $mysqlNormalizedPayload,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
);
$db->prepare(
    'UPDATE ipca_manual_ai_architect_drafts SET draft_payload_json=? WHERE id=15'
)->execute(array($mysqlNormalizedJson));
$replay = $plans->acceptDrafts(20, 7);
frozenStep4Assert(
    (int)($replay['draft_id'] ?? 0) === 15
        && (string)($replay['stage'] ?? '') === 'review'
        && $plans->draftPayloadFingerprint($payload)
            === $plans->draftPayloadFingerprint($mysqlNormalizedPayload)
        && (int)$db->query(
            "SELECT COUNT(*) FROM ipca_manual_ai_architect_decision_events
             WHERE event_type='DRAFT_AMENDMENTS_ACCEPTED'"
        )->fetchColumn() === 0,
    'A MySQL-normalized Step 4 payload was not an idempotent read of accepted state.'
);

$legacyFingerprint = hash('sha256', $payloadJson);
$legacyBaseline = array(
    'id' => 15,
    'source_fingerprint' => 'source',
    'content_fingerprint' => $legacyFingerprint,
    'draft_payload_json' => $mysqlNormalizedPayload,
);
$db->prepare(
    'UPDATE ipca_manual_ai_architect_drafts SET content_fingerprint=? WHERE id=15'
)->execute(array($legacyFingerprint));
$db->prepare(
    'INSERT INTO ipca_manual_ai_architect_review_baselines
     (id,plan_id,review_id,draft_baseline_json) VALUES (1,20,9,?)'
)->execute(array(json_encode(
    $legacyBaseline,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
)));
$legacyReplay = $plans->acceptDrafts(20, 7);
frozenStep4Assert(
    (int)($legacyReplay['draft_id'] ?? 0) === 15,
    'A legacy accepted fingerprint did not validate against its frozen semantic baseline.'
);
$resolution = new BooksManualsChangeReviewResolutionService($db, $plans);
$assertFrozen = new ReflectionMethod($resolution, 'assertFrozenDraftCurrent');
$assertFrozen->invoke($resolution, 20, 9);

$tamperedPayload = $payload;
$tamperedPayload['decisions']['5.6']['decision'] = 'rejected';
$tamperedJson = json_encode(
    $tamperedPayload,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
);
$db->prepare(
    'UPDATE ipca_manual_ai_architect_drafts
     SET draft_payload_json=?,content_fingerprint=? WHERE id=15'
)->execute(array($tamperedJson, hash('sha256', $tamperedJson)));
$corruptionBlocked = false;
try {
    $plans->acceptDrafts(20, 7);
} catch (RuntimeException $error) {
    $corruptionBlocked = str_contains($error->getMessage(), 'frozen review state');
}
frozenStep4Assert(
    $corruptionBlocked,
    'Repeated acceptance approved a changed Step 4 decision as an idempotent replay.'
);

$db->exec("UPDATE ipca_manual_ai_architect_plans SET stage='drafting' WHERE id=20");
$draftingPayload = $payload;
$draftingPayload['wizard_status'] = 'draft';
$draftingPayload['decisions'] = array();
$draftingJson = json_encode(
    $draftingPayload,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
);
$db->prepare(
    'UPDATE ipca_manual_ai_architect_drafts
     SET draft_payload_json=?,content_fingerprint=? WHERE id=15'
)->execute(array($draftingJson, hash('sha256', $draftingJson)));
$decision = $plans->recordDraftDecision(20, '5.6', 'accepted', 7);
$accepted = $plans->acceptDrafts(20, 7);
frozenStep4Assert(
    (string)($decision['decision'] ?? '') === 'accepted'
        && (string)($accepted['stage'] ?? '') === 'review'
        && (string)$db->query(
            'SELECT stage FROM ipca_manual_ai_architect_plans WHERE id=20'
        )->fetchColumn() === 'review'
        && (int)$db->query(
            "SELECT COUNT(*) FROM ipca_manual_ai_architect_decision_events
             WHERE event_type='DRAFT_AMENDMENTS_ACCEPTED'"
        )->fetchColumn() === 1,
    'Forward Step 4 acceptance did not transition exactly once into review.'
);

$frozenPayload = (string)$db->query(
    'SELECT draft_payload_json FROM ipca_manual_ai_architect_drafts WHERE id=15'
)->fetchColumn();
$blockedAgain = false;
try {
    $plans->recordDraftDecision(20, '5.6', 'rejected', 7);
} catch (RuntimeException $error) {
    $blockedAgain = str_contains($error->getMessage(), 'frozen');
}
frozenStep4Assert(
    $blockedAgain
        && (string)$db->query(
            'SELECT draft_payload_json FROM ipca_manual_ai_architect_drafts WHERE id=15'
        )->fetchColumn() === $frozenPayload,
    'Accepted wording was mutable after the forward Step 4 transition.'
);

$resolutionSource = file_get_contents(
    dirname(__DIR__) . '/src/publishing/BooksManualsChangeReviewResolutionService.php'
) ?: '';
$apiSource = file_get_contents(
    dirname(__DIR__) . '/public/admin/api/books_manuals_change_architect_api.php'
) ?: '';
$migration = file_get_contents(
    dirname(__DIR__) . '/scripts/sql/2026_08_20_manual_change_review_convergence.sql'
) ?: '';
frozenStep4Assert(
    str_contains($resolutionSource, '$this->assertFrozenDraftCurrent($planId, $reviewId);')
        && str_contains($resolutionSource, "\$state['hard_blockers']")
        && str_contains($resolutionSource, "'Accepted amendment package'")
        && str_contains($apiSource, 'Interactive Author correction is disabled')
        && str_contains(
            $migration,
            'UNIQUE KEY uk_imaa_review_baseline_hash (plan_id, review_id, baseline_fingerprint)'
        ),
    'Independent Review lacks a frozen baseline, visible global fallback, or forward-only API guard.'
);

echo "PASS: Accepted Step 4 state is frozen and forward-only in Independent Review.\n";
