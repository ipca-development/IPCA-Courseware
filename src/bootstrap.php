<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/time.php';

// CDN base
$CDN_BASE = rtrim(getenv('CW_CDN_BASE') ?: '', '/');
if ($CDN_BASE === '') {
    $CDN_BASE = 'https://ipca-media.nyc3.cdn.digitaloceanspaces.com';
}

// DB connection FIRST (session handler uses it)
$pdo = cw_db();

// DB session handler
require_once __DIR__ . '/session_db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name('CWSESS');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    // IMPORTANT: register DB handler BEFORE session_start()
    $handler = new CwDbSessionHandler($pdo);
    session_set_save_handler($handler, true);

    session_start();
}

// Compatibility: keep both key styles
if (!empty($_SESSION['cw_user_id']) && empty($_SESSION['user_id'])) {
    $_SESSION['user_id'] = (int)$_SESSION['cw_user_id'];
}
if (!empty($_SESSION['user_id']) && empty($_SESSION['cw_user_id'])) {
    $_SESSION['cw_user_id'] = (int)$_SESSION['user_id'];
}