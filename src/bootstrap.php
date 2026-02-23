<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/render.php';

/**
 * IMPORTANT:
 * - Only start session once
 * - Set cookie params before starting the session
 * - No echo/print in this file
 */

// CDN base
$CDN_BASE = rtrim(getenv('CW_CDN_BASE') ?: '', '/');
if ($CDN_BASE === '') {
    $CDN_BASE = 'https://ipca-media.nyc3.cdn.digitaloceanspaces.com';
}

// DB connection (safe to create before session)
$pdo = cw_db();

// Session cookie settings (behind HTTPS proxy, always use secure cookies)
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    // Keep sessions longer (optional)
    ini_set('session.gc_maxlifetime', '28800'); // 8 hours
    ini_set('session.cookie_lifetime', '0');

    session_start();
}