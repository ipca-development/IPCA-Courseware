<?php
require_once __DIR__ . '/../../../src/bootstrap.php';
cw_require_admin();

header('Content-Type: application/json');

$slideId = (int)($_POST['slide_id'] ?? 0);
$target = (string)($_POST['target'] ?? 'right'); // 'left' or 'right'
$alsoFillHtml = isset($_POST['fill_html']) ? 1 : 0;

if ($slideId <= 0) {
    echo json_encode(['ok'=>false,'error'=>'Missing slide_id']);
    exit;
}

$stmt = $pdo->prepare("SELECT id, image_path FROM slides WHERE id=? LIMIT 1");
$stmt->execute([$slideId]);
$slide = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$slide) {
    echo json_encode(['ok'=>false,'error'=>'Slide not found']);
    exit;
}

$imageUrl = cdn_url($CDN_BASE, (string)$slide['image_path']);

// Download image to temp file
$tmpDir = sys_get_temp_dir() . '/cw_ocr';
@mkdir($tmpDir, 0777, true);

$tmpImg = $tmpDir . "/slide_{$slideId}.png";
$tmpOut = $tmpDir . "/slide_{$slideId}";

$imgData = @file_get_contents($imageUrl);
if ($imgData === false) {
    echo json_encode(['ok'=>false,'error'=>'Could not download image from CDN','url'=>$imageUrl]);
    exit;
}
file_put_contents($tmpImg, $imgData);

// Run tesseract
$cmd = "tesseract " . escapeshellarg($tmpImg) . " " . escapeshellarg($tmpOut) . " -l eng 2>/dev/null";
@exec($cmd, $output, $code);

if ($code !== 0) {
    echo json_encode(['ok'=>false,'error'=>'Tesseract failed (is it installed in Docker?)']);
    exit;
}

$txtFile = $tmpOut . ".txt";
$rawText = @file_get_contents($txtFile);
if ($rawText === false) $rawText = "";

// Normalize text a bit
$rawText = preg_replace("/\r\n/", "\n", $rawText);
$rawText = trim($rawText);

// Save raw OCR
$pdo->prepare("UPDATE slides SET raw_ocr_text=? WHERE id=?")->execute([$rawText, $slideId]);

// Optional: create simple HTML
$generatedHtml = null;
if ($alsoFillHtml) {
    $lines = array_values(array_filter(array_map('trim', explode("\n", $rawText)), function($l){
        return $l !== '';
    }));

    // Very simple heuristic:
    // - If many short lines, treat as bullets
    $short = 0;
    foreach ($lines as $l) {
        if (mb_strlen($l) <= 60) $short++;
    }

    if (count($lines) > 0 && ($short / max(1,count($lines))) > 0.6) {
        $items = '';
        foreach ($lines as $l) {
            $safe = htmlspecialchars($l, ENT_QUOTES, 'UTF-8');
            $items .= "<li>{$safe}</li>\n";
        }
        $generatedHtml = "<ul>\n{$items}</ul>";
    } else {
        $paras = [];
        $buf = [];
        foreach ($lines as $l) {
            $buf[] = $l;
            if (mb_strlen($l) < 40) { // paragraph break hint
                $paras[] = implode(' ', $buf);
                $buf = [];
            }
        }
        if ($buf) $paras[] = implode(' ', $buf);

        $html = '';
        foreach ($paras as $p) {
            $safe = htmlspecialchars($p, ENT_QUOTES, 'UTF-8');
            $html .= "<p>{$safe}</p>\n";
        }
        $generatedHtml = $html;
    }

    if ($target === 'left') {
        $pdo->prepare("UPDATE slides SET html_left=? WHERE id=?")->execute([$generatedHtml, $slideId]);
    } else {
        $pdo->prepare("UPDATE slides SET html_right=? WHERE id=?")->execute([$generatedHtml, $slideId]);
    }
}

echo json_encode([
    'ok'=>true,
    'slide_id'=>$slideId,
    'download_url'=>$imageUrl,
    'raw_ocr_chars'=>mb_strlen($rawText),
    'filled_html'=>$alsoFillHtml ? 1 : 0
]);