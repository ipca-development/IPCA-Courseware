<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/helpers.php';
require_once $root . '/src/publishing/ControlledPublishingBookStyleManifestService.php';

$failures = array();
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$protected = array(
    'public/admin/compliance/controlled_book_editor.php' => '924bd98f8cb56e5154adc9b3a525bb69c8b3a9b6960eb16c447d7f143dee5c0f',
    'public/assets/controlled_book_editor.js' => '6f76a6bf25f5852a175ff402d46807aa9d8512ee9bc55e170aa34d70636c8d20',
    'public/assets/controlled_book_editor.css' => '2dbc860e42137df5c1cc7ebcf21409c962e411e99300dc46db989a9dc07cc2e2',
    'public/admin/api/controlled_book_editor_api.php' => 'ba11146a03046ea31c4423b6bc7a1d4332faf2d853e426156d1011843dd3eb1f',
    'public/student/manual_reader.php' => '9c442cd5db0f9c87ca291708e6e5d9f835a64ca7478bd77fcce7576f14052525',
);
foreach ($protected as $relative => $expected) {
    $actual = is_file($root . '/' . $relative) ? hash_file('sha256', $root . '/' . $relative) : false;
    $assert($actual === $expected, "Protected editor/online renderer changed: {$relative}");
}

$pdo = new PDO('sqlite::memory:');
$service = new ControlledPublishingBookStyleManifestService($pdo, $root);
$metadata = array(
    'paragraph_styles' => array(
        'body' => array('font_family' => 'serif', 'font_size' => 12, 'color' => '#123456'),
    ),
    'page_header' => array(
        'logo_url' => '/assets/manual_reader_content.css',
        'center_text' => "{book_title}\n{part_title}",
    ),
    'annex_page_header' => array(
        'center_text' => 'Annex {annex_number} — {annex_title}',
    ),
);
$version = array(
    'id' => 42,
    'book_key' => 'OM',
    'manual_code' => 'OM',
    'book_title' => 'Operations Manual',
    'version_label' => '7.2',
    'lifecycle_status' => 'released',
    'released_at' => '2026-08-13 12:00:00',
    'metadata_json' => json_encode($metadata, JSON_THROW_ON_ERROR),
);
$mainHeader = '<header class="cpb-page-header"><img src="/assets/manual_reader_content.css">Main {page}</header>';
$mainFooter = '<footer class="cpb-page-footer">Main footer {page_total}</footer>';
$annexHeader = '<header class="cpb-page-header">Annex {page}</header>';
$annexFooter = '<footer class="cpb-page-footer">Annex footer</footer>';
$source = array(
    'sections' => array(
        array(
            'section_id' => 10,
            'section_key' => 'part_1',
            'show_header_footer' => true,
            'header_template' => $mainHeader,
            'footer_template' => $mainFooter,
            'units' => array(),
        ),
        array(
            'section_id' => 20,
            'section_key' => 'annexes_annex_01',
            'show_header_footer' => true,
            'header_template' => $annexHeader,
            'footer_template' => $annexFooter,
            'units' => array(),
        ),
    ),
);

$first = $service->buildPublicationPackage($version, $source);
$second = $service->buildPublicationPackage($version, $source);
$assert($first === $second, 'Publication package is not deterministic.');
$assert(hash('sha256', ControlledPublishingBookStyleManifestService::canonicalJson($first['manifest'])) === $first['manifest_hash'], 'Manifest hash does not match canonical JSON.');
$assert(hash('sha256', $first['css']['content']) === $first['css']['hash'], 'CSS hash does not match exact CSS bytes.');
$assert(str_contains($first['css']['content'], '--reader-page-scale'), 'CSS lacks reader page scale variable.');
$assert(str_contains($first['css']['content'], '--reader-font-scale'), 'CSS lacks reader font scale variable.');
$assert(!str_contains($first['css']['content'], '.cpb-sheet{zoom:'), 'Book CSS must not scale only the sheet and bypass canonical page frames.');
$assert(str_contains($first['css']['content'], '.reader-page-body:not(.reader-page-cover){font-size:calc(11pt * var(--reader-font-scale,1));}'), 'Reader body font scaling is not backend-controlled.');
$assert(str_contains($first['css']['content'], '.reader-page-header-region,.reader-page-footer-region{--reader-font-scale:1;}'), 'Header/footer reader font-scale isolation is missing.');
$assert(!str_contains($first['css']['content'], '.cpb-page-header,.cpb-page-footer{font-size:initial;}'), 'Header/footer must inherit the golden publication typography.');
$assert(($first['manifest']['schema_version'] ?? '') === ControlledPublishingBookStyleManifestService::SCHEMA_VERSION, 'Manifest schema version is missing.');
$assert(isset($first['manifest']['styles'], $first['manifest']['page_bands']['main'], $first['manifest']['page_bands']['annex']), 'Manifest style/page-band configuration is incomplete.');
$assert(isset($first['manifest']['layout'], $first['manifest']['layout_hash'], $first['manifest']['render_pipeline']), 'Manifest layout/render pipeline identity is incomplete.');
$assert(($first['templates']['main']['rendered']['header_html'] ?? '') === $mainHeader, 'Main header template is not byte-identical to pagination source.');
$assert(($first['templates']['main']['rendered']['footer_html'] ?? '') === $mainFooter, 'Main footer template is not byte-identical to pagination source.');
$assert(($first['templates']['annex']['rendered']['header_html'] ?? '') === $annexHeader, 'Annex header template is not byte-identical to pagination source.');
$assert(($first['templates']['annex']['rendered']['footer_html'] ?? '') === $annexFooter, 'Annex footer template is not byte-identical to pagination source.');

$assetsByDescriptor = array();
foreach ($first['assets'] as $asset) {
    $assetsByDescriptor[(string)$asset['descriptor']] = $asset;
}
$localImage = $assetsByDescriptor['image:/assets/manual_reader_content.css'] ?? null;
$assert(is_array($localImage), 'Local image/logo asset is absent from inventory.');
$assert(
    is_array($localImage) && ($localImage['content_hash'] ?? null) === hash_file('sha256', $root . '/public/assets/manual_reader_content.css'),
    'Locally calculable asset hash is absent or incorrect.'
);
$assert(isset($assetsByDescriptor['font:serif']), 'Required font descriptor is absent from inventory.');

$timestampVariant = $version;
$timestampVariant['released_at'] = '2030-01-01 00:00:00';
$timestampPackage = $service->buildPublicationPackage($timestampVariant, $source);
$assert($timestampPackage['manifest_hash'] === $first['manifest_hash'], 'Timestamp leaked into manifest content hash.');

$unorderedA = array('z' => array('b' => 2, 'a' => 1), 'a' => 3);
$unorderedB = array('a' => 3, 'z' => array('a' => 1, 'b' => 2));
$assert(
    ControlledPublishingBookStyleManifestService::canonicalJson($unorderedA)
        === ControlledPublishingBookStyleManifestService::canonicalJson($unorderedB),
    'Recursive canonical sorting is not stable.'
);

if ($failures !== array()) {
    fwrite(STDERR, "Controlled publishing book style manifest contract: FAIL\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "Controlled publishing book style manifest contract: PASS\n";
echo "Deterministic manifest, CSS, templates, and assets verified\n";
echo "Protected Admin Editor and online renderer files: unchanged\n";
