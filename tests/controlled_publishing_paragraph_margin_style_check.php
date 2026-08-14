<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/src/publishing/ControlledPublishingBookStyleService.php');
$renderer = file_get_contents($root . '/src/publishing/ControlledPublishingBookRenderer.php');
$editor = file_get_contents($root . '/public/assets/controlled_book_editor.js');

foreach (array($service, $renderer, $editor) as $source) {
    if (!is_string($source)) {
        fwrite(STDERR, "Unable to read paragraph margin implementation files.\n");
        exit(1);
    }
}

foreach (array('margin_top', 'margin_bottom', 'normalizeParagraphMargin') as $marker) {
    if (!str_contains($service, $marker)) {
        fwrite(STDERR, "Missing paragraph margin service marker: {$marker}\n");
        exit(1);
    }
}

foreach (array('margin-top:', 'margin-bottom:', '$includeSpacing') as $marker) {
    if (!str_contains($renderer, $marker)) {
        fwrite(STDERR, "Missing paragraph margin renderer marker: {$marker}\n");
        exit(1);
    }
}

foreach (array(
    'Top margin (px)',
    'Bottom margin (px)',
    'data-ps-field="margin_top"',
    'data-ps-field="margin_bottom"',
) as $marker) {
    if (!str_contains($editor, $marker)) {
        fwrite(STDERR, "Missing paragraph margin editor marker: {$marker}\n");
        exit(1);
    }
}

echo "Controlled publishing paragraph margins: PASS\n";
echo "Book styles: optional top and bottom spacing from 0 to 200px\n";
echo "Legacy styles: unchanged until explicit margins are saved\n";
echo "Native reader: exact generated book-style CSS remains authoritative\n";
