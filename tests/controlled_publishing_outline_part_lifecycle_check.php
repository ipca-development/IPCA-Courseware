<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/publishing/ControlledPublishingOutlineService.php';
require_once $root . '/src/publishing/ControlledPublishingLepService.php';
require_once $root . '/src/publishing/ControlledPublishingEditorNavService.php';
require_once $root . '/src/publishing/ControlledPublishingPaginationService.php';
require_once $root . '/src/publishing/ControlledPublishingTocService.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE ipca_publishing_books (
    id INTEGER PRIMARY KEY,
    book_key TEXT,
    title TEXT,
    book_type TEXT,
    manual_code TEXT
)');
$pdo->exec('CREATE TABLE ipca_publishing_source_baselines (
    id INTEGER PRIMARY KEY,
    baseline_key TEXT,
    baseline_status TEXT,
    baseline_hash TEXT,
    source_snapshot_json TEXT,
    mapping_snapshot_json TEXT,
    frozen_at TEXT
)');
$pdo->exec('CREATE TABLE ipca_publishing_book_versions (
    id INTEGER PRIMARY KEY,
    book_id INTEGER,
    source_baseline_id INTEGER,
    version_label TEXT,
    lifecycle_status TEXT,
    metadata_json TEXT
)');
$pdo->exec('CREATE TABLE ipca_publishing_book_template_sections (
    id INTEGER PRIMARY KEY,
    allow_author_blocks INTEGER
)');
$pdo->exec('CREATE TABLE ipca_publishing_book_sections (
    id INTEGER PRIMARY KEY,
    book_version_id INTEGER,
    template_section_id INTEGER,
    parent_section_id INTEGER,
    section_key TEXT,
    stable_anchor TEXT,
    title TEXT,
    section_type TEXT,
    metadata_json TEXT,
    is_system_managed INTEGER,
    is_generated INTEGER,
    sort_order INTEGER,
    created_by INTEGER,
    updated_at TEXT
)');
$pdo->exec('CREATE TABLE ipca_publishing_book_blocks (
    id INTEGER PRIMARY KEY,
    book_version_id INTEGER,
    section_id INTEGER,
    block_type TEXT,
    payload_json TEXT
)');

