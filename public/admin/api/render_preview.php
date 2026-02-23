<?php
require_once __DIR__ . '/../../../src/bootstrap.php';
cw_require_admin();

header('Content-Type: text/html; charset=utf-8');

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

$templateKey = (string)($data['template_key'] ?? 'MEDIA_LEFT_TEXT_RIGHT');
$imagePath   = (string)($data['image_path'] ?? '');
$htmlLeft    = (string)($data['html_left'] ?? '');
$htmlRight   = (string)($data['html_right'] ?? '');

if ($imagePath === '') { http_response_code(400); exit("Missing image_path"); }

$templateRow = cw_get_template($pdo, $templateKey);

echo "<!doctype html><html><head><meta charset='utf-8'>";
echo "<meta name='viewport' content='width=device-width, initial-scale=1'>";
echo "<link rel='stylesheet' href='/assets/app.css'>";
echo "<style>body{margin:0;padding:12px;background:#f4f6ff;}</style>";
echo "</head><body>";

echo cw_render_slide_html($CDN_BASE, [
    'image_path' => $imagePath,
    'html_left' => $htmlLeft,
    'html_right' => $htmlRight,
], $templateRow);

echo "</body></html>";