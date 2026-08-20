<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/publishing/BooksManualsChangeAuthorService.php';
require_once $root . '/src/publishing/BooksManualsChangeReviewerService.php';
require_once $root . '/src/publishing/BooksManualsChangeReviewResolutionService.php';

function stableCheckAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$fixture = require $root . '/tests/fixtures/manual_change_architect_sms_amendment_readable.php';
$pdo = new PDO('sqlite::memory:');
$author = new BooksManualsChangeAuthorService($pdo);
$proposal = $author->assembleAmendmentProposal(
    $fixture['authorization'],
    $fixture['section_drafts'],
    $fixture['lifecycle'],
    array('legacy_status' => $fixture['legacy_status'])
);
$reviewer = new BooksManualsChangeReviewerService($pdo);
$first = $reviewer->verifyReadableAmendmentProposal($proposal);
$second = $reviewer->verifyReadableAmendmentProposal($proposal);
$firstIds = array_column((array)$first['review_checks'], 'check_id');
$secondIds = array_column((array)$second['review_checks'], 'check_id');
stableCheckAssert($firstIds === $secondIds, 'Stable check IDs changed across identical review cycles.');
stableCheckAssert(count($firstIds) === count(array_unique($firstIds)), 'Reviewer emitted duplicate check IDs.');

$equivalent = $proposal;
$equivalent['section_drafts']['5.6']['nodes']['5.6.7'] = str_replace(
    'Automated intermediate and final ECCAIRS updates are not operational. Until automated amendment functionality is operational, the Safety Manager shall enter required intermediate and final information directly into ECCAIRS using the applicable authority reporting interface.',
    'While automatic intermediate and final ECCAIRS follow-up remains unavailable, the Safety Manager remains responsible for entering required updates directly in ECCAIRS through the applicable authority reporting process.',
    (string)$equivalent['section_drafts']['5.6']['nodes']['5.6.7']
);
$equivalentReview = $reviewer->verifyReadableAmendmentProposal($equivalent);
stableCheckAssert(
    $equivalentReview['status'] === 'READY_FOR_HUMAN_REVIEW',
    'Control-equivalent limitation wording was rejected: ' . implode('; ', $equivalentReview['issues'])
);

$semanticInitial = $proposal;
$semanticInitial['section_drafts']['5.6']['nodes']['5.6.7'] = str_replace(
    'Submission of the initial occurrence does not complete the reporting process where subsequent intermediate or final information is required.',
    'Submitting the initial occurrence alone cannot constitute completion when intermediate or final authority follow-up remains required.',
    (string)$semanticInitial['section_drafts']['5.6']['nodes']['5.6.7']
);
$semanticInitialReview = $reviewer->verifyReadableAmendmentProposal($semanticInitial);
$initialCheck = array_column(
    (array)$semanticInitialReview['review_checks'],
    null,
    'check_id'
)['eccairs.follow-up.initial-not-completion'] ?? array();
stableCheckAssert(
    (string)($initialCheck['status'] ?? '') === 'PASS',
    'Semantic initial-submission control still depends on exact fixture prose.'
);

$evidenceFirst = $proposal;
$evidenceFirst['section_drafts']['5.6']['nodes']['5.6.4'] =
    'Initial ECCAIRS notification uses the information required for the applicable reporting stage. '
    . 'Unavailable or unknown information shall not delay the reporting deadline. '
    . 'The Safety Manager shall review and approve the notification before transmission. '
    . 'Evidence of preparation, approval, submission and authority acceptance shall be retained.';
$evidenceFirstReview = $reviewer->verifyReadableAmendmentProposal($evidenceFirst);
$evidenceFirstCheck = array_column(
    (array)$evidenceFirstReview['review_checks'],
    null,
    'check_id'
)['eccairs.initial.governance-complete'] ?? array();
stableCheckAssert(
    (string)($evidenceFirstCheck['status'] ?? '') === 'PASS',
    'Retained-evidence governance still depends on word order rather than the semantic control.'
);

