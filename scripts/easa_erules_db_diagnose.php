<?php
declare(strict_types=1);

/**
 * CLI DB diagnostics. Loads project root `.env` when CW_DB_* are unset (same pattern as verify_theory_progression_db.php).
 */
$root = dirname(__DIR__);

$loadDotenv = static function (string $path): void {
    if (!is_readable($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $m)) {
            continue;
        }
        $key = $m[1];
        $val = $m[2];
        if ($val !== '' && (($val[0] === '"' && str_ends_with($val, '"')) || ($val[0] === "'" && str_ends_with($val, "'")))) {
            $val = substr($val, 1, -1);
        }
        if (getenv($key) !== false) {
            continue;
        }
        putenv($key . '=' . $val);
        $_ENV[$key] = $val;
    }
};

if (!getenv('CW_DB_HOST')) {
    $loadDotenv($root . '/.env');
}

require_once $root . '/src/db.php';

function out(string $label, mixed $data): void
{
    echo "\n=== {$label} ===\n";
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}

/** Targeted inspect: php scripts/easa_erules_db_diagnose.php --inspect <batch_id> <node_uid> */
if (isset($argv[1], $argv[2], $argv[3]) && $argv[1] === '--inspect') {
    $batchId = (int) $argv[2];
    $nodeUid = trim((string) $argv[3]);
    if ($batchId <= 0 || $nodeUid === '') {
        fwrite(STDERR, "Usage: php easa_erules_db_diagnose.php --inspect <batch_id> <node_uid>\n");
        exit(1);
    }
    require_once __DIR__ . '/../src/easa_erules_xml_import.php';
    try {
        $pdo = cw_db();
        $hasCanonSum = function_exists('easa_erules_staging_has_canonical_column') && easa_erules_staging_has_canonical_column($pdo);
        $sql = $hasCanonSum ? <<<'SQL'
SELECT id, batch_id, node_uid, parent_node_uid, node_type, depth, sort_order, source_erules_id, title, source_title,
       CHAR_LENGTH(COALESCE(plain_text,'')) AS plain_len,
       CHAR_LENGTH(COALESCE(canonical_text,'')) AS canonical_len,
       CHAR_LENGTH(COALESCE(xml_fragment,'')) AS frag_len
FROM easa_erules_import_nodes_staging
WHERE batch_id = ?
  AND (node_uid = ? OR parent_node_uid = ?)
ORDER BY depth, sort_order, id
LIMIT 100
SQL
            : <<<'SQL'
SELECT id, batch_id, node_uid, parent_node_uid, node_type, depth, sort_order, source_erules_id, title, source_title,
       CHAR_LENGTH(COALESCE(plain_text,'')) AS plain_len,
       0 AS canonical_len,
       CHAR_LENGTH(COALESCE(xml_fragment,'')) AS frag_len
FROM easa_erules_import_nodes_staging
WHERE batch_id = ?
  AND (node_uid = ? OR parent_node_uid = ?)
ORDER BY depth, sort_order, id
LIMIT 100
SQL;
        $st = $pdo->prepare($sql);
        $st->execute([$batchId, $nodeUid, $nodeUid]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $directChildCount = 0;
        foreach ($rows as $r) {
            if (($r['parent_node_uid'] ?? '') === $nodeUid && ($r['node_uid'] ?? '') !== '') {
                ++$directChildCount;
            }
        }

        $detailCols = [
            'batch_id', 'node_uid', 'parent_node_uid', 'node_type', 'depth', 'sort_order',
            'source_erules_id', 'title', 'source_title', 'breadcrumb', 'path',
        ];
        if (function_exists('easa_erules_staging_has_canonical_column') && easa_erules_staging_has_canonical_column($pdo)) {
            $detailCols[] = 'canonical_text';
        }
        $detailCols[] = 'plain_text';
        $detailCols[] = 'xml_fragment';

        $detailSql = 'SELECT ' . implode(', ', $detailCols) . '
            FROM easa_erules_import_nodes_staging
            WHERE batch_id = ? AND node_uid = ?
            LIMIT 1';
        $dst = $pdo->prepare($detailSql);
        $dst->execute([$batchId, $nodeUid]);
        $detailRaw = $dst->fetch(PDO::FETCH_ASSOC);

        $sanitizedDetail = null;
        if (is_array($detailRaw)) {
            $plainTrim = trim((string) ($detailRaw['plain_text'] ?? ''));
            $composed = $plainTrim === '' ? easa_erules_aggregate_descendant_plain_text($pdo, $batchId, $nodeUid, 0) : '';
            $sanitizedDetail = [
                'node_uid' => $detailRaw['node_uid'] ?? null,
                'title' => $detailRaw['title'] ?? null,
                'source_erules_id' => $detailRaw['source_erules_id'] ?? null,
                'plain_len' => strlen((string) ($detailRaw['plain_text'] ?? '')),
                'canonical_len' => isset($detailRaw['canonical_text']) ? strlen((string) $detailRaw['canonical_text']) : null,
                'xml_fragment_len' => strlen((string) ($detailRaw['xml_fragment'] ?? '')),
                'aggregate_descendant_plain_len' => strlen(trim($composed)),
                'aggregate_preview_200' => substr(trim($composed), 0, 200),
            ];
        }

        $treeChildren = [];
        try {
            $treeChildren = easa_erules_tree_children_response_nodes($pdo, $batchId, $nodeUid);
        } catch (Throwable $e) {
            $treeChildren = ['_error' => $e->getMessage()];
        }

        $srcAbs = easa_erules_batch_source_xml_absolute_path($pdo, $batchId);
        $batchRow = null;
        try {
            $bst = $pdo->prepare('SELECT id, storage_relpath, status FROM easa_erules_import_batches WHERE id = ? LIMIT 1');
            $bst->execute([$batchId]);
            $batchRow = $bst->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable) {
            $batchRow = null;
        }

        out('inspect_meta', [
            'batch_id' => $batchId,
            'node_uid' => $nodeUid,
            'staging_direct_child_rows_in_sample' => $directChildCount,
            'batch_row' => $batchRow,
            'resolved_source_xml_absolute_path' => $srcAbs,
            'source_xml_exists' => $srcAbs !== null && is_file($srcAbs),
        ]);
        out('sql_parent_and_children_summary', $rows);
        out('node_detail_resolution_hints', $sanitizedDetail);
        out('tree_children_semantic_nodes', $treeChildren);
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
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
