<?php
require_once __DIR__ . '/../../../src/bootstrap.php';
cw_require_admin();

header('Content-Type: application/json');

$slideId = (int)($_GET['slide_id'] ?? 0);
if ($slideId <= 0) { echo json_encode(['ok'=>false,'error'=>'Missing slide_id']); exit; }

$stmt = $pdo->prepare("SELECT design_json FROM slides WHERE id=? LIMIT 1");
$stmt->execute([$slideId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode([
  'ok' => true,
  'design_json' => $row ? $row['design_json'] : null
]);