<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function cw_get_template(PDO $pdo, string $templateKey): ?array {
    $stmt = $pdo->prepare("SELECT * FROM templates WHERE template_key = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$templateKey]);
    $t = $stmt->fetch(PDO::FETCH_ASSOC);
    return $t ?: null;
}

function cw_render_slide_html(string $cdnBase, array $slide, ?array $templateRow): string {
    $tpl = $templateRow ? (string)$templateRow['html_skeleton'] : '';
    $css = $templateRow ? (string)($templateRow['css'] ?? '') : '';

    if ($tpl === '') {
        $tpl = '<div class="cw-grid"><div>{{MEDIA_LEFT}}</div><div>{{HTML_RIGHT}}</div></div>';
    }

    $imgUrl = cdn_url($cdnBase, (string)$slide['image_path']);
    $mediaImg = '<img src="' . h($imgUrl) . '" style="width:100%;border-radius:12px;">';

    $htmlLeft  = (string)($slide['html_left'] ?? '');
    $htmlRight = (string)($slide['html_right'] ?? '');

    $out = $tpl;
    $out = str_replace('{{MEDIA_LEFT}}', $mediaImg, $out);
    $out = str_replace('{{MEDIA_RIGHT}}', $mediaImg, $out);
    $out = str_replace('{{MEDIA_CENTER}}', $mediaImg, $out);
    $out = str_replace('{{HTML_LEFT}}', $htmlLeft, $out);
    $out = str_replace('{{HTML_RIGHT}}', $htmlRight, $out);

    if ($css !== '') {
        $out = "<style>{$css}</style>" . $out;
    }

    return $out;
}