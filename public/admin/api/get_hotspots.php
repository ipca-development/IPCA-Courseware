<?php
require_once __DIR__ . '/../../../src/bootstrap.php';
cw_require_admin();
header('Content-Type: application/json; charset=utf-8');

$slideId = (int)($_GET['slide_id'] ?? 0);
if ($slideId <= 0) { echo json_encode(['ok'=>false,'error'=>'missing slide_id']); exit; }

// Get slide external lesson + page
$stmt = $pdo->prepare("
  SELECT s.id, s.page_number, l.external_lesson_id
  FROM slides s
  JOIN lessons l ON l.id=s.lesson_id
  WHERE s.id=? LIMIT 1
");
$stmt->execute([$slideId]);
$info = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$info) { echo json_encode(['ok'=>false,'error'=>'slide not found']); exit; }

$lessonId = (int)$info['external_lesson_id'];
$pageNum  = (int)$info['page_number'];

// Load hotspots
$stmt = $pdo->prepare("SELECT id, kind, label, src, x, y, w, h, is_deleted FROM slide_hotspots WHERE slide_id=? ORDER BY id ASC");
$stmt->execute([$slideId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Find suggested src from manifest
$suggested = '';
$manifestPath = __DIR__ . '/../../assets/kings_videos_manifest.json';

if (file_exists($manifestPath)) {
    $raw = file_get_contents($manifestPath);
    $arr = json_decode($raw, true);

    if (is_array($arr)) {
        foreach ($arr as $item) {
            $lid = (int)($item['lessonId'] ?? 0);
            $pg  = (int)($item['page'] ?? 0);

            if ($lid === $lessonId && $pg === $pageNum) {
                $urls = $item['videoUrls'] ?? [];
                if (is_array($urls) && count($urls) > 0) {
                    $u = (string)$urls[0];
                    $base = basename(parse_url($u, PHP_URL_PATH) ?: $u);

                    $videosBase = getenv('CW_VIDEOS_BASE') ?: 'ks_videos/private';

                    // ✅ IMPORTANT: your actual stored filename format includes page prefix
                    $pagePrefix = 'page_' . str_pad((string)$pageNum, 3, '0', STR_PAD_LEFT) . '__';

                    $suggested = rtrim($videosBase,'/') . '/lesson_' . $lessonId . '/' . $pagePrefix . $base;
                }
                break;
            }
        }
    }
}

echo json_encode(['ok'=>true,'hotspots'=>$rows,'suggested_src'=>$suggested]);