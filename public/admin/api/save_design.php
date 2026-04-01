<?php
require_once __DIR__ . '/../../../src/bootstrap.php';
cw_require_admin();

header('Content-Type: application/json; charset=utf-8');

@set_time_limit(120);
@ini_set('memory_limit', '256M');

try {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        throw new RuntimeException('Empty request body');
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid JSON payload');
    }

    $slideId = (int)($data['slide_id'] ?? 0);
    $design  = $data['design_json'] ?? null;
    $render  = (int)($data['render'] ?? 0);

    if ($slideId <= 0) throw new RuntimeException('Missing slide_id');
    if (!is_array($design)) throw new RuntimeException('design_json must be an object');
    if (!isset($design['objects']) || !is_array($design['objects'])) {
        throw new RuntimeException('design_json.objects missing/invalid');
    }

    $designStr = json_encode($design, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($designStr === false) {
        throw new RuntimeException('Failed to encode design_json');
    }

    // Save JSON
    $stmt = $pdo->prepare("UPDATE slides SET design_json=? WHERE id=?");
    $stmt->execute([$designStr, $slideId]);

    // Optional render
    if ($render === 1) {
        $stmt = $pdo->prepare("SELECT * FROM slides WHERE id=? LIMIT 1");
        $stmt->execute([$slideId]);
        $slide = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$slide) throw new RuntimeException('Slide not found for render');

        if (!function_exists('cw_get_template')) {
            throw new RuntimeException('Template loader missing (cw_get_template)');
        }
        if (!function_exists('cw_render_from_design_json')) {
            throw new RuntimeException('Renderer missing (cw_render_from_design_json). Check src/render.php');
        }

        $templateRow = cw_get_template($pdo, (string)$slide['template_key']);

        // Render can throw; catch via outer try/catch
        $rendered = cw_render_from_design_json($CDN_BASE, $slide, $templateRow, $design);

        $stmt = $pdo->prepare("UPDATE slides SET html_rendered=? WHERE id=?");
        $stmt->execute([$rendered, $slideId]);
    }

    echo json_encode(['ok'=>true, 'slide_id'=>$slideId, 'rendered'=>($render===1)]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}