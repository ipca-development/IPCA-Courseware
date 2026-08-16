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

$outline = file_get_contents($root . '/src/publishing/ControlledPublishingOutlineService.php');
$nav = file_get_contents($root . '/src/publishing/ControlledPublishingEditorNavService.php');
$api = file_get_contents($root . '/public/admin/api/controlled_book_editor_api.php');
$page = file_get_contents($root . '/public/admin/compliance/controlled_book_editor.php');
$js = file_get_contents($root . '/public/assets/controlled_book_editor.js');
$structure = file_get_contents($root . '/src/publishing/ControlledPublishingManualStructureService.php');

if (!is_string($outline) || !str_contains($outline, 'Cover, Part 0, and Annexes cannot be edited in the outline.')) {
    $failures[] = 'Outline service must refuse edits to Cover, Part 0, and Annexes.';
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
) as $marker) {
    if (!is_string($api) || !str_contains($api, $marker)) {
        $failures[] = 'Missing editor API marker: ' . $marker;
    }
}
foreach (array(
    'id="cpbEditOutline"',
    'id="cpbOutlinePanel"',
    'Cover, Part 0, and Annexes stay fixed',
) as $marker) {
    if (!is_string($page) || !str_contains($page, $marker)) {
        $failures[] = 'Missing editor page marker: ' . $marker;
    }
}
foreach (array(
    'openOutlinePanel(',
    'rename_outline_part',
    '+ Add MAIN chapter',
) as $marker) {
    if (!is_string($js) || !str_contains($js, $marker)) {
        $failures[] = 'Missing editor JS marker: ' . $marker;
    }
}
if (!is_string($structure) || !str_contains($structure, "\$meta['outline_locked']")) {
    $failures[] = 'Import overlay must not overwrite author-locked MAIN titles.';
}

if ($failures !== array()) {
    fwrite(STDERR, "Controlled publishing outline editor: FAIL\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, '  - ' . $failure . "\n");
    }
    exit(1);
}

echo "Controlled publishing outline editor: PASS\n";
echo "PART/MAIN outline is editable; Cover, Part 0, and Annexes stay locked\n";
