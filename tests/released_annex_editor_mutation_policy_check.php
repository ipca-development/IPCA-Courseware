<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/publishing/BooksManualsVersionEditPolicy.php';
require_once __DIR__ . '/../src/publishing/ControlledPublishingBlockService.php';
require_once __DIR__ . '/../src/publishing/ControlledPublishingBookStyleService.php';
require_once __DIR__ . '/../src/publishing/ControlledPublishingSectionLayoutService.php';

function annex_editor_policy_assert(string $label, bool $condition): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP: released Annex editor mutation policy (pdo_sqlite unavailable)\n";
    exit(0);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE ipca_publishing_books (
    id INTEGER PRIMARY KEY,
    book_type TEXT NOT NULL
)');
$pdo->exec('CREATE TABLE ipca_publishing_book_versions (
    id INTEGER PRIMARY KEY,
    book_id INTEGER NOT NULL,
    lifecycle_status TEXT NOT NULL,
    metadata_json TEXT NOT NULL DEFAULT "{}",
    updated_at TEXT
)');
$pdo->exec('CREATE TABLE ipca_publishing_book_template_sections (
    id INTEGER PRIMARY KEY,
    allow_author_blocks INTEGER NOT NULL DEFAULT 1
)');
$pdo->exec('CREATE TABLE ipca_publishing_book_sections (
    id INTEGER PRIMARY KEY,
    book_version_id INTEGER NOT NULL,
    parent_section_id INTEGER,
    template_section_id INTEGER,
    section_key TEXT NOT NULL,
    stable_anchor TEXT NOT NULL,
    metadata_json TEXT NOT NULL DEFAULT "{}",
    updated_at TEXT
)');
$pdo->exec('CREATE TABLE ipca_publishing_book_blocks (
    id INTEGER PRIMARY KEY,
    book_version_id INTEGER NOT NULL,
    section_id INTEGER NOT NULL,
    block_type TEXT NOT NULL,
    payload_json TEXT NOT NULL DEFAULT "{}",
    updated_at TEXT
)');

