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
$sqlPath = __DIR__ . '/sql/2026_08_18_annex_revision_delete_restore.sql';
$sql = file_get_contents($sqlPath);
if (!is_string($sql) || trim($sql) === '') {
    throw new RuntimeException('Annex revision delete/restore migration SQL is missing.');
}

foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: array() as $statement) {
    $statement = trim((string)preg_replace('/^\s*--.*$/m', '', $statement));
    if ($statement !== '') {
        $pdo->exec($statement);
    }
}

$stmt = $pdo->query(
    "SHOW COLUMNS FROM ipca_publishing_annex_revisions LIKE 'source'"
);
$row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
$type = is_array($row) ? (string)($row['Type'] ?? '') : '';
if (!str_contains($type, 'delete') || !str_contains($type, 'restore')) {
    throw new RuntimeException('Annex revision source ENUM was not extended with delete/restore.');
}

echo "ipca_publishing_annex_revisions.source=delete,restore\n";
echo "ok\n";
