<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$js = (string)file_get_contents($root . '/public/assets/controlled_book_editor.js');
$css = (string)file_get_contents($root . '/public/assets/controlled_book_editor.css');
$failures = array();

$assertContains = static function (string $haystack, string $needle, string $message) use (&$failures): void {
    if (!str_contains($haystack, $needle)) {
        $failures[] = $message;
    }
};

$assertContains($js, 'function syncPrintPageGeometry(', 'Editor canvas must size print pages from section orientation.');
$assertContains($js, 'PRINT_PAGE.width = landscape ? 1056 : 816;', 'Landscape canvas pages must be 1056px wide.');
$assertContains($js, 'PRINT_PAGE.height = landscape ? 816 : 1056;', 'Landscape canvas pages must be 816px tall.');
$assertContains($css, '.cpb-sheet.cpb-print-layout.cpb-sheet--landscape', 'Print-layout CSS must keep landscape sheet geometry.');
$assertContains($css, '--cpb-print-page-width: 1056px;', 'Landscape print pages must use letter landscape width.');
$assertContains($css, '--cpb-print-page-height: 816px;', 'Landscape print pages must use letter landscape height.');
$assertContains($css, '.cpb-editor-root:not(.cpb-editor-paginated-mode) .cpb-canvas-scroll:has(.cpb-sheet--landscape)', 'Landscape canvas may scroll horizontally without changing paginated mode.');

if (preg_match('/\.cpb-paginated-page\s*\{[^}]*width:\s*1056px/', $css) === 1) {
    $failures[] = 'Paginated-mode page frames must stay portrait unless pagination is explicitly changed.';
}

if ($failures !== array()) {
    fwrite(STDERR, "Controlled book editor landscape canvas: FAIL\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, ' - ' . $failure . "\n");
    }
    exit(1);
}

echo "Controlled book editor landscape canvas: PASS\n";
echo "Canvas print layout follows landscape orientation; paginated frames unchanged\n";
