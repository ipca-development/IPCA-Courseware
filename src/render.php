<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function cw_get_template(PDO $pdo, string $templateKey): ?array {
    $stmt = $pdo->prepare("SELECT * FROM templates WHERE template_key = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$templateKey]);
    $t = $stmt->fetch(PDO::FETCH_ASSOC);
    return $t ?: null;
}

/**
 * Old simple renderer (kept)
 */
function cw_render_slide_html(string $cdnBase, array $slide, ?array $templateRow): string {
    $tpl = $templateRow ? (string)$templateRow['html_skeleton'] : '';
    $css = $templateRow ? (string)($templateRow['css'] ?? '') : '';

    if ($tpl === '') {
        $tpl = '<div class="ipca-canvas tpl-mltr"><div class="ipca-content"><div class="ipca-media-left">{{MEDIA_LEFT}}</div><div class="ipca-text-right">{{HTML_RIGHT}}</div></div></div>';
    }

    $imgUrl = cdn_url($cdnBase, (string)$slide['image_path']);
    $mediaImg = '<img src="' . h($imgUrl) . '" alt="">';

    $htmlLeft  = (string)($slide['html_left'] ?? '');
    $htmlRight = (string)($slide['html_right'] ?? '');

    $out = $tpl;
    $out = str_replace('{{MEDIA_LEFT}}', $mediaImg, $out);
    $out = str_replace('{{MEDIA_RIGHT}}', $mediaImg, $out);
    $out = str_replace('{{MEDIA_CENTER}}', $mediaImg, $out);
    $out = str_replace('{{HTML_LEFT}}', $htmlLeft, $out);
    $out = str_replace('{{HTML_RIGHT}}', $htmlRight, $out);

    if ($css !== '') $out = "<style>\n{$css}\n</style>\n" . $out;
    return $out;
}

/**
 * NEW: Render HTML from Fabric design_json.
 * We ignore Fabric’s drawing and instead convert objects to positioned HTML elements
 * inside the IPCA canvas.
 */
function cw_render_from_design_json(string $cdnBase, array $slide, ?array $templateRow, array $design): string {
    // base template wrapper: always IPCA canvas
    // We'll render absolute-positioned objects inside.
    $css = $templateRow ? (string)($templateRow['css'] ?? '') : '';

    $bg = "/assets/bg/ipca_bg.jpeg";

    $objects = $design['objects'] ?? [];
    if (!is_array($objects)) $objects = [];

    $html = "<div class=\"ipca-canvas\" style=\"background-image:url('{$bg}');\">";
    $html .= "<div style=\"position:absolute;inset:0;\">";

    foreach ($objects as $o) {
        // Fabric JSON fields
        $type = $o['type'] ?? '';
        $left = (float)($o['left'] ?? 0);
        $top  = (float)($o['top'] ?? 0);
        $w    = (float)($o['width'] ?? 100) * (float)($o['scaleX'] ?? 1);
        $h    = (float)($o['height'] ?? 30) * (float)($o['scaleY'] ?? 1);

        // clamp
        $left = max(0, $left); $top = max(0, $top);

        $style = "position:absolute;left:{$left}px;top:{$top}px;width:{$w}px;height:{$h}px;";

        // Textbox
        if ($type === 'textbox' || $type === 'text') {
            $text = htmlspecialchars((string)($o['text'] ?? ''), ENT_QUOTES, 'UTF-8');
            $fontSize = (int)($o['fontSize'] ?? 24);
            $fill = (string)($o['fill'] ?? '#0b2a4a');
            $bgc  = (string)($o['backgroundColor'] ?? 'rgba(255,255,255,0.75)');
            $pad  = 8;
            $html .= "<div style=\"{$style}font-size:{$fontSize}px;color:{$fill};background:{$bgc};padding:{$pad}px;border-radius:12px;overflow:hidden;\">{$text}</div>";
            continue;
        }

        // Group: treat as placeholder box
        if ($type === 'group') {
            $html .= "<div style=\"{$style}border:2px solid #0b2a4a;border-radius:12px;background:rgba(0,0,0,0.03);\"></div>";
            continue;
        }

        // Rect: could be redaction block
        if ($type === 'rect') {
            $fill = (string)($o['fill'] ?? 'rgba(255,255,255,0.96)');
            $html .= "<div style=\"{$style}background:{$fill};border-radius:12px;border:1px solid #ddd;\"></div>";
            continue;
        }
    }

    $html .= "</div></div>";

    if ($css !== '') $html = "<style>\n{$css}\n</style>\n" . $html;
    return $html;
}