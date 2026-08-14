<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = array();

$protected = array(
    'src/publishing/ControlledPublishingBlockService.php'
        => 'd63175aa9e0292b9137f27a38eeb913337e6af8ab5e6086cdf4207385474f265',
    'src/document/StructuredDocumentPayload.php'
        => '71bcbba2711d9158d952c8042af6439dc8df1697318da877f6e2225eca80a805',
    'src/publishing/ControlledPublishingBookRenderer.php'
        => '3f4af44e623dd5dbacd91f36578cf14764c487cb0cfeef9dc99aff101d48e0e9',
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
    ),
    $failures
);

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
