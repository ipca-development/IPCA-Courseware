<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = array();

$protected = array(
    'src/publishing/ControlledPublishingBlockService.php'
        => '3317718f7bd5e77f537b07c9dc95d51198321b5a0a05ff89629b5bc50baeb22f',
    'src/document/StructuredDocumentPayload.php'
        => '9b5412d9966d0e7ce162ae652cbd8515b4f8864689852f2807e5f404f356a8ed',
    'src/publishing/ControlledPublishingBookRenderer.php'
        => '1c2b71ec9d6bb1724026c5c484e992ddb5b18776106cd27248d6bdc456c2b87d',
);

foreach ($protected as $relative => $expectedHash) {
    $path = $root . '/' . $relative;
    $actualHash = is_file($path) ? hash_file('sha256', $path) : false;
    if ($actualHash !== $expectedHash) {
        $failures[] = "Protected Admin Editor boundary changed: {$relative}";
    }
}

/**
 * @param list<string> $needles
 */
function require_markers(string $path, array $needles, array &$failures): void
{
    $source = is_file($path) ? (string)file_get_contents($path) : '';
    foreach ($needles as $needle) {
        if (!str_contains($source, $needle)) {
            $failures[] = basename($path) . " missing required marker: {$needle}";
        }
    }
}

require_markers(
    $root . '/ipca-manual-reader-ios/IPCAManualReader/Models/ReaderPaginationModels.swift',
    array(
        'struct OfficialDocumentLocation',
        'struct SemanticReaderLocation',
        'struct PersonalReaderPage',
        'struct PageLayoutConfiguration',
        'headerFrame',
        'contentFrame',
        'footerFrame',
        'reader-normalizer-v1',
        'semantic-paginator-v2',
        'pagination-validator-v2',
        'safeAreaInsets',
        'gutterWidth',
        'pageScale',
    ),
    $failures
);

require_markers(
    $root . '/ipca-manual-reader-ios/IPCAManualReader/Models/ManualReaderModels.swift',
    array(
        'struct PublicationPackageResponse',
        'struct PublicationPackage',
        'struct BookStyleManifest',
        'struct PublicationLayout',
        'book-style-css-v2',
        'case original',
        'value == "light" ? .original',
        'enum ReaderHighlightColor',
        'struct TextHighlightAnchor',
    ),
    $failures
);

require_markers(
    $root . '/ipca-manual-reader-ios/IPCAManualReader/Services/PageCache.swift',
    array(
        'publicationManifestJSON',
        'bookStyleCSS',
        'downloadPublicationAssets',
        'Publication asset hash verification failed',
        'rewrittenPaginateSourceData',
        'catch ManualReaderAPIError.unauthorized',
        'ManualReaderSessionStore.shared.clearSession()',
        'case updateAvailable(String)',
        'downloadTasks',
        'var isFullyDownloaded: Bool',
        'return try await completeDownload(',
    ),
    $failures
);

require_markers(
    $root . '/ipca-manual-reader-ios/IPCAManualReader/Views/BookPageCurlView.swift',
    array(
        'return [safeIndex, right]',
        'if !pageNumber.isMultiple(of: 2)',
        'pageNumber.isMultiple(of: 2) == false',
        'onToggleBookmark',
        'Image(systemName: "bookmark")',
        'Image(systemName: "bookmark.fill")',
        'zoomedPositions',
    ),
    $failures
);

require_markers(
    $root . '/ipca-manual-reader-ios/IPCAManualReader/Views/ManualPageWebView.swift',
    array(
        'onNavigateToAnchor(fragment)',
        'onNavigateToSection(sectionID)',
        'onExternalLink(url)',
        'maximumZoomScale = 4',
        'WKScriptMessageHandler',
        'readerSelection',
        'readerPageReady',
        'document.fonts?.ready',
        'verifyStableGeometry',
    ),
    $failures
);

