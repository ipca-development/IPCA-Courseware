<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/helpers.php';
require_once $root . '/src/publishing/ControlledPublishingBookRenderer.php';
require_once $root . '/src/publishing/ControlledPublishingBookStyleManifestService.php';
require_once $root . '/src/publishing/ControlledPublishingReaderLayoutProfile.php';

$pdo = new PDO('sqlite::memory:');
$styleService = new ControlledPublishingBookStyleService($pdo);
$headerService = new ControlledPublishingPageHeaderService($pdo);
$styles = $styleService->defaultBookStyles();
$styles['paragraph_styles']['body']['font_size'] = 12;
$styles['paragraph_styles']['body']['margin_top'] = 7;
$styles['paragraph_styles']['body']['margin_bottom'] = 9;
$styles['paragraph_styles']['subtitle_2']['margin_top'] = 11;
$styles['paragraph_styles']['subtitle_2']['margin_bottom'] = 6;

$svg = static function (string $label, string $background, int $width, int $height): string {
    $safeLabel = htmlspecialchars($label, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $source = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height
        . '" viewBox="0 0 ' . $width . ' ' . $height . '"><rect width="100%" height="100%" rx="4" fill="'
        . $background . '"/><path d="M12 ' . ($height - 12) . ' L34 12 L56 ' . ($height - 12)
        . ' Z" fill="#38bdf8"/><text x="68" y="' . (int)round($height * 0.62)
        . '" font-family="Arial,sans-serif" font-size="18" font-weight="700" fill="#fff">'
        . $safeLabel . '</text></svg>';
    return 'data:image/svg+xml;base64,' . base64_encode($source);
};

$logo = $svg('IPCA', '#0f2744', 180, 96);
$figure = $svg('Deterministic fixture image', '#334155', 560, 112);
$metadata = array(
    'paragraph_styles' => $styles['paragraph_styles'],
    'table_styles' => $styles['table_styles'],
    'callout_styles' => $styles['callout_styles'],
    'page_header' => array(
        'enabled' => true,
        'logo_url' => $logo,
        'logo_alt' => 'IPCA fixture logo',
        'logo_max_height' => 96,
        'row_height' => 24,
        'center_text' => "{book_title}\n{part_title}",
        'center_font_family' => 'sans',
        'center_font_size' => 11,
        'center_font_bold' => true,
        'right_text' => "Page: {page}\nRevision: {revision}",
        'right_font_family' => 'sans',
        'right_font_size' => 10,
        'right_font_bold' => true,
    ),
    'page_footer' => array(
        'enabled' => true,
        'row_height' => 26,
        'left_text' => 'CONTROLLED',
        'left_font_family' => 'sans',
        'left_font_size' => 9,
        'left_font_bold' => true,
        'center_text' => 'Geometry parity fixture',
        'center_font_family' => 'sans',
        'center_font_size' => 9,
        'right_text' => 'Page {page} of {page_total}',
        'right_font_family' => 'sans',
        'right_font_size' => 9,
    ),
);
$version = array(
    'id' => 9001,
    'book_key' => 'GEOMETRY_FIXTURE',
    'manual_code' => 'IPCA-GEO',
    'book_title' => 'Controlled Publication Geometry',
    'version_label' => '1.0',
    'effective_date' => '2026-08-13',
    'metadata_json' => json_encode($metadata, JSON_THROW_ON_ERROR),
);
$section = array(
    'id' => 7001,
    'section_id' => 7001,
    'section_key' => 'part_1_geometry',
    'title' => 'Representative Elements',
    'part_title' => 'PART 1 — REPRESENTATIVE ELEMENTS',
    'stable_anchor' => 'geometry-fixture',
    'allow_author_blocks' => false,
    'show_header_footer' => true,
    'flags' => array(),
);

$block = static function (int $id, string $type, array $payload): array {
    return array(
        'id' => $id,
        'block_type' => $type,
        'stable_anchor' => 'golden-' . $type . '-' . $id,
        'payload_json' => $payload,
        'change_status' => 'unchanged',
    );
};
$blocks = array(
    $block(1, 'heading', array(
        'level' => 2,
        'text' => 'Representative heading',
        'paragraph_style' => 'subtitle_2',
    )),
    $block(2, 'paragraph', array(
        'html' => 'Body paragraph with <strong>bold</strong> and <em>italic</em> text for deterministic typography.',
        'paragraph_style' => 'body',
    )),
    $block(3, 'list', array(
        'ordered' => true,
        'start_number' => 3,
        'items' => array('Ordered representative item'),
        'paragraph_style' => 'body',
    )),
    $block(4, 'list', array(
        'ordered' => false,
        'items' => array('Unordered representative item'),
        'paragraph_style' => 'body',
    )),
    $block(5, 'callout', array(
        'callout_type' => 'note',
        'title' => 'NOTE',
        'text' => 'This note verifies callout box, icon, title, and body geometry.',
    )),
    $block(6, 'table', array(
        'table_style_kind' => 'standard',
        'title' => 'Representative standard table',
        'headers' => array('Element', 'Value'),
        'rows' => array(array('Geometry', 'Deterministic'), array('Renderer', 'Shared')),
        'col_widths' => array(260, 260),
        'has_title_row' => true,
        'has_header_row' => true,
        'border_width' => 'thin',
        'border_color' => '#64748b',
    )),
    $block(7, 'image', array(
        'url' => $figure,
        'alt' => 'Figure 1 — deterministic local data URI',
        'width_pct' => 78,
        'rotation_deg' => 0,
    )),
);

$renderer = new ControlledPublishingBookRenderer();
$renderer->setBookStyles($styles, $styleService);
$renderer->setPageHeaderService($headerService);
$blocksHtml = $renderer->renderBlocks($blocks, ControlledPublishingBookRenderer::MODE_READ);
$headerConfig = $headerService->resolveFromMetadata($metadata);
$headerConfig['page_header']['logo_url'] = $logo;
$headerConfig['token_overrides'] = array('page' => '1', 'page_total' => '1');
$pageHtml = $renderer->renderPageShell(
    $version,
    $section,
    $blocksHtml,
    ControlledPublishingBookRenderer::MODE_READ,
    array('orientation' => 'portrait'),
    $headerConfig
);

$matchOne = static function (string $pattern, string $html, string $label): string {
    if (preg_match($pattern, $html, $match) !== 1) {
        throw new RuntimeException('Unable to extract rendered ' . $label . '.');
    }
    return $match[0];
};
$headerHtml = $matchOne('/<header class="cpb-page-header".*?<\/header>/s', $pageHtml, 'header');
$footerHtml = $matchOne('/<footer class="cpb-page-footer".*?<\/footer>/s', $pageHtml, 'footer');

$sourceUnits = array();
foreach ($blocks as $index => $sourceBlock) {
    $sourceUnits[] = array(
        'unit_key' => 'fixture-unit-' . ($index + 1),
        'block_type' => $sourceBlock['block_type'],
        'html' => $renderer->renderBlock($sourceBlock, ControlledPublishingBookRenderer::MODE_READ),
        'force_break_before' => false,
    );
}
$source = array(
    'book_key' => $version['book_key'],
    'version_id' => $version['id'],
    'sections' => array(array_merge($section, array(
        'header_template' => $headerHtml,
        'footer_template' => $footerHtml,
        'units' => $sourceUnits,
    ))),
);
$manifestService = new ControlledPublishingBookStyleManifestService($pdo, $root);
$package = $manifestService->buildPublicationPackage($version, $source);
$layout = ControlledPublishingReaderLayoutProfile::spec();
$paginationLayout = array(
    'pageWidth' => (float)$layout['page_width_px'],
    'pageHeight' => (float)$layout['page_height_px'],
    'canonicalPageWidth' => (float)$layout['page_width_px'],
    'canonicalPageHeight' => (float)$layout['page_height_px'],
    'headerFrame' => array(
        'x' => (float)$layout['sheet_padding_x_px'],
        'y' => (float)$layout['sheet_padding_top_px'],
        'width' => (float)($layout['page_width_px'] - 2 * $layout['sheet_padding_x_px']),
        'height' => (float)$layout['header_band_px'],
    ),
    'contentFrame' => array(
        'x' => (float)$layout['sheet_padding_x_px'],
        'y' => (float)($layout['sheet_padding_top_px'] + $layout['header_band_px'] + $layout['header_margin_bottom_px']),
        'width' => (float)($layout['page_width_px'] - 2 * $layout['sheet_padding_x_px']),
        'height' => (float)$layout['body_capacity_px'],
    ),
    'footerFrame' => array(
        'x' => (float)$layout['sheet_padding_x_px'],
        'y' => (float)($layout['sheet_padding_top_px'] + $layout['header_band_px']
            + $layout['header_margin_bottom_px'] + $layout['body_capacity_px']
            + $layout['footer_margin_top_px']),
        'width' => (float)($layout['page_width_px'] - 2 * $layout['sheet_padding_x_px']),
        'height' => (float)$layout['footer_band_px'],
    ),
    'innerMargin' => (float)$layout['sheet_padding_x_px'],
    'outerMargin' => (float)$layout['sheet_padding_x_px'],
    'topMargin' => (float)$layout['sheet_padding_top_px'],
    'bottomMargin' => (float)$layout['sheet_padding_bottom_px'],
    'viewportWidth' => (float)$layout['page_width_px'],
    'viewportHeight' => (float)$layout['page_height_px'],
    'mode' => 'singlePage',
    'fontScale' => 1.0,
    'layoutVersion' => 'reader-page-frame-v2:' . ControlledPublishingReaderLayoutProfile::layoutHash(),
);

$fixture = array(
    'fixture_version' => 'publication-geometry-golden-v1',
    'renderer' => ControlledPublishingBookStyleManifestService::RENDERER_VERSION,
    'manifest_hash' => $package['manifest_hash'],
    'online_page_html' => $pageHtml,
    'source' => $source,
    'book_style_css' => $package['css']['content'],
    'layout' => $paginationLayout,
    'categories' => array(
        'controlled_header_footer',
        'logo_bounds',
        'title_metadata_cells',
        'paragraph_typography_margins',
        'heading',
        'ordered_list',
        'unordered_list',
        'note_callout',
        'standard_table',
        'figure_image_caption',
    ),
);
$target = $root . '/tests/fixtures/publication_geometry_golden.json';
if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) {
    throw new RuntimeException('Unable to create fixture directory.');
}
$json = json_encode(
    $fixture,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
) . "\n";
file_put_contents($target, $json);
echo 'Wrote ' . str_replace($root . '/', '', $target) . ' (' . hash('sha256', $json) . ')' . PHP_EOL;
