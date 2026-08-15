<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/publishing/ControlledPublishingPaginationService.php';

$pdo = new PDO('sqlite::memory:');
$reader = new ControlledPublishingReaderService($pdo);
$service = new ControlledPublishingPaginationService($reader);

$unitsMethod = new ReflectionMethod($service, 'unitsFromRenderedBody');
$section = array(
    'id' => 70,
    'section_key' => 'highlights',
    'parent_section_id' => null,
    'stable_anchor' => 'OM-6_0-HIGHLIGHTS',
    'is_generated' => 1,
    'is_system_managed' => 1,
    'allow_author_blocks' => 1,
);
$flags = array(
    'is_part0' => true,
    'pagination_authority' => 'generated',
);
$html = '<div class="cpb-part0">'
    . '<div class="cpb-lep-heading cpb-part0-heading cpb-ps-subtitle_1"'
    . ' data-part0-heading="subtitle_1" data-paragraph-style="subtitle_1">'
    . '0.7 Highlight of Changes</div>'
    . '<div class="cpb-part0-body">'
    . '<article class="cpb-block cpb-block--paragraph" data-block-id="701"'
    . ' data-stable-anchor="HIGHLIGHT-SUMMARY"><p>Revision 6 Changes:</p></article>'
    . '</div></div>';
$units = $unitsMethod->invoke($service, $html, $section, $flags);

if (count($units) !== 2) {
    throw new RuntimeException('Part 0 heading/article normalization did not emit two units.');
}
if (
    (string)($units[0]['block_type'] ?? '') !== 'heading'
    || !str_contains((string)($units[0]['html'] ?? ''), '0.7 Highlight of Changes')
) {
    throw new RuntimeException('Part 0 title was not preserved as the first authoritative unit.');
}
if ((string)($units[1]['block_type'] ?? '') !== 'paragraph') {
    throw new RuntimeException('Part 0 governed body block was not preserved after its title.');
}

$classifyMethod = new ReflectionMethod($service, 'classifySection');
$classified = $classifyMethod->invoke($service, $section, array(70 => $section));
if (empty($classified['force_page_break_before']) || empty($classified['is_part0'])) {
    throw new RuntimeException('Generated Part 0 section boundary is not explicit.');
}

echo "Controlled publishing Part 0 source: PASS\n";