$resolvePatchSection = new ReflectionMethod($author, 'resolveTargetedPatchSection');
$resolvedSection = $resolvePatchSection->invoke(
    $author,
    '5.6.4',
    array(array('number' => '5.6.4', 'content' => 'Targeted correction')),
    (array)$proposal['section_drafts'],
    array('5.6' => true)
);
stableCheckAssert(
    $resolvedSection === '5.6',
    'A node-scoped Author response did not resolve to its accepted parent section.'
);

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec(
    'CREATE TABLE ipca_manual_ai_architect_review_findings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        finding_uuid TEXT NOT NULL,
        plan_id INTEGER NOT NULL,
        review_id INTEGER NOT NULL,
        baseline_id INTEGER NOT NULL,
        finding_fingerprint TEXT NOT NULL,
        finding_class TEXT NOT NULL,
        outcome TEXT NOT NULL,
        status TEXT NOT NULL,
        material INTEGER NOT NULL,
        blocking INTEGER NOT NULL,
        title TEXT NOT NULL,
        human_explanation TEXT NOT NULL,
        unresolved_fact TEXT,
        why_matters TEXT,
        affected_sections_json TEXT NOT NULL,
        accepted_wording_json TEXT NOT NULL,
        evidence_json TEXT NOT NULL,
        resolution_json TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        resolved_at TEXT
    )'
);
$db->exec(
    'CREATE TABLE ipca_manual_ai_architect_review_check_metadata (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        check_uuid TEXT NOT NULL,
        plan_id INTEGER NOT NULL,
        review_id INTEGER NOT NULL,
        finding_id INTEGER NOT NULL UNIQUE,
        check_id TEXT NOT NULL,
        check_version TEXT NOT NULL,
        category TEXT NOT NULL,
        severity TEXT NOT NULL,
        review_status TEXT NOT NULL,
        resolution_status TEXT NOT NULL,
        affected_nodes_json TEXT NOT NULL,
        required_invariant TEXT NOT NULL,
        observed_state TEXT NOT NULL,
        evidence_references_json TEXT NOT NULL,
        allowed_repair_scope_json TEXT NOT NULL,
        known_limitations_json TEXT NOT NULL,
        first_seen_at TEXT DEFAULT CURRENT_TIMESTAMP,
        last_verified_at TEXT,
        verified_at TEXT,
        UNIQUE(plan_id, review_id, check_id)
    )'
);
$db->exec(
    'CREATE TABLE ipca_manual_ai_architect_review_baselines (
        id INTEGER PRIMARY KEY,
        draft_baseline_json TEXT NOT NULL
    )'
);
$db->exec("INSERT INTO ipca_manual_ai_architect_review_baselines (id,draft_baseline_json) VALUES (1,'{}')");

