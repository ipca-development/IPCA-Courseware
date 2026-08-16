<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/RuntimeSecrets.php';
RuntimeSecrets::ensureCliEnvLoaded();
require_once __DIR__ . '/../src/db.php';

$pdo = cw_db();
$pdo->exec("
    INSERT INTO ipca_communication_app_config (config_key, config_value)
    VALUES ('system_messages_enabled', '1')
    ON DUPLICATE KEY UPDATE config_value = '1'
");
$flag = (string)$pdo->query("SELECT config_value FROM ipca_communication_app_config WHERE config_key = 'system_messages_enabled'")->fetchColumn();
$actors = (int)$pdo->query('SELECT COUNT(*) FROM ipca_communication_system_actors WHERE is_active = 1')->fetchColumn();
echo 'system_messages_enabled=' . $flag . PHP_EOL;
echo 'active_system_actors=' . $actors . PHP_EOL;
echo 'ok' . PHP_EOL;
