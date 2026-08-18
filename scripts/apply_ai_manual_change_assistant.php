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
$path = __DIR__ . '/sql/2026_08_18_ai_manual_change_assistant.sql';
$sql = file_get_contents($path);
if (!is_string($sql) || trim($sql) === '') {
    throw new RuntimeException('AI Manual Change Assistant migration SQL is missing.');
}
foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: array() as $statement) {
    $statement = trim((string)preg_replace('/^\s*--.*$/m', '', $statement));
    if ($statement !== '') {
        $pdo->exec($statement);
    }
}

$required = array(
    'ipca_manual_ai_projects',
    'ipca_manual_ai_sources',
    'ipca_manual_ai_version_scopes',
    'ipca_manual_ai_requirements',
    'ipca_manual_ai_content_chunks',
    'ipca_manual_ai_findings',
    'ipca_manual_ai_proposals',
    'ipca_manual_ai_decisions',
    'ipca_manual_ai_jobs',
);
$check = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'
);
foreach ($required as $table) {
    $check->execute(array($table));
    if ((int)$check->fetchColumn() !== 1) {
        throw new RuntimeException('Migration did not create required table: ' . $table);
    }
    echo $table . "=ready\n";
}
echo "ok\n";
