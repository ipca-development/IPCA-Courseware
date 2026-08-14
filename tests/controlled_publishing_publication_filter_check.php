<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/publishing/ControlledPublishingPublicationFilter.php';

$failures = array();

function assert_true(bool $ok, string $message, array &$failures): void
{
    if (!$ok) {
        $failures[] = $message;
    }
}

$dropzone = '<div class="cpb-dropzone extra" data-dropzone="image">Drop image here to insert</div>';
$image = '<article class="cpb-block cpb-block--image" data-block-id="9" data-block-type="image" data-stable-anchor="block-image">'
    . '<div class="cpb-block-chrome" contenteditable="false">'
    . '<button type="button" class="cpb-block-btn" data-action="delete">×</button></div>'
    . '<figure class="cpb-image" data-field="image" style="width:80%" data-width-pct="80">'
    . '<div class="cpb-image-frame">'
    . '<img src="/media/cabin.png" alt="Cabin safety diagram">'
    . '<span class="cpb-image-resize" title="Drag to resize"></span>'
    . '<button type="button" class="cpb-image-rotate" data-image-action="rotate">↻</button>'
    . '</div>'
    . '<figcaption>Cabin safety diagram</figcaption>'
    . '</figure>'
    . '</article>';

$filtered = ControlledPublishingPublicationFilter::filterHtml($image . $dropzone);
assert_true(!str_contains($filtered, 'Drop image here to insert'), 'dropzone text must not survive filtering', $failures);
assert_true(!str_contains($filtered, 'cpb-dropzone'), 'dropzone class must not survive filtering', $failures);
assert_true(!str_contains($filtered, 'data-dropzone'), 'dropzone attribute must not survive filtering', $failures);
assert_true(!str_contains($filtered, 'cpb-block-chrome'), 'block chrome must not survive filtering', $failures);
assert_true(!str_contains($filtered, 'cpb-image-resize'), 'resize handle must not survive filtering', $failures);
assert_true(!str_contains($filtered, 'cpb-image-rotate'), 'rotate control must not survive filtering', $failures);
assert_true(str_contains($filtered, 'cabin.png'), 'real image must remain', $failures);
assert_true(str_contains($filtered, '<img'), 'img element must remain', $failures);
assert_true(str_contains($filtered, 'Cabin safety diagram'), 'image caption must remain', $failures);
assert_true(str_contains($filtered, 'cpb-image'), 'image figure must remain', $failures);

$shell = '<div class="cpb-sheet" data-section-id="4">'
    . '<div class="cpb-page-layout-toggle" contenteditable="false">Hide header/footer</div>'
    . '<header class="cpb-page-header"><span>EuroPilot Center</span></header>'
    . '<div class="cpb-sheet-body" data-blocks-root="1">'
    . '<article class="cpb-block cpb-block--paragraph"><p>Body copy.</p></article>'
    . '</div>'
    . $dropzone
    . '<footer class="cpb-page-footer">Copyright – EuroPilot Center<span class="cpb-page-number">Page: 4</span></footer>'
    . '</div>';

$parsed = ControlledPublishingPublicationFilter::parseSheet($shell);
assert_true($parsed['uses_sheet_body'] === true, 'sheet body must be detected', $failures);
assert_true(!str_contains($parsed['body'], 'Drop image here to insert'), 'dropzone must not enter parsed body', $failures);
assert_true(!str_contains($parsed['body'], 'cpb-page-header'), 'header must not leak into body', $failures);
assert_true(!str_contains($parsed['body'], 'cpb-page-footer'), 'footer must not leak into body', $failures);
assert_true(str_contains($parsed['body'], 'Body copy.'), 'body copy must remain', $failures);
assert_true(str_contains($parsed['header'], 'EuroPilot Center'), 'header template must remain', $failures);
assert_true(str_contains($parsed['footer'], 'Copyright – EuroPilot Center'), 'footer template must remain', $failures);
assert_true(str_contains($parsed['footer'], 'Page: 4'), 'page number must remain', $failures);
assert_true(!str_contains($parsed['footer'], 'Drop image'), 'dropzone must not sit in the footer', $failures);

$lepSheet = '<div class="cpb-sheet cpb-sheet--lep" style="width:816px;min-height:1056px">'
    . '<header class="cpb-page-header"><span>EuroPilot Center</span></header>'
    . '<div class="cpb-lep"><div class="cpb-lep-heading">0.1.1 Effective Parts</div>'
    . '<table class="cpb-lep-table"><tbody><tr class="cpb-lep-part-row"><td>Part 1</td></tr></tbody></table>'
    . '</div>'
    . '<footer class="cpb-page-footer">Copyright</footer>'
    . '</div>';
$lepParsed = ControlledPublishingPublicationFilter::parseSheet($lepSheet);
assert_true(!str_contains($lepParsed['body'], 'cpb-sheet'), 'LEP pagination body must not keep the page shell', $failures);
assert_true(!str_contains($lepParsed['body'], 'cpb-page-header'), 'LEP pagination body must not include header', $failures);
assert_true(!str_contains($lepParsed['body'], 'cpb-page-footer'), 'LEP pagination body must not include footer', $failures);
assert_true(!str_contains($lepParsed['body'], 'min-height:1056px'), 'LEP pagination body must not inherit page min-height', $failures);
assert_true(str_contains($lepParsed['body'], 'cpb-lep'), 'LEP inner content must remain', $failures);
assert_true(str_contains($lepParsed['body'], 'Part 1'), 'LEP row content must remain', $failures);
assert_true(str_contains($lepParsed['header'], 'EuroPilot Center'), 'LEP header template must remain', $failures);

$emptyImage = '<div class="cpb-image cpb-image--empty" data-field="image"><span>Image missing — upload or drop a file</span></div>';
$emptyFiltered = ControlledPublishingPublicationFilter::filterHtml($emptyImage);
assert_true(!str_contains($emptyFiltered, 'Image missing'), 'empty image affordance is editor chrome', $failures);

if ($failures !== array()) {
    fwrite(STDERR, "Publication filter: FAIL\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "Publication filter: PASS\n";
