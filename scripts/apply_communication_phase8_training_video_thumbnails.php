<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/RuntimeSecrets.php';
RuntimeSecrets::ensureCliEnvLoaded();
require_once __DIR__ . '/../src/db.php';

$pdo = cw_db();
$sql = (string)file_get_contents(__DIR__ . '/sql/2026_08_16_communication_phase8_training_video_thumbnails.sql');
if (trim($sql) === '') {
    throw new RuntimeException('Phase 8 thumbnail SQL is missing.');
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

$videoColumns = array(
    'category' => "VARCHAR(128) NULL AFTER description",
    'aircraft' => "VARCHAR(128) NULL AFTER category",
    'program' => "VARCHAR(128) NULL AFTER aircraft",
    'width' => "INT UNSIGNED NOT NULL DEFAULT 0 AFTER byte_size",
    'height' => "INT UNSIGNED NOT NULL DEFAULT 0 AFTER width",
    'orientation' => "VARCHAR(16) NULL AFTER height",
    'poster_source' => "VARCHAR(32) NULL AFTER poster_mime_type",
    'poster_template' => "VARCHAR(64) NULL AFTER poster_source",
    'poster_library_asset_id' => "BIGINT UNSIGNED NULL AFTER poster_template",
    'poster_candidate_json' => "JSON NULL AFTER poster_library_asset_id",
    'poster_candidate_index' => "INT UNSIGNED NOT NULL DEFAULT 0 AFTER poster_candidate_json",
    'description_source' => "VARCHAR(32) NULL AFTER description",
);

foreach ($videoColumns as $column => $definition) {
    $columnExists->execute(array('ipca_training_videos', $column));
    if ((int)$columnExists->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE ipca_training_videos ADD COLUMN ' . $column . ' ' . $definition);
        echo 'added_column=' . $column . PHP_EOL;
    }
}

$table = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_training_media_library'"
)->fetchColumn();
if ($table < 1) {
    throw new RuntimeException('ipca_training_media_library was not created.');
}

echo 'ipca_training_media_library=yes' . PHP_EOL;
echo 'ok' . PHP_EOL;
