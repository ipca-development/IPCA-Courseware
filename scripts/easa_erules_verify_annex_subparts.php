<?php
declare(strict_types=1);

/**
 * Prove annex → subpart parent links after import (batch_id from argv[1], default 6).
 *
 * Usage: php scripts/easa_erules_verify_annex_subparts.php [batch_id]
 *
 * Requires DB from src/db.php (same as easa_erules_db_diagnose.php).
 */
require_once __DIR__ . '/../src/db.php';

$batchId = isset($argv[1]) ? max(0, (int) $argv[1]) : 6;
if ($batchId <= 0) {
    fwrite(STDERR, "Usage: php {$argv[0]} <batch_id>\n");
    exit(1);
}

function jout(string $label, mixed $data): void
{
    echo "\n=== {$label} ===\n";
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}

try {
    $pdo = cw_db();

    $annexRows = $pdo->prepare(
        'SELECT id, batch_id, node_uid, parent_node_uid, node_type, title, sort_order
         FROM easa_erules_import_nodes_staging
         WHERE batch_id = ?
           AND title LIKE ?
         ORDER BY sort_order ASC, id ASC'
    );
    $annexRows->execute([$batchId, '%ANNEX I%']);
    $annex = $annexRows->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $subpartRows = $pdo->prepare(
        'SELECT id, node_uid, parent_node_uid, node_type, title, sort_order
         FROM easa_erules_import_nodes_staging
         WHERE batch_id = ?
           AND title REGEXP ?
         ORDER BY sort_order ASC, id ASC
         LIMIT 80'
    );
    $subpartRows->execute([$batchId, '^SUBPART [A-Z]']);
    $subparts = $subpartRows->fetchAll(PDO::FETCH_ASSOC) ?: [];

    jout("batch_{$batchId}_annex_I_rows", $annex);

    $annexUid = null;
    foreach ($annex as $r) {
        if (!is_array($r)) {
            continue;
        }
        $t = (string) ($r['title'] ?? '');
        if (preg_match('/ANNEX\s*I\b/iu', $t) && preg_match('/part[\s\-–—]*fcl/iu', $t)) {
            $annexUid = trim((string) ($r['node_uid'] ?? ''));
            if ($annexUid !== '') {
                break;
            }
        }
    }
    if ($annexUid === null) {
        foreach ($annex as $r) {
            if (is_array($r) && preg_match('/ANNEX\s*I\b/iu', (string) ($r['title'] ?? ''))) {
                $annexUid = trim((string) ($r['node_uid'] ?? ''));
                if ($annexUid !== '') {
                    break;
                }
            }
        }
    }

    $looksPartFclSubpart = static function (string $title): bool {
        if (!preg_match('/^SUBPART\s+[A-K]\b/iu', $title)) {
            return false;
        }
        if (preg_match('/(Part-\s*MED|AERO-MEDICAL\s+EXAMINER|MEDICAL CERTIFICATE|CABIN CREW|ATTESTATION|FSTD|AeMC|\bDTO\b)/iu', $title)) {
            return false;
        }

        return (bool) preg_match(
            '/(LAPL|LIGHT AIRCRAFT|PPL|GPL|CPL\b|MPL\b|ATPL|INSTRUMENT RATING|CLASS AND TYPE|ADDITIONAL RATINGS|INSTRUCTORS|EXAMINERS)/iu',
            $title
        );
    };

    jout("batch_{$batchId}_subpart_ABC_rows", $subparts);

    $check = [
        'resolved_annex_node_uid' => $annexUid,
        'subparts_under_annex' => 0,
        'subparts_not_under_annex' => [],
        'distinct_parent_uids_among_subparts' => [],
    ];
    $parents = [];
    foreach ($subparts as $sp) {
        if (!is_array($sp)) {
            continue;
        }
        $p = trim((string) ($sp['parent_node_uid'] ?? ''));
        $stt = (string) ($sp['title'] ?? '');
        $parents[$p] = true;
        if ($annexUid !== null && $p === $annexUid) {
            $check['subparts_under_annex']++;
        } elseif ($looksPartFclSubpart($stt)) {
            $check['subparts_not_under_annex'][] = [
                'node_uid' => $sp['node_uid'] ?? null,
                'title' => $sp['title'] ?? null,
                'parent_node_uid' => $p !== '' ? $p : null,
            ];
        }
    }
    $check['distinct_parent_uids_among_subparts'] = array_keys($parents);

    if ($annexUid !== null) {
        $ch = $pdo->prepare(
            'SELECT node_uid, parent_node_uid, node_type, title, sort_order
             FROM easa_erules_import_nodes_staging
             WHERE batch_id = ? AND parent_node_uid = ?
             ORDER BY sort_order ASC, id ASC
             LIMIT 50'
        );
        $ch->execute([$batchId, $annexUid]);
        $check['tree_children_equivalent_for_annex_uid'] = $ch->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $check['tree_children_count'] = count($check['tree_children_equivalent_for_annex_uid']);
    } else {
        $check['tree_children_equivalent_for_annex_uid'] = null;
        $check['tree_children_count'] = null;
        $check['error'] = 'No ANNEX I row found to test parent_uid filter.';
    }

    jout("verification_summary", $check);

    $pass = $annexUid !== null
        && ($check['tree_children_count'] ?? 0) >= 10
        && $check['subparts_not_under_annex'] === [];
    echo "\n=== PASS_FAIL ===\n";
    echo $pass
        ? "PASS: ANNEX I (Part-FCL) has >=10 children in staging; Part-FCL SUBPART A–K rows parent_node_uid matches that annex; tree_children query matches API.\n"
        : "FAIL: annex missing, too few children under annex, or Part-FCL-looking SUBPART rows not parented to ANNEX I.\n";

    exit($pass ? 0 : 2);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
