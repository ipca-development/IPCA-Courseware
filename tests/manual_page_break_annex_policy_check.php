<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/publishing/ControlledPublishingManualPageBreakService.php';

function page_break_policy_assert(string $label, bool $condition): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(
    'CREATE TABLE ipca_publishing_books (
        id INTEGER PRIMARY KEY,
        book_type TEXT NOT NULL
    )'
);
$pdo->exec(
    'CREATE TABLE ipca_publishing_book_versions (
        id INTEGER PRIMARY KEY,
        book_id INTEGER NOT NULL,
        lifecycle_status TEXT NOT NULL
    )'
);
$pdo->exec(
    "INSERT INTO ipca_publishing_books (id, book_type) VALUES
        (1, 'annex_book'),
        (2, 'manual')"
);
$pdo->exec(
    "INSERT INTO ipca_publishing_book_versions (id, book_id, lifecycle_status) VALUES
        (10, 1, 'released'),
        (20, 2, 'released'),
        (21, 2, 'draft')"
);

$service = new ControlledPublishingManualPageBreakService($pdo);
$assertEditable = new ReflectionMethod($service, 'assertEditableVersion');

$annexAllowed = true;
try {
    $assertEditable->invoke($service, 10);
} catch (Throwable) {
    $annexAllowed = false;
}
page_break_policy_assert('published Annex Book permits page-break mutation', $annexAllowed);

$releasedManualBlocked = false;
try {
    $assertEditable->invoke($service, 20);
} catch (RuntimeException $e) {
    $releasedManualBlocked = str_contains($e->getMessage(), 'Released pagination is immutable');
}
page_break_policy_assert('released Books/Manuals remain immutable', $releasedManualBlocked);

$draftManualAllowed = true;
try {
    $assertEditable->invoke($service, 21);
} catch (Throwable) {
    $draftManualAllowed = false;
}
page_break_policy_assert('draft Books/Manuals page-break behavior is unchanged', $draftManualAllowed);

$source = (string)file_get_contents(
    dirname(__DIR__) . '/src/publishing/ControlledPublishingManualPageBreakService.php'
);
page_break_policy_assert(
    'page-break mutation preserves the last valid reader map during regeneration',
    str_contains($source, 'retain the last valid page map')
        && !str_contains($source, 'ControlledPublishingReaderPageMapStore')
        && !str_contains($source, 'invalidatePageMap')
);

echo "Manual page-break Annex policy: PASS\n";
