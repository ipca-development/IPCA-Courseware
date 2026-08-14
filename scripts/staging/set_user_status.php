<?php
declare(strict_types=1);

require_once __DIR__ . '/load_fpm_db_env.php';

$email = strtolower(trim((string)($argv[1] ?? '')));
$status = strtolower(trim((string)($argv[2] ?? '')));
if ($email === '' || !in_array($status, array('active', 'locked', 'retired', 'pending_activation'), true)) {
    fwrite(STDERR, "Usage: php set_user_status.php <email> <active|locked|retired|pending_activation>\n");
    exit(1);
}
if (!str_ends_with($email, '@ipca.training')) {
    fwrite(STDERR, "REFUSING: only @ipca.training staging accounts may be changed.\n");
    exit(1);
}

$env = ipca_load_fpm_db_env('ipca_courseware_staging');
$pdo = ipca_pdo_from_env($env, 'ipca_courseware_staging');
if ((string)$pdo->query('SELECT DATABASE()')->fetchColumn() !== 'ipca_courseware_staging') {
    fwrite(STDERR, "REFUSING: not connected to staging.\n");
    exit(1);
}

$stmt = $pdo->prepare('UPDATE users SET status = ? WHERE email = ?');
$stmt->execute(array($status, $email));
if ($stmt->rowCount() < 1) {
    $exists = $pdo->prepare('SELECT status FROM users WHERE email = ?');
    $exists->execute(array($email));
    $row = $exists->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        fwrite(STDERR, "User not found: {$email}\n");
        exit(1);
    }
}
echo "updated={$email} status={$status}\n";
