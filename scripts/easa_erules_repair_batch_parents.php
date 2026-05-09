<?php
declare(strict_types=1);

/**
 * Re-run staging parent-link fixes on an existing batch (no XML re-parse).
 * Run after deploying updates to easa_erules_reparent_* logic.
 *
 *   php scripts/easa_erules_repair_batch_parents.php BATCH_ID
 *   php scripts/easa_erules_repair_batch_parents.php BATCH_ID --annex=b6_n65 --subpart=b6_n66
 *
 * Peer-lift diagnostics for one wrapper (stderr): easa_erules_reparent_rule_ref_toc_peer_lift_once():
 *   EASA_PEER_LIFT_DEBUG_UID=b6_n68 php scripts/easa_erules_repair_batch_parents.php 6
 *
 * With --annex (and optional --subpart): prints DB probes before/after repair and exits with
 * status 2 if the annex node still has zero children after repair (acceptance check for tree_children).
 */

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/easa_erules_xml_import.php';

/** @return array{annex: string, subpart: string} */
function easa_erules_repair_script_parse_opts(array $argv): array
{
    $annex = '';
    $subpart = '';
    foreach (array_slice($argv, 2) as $arg) {
        if (preg_match('/^--annex=(.+)$/', $arg, $m)) {
            $annex = trim($m[1]);

            continue;
        }
        if (preg_match('/^--subpart=(.+)$/', $arg, $m)) {
            $subpart = trim($m[1]);

            continue;
        }
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        exit(1);
    }

    return ['annex' => $annex, 'subpart' => $subpart];
}

function easa_erules_repair_script_count_children(PDO $pdo, int $batchId, string $parentUid): int
{
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM easa_erules_import_nodes_staging
         WHERE batch_id = ? AND parent_node_uid = ?'
    );
    $st->execute([$batchId, $parentUid]);

    return (int) $st->fetchColumn();
}

/** Fetch one staging row by batch + node_uid (probe annex/subpart existence). */
function easa_erules_repair_script_fetch_node(PDO $pdo, int $batchId, string $nodeUid): ?array
{
    $st = $pdo->prepare(
        'SELECT batch_id, node_uid, parent_node_uid, node_type, title
         FROM easa_erules_import_nodes_staging
         WHERE batch_id = ? AND node_uid = ?
         LIMIT 1'
    );
    $st->execute([$batchId, $nodeUid]);
    $r = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($r) ? $r : null;
}

/**
 * SUBPART A / B / C heading rows (Part-FCL style titles).
 *
 * @return list<array{node_uid: string, parent_node_uid: ?string, title: string}>
 */
