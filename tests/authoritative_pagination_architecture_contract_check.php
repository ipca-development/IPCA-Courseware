<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = array();

$protected = array(
    'src/publishing/ControlledPublishingBlockService.php'
        => 'a0c103104d4182dbe797d85ea04d3d757972b34cf4bc5e989925a034d64e7c39',
);
foreach ($protected as $relative => $hash) {
    if (!is_file($root . '/' . $relative) || hash_file('sha256', $root . '/' . $relative) !== $hash) {
        $failures[] = 'Protected authoring boundary changed: ' . $relative;
    }
}

$contracts = array(
    'docs/architecture/authoritative-manual-pagination.md' => array(
        'single-surface authoring',
        'stored `page_html`',
        '`data-source-fragment-id`',
        'range-aware save',
        'presentation copies remain read-only',
        'automatically queue `live_ensure`',
    ),
    'src/publishing/ControlledPublishingAuthoritativePaginationService.php' => array(
        'live-authoritative-flow-v2',
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
        'INCREMENTAL_PREFIX_MISMATCH',
        'validateMergedCoverageOrder',
        'assertSourceSectionCoverage',
        'MISSING_SECTION_PAGE',
    ),
    'scripts/authoritative_manual_paginator.cjs' => array(
        'ReaderPaginationCore.js',
        'page.setContent',
        'validation failed',
        'authoritative_layout',
        'CW_PAGINATION_PLAYWRIGHT_BROWSERS_PATH',
        'const resolvedHeaderHeight = measuredBands.portrait.header || headerHeight',
        'const resolvedFooterHeight = measuredBands.portrait.footer || footerHeight',
        'INCREMENTAL_PREFIX_MISMATCH',
    ),
    'src/publishing/ControlledPublishingReaderLayoutProfile.php' => array(
        "'header_band_px' => 64",
        "'footer_band_px' => 34",
        "'body_capacity_px' => 802",
    ),
    'src/publishing/ControlledPublishingBookStyleManifestService.php' => array(
        '.reader-page-header-region>.cpb-page-header,.reader-page-footer-region>.cpb-page-footer{position:static;inset:auto;width:100%;height:auto;',
    ),
    'src/publishing/ControlledPublishingManualPageBreakService.php' => array(
        'before_block_anchor',
        'force_break_before',
        'assertEditableVersion',
        'invalidatePageMap',
    ),
    'public/assets/controlled_book_editor.js' => array(
        'authoritative_editor_page_starts_enabled',
        'check_freshness=1',
        'authoritativeEditorPageStartsEnabled',
        'authoritativeEditorPageStartsFromResult',
        'data-authoritative-page-break',
        'state.authoritativeEditorPageStarts = []',
        'observeCanonicalPageState',
        "root.addEventListener('cpb:live-pagination-state', observeCanonicalPageState)",
        "viewMode: 'paginated'",
        'enforceAuthoritativeEditorSurface',
        'var pages = sectionPages;',
        "content.innerHTML = page.page_html || '';",
        'scopePublicationCssForEditor',
        'installEditorPublicationCss',
        'wireTableResize(blockEl, authoritativeSurface)',
        'wirePaginatedFields();',
        'hasUnsavedCanonicalEdits',
    ),
    'public/admin/compliance/controlled_book_editor.php' => array(
        'id="cpbEditorRoot"',
    ),
    'src/publishing/ControlledPublishingPaginationService.php' => array(
        'htmlHasPaginableContent',
        '<(?:img|svg|canvas|video)',
        'contains governed blocks but no renderable units',
    ),
    'public/admin/api/controlled_book_page_map_api.php' => array(
        "'returned_page_count' => count(\$responsePages)",
        "\$sectionId > 0 ? \$sectionId : null",
        "\$reader->authoritativePageMapFreshness(\$version, \$paginateSource)",
        "\$reader->readerPublicationPackage(\$version, \$paginateSource)",
        "'artifact_compatible' => \$artifactCompatible",
        'ControlledPublishingPaginationValidationException',
        '$e->payload()',
        "case 'live_ensure':",
        "case 'live_status':",
        "case 'live_retry':",
        'cp_pm_mutation_hint',
        "case 'generate':",
    ),
    'src/publishing/ControlledPublishingReaderPageMapStore.php' => array(
        '?int $sectionId = null',
        'pageContainsSection',
        'coverageSectionIds',
        'replaceStagingPages',
        'promoteStagingPagesCas',
        'requested_fingerprint_hash',
        'STALE_COMPLETION',
    ),
    'src/publishing/ControlledPublishingLivePageMapService.php' => array(
        'One DB row coalesces each version/profile',
        'STATUS_RETRY_AVAILABLE',
        'queueRequest',
        'claimFingerprintMatches',
        'promoteStagingPagesCas',
        '$freshSource',
        'defaultSpawner',
        'pending_generation_seq',
        'acquireServerSingleFlight',
        'GET_LOCK',
        'RELEASE_LOCK',
        "' --drain'",
        'CW_PAGINATION_PHP',
        'PHP_BINDIR',
        'incrementalGenerationOptions',
        'prefix_source_fingerprints',
    ),
    'scripts/controlled_publishing_page_map_worker.php' => array(
        "require_once __DIR__ . '/../src/helpers.php'",
        'ControlledPublishingLivePageMapService',
        'workOne',
        "'drain'",
        'processed=',
    ),
    'scripts/sql/2026_08_14_publishing_live_page_map_generation.sql' => array(
        'ipca_publishing_page_map_generation_state',
        'ipca_publishing_reader_page_map_staging',
        'generation_seq',
        'lease_token',
        'pending_generation_seq',
        'requested_mutation_json',
        'pending_mutation_json',
    ),
    'scripts/apply_publishing_live_page_map_generation.php' => array(
        'requiredColumns',
        'requested_fingerprint_hash',
        "'requested_mutation_json'",
        "'lease_token'",
    ),
    'src/publishing/ControlledPublishingManualPageBreakService.php' => array(
        'listBlockCandidates(int $bookVersionId, ?int $sectionId = null)',
        "' AND s.id = ?'",
    ),
    'ipca-manual-reader-ios/IPCAManualReader/Services/ReaderPaginationCore.js' => array(
        'MANUAL_BREAK_REQUIRED',
        'live-authoritative-flow-v2',
        'automatic_author_flow',
        'heading_keep_with_following',
        'main_title_section_start',
        'HEADING_PARAGRAPH_STYLE_LEVELS',
        'section_id: Number(value.fragment.section',
        'verticalNode: overflow.verticalNode',
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
        'source_fingerprint',
        'validateIncrementalPrefix',
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
        "'paragraph_style' => \$paragraphStyle",
        "'heading_level' => \$headingLevel",
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

$editorShell = (string)@file_get_contents(
    $root . '/public/admin/compliance/controlled_book_editor.php'
);
foreach (array(
    'id="cpbViewPaginated"',
    'id="cpbViewEdit"',
    'id="cpbPaginationRegenerate"',
    'id="cpbPaginationApprove"',
    'Page (iOS)',
    'data-initial-view=',
) as $forbiddenControl) {
    if (str_contains($editorShell, $forbiddenControl)) {
        $failures[] = "Editor still exposes preview/source workflow control: {$forbiddenControl}";
    }
}

$editorJs = (string)@file_get_contents($root . '/public/assets/controlled_book_editor.js');
foreach (array(
    'canvasEl.appendChild(paginationBreakControl())',
    'canvasEl.appendChild(paginationPageNavigation(',
    'function paginationBreakControl(',
    'function paginationPageNavigation(',
    'appendCanonicalPageEditPortals',
    'cpbPaginatedBlockEditor',
    'function setViewMode(',
) as $forbiddenChrome) {
    if (str_contains($editorJs, $forbiddenChrome)) {
        $failures[] = "Authoritative editor still renders non-document page chrome: {$forbiddenChrome}";
    }
}
if (str_contains($editorJs, "publicationCssEl.textContent = result.book_style_css")) {
    $failures[] = 'Authoritative publication CSS is still installed globally in the admin document.';
}

$architectureDoc = (string)@file_get_contents(
    $root . '/docs/architecture/authoritative-manual-pagination.md'
);
foreach (array(
    'projected iframe remains sandboxed, non-editable',
    'edit the continuous source document',
    'page furniture is explicitly labelled as an approximate editing layout',
) as $obsoletePolicy) {
    if (str_contains($architectureDoc, $obsoletePolicy)) {
        $failures[] = "Architecture policy still describes the removed split editor: {$obsoletePolicy}";
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
echo "Protected non-editor authoring files: " . count($protected) . "\n";
