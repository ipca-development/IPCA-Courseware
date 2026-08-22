<?php
require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/theory_studio/TheoryStudioIsolation.php';
cw_require_admin();
header('Content-Type: application/json; charset=utf-8');

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'invalid json']); exit; }

$slideId = (int)($data['slide_id'] ?? 0);
if ($slideId <= 0) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'missing slide_id']); exit; }
try {
    theory_studio_require_legacy_slide($pdo, $slideId);
} catch (TheoryStudioException $e) {
    http_response_code($e->httpStatus);
    echo json_encode(['ok' => false, 'error_code' => $e->errorCode, 'error' => $e->getMessage()]);
    exit;
}

$narrEn = (string)($data['narration_en'] ?? '');
$narrEs = (string)($data['narration_es'] ?? '');

$stmt = $pdo->prepare("
  INSERT INTO slide_enrichment (slide_id, narration_en, narration_es)
  VALUES (?,?,?)
  ON DUPLICATE KEY UPDATE
    narration_en=VALUES(narration_en),
    narration_es=VALUES(narration_es)
");
$stmt->execute([$slideId, $narrEn, $narrEs]);

echo json_encode(['ok'=>true]);