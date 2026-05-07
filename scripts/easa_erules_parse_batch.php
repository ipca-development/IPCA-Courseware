<?php
declare(strict_types=1);

/**
 * Parse uploaded EASA eRules XML into easa_erules_import_nodes_staging for a batch id (CLI; synchronous).
 *
 *   php scripts/easa_erules_parse_batch.php BATCH_ID
 *
 * Requires: scripts/sql/resource_library_easa_erules.sql and resource_library_easa_erules_staging.sql
 * Optional: scripts/sql/resource_library_easa_erules_staging_structured_blocks.sql adds structured_blocks_json for the UI renderer.
 */

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/easa_erules_xml_import.php';

$id = (int) ($argv[1] ?? 0);
if ($id <= 0) {
    fwrite(STDERR, "Usage: php easa_erules_parse_batch.php BATCH_ID\n");
    exit(1);
}

if (!easa_erules_staging_tables_ok($pdo)) {
    fwrite(STDERR, "Apply scripts/sql/resource_library_easa_erules_staging.sql first.\n");
    exit(1);
}

@ini_set('memory_limit', '768M');
@set_time_limit(0);

$pdo->prepare('UPDATE easa_erules_import_batches SET status = \'staging\', error_message = NULL WHERE id = ?')->execute([$id]);

try {
    $result = easa_erules_import_batch_xml_to_staging($pdo, $id);
    easa_erules_import_finalize_success($pdo, $id, (int) $result['imported'], $result['publication_meta'] ?? null);
    fwrite(STDOUT, "Imported {$result['imported']} staging rows for batch {$id}.\n");
    exit(0);
} catch (Throwable $e) {
    easa_erules_import_finalize_failure($pdo, $id, $e->getMessage());
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
