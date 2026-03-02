<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

/**
 * SESSION (Safari-safe)
 * - Must run before any output
 * - SameSite=Lax so Safari keeps it
 */
if (session_status() === PHP_SESSION_NONE) {
    // Use a stable session name (avoids collisions)
    session_name('CWSESS');

    // Must be before session_start()
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => true,   // DO App Platform is HTTPS
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

// Backward compatibility mapping
if (!empty($_SESSION['cw_user_id']) && empty($_SESSION['user_id'])) {
    $_SESSION['user_id'] = (int)$_SESSION['cw_user_id'];
}

// CDN base
$CDN_BASE = rtrim(getenv('CW_CDN_BASE') ?: '', '/');
if ($CDN_BASE === '') {
    $CDN_BASE = 'https://ipca-media.nyc3.cdn.digitaloceanspaces.com';
}

// DB connection
$pdo = cw_db();