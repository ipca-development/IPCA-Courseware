<?php
declare(strict_types=1);

/**
 * Return the current logged in user row, or null.
 * Accepts optional PDO for convenience.
 */
function cw_current_user(?PDO $pdo = null): ?array {
    if ($pdo === null) {
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            $pdo = $GLOBALS['pdo'];
        } else {
            return null;
        }
    }

    // Backward compatibility: support cw_user_id
    if (empty($_SESSION['user_id']) && !empty($_SESSION['cw_user_id'])) {
        $_SESSION['user_id'] = (int)$_SESSION['cw_user_id'];
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
    if (!empty($_SESSION['user_id'])) return true;
    if (!empty($_SESSION['cw_user_id'])) return true;
    return false;
}

/**
 * Role-based home route.
 */
function cw_home_path_for_role(string $role): string {
    $role = strtolower(trim($role));
    if ($role === 'admin') return '/admin/dashboard.php';
    if ($role === 'supervisor' || $role === 'instructor') return '/instructor/cohorts.php';
    if ($role === 'student') return '/student/dashboard.php';
    // fallback
    return '/login.php';
}

/**
 * Log in (sets session user_id).
 */
function cw_login(PDO $pdo, string $email, string $password): bool {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email=? LIMIT 1");
    $stmt->execute([$email]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u) return false;

    if (!password_verify($password, (string)$u['password_hash'])) return false;

    $update = $pdo->prepare("
        UPDATE users
        SET
            last_login_at = NOW(),
            updated_at = NOW()
        WHERE id = ?
        LIMIT 1
    ");
    $update->execute([(int)$u['id']]);

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$u['id'];

    // also store compatibility fields (optional)
    $_SESSION['cw_user_id'] = (int)$u['id'];
    $_SESSION['cw_email']   = (string)$u['email'];
    $_SESSION['cw_name']    = (string)$u['name'];
    $_SESSION['cw_role']    = (string)$u['role'];

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
 * Require login.
 */
function cw_require_login(): void {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    if (strpos($path, '/login.php') !== false) return;

    if (!cw_is_logged_in()) {
        redirect('/login.php');
    }
}

/**
 * Require specific roles.
 * If user is logged in but wrong role, send them to their own home (prevents redirect loops).
 */
function cw_require_admin(): void {
    global $pdo;
    cw_require_login();
    $u = cw_current_user($pdo);
    if (!$u) redirect('/login.php');

    if (($u['role'] ?? '') !== 'admin') {
        redirect(cw_home_path_for_role((string)($u['role'] ?? '')));
    }
}

function cw_require_student(): void {
    global $pdo;
    cw_require_login();
    $u = cw_current_user($pdo);
    if (!$u) redirect('/login.php');

    $role = (string)($u['role'] ?? '');
    if ($role !== 'student' && $role !== 'admin') {
        redirect(cw_home_path_for_role($role));
    }
}

function cw_require_supervisor(): void {
    global $pdo;
    cw_require_login();
    $u = cw_current_user($pdo);
    if (!$u) redirect('/login.php');

    $role = (string)($u['role'] ?? '');
    if ($role !== 'supervisor' && $role !== 'admin') {
        redirect(cw_home_path_for_role($role));
    }
}