function easa_erules_repair_script_fetch_subpart_abc(PDO $pdo, int $batchId): array
{
    $st = $pdo->prepare(
        'SELECT node_uid, parent_node_uid, title
         FROM easa_erules_import_nodes_staging
         WHERE batch_id = ?
           AND LOWER(node_type) = \'heading\'
           AND (
             TRIM(title) LIKE \'SUBPART A%\'
             OR TRIM(title) LIKE \'SUBPART B%\'
             OR TRIM(title) LIKE \'SUBPART C%\'
           )
         ORDER BY sort_order ASC, id ASC'
    );
    $st->execute([$batchId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_map(static function (array $r): array {
        return [
            'node_uid' => trim((string) ($r['node_uid'] ?? '')),
            'parent_node_uid' => isset($r['parent_node_uid']) && trim((string) $r['parent_node_uid']) !== ''
                ? trim((string) $r['parent_node_uid'])
                : null,
            'title' => (string) ($r['title'] ?? ''),
        ];
    }, $rows);
}

/**
 * @param 'before'|'after' $phase
 */
function easa_erules_repair_script_report_probe(
    PDO $pdo,
    int $batchId,
    string $phase,
    ?string $annexUid,
    ?string $subpartUid
): void {
    fwrite(STDOUT, "\n=== Probe {$phase} repair ===\n");
    fwrite(STDOUT, "batch_id={$batchId}\n");

    if ($annexUid !== null && $annexUid !== '') {
        $row = easa_erules_repair_script_fetch_node($pdo, $batchId, $annexUid);
        if ($row === null) {
            fwrite(STDOUT, "WARN: no staging row for node_uid={$annexUid} in batch_id={$batchId} (wrong uid or database?).\n");
        } else {
            $t = mb_substr((string) ($row['title'] ?? ''), 0, 160);
            fwrite(
                STDOUT,
                "Annex row: {$row['node_uid']} batch_id={$row['batch_id']} parent={$row['parent_node_uid']} type={$row['node_type']} title="
                . str_replace(["\r", "\n"], ' ', $t) . "\n"
            );
        }
        $n = easa_erules_repair_script_count_children($pdo, $batchId, $annexUid);
        fwrite(STDOUT, "Children of annex {$annexUid} (parent_node_uid = {$annexUid}): {$n}\n");
    } else {
        fwrite(STDOUT, "(No --annex=… set: skipping annex child count.)\n");
    }

    if ($subpartUid !== null && $subpartUid !== '') {
        $nSub = easa_erules_repair_script_count_children($pdo, $batchId, $subpartUid);
        fwrite(STDOUT, "Children of subpart sample {$subpartUid} (parent_node_uid = {$subpartUid}): {$nSub}\n");
    } else {
        fwrite(STDOUT, "(No --subpart=… set: skipping subpart child count.)\n");
    }

    $abc = easa_erules_repair_script_fetch_subpart_abc($pdo, $batchId);
    fwrite(STDOUT, 'SUBPART A/B/C heading rows (title match): ' . count($abc) . "\n");
    foreach ($abc as $r) {
        $pu = $r['parent_node_uid'] ?? 'NULL';
        $t = mb_substr($r['title'], 0, 100);
        fwrite(STDOUT, "  {$r['node_uid']}  parent={$pu}  title=" . str_replace(["\r", "\n"], ' ', $t) . "\n");
    }
}

$id = (int) ($argv[1] ?? 0);
$opts = easa_erules_repair_script_parse_opts($argv);
$annexUid = $opts['annex'];
$subpartUid = $opts['subpart'];

if ($id <= 0) {
    fwrite(
        STDERR,
        "Usage: php easa_erules_repair_batch_parents.php BATCH_ID [--annex=NODE_UID] [--subpart=NODE_UID]\n"
        . "Example: php easa_erules_repair_batch_parents.php 6 --annex=b6_n65 --subpart=b6_n66\n"
    );
    exit(1);
}

if (!easa_erules_staging_tables_ok($pdo)) {
    fwrite(STDERR, "Apply scripts/sql/resource_library_easa_erules_staging.sql first.\n");
    exit(1);
}

@ini_set('memory_limit', '512M');
@set_time_limit(0);

$annexForProbe = $annexUid !== '' ? $annexUid : null;
$subpartForProbe = $subpartUid !== '' ? $subpartUid : null;

if ($annexForProbe !== null || $subpartForProbe !== null) {
    easa_erules_repair_script_report_probe($pdo, $id, 'before', $annexForProbe, $subpartForProbe);
}

try {
    easa_erules_repair_batch_tree_parents($pdo, $id);
    fwrite(STDOUT, "\nRepaired parent links for batch {$id} (structural → annex/toc-lift → annex/subpart/appendix → outline lift → FCL promote → rule-ref peer lift → annex appendix-bundle toc attach).\n");
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($annexForProbe !== null || $subpartForProbe !== null) {
    easa_erules_repair_script_report_probe($pdo, $id, 'after', $annexForProbe, $subpartForProbe);
}

if ($annexForProbe !== null && $annexForProbe !== '') {
    $afterChildren = easa_erules_repair_script_count_children($pdo, $id, $annexForProbe);
    if ($afterChildren === 0) {
        fwrite(
            STDERR,
            "\nFAIL: annex {$annexForProbe} still has 0 children after repair. "
            . "tree_children will return nodes=[] for parent_uid={$annexForProbe}. "
            . "Check batch_id, DB connection, titles (ANNEX/SUBPART first lines), and deployed easa_erules_xml_import.php.\n"
        );
        exit(2);
    }
    fwrite(STDOUT, "\nOK: annex {$annexForProbe} has {$afterChildren} direct children (expect SUBPARTs / appendices under ANNEX).\n");
}

exit(0);
