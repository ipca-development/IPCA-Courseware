<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../src/RuntimeSecrets.php';
RuntimeSecrets::ensureCliEnvLoaded();
require_once __DIR__ . '/../src/db.php';

$pdo = cw_db();
$sqlPaths = array(
    __DIR__ . '/sql/2026_08_18_ai_manual_change_architect.sql',
    __DIR__ . '/sql/2026_08_20_manual_change_review_convergence.sql',
);
foreach ($sqlPaths as $sqlPath) {
    $sql = file_get_contents($sqlPath);
    if (!is_string($sql) || trim($sql) === '') {
        throw new RuntimeException('Manual Change Architect migration SQL is missing: ' . basename($sqlPath));
    }
    foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: array() as $statement) {
        $statement = trim((string)preg_replace('/^\s*--.*$/m', '', $statement));
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

$requiredTables = array(
    'ipca_manual_ai_architect_plans',
    'ipca_manual_ai_architect_evidence',
    'ipca_manual_ai_architect_change_intents',
    'ipca_manual_ai_architect_target_components',
    'ipca_manual_ai_architect_target_coverage',
    'ipca_manual_ai_architect_scope_boundaries',
    'ipca_manual_ai_architect_impacts',
    'ipca_manual_ai_architect_impact_dependencies',
    'ipca_manual_ai_architect_legacy_hits',
    'ipca_manual_ai_architect_legacy_hit_decisions',
    'ipca_manual_ai_architect_structure_proposals',
    'ipca_manual_ai_architect_structure_nodes',
    'ipca_manual_ai_architect_decision_events',
    'ipca_manual_ai_architect_edit_events',
    'ipca_manual_ai_architect_cross_manual_links',
    'ipca_manual_ai_architect_drafts',
    'ipca_manual_ai_architect_reviews',
    'ipca_manual_ai_architect_operations',
    'ipca_manual_ai_architect_review_baselines',
    'ipca_manual_ai_architect_review_findings',
    'ipca_manual_ai_architect_review_questions',
    'ipca_manual_ai_architect_review_answers',
    'ipca_manual_ai_architect_review_patches',
    'ipca_manual_ai_architect_review_cycles',
    'ipca_manual_ai_architect_review_check_metadata',
);

$tableCheck = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
);
foreach ($requiredTables as $table) {
    $tableCheck->execute(array($table));
    if ((int)$tableCheck->fetchColumn() !== 1) {
        throw new RuntimeException('Migration did not create required table: ' . $table);
    }
    echo $table . '=ready' . PHP_EOL;
}

echo "ok\n";
