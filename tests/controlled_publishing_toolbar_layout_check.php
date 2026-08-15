<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$markup = file_get_contents($root . '/public/admin/compliance/controlled_book_editor.php');
$css = file_get_contents($root . '/public/assets/controlled_book_editor.css');

if (!is_string($markup) || !is_string($css)) {
    fwrite(STDERR, "Unable to read controlled publishing toolbar files.\n");
    exit(1);
}

$primary = strpos($markup, 'cpb-toolbar-row--primary');
$listStart = strpos($markup, 'id="cpbListStart"');
$secondary = strpos($markup, 'cpb-toolbar-row--secondary');
$outdent = strpos($markup, 'id="cpbOutdent"');
$tableToolbar = strpos($markup, 'id="cpbTableToolbar"');

if ($primary === false || $listStart === false || $secondary === false || $outdent === false
    || $tableToolbar === false
    || !($primary < $listStart
        && $listStart < $tableToolbar
        && $tableToolbar < $secondary
        && $secondary < $outdent
    )) {
    fwrite(STDERR, "Toolbar controls are not split into the expected two rows.\n");
    exit(1);
}

if (str_contains($markup, 'data-cmd="removeList"')) {
    fwrite(STDERR, "Unused remove-list-formatting control is still present.\n");
    exit(1);
}

if (!str_contains($markup, '<select id="cpbListStart"')
    || !str_contains($markup, 'cpb-tool-select--list-start')) {
    fwrite(STDERR, "List start control must use the compact toolbar dropdown style.\n");
    exit(1);
}

foreach (array(
    '.cpb-toolbar-row--primary',
    '.cpb-toolbar-row--table-structure',
    '.cpb-toolbar-row--table-cells',
    'min-height: 160px',
    'height: 159px',
    'flex: 0 0 128px',
    'overflow-x: hidden',
    'width: 38px !important',
    'height: 22px !important',
) as $marker) {
    if (!str_contains($css, $marker)) {
        fwrite(STDERR, "Missing toolbar layout marker: {$marker}\n");
        exit(1);
    }
}

foreach (array(
    'data-table-action="table-align-left"',
    'data-table-action="toggle-title"',
    'data-table-action="delete-table"',
    'data-table-action="move-row-up"',
    'data-table-action="add-row"',
    'data-table-action="add-col"',
    'data-table-action="merge-cells-right"',
    'data-table-action="merge-cells-down"',
    'data-table-action="cell-bg"',
    'data-table-action="border-thick"',
    'data-table-action="formula-sum"',
    'data-table-action="copy-table"',
    'data-table-action="paste-table"',
) as $control) {
    if (!str_contains($markup, $control)) {
        fwrite(STDERR, "Missing always-visible table toolbar control: {$control}\n");
        exit(1);
    }
}
if (!str_contains($css, '.cpb-table-toolbar')) {
    fwrite(STDERR, "Missing always-visible table toolbar styling.\n");
    exit(1);
}

echo "Controlled publishing toolbar layout: PASS\n";
echo "Rows: formatting / insertion and table / rows, cells and calculations\n";
echo "List start selector: compact 38 x 22px\n";
echo "Table controls: always visible across three table rows\n";