$pdo->exec("INSERT INTO ipca_publishing_books (id, book_type) VALUES
    (1, 'annex_book'),
    (2, 'manual')");
$pdo->exec("INSERT INTO ipca_publishing_book_versions
    (id, book_id, lifecycle_status, metadata_json) VALUES
    (10, 1, 'released', '{}'),
    (20, 2, 'released', '{}'),
    (21, 2, 'draft', '{}')");
$pdo->exec("INSERT INTO ipca_publishing_book_sections
    (id, book_version_id, parent_section_id, section_key, stable_anchor, metadata_json) VALUES
    (100, 10, 1, 'annexes_annex_01', 'ANNEX-01', '{}'),
    (200, 20, 1, 'part_1_chapter_1', 'MANUAL-RELEASED', '{}'),
    (210, 21, 1, 'part_1_chapter_1', 'MANUAL-DRAFT', '{}')");
$pdo->exec("INSERT INTO ipca_publishing_book_blocks
    (id, book_version_id, section_id, block_type, payload_json) VALUES
    (1000, 10, 100, 'paragraph', '{}'),
    (2000, 20, 200, 'paragraph', '{}'),
    (2100, 21, 210, 'paragraph', '{}')");

$releasedAnnex = array('lifecycle_status' => 'released', 'book_type' => 'annex_book');
$releasedManual = array('lifecycle_status' => 'released', 'book_type' => 'manual');
$draftManual = array('lifecycle_status' => 'draft', 'book_type' => 'manual');

annex_editor_policy_assert(
    'published Annex content remains editable',
    BooksManualsVersionEditPolicy::allowsMutation(
        $releasedAnnex,
        BooksManualsVersionEditPolicy::ANNEX_CONTENT
    )
);
annex_editor_policy_assert(
    'published Annex presentation remains editable',
    BooksManualsVersionEditPolicy::allowsMutation(
        $releasedAnnex,
        BooksManualsVersionEditPolicy::ANNEX_PRESENTATION
    )
);
annex_editor_policy_assert(
    'published Annex cannot inherit Manual structure mutations',
    !BooksManualsVersionEditPolicy::allowsMutation(
        $releasedAnnex,
        BooksManualsVersionEditPolicy::MANUAL_STRUCTURE
    )
);
annex_editor_policy_assert(
    'released Manual remains immutable',
    !BooksManualsVersionEditPolicy::allowsMutation(
        $releasedManual,
        BooksManualsVersionEditPolicy::ANNEX_CONTENT
    )
);
annex_editor_policy_assert(
    'draft Manual behavior remains editable',
    BooksManualsVersionEditPolicy::allowsMutation(
        $draftManual,
        BooksManualsVersionEditPolicy::ANNEX_CONTENT
    )
);

$blocks = new ControlledPublishingBlockService($pdo);
$requireEditableBlock = new ReflectionMethod($blocks, 'requireEditableBlock');
$annexBlockAllowed = true;
try {
    $requireEditableBlock->invoke($blocks, 1000);
} catch (Throwable) {
    $annexBlockAllowed = false;
}
annex_editor_policy_assert('published Annex font/block mutation is allowed', $annexBlockAllowed);

$releasedManualBlocked = false;
try {
    $requireEditableBlock->invoke($blocks, 2000);
} catch (RuntimeException $e) {
    $releasedManualBlocked = str_contains($e->getMessage(), 'Released versions cannot be edited');
}
annex_editor_policy_assert('released Manual font/block mutation is blocked', $releasedManualBlocked);

$draftManualAllowed = true;
try {
    $requireEditableBlock->invoke($blocks, 2100);
} catch (Throwable) {
    $draftManualAllowed = false;
}
annex_editor_policy_assert('draft Manual font/block mutation remains allowed', $draftManualAllowed);

$styles = new ControlledPublishingBookStyleService($pdo);
$annexStyleAllowed = true;
try {
    $styles->saveForVersion(10, array());
} catch (Throwable) {
    $annexStyleAllowed = false;
}
annex_editor_policy_assert('published Annex book styles are editable', $annexStyleAllowed);

$releasedManualStyleBlocked = false;
try {
    $styles->saveForVersion(20, array());
} catch (RuntimeException $e) {
    $releasedManualStyleBlocked = str_contains($e->getMessage(), 'Released versions cannot be edited');
}
annex_editor_policy_assert('released Manual book styles remain immutable', $releasedManualStyleBlocked);

$draftManualStyleAllowed = true;
try {
    $styles->saveForVersion(21, array());
} catch (Throwable) {
    $draftManualStyleAllowed = false;
}
annex_editor_policy_assert('draft Manual book-style behavior remains unchanged', $draftManualStyleAllowed);

$layouts = new ControlledPublishingSectionLayoutService($pdo);
$annexLayoutAllowed = true;
try {
    $layouts->saveLayout(10, 100, array('orientation' => 'landscape'));
} catch (Throwable) {
    $annexLayoutAllowed = false;
}
annex_editor_policy_assert('published Annex section layout is editable', $annexLayoutAllowed);

$releasedManualLayoutBlocked = false;
try {
    $layouts->saveLayout(20, 200, array('orientation' => 'landscape'));
} catch (RuntimeException $e) {
    $releasedManualLayoutBlocked = str_contains($e->getMessage(), 'Released versions cannot be edited');
}
annex_editor_policy_assert('released Manual section layout remains immutable', $releasedManualLayoutBlocked);

$draftManualLayoutAllowed = true;
try {
    $layouts->saveLayout(21, 210, array('orientation' => 'landscape'));
} catch (Throwable) {
    $draftManualLayoutAllowed = false;
}
annex_editor_policy_assert('draft Manual section-layout behavior remains unchanged', $draftManualLayoutAllowed);

echo "Released Annex editor mutation policy: PASS\n";
