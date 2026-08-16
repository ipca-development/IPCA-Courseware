<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/RuntimeSecrets.php';
RuntimeSecrets::ensureCliEnvLoaded();
require_once __DIR__ . '/../src/db.php';

$pdo = cw_db();
$pdo->exec("
    INSERT INTO ipca_communication_app_config (config_key, config_value)
    VALUES ('training_enabled', '1')
    ON DUPLICATE KEY UPDATE config_value = '1'
");
$flag = (string)$pdo->query("SELECT config_value FROM ipca_communication_app_config WHERE config_key = 'training_enabled'")->fetchColumn();
echo 'training_enabled=' . $flag . PHP_EOL;
echo 'ok' . PHP_EOL;
