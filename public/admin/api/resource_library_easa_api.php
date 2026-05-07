<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/openai.php';
require_once __DIR__ . '/../../../src/ecfr_api_client.php';
require_once __DIR__ . '/../../../src/resource_library_catalog.php';
require_once __DIR__ . '/../../../src/easa_erules_storage.php';
require_once __DIR__ . '/../../../src/easa_download_monitor.php';
require_once __DIR__ . '/../../../src/easa_erules_xml_import.php';

@ini_set('upload_max_filesize', '128M');
@ini_set('post_max_size', '128M');

cw_require_admin();

header('Content-Type: application/json; charset=utf-8');

function rl_easa_json_out(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** @return int Parsed byte size from php.ini strings such as "32M" (0 if unknown). */
function rl_easa_ini_bytes(string $iniKey): int
{
    $raw = trim((string) ini_get($iniKey));
    if ($raw === '' || $raw === '0') {
        return 0;
    }
    if (!preg_match('/^(-?\d+(?:\.\d+)?)\s*([kmg]?)\s*$/i', str_replace(' ', '', $raw), $m)) {
        return (int) $raw;
    }
    $num = (float) $m[1];
    $u = strtolower($m[2] ?? '');

    return match ($u) {
        'g' => (int) round($num * 1073741824),
        'm' => (int) round($num * 1048576),
        'k' => (int) round($num * 1024),
        default => (int) round($num),
    };
}

/**
 * LIKE patterns for staging search (escape % and _). Second pattern drops dots so FCL.055 also matches FCL055-style ids.
 *
 * @return array{0: string, 1: string|null}
 */
function rl_easa_search_like_patterns(string $q): array
{
    $esc = static function (string $s): string {
        return '%' . str_replace(['%', '_'], ['\\%', '\\_'], $s) . '%';
    };
    $like = $esc($q);
    $noDots = str_replace(['.', '·', "\u{00B7}"], '', $q);
    if ($noDots === $q || strlen(trim($noDots)) < 2) {
        return [$like, null];
    }

    return [$like, $esc(trim($noDots))];
}

/**
 * Same WHERE clause as Search EASA index (titles, ids, paths, plain_text, optional canonical_text).
 *
 * @return array{where: string, bind: list<mixed>}
 */
function rl_easa_search_match_clause(PDO $pdo, string $q): array
{
    [$like, $likeNoDots] = rl_easa_search_like_patterns($q);
    $matchCols = ['plain_text', 'title', 'source_title', 'breadcrumb', 'source_erules_id', 'path'];
    if (easa_erules_staging_has_canonical_column($pdo)) {
        $matchCols[] = 'canonical_text';
    }
    $matchParts = [];
    $bind = [];
    foreach ($matchCols as $col) {
        $matchParts[] = "COALESCE({$col}, '') LIKE ? ESCAPE '\\\\'";
        $bind[] = $like;
    }
    if ($likeNoDots !== null) {
        foreach (['source_erules_id', 'path', 'title', 'breadcrumb'] as $col) {
            $matchParts[] = "COALESCE({$col}, '') LIKE ? ESCAPE '\\\\'";
            $bind[] = $likeNoDots;
        }
    }

    return ['where' => '(' . implode(' OR ', $matchParts) . ')', 'bind' => $bind];
}

/**
 * Snippet column: prefers canonical body when the column exists (aligned with search/compare).
 */
function rl_easa_staging_snippet_concat_body(PDO $pdo): string
{
    if (easa_erules_staging_has_canonical_column($pdo)) {
        return 'COALESCE(NULLIF(TRIM(canonical_text), \'\'), plain_text)';
    }

    return 'plain_text';
}

/**
 * Pull staging excerpts for AI compare using the same matching rules as search.
 *
 * @return array{hit_count: int, bundle: string, sources: list<array<string, mixed>>, summary: string}
 */
function rl_easa_build_compare_staging_bundle(PDO $pdo, string $q, int $batchFilter): array
{
    $out = [
        'hit_count' => 0,
        'bundle' => '',
        'sources' => [],
        'summary' => '',
    ];
    if (!easa_erules_staging_tables_ok($pdo)) {
        $out['summary'] = 'EASA staging is not available (apply scripts/sql/resource_library_easa_erules_staging.sql).';

        return $out;
    }
    try {
        $total = (int) $pdo->query('SELECT COUNT(*) FROM easa_erules_import_nodes_staging')->fetchColumn();
    } catch (Throwable) {
        $out['summary'] = 'Could not read easa_erules_import_nodes_staging.';

        return $out;
    }
    if ($total === 0) {
        $out['summary'] = 'No rows in staging yet. Upload official Easy Access XML and run “Parse XML → staging”.';

        return $out;
    }
    $m = rl_easa_search_match_clause($pdo, $q);
    $whereMatch = $m['where'];
    $bind = $m['bind'];
    $wrapRank = '(CASE WHEN LOWER(node_type) IN (\'document\',\'frontmatter\',\'toc\',\'backmatter\') THEN 1 ELSE 0 END)';
    $maxRows = 15;
    $excerptLen = 4500;
    $excerptBody = rl_easa_staging_snippet_concat_body($pdo);
    if ($batchFilter > 0) {
        $sql = "
            SELECT batch_id, node_uid, node_type, source_erules_id, title, breadcrumb,
                   SUBSTRING({$excerptBody}, 1, {$excerptLen}) AS excerpt
            FROM easa_erules_import_nodes_staging
            WHERE batch_id = ? AND {$whereMatch}
            ORDER BY {$wrapRank} ASC, id ASC
            LIMIT {$maxRows}
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$batchFilter], $bind));
    } else {
        $sql = "
            SELECT batch_id, node_uid, node_type, source_erules_id, title, breadcrumb,
                   SUBSTRING({$excerptBody}, 1, {$excerptLen}) AS excerpt
            FROM easa_erules_import_nodes_staging
            WHERE {$whereMatch}
            ORDER BY {$wrapRank} ASC, id DESC
            LIMIT {$maxRows}
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out['hit_count'] = count($rows);
    foreach ($rows as $r) {
        $out['sources'][] = [
            'batch_id' => (int) ($r['batch_id'] ?? 0),
            'node_uid' => (string) ($r['node_uid'] ?? ''),
            'node_type' => (string) ($r['node_type'] ?? ''),
            'source_erules_id' => (string) ($r['source_erules_id'] ?? ''),
            'title' => (string) ($r['title'] ?? ''),
        ];
    }
    $maxBundle = 38000;
    $parts = [];
    $totalChars = 0;
    foreach ($rows as $i => $r) {
        $hdr = sprintf(
            "--- Source %d | batch_id=%s | node_uid=%s | type=%s | ERulesId=%s ---\nTitle: %s\nBreadcrumb: %s\nExcerpt:\n%s\n",
            $i + 1,
            (string) ($r['batch_id'] ?? ''),
            (string) ($r['node_uid'] ?? ''),
            (string) ($r['node_type'] ?? ''),
            (string) ($r['source_erules_id'] ?? ''),
            (string) ($r['title'] ?? ''),
            (string) ($r['breadcrumb'] ?? ''),
            (string) ($r['excerpt'] ?? '')
        );
        if (strlen($hdr) > 14000) {
            $hdr = substr($hdr, 0, 14000) . "\n… [truncated]";
        }
        if ($totalChars + strlen($hdr) > $maxBundle && $parts !== []) {
            $parts[] = '[Further matched rows omitted to stay within model context size.]';

            break;
        }
        $parts[] = $hdr;
        $totalChars += strlen($hdr);
    }
    $out['bundle'] = implode("\n", $parts);
    if ($out['hit_count'] > 0) {
        $out['summary'] = sprintf(
            'Loaded %d excerpt(s) from easa_erules_import_nodes_staging (parsed Easy Access XML). Quote with batch_id + node_uid and/or ERulesId; verify on official EASA sources.',
            $out['hit_count']
        );
    } else {
        $out['summary'] = 'No staging rows matched your question. Use keywords or rule ids (e.g. FCL.055), optional batch filter, or the rule tree. Always verify on EASA and national portals.';
    }

    return $out;
}

