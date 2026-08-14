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
        => '5ebb7237faa86d2958783290f8a1e67ba4c83b15284bb5e8038f4a90dd37a524',
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
        'manual-segments-v1',
        'MANUAL_BREAK_REQUIRED',
        'There is deliberately no heuristic fallback',
        'validation',
        'page_map_hash',
        'header_footer_hash',
        'ControlledPublishingPaginationValidationException',
        'CW_PAGINATION_PLAYWRIGHT_BROWSERS_PATH',
        'workerEnvironment',
        'acquireGenerationLock',
        'LOCK_EX | LOCK_NB',
        'Authoritative pagination is already running for this manual version',
    ),
    'scripts/authoritative_manual_paginator.cjs' => array(
        'ReaderPaginationCore.js',
        'page.setContent',
        'validation failed',
        'authoritative_layout',
        'CW_PAGINATION_PLAYWRIGHT_BROWSERS_PATH',
    ),
    'src/publishing/ControlledPublishingManualPageBreakService.php' => array(
        'before_block_anchor',
        'force_break_before',
        'assertEditableVersion',
        'invalidatePageMap',
    ),
    'public/admin/api/controlled_book_page_map_api.php' => array(
        "'returned_page_count' => count(\$pages)",
        "\$sectionId > 0 ? \$sectionId : null",
        "\$reader->authoritativePageMapFreshness(\$version, \$paginateSource)",
        'ControlledPublishingPaginationValidationException',
        '$e->payload()',
    ),
    'src/publishing/ControlledPublishingReaderPageMapStore.php' => array(
        '?int $sectionId = null',
        "' AND section_id = ?'",
    ),
    'src/publishing/ControlledPublishingManualPageBreakService.php' => array(
        'listBlockCandidates(int $bookVersionId, ?int $sectionId = null)',
        "' AND s.id = ?'",
    ),
    'ipca-manual-reader-ios/IPCAManualReader/Services/ReaderPaginationCore.js' => array(
        'MANUAL_BREAK_REQUIRED',
        'manual-segments-v1',
        'reader-generated-page',
        'reader-page-header-region',
        'reader-page-body',
        'reader-page-footer-region',
        'justify-content:flex-end',
        'stripPublicationChrome',
        'extractPageShell',
        'contentRootFromHTML',
        'HEADER_BODY_INTERSECT',
        'BODY_STARTS_ABOVE_CONTENT_FRAME',
        'overflow:hidden',
        'isLepFragment',
        'lep-row-',
        'startContinuation(sourceFragment, "lep")',
        'humanTitleForFragment',
        'pagination_authority',
        'paginationAuthority',
        'if (isPart0) return "generated"',
        'isGeneratedFragment',
    ),
    'public/admin/api/controlled_book_editor_api.php' => array(
        "case 'split_block_page_break':",
        'cp_editor_handle_split_block_page_break',
        'ControlledPublishingManualPageBreakService',
    ),
    'src/publishing/ControlledPublishingPaginationService.php' => array(
        'exact browser measurement',
        'real .cpb-toc-row',
        'unitsFromLepBody',
        'real .cpb-lep-part-row',
        'paginationAuthority',
        'Part 0 editability does not change its automatic pagination authority',
        'PART0_SECTION_KEYS',
        "'pagination_authority' => 'generated'",
        '$this->reader->loadReaderPaginateSource($resolvedVersion)',
        'ControlledPublishingPublicationFilter',
    ),
    'src/publishing/ControlledPublishingPublicationFilter.php' => array(
        'EDITOR AUTHORING UI',
        'cpb-dropzone',
        'data-editor-only',
        'cpb-image--empty',
        'filterHtml',
        'parseSheet',
        'unwrapPageShell',
        'content fragments',
    ),
    'src/publishing/ControlledPublishingFoundationService.php' => array(
        'assertAuthoritativePageMapReadyForRelease',
    ),
    'public/admin/compliance/controlled_book_page_preview.php' => array(
        'action=stored_preview',
        'Exact Page Preview',
        'MANUAL_BREAK_REQUIRED',
        'Loading stored pages',
        'Return to Editor',
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

$preview = (string)@file_get_contents(
    $root . '/public/admin/compliance/controlled_book_page_preview.php'
);
if (preg_match('/redirect\s*\(\s*[\'"]\/admin\/compliance\/controlled_book_editor\.php/', $preview) === 1) {
    $failures[] = 'Exact Page Preview still redirects to the editor.';
}
if (str_contains($preview, 'contenteditable')) {
    $failures[] = 'Exact Page Preview contains editing controls.';
}
if (!str_contains($preview, 'loadStored()') || !str_contains($preview, 'stored_preview')) {
    $failures[] = 'Exact Page Preview does not load stored_preview on view.';
}

$paginationService = (string)@file_get_contents(
    $root . '/src/publishing/ControlledPublishingPaginationService.php'
);
if (str_contains($paginationService, 'function paginateSourceDeterministic')) {
    $failures[] = 'Obsolete paginateSourceDeterministic() remains in the pagination service.';
}

$versionPage = (string)@file_get_contents(
    $root . '/public/admin/compliance/controlled_book_version.php'
);
if (!str_contains($versionPage, 'controlled_book_page_preview.php')) {
    $failures[] = 'Version page is missing the Exact Page Preview entry.';
}

$draftGenerate = (string)@file_get_contents(
    $root . '/src/publishing/ControlledPublishingReaderService.php'
);
$generatePos = strpos($draftGenerate, 'function generateFrozenPageMapDraft');
$replacePos = strpos($draftGenerate, 'replaceDraftPages', $generatePos !== false ? $generatePos : 0);
$mapPos = strpos($draftGenerate, 'generateFrozenPageMap(', $generatePos !== false ? $generatePos : 0);
if ($generatePos === false || $mapPos === false || $replacePos === false || $mapPos > $replacePos) {
    $failures[] = 'Draft generation must run generateFrozenPageMap before replaceDraftPages.';
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
