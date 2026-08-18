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
$sql = (string)file_get_contents(
    __DIR__ . '/sql/2026_08_18_books_manuals_compliance_overrides.sql'
);
$pdo->exec($sql);

$table = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'ipca_publishing_compliance_overrides'"
)->fetchColumn();
if ($table !== 1) {
    throw new RuntimeException('Compliance override table was not created.');
}

echo "ipca_publishing_compliance_overrides=ready\n";
echo "ok\n";
