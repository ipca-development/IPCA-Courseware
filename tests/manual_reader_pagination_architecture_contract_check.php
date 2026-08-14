<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = array();

$protected = array(
    'public/admin/compliance/controlled_book_editor.php'
        => '459a755e2aef5802f7bbc9c9679619252596a7705a9629bb09a5f8cf998219b7',
    'public/assets/controlled_book_editor.js'
        => 'a11c51a9a5030c2232241dbe4c1ec844fdb8e56e9fa1e067b52c07bf218aac52',
    'public/assets/controlled_book_editor.css'
        => '06fa6366568e0a0952b3a8b88fa179da1cf611861bcb13a94f57bc7c983e16fe',
    'public/admin/api/controlled_book_editor_api.php'
        => '22e5f6c90f2a63401b15d8f70942fa3ca2954c7767aaaf4d61477bdec0bac1e9',
    'src/publishing/ControlledPublishingBlockService.php'
        => 'd63175aa9e0292b9137f27a38eeb913337e6af8ab5e6086cdf4207385474f265',
    'src/document/StructuredDocumentPayload.php'
        => '71bcbba2711d9158d952c8042af6439dc8df1697318da877f6e2225eca80a805',
    'src/publishing/ControlledPublishingBookRenderer.php'
        => '20117d5ebfe2f5c6fcd05e589a0d8a1b65ef5dd26f673e2720fb6ffa594d6d5d',
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
        'semantic-paginator-v1',
        'pagination-validator-v1',
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
        'MISSING_HEADER',
        'MISSING_FOOTER',
        'LOW_PAGE_UTILIZATION',
        'EXCESSIVE_WHITESPACE',
        'document.fonts.ready',
        'tableHeaderHTML',
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
