<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/easa_erules_storage.php';
require_once __DIR__ . '/../src/easa_erules_xml_import.php';

function argValue(array $argv, string $name): ?string
{
    foreach ($argv as $a) {
        if (str_starts_with($a, $name . '=')) {
            return substr($a, strlen($name) + 1);
        }
    }

    return null;
}

function jprint(string $label, mixed $data): void
{
    echo "\n=== {$label} ===\n";
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}

$batchId = (int) (argValue($argv, '--batch') ?? 0);
$nodeUid = trim((string) (argValue($argv, '--node') ?? ''));
$erulesArg = trim((string) (argValue($argv, '--erules') ?? ''));

if ($batchId <= 0 || $nodeUid === '') {
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php scripts/easa_erules_db_probe_node.php --batch=1 --node=b1_n175 [--erules=ERULES-...]\n");
    exit(1);
}

try {
    $pdo = cw_db();

    $st = $pdo->prepare("
        SELECT id,batch_id,node_uid,parent_node_uid,node_type,source_erules_id,title,breadcrumb,path,
               CHAR_LENGTH(COALESCE(plain_text,'')) AS plain_len,
               CHAR_LENGTH(COALESCE(canonical_text,'')) AS canonical_len,
               CHAR_LENGTH(COALESCE(xml_fragment,'')) AS fragment_len,
               LEFT(COALESCE(plain_text,''),260) AS plain_head,
               LEFT(COALESCE(canonical_text,''),260) AS canonical_head,
               LEFT(COALESCE(xml_fragment,''),260) AS fragment_head,
               RIGHT(COALESCE(xml_fragment,''),260) AS fragment_tail,
               metadata_json
        FROM easa_erules_import_nodes_staging
        WHERE batch_id = ? AND node_uid = ?
        LIMIT 1
    ");
    $st->execute([$batchId, $nodeUid]);
    $node = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($node)) {
        throw new RuntimeException('Node not found in staging for requested batch/node.');
    }

    $batchSt = $pdo->prepare("
        SELECT id,status,storage_relpath,rows_detected,error_message,parse_phase,parse_rows_so_far,
               parse_started_at,parse_finished_at,updated_at
        FROM easa_erules_import_batches
        WHERE id = ?
        LIMIT 1
    ");
    $batchSt->execute([$batchId]);
    $batch = $batchSt->fetch(PDO::FETCH_ASSOC);

    $sourceAbs = easa_erules_batch_source_xml_absolute_path($pdo, $batchId);
    $storage = [
        'resolved_absolute' => $sourceAbs,
        'exists' => $sourceAbs !== null ? is_file($sourceAbs) : false,
        'readable' => $sourceAbs !== null ? is_readable($sourceAbs) : false,
        'expected_relpath' => easa_erules_batch_relative_path($batchId),
        'db_relpath' => is_array($batch) ? (string) ($batch['storage_relpath'] ?? '') : null,
    ];

    $sourceErules = $erulesArg !== '' ? $erulesArg : trim((string) ($node['source_erules_id'] ?? ''));
    $extractedByFunction = '';
    $candidates = [];

    if ($sourceAbs !== null && $sourceErules !== '') {
        $extractedByFunction = easa_erules_extract_plain_text_from_source_xml_by_erules_id($sourceAbs, $sourceErules);

        $wantKey = easa_erules_id_match_key($sourceErules);
        $reader = new XMLReader();
        if ($wantKey !== '' && $reader->open($sourceAbs, null, LIBXML_PARSEHUGE | LIBXML_NONET | LIBXML_COMPACT)) {
            try {
                while ($reader->read()) {
                    if ($reader->nodeType !== XMLReader::ELEMENT) {
                        continue;
                    }
                    $attrs = easa_erules_reader_collect_attributes($reader);
                    $erRaw = easa_erules_attr_ci($attrs, 'ERulesId');
                    $er = trim((string) ($erRaw ?? ''));
                    if (easa_erules_id_match_key($er) !== $wantKey) {
                        continue;
                    }
                    $local = (string) $reader->localName;
                    $outer = $reader->readOuterXml();
                    if (!is_string($outer) || $outer === '') {
                        continue;
                    }
                    $pc = easa_erules_plain_canonical_from_outer_xml($outer);
                    $plainTrim = trim($pc['plain']);
                    $score = strlen($plainTrim) + (strcasecmp($local, 'topic') === 0 ? 20000 : 0);
                    $candidates[] = [
                        'local_name' => $local,
                        'erules_attr' => $er,
                        'plain_len' => strlen($plainTrim),
                        'canonical_len' => strlen(trim($pc['canonical'])),
                        'outer_len' => strlen($outer),
                        'score' => $score,
                        'plain_head' => substr($plainTrim, 0, 220),
                    ];
                    if (count($candidates) >= 120) {
                        break;
                    }
                }
            } finally {
                $reader->close();
            }
        }
    }

    usort($candidates, static function (array $a, array $b): int {
        return (int) ($b['score'] <=> $a['score']);
    });

    $probe = [
        'batch' => $batch,
        'node' => $node,
        'storage' => $storage,
        'probe_erules_id' => $sourceErules !== '' ? $sourceErules : null,
        'probe_erules_key' => $sourceErules !== '' ? easa_erules_id_match_key($sourceErules) : null,
        'extractor_plain_len' => strlen(trim($extractedByFunction)),
        'extractor_plain_head' => substr(trim($extractedByFunction), 0, 260),
        'candidate_count' => count($candidates),
        'top_candidates' => array_slice($candidates, 0, 15),
    ];

    jprint('probe', $probe);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