$resolution = new BooksManualsChangeReviewResolutionService(
    $db,
    new BooksManualsChangePlanService($db)
);
$persist = new ReflectionMethod($resolution, 'persistReviewCheck');
$reconcile = new ReflectionMethod($resolution, 'reconcileVerificationChecks');
$baseCheck = static function (
    string $id,
    string $status,
    string $node,
    string $category = 'CONTENT',
    string $severity = 'MATERIAL'
): array {
    return array(
        'check_id' => $id,
        'check_version' => '2',
        'category' => $category,
        'severity' => $severity,
        'status' => $status,
        'affected_sections' => array(strtok($node, '.')),
        'affected_nodes' => array($node),
        'required_invariant' => "Invariant {$id}",
        'observed_state' => "Observed {$status}",
        'evidence_references' => array('fixture'),
        'human_explanation' => $status === 'PASS' ? "Invariant {$id}" : "Failure {$id}",
        'allowed_repair_scope' => array($node),
        'known_limitations' => array(),
    );
};
foreach (array(
    $baseCheck('check.a', 'FAIL', '5.6.7'),
    $baseCheck('check.b', 'FAIL', '5.6.7'),
    $baseCheck('check.pass', 'PASS', '4.2'),
    $baseCheck('check.hard', 'FAIL', 'protected', 'INTEGRITY', 'HARD'),
) as $check) {
    $persist->invoke($resolution, 20, 9, 1, $check, array('status' => 'REQUIRES_REVIEW'), 1);
}
$metrics = $reconcile->invoke(
    $resolution,
    20,
    9,
    1,
    array(
        $baseCheck('check.a', 'PASS', '5.6.7'),
        $baseCheck('check.b', 'FAIL', '5.6.7'),
        $baseCheck('check.hard', 'FAIL', 'protected', 'INTEGRITY', 'HARD'),
        $baseCheck('check.new', 'FAIL', '8.1'),
    ),
    1,
    array('patch_id' => 1)
);
stableCheckAssert(
    $metrics === array(
        'checks_before' => 3,
        'checks_fixed' => 1,
        'checks_remaining' => 2,
        'new_checks' => 1,
        'regressed_checks' => 0,
        'checks_after' => 3,
    ),
    'Check-by-check convergence metrics are incorrect: ' . json_encode($metrics)
);
$states = $db->query(
    'SELECT check_id,resolution_status FROM ipca_manual_ai_architect_review_check_metadata ORDER BY check_id'
)->fetchAll(PDO::FETCH_KEY_PAIR);
stableCheckAssert($states['check.a'] === 'VERIFIED', 'One fixed finding did not close independently.');
stableCheckAssert($states['check.b'] === 'UNRESOLVED', 'An unfixed finding did not remain unresolved.');
stableCheckAssert($states['check.pass'] === 'VERIFIED', 'A failed patch reopened an omitted verified finding.');
stableCheckAssert($states['check.hard'] === 'BLOCKED', 'A hard integrity blocker became overridable.');
stableCheckAssert($states['check.new'] === 'UNRESOLVED', 'A genuinely new defect did not receive a new identity.');

$reconcile->invoke(
    $resolution,
    20,
    9,
    1,
    array($baseCheck('check.b', 'PASS', '5.6.7')),
    1,
    array('patch_id' => 2)
);
$afterRepeat = $db->query(
    'SELECT check_id,resolution_status FROM ipca_manual_ai_architect_review_check_metadata ORDER BY check_id'
)->fetchAll(PDO::FETCH_KEY_PAIR);
stableCheckAssert(count($afterRepeat) === 5, 'Repeated repair created duplicate check identities.');
stableCheckAssert($afterRepeat['check.a'] === 'VERIFIED', 'A later repair reopened an earlier verified check.');
stableCheckAssert($afterRepeat['check.b'] === 'VERIFIED', 'A subsequent repair did not converge.');

$regression = $reconcile->invoke(
    $resolution,
    20,
    9,
    1,
    array($baseCheck('check.a', 'FAIL', '5.6.7')),
    1,
    array('patch_id' => 3)
);
stableCheckAssert(
    $regression['regressed_checks'] === 1
        && $regression['new_checks'] === 0,
    'A previously verified check regression was not distinguished from a new check.'
);
$reconcile->invoke(
    $resolution,
    20,
    9,
    1,
    array($baseCheck('check.a', 'PASS', '5.6.7')),
    1,
    array('patch_id' => 4)
);

$persist->invoke(
    $resolution,
    20,
    9,
    1,
    $baseCheck('check.structure-hard', 'FAIL', '5.6', 'STRUCTURE', 'HARD'),
    array('status' => 'REQUIRES_REVIEW'),
    1
);
$structureStatus = $db->query(
    "SELECT resolution_status FROM ipca_manual_ai_architect_review_check_metadata
     WHERE check_id='check.structure-hard'"
)->fetchColumn();
stableCheckAssert(
    $structureStatus === 'BLOCKED',
    'A HARD structure check was incorrectly made patchable.'
);
$persist->invoke(
    $resolution,
    20,
    9,
    1,
    $baseCheck('check.required-content', 'FAIL', '5.6.7', 'KNOWN_LIMITATION', 'HARD'),
    array('status' => 'REQUIRES_REVIEW'),
    1
);
$contentStatus = $db->query(
    "SELECT resolution_status FROM ipca_manual_ai_architect_review_check_metadata
     WHERE check_id='check.required-content'"
)->fetchColumn();
stableCheckAssert(
    $contentStatus === 'UNRESOLVED',
    'A repairable HARD content requirement was turned into an opaque technical blocker.'
);

