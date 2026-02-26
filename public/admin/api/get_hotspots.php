<?php
require_once __DIR__ . '/../../../src/bootstrap.php';
cw_require_admin();
header('Content-Type: application/json; charset=utf-8');

$slideId = (int)($_GET['slide_id'] ?? 0);
if ($slideId <= 0) { echo json_encode(['ok'=>false,'error'=>'missing slide_id']); exit; }

$stmt = $pdo->prepare("SELECT id, kind, label, src, x, y, w, h, is_deleted FROM slide_hotspots WHERE slide_id=? ORDER BY id ASC");
$stmt->execute([$slideId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['ok'=>true,'hotspots'=>$rows]);