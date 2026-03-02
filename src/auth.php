<?php
declare(strict_types=1);

function cw_current_user(PDO $pdo = null): ?array {
    if ($pdo === null) {
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            $pdo = $GLOBALS['pdo'];
        } else {
            return null;
        }
    }

    if (empty($_SESSION['user_id'])) return null;
    $uid = (int)$_SESSION['user_id'];
    if ($uid <= 0) return null;

    $stmt = $pdo->prepare("SELECT id,email,name,role FROM users WHERE id=? LIMIT 1");
    $stmt->execute([$uid]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    return $u ?: null;
}

function cw_is_logged_in(): bool {
    return !empty($_SESSION['user_id']);
}

function cw_login(PDO $pdo, string $email, string $password): bool {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email=? LIMIT 1");
    $stmt->execute([$email]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u) return false;

    if (!password_verify($password, (string)$u['password_hash'])) return false;

    // Regenerate session to avoid fixation issues
    session_regenerate_id(true);

    $_SESSION['user_id'] = (int)$u['id'];
    $_SESSION['cw_user_id'] = (int)$u['id']; // compat
    return true;
}

function cw_logout(): void {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], '', $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}

/**
 * Builds a safe local "next" URL.
 */
function cw_next_param(): string {
    $path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if ($path === '') $path = '/';
    // never send them back to login/logout
    if (strpos($path, '/login.php') !== false || strpos($path, '/logout.php') !== false) {
        $path = '/admin/dashboard.php';
    }
    return $path;
}

function cw_require_login(): void {
    // NEVER redirect while already on login page (prevents loops)
    $path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if (strpos($path, '/login.php') !== false) return;

    if (empty($_SESSION['user_id'])) {
        $next = urlencode(cw_next_param());
        redirect('/login.php?next=' . $next);
    }
}

function cw_require_admin(): void {
    global $pdo;
    cw_require_login();
    $u = cw_current_user($pdo);
    if (!$u || ($u['role'] ?? '') !== 'admin') {
        redirect('/login.php?next=' . urlencode('/admin/dashboard.php'));
    }
}

function cw_require_student(): void {
    global $pdo;
    cw_require_login();
    $u = cw_current_user($pdo);
    if (!$u || ($u['role'] ?? '') !== 'student') {
        redirect('/login.php?next=' . urlencode('/student/dashboard.php'));
    }
}

function cw_require_supervisor(): void {
    global $pdo;
    cw_require_login();
    $u = cw_current_user($pdo);
    if (!$u || ($u['role'] ?? '') !== 'supervisor') {
        redirect('/login.php?next=' . urlencode('/instructor/dashboard.php'));
    }
}