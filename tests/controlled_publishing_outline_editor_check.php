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
    ControlledPublishingOutlineService::isPartHidden(array(
        'metadata_json' => '{"outline_hidden":true}',
    )),
    'Deleted empty PARTs must be hidden without deleting their stable section identity.'
);
$assert(
    !ControlledPublishingOutlineService::isPartHidden(array(
        'metadata_json' => '{"outline_hidden":false}',
    )),
    'Active PARTs must remain visible.'
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

$manualStructure = (new ReflectionClass(
    ControlledPublishingManualStructureService::class
))->newInstanceWithoutConstructor();
$legacyPart = array(
    'id' => 10,
    'section_key' => 'main_content',
    'title' => 'Main Content',
    'parent_section_id' => null,
);
$canonicalPart = array(
    'id' => 54,
    'section_key' => 'part_1',
    'title' => 'PART 1 – General',
    'parent_section_id' => null,
);
$legacyChapter = array(
    'id' => 35,
    'section_key' => 'part_1_chapter_1',
    'title' => 'Introduction',
    'parent_section_id' => 10,
);
$assert(
    $manualStructure->resolvePartTitleForSection(
        $legacyChapter,
        array($legacyPart, $canonicalPart, $legacyChapter)
    ) === 'PART 1 – GENERAL',
    'Legacy main_content chapters must use the canonical PART 1 title.'
);
$assert(
    $manualStructure->resolvePartTitleForSection(
        $legacyChapter,
        array($legacyPart, $legacyChapter)
    ) === 'PART 1 – GENERAL',
    'Generic legacy Main Content must fall back to PART 1 – GENERAL.'
);
$customLegacyPart = $legacyPart;
$customLegacyPart['title'] = 'Company Procedures';
$assert(
    $manualStructure->resolvePartTitleForSection(
        $legacyChapter,
        array($customLegacyPart, $legacyChapter)
    ) === 'PART 1 – COMPANY PROCEDURES',
    'A deliberately customized legacy PART title must remain intact.'
);

$tree = ControlledPublishingOutlineService::headingTreeFromNavItems(array(
    array('block_id' => 41, 'section_ref' => '4.1', 'title' => 'Theory Training Syllabus', 'nav_label' => '4.1 Theory Training Syllabus'),
    array('block_id' => 42, 'section_ref' => '4.1.1', 'title' => 'Application', 'nav_label' => '4.1.1 Application'),
    array('block_id' => 43, 'section_ref' => '4.2', 'title' => 'Test and Examination', 'nav_label' => '4.2 Test and Examination'),
));
$assert(count($tree) === 2, 'First-level headings 4.1 and 4.2 must be promotable MAIN-chapter candidates.');
$assert(!empty($tree[0]['can_promote']), '4.1 must be promotable to a MAIN chapter.');
$assert(($tree[0]['headings'][0]['section_ref'] ?? '') === '4.1.1', '4.1.1 must stay nested under 4.1.');
$assert(empty($tree[0]['headings'][0]['can_promote']), 'Nested 4.1.1 must not promote in one step.');
$assert(($tree[0]['block_id'] ?? 0) === 41, 'Promotable headings must preserve their source block identity.');

$assert(
    ControlledPublishingOutlineService::rewritePromotedSectionRef('4.1', '4.1', 1) === '1',
    'Promoted heading 4.1 must become MAIN chapter 1.'
);
$assert(
    ControlledPublishingOutlineService::rewritePromotedSectionRef('4.1', '4.1.1', 1) === '1.1',
    'Child 4.1.1 must become 1.1 after promotion.'
);
$assert(
    ControlledPublishingOutlineService::rewriteDemotedSectionRef(4, '4', '3.3') === '3.3',
    'Demoted MAIN chapter 4 must become subchapter 3.3.'
);
$assert(
    ControlledPublishingOutlineService::rewriteDemotedSectionRef(4, '4.1.2', '3.3') === '3.3.1.2',
    'Nested refs must remain nested after MAIN chapter demotion.'
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
$fallbackSlice = ControlledPublishingOutlineService::headingBlockSlice(array(
    array('section_ref' => '4', 'block_id' => 1),
    array('section_ref' => '', 'block_id' => 2),
    array('section_ref' => '', 'block_id' => 3),
    array('section_ref' => '4.2', 'block_id' => 5),
), '4.1', 2);
$assert(
    array_column($fallbackSlice, 'block_id') === array(2, 3),
    'Promotion must find a computed-number heading by block identity when no canonical ref is stored.'
);

$outline = file_get_contents($root . '/src/publishing/ControlledPublishingOutlineService.php');
$nav = file_get_contents($root . '/src/publishing/ControlledPublishingEditorNavService.php');
$api = file_get_contents($root . '/public/admin/api/controlled_book_editor_api.php');
$page = file_get_contents($root . '/public/admin/compliance/controlled_book_editor.php');
$js = file_get_contents($root . '/public/assets/controlled_book_editor.js');
$structure = file_get_contents($root . '/src/publishing/ControlledPublishingManualStructureService.php');
$toc = file_get_contents($root . '/src/publishing/ControlledPublishingTocService.php');
$sections = file_get_contents($root . '/src/publishing/ControlledPublishingSectionService.php');
$pagination = file_get_contents($root . '/src/publishing/ControlledPublishingPaginationService.php');
$lep = file_get_contents($root . '/src/publishing/ControlledPublishingLepService.php');
$revision = file_get_contents($root . '/src/publishing/ControlledPublishingRevisionService.php');

if (!is_string($outline) || !str_contains($outline, 'Cover, Part 0, and Annexes cannot be edited in the outline.')) {
    $failures[] = 'Outline service must refuse edits to Cover, Part 0, and Annexes.';
}
if (!is_string($outline) || !str_contains($outline, 'function promoteHeading(')) {
    $failures[] = 'Outline service must promote a nested heading to a MAIN chapter.';
}
if (!is_string($outline)
    || !str_contains($outline, '$insertAt = $index + 1 + $headingIndex;')
    || !str_contains($outline, 'SELECT COUNT(*) FROM ipca_publishing_book_blocks')) {
    $failures[] = 'Heading promotion must follow and preserve its populated source MAIN chapter.';
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
    'add_outline_part',
    'delete_outline_part',
    'rename_outline_chapter',
    'add_outline_chapter',
    'delete_outline_chapter',
    'move_outline_chapter',
    'promote_outline_heading',
    'demote_outline_chapter',
    'block_id',
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
    'add_outline_part',
    'delete_outline_part',
    '+ Add PART',
    'Only an empty PART can be deleted.',
    '+ Add MAIN chapter',
    'promote_outline_heading',
    'Make this a MAIN chapter',
    'demote_outline_chapter',
    'Make subchapter',
    "status !== 'released'",
    'data-block-id',
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
if (!is_string($css)
    || !str_contains($css, 'button.cpb-outline-btn--demote')
    || !str_contains($css, 'min-width: 112px !important')) {
    $failures[] = 'The labeled MAIN chapter demotion action must remain readable.';
}
if (!is_string($structure) || !str_contains($structure, "\$meta['outline_locked']")) {
    $failures[] = 'Import overlay must not overwrite author-locked MAIN titles.';
}
if (!is_string($structure) || !str_contains($structure, 'function promoteHeadingToMainChapter(')) {
    $failures[] = 'Structure service must move heading blocks onto a new MAIN chapter.';
}
if (!is_string($structure) || !str_contains($structure, 'function demoteMainChapterToSubchapter(')) {
    $failures[] = 'Structure service must move a MAIN chapter back under an earlier chapter.';
}
if (!is_string($outline) || !str_contains($outline, 'function demoteChapter(')) {
    $failures[] = 'Outline service must expose MAIN chapter demotion.';
}
if (!is_string($outline)
    || !str_contains($outline, 'function addPart(')
    || !str_contains($outline, 'function deletePart(')
    || !str_contains($outline, "'part_' . \$partNumber")
    || str_contains($outline, 'maximum of four PARTs')
    || !str_contains($outline, "'outline_hidden' => true")
    || !str_contains($outline, 'Move or delete every chapter before deleting this PART.')
    || !str_contains($outline, 'Remove all PART content before deleting this PART.')) {
    $failures[] = 'PART add/delete must preserve identity and refuse deletion while content remains.';
}
if (!is_string($sections)
    || !str_contains($sections, "preg_match('/^part_[1-9][0-9]*$/'")) {
    $failures[] = 'Dynamic PARTs must accept author-created MAIN chapters and subsections.';
}
if (!is_string($pagination)
    || !str_contains($pagination, 'function isPartSectionKey(')
    || !str_contains($pagination, "/^part_[1-9][0-9]*$/")) {
    $failures[] = 'Authoritative pagination must classify PART 5 and later as PART starts.';
}
if (!is_string($lep)
    || !str_contains($lep, "section_key LIKE 'part_%'")
    || !str_contains($lep, "preg_match('/^part_[1-9][0-9]*$/'")) {
    $failures[] = 'The List of Effective Parts must include dynamically created PARTs.';
}
if (!is_string($revision)
    || !str_contains($revision, "preg_match('/part_([1-9][0-9]*)/'")) {
    $failures[] = 'Revision highlights must resolve labels for dynamically created PARTs.';
}
if (!is_string($nav)
    || !str_contains($nav, 'ControlledPublishingOutlineService::isPartHidden($partRow)')) {
    $failures[] = 'Deleted empty PARTs must disappear from editor navigation.';
}
if (!is_string($toc)
    || !str_contains($toc, "array('part_1', 'main_content')")
    || !str_contains($toc, 'ControlledPublishingOutlineService::partNavTitle(')
    || !str_contains($toc, 'ControlledPublishingOutlineService::isPartHidden($row)')) {
    $failures[] = 'Generated TOC PART labels must use the same resolver as editor/iOS navigation.';
}
if (!is_string($api) || !str_contains($api, "\$tocSvc->regenerateTocSection(\$versionId, \$uid)")) {
    $failures[] = 'Outline mutations must refresh the generated TOC automatically.';
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
