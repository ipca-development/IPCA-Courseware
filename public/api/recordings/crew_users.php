<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    $stmt = $pdo->query("
        SELECT
            id,
            COALESCE(NULLIF(TRIM(name), ''), email, CONCAT('User #', id)) AS name,
            COALESCE(email, '') AS email,
            COALESCE(role, '') AS role
        FROM users
        WHERE role IN ('student', 'instructor', 'supervisor', 'chief_instructor')
        ORDER BY name ASC, email ASC, id ASC
    ");
    $rows = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();
    $users = array_map(static function (array $row): array {
        return array(
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'email' => (string)$row['email'],
            'role' => (string)$row['role'],
        );
    }, is_array($rows) ? $rows : array());

    echo json_encode(array(
        'ok' => true,
        'users' => $users,
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(array(
        'ok' => false,
        'error' => $e->getMessage(),
        'users' => array(),
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
