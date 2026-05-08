<?php
declare(strict_types=1);

/**
 * Re-run staging parent-link fixes on an existing batch (no XML re-parse).
 * Run after deploying updates to easa_erules_reparent_* logic.
 *
 *   php scripts/easa_erules_repair_batch_parents.php BATCH_ID
 */

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/easa_erules_xml_import.php';

$id = (int) ($argv[1] ?? 0);
if ($id <= 0) {
    fwrite(STDERR, "Usage: php easa_erules_repair_batch_parents.php BATCH_ID\n");
    exit(1);
}

if (!easa_erules_staging_tables_ok($pdo)) {
    fwrite(STDERR, "Apply scripts/sql/resource_library_easa_erules_staging.sql first.\n");
    exit(1);
}

@ini_set('memory_limit', '512M');
@set_time_limit(0);

try {
    easa_erules_repair_batch_tree_parents($pdo, $id);
    fwrite(STDOUT, "Repaired parent links for batch {$id}.\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
