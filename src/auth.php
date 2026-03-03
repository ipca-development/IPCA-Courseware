<?php
declare(strict_types=1);

function cw_is_logged_in(): bool {
    return !empty($_SESSION['user_id']) || !empty($_SESSION['cw_user_id']);
}

function cw_current_user(PDO $pdo = null): ?array {
    if ($pdo === null) {
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) $pdo = $GLOBALS['pdo'];
        else return null;
    }

    $uid = 0;
    if (!empty($_SESSION['user_id'])) $uid = (int)$_SESSION['user_id'];
    else if (!empty($_SESSION['cw_user_id'])) $uid = (int)$_SESSION['cw_user_id'];

    if ($uid <= 0) return null;

    $stmt = $pdo->prepare("SELECT id,email,name,role FROM users WHERE id=? LIMIT 1");
    $stmt->execute([$uid]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function cw_login(PDO $pdo, string $email, string $password): bool {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email=? LIMIT 1");
    $stmt->execute([$email]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u) return false;

    if (!password_verify($password, (string)$u['password_hash'])) return false;

    session_regenerate_id(true);

    $_SESSION['user_id'] = (int)$u['id'];
    $_SESSION['cw_user_id'] = (int)$u['id'];
    $_SESSION['cw_email'] = (string)$u['email'];
    $_SESSION['cw_name'] = (string)$u['name'];
    $_SESSION['cw_role'] = (string)$u['role'];

    return true;
}

function cw_logout(): void {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params["path"], '', $params["secure"], $params["httponly"]);
    }
    session_destroy();
}

function cw_require_login(): void {
    $path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if (strpos($path, '/login.php') !== false) return;

    if (!cw_is_logged_in()) {
        redirect('/login.php');
    }
}

function cw_require_admin(): void {
    global $pdo;
    cw_require_login();
    $u = cw_current_user($pdo);
    if (!$u || ($u['role'] ?? '') !== 'admin') redirect('/login.php');
}