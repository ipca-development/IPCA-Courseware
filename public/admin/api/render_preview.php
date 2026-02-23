<?php
require_once __DIR__ . '/../../../src/bootstrap.php';
cw_require_admin();

header('Content-Type: text/html; charset=utf-8');

// Accept JSON payload
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

$templateKey = (string)($data['template_key'] ?? 'MEDIA_LEFT_TEXT_RIGHT');
$imagePath   = (string)($data['image_path'] ?? '');
$htmlLeft    = (string)($data['html_left'] ?? '');
$htmlRight   = (string)($data['html_right'] ?? '');

if ($imagePath === '') {
    http_response_code(400);
    echo "Missing image_path";
    exit;
}

// Load template
$templateRow = cw_get_template($pdo, $templateKey);

// Render using your existing renderer
$slide = [
    'image_path' => $imagePath,
    'html_left'  => $htmlLeft,
    'html_right' => $htmlRight,
];

// Wrap in a minimal preview container so it looks nice in an iframe
echo "<!doctype html><html><head><meta charset='utf-8'>";
echo "<style>
body{margin:0;padding:12px;background:#f4f6ff;font-family:system-ui;}
.preview-wrap{background:white;border-radius:14px;padding:14px;box-shadow:0 6px 18px rgba(0,0,0,.08);}
</style>";
echo "</head><body><div class='preview-wrap'>";

echo cw_render_slide_html($CDN_BASE, $slide, $templateRow);

echo "</div></body></html>";