<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/publishing/ControlledPublishingBookRenderer.php';
require_once __DIR__ . '/../src/publishing/ControlledPublishingPaginationService.php';

function part0_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$renderer = new ControlledPublishingBookRenderer();

$amendment = $renderer->renderAmendmentListContent(array(
    'rows' => array(array(
        'revision_nr' => 'Rev 2',
        'reason' => 'Layout test',
        'revision_date' => '01/08/2026',
        'effective_date' => '02/08/2026',
        'date_incorp' => '03/08/2026',
        'incorp_by' => 'EPC',
    )),
    'empty_rows' => 0,
    'column_widths' => array(10, 30, 15, 15, 15, 15),
    'table_style' => array(
        'border_width' => 'thick',
        'border_color' => '#123456',
        'header_row' => array('bg' => '#112233', 'color' => '#ffffff'),
        'body_row' => array('bg' => '#f0f0f0', 'color' => '#101010'),
    ),
), true);
part0_assert(substr_count($amendment, 'data-part0-column-resize="1"') === 6, 'Amendment columns are not resizable.');
part0_assert(str_contains($amendment, '<col style="width:30%">'), 'Saved amendment widths are not rendered.');
part0_assert(str_contains($amendment, 'cpb-table-border-thick'), 'Page-specific table border is not rendered.');
part0_assert(str_contains($amendment, '--cpb-table-border-color:#123456'), 'Page-specific border color is not rendered.');
part0_assert(str_contains($amendment, 'data-cell-bg="#112233"'), 'Page-specific header style is not rendered.');

$distribution = $renderer->renderDistributionListContent(array(
    'rows' => array(array('copy_nr' => '1', 'issue_to' => 'Authority')),
    'empty_rows' => 0,
    'column_widths' => array(24, 76),
), true);
part0_assert(substr_count($distribution, 'data-part0-column-resize="1"') === 2, 'Distribution columns are not resizable.');
part0_assert(str_contains($distribution, '<col style="width:76%">'), 'Saved distribution widths are not rendered.');

$lepMethod = (new ReflectionClass(ControlledPublishingBookRenderer::class))->getMethod('renderLepPartsTable');
$lepTable = $lepMethod->invoke($renderer, array(
    'effective_parts' => array(array(
        'part' => '0',
        'pages' => '1–12',
        'date' => '15/08/2026',
        'revision' => '2',
    )),
    'empty_rows' => 0,
    'column_widths' => array(20, 30, 25, 25),
), true);
part0_assert(substr_count($lepTable, 'data-part0-column-resize="1"') === 4, 'LEP columns are not resizable.');
part0_assert(substr_count($lepTable, 'data-lep-part-col=') === 4, 'LEP cells are not editable structured fields.');
part0_assert(str_contains($lepTable, '<col style="width:30%">'), 'Saved LEP widths are not rendered.');

$publishedAbbreviations = $renderer->renderAbbreviationsIndexContent(array(
    'entries' => array(
        array('abbreviation' => 'ABC', 'definition' => '', 'definition_status' => 'needs_review'),
        array('abbreviation' => 'DEF', 'definition' => 'Definition', 'definition_status' => 'ai_suggested'),
    ),
    'empty_rows' => 0,
), false);
part0_assert(!str_contains($publishedAbbreviations, '>Review<'), 'Review label leaked into publication HTML.');
part0_assert(!str_contains($publishedAbbreviations, '>AI<'), 'AI label leaked into publication HTML.');
part0_assert(!str_contains($publishedAbbreviations, 'cpb-part0-abbr-row--review'), 'Review chrome leaked into publication HTML.');
part0_assert(!str_contains($publishedAbbreviations, 'cpb-part0-abbr-row--ai'), 'AI chrome leaked into publication HTML.');

$paginationReflection = new ReflectionClass(ControlledPublishingPaginationService::class);
$pagination = $paginationReflection->newInstanceWithoutConstructor();
$unitsMethod = $paginationReflection->getMethod('unitsFromRenderedBody');
$title = '0.6 Definitions and Terms';
$body = '<div class="cpb-part0">'
    . '<div class="cpb-part0-heading" data-paragraph-style="subtitle_1">' . $title . '</div>'
    . '<div class="cpb-part0-body"><div class="cpb-part0-def-row"><strong>Term</strong><span>Meaning</span></div></div>'
    . '</div>';
$units = $unitsMethod->invoke($pagination, $body, array(
    'id' => 6,
    'section_key' => 'definitions',
    'stable_anchor' => 'PART0-DEFINITIONS',
    'is_part0' => 1,
), array('is_part0' => true));
$unitHtml = implode('', array_map(static fn(array $unit): string => (string)$unit['html'], $units));
part0_assert(substr_count($unitHtml, $title) === 1, 'Part 0 title is duplicated in authoritative pagination units.');

$revisionTitle = '0.2 Revision System';
$revisionUnits = $unitsMethod->invoke(
    $pagination,
    '<div class="cpb-part0-heading" data-paragraph-style="subtitle_1">' . $revisionTitle . '</div>'
        . '<article class="cpb-block cpb-block--paragraph"><p>' . $revisionTitle . '</p></article>'
        . '<article class="cpb-block cpb-block--paragraph"><p>Revision body.</p></article>',
    array(
        'id' => 2,
        'section_key' => 'revision_system',
        'stable_anchor' => 'PART0-REVISION',
        'is_part0' => 1,
    ),
    array('is_part0' => true)
);
$revisionHtml = implode('', array_map(static fn(array $unit): string => (string)$unit['html'], $revisionUnits));
part0_assert(substr_count($revisionHtml, $revisionTitle) === 1, 'Matching author title was not de-duplicated.');
part0_assert(str_contains($revisionHtml, 'Revision body.'), 'Part 0 body content was removed with its duplicate title.');

$editorJs = file_get_contents(__DIR__ . '/../public/assets/controlled_book_editor.js') ?: '';
part0_assert(str_contains($editorJs, 'extractTableColumnWidths'), 'Editor does not persist structured table widths.');
part0_assert(str_contains($editorJs, 'data-part0-column-resize'), 'Editor does not handle structured table resizing.');

$importer = file_get_contents(__DIR__ . '/../src/publishing/ControlledPublishingDocxImportService.php') ?: '';
part0_assert(str_contains($importer, "'paragraph_style' => 'subtitle_2'"), 'Revision change titles are not imported as Subtitle 2.');
part0_assert(str_contains($importer, "Revision\\s+\\d+\\s+Changes"), 'Revision change title recognition is missing.');

echo "PASS Part 0 editing/publication contract\n";
