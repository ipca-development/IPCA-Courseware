<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/publishing/ControlledPublishingReaderPageMapStore.php';

function mixed_page_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(
    'CREATE TABLE ipca_publishing_reader_page_maps (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        book_version_id INTEGER NOT NULL,
        layout_profile TEXT NOT NULL,
        page_number INTEGER NOT NULL,
        section_id INTEGER NULL,
        stable_anchor TEXT NULL,
        page_type TEXT NOT NULL,
        is_cover INTEGER NOT NULL,
        is_section_start INTEGER NOT NULL,
        is_major_section_start INTEGER NOT NULL,
        page_html TEXT NOT NULL,
        thumbnail_html TEXT NULL,
        metadata_json TEXT NOT NULL
    )'
);

$insert = $pdo->prepare(
    'INSERT INTO ipca_publishing_reader_page_maps (
        book_version_id, layout_profile, page_number, section_id, stable_anchor,
        page_type, is_cover, is_section_start, is_major_section_start,
        page_html, thumbnail_html, metadata_json
    ) VALUES (1, ?, ?, ?, ?, ?, 0, 0, 0, ?, NULL, ?)'
);
$pages = array(
    array(89, 9, 'section-9', array(
        array('source_fragment_id' => 'section-9/block-a/root', 'section_id' => 9),
        array('source_fragment_id' => 'section-35/block-b/root', 'section_id' => 35),
    )),
    array(90, 35, 'section-35', array(
        array('source_fragment_id' => 'section-35/block-c/root', 'section_id' => 35),
    )),
    array(91, 36, 'section-36', array(
        array('source_fragment_id' => 'section-35/block-d/root', 'section_id' => 35),
        array('source_fragment_id' => 'section-36/block-e/root', 'section_id' => 36),
    )),
    array(92, 36, 'section-36', array(
        array('source_fragment_id' => 'section-36/block-f/root', 'section_id' => 36),
    )),
    array(93, 36, 'section-36', array(
        array(
            'source_fragment_id' => 'section-35/repeated-header/root',
            'section_id' => 35,
            'presentation_copy' => true,
        ),
        array('source_fragment_id' => 'section-36/block-g/root', 'section_id' => 36),
    )),
);
foreach ($pages as [$pageNumber, $primarySectionId, $anchor, $coverage]) {
    $insert->execute(array(
        'LETTER_READER_v1',
        $pageNumber,
        $primarySectionId,
        $anchor,
        'content',
        '<div>Page ' . $pageNumber . '</div>',
        json_encode(array('coverage' => $coverage), JSON_UNESCAPED_SLASHES),
    ));
}

$store = new ControlledPublishingReaderPageMapStore($pdo);
$section35 = $store->loadStoredPages(1, 'LETTER_READER_v1', 35);
mixed_page_assert(
    array_column($section35, 'page_number') === array(89, 90, 91),
    'Section-scoped retrieval must include every page whose coverage contains section 35.'
);
mixed_page_assert(
    (int)$section35[0]['section_id'] === 9,
    'A mixed page must retain its primary section while being included for a covered section.'
);
mixed_page_assert(
    !in_array(93, array_column($section35, 'page_number'), true),
    'Presentation-only copies must not create section membership.'
);

$index = $store->sectionPageIndex(1, 'LETTER_READER_v1');
mixed_page_assert(
    ($index[35] ?? null) === 89,
    'Section page index must begin at the first page containing section coverage.'
);
mixed_page_assert(
    ($index[36] ?? null) === 91,
    'Primary and coverage section membership must both contribute to the section index.'
);

echo "Controlled publishing mixed-section page map: PASS\n";
