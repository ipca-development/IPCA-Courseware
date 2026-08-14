<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/src/publishing/ControlledPublishingBookStyleService.php');
$api = file_get_contents($root . '/public/admin/api/controlled_book_editor_api.php');
$editor = file_get_contents($root . '/public/assets/controlled_book_editor.js');
$css = file_get_contents($root . '/public/assets/controlled_book_editor.css');

if (!is_string($service) || !is_string($api) || !is_string($editor) || !is_string($css)) {
    fwrite(STDERR, "Unable to read style-copy implementation files.\n");
    exit(1);
}

$requiredServiceMarkers = array(
    'copyStylesFromVersion',
    "Released versions cannot receive copied styles.",
    "\$targetMeta['paragraph_styles']",
    "\$targetMeta['table_styles']",
    "\$targetMeta['callout_styles']",
    "\$targetMeta['page_header']",
    "\$targetMeta['page_footer']",
    "'annex_page_header', 'annex_page_footer'",
    'stripRedundantBlockTypography',
);
foreach ($requiredServiceMarkers as $marker) {
    if (!str_contains($service, $marker)) {
        fwrite(STDERR, "Missing style-copy service marker: {$marker}\n");
        exit(1);
    }
}

if (preg_match(
    '/function copyStylesFromVersion\b.*?\$targetMeta\s*\[\s*[\'"]callout_presets[\'"]\s*\]\s*=/s',
    $service
)) {
    fwrite(STDERR, "Style copy must preserve target callout preset text.\n");
    exit(1);
}

foreach (array('list_style_copy_sources', 'copy_book_styles') as $action) {
    if (!str_contains($api, "case '{$action}'")) {
        fwrite(STDERR, "Missing style-copy API action: {$action}\n");
        exit(1);
    }
}

foreach (array(
    'cpb-style-copy-select',
    'cpb-style-copy-button',
    "apiPost('copy_book_styles'",
    'Manual content will not be copied.',
) as $marker) {
    if (!str_contains($editor, $marker)) {
        fwrite(STDERR, "Missing style-copy editor marker: {$marker}\n");
        exit(1);
    }
}

if (!str_contains($css, '.cpb-style-copy-row')) {
    fwrite(STDERR, "Missing style-copy dialog styling.\n");
    exit(1);
}

echo "Controlled publishing book style copy: PASS\n";
echo "Copied: paragraph, table, callout visuals, main and annex header/footer\n";
echo "Preserved: manual content and callout preset text\n";
