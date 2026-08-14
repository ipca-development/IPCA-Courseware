<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = array();

$protected = array(
    'public/admin/compliance/controlled_book_editor.php'
        => '456840dd3aed463fb98be206a9bf27de47d942341968bba57d3ea46a80cfb24f',
    'public/assets/controlled_book_editor.js'
        => '8b82c9a76a007b266595d54a78c4f37e17b18f2dd29822b393fb068b87b256b5',
    'public/assets/controlled_book_editor.css'
        => '323af0d9b94337e0ce818a3601daef4bcec10add118b76e7e91ff3c70c03f8de',
    'public/admin/api/controlled_book_editor_api.php'
        => '22e5f6c90f2a63401b15d8f70942fa3ca2954c7767aaaf4d61477bdec0bac1e9',
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
    'public/admin/compliance/controlled_book_page_preview.php' => array(
        'Page Preview',
        'Needs regeneration',
        'Manual Page Break',
        'stored_preview',
        'Approve pagination',
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
