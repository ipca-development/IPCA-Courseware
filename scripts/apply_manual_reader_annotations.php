<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

$sqlPath = __DIR__ . '/sql/2026_08_16_manual_reader_annotations.sql';
$sql = file_get_contents($sqlPath);
if (!is_string($sql) || trim($sql) === '') {
    throw new RuntimeException('Manual reader annotations migration is empty.');
}

foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: array() as $statement) {
    $statement = trim($statement);
    if ($statement !== '') {
        if (str_starts_with($statement, 'ALTER TABLE users')) {
            $columnStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            );
            $columnStmt->execute(array('users', 'can_manual_reviewer'));
            if ((int)$columnStmt->fetchColumn() > 0) {
                continue;
            }
        }
        $pdo->exec($statement);
    }
}

echo "Manual reader annotations migration applied.\n";
