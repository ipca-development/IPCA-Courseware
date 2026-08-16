<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/RuntimeSecrets.php';
RuntimeSecrets::ensureCliEnvLoaded();
require_once __DIR__ . '/../src/db.php';

$pdo = cw_db();
$sql = (string)file_get_contents(__DIR__ . '/sql/2026_08_16_communication_phase9_training_video_catalog.sql');
if (trim($sql) === '') {
    throw new RuntimeException('Phase 9 training-video catalog SQL is missing.');
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
    'category_id' => 'BIGINT UNSIGNED NULL AFTER category',
    'title_source' => "VARCHAR(32) NULL AFTER title",
);
foreach ($videoColumns as $column => $definition) {
    $columnExists->execute(array('ipca_training_videos', $column));
    if ((int)$columnExists->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE ipca_training_videos ADD COLUMN ' . $column . ' ' . $definition);
        echo 'added_column=' . $column . PHP_EOL;
    }
}

$viewColumns = array(
    'position_ms' => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_viewed_at_utc',
    'max_position_ms' => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER position_ms',
    'progress_percent' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER max_position_ms',
    'completed_at_utc' => 'DATETIME(3) NULL AFTER progress_percent',
    'updated_at_utc' => 'DATETIME(3) NULL AFTER completed_at_utc',
);
foreach ($viewColumns as $column => $definition) {
    $columnExists->execute(array('ipca_training_video_views', $column));
    if ((int)$columnExists->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE ipca_training_video_views ADD COLUMN ' . $column . ' ' . $definition);
        echo 'added_view_column=' . $column . PHP_EOL;
    }
}

$uncategorizedId = (int)$pdo->query(
    "SELECT id FROM ipca_training_video_categories WHERE slug = 'uncategorized' LIMIT 1"
)->fetchColumn();

$videos = $pdo->query(
    'SELECT id, category, category_id FROM ipca_training_videos WHERE deleted_at_utc IS NULL'
)->fetchAll(PDO::FETCH_ASSOC);
$find = $pdo->prepare(
    'SELECT id, name FROM ipca_training_video_categories
     WHERE is_active = 1 AND (LOWER(name) = ? OR LOWER(slug) = ?)
     LIMIT 1'
);
$update = $pdo->prepare('UPDATE ipca_training_videos SET category_id = ?, category = ? WHERE id = ?');
foreach ($videos as $row) {
    if ((int)($row['category_id'] ?? 0) > 0) {
        continue;
    }
    $text = strtolower(trim((string)($row['category'] ?? '')));
    $categoryId = $uncategorizedId;
    $name = 'Uncategorized';
    if ($text !== '') {
        $find->execute(array($text, str_replace(' ', '-', $text)));
        $match = $find->fetch(PDO::FETCH_ASSOC);
        if (is_array($match)) {
            $categoryId = (int)$match['id'];
            $name = (string)$match['name'];
        }
    }
    $update->execute(array($categoryId > 0 ? $categoryId : null, $name, (int)$row['id']));
}

$table = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_training_video_categories'"
)->fetchColumn();
if ($table < 1) {
    throw new RuntimeException('ipca_training_video_categories was not created.');
}

echo 'ipca_training_video_categories=' . (int)$pdo->query('SELECT COUNT(*) FROM ipca_training_video_categories')->fetchColumn() . PHP_EOL;
echo 'ok' . PHP_EOL;
