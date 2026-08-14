<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = array();

$protected = array(
    'public/admin/compliance/controlled_book_editor.php'
        => '32b2edd0a696ccb8759f61841e834f1dce035ce98608eed74eaf151b8e18ed45',
    'public/assets/controlled_book_editor.js'
        => '59b987de8f33b732356f76e13a7564c56262aaf14ee885eaf1eef1648d187365',
    'public/assets/controlled_book_editor.css'
        => 'b564fd7623989d8804e972bf6e9bd6d51abfa015f4238c9bd28cef5c63170771',
    'public/admin/api/controlled_book_editor_api.php'
        => 'ba11146a03046ea31c4423b6bc7a1d4332faf2d853e426156d1011843dd3eb1f',
    'src/publishing/ControlledPublishingBlockService.php'
        => 'd63175aa9e0292b9137f27a38eeb913337e6af8ab5e6086cdf4207385474f265',
);
foreach ($protected as $relative => $hash) {
    if (!is_file($root . '/' . $relative) || hash_file('sha256', $root . '/' . $relative) !== $hash) {
        $failures[] = 'Protected authoring boundary changed: ' . $relative;
    }
}

$contracts = array(
    'src/publishing/ControlledPublishingAuthoritativePaginationService.php' => array(
        'authoritative-browser-pagination-v1',
        'There is deliberately no heuristic fallback',
        'validation',
        'page_map_hash',
        'header_footer_hash',
    ),
    'scripts/authoritative_manual_paginator.cjs' => array(
        'ReaderPaginationCore.js',
        'page.setContent',
        'validation failed',
        'authoritative_layout',
    ),
    'src/publishing/ControlledPublishingManualPageBreakService.php' => array(
        'before_block_anchor',
        'force_break_before',
        'assertEditableVersion',
        'invalidatePageMap',
    ),
    'public/assets/controlled_book_editor.js' => array(
        'allPages.filter(function (page)',
        'selectedSectionUsesAutomaticPages',
        'paginationPageNavigation',
        "apiPost('split_block_page_break'",
        'insertPageBreakAtCursor',
    ),
    'public/admin/api/controlled_book_editor_api.php' => array(
        "case 'split_block_page_break':",
        'cp_editor_handle_split_block_page_break',
        'ControlledPublishingManualPageBreakService',
    ),
    'src/publishing/ControlledPublishingPaginationService.php' => array(
        'exact browser measurement',
        'real .cpb-toc-row',
    ),
    'src/publishing/ControlledPublishingFoundationService.php' => array(
        'assertAuthoritativePageMapReadyForRelease',
    ),
    'ipca-manual-reader-ios/IPCAManualReader/Models/ManualReaderModels.swift' => array(
        'static let controlledFrozenPages = true',
        'pageMapHash',
        'versionID',
    ),
    'ipca-manual-reader-ios/IPCAManualReader/ViewModels/ReaderViewModels.swift' => array(
        'READER_LAYOUT_USING_FROZEN_PAGES',
        'if ReaderDisplayMode.controlledFrozenPages',
        'let rawHTML = offlinePackage?.page(number: pageNumber)?.pageHtml',
        'layout: nil',
    ),
    'ipca-manual-reader-ios/IPCAManualReader/Services/PageCache.swift' => array(
        'Frozen authoritative page-map hash verification failed.',
        'paginateSourceData: nil',
    ),
);
foreach ($contracts as $relative => $markers) {
    $contents = @file_get_contents($root . '/' . $relative);
    if (!is_string($contents)) {
        $failures[] = 'Missing architecture file: ' . $relative;
        continue;
    }
    foreach ($markers as $marker) {
        if (!str_contains($contents, $marker)) {
            $failures[] = "Missing marker '{$marker}' in {$relative}";
        }
    }
}

$cache = (string)@file_get_contents(
    $root . '/ipca-manual-reader-ios/IPCAManualReader/Services/PageCache.swift'
);
if (str_contains($cache, 'client.fetchPaginateSource(')) {
    $failures[] = 'Canonical offline download still fetches client pagination source.';
}

if ($failures !== array()) {
    fwrite(STDERR, "Authoritative pagination architecture contract: FAIL\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "Authoritative pagination architecture contract: PASS\n";
echo "Protected authoring files: " . count($protected) . "\n";