function rl_easa_extract_ai_text(array $resp): string
{
    if (!empty($resp['output_text']) && is_string($resp['output_text'])) {
        return trim($resp['output_text']);
    }
    $out = $resp['output'] ?? [];
    if (!is_array($out)) {
        return '';
    }
    $text = '';
    foreach ($out as $item) {
        if (!is_array($item)) {
            continue;
        }
        $content = $item['content'] ?? [];
        if (!is_array($content)) {
            continue;
        }
        foreach ($content as $c) {
            if (is_array($c) && ($c['type'] ?? '') === 'output_text') {
                $text .= (string) ($c['text'] ?? '');
            }
        }
    }

    return trim($text);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $action = trim((string) ($_GET['action'] ?? 'status'));

    if ($action === 'batch_progress') {
        $bid = (int) ($_GET['batch_id'] ?? 0);
        if ($bid <= 0) {
            rl_easa_json_out(400, ['ok' => false, 'error' => 'batch_id required']);
        }
        try {
            $st = $pdo->prepare('SELECT * FROM easa_erules_import_batches WHERE id = ? LIMIT 1');
            $st->execute([$bid]);
            $batchRow = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            rl_easa_json_out(503, ['ok' => false, 'error' => 'easa_erules_import_batches not available']);
        }
        if (!is_array($batchRow)) {
            rl_easa_json_out(404, ['ok' => false, 'error' => 'Batch not found']);
        }
        rl_easa_json_out(200, [
            'ok' => true,
            'batch' => $batchRow,
        ]);
    }

    if ($action === 'tree_children') {
        if (!easa_erules_staging_tables_ok($pdo)) {
            rl_easa_json_out(503, ['ok' => false, 'error' => 'Apply scripts/sql/resource_library_easa_erules_staging.sql first']);
        }
        $batchId = (int) ($_GET['batch_id'] ?? 0);
        if ($batchId <= 0) {
            rl_easa_json_out(400, ['ok' => false, 'error' => 'batch_id required']);
        }
        $parentRaw = isset($_GET['parent_uid']) ? trim((string) $_GET['parent_uid']) : null;
        $isRoot = $parentRaw === null || $parentRaw === '';
        try {
            if ($isRoot) {
                $sql = "
                    SELECT n.batch_id, n.node_uid, n.parent_node_uid, n.node_type, n.sort_order, n.depth,
                           n.source_erules_id, n.title, n.source_title, n.breadcrumb,
                           (SELECT COUNT(*) FROM easa_erules_import_nodes_staging c
                            WHERE c.batch_id = n.batch_id AND c.parent_node_uid = n.node_uid) AS child_count
                    FROM easa_erules_import_nodes_staging n
                    WHERE n.batch_id = ?
                      AND (n.parent_node_uid IS NULL OR n.parent_node_uid = '')
                    ORDER BY n.sort_order ASC, n.id ASC
                ";
                $st = $pdo->prepare($sql);
                $st->execute([$batchId]);
            } else {
                $sql = "
                    SELECT n.batch_id, n.node_uid, n.parent_node_uid, n.node_type, n.sort_order, n.depth,
                           n.source_erules_id, n.title, n.source_title, n.breadcrumb,
                           (SELECT COUNT(*) FROM easa_erules_import_nodes_staging c
                            WHERE c.batch_id = n.batch_id AND c.parent_node_uid = n.node_uid) AS child_count
                    FROM easa_erules_import_nodes_staging n
                    WHERE n.batch_id = ? AND n.parent_node_uid = ?
                    ORDER BY n.sort_order ASC, n.id ASC
                ";
                $st = $pdo->prepare($sql);
                $st->execute([$batchId, $parentRaw]);
            }
            $nodes = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($nodes as $k => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $nodes[$k]['label_short'] = easa_erules_short_tree_label($row);
                $nodes[$k]['rule_band'] = easa_erules_classify_display_band(
                    $row['node_type'] ?? null,
                    $row['title'] ?? null,
                    $row['source_title'] ?? null,
                    $row['source_erules_id'] ?? null
                );
            }
        } catch (Throwable $e) {
            rl_easa_json_out(503, ['ok' => false, 'error' => $e->getMessage()]);
        }
        rl_easa_json_out(200, [
            'ok' => true,
            'batch_id' => $batchId,
            'parent_uid' => $isRoot ? null : $parentRaw,
            'nodes' => $nodes,
        ]);
    }

    if ($action === 'node_detail') {
        if (!easa_erules_staging_tables_ok($pdo)) {
            rl_easa_json_out(503, ['ok' => false, 'error' => 'Apply scripts/sql/resource_library_easa_erules_staging.sql first']);
        }
        $batchId = (int) ($_GET['batch_id'] ?? 0);
        $nodeUid = trim((string) ($_GET['node_uid'] ?? ''));
        if ($batchId <= 0 || $nodeUid === '') {
            rl_easa_json_out(400, ['ok' => false, 'error' => 'batch_id and node_uid required']);
        }
        try {
            $detailSql = easa_erules_staging_has_canonical_column($pdo)
                ? '
                SELECT batch_id, node_uid, parent_node_uid, node_type, depth, sort_order,
                       source_erules_id, title, source_title, breadcrumb, path,
                       plain_text, canonical_text, xml_fragment, metadata_json
                FROM easa_erules_import_nodes_staging
                WHERE batch_id = ? AND node_uid = ?
                LIMIT 1
            '
                : '
                SELECT batch_id, node_uid, parent_node_uid, node_type, depth, sort_order,
                       source_erules_id, title, source_title, breadcrumb, path,
                       plain_text, xml_fragment, metadata_json
                FROM easa_erules_import_nodes_staging
                WHERE batch_id = ? AND node_uid = ?
                LIMIT 1
            ';
            $st = $pdo->prepare($detailSql);
            $st->execute([$batchId, $nodeUid]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            rl_easa_json_out(503, ['ok' => false, 'error' => $e->getMessage()]);
        }
        if (!is_array($row)) {
            rl_easa_json_out(404, ['ok' => false, 'error' => 'Node not found']);
        }
        $canonicalRaw = trim((string) ($row['canonical_text'] ?? ''));
        $plainRaw = (string) ($row['plain_text'] ?? '');
        $plainTrim = trim($plainRaw);
        $composed = '';
        if ($plainTrim === '') {
            $composed = easa_erules_aggregate_descendant_plain_text($pdo, $batchId, $nodeUid, 0);
        }
        $stepPlain = $plainTrim !== '' ? $plainRaw : $composed;
        $stepPlainTrim = trim($stepPlain);
        $fromFrag = '';
        if ($canonicalRaw === '' && $stepPlainTrim === '') {
            $fragRaw = trim((string) ($row['xml_fragment'] ?? ''));
            if ($fragRaw !== '') {
                $fromFrag = easa_erules_plain_text_from_stored_xml_fragment($fragRaw);
            }
        }
        $srcEid = trim((string) ($row['source_erules_id'] ?? ''));
        $fromXml = '';
        if ($canonicalRaw === '' && $stepPlainTrim === '' && trim($fromFrag) === '' && $srcEid !== '') {
            $xmlAbs = easa_erules_batch_source_xml_absolute_path($pdo, $batchId);
            if ($xmlAbs !== null) {
                $fromXml = easa_erules_extract_plain_text_from_source_xml_by_erules_id($xmlAbs, $srcEid);
            }
        }

        $effectivePlain = '';
        if ($canonicalRaw !== '') {
            $effectivePlain = $canonicalRaw;
            $row['plain_text_effective_source'] = 'canonical';
        } elseif ($stepPlainTrim !== '') {
            $effectivePlain = $stepPlain;
            $row['plain_text_effective_source'] = $plainTrim !== '' ? 'node' : 'descendants';
        } elseif (trim($fromFrag) !== '') {
            $effectivePlain = $fromFrag;
            $row['plain_text_effective_source'] = 'xml_fragment';
        } elseif (trim($fromXml) !== '') {
            $effectivePlain = $fromXml;
            $row['plain_text_effective_source'] = 'source_xml_erules';
        } else {
            $row['plain_text_effective_source'] = 'none';
        }
        $row['plain_text_composed_from_descendants'] = $canonicalRaw === '' && $plainTrim === '' && trim($composed) !== '';
        $maxPlain = 400000;
        $truncated = strlen($effectivePlain) > $maxPlain;
        if ($truncated) {
            $row['plain_text'] = substr($effectivePlain, 0, $maxPlain) . "\n\n… [truncated for API; full text is in the staging rows]";
        } else {
            $row['plain_text'] = $effectivePlain;
        }
        $row['plain_text_truncated'] = $truncated;
        $row['title_display'] = easa_erules_sanitize_display_text((string) ($row['title'] ?? ''));
        $row['plain_text_display'] = easa_erules_sanitize_rule_body_text($truncated ? (string) $row['plain_text'] : $effectivePlain);
        if ($row['plain_text_display'] === '' && $row['plain_text_effective_source'] === 'none') {
            $row['plain_text_display'] = 'No rule text could be resolved: canonical_text and plain_text are empty, no text could be extracted from xml_fragment, no child rows contributed text, and source.xml could not be matched by ERulesId (or the file is missing). Expected file: storage/easa_erules/batches/' . (int) $batchId . '/source.xml — verify batch storage_relpath matches this batch id.';
        }
        $row['rule_band'] = easa_erules_classify_display_band(
            $row['node_type'] ?? null,
            $row['title'] ?? null,
            $row['source_title'] ?? null,
            $row['source_erules_id'] ?? null
        );
        rl_easa_json_out(200, ['ok' => true, 'node' => $row]);
    }

    if ($action !== 'status') {
        rl_easa_json_out(400, ['ok' => false, 'error' => 'Unknown action']);
    }

    $tablesOk = easa_download_monitor_tables_ok($pdo);
    $stagingOk = easa_erules_staging_tables_ok($pdo);
    $progressOk = easa_erules_batch_progress_available($pdo);
    $monitor = [];
    $batches = [];
    $stagingNodes = 0;
    if ($tablesOk) {
        $monitor = $pdo->query('SELECT id, url, label, checked_at, http_status, final_url, etag, last_modified, content_length, changed_flag, last_error FROM easa_download_monitor ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $batches = $pdo->query('SELECT * FROM easa_erules_import_batches ORDER BY id DESC LIMIT 25')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($stagingOk) {
            $stagingNodes = (int) $pdo->query('SELECT COUNT(*) FROM easa_erules_import_nodes_staging')->fetchColumn();
            $stmtN = $pdo->query('SELECT batch_id, COUNT(*) AS c FROM easa_erules_import_nodes_staging GROUP BY batch_id');
            $byBatch = [];
            if ($stmtN instanceof PDOStatement) {
                while ($r = $stmtN->fetch(PDO::FETCH_ASSOC)) {
                    $byBatch[(int) ($r['batch_id'] ?? 0)] = (int) ($r['c'] ?? 0);
                }
            }
            foreach ($batches as $k => $br) {
                $bid = (int) ($br['id'] ?? 0);
                $batches[$k]['staging_nodes'] = $byBatch[$bid] ?? 0;
            }
        }
    }

    $upBytes = rl_easa_ini_bytes('upload_max_filesize');
    $postBytes = rl_easa_ini_bytes('post_max_size');
    $maxBodyBytes = ($upBytes > 0 && $postBytes > 0) ? min($upBytes, $postBytes) : max($upBytes, $postBytes);

    rl_easa_json_out(200, [
        'ok' => true,
        'tables_ok' => $tablesOk,
        'staging_tables_ok' => $stagingOk,
        'progress_columns_ok' => $progressOk,
        'php_upload_max_filesize' => ini_get('upload_max_filesize'),
        'php_post_max_size' => ini_get('post_max_size'),
        'max_body_bytes' => $maxBodyBytes,
        'migrate_hint' => $tablesOk ? null : 'Apply scripts/sql/resource_library_easa_erules.sql',
        'staging_migrate_hint' => ($tablesOk && !$stagingOk) ? 'Apply scripts/sql/resource_library_easa_erules_staging.sql for XML node staging.' : null,
        'progress_migrate_hint' => ($tablesOk && !$progressOk) ? 'Apply scripts/sql/resource_library_easa_erules_batch_progress.sql for live import progress (parse_phase, heartbeat).' : null,
        'supports_async_parse' => function_exists('fastcgi_finish_request'),
        'indexed_nodes' => $stagingOk ? $stagingNodes : 0,
        'indexed_hint' => $stagingOk
            ? 'Staging rows hold parsed XML nodes (streaming import). Canonical publish + search chunks are a later step.'
            : 'Apply staging migration, then use Parse XML → staging on a batch.',
        'monitor' => $monitor,
        'batches' => $batches,
        'ecfr_configured' => rl_catalog_resolve_ecfr_training_report_edition($pdo) !== null,
    ]);
}

if ($method !== 'POST') {
    rl_easa_json_out(405, ['ok' => false, 'error' => 'Method not allowed']);
}

$contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));

