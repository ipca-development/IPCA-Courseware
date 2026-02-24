<?php
require_once __DIR__ . '/../../../src/bootstrap.php';
cw_require_admin();

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$slideId = (int)($data['slide_id'] ?? 0);
$design  = $data['design_json'] ?? null;
$render  = (int)($data['render'] ?? 0);

if ($slideId <= 0 || !$design) {
    echo json_encode(['ok'=>false,'error'=>'Invalid payload']);
    exit;
}

// Save JSON
$stmt = $pdo->prepare("UPDATE slides SET design_json=? WHERE id=?");
$stmt->execute([json_encode($design), $slideId]);

// Optional render
if ($render === 1) {
    // Load slide + template
    $stmt = $pdo->prepare("SELECT * FROM slides WHERE id=? LIMIT 1");
    $stmt->execute([$slideId]);
    $slide = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($slide) {
        $templateRow = cw_get_template($pdo, (string)$slide['template_key']);
        $rendered = cw_render_from_design_json($CDN_BASE, $slide, $templateRow, $design);
        $stmt = $pdo->prepare("UPDATE slides SET html_rendered=? WHERE id=?");
        $stmt->execute([$rendered, $slideId]);
    }
}

echo json_encode(['ok'=>true]);