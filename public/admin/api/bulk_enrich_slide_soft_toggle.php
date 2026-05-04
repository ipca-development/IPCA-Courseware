<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';

cw_require_admin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$slideId = (int)($_POST['slide_id'] ?? 0);
$isDeleted = (int)($_POST['is_deleted'] ?? -1);
if ($slideId <= 0 || ($isDeleted !== 0 && $isDeleted !== 1)) {
    echo json_encode(['ok' => false, 'error' => 'slide_id and is_deleted (0|1) required']);
    exit;
}

$stmt = $pdo->prepare('UPDATE slides SET is_deleted = ? WHERE id = ? LIMIT 1');
$stmt->execute([$isDeleted, $slideId]);
if ($stmt->rowCount() < 1) {
    echo json_encode(['ok' => false, 'error' => 'slide not found']);
    exit;
}

echo json_encode(['ok' => true, 'slide_id' => $slideId, 'is_deleted' => $isDeleted]);
