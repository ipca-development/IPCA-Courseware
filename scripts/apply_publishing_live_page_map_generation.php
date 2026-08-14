<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/RuntimeSecrets.php';
RuntimeSecrets::ensureCliEnvLoaded();
require_once __DIR__ . '/../src/db.php';

$pdo = cw_db();
$sqlPath = __DIR__ . '/sql/2026_08_14_publishing_live_page_map_generation.sql';
$sql = file_get_contents($sqlPath);
if (!is_string($sql) || trim($sql) === '') {
    throw new RuntimeException('Live page-map migration SQL is missing.');
}

$statements = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: array();
foreach ($statements as $statement) {
    if (trim($statement) !== '') {
        $pdo->exec($statement);
    }
}

$required = array(
    'ipca_publishing_page_map_generation_state',
    'ipca_publishing_reader_page_map_staging',
);
$check = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
);
foreach ($required as $table) {
    $check->execute(array($table));
    if ((int)$check->fetchColumn() !== 1) {
        throw new RuntimeException('Migration did not create required table: ' . $table);
    }
    echo $table . '=ready' . PHP_EOL;
}

$upgradeColumns = array(
    'pending_generation_seq'
        => "BIGINT UNSIGNED NULL COMMENT 'Newest coalesced revision waiting behind the active lease'",
    'pending_fingerprint_hash' => 'CHAR(64) NULL',
    'pending_fingerprint_json' => 'JSON NULL',
    'pending_requested_by_user_id' => 'INT UNSIGNED NULL',
);
$columnExists = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ? AND COLUMN_NAME = ?'
);
foreach ($upgradeColumns as $column => $definition) {
    $columnExists->execute(array('ipca_publishing_page_map_generation_state', $column));
    if ((int)$columnExists->fetchColumn() === 0) {
        $pdo->exec(
            'ALTER TABLE ipca_publishing_page_map_generation_state ADD COLUMN '
            . $column . ' ' . $definition
        );
        echo 'added_column=' . $column . PHP_EOL;
    }
}

$requiredColumns = array(
    'ipca_publishing_page_map_generation_state' => array(
        'generation_seq',
        'status',
        'requested_fingerprint_hash',
        'requested_fingerprint_json',
        'pending_generation_seq',
        'pending_fingerprint_hash',
        'pending_fingerprint_json',
        'pending_requested_by_user_id',
        'lease_token',
        'lease_expires_at',
    ),
    'ipca_publishing_reader_page_map_staging' => array(
        'generation_seq',
        'lease_token',
        'page_html',
    ),
);
$columnCheck = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
);
foreach ($requiredColumns as $table => $columns) {
    foreach ($columns as $column) {
        $columnCheck->execute(array($table, $column));
        if ((int)$columnCheck->fetchColumn() !== 1) {
            throw new RuntimeException("Migration column missing: {$table}.{$column}");
        }
    }
}
echo 'ok' . PHP_EOL;
