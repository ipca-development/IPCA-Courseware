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
    ControlledPublishingDocxReader::isPlausibleManualSectionRef('2', 'Training Records', 1, true),
    'Flattened Title Case MAIN chapters such as 2. Training Records must remain valid excerpts.'
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

$heading = static function (string $ref, string $title): array {
    return array(
        'type' => 'paragraph',
        'text' => $ref . ' ' . $title,
        'style_id' => 'Titel',
        'style_name' => 'Titel',
        'section_ref' => $ref,
        'section_title' => $title,
        'paragraph_style' => ControlledPublishingDocxReader::sectionRefToParagraphStyle($ref),
    );
};

$flattened = ControlledPublishingDocxReader::flattenGenericPartChapters(array(
    $heading('1', 'GENERAL'),
    $heading('1.1', 'Course Enrollment'),
    $heading('1.1.1', 'Enrollment Phases'),
    $heading('1.2', 'Training Records'),
    $heading('1.3', 'Safety Training'),
), 1);

$refs = array();
foreach ($flattened as $node) {
    $refs[] = (string)($node['section_ref'] ?? '');
}
$assert(
    $refs === array('1', '1.1', '2', '3'),
    'Generic 1. GENERAL must flatten 1.1/1.2/1.3 into MAIN chapters 1/2/3.'
);
$assert(
    (string)($flattened[0]['section_title'] ?? '') === 'Course Enrollment'
        && (string)($flattened[0]['paragraph_style'] ?? '') === 'title',
    'Flattened 1.1 Course Enrollment must become MAIN chapter 1.'
);
$assert(
    (string)($flattened[1]['section_ref'] ?? '') === '1.1'
        && (string)($flattened[1]['paragraph_style'] ?? '') === 'subtitle_1',
    'Flattened 1.1.1 Enrollment Phases must become subsection 1.1.'
);
$assert(
    (string)($flattened[2]['section_title'] ?? '') === 'Training Records'
        && (string)($flattened[2]['paragraph_style'] ?? '') === 'title',
    'Flattened 1.2 Training Records must become MAIN chapter 2.'
);

$omShape = ControlledPublishingDocxReader::flattenGenericPartChapters(array(
    $heading('1', 'INTRODUCTION'),
    $heading('1.1', 'Scope'),
    $heading('2', 'ORGANIZATION AND RESPONSIBILITIES'),
    $heading('2.1', 'Structure'),
), 1);
$omRefs = array();
foreach ($omShape as $node) {
    $omRefs[] = (string)($node['section_ref'] ?? '');
}
$assert(
    $omRefs === array('1', '1.1', '2', '2.1'),
    'OM-style parts with real chapters 1 and 2 must not flatten 1.1 into a MAIN chapter.'
);

$realChapter = ControlledPublishingDocxReader::flattenGenericPartChapters(array(
    $heading('1', 'COURSE ENROLLMENT'),
    $heading('1.1', 'Enrollment Phases'),
    $heading('1.2', 'Entry Requirements'),
), 1);
$realRefs = array();
foreach ($realChapter as $node) {
    $realRefs[] = (string)($node['section_ref'] ?? '');
}
$assert(
    $realRefs === array('1', '1.1', '1.2'),
    'A real MAIN chapter with 1.1/1.2 subsections must keep its nested structure.'
);

$noWrapper = ControlledPublishingDocxReader::flattenGenericPartChapters(array(
    $heading('1.1', 'Course Enrollment'),
    $heading('1.2', 'Training Records'),
), 1);
$assert(
    (string)($noWrapper[0]['section_ref'] ?? '') === '1'
        && (string)($noWrapper[1]['section_ref'] ?? '') === '2',
    'Part files that start at 1.1/1.2 with no 1. GENERAL wrapper must still promote those to MAIN chapters.'
);

$assert(
    ControlledPublishingDocxReader::isGenericPartWrapperTitle('GENERAL'),
    'GENERAL must be treated as a generic part wrapper.'
);
$assert(
    !ControlledPublishingDocxReader::isGenericPartWrapperTitle('Course Enrollment'),
    'Course Enrollment must not be treated as a generic part wrapper.'
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
    'flattenGenericPartChapters(',
    'isGenericPartWrapperTitle(',
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
    'flattenGenericPartChapters(',
    'parsePartFile(',
    'ensureChapterSection(',
    'syncVersionStructure($versionId, $actorUserId, false)',
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
$structure = file_get_contents($root . '/src/publishing/ControlledPublishingManualStructureService.php');
if (!is_string($structure) || !str_contains($structure, '$pruneStaleChapters || $this->canRemoveChapterSection($row)')) {
    $failures[] = 'Non-OM structure sync must remove leftover OM MAIN chapter shells.';
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
