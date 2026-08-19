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
$sqlFiles = array(
    __DIR__ . '/sql/2026_08_18_annex_book_structure.sql',
    __DIR__ . '/sql/2026_08_18_annex_revision_snapshots.sql',
);
foreach ($sqlFiles as $sqlPath) {
    $sql = file_get_contents($sqlPath);
    if (!is_string($sql) || trim($sql) === '') {
        throw new RuntimeException('Annex Book migration SQL is missing: ' . basename($sqlPath));
    }
    foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: array() as $statement) {
        $statement = trim((string)preg_replace('/^\s*--.*$/m', '', $statement));
        if ($statement === '') {
            continue;
        }
        try {
            $pdo->exec($statement);
        } catch (PDOException $e) {
            $message = $e->getMessage();
            if (!str_contains($message, 'Duplicate column')
                && !str_contains($message, 'Duplicate key name')) {
                throw $e;
            }
        }
    }
}

$requiredTables = array(
    'ipca_publishing_annex_book_map',
    'ipca_publishing_annex_revisions',
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
