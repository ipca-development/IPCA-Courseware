<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

/**
 * SESSION (fix redirect loops on Safari/DO App Platform)
 * - Only start once
 * - Secure/HttpOnly cookie
 * - SameSite=Lax so Safari keeps the cookie
 */
if (session_status() === PHP_SESSION_NONE) {
    // Must be before session_start()
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => true,   // site is https on DO
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// CDN base
$CDN_BASE = rtrim(getenv('CW_CDN_BASE') ?: '', '/');
if ($CDN_BASE === '') {
    $CDN_BASE = 'https://ipca-media.nyc3.cdn.digitaloceanspaces.com';
}

// DB connection
$pdo = cw_db();