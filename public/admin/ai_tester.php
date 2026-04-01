<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

echo 'STEP 1<br>';

require_once __DIR__ . '/../../src/bootstrap.php';
echo 'STEP 2<br>';

cw_require_login();
echo 'STEP 3<br>';

$user = cw_current_user($pdo);
echo 'STEP 4<br>';

$role = (string)($user['role'] ?? '');
echo 'STEP 5<br>';

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

echo 'STEP 6<br>';

function table_exists(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = ?
    ");
    $stmt->execute([$tableName]);
    return (int)$stmt->fetchColumn() > 0;
}

echo 'STEP 7<br>';

$hasSsotTable = table_exists($pdo, 'ai_ssot_snapshots');
echo 'STEP 8<br>';

$hasRequestsTable = table_exists($pdo, 'ai_jake_requests');
echo 'STEP 9<br>';

$latestSsot = null;
$recentRequests = [];
echo 'STEP 10<br>';

if ($hasSsotTable) {
    $stmt = $pdo->query("
        SELECT *
        FROM ai_ssot_snapshots
        ORDER BY id DESC
        LIMIT 1
    ");
    $latestSsot = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
echo 'STEP 11<br>';

if ($hasRequestsTable) {
    $stmt = $pdo->query("
        SELECT id, request_title, request_type, status, created_at, updated_at
        FROM ai_jake_requests
        ORDER BY id DESC
        LIMIT 10
    ");
    $recentRequests = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
echo 'STEP 12<br>';

exit;