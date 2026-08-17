<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/publishing/ControlledPublishingOutlineService.php';
require_once $root . '/src/publishing/ControlledPublishingPart0PageService.php';

$failures = array();
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$assert(
    ControlledPublishingOutlineService::isProtectedSectionKey('cover'),
    'Cover must stay locked in the outline editor.'
);
$assert(
    ControlledPublishingOutlineService::isProtectedSectionKey('toc'),
    'Part 0 TOC must stay locked in the outline editor.'
);
$assert(
    ControlledPublishingOutlineService::isProtectedSectionKey('lep'),
    'Part 0 LEP must stay locked in the outline editor.'
);
$assert(
    ControlledPublishingOutlineService::isProtectedSectionKey('annexes'),
    'Annexes must stay locked in the outline editor.'
);
$assert(
    ControlledPublishingOutlineService::isProtectedSectionKey('annexes_register'),
    'Annex register must stay locked in the outline editor.'
);
$assert(
    !ControlledPublishingOutlineService::isProtectedSectionKey('part_1'),
    'PART 1 must be editable in the outline editor.'
);
$assert(
    !ControlledPublishingOutlineService::isProtectedSectionKey('part_2_chapter_1'),
    'MAIN chapters must be editable in the outline editor.'
);

$assert(
    ControlledPublishingOutlineService::formatPartNavTitle(1, 'THE TRAINING PLAN') === 'PART 1 – THE TRAINING PLAN',
    'PART 1 title must format as PART 1 – THE TRAINING PLAN.'
);
$assert(
    ControlledPublishingOutlineService::formatPartNavTitle(2, 'PART 2 – BRIEFING AND EXERCISES') === 'PART 2 – BRIEFING AND EXERCISES',
    'Existing PART 2 prefix must not be duplicated.'
);
$assert(
    ControlledPublishingOutlineService::formatPartNavTitle(3, 'Flight Training in an FSTD') === 'PART 3 – FLIGHT TRAINING IN AN FSTD',
    'PART titles must be stored in uppercase.'
);
$assert(
    ControlledPublishingOutlineService::formatChapterNavTitle(1, 'COURSE ENROLLMENT') === '1. COURSE ENROLLMENT',
    'MAIN chapter 1 must keep its number and title.'
);
$assert(
    ControlledPublishingOutlineService::formatChapterNavTitle(2, '2. TRAINING RECORDS') === '2. TRAINING RECORDS',
    'Existing chapter numbers must not be duplicated.'
);

$assert(
    ControlledPublishingOutlineService::partNavTitle(
        array('section_key' => 'part_1', 'title' => 'PART 1 – THE TRAINING PLAN'),
        'PART 1 – General'
    ) === 'PART 1 – THE TRAINING PLAN',
    'Sidebar PART labels must use the stored outline title.'
);

$tree = ControlledPublishingOutlineService::headingTreeFromNavItems(array(
    array('section_ref' => '4.1', 'title' => 'Theory Training Syllabus', 'nav_label' => '4.1 Theory Training Syllabus'),
    array('section_ref' => '4.1.1', 'title' => 'Application', 'nav_label' => '4.1.1 Application'),
    array('section_ref' => '4.2', 'title' => 'Test and Examination', 'nav_label' => '4.2 Test and Examination'),
));
$assert(count($tree) === 2, 'First-level headings 4.1 and 4.2 must be promotable MAIN-chapter candidates.');
$assert(!empty($tree[0]['can_promote']), '4.1 must be promotable to a MAIN chapter.');
$assert(($tree[0]['headings'][0]['section_ref'] ?? '') === '4.1.1', '4.1.1 must stay nested under 4.1.');
$assert(empty($tree[0]['headings'][0]['can_promote']), 'Nested 4.1.1 must not promote in one step.');

$assert(
    ControlledPublishingOutlineService::rewritePromotedSectionRef('4.1', '4.1', 1) === '1',
    'Promoted heading 4.1 must become MAIN chapter 1.'
);
$assert(
    ControlledPublishingOutlineService::rewritePromotedSectionRef('4.1', '4.1.1', 1) === '1.1',
    'Child 4.1.1 must become 1.1 after promotion.'
);

$slice = ControlledPublishingOutlineService::headingBlockSlice(array(
    array('section_ref' => '4', 'block_id' => 1),
    array('section_ref' => '4.1', 'block_id' => 2),
    array('section_ref' => '4.1.1', 'block_id' => 3),
    array('section_ref' => '', 'block_id' => 4),
    array('section_ref' => '4.2', 'block_id' => 5),
), '4.1');
$assert(
    array_column($slice, 'block_id') === array(2, 3, 4),
    'Promoting 4.1 must take that heading, its children, and following body until 4.2.'
);

