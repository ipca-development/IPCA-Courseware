<?php
declare(strict_types=1);

/**
 * Creates ipca_courseware_staging, copies users TABLE STRUCTURE only (no production rows),
 * applies Phase 1 communication SQL, and seeds synthetic IPCA.training test accounts.
 *
 * Refuses to write to ipca_courseware (production).
 */
require_once __DIR__ . '/load_fpm_db_env.php';

$stagingName = 'ipca_courseware_staging';
$prodName = 'ipca_courseware';
$sqlFile = dirname(__DIR__, 2) . '/scripts/sql/2026_08_13_communication_phase1.sql';
if (!is_readable($sqlFile)) {
    // When deployed to /var/www/ipca-comm-staging the SQL lives beside this script's parent.
    $alt = dirname(__DIR__, 2) . '/sql/2026_08_13_communication_phase1.sql';
    if (is_readable($alt)) {
        $sqlFile = $alt;
    }
}

$env = ipca_load_fpm_db_env();
if (($env['CW_DB_NAME'] ?? '') !== $prodName) {
    fwrite(STDERR, "Unexpected FPM database name: " . ($env['CW_DB_NAME'] ?? '') . PHP_EOL);
    exit(1);
}
if (str_contains((string)$env['CW_DB_HOST'], 'prod') === false) {
    echo "NOTE host does not contain 'prod': {$env['CW_DB_HOST']}\n";
}

$admin = ipca_pdo_from_env($env, $prodName);
echo "connected_read=" . $admin->query('SELECT DATABASE()')->fetchColumn() . PHP_EOL;

$prodComm = (int)$admin->query("
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = " . $admin->quote($prodName) . "
      AND table_name LIKE 'ipca_communication_%'
")->fetchColumn();
if ($prodComm > 0) {
    fwrite(STDERR, "REFUSING: production {$prodName} already has {$prodComm} communication tables. Aborting.\n");
    exit(1);
}

$admin->exec("CREATE DATABASE IF NOT EXISTS `{$stagingName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$admin->exec("CREATE TABLE IF NOT EXISTS `{$stagingName}`.`users` LIKE `{$prodName}`.`users`");

$staging = ipca_pdo_from_env($env, $stagingName);
$active = (string)$staging->query('SELECT DATABASE()')->fetchColumn();
if ($active !== $stagingName) {
    fwrite(STDERR, "REFUSING: connected to {$active}, expected {$stagingName}\n");
    exit(1);
}

$copiedUsers = (int)$staging->query('SELECT COUNT(*) FROM users')->fetchColumn();
if ($copiedUsers > 0) {
    $synthetic = (int)$staging->query("SELECT COUNT(*) FROM users WHERE email LIKE '%@ipca.training'")->fetchColumn();
    if ($synthetic !== $copiedUsers) {
        fwrite(STDERR, "REFUSING: staging users table has {$copiedUsers} rows that are not exclusively synthetic @ipca.training accounts.\n");
        exit(1);
    }
}

if (!is_readable($sqlFile)) {
    fwrite(STDERR, "Cannot read SQL file: {$sqlFile}\n");
    exit(1);
}

$sql = (string)file_get_contents($sqlFile);
$statements = array();
$buffer = '';
foreach (preg_split("/\n/", $sql) ?: array() as $line) {
    if (preg_match('/^\s*--/', $line)) {
        continue;
    }
    $buffer .= $line . "\n";
    if (str_contains(rtrim($line), ';')) {
        $statement = trim($buffer);
        $buffer = '';
        if ($statement !== '' && $statement !== ';') {
            $statements[] = $statement;
        }
    }
}
foreach ($statements as $statement) {
    $staging->exec($statement);
}

$password = 'Phase1LiveValidate-2026!';
$hash = password_hash($password, PASSWORD_DEFAULT);
$now = gmdate('Y-m-d H:i:s');
$seeds = array(
    array('uuid' => 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa', 'email' => 'live-a@ipca.training', 'name' => 'Live A', 'first' => 'Live', 'last' => 'A'),
    array('uuid' => 'bbbbbbbb-2222-4222-8222-bbbbbbbbbbbb', 'email' => 'live-b@ipca.training', 'name' => 'Live B', 'first' => 'Live', 'last' => 'B'),
    array('uuid' => 'cccccccc-3333-4333-8333-cccccccccccc', 'email' => 'live-c@ipca.training', 'name' => 'Live C', 'first' => 'Live', 'last' => 'C'),
);
$insert = $staging->prepare("
    INSERT INTO users (uuid, email, username, name, first_name, last_name, password_hash, status, role, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, 'active', 'student', ?, ?)
    ON DUPLICATE KEY UPDATE
      password_hash = VALUES(password_hash),
      status = 'active',
      account_valid_until = NULL,
      name = VALUES(name)
");
foreach ($seeds as $seed) {
    $insert->execute(array(
        $seed['uuid'],
        $seed['email'],
        explode('@', $seed['email'])[0],
        $seed['name'],
        $seed['first'],
        $seed['last'],
        $hash,
        $now,
        $now,
    ));
}

$tables = $staging->query("SHOW TABLES LIKE 'ipca_communication_%'")->fetchAll(PDO::FETCH_COLUMN);
$flags = (int)$staging->query("SELECT COUNT(*) FROM ipca_communication_app_config")->fetchColumn();
$users = (int)$staging->query('SELECT COUNT(*) FROM users')->fetchColumn();
$prodStill = (int)$admin->query("
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = " . $admin->quote($prodName) . "
      AND table_name LIKE 'ipca_communication_%'
")->fetchColumn();

echo "staging_db={$active}\n";
echo "communication_tables=" . count($tables) . "\n";
echo "tables=" . implode(',', $tables) . "\n";
echo "config_rows={$flags}\n";
echo "staging_users={$users}\n";
echo "production_communication_tables={$prodStill}\n";
echo "test_password_set=1\n";

if ($prodStill !== 0) {
    fwrite(STDERR, "REFUSING success: production gained communication tables.\n");
    exit(1);
}
if (count($tables) < 10) {
    fwrite(STDERR, "Expected at least 10 communication tables.\n");
    exit(1);
}
echo "MIGRATION_OK\n";
