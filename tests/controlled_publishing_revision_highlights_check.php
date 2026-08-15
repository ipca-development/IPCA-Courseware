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

$summariesMethod = new ReflectionMethod($service, 'humanChangeSummaries');
$changes = array(
    array(
        'block_key' => 'part_2_medication_body',
        'stable_anchor' => 'OM-6_1-PART-2-BLOCK-024',
        'section_key' => 'part_2_chapter_6',
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
foreach (array('Part 2 — Technical', '§6.1.9', 'Medication', 'Updated', 'Added') as $required) {
    if (!str_contains($text, $required)) {
        throw new RuntimeException('Human change summary is missing: ' . $required);
    }
}
if (preg_match('/BLOCK-|stable anchor|source fragment|source_fragment_id/i', $text) === 1) {
    throw new RuntimeException('Human change summary exposes an internal identifier.');
}

echo "Controlled publishing revision highlights: PASS\n";
