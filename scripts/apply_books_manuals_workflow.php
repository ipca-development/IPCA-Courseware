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
$sqlPath = __DIR__ . '/sql/2026_08_18_books_manuals_workflow.sql';
$sql = file_get_contents($sqlPath);
if (!is_string($sql) || trim($sql) === '') {
    throw new RuntimeException('Books & Manuals workflow migration SQL is missing.');
}

foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: array() as $statement) {
    $statement = trim((string)preg_replace('/^\s*--.*$/m', '', $statement));
    if ($statement !== '') {
        $pdo->exec($statement);
    }
}

$requiredTables = array(
    'ipca_publishing_book_profiles',
    'ipca_publishing_version_workflow',
    'ipca_publishing_lifecycle_events',
    'ipca_publishing_version_audiences',
    'ipca_publishing_audit_snapshots',
    'ipca_publishing_annex_book_links',
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

$versionCount = (int)$pdo->query(
    'SELECT COUNT(*) FROM ipca_publishing_book_versions'
)->fetchColumn();
$identityCount = (int)$pdo->query(
    'SELECT COUNT(*) FROM ipca_publishing_version_workflow'
)->fetchColumn();
if ($identityCount < $versionCount) {
    throw new RuntimeException(
        "Workflow identity backfill incomplete: {$identityCount}/{$versionCount}."
    );
}

echo "workflow_identities={$identityCount}\n";
echo "ok\n";
