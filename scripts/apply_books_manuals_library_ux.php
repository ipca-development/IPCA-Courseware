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
$columnCheck = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
);
$columnCheck->execute(array(
    'ipca_publishing_book_profiles',
    'approved_reader_policy',
));
if ((int)$columnCheck->fetchColumn() === 0) {
    $pdo->exec(
        "ALTER TABLE ipca_publishing_book_profiles
         ADD COLUMN approved_reader_policy
           ENUM('all_readers','selected_reviewers')
           NOT NULL DEFAULT 'all_readers'
           AFTER authority_code"
    );
}

$sql = (string)file_get_contents(
    __DIR__ . '/sql/2026_08_18_books_manuals_library_ux.sql'
);
$statements = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: array();
foreach ($statements as $statement) {
    $statement = trim((string)preg_replace('/^\s*--.*$/m', '', $statement));
    if ($statement === '' || str_starts_with($statement, 'ALTER TABLE')) {
        continue;
    }
    $pdo->exec($statement);
}

$reviewerTable = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'ipca_publishing_book_reviewers'"
)->fetchColumn();
if ($reviewerTable !== 1) {
    throw new RuntimeException('Book reviewer table was not created.');
}

echo "approved_reader_policy=ready\n";
echo "ipca_publishing_book_reviewers=ready\n";
echo "ok\n";
