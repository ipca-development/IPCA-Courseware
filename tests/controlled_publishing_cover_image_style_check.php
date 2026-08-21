<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$renderer = (string)file_get_contents(
    $root . '/src/publishing/ControlledPublishingBookRenderer.php'
);
$editorCss = (string)file_get_contents(
    $root . '/public/assets/controlled_book_editor.css'
);
$readerCss = (string)file_get_contents(
    $root . '/public/assets/manual_reader_content.css'
);

$checks = array(
    'renderer marks populated cover images for styling' =>
        str_contains($renderer, "cpb-cover-image--styled"),
    'editor renders cover images in black and white' =>
        str_contains($editorCss, '.cpb-cover-image--styled .cpb-cover-image-img')
        && str_contains($editorCss, 'filter: grayscale(1)'),
    'reader renders cover images in black and white' =>
        str_contains($readerCss, '.cpb-cover-image--styled .cpb-cover-image-img')
        && str_contains($readerCss, 'filter: grayscale(1)'),
    'editor and reader use the same navy gradient' =>
        str_contains($editorCss, 'rgba(15, 39, 68, .36)')
        && str_contains($editorCss, 'rgba(15, 39, 68, 0) 75%')
        && str_contains($readerCss, 'rgba(15, 39, 68, .36)')
        && str_contains($readerCss, 'rgba(15, 39, 68, 0) 75%'),
    'cover overlay cannot block image replacement' =>
        str_contains($editorCss, 'pointer-events: none')
        && str_contains($readerCss, 'pointer-events: none'),
);

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}

echo "Controlled publishing cover image style: PASS\n";
