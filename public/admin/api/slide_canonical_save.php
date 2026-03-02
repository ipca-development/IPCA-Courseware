<?php
require_once __DIR__ . '/../../../src/bootstrap.php';
cw_require_admin();
header('Content-Type: application/json; charset=utf-8');

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'invalid json']); exit; }

$slideId = (int)($data['slide_id'] ?? 0);
$narr = (string)($data['narration_en'] ?? '');

if ($slideId <= 0) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'missing slide_id']); exit; }

$stmt = $pdo->prepare("
  INSERT INTO slide_enrichment (slide_id, narration_en)
  VALUES (?,?)
  ON DUPLICATE KEY UPDATE narration_en=VALUES(narration_en)
");
$stmt->execute([$slideId, $narr]);

echo json_encode(['ok'=>true]);