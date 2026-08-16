<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/publishing/ControlledPublishingDocxReader.php';

$failures = array();

$assert = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$assert(
    ControlledPublishingDocxReader::isPlausibleManualSectionRef('1', 'INTRODUCTION', 1),
    'ALL-CAPS OM chapter titles must still be accepted.'
);
$assert(
    !ControlledPublishingDocxReader::isPlausibleManualSectionRef('1', 'Personal Data', 1),
    'Title Case numbered lists must not become MAIN chapters without a heading style.'
);
$assert(
    ControlledPublishingDocxReader::isPlausibleManualSectionRef('1', 'Course Enrollment', 1, true),
    'Title Case chapter titles on a heading style must be accepted as MAIN sections.'
);
$assert(
    ControlledPublishingDocxReader::isPlausibleManualSectionRef('1.1', 'Course Enrollment', 1),
    'Subtitle 1.1 Title Case headings must remain accepted.'
);
$assert(
    ControlledPublishingDocxReader::isHeadingParagraphStyle('Titel', 'Titel'),
    'Pages Titel style must count as a chapter heading style.'
);

$promoted = ControlledPublishingDocxReader::promoteMissingChapterHeadings(array(
    array(
        'type' => 'paragraph',
        'text' => '1. Course Enrollment',
        'style_id' => 'Titel',
        'style_name' => 'Titel',
        'section_ref' => '',
        'section_title' => '',
        'paragraph_style' => 'body',
    ),
    array(
        'type' => 'paragraph',
        'text' => '1.1 Course Enrollment',
        'style_id' => 'Koptekst1',
        'style_name' => 'Koptekst 1',
        'section_ref' => '1.1',
        'section_title' => 'Course Enrollment',
        'paragraph_style' => 'subtitle_1',
    ),
    array(
        'type' => 'paragraph',
        'text' => '1.1.1 Enrollment Phases',
        'style_id' => 'Koptekst2',
        'style_name' => 'Koptekst 2',
        'section_ref' => '1.1.1',
        'section_title' => 'Enrollment Phases',
        'paragraph_style' => 'subtitle_2',
    ),
), 1);

$assert(
    (string)($promoted[0]['section_ref'] ?? '') === '1',
    'Title Case "1. Course Enrollment" must be promoted to MAIN chapter 1 when 1.1 exists.'
);
$assert(
    (string)($promoted[0]['paragraph_style'] ?? '') === 'title',
    'Promoted MAIN headings must use the title paragraph style.'
);
$assert(
    (string)($promoted[0]['section_title'] ?? '') === 'Course Enrollment',
    'Promoted MAIN heading must keep the imported title, not the OM Introduction label.'
);

$unnumbered = ControlledPublishingDocxReader::promoteMissingChapterHeadings(array(
    array(
        'type' => 'paragraph',
        'text' => 'Course Enrollment',
        'style_id' => 'Titel',
        'style_name' => 'Titel',
        'section_ref' => '',
        'section_title' => '',
        'paragraph_style' => 'body',
    ),
    array(
        'type' => 'paragraph',
        'text' => '1.1 Course Enrollment',
        'style_id' => 'Koptekst1',
        'style_name' => 'Koptekst 1',
        'section_ref' => '1.1',
        'section_title' => 'Course Enrollment',
        'paragraph_style' => 'subtitle_1',
    ),
), 1);

$assert(
    (string)($unnumbered[0]['section_ref'] ?? '') === '1',
    'Unnumbered Titel before 1.1 must become MAIN chapter 1.'
);
$assert(
    (string)($unnumbered[0]['section_title'] ?? '') === 'Course Enrollment',
    'Unnumbered Titel must keep its imported MAIN title.'
);

$listItem = ControlledPublishingDocxReader::promoteMissingChapterHeadings(array(
    array(
        'type' => 'paragraph',
        'text' => '1. INTRODUCTION',
        'style_id' => 'Titel',
        'style_name' => 'Titel',
        'section_ref' => '1',
        'section_title' => 'INTRODUCTION',
        'paragraph_style' => 'title',
    ),
    array(
        'type' => 'paragraph',
        'text' => '1. Personal Data',
        'style_id' => '',
        'style_name' => '',
        'section_ref' => '',
        'section_title' => '',
        'paragraph_style' => 'body',
    ),
    array(
        'type' => 'paragraph',
        'text' => '1.1 Scope',
        'style_id' => 'Koptekst1',
        'style_name' => 'Koptekst 1',
        'section_ref' => '1.1',
        'section_title' => 'Scope',
        'paragraph_style' => 'subtitle_1',
    ),
), 1);

$assert(
    (string)($listItem[1]['section_ref'] ?? '') === '',
    'Numbered body list items must not replace an existing MAIN chapter heading.'
);

$reader = file_get_contents($root . '/src/publishing/ControlledPublishingDocxReader.php');
$import = file_get_contents($root . '/src/publishing/ControlledPublishingDocxImportService.php');
$nav = file_get_contents($root . '/src/publishing/ControlledPublishingEditorNavService.php');
$foundation = file_get_contents($root . '/src/publishing/ControlledPublishingFoundationService.php');
if (!is_string($reader) || !is_string($import) || !is_string($nav) || !is_string($foundation)) {
    fwrite(STDERR, "Unable to read chapter-title sync implementation files.\n");
    exit(1);
}

foreach (array(
    'promoteMissingChapterHeadings(',
    'promoteUnnumberedChapterTitles(',
    'allowTitleCaseChapter',
    'isHeadingParagraphStyle(',
) as $marker) {
    if (!str_contains($reader, $marker)) {
        $failures[] = 'Missing DocxReader marker: ' . $marker;
    }
}
foreach (array(
    'sectionAllowsTitleCaseChapter(',
    'self::sectionAllowsTitleCaseChapter($section)',
) as $marker) {
    if (!str_contains($import, $marker)) {
        $failures[] = 'Missing DocxImportService marker: ' . $marker;
    }
}
if (!str_contains($nav, 'chapterTitleFromImportedBlocks(')) {
    $failures[] = 'Editor sidebar must prefer imported MAIN chapter titles.';
}
if (!str_contains($foundation, 'resetClonedChapterTitles(')) {
    $failures[] = 'New Manual structure-only clone must reset OM MAIN titles.';
}

if ($failures !== array()) {
    fwrite(STDERR, "Controlled publishing chapter title sync: FAIL\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, '  - ' . $failure . "\n");
    }
    exit(1);
}

echo "Controlled publishing chapter title sync: PASS\n";
echo "MAIN titles follow imported headings instead of cloned OM labels\n";
