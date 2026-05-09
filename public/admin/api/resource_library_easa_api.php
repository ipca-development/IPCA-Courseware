<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/openai.php';
require_once __DIR__ . '/../../../src/ecfr_api_client.php';
require_once __DIR__ . '/../../../src/resource_library_catalog.php';
require_once __DIR__ . '/../../../src/easa_erules_storage.php';
require_once __DIR__ . '/../../../src/easa_download_monitor.php';
require_once __DIR__ . '/../../../src/easa_erules_xml_import.php';
require_once __DIR__ . '/../../../src/resource_library_easa_node_detail_build.php';

@ini_set('upload_max_filesize', '128M');
@ini_set('post_max_size', '128M');

cw_require_admin();

header('Content-Type: application/json; charset=utf-8');

function rl_easa_json_out(int $code, array $payload): void
{
    http_response_code($code);
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $json = json_encode($payload, $flags);
    if ($json === false) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => 'json_encode failed: ' . json_last_error_msg(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    echo $json;
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
 * Rule-id and keyword fallbacks for natural-language compare questions.
 *
 * @return list<string>
 */
function rl_easa_compare_query_needles(string $q): array
{
    $q = trim($q);
    if ($q === '') {
        return [];
    }
    $needles = [];
    if (preg_match_all('/\b(?:FCL\.\d+[A-Z]?|(?:ORA|CAT|DTO|ARA|MED)(?:\.[A-Z0-9]+)+)\b/iu', $q, $m)) {
        foreach (($m[0] ?? []) as $id) {
            $id = strtoupper(trim((string) $id));
            if ($id !== '' && !in_array($id, $needles, true)) {
                $needles[] = $id;
            }
        }
    }
    $raw = preg_split('/[^\p{L}\p{N}\.\-]+/u', mb_strtolower($q)) ?: [];
    $stop = [
        'the' => true, 'and' => true, 'for' => true, 'with' => true, 'from' => true, 'that' => true, 'this' => true,
        'what' => true, 'when' => true, 'where' => true, 'which' => true, 'does' => true, 'about' => true,
        'under' => true, 'into' => true, 'are' => true, 'can' => true, 'should' => true, 'must' => true,
        'rule' => true, 'rules' => true, 'official' => true, 'regulation' => true, 'regulations' => true,
    ];
    foreach ($raw as $tok) {
        $tok = trim((string) $tok, " \t\n\r\0\x0B.-");
        if ($tok === '' || strlen($tok) < 4 || isset($stop[$tok])) {
            continue;
        }
        if (!in_array($tok, $needles, true)) {
            $needles[] = $tok;
        }
        if (count($needles) >= 10) {
            break;
        }
    }

    return $needles;
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
    $wrapRank = '(CASE WHEN LOWER(node_type) IN (\'document\',\'frontmatter\',\'toc\',\'backmatter\') THEN 1 ELSE 0 END)';
    $maxRows = 15;
    $excerptLen = 4500;
    $excerptBody = rl_easa_staging_snippet_concat_body($pdo);
    $fetchMatches = static function (string $needle, int $limit) use ($pdo, $batchFilter, $wrapRank, $excerptBody, $excerptLen): array {
        $m = rl_easa_search_match_clause($pdo, $needle);
        $whereMatch = $m['where'];
        $bind = $m['bind'];
        if ($batchFilter > 0) {
            $sql = "
                SELECT batch_id, node_uid, node_type, source_erules_id, title, breadcrumb,
                       SUBSTRING({$excerptBody}, 1, {$excerptLen}) AS excerpt
                FROM easa_erules_import_nodes_staging
                WHERE batch_id = ? AND {$whereMatch}
                ORDER BY {$wrapRank} ASC, id ASC
                LIMIT {$limit}
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
                LIMIT {$limit}
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($bind);
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    };

    $rows = $fetchMatches($q, $maxRows);
    // Natural-language prompts rarely appear verbatim in legal text; fallback to ids + keywords.
    if ($rows === []) {
        $seen = [];
        foreach (rl_easa_compare_query_needles($q) as $needle) {
            $more = $fetchMatches($needle, 8);
            foreach ($more as $r) {
                $key = (string) ($r['batch_id'] ?? '') . '|' . (string) ($r['node_uid'] ?? '');
                if ($key === '|' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $rows[] = $r;
                if (count($rows) >= $maxRows) {
                    break 2;
                }
            }
        }
    }
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

/**
 * Staging search → unique node_uids → full node_detail payloads for AI (canonical regulation text, not snippets only).
 *
 * @return array{
 *   summary: string,
 *   hit_count: int,
 *   sources: list<array<string, mixed>>,
 *   model_bundle: string,
 *   full_nodes: list<array{batch_id: int, node_uid: string, node: array<string, mixed>}>
 * }
 */
function rl_easa_build_ai_canonical_regulatory_bundle(PDO $pdo, string $q, int $batchFilter): array
{
    $stagingCompare = rl_easa_build_compare_staging_bundle($pdo, $q, $batchFilter);
    $out = [
        'summary' => (string) ($stagingCompare['summary'] ?? ''),
        'hit_count' => (int) ($stagingCompare['hit_count'] ?? 0),
        'sources' => is_array($stagingCompare['sources'] ?? null) ? $stagingCompare['sources'] : [],
        'model_bundle' => '',
        'full_nodes' => [],
    ];
    if (!easa_erules_staging_tables_ok($pdo) || $out['hit_count'] === 0) {
        $out['model_bundle'] = '(No staging matches — no full node payloads loaded.)';

        return $out;
    }
    $seen = [];
    $pairs = [];
    foreach ($out['sources'] as $src) {
        if (!is_array($src)) {
            continue;
        }
        $bid = (int) ($src['batch_id'] ?? 0);
        $nuid = trim((string) ($src['node_uid'] ?? ''));
        if ($bid <= 0 || $nuid === '') {
            continue;
        }
        $k = $bid . '|' . $nuid;
        if (isset($seen[$k])) {
            continue;
        }
        $seen[$k] = true;
        $pairs[] = ['batch_id' => $bid, 'node_uid' => $nuid];
        if (count($pairs) >= 8) {
            break;
        }
    }
    $maxTotal = 220000;
    $total = 0;
    $parts = [];
    $idx = 0;
    foreach ($pairs as $pair) {
        $bid = $pair['batch_id'];
        $nuid = $pair['node_uid'];
        $det = rl_easa_api_node_detail_build($pdo, $bid, $nuid);
        if (!$det['ok'] || !is_array($det['node'] ?? null)) {
            continue;
        }
        $node = $det['node'];
        $out['full_nodes'][] = ['batch_id' => $bid, 'node_uid' => $nuid, 'node' => $node];
        $idx++;
        $sb = $node['structured_blocks'] ?? null;
        $sbJson = '';
        if (is_array($sb) && $sb !== []) {
            $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
            if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
                $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
            }
            $enc = json_encode($sb, $flags);
            $sbJson = is_string($enc) ? $enc : '';
            if (strlen($sbJson) > 120000) {
                $sbJson = substr($sbJson, 0, 120000) . "\n… [structured_blocks_json truncated for model context]";
            }
        }
        $canon = trim((string) ($node['canonical_text'] ?? ''));
        $plain = (string) ($node['plain_text'] ?? '');
        if (strlen($plain) > 120000) {
            $plain = substr($plain, 0, 120000) . "\n… [plain_text truncated for model context]";
        }
        if (strlen($canon) > 120000) {
            $canon = substr($canon, 0, 120000) . "\n… [canonical_text truncated for model context]";
        }
        $hdr = sprintf(
            "--- CANONICAL NODE %d ---\nbatch_id=%d\nnode_uid=%s\nERulesId=%s\ntitle_display=%s\nbreadcrumb=%s\nstructured_blocks_json:\n%s\n\ncanonical_text:\n%s\n\nplain_text (effective body field from node_detail):\n%s\n",
            $idx,
            $bid,
            $nuid,
            trim((string) ($node['source_erules_id'] ?? '')),
            trim((string) ($node['title_display'] ?? '')),
            trim((string) ($node['breadcrumb'] ?? '')),
            $sbJson !== '' ? $sbJson : '(none — rely on canonical_text/plain_text)',
            $canon !== '' ? $canon : '(empty in staging row)',
            $plain !== '' ? $plain : '(empty)'
        );
        if ($total + strlen($hdr) > $maxTotal && $parts !== []) {
            $parts[] = '[Further canonical nodes omitted to stay within model context budget.]';
            break;
        }
        $parts[] = $hdr;
        $total += strlen($hdr);
    }
    if ($parts === []) {
        $out['model_bundle'] = 'Staging matched rows but full node_detail could not be loaded for any candidate (check staging integrity).';
    } else {
        $out['model_bundle'] = implode("\n", $parts);
        $out['summary'] .= sprintf(' Loaded %d full node_detail payload(s) for the model.', count($parts));
    }

    return $out;
}

function rl_easa_ai_chat_tables_ok(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM easa_ai_chat_sessions LIMIT 1');
    } catch (Throwable) {
        return false;
    }

    return true;
}

/** GET or POST: EASA AI chat bootstrap (sessions + messages). */
function rl_easa_ai_chat_bootstrap_output(PDO $pdo, int $wantSession): void
{
    $chatSupported = rl_easa_ai_chat_tables_ok($pdo);
    if (!$chatSupported) {
        rl_easa_json_out(200, [
            'ok' => true,
            'chat_supported' => false,
            'chat_migrate_hint' => 'Apply scripts/sql/resource_library_easa_ai_chat.sql to enable persistent EASA AI chat.',
            'sessions' => [],
            'messages' => [],
            'current_session_id' => null,
        ]);
    }
    $u = cw_current_user($pdo);
    $userId = is_array($u) ? (int) ($u['id'] ?? 0) : 0;
    if ($userId <= 0) {
        rl_easa_json_out(401, ['ok' => false, 'error' => 'Not authenticated']);
    }
    try {
        $st = $pdo->prepare('SELECT id, title, created_at, updated_at FROM easa_ai_chat_sessions WHERE user_id = ? ORDER BY updated_at DESC LIMIT 40');
        $st->execute([$userId]);
        $sessions = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        rl_easa_json_out(503, ['ok' => false, 'error' => $e->getMessage()]);
    }
    $current = $wantSession;
    if ($current <= 0 && $sessions !== []) {
        $current = (int) ($sessions[0]['id'] ?? 0);
    }
    $messages = [];
    if ($current > 0) {
        $chk = $pdo->prepare('SELECT id FROM easa_ai_chat_sessions WHERE id = ? AND user_id = ? LIMIT 1');
        $chk->execute([$current, $userId]);
        if ($chk->fetchColumn()) {
            $mst = $pdo->prepare('SELECT id, role, content, response_json, created_at FROM easa_ai_chat_messages WHERE session_id = ? ORDER BY id ASC');
            $mst->execute([$current]);
            $messages = $mst->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } else {
            $current = 0;
        }
    }
    rl_easa_json_out(200, [
        'ok' => true,
        'chat_supported' => true,
        'sessions' => $sessions,
        'messages' => $messages,
        'current_session_id' => $current > 0 ? $current : null,
    ]);
}

/** @return array{answer_markdown: string, primary_references: list<array<string, mixed>>, secondary_references: list<array<string, mixed>>, confidence: string} */
function rl_easa_normalize_ai_json_payload(array $j): array
{
    $md = trim((string) ($j['answer_markdown'] ?? ''));
    $conf = strtolower(trim((string) ($j['confidence'] ?? 'medium')));
    if (!in_array($conf, ['high', 'medium', 'low'], true)) {
        $conf = 'medium';
    }
    $prim = $j['primary_references'] ?? [];
    $sec = $j['secondary_references'] ?? [];
    if (!is_array($prim)) {
        $prim = [];
    }
    if (!is_array($sec)) {
        $sec = [];
    }
    $mapRef = static function (mixed $r): ?array {
        if (!is_array($r)) {
            return null;
        }
        $bid = (int) ($r['batch_id'] ?? 0);
        $nuid = trim((string) ($r['node_uid'] ?? ''));
        if ($bid <= 0 || $nuid === '') {
            return null;
        }
        $eid = trim((string) ($r['erules_id'] ?? $r['source_erules_id'] ?? ''));
        $title = trim((string) ($r['title'] ?? ''));
        $mt = $r['matched_terms'] ?? [];
        if (!is_array($mt)) {
            $mt = [];
        }
        $mt = array_values(array_filter(array_map(static fn($x) => trim((string) $x), $mt), static fn(string $x): bool => $x !== ''));
        $quote = trim((string) ($r['quote'] ?? ''));

        return [
            'title' => $title !== '' ? $title : $nuid,
            'batch_id' => $bid,
            'node_uid' => $nuid,
            'erules_id' => $eid,
            'matched_terms' => $mt,
            'quote' => $quote,
        ];
    };
    $primOut = [];
    foreach ($prim as $r) {
        $m = $mapRef($r);
        if ($m !== null) {
            $primOut[] = $m;
        }
    }
    $secOut = [];
    foreach ($sec as $r) {
        $m = $mapRef($r);
        if ($m !== null) {
            $secOut[] = $m;
        }
    }

    return [
        'answer_markdown' => $md,
        'primary_references' => $primOut,
        'secondary_references' => $secOut,
        'confidence' => $conf,
    ];
}

function rl_easa_parse_ai_compare_response(array $resp): array
{
    try {
        $j = cw_openai_extract_json_text($resp);
        if (!is_array($j)) {
            throw new RuntimeException('non-array');
        }

        return rl_easa_normalize_ai_json_payload($j);
    } catch (Throwable) {
        $t = rl_easa_extract_ai_text($resp);

        return [
            'answer_markdown' => $t,
            'primary_references' => [],
            'secondary_references' => [],
            'confidence' => 'low',
        ];
    }
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

function rl_easa_current_user_first_name(PDO $pdo): string
{
    $u = cw_current_user($pdo);
    if (!is_array($u)) {
        return '';
    }
    $first = trim((string) ($u['first_name'] ?? ''));
    if ($first !== '') {
        return $first;
    }
    $name = trim((string) ($u['name'] ?? ''));
    if ($name !== '') {
        $parts = preg_split('/\s+/u', $name) ?: [];
        if ($parts !== []) {
            return trim((string) $parts[0]);
        }
    }

    return '';
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $action = trim((string) ($_GET['action'] ?? 'status'));

    if ($action === 'storage_health') {
        $batchId = (int) ($_GET['batch_id'] ?? 0);
        $health = easa_erules_storage_health($batchId > 0 ? $batchId : null);
        if ($batchId > 0) {
            try {
                $st = $pdo->prepare('SELECT id, storage_relpath, status, error_message, updated_at FROM easa_erules_import_batches WHERE id = ? LIMIT 1');
                $st->execute([$batchId]);
                $health['batch_row'] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable) {
                $health['batch_row'] = null;
            }
        }
        rl_easa_json_out(200, ['ok' => true, 'health' => $health]);
    }

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
            $nodes = easa_erules_tree_children_response_nodes(
                $pdo,
                $batchId,
                $isRoot ? null : $parentRaw
            );
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
        $res = rl_easa_api_node_detail_build($pdo, $batchId, $nodeUid);
        if (!$res['ok']) {
            rl_easa_json_out($res['http'], ['ok' => false, 'error' => $res['error']]);
        }
        rl_easa_json_out(200, ['ok' => true, 'node' => $res['node']]);
    }

    if ($action === 'easa_ai_chat_bootstrap') {
        rl_easa_ai_chat_bootstrap_output($pdo, (int) ($_GET['session_id'] ?? 0));
    }

    // GET action=source_probe — diagnostic: ERulesId matches in batch source.xml (deploy must include this block).
    if ($action === 'source_probe') {
        if (!easa_erules_staging_tables_ok($pdo)) {
            rl_easa_json_out(503, ['ok' => false, 'error' => 'Apply scripts/sql/resource_library_easa_erules_staging.sql first']);
        }
        $batchId = (int) ($_GET['batch_id'] ?? 0);
        $nodeUid = trim((string) ($_GET['node_uid'] ?? ''));
        $erulesParam = trim((string) ($_GET['erules_id'] ?? ''));
        if ($batchId <= 0) {
            rl_easa_json_out(400, ['ok' => false, 'error' => 'batch_id required']);
        }
        if ($erulesParam === '' && $nodeUid === '') {
            rl_easa_json_out(400, ['ok' => false, 'error' => 'Provide node_uid or erules_id']);
        }
        $batchSummarySql = '
            SELECT id, storage_relpath, status, rows_detected, error_message, parse_phase, updated_at
            FROM easa_erules_import_batches
            WHERE id = ?
            LIMIT 1';
        try {
            $bst = $pdo->prepare($batchSummarySql);
            $bst->execute([$batchId]);
            $batchRow = $bst->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            rl_easa_json_out(503, ['ok' => false, 'error' => $e->getMessage()]);
        }
        $batchStorageRelpath = is_array($batchRow) ? trim((string) ($batchRow['storage_relpath'] ?? '')) : '';

        $stagingSummary = null;
        $probeId = $erulesParam;

        $stagingSelect = '
                SELECT batch_id, node_uid, node_type, source_erules_id, title, path, breadcrumb,
                       CHAR_LENGTH(COALESCE(plain_text, \'\')) AS plain_len,
                       CHAR_LENGTH(COALESCE(canonical_text, \'\')) AS canonical_len,
                       CHAR_LENGTH(COALESCE(xml_fragment, \'\')) AS fragment_len
                FROM easa_erules_import_nodes_staging
                WHERE batch_id = ?';

        try {
            if ($nodeUid !== '') {
                $st = $pdo->prepare($stagingSelect . ' AND node_uid = ? LIMIT 1');
                $st->execute([$batchId, $nodeUid]);
                $stagingSummary = $st->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($stagingSummary !== null && $probeId === '') {
                    $probeId = trim((string) ($stagingSummary['source_erules_id'] ?? ''));
                }
            } elseif ($probeId !== '') {
                $st = $pdo->prepare($stagingSelect . ' AND TRIM(source_erules_id) = ? LIMIT 1');
                $st->execute([$batchId, $probeId]);
                $stagingSummary = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            }
        } catch (Throwable $e) {
            rl_easa_json_out(503, ['ok' => false, 'error' => $e->getMessage()]);
        }

        if ($probeId === '') {
            rl_easa_json_out(404, ['ok' => false, 'error' => 'No ERulesId resolved for probe (node missing erules id or staging row not found).']);
        }

        $resolvedAbs = easa_erules_batch_source_xml_absolute_path($pdo, $batchId);
        if ($resolvedAbs === null && $batchStorageRelpath !== '') {
            $cand = rl_project_root() . '/' . str_replace('\\', '/', $batchStorageRelpath);
            $resolvedAbs = is_file($cand) ? $cand : null;
        }

        $exists = $resolvedAbs !== null && is_file($resolvedAbs);
        $readable = $exists && is_readable($resolvedAbs);
        $sizeBytes = $exists ? (@filesize($resolvedAbs)) : false;
        $sha256 = $readable ? (@hash_file('sha256', $resolvedAbs)) : false;

        $matches = [];
        $matchCount = 0;
        if ($resolvedAbs !== null && $readable) {
            $probe = easa_erules_probe_source_candidates_by_erules_id($resolvedAbs, $probeId, 80);
            $matches = is_array($probe['matches'] ?? null) ? $probe['matches'] : [];
            $matchCount = (int) ($probe['match_count'] ?? count($matches));
        }

        rl_easa_json_out(200, [
            'ok' => true,
            'batch_id' => $batchId,
            'staging_summary' => $stagingSummary,
            'batch_row' => is_array($batchRow) ? $batchRow : null,
            'batch_storage_relpath' => $batchStorageRelpath !== '' ? $batchStorageRelpath : null,
            'resolved_source_xml_absolute_path' => $resolvedAbs,
            'source_file_exists' => $exists,
            'source_file_readable' => $readable,
            'source_file_size_bytes' => is_int($sizeBytes) ? $sizeBytes : null,
            'source_file_sha256' => is_string($sha256) ? $sha256 : null,
            'probe_erules_id' => $probeId,
            'match_count' => $matchCount,
            'matches' => $matches,
            'storage_health' => easa_erules_storage_health($batchId),
            'project_root' => rl_project_root(),
        ]);
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
        'storage_health' => easa_erules_storage_health(null),
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

if ($action === 'easa_ai_chat_bootstrap') {
    rl_easa_ai_chat_bootstrap_output($pdo, (int) ($data['session_id'] ?? 0));
}

if ($action === 'easa_ai_chat_session_create') {
    if (!rl_easa_ai_chat_tables_ok($pdo)) {
        rl_easa_json_out(503, ['ok' => false, 'error' => 'Apply scripts/sql/resource_library_easa_ai_chat.sql first']);
    }
    $u = cw_current_user($pdo);
    $userId = is_array($u) ? (int) ($u['id'] ?? 0) : 0;
    if ($userId <= 0) {
        rl_easa_json_out(401, ['ok' => false, 'error' => 'Not authenticated']);
    }
    $title = isset($data['title']) ? trim((string) $data['title']) : '';
    $title = $title !== '' ? substr($title, 0, 255) : null;
    try {
        $pdo->prepare('INSERT INTO easa_ai_chat_sessions (user_id, title, created_at, updated_at) VALUES (?, ?, NOW(), NOW())')->execute([$userId, $title]);
        $sid = (int) $pdo->lastInsertId();
    } catch (Throwable $e) {
        rl_easa_json_out(503, ['ok' => false, 'error' => $e->getMessage()]);
    }
    rl_easa_json_out(200, ['ok' => true, 'session_id' => $sid]);
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

    $preflight = easa_erules_storage_health($batchId);
    $batchHealth = is_array($preflight['batch'] ?? null) ? $preflight['batch'] : [];
    $sourceReadable = !empty($batchHealth['source_exists']) && !empty($batchHealth['source_readable']);
    if (!$sourceReadable) {
        $expectedRel = (string) ($batchHealth['expected_relpath'] ?? easa_erules_batch_relative_path($batchId));
        $msg = 'Parser refused to start: source.xml missing/unreadable for batch '
            . $batchId . ' at ' . $expectedRel
            . '. Ensure storage/easa_erules/batches exists/writable, then upload again.';
        $pdo->prepare('UPDATE easa_erules_import_batches SET status = \'failed\', error_message = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([$msg, $batchId]);
        rl_easa_json_out(409, ['ok' => false, 'error' => $msg, 'storage_health' => $preflight]);
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
    $userFirstName = rl_easa_current_user_first_name($pdo);

    $canon = rl_easa_build_ai_canonical_regulatory_bundle($pdo, $q, $compareBatchId);
    $easaCtx = (string) ($canon['summary'] ?? '');
    $easaModelBundle = trim((string) ($canon['model_bundle'] ?? ''));

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

    $chatSupported = rl_easa_ai_chat_tables_ok($pdo);
    $u = cw_current_user($pdo);
    $userId = is_array($u) ? (int) ($u['id'] ?? 0) : 0;
    $sessionId = (int) ($data['session_id'] ?? 0);
    if ($chatSupported && $userId > 0) {
        try {
            if ($sessionId <= 0) {
                $pdo->prepare('INSERT INTO easa_ai_chat_sessions (user_id, title, created_at, updated_at) VALUES (?, NULL, NOW(), NOW())')->execute([$userId]);
                $sessionId = (int) $pdo->lastInsertId();
            } else {
                $chk = $pdo->prepare('SELECT id FROM easa_ai_chat_sessions WHERE id = ? AND user_id = ? LIMIT 1');
                $chk->execute([$sessionId, $userId]);
                if (!$chk->fetchColumn()) {
                    $pdo->prepare('INSERT INTO easa_ai_chat_sessions (user_id, title, created_at, updated_at) VALUES (?, NULL, NOW(), NOW())')->execute([$userId]);
                    $sessionId = (int) $pdo->lastInsertId();
                }
            }
            $pdo->prepare('INSERT INTO easa_ai_chat_messages (session_id, role, content, response_json, created_at) VALUES (?, \'user\', ?, NULL, NOW())')->execute([$sessionId, $q]);
            $pdo->prepare('UPDATE easa_ai_chat_sessions SET updated_at = NOW(), title = COALESCE(title, ?) WHERE id = ? AND user_id = ?')->execute([substr($q, 0, 255), $sessionId, $userId]);
        } catch (Throwable) {
            $sessionId = 0;
        }
    } else {
        $sessionId = 0;
    }

    $payload = [
        'ok' => true,
        'user_first_name' => $userFirstName !== '' ? $userFirstName : null,
        'easa_context_note' => $easaCtx,
        'easa_staging_hits' => (int) ($canon['hit_count'] ?? 0),
        'easa_sources' => is_array($canon['sources'] ?? null) ? $canon['sources'] : [],
        'canonical_nodes_loaded' => is_array($canon['full_nodes'] ?? null) ? count($canon['full_nodes']) : 0,
        'ecfr_html' => $ecfrHtml !== '' ? $ecfrHtml : null,
        'ecfr_note' => $ecfrNote !== '' ? $ecfrNote : null,
        'ai_answer' => '',
        'ai_error' => null,
        'answer_markdown' => '',
        'primary_references' => [],
        'secondary_references' => [],
        'confidence' => 'medium',
        'session_id' => $sessionId > 0 ? $sessionId : null,
        'chat_supported' => $chatSupported && $userId > 0,
    ];

    if (!$useAi) {
        rl_easa_json_out(200, $payload);
    }

    $bundle = "EU / EASA — FULL canonical regulation payloads from this installation (same resolver as GET node_detail). "
        . "Each CANONICAL NODE block includes structured_blocks_json, canonical_text, plain_text, title_display, breadcrumb, batch_id, node_uid, ERulesId. "
        . "Answer ONLY from this material; quote accurately. If blocks are empty or truncated, say so.\n\n";
    if ($easaModelBundle !== '') {
        $bundle .= $easaModelBundle . "\n\n";
    } else {
        $bundle .= "(No canonical node payloads loaded — refer to easa_context_note above.)\n\n";
    }
    if ($ecfrHtml !== '') {
        $strip = preg_replace('/\s+/', ' ', strip_tags($ecfrHtml));
        $strip = is_string($strip) ? trim($strip) : '';
        if (strlen($strip) > 12000) {
            $strip = substr($strip, 0, 12000) . '…';
        }
        $bundle .= "U.S. 14 CFR excerpt (eCFR API, for comparison only):\n" . $strip . "\n\n";
    }

    $jsonInstructions = <<<'TXT'
Your entire model output must be a single JSON object (no markdown fences) with exactly these keys:
- "answer_markdown": string (short structured Markdown answer; traceable statements must name batch_id + node_uid and/or ERulesId from the bundle).
- "primary_references": array of objects, each with: "title", "batch_id" (int), "node_uid" (string), "erules_id" (string, ERules id or empty), "matched_terms" (array of strings), "quote" (short verbatim excerpt from the bundle for that node).
- "secondary_references": same shape as primary_references (optional cross-links).
- "confidence": "high" | "medium" | "low"

Only cite batch_id/node_uid pairs that appear in the CANONICAL NODE blocks. Do not invent ERulesIds.
TXT;

    try {
        $resp = cw_openai_responses([
            'model' => cw_openai_model(),
            'input' => [
                [
                    'role' => 'system',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => "You help aviation compliance staff interpret Easy Access Rules using ONLY the canonical EASA bundles provided. "
                                . $jsonInstructions
                                . " When U.S. text is provided from eCFR, label it as U.S. 14 CFR. Address the user by first name at least once when a first name is provided. Not legal advice; always verify on official EASA publications.",
                        ],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => "User first name:\n" . ($userFirstName !== '' ? $userFirstName : '(unknown)') . "\n\nQuestion:\n" . $q . "\n\nReference bundle:\n" . $bundle,
                        ],
                    ],
                ],
            ],
        ], 180);
        $parsed = rl_easa_parse_ai_compare_response($resp);
        $payload['answer_markdown'] = $parsed['answer_markdown'];
        $payload['primary_references'] = $parsed['primary_references'];
        $payload['secondary_references'] = $parsed['secondary_references'];
        $payload['confidence'] = $parsed['confidence'];
        $payload['ai_answer'] = $parsed['answer_markdown'];
        if ($userFirstName !== '' && $payload['ai_answer'] !== '' && stripos($payload['ai_answer'], $userFirstName) === false) {
            $payload['ai_answer'] = $userFirstName . ', ' . $payload['ai_answer'];
            $payload['answer_markdown'] = $payload['ai_answer'];
        }
        if ($chatSupported && $userId > 0 && $sessionId > 0) {
            $persist = [
                'ok' => true,
                'answer_markdown' => $payload['answer_markdown'],
                'primary_references' => $payload['primary_references'],
                'secondary_references' => $payload['secondary_references'],
                'confidence' => $payload['confidence'],
            ];
            $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
            if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
                $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
            }
            $pj = json_encode($persist, $flags);
            if ($pj === false) {
                $pj = '{"ok":false}';
            }
            try {
                $pdo->prepare('INSERT INTO easa_ai_chat_messages (session_id, role, content, response_json, created_at) VALUES (?, \'assistant\', ?, ?, NOW())')->execute([$sessionId, $payload['answer_markdown'], $pj]);
                $pdo->prepare('UPDATE easa_ai_chat_sessions SET updated_at = NOW() WHERE id = ?')->execute([$sessionId]);
            } catch (Throwable) {
            }
        }
    } catch (Throwable $e) {
        $payload['ai_error'] = $e->getMessage();
    }

    rl_easa_json_out(200, $payload);
}

rl_easa_json_out(400, ['ok' => false, 'error' => 'Unknown action']);