// Prefer $_FILES over Content-Type: some stacks omit or rewrite CONTENT_TYPE while still populating FILES.
$hasErulesUpload = isset($_FILES['erules_xml']) && is_array($_FILES['erules_xml']);
$multipartLike = str_contains($contentType, 'multipart/form-data') || str_contains($contentType, 'multipart/');

if ($hasErulesUpload) {
    if (!easa_download_monitor_tables_ok($pdo)) {
        rl_easa_json_out(503, ['ok' => false, 'error' => 'Apply scripts/sql/resource_library_easa_erules.sql first']);
    }
    $err = (int) ($_FILES['erules_xml']['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        rl_easa_json_out(400, ['ok' => false, 'error' => 'Upload error code ' . $err]);
    }
    $tmp = (string) ($_FILES['erules_xml']['tmp_name'] ?? '');
    $orig = (string) ($_FILES['erules_xml']['name'] ?? 'export.xml');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        rl_easa_json_out(400, ['ok' => false, 'error' => 'Invalid upload']);
    }
    $raw = file_get_contents($tmp);
    if ($raw === false) {
        rl_easa_json_out(400, ['ok' => false, 'error' => 'Could not read upload']);
    }
    $sha = hash('sha256', $raw);
    $uid = cw_current_user($pdo);
    $userId = is_array($uid) ? (int) ($uid['id'] ?? 0) : 0;

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare('
            INSERT INTO easa_erules_import_batches (status, original_filename, file_sha256, storage_relpath, file_size, uploaded_by_user_id)
            VALUES (\'uploaded\', ?, ?, \'\', ?, ?)
        ');
        $ins->execute([$orig, $sha, strlen($raw), $userId > 0 ? $userId : null]);
        $batchId = (int) $pdo->lastInsertId();
        if ($batchId <= 0) {
            throw new RuntimeException('Could not create batch row');
        }
        $stored = easa_erules_store_batch_upload($batchId, $tmp);
        $rel = $stored['relpath'];
        $pdo->prepare('UPDATE easa_erules_import_batches SET storage_relpath = ?, file_size = ? WHERE id = ?')->execute([$rel, $stored['size'], $batchId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        rl_easa_json_out(500, ['ok' => false, 'error' => $e->getMessage()]);
    }

    rl_easa_json_out(200, [
        'ok' => true,
        'batch_id' => $batchId,
        'sha256' => $sha,
        'message' => 'File stored as official evidence. Click “Parse XML → staging” for this batch (after applying staging SQL if needed).',
    ]);
}

if ($multipartLike) {
    rl_easa_json_out(400, [
        'ok' => false,
        'error' => 'Missing file field erules_xml, or the upload exceeded PHP limits (post_max_size / upload_max_filesize).',
    ]);
}

$rawIn = file_get_contents('php://input');
$data = json_decode((string) $rawIn, true);
if (!is_array($data)) {
    rl_easa_json_out(400, ['ok' => false, 'error' => 'Invalid JSON']);
}

$action = trim((string) ($data['action'] ?? ''));

if ($action === 'probe_monitor') {
    if (!easa_download_monitor_tables_ok($pdo)) {
        rl_easa_json_out(503, ['ok' => false, 'error' => 'Apply scripts/sql/resource_library_easa_erules.sql']);
    }
    $res = easa_download_monitor_probe_all($pdo);
    rl_easa_json_out(200, ['ok' => true, 'probed' => $res['probed'], 'errors' => $res['errors']]);
}

if ($action === 'search') {
    $q = trim((string) ($data['query'] ?? ''));
    if ($q === '') {
        rl_easa_json_out(400, ['ok' => false, 'error' => 'query required']);
    }
    if (!easa_erules_staging_tables_ok($pdo)) {
        rl_easa_json_out(200, [
            'ok' => true,
            'hits' => [],
            'hit_count' => 0,
            'note' => 'Apply scripts/sql/resource_library_easa_erules_staging.sql and parse a batch first.',
        ]);
    }
    $batchFilter = (int) ($data['batch_id'] ?? 0);
    $limit = (int) ($data['limit'] ?? 50);
    $limit = min(200, max(1, $limit));
    $offset = max(0, (int) ($data['offset'] ?? 0));
    $mSearch = rl_easa_search_match_clause($pdo, $q);
    $whereMatch = $mSearch['where'];
    $bind = $mSearch['bind'];
    // Root <document> / frontmatter / toc / backmatter: sort after rows that look like real rules.
    $wrapRank = '(CASE WHEN LOWER(node_type) IN (\'document\',\'frontmatter\',\'toc\',\'backmatter\') THEN 1 ELSE 0 END)';
    $snippetBody = rl_easa_staging_snippet_concat_body($pdo);
    $snippet = "SUBSTRING(TRIM(CONCAT_WS(CHAR(10),
        NULLIF(TRIM(COALESCE(source_erules_id,'')), ''),
        NULLIF(TRIM(COALESCE(title,'')), ''),
        NULLIF(TRIM(COALESCE(source_title,'')), ''),
        NULLIF(TRIM(COALESCE(breadcrumb,'')), ''),
        {$snippetBody})), 1, 520)";
    if ($batchFilter > 0) {
        $sql = "
            SELECT batch_id, node_uid, node_type, source_erules_id, title, breadcrumb,
                   {$snippet} AS snippet
            FROM easa_erules_import_nodes_staging
            WHERE batch_id = ? AND {$whereMatch}
            ORDER BY {$wrapRank} ASC, id ASC
            LIMIT {$limit} OFFSET {$offset}
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$batchFilter], $bind));
    } else {
        $sql = "
            SELECT batch_id, node_uid, node_type, source_erules_id, title, breadcrumb,
                   {$snippet} AS snippet
            FROM easa_erules_import_nodes_staging
            WHERE {$whereMatch}
            ORDER BY {$wrapRank} ASC, id DESC
            LIMIT {$limit} OFFSET {$offset}
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
    }
    $hits = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    rl_easa_json_out(200, [
        'ok' => true,
        'hits' => $hits,
        'hit_count' => count($hits),
        'limit' => $limit,
        'offset' => $offset,
        'note' => $hits === []
            ? 'No matches in staging. Searches plain_text' . (easa_erules_staging_has_canonical_column($pdo) ? ', canonical_text' : '') . ', title, source_title, breadcrumb, source_erules_id, and path (with a dotless variant for ids like FCL.055 vs FCL055).'
            : null,
    ]);
}