$outline = file_get_contents($root . '/src/publishing/ControlledPublishingOutlineService.php');
$nav = file_get_contents($root . '/src/publishing/ControlledPublishingEditorNavService.php');
$api = file_get_contents($root . '/public/admin/api/controlled_book_editor_api.php');
$page = file_get_contents($root . '/public/admin/compliance/controlled_book_editor.php');
$js = file_get_contents($root . '/public/assets/controlled_book_editor.js');
$structure = file_get_contents($root . '/src/publishing/ControlledPublishingManualStructureService.php');

if (!is_string($outline) || !str_contains($outline, 'Cover, Part 0, and Annexes cannot be edited in the outline.')) {
    $failures[] = 'Outline service must refuse edits to Cover, Part 0, and Annexes.';
}
if (!is_string($outline) || !str_contains($outline, 'function promoteHeading(')) {
    $failures[] = 'Outline service must promote a nested heading to a MAIN chapter.';
}
if (!is_string($outline) || !str_contains($outline, '_chapter_tmp_')) {
    $failures[] = 'Chapter reorder must use temporary section keys to avoid unique-key collisions.';
}
if (!is_string($outline) || !str_contains($outline, 'chapter_parent_id')) {
    $failures[] = 'Outline modal must load MAIN chapters from the same PART parent as the sidebar.';
}
if (!is_string($outline) || !str_contains($outline, 'computeSectionNumberDisplay(')) {
    $failures[] = 'Outline modal headings must use the same section numbers as the sidebar.';
}

foreach (array(
    'ControlledPublishingOutlineService::partNavTitle(',
    "'outline_kind' => 'locked'",
    "'outline_kind' => 'part'",
) as $marker) {
    if (!is_string($nav) || !str_contains($nav, $marker)) {
        $failures[] = 'Missing editor nav marker: ' . $marker;
    }
}
foreach (array(
    'get_outline',
    'rename_outline_part',
    'rename_outline_chapter',
    'add_outline_chapter',
    'delete_outline_chapter',
    'move_outline_chapter',
    'promote_outline_heading',
) as $marker) {
    if (!is_string($api) || !str_contains($api, $marker)) {
        $failures[] = 'Missing editor API marker: ' . $marker;
    }
}
foreach (array(
    'id="cpbEditOutline"',
    'class="cpb-tree-outline-btn"',
    'id="cpbStructModal"',
    'Make this a MAIN chapter',
) as $marker) {
    if (!is_string($page) || !str_contains($page, $marker)) {
        $failures[] = 'Missing editor page marker: ' . $marker;
    }
}
foreach (array(
    'openOutlinePanel(',
    'rename_outline_part',
    '+ Add MAIN chapter',
    'promote_outline_heading',
    'Make this a MAIN chapter',
    "status !== 'released'",
) as $marker) {
    if (!is_string($js) || !str_contains($js, $marker)) {
        $failures[] = 'Missing editor JS marker: ' . $marker;
    }
}
$css = file_get_contents($root . '/public/assets/controlled_book_editor.css');
if (!is_string($css) || !str_contains($css, '.cpb-tree-outline-btn')) {
    $failures[] = 'Missing editor CSS marker: .cpb-tree-outline-btn';
}
if (!is_string($css) || !str_contains($css, '.cpb-struct-overlay')) {
    $failures[] = 'Missing structure modal CSS.';
}
if (!is_string($css) || !str_contains($css, 'min-height: 22px !important')) {
    $failures[] = 'Outline inputs must stay compact against global compliance field styles.';
}
if (!is_string($css) || !str_contains($css, 'width: 18px !important')) {
    $failures[] = 'Outline move/delete buttons must stay compact against global compliance button styles.';
}
if (!is_string($structure) || !str_contains($structure, "\$meta['outline_locked']")) {
    $failures[] = 'Import overlay must not overwrite author-locked MAIN titles.';
}
if (!is_string($structure) || !str_contains($structure, 'function promoteHeadingToMainChapter(')) {
    $failures[] = 'Structure service must move heading blocks onto a new MAIN chapter.';
}

if ($failures !== array()) {
    fwrite(STDERR, "Controlled publishing outline editor: FAIL\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, '  - ' . $failure . "\n");
    }
    exit(1);
}

echo "Controlled publishing outline editor: PASS\n";
echo "PART/MAIN outline is editable; nested headings can become MAIN chapters; Cover, Part 0, and Annexes stay locked\n";