$dismissedScopeCheck = $baseCheck(
    'evidence.section.8-1.change-accounting',
    'FAIL',
    '8.1',
    'INTEGRITY',
    'HARD'
);
$persist->invoke(
    $resolution,
    20,
    9,
    1,
    $dismissedScopeCheck,
    array('status' => 'REQUIRES_REVIEW'),
    1
);
$dismissedScopeCheck['status'] = 'INFORMATIONAL';
$dismissedScopeCheck['severity'] = 'INFORMATIONAL';
$dismissedScopeCheck['affected_nodes'] = array();
$dismissedScopeCheck['allowed_repair_scope'] = array();
$dismissedScopeCheck['observed_state'] = 'Section 8.1 is outside the human-accepted amendment scope.';
$dismissedScopeCheck['human_explanation'] = $dismissedScopeCheck['observed_state'];
$scopeMetrics = $reconcile->invoke(
    $resolution,
    20,
    9,
    1,
    array($dismissedScopeCheck),
    1,
    array('accepted_scope_reconciliation' => true)
);
$dismissedState = $db->query(
    "SELECT review_status,resolution_status
     FROM ipca_manual_ai_architect_review_check_metadata
     WHERE check_id='evidence.section.8-1.change-accounting'"
)->fetch(PDO::FETCH_ASSOC);
stableCheckAssert(
    $scopeMetrics['checks_fixed'] === 1
        && $scopeMetrics['new_checks'] === 0
        && $scopeMetrics['regressed_checks'] === 0
        && ($dismissedState['review_status'] ?? '') === 'INFORMATIONAL'
        && ($dismissedState['resolution_status'] ?? '') === 'VERIFIED',
    'A hard check outside the accepted scope did not reconcile monotonically to informational.'
);

$repairGroups = new ReflectionMethod($resolution, 'repairGroups');
$groups = $repairGroups->invoke($resolution, array(
    array(
        'id' => 1, 'resolution_status' => 'UNRESOLVED', 'category' => 'ECCAIRS_FOLLOW_UP',
        'allowed_repair_scope_json' => array('5.6.7'), 'check_id' => 'x',
        'human_explanation' => 'X',
    ),
    array(
        'id' => 2, 'resolution_status' => 'UNRESOLVED', 'category' => 'ECCAIRS_FOLLOW_UP',
        'allowed_repair_scope_json' => array('5.6.7'), 'check_id' => 'y',
        'human_explanation' => 'Y',
    ),
    array(
        'id' => 3, 'resolution_status' => 'UNRESOLVED', 'category' => 'RECORDS',
        'allowed_repair_scope_json' => array('4.2'), 'check_id' => 'z',
        'human_explanation' => 'Z',
    ),
));
stableCheckAssert(
    count($groups) === 2 && count((array)$groups[0]['check_ids']) === 2,
    'Corrections were not grouped only by coherent repair scope.'
);

$resolutionSource = file_get_contents(
    $root . '/src/publishing/BooksManualsChangeReviewResolutionService.php'
) ?: '';
$authorSource = file_get_contents(
    $root . '/src/publishing/BooksManualsChangeAuthorService.php'
) ?: '';
stableCheckAssert(
    str_contains($resolutionSource, 'HUMAN_ACCEPTED_PENDING_VERIFICATION')
        && str_contains($resolutionSource, 'VERIFICATION_FAILED')
        && str_contains($resolutionSource, 'INDEPENDENT_REVIEW_PATCH_HUMAN_ACCEPTED')
        && str_contains($resolutionSource, 'INDEPENDENT_REVIEW_PATCH_VERIFICATION_COMPLETED'),
    'Human acceptance and successful verification are not separate patch states/events.'
);
stableCheckAssert(
    str_contains($authorSource, 'Targeted Author attempted to modify a frozen unrelated node.')
        && str_contains($authorSource, 'frozen_node_fingerprints')
        && str_contains($authorSource, 'failed_check_ids'),
    'Targeted Author scope does not freeze unrelated accepted nodes.'
);

echo "PASS: stable Independent Review checks reconcile monotonically and preserve targeted scope.\n";
