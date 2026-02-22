<?php
declare(strict_types=1);

function cw_is_logged_in(): bool {
    return !empty($_SESSION['cw_user_id']) && !empty($_SESSION['cw_role']);
}

function cw_require_admin(): void {
    if (!cw_is_logged_in() || ($_SESSION['cw_role'] ?? '') !== 'admin') {
        redirect('/login.php');
    }
}

function cw_login(PDO $pdo, string $email, string $password): bool {
    $stmt = $pdo->prepare("SELECT id, email, name, password_hash, role FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $u = $stmt->fetch();
    if (!$u) return false;
    if (!password_verify($password, $u['password_hash'])) return false;

    $_SESSION['cw_user_id'] = (int)$u['id'];
    $_SESSION['cw_email']   = $u['email'];
    $_SESSION['cw_name']    = $u['name'];
    $_SESSION['cw_role']    = $u['role'];
    return true;
}

function cw_logout(): void {
    session_destroy();
}

function cw_current_user(): array {
    return [
        'id' => (int)($_SESSION['cw_user_id'] ?? 0),
        'email' => (string)($_SESSION['cw_email'] ?? ''),
        'name' => (string)($_SESSION['cw_name'] ?? ''),
        'role' => (string)($_SESSION['cw_role'] ?? ''),
    ];
}