if ($action === 'parse_batch') {
    @ini_set('memory_limit', '768M');
    @set_time_limit(0);

    if (!easa_erules_staging_tables_ok($pdo)) {
        rl_easa_json_out(503, ['ok' => false, 'error' => 'Apply scripts/sql/resource_library_easa_erules_staging.sql first']);
    }

    $batchId = (int) ($data['batch_id'] ?? 0);
    if ($batchId <= 0) {
        rl_easa_json_out(400, ['ok' => false, 'error' => 'batch_id required']);
    }

    $syncWait = !empty($data['sync_wait']) || !empty($data['sync']);
    $useAsync = !$syncWait && function_exists('fastcgi_finish_request');

    $pdo->prepare('UPDATE easa_erules_import_batches SET status = \'staging\', error_message = NULL WHERE id = ?')->execute([$batchId]);

    $runImport = function () use ($pdo, $batchId): array {
        return easa_erules_import_batch_xml_to_staging($pdo, $batchId);
    };

    $finishOk = function (array $result) use ($pdo, $batchId): void {
        easa_erules_import_finalize_success($pdo, $batchId, (int) $result['imported'], $result['publication_meta'] ?? null);
    };

    $finishErr = function (Throwable $e) use ($pdo, $batchId): void {
        easa_erules_import_finalize_failure($pdo, $batchId, $e->getMessage());
    };

    if ($useAsync) {
        header('Content-Type: application/json; charset=utf-8');
        header('Connection: close');
        http_response_code(202);
        echo json_encode([
            'ok' => true,
            'async' => true,
            'batch_id' => $batchId,
            'message' => 'Import started on the server. Status updates every few seconds in the batch row (poll batch_progress).',
            'poll_hint' => 'GET ?action=batch_progress&batch_id=' . $batchId,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            flush();
        }

        try {
            $result = $runImport();
            $finishOk($result);
        } catch (Throwable $e) {
            $finishErr($e);
        }
        exit(0);
    }

    try {
        $result = $runImport();
        $finishOk($result);
        rl_easa_json_out(200, [
            'ok' => true,
            'async' => false,
            'imported' => (int) $result['imported'],
            'batch_id' => $batchId,
            'publication_meta' => $result['publication_meta'],
            'message' => 'Parsed into easa_erules_import_nodes_staging. Review rows before any canonical publish.',
        ]);
    } catch (Throwable $e) {
        $finishErr($e);
        rl_easa_json_out(400, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

if ($action === 'regulatory_compare_ai') {
    $q = trim((string) ($data['query'] ?? ''));
    if ($q === '') {
        rl_easa_json_out(400, ['ok' => false, 'error' => 'query required']);
    }
    $useAi = !empty($data['use_ai']);
    $includeEcfr = !empty($data['include_ecfr']);
    $titleNum = (int) ($data['ecfr_title_number'] ?? 14);
    $section = trim((string) ($data['ecfr_section'] ?? ''));
    $compareBatchId = (int) ($data['batch_id'] ?? 0);

    $stagingCompare = rl_easa_build_compare_staging_bundle($pdo, $q, $compareBatchId);
    $easaCtx = $stagingCompare['summary'];
    $easaExcerptBundle = trim((string) ($stagingCompare['bundle'] ?? ''));

    $ecfrHtml = '';
    $ecfrNote = '';
    if ($includeEcfr) {
        if ($section === '') {
            $ecfrNote = 'Include U.S. eCFR: provide ecfr_section (e.g. 61.105).';
        } else {
            try {
                $cfg = rl_catalog_ecfr_runtime_config($pdo);
                $client = new EcfrApiClient($cfg['api_base_url']);
                $snap = $client->resolveTitleSnapshotDate($titleNum > 0 ? $titleNum : 14);
                $xml = $client->fetchSectionXml($titleNum > 0 ? $titleNum : 14, $section, $snap);
                $ecfrHtml = $client->sectionXmlToHtml($xml);
                $browse = $client->sectionBrowseUrl($titleNum > 0 ? $titleNum : 14, $section);
                $ecfrNote = 'Official excerpt via eCFR versioner API · snapshot ' . $snap . ' · Browse: ' . $browse;
            } catch (Throwable $e) {
                $ecfrNote = 'eCFR fetch failed: ' . $e->getMessage();
            }
        }
    }

    $payload = [
        'ok' => true,
        'easa_context_note' => $easaCtx,
        'easa_staging_hits' => $stagingCompare['hit_count'],
        'easa_sources' => $stagingCompare['sources'],
        'ecfr_html' => $ecfrHtml !== '' ? $ecfrHtml : null,
        'ecfr_note' => $ecfrNote !== '' ? $ecfrNote : null,
        'ai_answer' => '',
        'ai_error' => null,
    ];

    if (!$useAi) {
        rl_easa_json_out(200, $payload);
    }

    $bundle = "EU / EASA — official excerpts from this installation’s staging table (easa_erules_import_nodes_staging), matched by your question using the same rules as “Search EASA index”. Each block is labeled with batch_id and node_uid for traceability.\n\n";
    if ($easaExcerptBundle !== '') {
        $bundle .= $easaExcerptBundle . "\n\n";
    } else {
        $bundle .= "(No row-level excerpts matched — refer to easa_context_note above.)\n\n";
    }
    if ($ecfrHtml !== '') {
        $strip = preg_replace('/\s+/', ' ', strip_tags($ecfrHtml));
        $strip = is_string($strip) ? trim($strip) : '';
        if (strlen($strip) > 12000) {
            $strip = substr($strip, 0, 12000) . '…';
        }
        $bundle .= "U.S. 14 CFR excerpt (eCFR API, for comparison only):\n" . $strip . "\n\n";
    }

    try {
        $resp = cw_openai_responses([
            'model' => cw_openai_model(),
            'input' => [
                [
                    'role' => 'system',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => 'You help aviation compliance staff compare regulatory concepts. When the bundle includes EASA blocks labeled with batch_id/node_uid/ERulesId, treat those as the installation’s parsed Easy Access staging excerpts—quote them accurately and cite batch_id + node_uid or ERulesId. If no excerpts matched, say so clearly. When U.S. text is provided from eCFR, label it as U.S. 14 CFR. Never replace official sources; not legal advice.',
                        ],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => "Question:\n" . $q . "\n\nReference bundle:\n" . $bundle,
                        ],
                    ],
                ],
            ],
        ], 120);
        $payload['ai_answer'] = rl_easa_extract_ai_text($resp);
    } catch (Throwable $e) {
        $payload['ai_error'] = $e->getMessage();
    }

    rl_easa_json_out(200, $payload);
}

rl_easa_json_out(400, ['ok' => false, 'error' => 'Unknown action']);
