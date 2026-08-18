<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/publishing/ControlledPublishingRevisionService.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE ipca_publishing_books (id INTEGER PRIMARY KEY, book_key TEXT NOT NULL)');
$pdo->exec('CREATE TABLE ipca_publishing_book_versions (
    id INTEGER PRIMARY KEY, book_id INTEGER NOT NULL, version_label TEXT,
    supersedes_version_id INTEGER NULL
)');
$pdo->exec('CREATE TABLE ipca_publishing_book_sections (
    id INTEGER PRIMARY KEY, book_version_id INTEGER NOT NULL, section_key TEXT,
    title TEXT, sort_order INTEGER
)');
$pdo->exec('CREATE TABLE ipca_publishing_book_blocks (
    id INTEGER PRIMARY KEY, book_version_id INTEGER NOT NULL, section_id INTEGER NOT NULL,
    block_key TEXT, stable_anchor TEXT, block_type TEXT, sort_order INTEGER,
    payload_json TEXT, content_hash TEXT
)');
$pdo->exec("INSERT INTO ipca_publishing_books VALUES (1, 'OM')");
$pdo->exec("INSERT INTO ipca_publishing_book_versions VALUES
    (10, 1, '6.0', NULL), (11, 1, '6.1', 10)");
$pdo->exec("INSERT INTO ipca_publishing_book_sections VALUES
    (100, 10, 'part_2_chapter_6', 'Technical', 10),
    (110, 11, 'part_2_chapter_6', 'Technical', 10)");
$payload = json_encode(array('html' => '<p>Unchanged medication text.</p>'), JSON_THROW_ON_ERROR);
$hash = hash('sha256', 'paragraph|' . $payload);
$pdo->prepare('INSERT INTO ipca_publishing_book_blocks VALUES (?,?,?,?,?,?,?,?,?)')->execute(array(
    1000, 10, 100, 'part_2_medication_body', 'OM-6_0-PART-2-BLOCK-024',
    'paragraph', 10, $payload, $hash,
));

$service = new ControlledPublishingRevisionService($pdo);
$annotated = $service->annotateChangeStatus(11, array(array(
    'id' => 1100,
    'book_version_id' => 11,
    'section_id' => 110,
    'block_key' => 'part_2_medication_body',
    'stable_anchor' => 'OM-6_1-PART-2-BLOCK-024',
    'block_type' => 'paragraph',
    'payload_json' => $payload,
    'content_hash' => $hash,
)));
if ((string)($annotated[0]['change_status'] ?? '') !== 'unchanged') {
    throw new RuntimeException('Version-independent block identity did not preserve unchanged status.');
}

$formatOnlyPayload = json_encode(
    array('html' => '<p>Unchanged medication text.</p><p><br></p>'),
    JSON_THROW_ON_ERROR
);
$formatOnly = $service->annotateChangeStatus(11, array(array(
    'block_key' => 'part_2_medication_body',
    'payload_json' => $formatOnlyPayload,
    'content_hash' => hash('sha256', 'paragraph|' . $formatOnlyPayload),
)));
if ((string)($formatOnly[0]['change_status'] ?? '') !== 'unchanged') {
    throw new RuntimeException('Empty-paragraph formatting noise was reported as a content change.');
}

$summariesMethod = new ReflectionMethod($service, 'humanChangeSummaries');
$changes = array(
    array(
        'block_key' => 'part_2_medication_body',
        'stable_anchor' => 'OM-6_1-PART-2-BLOCK-024',
        'section_key' => 'part_2_chapter_6',
        'section_sort_order' => 20,
        'section_title' => 'Technical',
        'block_type' => 'paragraph',
        'change_status' => 'modified',
        'payload_json' => json_encode(array(
            'html' => '<p>Permitted medication and protective equipment are clarified.</p>',
        ), JSON_THROW_ON_ERROR),
        'prior_payload_json' => json_encode(array(
            'html' => '<p>The former medication wording.</p>',
        ), JSON_THROW_ON_ERROR),
        'change_context' => array('reference' => '6.1.9', 'title' => 'Medication'),
    ),
    array(
        'block_key' => 'part_2_medication_note',
        'stable_anchor' => 'OM-6_1-PART-2-BLOCK-025',
        'section_key' => 'part_2_chapter_6',
        'section_sort_order' => 20,
        'section_title' => 'Technical',
        'block_type' => 'paragraph',
        'change_status' => 'new',
        'payload_json' => json_encode(array(
            'html' => '<p>Added restrictions for personnel administering medication.</p>',
        ), JSON_THROW_ON_ERROR),
        'change_context' => array('reference' => '6.1.9', 'title' => 'Medication'),
    ),
);
$summaries = $summariesMethod->invoke($service, $changes);
if (count($summaries) !== 1) {
    throw new RuntimeException('Low-level changes were not grouped by logical subsection.');
}
$text = (string)($summaries[0]['text'] ?? '');
foreach (array('Section 6.1.9', 'Medication', 'Changed', 'Added') as $required) {
    if (!str_contains($text, $required)) {
        throw new RuntimeException('Human change summary is missing: ' . $required);
    }
}
if (preg_match('/BLOCK-|stable anchor|source fragment|source_fragment_id/i', $text) === 1) {
    throw new RuntimeException('Human change summary exposes an internal identifier.');
}

$ordered = $summariesMethod->invoke($service, array(
    array(
        'block_key' => 'part_4_change',
        'section_key' => 'part_4_chapter_1',
        'section_sort_order' => 40,
        'section_title' => 'Training',
        'change_status' => 'new',
        'payload_json' => json_encode(array('html' => '<p>Part 4 update.</p>'), JSON_THROW_ON_ERROR),
        'change_context' => array(),
    ),
    array(
        'block_key' => 'part_1_change',
        'section_key' => 'main_content_chapter_1',
        'section_sort_order' => 10,
        'section_title' => 'General',
        'change_status' => 'new',
        'payload_json' => json_encode(array('html' => '<p>Part 1 update.</p>'), JSON_THROW_ON_ERROR),
        'change_context' => array(),
    ),
));
if (
    count($ordered) !== 2
    || (string)($ordered[0]['part'] ?? '') !== 'Part 1 — General'
    || (string)($ordered[1]['part'] ?? '') !== 'Part 4 — Training'
) {
    throw new RuntimeException('Generated change summaries are not ordered by manual part.');
}

$naturalOrder = $summariesMethod->invoke($service, array(
    array(
        'block_key' => 'chapter_10_change',
        'section_key' => 'part_1_chapter_10',
        'section_sort_order' => 20,
        'section_title' => 'Later chapter',
        'change_status' => 'new',
        'payload_json' => json_encode(array('html' => '<p>Chapter ten.</p>'), JSON_THROW_ON_ERROR),
        'change_context' => array('reference' => '10.1', 'title' => 'Later chapter'),
    ),
    array(
        'block_key' => 'chapter_2_change',
        'section_key' => 'part_1_chapter_2',
        'section_sort_order' => 10,
        'section_title' => 'Earlier chapter',
        'change_status' => 'new',
        'payload_json' => json_encode(array('html' => '<p>Chapter two.</p>'), JSON_THROW_ON_ERROR),
        'change_context' => array('reference' => '2.1', 'title' => 'Earlier chapter'),
    ),
));
if (
    count($naturalOrder) !== 2
    || !str_starts_with((string)$naturalOrder[0]['text'], 'Section 2.1')
    || !str_starts_with((string)$naturalOrder[1]['text'], 'Section 10.1')
) {
    throw new RuntimeException('Generated change bullets are not ordered by chapter and subsection.');
}

$longPrefix = str_repeat('Shared operational wording ', 8);
$focusedDiff = $summariesMethod->invoke($service, array(array(
    'block_key' => 'focused_wording_change',
    'section_key' => 'part_1_chapter_2',
    'section_sort_order' => 10,
    'section_title' => 'Responsibilities',
    'change_status' => 'modified',
    'payload_json' => json_encode(
        array('html' => '<p>' . $longPrefix . 'must be reported immediately.</p>'),
        JSON_THROW_ON_ERROR
    ),
    'prior_payload_json' => json_encode(
        array('html' => '<p>' . $longPrefix . 'should be reported promptly.</p>'),
        JSON_THROW_ON_ERROR
    ),
    'change_context' => array('reference' => '2.4', 'title' => 'Responsibilities'),
)));
$focusedText = (string)($focusedDiff[0]['text'] ?? '');
if (
    !str_contains($focusedText, 'Changed “should be reported promptly.”')
    || !str_contains($focusedText, 'to “must be reported immediately.”')
) {
    throw new RuntimeException('Modified content summary does not isolate the actual changed wording.');
}

$labelMethod = new ReflectionMethod($service, 'revisionDisplayLabel');
if ($labelMethod->invoke($service, 11) !== '6.1' || $labelMethod->invoke($service, 10) !== '6') {
    throw new RuntimeException('Revision change title labels are not human readable.');
}

$pdo->exec('ALTER TABLE ipca_publishing_book_blocks ADD COLUMN is_system_managed INTEGER DEFAULT 0');
$pdo->exec('ALTER TABLE ipca_publishing_book_blocks ADD COLUMN created_by INTEGER NULL');
$pdo->exec('ALTER TABLE ipca_publishing_book_blocks ADD COLUMN updated_by INTEGER NULL');
$pdo->exec("INSERT INTO ipca_publishing_book_sections VALUES
    (111, 11, 'highlights', 'Highlight of Changes', 5)");
$currentPayload = json_encode(
    array('html' => '<p>Clarified medication controls.</p>'),
    JSON_THROW_ON_ERROR
);
$pdo->prepare(
    'INSERT INTO ipca_publishing_book_blocks
      (id, book_version_id, section_id, block_key, stable_anchor, block_type, sort_order,
       payload_json, content_hash, is_system_managed)
     VALUES (?,?,?,?,?,?,?,?,?,0)'
)->execute(array(
    1100,
    11,
    110,
    'part_2_medication_body',
    'OM-6_1-PART-2-BLOCK-024',
    'paragraph',
    10,
    $currentPayload,
    hash('sha256', 'paragraph|' . $currentPayload),
));
$systemAnnotated = $service->annotateChangeStatus(11, array(array(
    'block_key' => 'generated_toc',
    'content_hash' => 'new-generated-hash',
    'is_system_managed' => 1,
)));
if ((string)($systemAnnotated[0]['change_status'] ?? '') !== 'unchanged') {
    throw new RuntimeException('Generated TOC/system content must not create revision-change noise.');
}
$generated = $service->regenerateHighlightsSection(11, 1);
if ((int)$generated['changes_count'] !== 1) {
    throw new RuntimeException('Highlight regeneration did not report the logical revision change.');
}
$generatedRows = $pdo->query(
    'SELECT block_type, payload_json
     FROM ipca_publishing_book_blocks
     WHERE section_id = 111 AND is_system_managed = 1
     ORDER BY sort_order'
)->fetchAll(PDO::FETCH_ASSOC);
$generatedPayloads = array_map(
    static fn(array $row): array => json_decode((string)$row['payload_json'], true) ?: array(),
    $generatedRows
);
if (
    (string)($generatedPayloads[0]['text'] ?? '') !== 'Revision 6.1 Changes'
    || !in_array('list', array_column($generatedRows, 'block_type'), true)
    || str_contains(json_encode($generatedPayloads), 'Auto-detected changes')
    || str_contains(json_encode($generatedPayloads), 'governed section change(s)')
) {
    throw new RuntimeException('Highlight regeneration did not create the revision title and bullet list.');
}

echo "Controlled publishing revision highlights: PASS\n";
