<?php
declare(strict_types=1);

/**
 * Read-only E-gle connector. Rejects any non-SELECT/SHOW/DESCRIBE statement.
 */
function egle_readonly_connect(string $host, int $port, string $db, string $user, string $pass): PDO
{
    $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => true,
    ]);
    // Defense in depth: attempt read-only session (may be ignored depending on privileges)
    try {
        $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    } catch (Throwable $e) {
        // continue; application-layer SELECT-only enforcement remains
    }
    return $pdo;
}

function egle_select(PDO $pdo, string $sql, array $params = []): array
{
    $trimmed = ltrim($sql);
    if (!preg_match('/^(SELECT|SHOW|DESCRIBE|EXPLAIN|WITH)\b/i', $trimmed)) {
        throw new RuntimeException('E-gle adapter is read-only. Blocked SQL: ' . substr($trimmed, 0, 120));
    }
    // Block multiple statements
    if (str_contains($trimmed, ';') && !preg_match('/;\s*$/', $trimmed)) {
        throw new RuntimeException('Multiple SQL statements are not allowed in E-gle adapter.');
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll() ?: [];
}

function php_unserialize_safe(mixed $raw): array
{
    if ($raw === null) {
        return ['ok' => true, 'data' => null, 'status' => 'EMPTY', 'error' => null];
    }
    if (is_array($raw)) {
        return ['ok' => true, 'data' => $raw, 'status' => 'OK', 'error' => null];
    }
    if (!is_string($raw)) {
        return ['ok' => false, 'data' => null, 'status' => 'FAIL', 'error' => 'non_string_blob'];
    }
    if ($raw === '' || $raw === 'N;') {
        return ['ok' => true, 'data' => null, 'status' => 'EMPTY', 'error' => null];
    }
    $prev = set_error_handler(static function () {
        return true;
    });
    try {
        $data = unserialize($raw, ['allowed_classes' => false]);
    } finally {
        if ($prev !== null) {
            set_error_handler($prev);
        } else {
            restore_error_handler();
        }
    }
    if ($data === false && $raw !== 'b:0;') {
        return ['ok' => false, 'data' => null, 'status' => 'FAIL', 'error' => 'unserialize_failed'];
    }
    return ['ok' => true, 'data' => $data, 'status' => 'OK', 'error' => null];
}

function null_if_zero_date(?string $d): ?string
{
    if ($d === null || $d === '' || $d === '0000-00-00' || $d === '0000-00-00 00:00:00') {
        return null;
    }
    return $d;
}
