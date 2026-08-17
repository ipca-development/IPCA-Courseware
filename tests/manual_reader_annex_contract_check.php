<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = array();

$requirements = array(
    'src/publishing/ControlledPublishingReaderService.php' => array(
        'isAnnexCoverSection',
        "'manual_title']",
        "' Annexes'",
        'cover_page_thumbnail_url',
        'isAnnexCrossRefSection',
        'renderAnnexCrossRefShell',
    ),
    'src/publishing/ControlledPublishingPaginationService.php' => array(
        "\$isAnnexCover = \$key === 'annexes'",
        "\$isAnnexSection = \$parentKey === 'annexes'",
        "\$flags['is_cover'] || \$flags['is_annex_cover']",
        '$pageBreakBefore = $isCover || $isAnnexCover || $isPart0 || $isAnnexSection',
    ),
    'ipca-manual-reader-ios/IPCAManualReader/Views/ManualPageWebView.swift' => array(
        'readerAnnexAction',
        'mr-annex-share',
        "action: 'open'",
    ),
    'public/student/api/manual_reader_cover_thumbnail.php' => array(
        'page_map_hash',
        'image/png',
        'render_annex_pdf.cjs',
        'ETag:',
        'must-revalidate',
    ),
    'scripts/render_annex_pdf.cjs' => array(
        'printBackground: true',
        'preferCSSPageSize: true',
        'page.screenshot',
    ),
);

foreach ($requirements as $relative => $markers) {
    $source = is_file($root . '/' . $relative)
        ? (string)file_get_contents($root . '/' . $relative)
        : '';
    foreach ($markers as $marker) {
        if (!str_contains($source, $marker)) {
            $failures[] = $relative . ' missing: ' . $marker;
        }
    }
}

if ($failures !== array()) {
    fwrite(STDERR, "Manual reader Annex contract: FAIL\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Manual reader Annex contract: PASS\n";
