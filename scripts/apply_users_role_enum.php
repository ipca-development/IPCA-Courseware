<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/RuntimeSecrets.php';
RuntimeSecrets::ensureCliEnvLoaded();
require_once __DIR__ . '/../src/db.php';

$pdo = cw_db();

/**
 * @return list<string>
 */
function users_role_parse_enum(string $columnType): array
{
    if (!preg_match('/^enum\((.*)\)$/i', $columnType, $match)) {
        return array();
    }

    $values = array();
    foreach (str_getcsv($match[1], ',', "'") as $value) {
        $value = trim((string)$value);
        if ($value !== '') {
            $values[] = $value;
        }
    }

    return $values;
}

$row = $pdo->query("
    SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'role'
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

if (!is_array($row)) {
    fwrite(STDERR, "users.role column was not found\n");
    exit(1);
}

$columnType = (string)$row['COLUMN_TYPE'];
$nullable = strtoupper((string)$row['IS_NULLABLE']) === 'YES';
$default = $row['COLUMN_DEFAULT'];
$required = array('admin', 'student', 'supervisor', 'instructor', 'chief_instructor');

echo 'current_role_type=' . $columnType . PHP_EOL;

if (stripos($columnType, 'enum(') !== 0) {
    $pdo->exec("ALTER TABLE users MODIFY COLUMN role VARCHAR(32) NOT NULL");
    echo "changed users.role to VARCHAR(32)\n";
    echo "ok\n";
    exit(0);
}

$existing = users_role_parse_enum($columnType);
$merged = $existing;
foreach ($required as $value) {
    if (!in_array($value, $merged, true)) {
        $merged[] = $value;
    }
}

if ($merged === $existing) {
    echo "users.role already includes instructor and chief_instructor\n";
    echo "ok\n";
    exit(0);
}

$enumSql = implode(', ', array_map(
    static fn(string $value): string => "'" . str_replace("'", "''", $value) . "'",
    $merged
));
$nullSql = $nullable ? 'NULL' : 'NOT NULL';
$defaultSql = '';
if (is_string($default) && $default !== '' && in_array($default, $merged, true)) {
    $defaultSql = " DEFAULT '" . str_replace("'", "''", $default) . "'";
}

$sql = "ALTER TABLE users MODIFY COLUMN role ENUM({$enumSql}) {$nullSql}{$defaultSql}";
$pdo->exec($sql);
echo 'updated_role_type=enum(' . implode(',', $merged) . ')' . PHP_EOL;
echo "ok\n";
