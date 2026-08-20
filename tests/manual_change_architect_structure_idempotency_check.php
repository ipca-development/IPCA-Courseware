<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/publishing/BooksManualsChangeStructureService.php';

function structure_idempotency_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec(
    'CREATE TABLE ipca_manual_ai_architect_plans (
        id INTEGER PRIMARY KEY,
        stage TEXT,
        status TEXT,
        updated_by INTEGER
    )'
);

$simpleCollections = array(
    'evidence',
    'change_intents',
    'target_components',
    'target_coverage',
    'impact_dependencies',
    'scope_boundaries',
    'legacy_hits',
    'legacy_hit_decisions',
    'edit_events',
    'cross_manual_links',
    'drafts',
    'reviews',
    'operations',
);
foreach ($simpleCollections as $collection) {
    $pdo->exec(
        'CREATE TABLE ipca_manual_ai_architect_' . $collection
        . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, plan_id INTEGER)'
    );
}

$pdo->exec(
    'CREATE TABLE ipca_manual_ai_architect_impacts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        plan_id INTEGER NOT NULL,
        section_number TEXT NOT NULL,
        status TEXT NOT NULL
    )'
);
$pdo->exec(
    'CREATE TABLE ipca_manual_ai_architect_structure_proposals (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        plan_id INTEGER NOT NULL,
        proposal_uuid TEXT NOT NULL,
        proposal_version INTEGER NOT NULL,
        title TEXT NOT NULL,
        rationale TEXT NOT NULL,
        status TEXT NOT NULL,
        structure_fingerprint TEXT NOT NULL,
        proposed_by INTEGER NOT NULL,
        UNIQUE(plan_id, proposal_version),
        UNIQUE(plan_id, structure_fingerprint)
    )'
);
$pdo->exec(
    'CREATE TABLE ipca_manual_ai_architect_structure_nodes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        structure_proposal_id INTEGER NOT NULL,
        node_uuid TEXT NOT NULL,
        parent_node_id INTEGER,
        source_section_id INTEGER,
        node_key TEXT NOT NULL,
        node_type TEXT NOT NULL,
        title TEXT NOT NULL,
        purpose TEXT,
        action TEXT NOT NULL,
        decision_status TEXT NOT NULL,
        decision_rationale TEXT,
        decided_by INTEGER,
        decided_at TEXT,
        depth INTEGER NOT NULL,
        sort_order INTEGER NOT NULL,
        node_fingerprint TEXT NOT NULL,
        UNIQUE(structure_proposal_id, node_key)
    )'
);
$pdo->exec(
    'CREATE TABLE ipca_manual_ai_architect_decision_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        plan_id INTEGER NOT NULL,
        event_uuid TEXT NOT NULL,
        aggregate_type TEXT NOT NULL,
        aggregate_id INTEGER NOT NULL,
        event_type TEXT NOT NULL,
        stage INTEGER NOT NULL,
        event_payload_json TEXT NOT NULL,
        decision TEXT NOT NULL,
        event_fingerprint TEXT NOT NULL,
        actor_id INTEGER
    )'
);

$pdo->exec("INSERT INTO ipca_manual_ai_architect_plans (id,stage,status) VALUES (14,'structure','ready_for_review')");
$pdo->exec(
    "INSERT INTO ipca_manual_ai_architect_impacts (plan_id,section_number,status)
     VALUES (14,'5.6','approved')"
);

$service = new BooksManualsChangeStructureService($pdo);
$sourceFingerprint = hash('sha256', 'plan-14-source');
$proposal = $service->buildProposal(
    'Plan 14 proposed structure',
    'Regression for review-driven structure reopening.',
    $sourceFingerprint,
    array(array(
        'section_number' => '5.6',
        'section_title' => 'Occurrence Reporting',
        'source_section_id' => 56,
        'source_content_fingerprint' => $sourceFingerprint,
        'treatment' => 'RESTRUCTURE',
        'human_accepted' => true,
        'current' => array(array(
            'number' => '5.6',
            'title' => 'Occurrence Reporting',
            'action' => 'PRESERVE',
        )),
        'future' => array(array(
            'number' => '5.6',
            'title' => 'Occurrence Management',
            'action' => 'RENAME',
            'children' => array(array(
                'number' => '5.6.1',
                'title' => 'Initial Occurrence Reporting',
                'action' => 'ADD',
            )),
        )),
    ))
);

$firstId = $service->persistProposal(14, $proposal, 7);
$pdo->exec(
    "UPDATE ipca_manual_ai_architect_structure_proposals SET status='superseded' WHERE id={$firstId}"
);
$pdo->exec(
    "UPDATE ipca_manual_ai_architect_structure_nodes SET decision_status='superseded'
     WHERE structure_proposal_id={$firstId}"
);
$secondId = $service->persistProposal(14, $proposal, 7);

structure_idempotency_assert($secondId === $firstId, 'Identical structure did not reuse its existing proposal.');
structure_idempotency_assert(
    (int)$pdo->query('SELECT COUNT(*) FROM ipca_manual_ai_architect_structure_proposals')->fetchColumn() === 1,
    'Identical structure created a duplicate proposal row.'
);
structure_idempotency_assert(
    $pdo->query(
        "SELECT status FROM ipca_manual_ai_architect_structure_proposals WHERE id={$firstId}"
    )->fetchColumn() === 'proposed',
    'Existing proposal was not reopened for review.'
);
structure_idempotency_assert(
    (int)$pdo->query(
        "SELECT COUNT(*) FROM ipca_manual_ai_architect_structure_nodes
         WHERE structure_proposal_id={$firstId} AND decision_status='proposed'"
    )->fetchColumn() === 2,
    'Existing proposal nodes were not reset for review.'
);
structure_idempotency_assert(
    (int)$pdo->query(
        "SELECT COUNT(*) FROM ipca_manual_ai_architect_decision_events
         WHERE event_type='STRUCTURE_PROPOSED'"
    )->fetchColumn() === 2,
    'Reopening the proposal did not retain an immutable audit event.'
);

echo "PASS: identical structure fingerprints reopen without duplicate inserts\n";
