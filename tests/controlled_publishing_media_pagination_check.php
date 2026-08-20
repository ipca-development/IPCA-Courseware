<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/helpers.php';
require_once $root . '/src/publishing/ControlledPublishingPaginationService.php';

$pdo = new PDO('sqlite::memory:');
$reader = new ControlledPublishingReaderService($pdo);
$pagination = new ControlledPublishingPaginationService($reader);
$unitsMethod = new ReflectionMethod($pagination, 'unitsFromRenderedBody');

$section = array(
    'id' => 710,
    'section_key' => 'annex_10',
    'stable_anchor' => 'OMM-ANNEX-10',
    'is_generated' => 0,
    'is_system_managed' => 0,
    'allow_author_blocks' => 1,
);
$flags = array('pagination_authority' => 'author');
$imageHtml = '<article class="cpb-block cpb-block--image" data-block-id="711"'
    . ' data-stable-anchor="OMM-ANNEX-10-IMAGE">'
    . '<figure><img src="https://assets.example.invalid/annex-10.png" alt="Annex 10 form"></figure>'
    . '</article>';
$imageUnits = $unitsMethod->invoke($pagination, $imageHtml, $section, $flags);

if (
    count($imageUnits) !== 1
    || (string)($imageUnits[0]['block_type'] ?? '') !== 'image'
    || !str_contains((string)($imageUnits[0]['html'] ?? ''), '<img')
) {
    throw new RuntimeException('Image-only governed section was removed from pagination source.');
}

$shellUnits = $unitsMethod->invoke(
    $pagination,
    '<div class="cpb-image-only-shell"><img src="/media/form.png" alt="Form"></div>',
    $section,
    $flags
);
if (count($shellUnits) !== 1 || !str_contains((string)$shellUnits[0]['html'], '<img')) {
    throw new RuntimeException('Image-only shell section was removed from pagination source.');
}

$visualTableUnits = $unitsMethod->invoke(
    $pagination,
    '<table class="cpb-table"><tbody><tr><td></td><td></td></tr></tbody></table>',
    $section,
    $flags
);
if (count($visualTableUnits) !== 1 || !str_contains((string)$visualTableUnits[0]['html'], '<table')) {
    throw new RuntimeException('Visible text-free table was removed from pagination source.');
}

if ($unitsMethod->invoke($pagination, '<div> &nbsp; </div>', $section, $flags) !== array()) {
    throw new RuntimeException('Truly empty section unexpectedly emitted a pagination unit.');
}

$authoritative = new ControlledPublishingAuthoritativePaginationService($reader, $root);
$sectionIndexMethod = new ReflectionMethod($authoritative, 'sectionIndexFromPages');
$coverageMethod = new ReflectionMethod($authoritative, 'assertSourceSectionCoverage');
$sectionIndex = $sectionIndexMethod->invoke($authoritative, array(array(
    'page_number' => 15,
    'section_id' => 709,
    'metadata' => array(
        'coverage' => array(array(
            'section_id' => 710,
            'source_fragment_id' => 'OMM-ANNEX-10/OMM-ANNEX-10-IMAGE/root',
            'presentation_copy' => false,
        )),
    ),
)));
if ((int)($sectionIndex['710'] ?? 0) !== 15) {
    throw new RuntimeException('Section page index ignored a section carried by page coverage.');
}

$source = array('sections' => array(array(
    'section_id' => 710,
    'section_key' => 'annex_10',
    'title' => 'Dangerous Goods Occurrence Report',
    'content_mode' => 'units',
    'units' => $imageUnits,
)));
$coverageMethod->invoke($authoritative, $source, $sectionIndex);

$missingRejected = false;
try {
    $coverageMethod->invoke($authoritative, $source, array());
} catch (ControlledPublishingPaginationValidationException $error) {
    $missingRejected = $error->codeName() === 'MISSING_SECTION_PAGE';
}
if (!$missingRejected) {
    throw new RuntimeException('Authoritative map accepted a missing non-empty section.');
}

echo "Controlled publishing media pagination: PASS\n";
