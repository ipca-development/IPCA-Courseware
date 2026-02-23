<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/render.php';

session_start();

$CDN_BASE = rtrim(getenv('CW_CDN_BASE') ?: '', '/');
if ($CDN_BASE === '') {
    // You can hardcode temporarily if you want
    $CDN_BASE = 'https://ipca-media.nyc3.cdn.digitaloceanspaces.com';
}

$pdo = cw_db();
