<?php
declare(strict_types=1);
require '/var/www/ipca-comm-staging/scripts/staging/load_fpm_db_env.php';
$env = ipca_load_fpm_db_env();
$p = ipca_pdo_from_env($env, 'ipca_courseware');
echo 'users=' . $p->query('SELECT COUNT(*) FROM users')->fetchColumn() . PHP_EOL;
$st = $p->prepare("SELECT COUNT(*) FROM users WHERE status = 'active'");
$st->execute();
echo 'active=' . $st->fetchColumn() . PHP_EOL;
$st = $p->prepare("SELECT COUNT(*) FROM users WHERE uuid IS NULL OR TRIM(uuid) = ''");
$st->execute();
echo 'uuid_empty=' . $st->fetchColumn() . PHP_EOL;
echo 'conversations=' . $p->query('SELECT COUNT(*) FROM ipca_communication_conversations')->fetchColumn() . PHP_EOL;
echo 'devices=' . $p->query('SELECT COUNT(*) FROM ipca_communication_devices')->fetchColumn() . PHP_EOL;
foreach ($p->query('SELECT id, email, uuid, status FROM users ORDER BY id') as $r) {
    echo $r['id'] . "\t" . $r['status'] . "\t" . ($r['uuid'] ?? 'NULL') . "\t" . $r['email'] . PHP_EOL;
}