$pdo->exec("INSERT INTO ipca_publishing_books VALUES (1, 'OM', 'Operations Manual', 'manual', 'OM')");
$pdo->exec("INSERT INTO ipca_publishing_book_versions
    VALUES (1, 1, NULL, '1.0', 'draft', '{}')");
$insertPart = $pdo->prepare('INSERT INTO ipca_publishing_book_sections (
    id, book_version_id, template_section_id, parent_section_id, section_key,
    stable_anchor, title, section_type, metadata_json, is_system_managed,
    is_generated, sort_order, created_by, updated_at
) VALUES (?, 1, NULL, NULL, ?, ?, ?, \'content\', ?, 0, 0, ?, 1, CURRENT_TIMESTAMP)');
foreach (range(1, 4) as $partNumber) {
    $insertPart->execute(array(
        $partNumber,
        'part_' . $partNumber,
        'OM-1_0-PART-' . $partNumber,
        'PART ' . $partNumber . ' – ORIGINAL',
        json_encode(array('manual_part' => $partNumber), JSON_THROW_ON_ERROR),
        90 + ($partNumber * 10),
    ));
}
$insertPart->execute(array(
    10,
    'annexes',
    'OM-1_0-ANNEXES',
    'Annexes',
    '{}',
    140,
));
$pdo->exec("INSERT INTO ipca_publishing_book_sections (
    id, book_version_id, template_section_id, parent_section_id, section_key,
    stable_anchor, title, section_type, metadata_json, is_system_managed,
    is_generated, sort_order, created_by, updated_at
) VALUES (
    20, 1, NULL, 2, 'part_2_chapter_1', 'OM-1_0-PART-2-CHAPTER-1',
    'Populated chapter', 'content', '{\"manual_part\":2,\"chapter_number\":1}',
    0, 0, 10, 1, CURRENT_TIMESTAMP
)");

$foundation = new ControlledPublishingFoundationService($pdo);
$sections = new ControlledPublishingSectionService($pdo);
$structure = new ControlledPublishingManualStructureService($pdo, $foundation, $sections);
$outline = new ControlledPublishingOutlineService($pdo, $foundation, $sections, $structure);

try {
    $outline->deletePart(1, 2, 1);
    throw new RuntimeException('Deleting a PART with chapters unexpectedly succeeded.');
} catch (RuntimeException $e) {
    if (!str_contains($e->getMessage(), 'Move or delete every chapter')) {
        throw $e;
    }
}

$outline->deletePart(1, 1, 1);
$afterDelete = $outline->getOutline(1);
if (count($afterDelete['parts']) !== 3 || empty($afterDelete['can_add_part'])) {
    throw new RuntimeException('Deleting an empty PART did not remove it from the outline safely.');
}
$storedMeta = json_decode((string)$pdo->query(
    'SELECT metadata_json FROM ipca_publishing_book_sections WHERE id = 1'
)->fetchColumn(), true);
if (empty($storedMeta['outline_hidden'])) {
    throw new RuntimeException('Deleting an empty PART destroyed or failed to hide its stable section.');
}

$restoredId = $outline->addPart(1, 'Restored operations', 1);
if ($restoredId !== 1) {
    throw new RuntimeException('Adding a PART did not restore the first available PART slot.');
}
$restored = $sections->getSection(1, 1);
$restoredMeta = json_decode((string)($restored['metadata_json'] ?? '{}'), true);
if (!empty($restoredMeta['outline_hidden'])
    || (string)($restored['title'] ?? '') !== 'PART 1 – RESTORED OPERATIONS') {
    throw new RuntimeException('Restored PART visibility or title is incorrect.');
}

$part5Id = $outline->addPart(1, 'Additional procedures', 1);
$part5 = $sections->getSection(1, $part5Id);
if ((string)($part5['section_key'] ?? '') !== 'part_5'
    || (string)($part5['title'] ?? '') !== 'PART 5 – ADDITIONAL PROCEDURES'
    || (int)($part5['sort_order'] ?? 0) !== 140) {
    throw new RuntimeException('Adding beyond the original four PARTs did not create PART 5 correctly.');
}
$annexSort = (int)$pdo->query(
    "SELECT sort_order FROM ipca_publishing_book_sections WHERE section_key = 'annexes'"
)->fetchColumn();
if ($annexSort !== 150) {
    throw new RuntimeException('Adding PART 5 did not keep Annexes after all manual PARTs.');
}
$chapter5Id = $outline->addChapter(1, $part5Id, 'Supplemental chapter', 1);
if ($chapter5Id <= 0) {
    throw new RuntimeException('A MAIN chapter could not be added under dynamic PART 5.');
}
$afterPart5 = $outline->getOutline(1);
$part5Outline = array_values(array_filter(
    $afterPart5['parts'],
    static fn(array $part): bool => (int)($part['part_number'] ?? 0) === 5
));
if (count($afterPart5['parts']) !== 5
    || count($part5Outline) !== 1
    || count($part5Outline[0]['chapters'] ?? array()) !== 1) {
    throw new RuntimeException('Dynamic PART 5 or its MAIN chapter is missing from Edit Outline.');
}
$lep = new ControlledPublishingLepService($pdo);
$effectiveParts = $lep->computeEffectiveParts(1, array('version_label' => '1.0'));
$part5Lep = array_values(array_filter(
    $effectiveParts,
    static fn(array $part): bool => (string)($part['part'] ?? '') === '5'
));
if (count($part5Lep) !== 1
    || (string)($part5Lep[0]['label'] ?? '') !== 'PART 5 – ADDITIONAL PROCEDURES') {
    throw new RuntimeException('Dynamic PART 5 is missing from the List of Effective Parts.');
}
$toc = new ControlledPublishingTocService($pdo, new ControlledPublishingBlockService($pdo));
$tocReflection = new ReflectionClass($toc);
$loadChapters = $tocReflection->getMethod('loadChaptersGroupedByPart');
$tocGroups = $loadChapters->invoke($toc, 1);
if (!isset($tocGroups['part_5']) || count($tocGroups['part_5']) !== 1) {
    throw new RuntimeException('Dynamic PART 5 is missing from the generated Table of Contents.');
}
$navReflection = new ReflectionClass(ControlledPublishingEditorNavService::class);
$nav = $navReflection->newInstanceWithoutConstructor();
$partDefinitions = $navReflection->getMethod('partDefinitions');
$rowsByKey = array();
foreach ($sections->listFlatSections(1) as $row) {
    $rowsByKey[(string)$row['section_key']] = $row;
}
$navParts = $partDefinitions->invoke($nav, $rowsByKey, 'OM');
if ((string)($navParts[count($navParts) - 1]['section_key'] ?? '') !== 'part_5') {
    throw new RuntimeException('Dynamic PART 5 is missing from the editor navigation model.');
}

$paginationReflection = new ReflectionClass(ControlledPublishingPaginationService::class);
$pagination = $paginationReflection->newInstanceWithoutConstructor();
$classifySection = $paginationReflection->getMethod('classifySection');
$part5Classification = $classifySection->invoke($pagination, array(
    'id' => $part5Id,
    'section_key' => 'part_5',
    'parent_section_id' => null,
), array());
if (empty($part5Classification['is_major_section_start'])
    || ($part5Classification['manual_part'] ?? null) !== 'part_5') {
    throw new RuntimeException('Authoritative pagination did not classify dynamic PART 5 as a PART start.');
}

$part6Id = $outline->addPart(1, 'Additional material', 1);
$part6 = $sections->getSection(1, $part6Id);
$annexSortAfterPart6 = (int)$pdo->query(
    "SELECT sort_order FROM ipca_publishing_book_sections WHERE section_key = 'annexes'"
)->fetchColumn();
if ((string)($part6['section_key'] ?? '') !== 'part_6'
    || (int)($part6['sort_order'] ?? 0) !== 150
    || $annexSortAfterPart6 !== 160) {
    throw new RuntimeException('Consecutive dynamic PART creation did not keep Annexes last.');
}
$outline->deletePart(1, $part6Id, 1);
$restoredPart6Id = $outline->addPart(1, 'Restored additional material', 1);
if ($restoredPart6Id !== $part6Id) {
    throw new RuntimeException('A hidden dynamic PART was not restored before creating another PART.');
}
$part6Count = (int)$pdo->query(
    "SELECT COUNT(*) FROM ipca_publishing_book_sections WHERE section_key IN ('part_6', 'part_7')"
)->fetchColumn();
if ($part6Count !== 1) {
    throw new RuntimeException('Restoring dynamic PART 6 unexpectedly created PART 7.');
}

$pdo->exec("INSERT INTO ipca_publishing_book_blocks
    VALUES (1, 1, 1, 'paragraph', '{\"html\":\"<p>Protected content</p>\"}')");
try {
    $outline->deletePart(1, 1, 1);
    throw new RuntimeException('Deleting a PART with direct content unexpectedly succeeded.');
} catch (RuntimeException $e) {
    if (!str_contains($e->getMessage(), 'Remove all PART content')) {
        throw $e;
    }
}

echo "Controlled publishing outline PART lifecycle: PASS\n";
