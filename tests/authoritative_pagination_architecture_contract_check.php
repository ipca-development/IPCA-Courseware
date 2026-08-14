<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = array();

$protected = array(
    'public/admin/compliance/controlled_book_editor.php'
        => '924bd98f8cb56e5154adc9b3a525bb69c8b3a9b6960eb16c447d7f143dee5c0f',
    'public/assets/controlled_book_editor.js'
        => '0cf06b08b89e523ecf1ef25d78ffce343ad4afcaba9e3830c1c6e208e3181f00',
    'public/assets/controlled_book_editor.css'
        => '91c2906b16403ac8568ca75ab8f1df59a053d9a1e97cd9b7a7f2b3f8d07f9391',
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
        'applyUnifiedPrintLayout',
        'scheduleUnifiedPrintLayout',
        'cpb-flow-page-break--automatic',
        'range.getClientRects()',
        'hasManualSpacer',
        'target - current - gridGap',
        'cpb-print-change-marker',
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

$editorMarkup = (string)@file_get_contents(
    $root . '/public/admin/compliance/controlled_book_editor.php'
);
foreach (array(
    'cpbViewPaginated',
    'cpbPaginationRegenerate',
    'cpbPaginationApprove',
    'cpbPaginationStatus',
) as $removedControl) {
    if (str_contains($editorMarkup, $removedControl)) {
        $failures[] = 'Removed page-management control remains in the unified editor: ' . $removedControl;
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
