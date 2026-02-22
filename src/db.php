<?php
declare(strict_types=1);

function cw_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $host = getenv('CW_DB_HOST');
    $port = getenv('CW_DB_PORT') ?: '25060';
    $db   = getenv('CW_DB_NAME');
    $user = getenv('CW_DB_USER');
    $pass = getenv('CW_DB_PASS');

    if (!$host || !$db || !$user) {
        throw new RuntimeException("DB env vars missing");
    }

    $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];

    // DO MySQL requires SSL; PDO uses it automatically when server requires it.
    // If you ever need explicit SSL CA, that’s possible, but App Platform typically works fine with REQUIRED.

    $pdo = new PDO($dsn, $user, $pass, $options);
    return $pdo;
}