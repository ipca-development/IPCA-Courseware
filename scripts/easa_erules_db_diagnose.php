<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/db.php';

function out(string $label, mixed $data): void
{
    echo "\n=== {$label} ===\n";
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}

try {
    $pdo = cw_db();

    $stagingCols = $pdo->query("
        SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'easa_erules_import_nodes_staging'
        ORDER BY ORDINAL_POSITION
    ")->fetchAll(PDO::FETCH_ASSOC);

    $batchCols = $pdo->query("
        SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'easa_erules_import_batches'
        ORDER BY ORDINAL_POSITION
    ")->fetchAll(PDO::FETCH_ASSOC);

    $batchStatus = $pdo->query("
        SELECT id, status, rows_detected, parse_phase, parse_rows_so_far,
               parse_started_at, parse_finished_at, updated_at,
               storage_relpath, error_message
        FROM easa_erules_import_batches
        ORDER BY id DESC
        LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);

    $qualityByType = $pdo->query("
        SELECT
            batch_id,
            node_type,
            COUNT(*) AS rows_total,
            SUM(CASE WHEN CHAR_LENGTH(COALESCE(plain_text, '')) > 0 THEN 1 ELSE 0 END) AS rows_plain_nonempty,
            SUM(CASE WHEN CHAR_LENGTH(COALESCE(canonical_text, '')) > 0 THEN 1 ELSE 0 END) AS rows_canonical_nonempty,
            SUM(CASE WHEN CHAR_LENGTH(COALESCE(xml_fragment, '')) > 0 THEN 1 ELSE 0 END) AS rows_fragment_nonempty,
            AVG(CHAR_LENGTH(COALESCE(xml_fragment, ''))) AS avg_fragment_len
        FROM easa_erules_import_nodes_staging
        GROUP BY batch_id, node_type
        ORDER BY batch_id, node_type
    ")->fetchAll(PDO::FETCH_ASSOC);

    $qualityByBatch = $pdo->query("
        SELECT
            batch_id,
            COUNT(*) AS rows_total,
            SUM(CASE WHEN CHAR_LENGTH(COALESCE(plain_text, '')) = 0 THEN 1 ELSE 0 END) AS rows_plain_empty,
            SUM(CASE WHEN CHAR_LENGTH(COALESCE(canonical_text, '')) = 0 THEN 1 ELSE 0 END) AS rows_canonical_empty,
            SUM(CASE WHEN CHAR_LENGTH(COALESCE(xml_fragment, '')) = 0 THEN 1 ELSE 0 END) AS rows_fragment_empty,
            SUM(CASE WHEN CHAR_LENGTH(COALESCE(plain_text, '')) = 0 AND CHAR_LENGTH(COALESCE(xml_fragment, '')) > 0 THEN 1 ELSE 0 END) AS rows_empty_plain_with_fragment
        FROM easa_erules_import_nodes_staging
        GROUP BY batch_id
        ORDER BY batch_id
    ")->fetchAll(PDO::FETCH_ASSOC);

    out('staging_columns', $stagingCols);
    out('batch_columns', $batchCols);
    out('batch_status', $batchStatus);
    out('staging_quality_by_type', $qualityByType);
    out('staging_quality_by_batch', $qualityByBatch);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
