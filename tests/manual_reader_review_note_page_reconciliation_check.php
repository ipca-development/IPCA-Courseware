<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/publishing/ControlledPublishingReaderAnnotationService.php';

function review_page_assert(string $label, bool $condition): void
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
    'CREATE TABLE ipca_manual_reader_review_threads (
        id INTEGER PRIMARY KEY,
        book_version_id INTEGER NOT NULL,
        page_number_snapshot INTEGER NOT NULL,
        selected_text TEXT NOT NULL,
        source_fragment_id TEXT NULL,
        stable_anchor TEXT NULL
    )'
);
$insert = $pdo->prepare(
    'INSERT INTO ipca_manual_reader_review_threads
        (id, book_version_id, page_number_snapshot, selected_text,
         source_fragment_id, stable_anchor)
     VALUES (?,?,?,?,?,?)'
);
$insert->execute(array(1, 8, 52, 'Older anchored note', 'fragment-older', 'anchor-older'));
$insert->execute(array(2, 8, 52, 'Unique unanchored reviewer text', null, null));
$insert->execute(array(3, 8, 52, 'Repeated reviewer text', null, null));
$insert->execute(array(4, 9, 7, 'Other version', 'fragment-other', 'anchor-other'));

$pages = array(
    array(
        'page_number' => 54,
        'page_html' => '<article data-source-fragment-id="fragment-older">'
            . '<p>Older anchored note</p><p>Repeated reviewer text</p></article>',
    ),
    array(
        'page_number' => 55,
        'page_html' => '<article><p>Unique unanchored reviewer text</p>'
            . '<p>Repeated reviewer text</p></article>',
    ),
);

$updated = (new ControlledPublishingReaderAnnotationService($pdo))
    ->reconcileReviewThreadPageSnapshots(8, $pages);
$rows = $pdo->query(
    'SELECT id, page_number_snapshot
       FROM ipca_manual_reader_review_threads
      ORDER BY id'
)->fetchAll(PDO::FETCH_KEY_PAIR);

review_page_assert('anchored historical note follows its authoritative fragment', (int)$rows[1] === 54);
review_page_assert('unique unanchored note follows its authoritative text', (int)$rows[2] === 55);
review_page_assert('ambiguous text does not move to an arbitrary page', (int)$rows[3] === 52);
review_page_assert('another manual version remains untouched', (int)$rows[4] === 7);
review_page_assert('only resolvable stale snapshots are updated', $updated === 2);

echo "Manual reader reviewer-note page reconciliation: PASS\n";
