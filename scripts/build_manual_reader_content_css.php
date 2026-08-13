<?php
declare(strict_types=1);

/**
 * Build the read-only controlled-content stylesheet used by native readers.
 *
 * The source stylesheet remains an immutable Admin Editor asset. This generator
 * copies only content-rendering rule ranges and rejects editor interaction
 * selectors. Native readers never load controlled_book_editor.css directly.
 */

$root = dirname(__DIR__);
$sourcePath = $root . '/public/assets/controlled_book_editor.css';
$outputPath = $root . '/public/assets/manual_reader_content.css';
$lines = file($sourcePath);
if (!is_array($lines)) {
    fwrite(STDERR, "Unable to read {$sourcePath}\n");
    exit(1);
}

$ranges = array(
    array(654, 798),   // sheet and controlled header/footer
    array(1032, 1075), // sheet metadata/body and block shell
    array(1145, 1906), // headings, paragraphs, lists, tables, figures
    array(2169, 2299), // controlled callouts and links
    array(2603, 2877), // cover and generated TOC
);

$chunks = array();
foreach ($ranges as [$start, $end]) {
    $chunks[] = implode('', array_slice($lines, $start - 1, $end - $start + 1));
}
$candidate = implode("\n", $chunks);

$forbidden = '/(?:editor|toolbar|dialog|overlay|picker|chrome|dropzone|resize|rotate|'
    . 'page-layout|change-marker|is-cell-selected|contenteditable|:hover|:focus|'
    . '\.cmp-page|control|button)/i';
$rules = array();
if (preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $candidate, $matches, PREG_SET_ORDER)) {
    foreach ($matches as $match) {
        $selector = trim((string)$match[1]);
        $declarations = trim((string)$match[2]);
        if ($selector === '' || $declarations === '' || preg_match($forbidden, $selector)) {
            continue;
        }
        if (!str_contains($selector, '.cpb-') && !str_contains($selector, '[data-')) {
            continue;
        }
        $rules[] = $selector . " {\n  "
            . preg_replace('/\s*;\s*/', ";\n  ", $declarations)
            . "\n}";
    }
}

$header = <<<'CSS'
/*
 * GENERATED FILE — read-only controlled manual content styles.
 * Source: controlled_book_editor.css content-rendering rules only.
 * Regenerate with: php scripts/build_manual_reader_content_css.php
 */
:root {
  --cpb-frame-border-color: #94a3b8;
  --cpb-frame-border-width: 1px;
  --cpb-frame-radius: 4px;
}
* { box-sizing: border-box; }
.cpb-block-chrome,
.cpb-dropzone,
.cpb-change-marker,
.cpb-page-layout-toggle,
.cpb-image-rotate,
.cpb-image-resize {
  display: none !important;
}

CSS;

$output = $header . implode("\n\n", array_values(array_unique($rules))) . "\n";
if (file_put_contents($outputPath, $output, LOCK_EX) === false) {
    fwrite(STDERR, "Unable to write {$outputPath}\n");
    exit(1);
}

echo "Generated public/assets/manual_reader_content.css (" . strlen($output) . " bytes)\n";
