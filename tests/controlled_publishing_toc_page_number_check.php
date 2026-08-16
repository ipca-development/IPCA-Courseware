<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/publishing/ControlledPublishingAuthoritativePaginationService.php';

$reflection = new ReflectionClass(ControlledPublishingAuthoritativePaginationService::class);
$service = $reflection->newInstanceWithoutConstructor();

$pages = array(
    array(
        'page_number' => 33,
        'section_id' => 42,
        'stable_anchor' => 'PART-1-CHAPTER-8',
        'metadata' => array('coverage' => array(array(
            'source_fragment_id' => 'PART-1-CHAPTER-8/PART-1-CHAPTER-8-BLOCK-084/root',
            'presentation_copy' => false,
        ))),
    ),
    array(
        'page_number' => 110,
        'section_id' => 43,
        'stable_anchor' => 'PART-1-CHAPTER-9',
        'metadata' => array('coverage' => array(array(
            'source_fragment_id' => 'PART-1-CHAPTER-9/PART-1-CHAPTER-9-BLOCK-001/root',
            'presentation_copy' => false,
        ))),
    ),
);

$anchorMethod = $reflection->getMethod('anchorIndexFromPages');
$anchorIndex = $anchorMethod->invoke($service, $pages);
$injectMethod = $reflection->getMethod('injectTocPageNumbers');
$html = '<div class="cpb-toc-row cpb-toc-row--title">'
    . '<span class="cpb-toc-label"><a class="cpb-toc-link"'
    . ' data-section-id="43" data-toc-target="PART-1-CHAPTER-9-BLOCK-001">'
    . '9. SAFETY AND SECURITY</a></span>'
    . '<span class="cpb-toc-leader"></span>'
    . '<span class="cpb-toc-page" data-toc-page="—">—</span>'
    . '</div>';
$updated = $injectMethod->invoke($service, $html, array('43' => 109), $anchorIndex);

if (!str_contains($updated, 'data-toc-page="110">110</span>')) {
    throw new RuntimeException('TOC target did not resolve to its exact covered page.');
}
if (substr_count($updated, '<span class="cpb-toc-page"') !== 1) {
    throw new RuntimeException('TOC page-number injection corrupted the opening span.');
}
if (str_contains($updated, '>110110</span>') || str_contains($updated, '>1010</span>')) {
    throw new RuntimeException('TOC page number was concatenated or duplicated.');
}

$malformed = '<div class="cpb-toc-row"><a data-section-id="43"'
    . ' data-toc-target="PART-1-CHAPTER-9-BLOCK-001">Chapter 9</a>'
    . '<span class="cpb-toc-leader"></span>1010</span></div>';
$repaired = $injectMethod->invoke($service, $malformed, array('43' => 109), $anchorIndex);
if (!str_contains($repaired, 'data-toc-page="110">110</span>')) {
    throw new RuntimeException('Previously malformed stored TOC markup was not repaired.');
}

echo "Controlled publishing TOC page numbers: PASS\n";
