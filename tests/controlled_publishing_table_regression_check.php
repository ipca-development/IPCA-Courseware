<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/helpers.php';
require_once dirname(__DIR__) . '/src/publishing/ControlledPublishingBookRenderer.php';

$failures = array();
$renderer = new ControlledPublishingBookRenderer();

/**
 * @param array<string,mixed> $payload
 */
function rendered_title_shape(
    ControlledPublishingBookRenderer $renderer,
    array $payload
): array {
    $html = $renderer->renderBlock(
        array(
            'id' => 1,
            'block_type' => 'table',
            'stable_anchor' => 'table-regression-test',
            'payload_json' => json_encode($payload, JSON_THROW_ON_ERROR),
        ),
        ControlledPublishingBookRenderer::MODE_EDIT
    );
    $document = new DOMDocument();
    @$document->loadHTML('<meta charset="utf-8">' . $html);
    $xpath = new DOMXPath($document);
    $rows = $xpath->query('//tr[@data-title-row="1"]');
    $row = $rows !== false ? $rows->item(0) : null;
    if (!$row instanceof DOMElement) {
        return array('cells' => 0, 'colspan' => 0);
    }
    $cells = $xpath->query('./th|./td', $row);
    $cell = $cells !== false ? $cells->item(0) : null;
    return array(
        'cells' => $cells !== false ? $cells->length : 0,
        'colspan' => $cell instanceof DOMElement ? (int)$cell->getAttribute('colspan') : 0,
    );
}

/**
 * @return array<string,mixed>
 */
function table_payload(int $columnCount): array
{
    $headers = $columnCount === 3 ? array('Merged heading', 'Heading 3') : array();
    $headerColspans = $columnCount === 3 ? array(2, 1) : array();
    if ($headers === array()) {
        for ($column = 0; $column < $columnCount; $column++) {
            $headers[] = 'Heading ' . ($column + 1);
            $headerColspans[] = 1;
        }
    }
    return array(
        'title' => 'Full-width title',
        'has_title_row' => true,
        'has_header_row' => true,
        'headers' => $headers,
        'header_colspans' => $headerColspans,
        'rows' => array(array_fill(0, $columnCount, 'Cell')),
        'row_colspans' => array(array_fill(0, $columnCount, 1)),
        'col_widths' => array_fill(0, $columnCount, 140),
    );
}

foreach (array(3, 4, 2) as $columnCount) {
    $shape = rendered_title_shape($renderer, table_payload($columnCount));
    if ($shape['cells'] !== 1 || $shape['colspan'] !== $columnCount) {
        $failures[] = "Expected one title cell spanning {$columnCount} logical columns.";
    }
}

$verticalPayload = table_payload(2);
$verticalPayload['rows'] = array(
    array('Merged top', 'Right 1'),
    array('', 'Right 2'),
);
$verticalPayload['row_colspans'] = array(array(1, 1), array(1, 1));
$verticalPayload['row_rowspans'] = array(array(2, 1), array(0, 1));
$verticalEditHtml = $renderer->renderBlock(
    array(
        'id' => 2,
        'block_type' => 'table',
        'stable_anchor' => 'vertical-table-edit',
        'payload_json' => json_encode($verticalPayload, JSON_THROW_ON_ERROR),
    ),
    ControlledPublishingBookRenderer::MODE_EDIT
);
$verticalReadHtml = $renderer->renderBlock(
    array(
        'id' => 2,
        'block_type' => 'table',
        'stable_anchor' => 'vertical-table-read',
        'payload_json' => json_encode($verticalPayload, JSON_THROW_ON_ERROR),
    ),
    ControlledPublishingBookRenderer::MODE_READ
);
if (!str_contains($verticalEditHtml, 'rowspan="2"')
    || !str_contains($verticalEditHtml, 'data-rowspan-covered="1"')) {
    $failures[] = 'Edit rendering does not preserve vertical merge metadata.';
}
if (!str_contains($verticalReadHtml, 'rowspan="2"')
    || str_contains($verticalReadHtml, 'data-rowspan-covered="1"')) {
    $failures[] = 'Published rendering does not emit a valid vertical rowspan.';
}
if (str_contains($verticalEditHtml, 'cpb-table-tools')) {
    $failures[] = 'Floating table tools still render inside the source table.';
}
if (!str_contains($verticalEditHtml, 'cpb-table-block--align-left')
    || !str_contains($verticalEditHtml, 'width:280px;max-width:100%')) {
    $failures[] = 'Table alignment frame does not retain the authored table width.';
}

$editorPath = dirname(__DIR__) . '/public/assets/controlled_book_editor.js';
$editorSource = (string)file_get_contents($editorPath);
$requiredMarkers = array(
    'function normalizeTableTitleRow(blockEl)',
    'titleCell.colSpan = tableColCount(blockEl);',
    'extraCell.remove();',
    'function removeLogicalColumnFromRow(row, logicalIndex)',
    'if (span > 1) cell.colSpan = span - 1;',
    'function shouldUseNativeTableCellClipboard(cell)',
    'if (shouldUseNativeTableCellClipboard(cell)) return;',
    'function copyEntireTable(blockEl)',
    'function pasteEntireTable(afterBlock)',
    'function tablePageContentMaxWidth()',
    'function applyStoredTableWidths()',
    'function tableCellPlainText(cell)',
    'function buildSelectedCellsCopy(blockEl, cells)',
    'function tableMergeCellDown(blockEl)',
    'function tableUnmergeCellDown(blockEl)',
    'function rebuildTableColumnResizeHandles(blockEl)',
    "handle.setAttribute('data-col-index', String(logicalIndex + colspan - 1));",
    "table.querySelectorAll('.cpb-col-resize')",
    'domRange: range.cloneRange()',
);
foreach ($requiredMarkers as $marker) {
    if (!str_contains($editorSource, $marker)) {
        $failures[] = "Editor is missing required regression marker: {$marker}";
    }
}

if (!preg_match(
    "/addEventListener\\('copy'.*shouldUseNativeTableCellClipboard\\(cell\\).*e\\.preventDefault\\(\\)/s",
    $editorSource
)) {
    $failures[] = 'Native text-copy guard must run before table-grid copy interception.';
}
if (!preg_match(
    "/addEventListener\\('paste'.*shouldUseNativeTableCellClipboard\\(cell\\).*e\\.preventDefault\\(\\)/s",
    $editorSource
)) {
    $failures[] = 'Native text-paste guard must run before table-grid paste interception.';
}

if ($failures !== array()) {
    fwrite(STDERR, "Controlled publishing table regressions: FAIL\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "- {$failure}\n");
    }
    exit(1);
}

echo "Controlled publishing table regressions: PASS\n";
echo "Title row: one cell spanning logical column count\n";
echo "Clipboard: native text editing precedes table-grid interception\n";
