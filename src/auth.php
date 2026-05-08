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

/** @internal Session key — admin sidebar / shell mode */
const CW_ADMIN_SHELL_MODE_KEY = 'cw_admin_shell_mode';

/** @internal Session key — which student UI an admin is previewing */
const CW_ADMIN_STUDENT_PREVIEW_ID_KEY = 'cw_admin_student_preview_id';

function cw_users_id_is_student(PDO $pdo, int $id): bool {
    if ($id <= 0) {
        return false;
    }
    $st = $pdo->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $r = $st->fetchColumn();
    return $r !== false && strtolower((string)$r) === 'student';
}

/**
 * Effective student-context user id for pages that support admin preview via ?user_id= or persisted session.
 * Non-admin users always get their own account id.
 */
function cw_student_view_user_id(PDO $pdo, ?array $u = null): int {
    if ($u === null) {
        $u = cw_current_user($pdo);
    }
    if (!$u) {
        return 0;
    }

    $role = strtolower(trim((string)($u['role'] ?? '')));
    $selfId = (int)($u['id'] ?? 0);

    if ($role !== 'admin') {
        return $selfId;
    }

    if (isset($_GET['user_id'])) {
        $cand = (int)$_GET['user_id'];
        if ($cand > 0 && cw_users_id_is_student($pdo, $cand)) {
            $_SESSION[CW_ADMIN_STUDENT_PREVIEW_ID_KEY] = $cand;
            return $cand;
        }
    }

    $sid = isset($_SESSION[CW_ADMIN_STUDENT_PREVIEW_ID_KEY])
        ? (int)$_SESSION[CW_ADMIN_STUDENT_PREVIEW_ID_KEY]
        : 0;

    if ($sid > 0 && cw_users_id_is_student($pdo, $sid)) {
        return $sid;
    }

    unset($_SESSION[CW_ADMIN_STUDENT_PREVIEW_ID_KEY]);
    return $selfId;
}

/**
 * Admin-only: which sidebar shell should load (does not replace the authenticated account role in the database).
 *
 * @return 'admin'|'instructor'|'student'
 */
function cw_admin_shell_mode(): string {
    $m = strtolower(trim((string)($_SESSION[CW_ADMIN_SHELL_MODE_KEY] ?? '')));
    if ($m !== 'admin' && $m !== 'instructor' && $m !== 'student') {
        return 'admin';
    }
    return $m;
}

/**
 * Resolve which navigation preset to render in the shell.
 *
 * Stored admin shell mode overrides only when the signed-in DB role is admin.
 */
function cw_effective_navigation_role(?array $u): string {
    if (!$u) {
        return '';
    }

    $db = strtolower(trim((string)($u['role'] ?? '')));
    if ($db !== 'admin') {
        return $db;
    }

    return match (cw_admin_shell_mode()) {
        'instructor' => 'instructor',
        'student' => 'student',
        default => 'admin',
    };
}

/** Student-user id appended to nav links while an admin uses the Student shell with a preview target. */
function cw_admin_navigation_student_preview_id(PDO $pdo, ?array $u): int {
    if (!$u || strtolower(trim((string)($u['role'] ?? ''))) !== 'admin') {
        return 0;
    }
    if (cw_effective_navigation_role($u) !== 'student') {
        return 0;
    }
    $vid = cw_student_view_user_id($pdo, $u);

    return cw_users_id_is_student($pdo, $vid) ? $vid : 0;
}

/**
 * Build a navigation href that preserves optional admin student-preview query pairs.
 *
 * Used only when `$previewUserId` is a validated student row id.
 */
function cw_nav_href_with_admin_student_preview(string $href, int $previewUserId): string {
    if ($previewUserId <= 0 || $href === '') {
        return $href;
    }

    $path = parse_url($href, PHP_URL_PATH);
    if (!is_string($path) || $path === '' || !str_starts_with($path, '/student/')) {
        return $href;
    }

    $query = [];
    $qRaw = parse_url($href, PHP_URL_QUERY);
    if (is_string($qRaw) && $qRaw !== '') {
        parse_str($qRaw, $query);
    }

    $query['user_id'] = $previewUserId;

    return $path . '?' . http_build_query($query);
}