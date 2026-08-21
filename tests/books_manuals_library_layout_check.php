<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$css = (string)file_get_contents($root . '/public/assets/books-manuals.css');
$importPage = (string)file_get_contents(
    $root . '/public/admin/compliance/controlled_book_docx_import.php'
);

$checks = array(
    'library cards retain a readable minimum width' =>
        str_contains($css, 'minmax(min(100%, 420px), 1fr)'),
    'metadata uses word boundaries instead of character wrapping' =>
        str_contains($css, 'overflow-wrap: break-word')
        && str_contains($css, 'word-break: normal'),
    'duplicate-import option has the requested label' =>
        str_contains($importPage, "Don't Import Duplicate Content"),
);

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}

echo "Books & Manuals library layout: PASS\n";
