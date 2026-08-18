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
$sqlPath = __DIR__ . '/sql/2026_08_18_ai_manual_change_context_pipeline.sql';
$sql = file_get_contents($sqlPath);
if (!is_string($sql) || trim($sql) === '') {
    throw new RuntimeException('AI Manual Change context pipeline migration SQL is missing.');
}

foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: array() as $statement) {
    $statement = trim((string)preg_replace('/^\s*--.*$/m', '', $statement));
    if ($statement !== '') {
        $pdo->exec($statement);
    }
}

$requiredTables = array(
    'ipca_manual_ai_analysis_runs',
    'ipca_manual_ai_change_intents',
    'ipca_manual_ai_target_workflow_areas',
    'ipca_manual_ai_impact_areas',
    'ipca_manual_ai_impact_area_requirements',
    'ipca_manual_ai_impact_area_sections',
    'ipca_manual_ai_impact_area_blocks',
    'ipca_manual_ai_impact_area_findings',
    'ipca_manual_ai_legacy_hits',
    'ipca_manual_ai_scope_warnings',
    'ipca_manual_ai_composer_runs',
    'ipca_manual_ai_composer_proposals',
    'ipca_manual_ai_consistency_assertions',
    'ipca_manual_ai_assertion_requirements',
    'ipca_manual_ai_assertion_sections',
    'ipca_manual_ai_assertion_blocks',
    'ipca_manual_ai_assertion_findings',
    'ipca_manual_ai_assertion_proposals',
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

$requiredRequirementColumns = array(
    'analysis_run_id',
    'change_intent_id',
    'workflow_area_id',
    'validation_status',
    'validation_diagnostics_json',
    'validated_at',
);
$columnCheck = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = ?
       AND COLUMN_NAME = ?'
);
foreach ($requiredRequirementColumns as $column) {
    $columnCheck->execute(array('ipca_manual_ai_requirements', $column));
    if ((int)$columnCheck->fetchColumn() !== 1) {
        throw new RuntimeException(
            'Migration did not add required requirement column: ' . $column
        );
    }
    echo 'ipca_manual_ai_requirements.' . $column . '=ready' . PHP_EOL;
}

echo "ok\n";
