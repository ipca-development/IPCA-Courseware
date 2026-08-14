<?php
declare(strict_types=1);

/**
 * Build the read-only controlled-content stylesheet used by native readers.
 *
 * The source stylesheet remains an immutable Admin Editor asset. This generator
 * selector-extracts content-rendering rules and rejects editor interaction
 * selectors. Native readers never load controlled_book_editor.css directly.
 */

$root = dirname(__DIR__);
$sourcePath = $root . '/public/assets/controlled_book_editor.css';
$outputPath = $root . '/public/assets/manual_reader_content.css';
$source = file_get_contents($sourcePath);
if (!is_string($source)) {
    fwrite(STDERR, "Unable to read {$sourcePath}\n");
    exit(1);
}
$source = (string)preg_replace('#/\*.*?\*/#s', '', $source);

$forbidden = '/(?:editor|toolbar|dialog|overlay|picker|chrome|dropzone|resize|rotate|'
    . 'workspace|fullscreen|modal|submit|import|upload|table-tools|empty-state|'
    . 'textarea|style-input|level-check|token|add-|delete|remove|sign-btn|'
    . 'page-layout|print-layout|paginated-page|change-marker|is-cell-selected|contenteditable|:hover|:focus|'
    . '\.cmp-page|control|button)/i';
$publicationSelector = '/\.cpb-(?:sheet(?:\b|-)|page-(?:header|footer)|block(?:\b|--)|'
    . 'heading|paragraph|cross-ref|section-number|regulatory-ref|font-|line-indent|list|'
    . 'table|image|callout|cover|toc(?:\b|-(?:row|link|label|page|depth|dot|title))|'
    . 'part0|lep|annex|amendment|distribution|abbreviations|definitions|highlights|revision)/i';
$rules = array();
if (preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $source, $matches, PREG_SET_ORDER)) {
    foreach ($matches as $match) {
        $selector = trim((string)$match[1]);
        $declarations = trim((string)$match[2]);
        if (
            $selector === ''
            || $declarations === ''
            || str_starts_with($selector, '@')
            || preg_match($forbidden, $selector)
        ) {
            continue;
        }
        if (!str_contains($selector, '.cpb-') && !str_contains($selector, '[data-')) {
            continue;
        }
        if (!preg_match($publicationSelector, $selector)) {
            continue;
        }
        $formattedDeclarations = array_values(array_filter(
            array_map('trim', explode(';', $declarations)),
            static fn(string $declaration): bool => $declaration !== ''
        ));
        if ($formattedDeclarations === array()) {
            continue;
        }
        $rules[] = $selector . " {\n  "
            . implode(";\n  ", $formattedDeclarations)
            . ";\n}";
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

/* Authoritative page geometry: Book Style frames are the only layout. */
.reader-canonical-page.cpb-sheet {
  padding: 0;
  margin: 0;
  zoom: 1;
  max-width: none;
  min-height: 0;
  box-shadow: none;
  border-radius: 0;
}
.reader-page-header-region,
.reader-page-footer-region,
.reader-page-body:not(.reader-page-cover) {
  overflow: hidden;
}
.reader-page-header-region > .cpb-page-header,
.reader-page-footer-region > .cpb-page-footer {
  position: static;
  inset: auto;
  width: 100%;
  height: 100%;
  margin: 0;
  box-sizing: border-box;
}

CSS;

$output = $header . implode("\n\n", array_values(array_unique($rules))) . "\n";
if (file_put_contents($outputPath, $output, LOCK_EX) === false) {
    fwrite(STDERR, "Unable to write {$outputPath}\n");
    exit(1);
}

echo "Generated public/assets/manual_reader_content.css (" . strlen($output) . " bytes)\n";
