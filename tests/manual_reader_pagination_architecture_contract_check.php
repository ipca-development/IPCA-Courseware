<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = array();

$protected = array(
    'public/admin/compliance/controlled_book_editor.php'
        => '924bd98f8cb56e5154adc9b3a525bb69c8b3a9b6960eb16c447d7f143dee5c0f',
    'public/assets/controlled_book_editor.js'
        => 'd25c09e8c5fc7abf7ef9fdf0147159955698391f8219caa30423f69a4d5cf4c3',
    'public/assets/controlled_book_editor.css'
        => '23e9d551939f45d254dafb2f29a0347ae7e0b0d643e00eed5da6246ecbdbd753',
    'public/admin/api/controlled_book_editor_api.php'
        => 'ba11146a03046ea31c4423b6bc7a1d4332faf2d853e426156d1011843dd3eb1f',
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
echo "Protected editor files: " . count($protected) . "\n";
echo "Location concepts: OfficialDocumentLocation, SemanticReaderLocation, PersonalReaderPage\n";
echo "Exactly-once source coverage validator: present\n";
