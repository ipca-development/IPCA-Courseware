<?php
require_once __DIR__ . '/../../../src/bootstrap.php';
cw_require_admin();
header('Content-Type: application/json; charset=utf-8');

try {
  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true);
  if (!is_array($data)) throw new RuntimeException("Invalid JSON");

  $slideId = (int)($data['slide_id'] ?? 0);
  if ($slideId <= 0) throw new RuntimeException("Missing slide_id");

  $hotspots = $data['hotspots'] ?? [];
  if (!is_array($hotspots)) throw new RuntimeException("hotspots must be array");

  foreach ($hotspots as $h) {
    $id = (int)($h['id'] ?? 0);
    $label = (string)($h['label'] ?? 'Video');
    $src = (string)($h['src'] ?? '');
    $x = (int)($h['x'] ?? 0);
    $y = (int)($h['y'] ?? 0);
    $w = (int)($h['w'] ?? 200);
    $hh = (int)($h['h'] ?? 120);
    $isDeleted = (int)($h['is_deleted'] ?? 0);

    // clamp
    $x = max(0, min(1600, $x));
    $y = max(0, min(900, $y));
    $w = max(10, min(1600, $w));
    $hh = max(10, min(900, $hh));

    if ($id > 0) {
      $stmt = $pdo->prepare("UPDATE slide_hotspots SET label=?, src=?, x=?, y=?, w=?, h=?, is_deleted=? WHERE id=? AND slide_id=?");
      $stmt->execute([$label,$src,$x,$y,$w,$hh,$isDeleted,$id,$slideId]);
    } else {
      // new
      $stmt = $pdo->prepare("INSERT INTO slide_hotspots (slide_id, kind, label, src, x, y, w, h, is_deleted) VALUES (?,?,?,?,?,?,?,?,?)");
      $stmt->execute([$slideId,'video',$label,$src,$x,$y,$w,$hh,$isDeleted]);
    }
  }

  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}