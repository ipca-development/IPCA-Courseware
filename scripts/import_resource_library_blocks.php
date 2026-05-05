<?php
declare(strict_types=1);

/**
 * CLI: import source.json for an edition into resource_library_blocks (AI retrieval).
 *
 * Usage:
 *   php scripts/import_resource_library_blocks.php EDITION_ID
 *
 * Requires: DB env as bootstrap, migration scripts/sql/resource_library_blocks.sql applied.
 */

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/resource_library_ingest.php';

$id = (int)($argv[1] ?? 0);
if ($id <= 0) {
    fwrite(STDERR, "Usage: php import_resource_library_blocks.php EDITION_ID\n");
    exit(1);
}

try {
    $stats = rl_ingest_blocks_from_source_file($pdo, $id);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, "Imported {$stats['imported']} blocks across {$stats['chapter_count']} chapters for edition {$id}.\n");
