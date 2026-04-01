<?php
require_once __DIR__ . '/bootstrap.php';

$email = getenv('CW_APP_ADMIN_EMAIL') ?: '';
$pass  = getenv('CW_APP_ADMIN_PASS') ?: '';
if ($email === '' || $pass === '') {
    exit("Set CW_APP_ADMIN_EMAIL and CW_APP_ADMIN_PASS env vars first.");
}

$hash = password_hash($pass, PASSWORD_DEFAULT);

$stmt = $pdo->prepare("SELECT id FROM users WHERE email=?");
$stmt->execute([$email]);
$exists = $stmt->fetchColumn();

if ($exists) {
    echo "Admin already exists.\n";
    exit;
}

$stmt = $pdo->prepare("INSERT INTO users (email, name, password_hash, role) VALUES (?,?,?, 'admin')");
$stmt->execute([$email, 'Admin', $hash]);

echo "Admin created. Now login at /login.php\n";