require_markers(
    $root . '/ipca-manual-reader-ios/IPCAManualReader/Views/ReaderView.swift',
    array(
        '"Open External Website?"',
        'viewModel.openingProgress',
        'ReaderSelectionActionMenu',
        'PersonalNoteEditorSheet',
        'bookmarksPopover',
        '.preferredColorScheme(.light)',
        '.opacity(isOpening ? 0 : 1)',
        '.allowsHitTesting(!isOpening)',
    ),
    $failures
);

require_markers(
    $root . '/ipca-manual-reader-ios/IPCAManualReader/Services/ManualReaderSessionStore.swift',
    array(
        'private var apiClient: ManualReaderAPIClient?',
        'if let apiClient, apiClient.baseURL == baseURL',
        'catch ManualReaderAPIError.unauthorized',
        'settings.theme = .original',
        'settings.zoom = .fitWidth',
        'func addHighlight(',
    ),
    $failures
);

require_markers(
    $root . '/ipca-manual-reader-ios/IPCAManualReader/Services/ManualReaderAPIClient.swift',
    array(
        'URL(string: path, relativeTo: baseURL)?.absoluteURL',
        'http.url?.path.hasSuffix("/login.php")',
        'throw ManualReaderAPIError.unauthorized',
        'enum ReaderHTMLAnnotationService',
        '.mr-search-hit',
        'user-scalable=yes',
    ),
    $failures
);
$apiClientSource = (string)file_get_contents(
    $root . '/ipca-manual-reader-ios/IPCAManualReader/Services/ManualReaderAPIClient.swift'
);
if (str_contains($apiClientSource, 'components?.path = path')) {
    $failures[] = 'Authenticated asset URLs must not encode query strings into URL paths.';
}

$readerView = (string)file_get_contents(
    $root . '/ipca-manual-reader-ios/IPCAManualReader/Views/ReaderView.swift'
);
foreach (array('ReaderSettingsView', '"Reader theme"', '"Reader settings"') as $removedControl) {
    if (str_contains($readerView, $removedControl)) {
        $failures[] = "ReaderView.swift still exposes removed setting control: {$removedControl}";
    }
}
require_markers(
    $root . '/ipca-manual-reader-ios/IPCAManualReader/Views/LibraryView.swift',
    array(
        'private struct BookmarksByManualSection',
        'onSelectBookmark(book, bookmark)',
        'AuthenticatedCoverImage',
        'Image(systemName: "icloud.and.arrow.down")',
        'Image(systemName: "icloud")',
        'coverImageKind == "authoritative_page_thumbnail_v1"',
        'Button("Delete Local Download"',
    ),
    $failures
);
$libraryViewSource = (string)file_get_contents(
    $root . '/ipca-manual-reader-ios/IPCAManualReader/Views/LibraryView.swift'
);
$coverCardStart = strpos($libraryViewSource, 'private struct ManualCoverCard');
$coverCardEnd = strpos($libraryViewSource, 'private struct AuthenticatedCoverImage');
$coverCardSource = $coverCardStart !== false && $coverCardEnd !== false
    ? substr($libraryViewSource, $coverCardStart, $coverCardEnd - $coverCardStart)
    : '';
foreach (array('book.coverImageUrl', 'book.coverUrl', 'Text("DRAFT")', 'coverPlaceholder') as $legacyCoverMarker) {
    if (str_contains($coverCardSource, $legacyCoverMarker)) {
        $failures[] = "Manual cover card still exposes non-authoritative fallback: {$legacyCoverMarker}";
    }
}
require_markers(
    $root . '/ipca-manual-reader-ios/IPCAManualReader/Services/PageCache.swift',
    array(
        'let coverImageKind: String?',
        'from: coverPath',
        '"authoritative_page_thumbnail_v1"',
    ),
    $failures
);

$readerApi = (string)file_get_contents($root . '/public/student/api/manual_reader_api.php');
if (str_contains($readerApi, 'cw_require_login();')) {
    $failures[] = 'Manual Reader API must return JSON 401 instead of redirecting to login.php.';
}
foreach (array(
    "mr_json(401, array('ok' => false, 'error' => 'Login required'))",
    "loadReaderPageMap(\$ctx['version'], false)",
    "loadReaderTocWithPages(\$ctx['version'], false)",
) as $readerApiMarker) {
    if (!str_contains($readerApi, $readerApiMarker)) {
        $failures[] = "Manual Reader API missing stored-map/auth marker: {$readerApiMarker}";
    }
}

$iosSwiftFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
    $root . '/ipca-manual-reader-ios/IPCAManualReader',
    FilesystemIterator::SKIP_DOTS
));
foreach ($iosSwiftFiles as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'swift') {
        continue;
    }
    $source = (string)file_get_contents($file->getPathname());
    if (str_contains($source, 'assets/controlled_book_editor.css')) {
        $failures[] = 'Native reader must not load the Admin Editor stylesheet: ' . $file->getFilename();
    }
}

require_markers(
    $root . '/public/assets/manual_reader_content.css',
    array(
        'GENERATED FILE — read-only controlled manual content styles',
        '.cpb-page-header',
        '.cpb-page-footer',
        '.cpb-heading',
        '.cpb-paragraph',
        '.cpb-list',
        '.cpb-table',
        '.cpb-callout',
        '.cpb-image',
        '.cpb-cover',
        '.cpb-toc',
        '.reader-canonical-page',
    ),
    $failures
);

$paginationCore = (string)file_get_contents(
    $root . '/ipca-manual-reader-ios/IPCAManualReader/Services/ReaderPaginationCore.js'
);
foreach (array(
    'applyTypography',
    'applyControlledBandTypography',
    '[8, 9, 10, 11, 12, 14, 16, 18, 24]',
    'layout.pageWidth / 816',
    'layout.contentFrame.height * 0.82',
) as $forbiddenMarker) {
    if (str_contains($paginationCore, $forbiddenMarker)) {
        $failures[] = "Native paginator retained approximated publication styling: {$forbiddenMarker}";
    }
}

require_markers(
    $root . '/ipca-manual-reader-ios/IPCAManualReader/Services/ReaderPaginationCore.js',
    array(
        'function normalizeDocument()',
        'function validateCoverage(normalized, pages)',
        'SOURCE_FRAGMENT_MISSING',
        'SOURCE_FRAGMENT_DUPLICATED',
        'SOURCE_FRAGMENT_GAP',
        'SOURCE_ORDER_CHANGED',
        'UNKNOWN_SOURCE_FRAGMENT',
        'presentation_copy',
        'ORPHAN_HEADING',
        'CONTENT_WIDTH_OVERFLOW',
        'CONTENT_HEIGHT_OVERFLOW',
        'SEMANTIC_BLOCK_OUTSIDE_CONTENT_FRAME',
        'HEADER_CLIPPED',
        'FOOTER_CLIPPED',
        'MISSING_HEADER',
        'MISSING_FOOTER',
        'LOW_PAGE_UTILIZATION',
        'EXCESSIVE_WHITESPACE',
        'document.fonts.ready',
        'tableHeaderHTML',
        'function canonicalRect(rect)',
        'extractPageShell',
        'reader-canonical-page',
        'transform:scale(var(--reader-page-scale))',
        'body.scrollHeight * scale',
        'bodyRect.height',
        'region.style.setProperty("--reader-font-scale", "1")',
    ),
    $failures
);

require_markers(
    $root . '/ipca-manual-reader-ios/IPCAManualReader/ViewModels/ReaderViewModels.swift',
    array(
        'currentSemanticLocation',
        'resolvedOfficialLocation()',
        'PersonalPaginationCache.shared',
        'coverage.sourceFragmentID',
        'officialPageNumber',
        'openingMessage = "Synchronizing notes…"',
        'htmlGeneration += 1',
    ),
    $failures
);

if ($failures !== array()) {
    fwrite(STDERR, "Manual reader pagination architecture contract: FAIL\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "Manual reader pagination architecture contract: PASS\n";
echo "Protected non-editor reader-boundary files: " . count($protected) . "\n";
echo "Location concepts: OfficialDocumentLocation, SemanticReaderLocation, PersonalReaderPage\n";
echo "Exactly-once source coverage validator: present\n";
