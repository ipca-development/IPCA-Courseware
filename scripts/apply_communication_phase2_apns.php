<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/RuntimeSecrets.php';
RuntimeSecrets::ensureCliEnvLoaded();
require_once __DIR__ . '/../src/db.php';

$pdo = cw_db();
$pdo->exec("INSERT IGNORE INTO ipca_communication_app_config (config_key, config_value) VALUES ('push_enabled', '1')");

$col = (int)$pdo->query("
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'ipca_communication_devices'
      AND COLUMN_NAME = 'apns_environment'
")->fetchColumn();
if ($col === 0) {
    $pdo->exec('ALTER TABLE ipca_communication_devices ADD COLUMN apns_environment VARCHAR(16) NULL AFTER push_authorized');
    echo "added apns_environment\n";
} else {
    echo "apns_environment exists\n";
}

$idx = (int)$pdo->query("
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'ipca_communication_push_attempts'
      AND INDEX_NAME = 'uk_comm_push_msg_device'
")->fetchColumn();
if ($idx === 0) {
    $pdo->exec('ALTER TABLE ipca_communication_push_attempts ADD UNIQUE KEY uk_comm_push_msg_device (message_id, device_id)');
    echo "added uk_comm_push_msg_device\n";
} else {
    echo "uk_comm_push_msg_device exists\n";
}

$flag = (string)$pdo->query("SELECT config_value FROM ipca_communication_app_config WHERE config_key = 'push_enabled'")->fetchColumn();
echo 'push_enabled=' . $flag . PHP_EOL;
echo 'ok' . PHP_EOL;
