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

if ($primary === false || $listStart === false || $secondary === false || $outdent === false
    || !($primary < $listStart && $listStart < $secondary && $secondary < $outdent)) {
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
    'min-height: 64px',
    'width: 38px !important',
    'height: 22px !important',
) as $marker) {
    if (!str_contains($css, $marker)) {
        fwrite(STDERR, "Missing toolbar layout marker: {$marker}\n");
        exit(1);
    }
}

echo "Controlled publishing toolbar layout: PASS\n";
echo "Rows: formatting through lists / indentation and insertion tools\n";
echo "List start selector: compact 38 x 22px\n";
