<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/publishing/ControlledPublishingBlockService.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(
    'CREATE TABLE ipca_publishing_book_blocks (
        book_version_id INTEGER NOT NULL,
        block_key TEXT NOT NULL,
        stable_anchor TEXT NOT NULL
    )'
);
$pdo->exec(
    "INSERT INTO ipca_publishing_book_blocks VALUES
      (9, 'part_2_chapter_13_paragraph_001', 'LEGACY-UNRELATED-ANCHOR'),
      (9, 'part_2_chapter_13_heading_007', 'CURRENT-SECTION-BLOCK-007')"
);

$service = new ControlledPublishingBlockService($pdo);
$method = new ReflectionMethod($service, 'nextBlockSequence');
$sequence = $method->invoke(
    $service,
    9,
    'CURRENT-SECTION',
    'part_2_chapter_13',
    'paragraph'
);

if ($sequence !== 8) {
    throw new RuntimeException(
        'Block sequence allocation did not account for both stable anchors and existing block keys.'
    );
}

echo "Controlled publishing block-key allocation: PASS\n";
