<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/RuntimeSecrets.php';
RuntimeSecrets::ensureCliEnvLoaded();
require_once __DIR__ . '/../src/db.php';

$pdo = cw_db();
$sql = (string)file_get_contents(__DIR__ . '/sql/2026_08_15_communication_phase7_training_videos.sql');
if (trim($sql) === '') {
    throw new RuntimeException('Phase 7 SQL is missing.');
}

foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: array() as $statement) {
    $statement = trim((string)preg_replace('/^\s*--.*$/m', '', $statement));
    if ($statement !== '') {
        $pdo->exec($statement);
    }
}

$enabled = (string)$pdo->query(
    "SELECT config_value FROM ipca_communication_app_config WHERE config_key = 'training_videos_enabled'"
)->fetchColumn();
$table = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_training_videos'"
)->fetchColumn();

echo 'training_videos_enabled=' . $enabled . PHP_EOL;
echo 'training_videos_table=' . ($table > 0 ? 'yes' : 'no') . PHP_EOL;
echo 'ok' . PHP_EOL;
