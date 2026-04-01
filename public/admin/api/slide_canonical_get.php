<?php
require_once __DIR__ . '/../../../src/bootstrap.php';
cw_require_admin();
header('Content-Type: application/json; charset=utf-8');

$slideId = (int)($_GET['slide_id'] ?? 0);
if ($slideId <= 0) { echo json_encode(['ok'=>false,'error'=>'missing slide_id']); exit; }

$stmt = $pdo->prepare("SELECT narration_en, narration_es FROM slide_enrichment WHERE slide_id=? LIMIT 1");
$stmt->execute([$slideId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$narrEn = (string)($row['narration_en'] ?? '');
$narrEs = (string)($row['narration_es'] ?? '');

// refs
$stmt = $pdo->prepare("
  SELECT ref_type, ref_code, ref_title, confidence, notes
  FROM slide_references
  WHERE slide_id=? AND ref_type IN ('PHAK','ACS')
  ORDER BY ref_type, id
");
$stmt->execute([$slideId]);
$refs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$phak = []; $acs = [];
foreach ($refs as $r) {
  if ($r['ref_type'] === 'PHAK') $phak[] = $r;
  if ($r['ref_type'] === 'ACS')  $acs[]  = $r;
}

echo json_encode([
  'ok'=>true,
  'narration_en'=>$narrEn,
  'narration_es'=>$narrEs,
  'phak'=>$phak,
  'acs'=>$acs
]);