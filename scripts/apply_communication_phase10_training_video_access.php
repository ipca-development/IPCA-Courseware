<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/RuntimeSecrets.php';
RuntimeSecrets::ensureCliEnvLoaded();
require_once __DIR__ . '/../src/db.php';

$pdo = cw_db();
$sql = (string)file_get_contents(__DIR__ . '/sql/2026_08_16_communication_phase10_training_video_access.sql');
if (trim($sql) === '') {
    throw new RuntimeException('Phase 10 training-video access SQL is missing.');
}

foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: array() as $statement) {
    $statement = trim((string)preg_replace('/^\s*--.*$/m', '', $statement));
    if ($statement !== '') {
        $pdo->exec($statement);
    }
}

$columnExists = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ? AND COLUMN_NAME = ?'
);

$nullable = array('available_from_utc', 'available_until_utc');
foreach ($nullable as $column) {
    $columnExists->execute(array('ipca_training_video_grants', $column));
    if ((int)$columnExists->fetchColumn() > 0) {
        $pdo->exec('ALTER TABLE ipca_training_video_grants MODIFY ' . $column . ' DATETIME(3) NULL');
        echo 'nullable_grant_column=' . $column . PHP_EOL;
    }
}

$table = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_training_video_category_entitlements'"
)->fetchColumn();
if ($table < 1) {
    throw new RuntimeException('ipca_training_video_category_entitlements was not created.');
}

echo 'ipca_training_video_category_entitlements=yes' . PHP_EOL;
echo 'ok' . PHP_EOL;
