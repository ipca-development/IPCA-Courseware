<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/resource_library_catalog.php';
require_once __DIR__ . '/../../../src/resource_library_pdf.php';
require_once __DIR__ . '/../../../src/resource_library_pdf_import.php';
require_once __DIR__ . '/../../../src/resource_library_ingest.php';

cw_require_admin();

@ini_set('upload_max_filesize', '128M');
@ini_set('post_max_size', '128M');
@set_time_limit(300);

function rl_pdf_api_json(int $code, array $payload): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * @return array<string, mixed>|null
 */
function rl_pdf_api_load_edition(PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $row = rl_catalog_fetch_edition($pdo, $id);
    if (!is_array($row)) {
        return null;
    }
    if (rl_catalog_normalize_resource_type((string) ($row['resource_type'] ?? '')) !== RL_RESOURCE_PDF_BOOK) {
        return null;
    }

    return $row;
}

function rl_pdf_api_current_user_id(PDO $pdo): int
{
    $u = cw_current_user($pdo);

    return is_array($u) ? (int) ($u['id'] ?? 0) : 0;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = trim((string) ($_GET['action'] ?? ''));

if ($method === 'GET') {
    if ($action === 'list_sources') {
        if (!rl_pdf_tables_ok($pdo)) {
            rl_pdf_api_json(503, [
                'ok' => false,
                'error' => 'Apply scripts/sql/resource_library_pdf_crawler.sql first',
                'sources' => [],
            ]);
        }
        $sources = rl_pdf_list_sources($pdo);
        $probe = rl_pdf_pdftotext_probe();
        rl_pdf_api_json(200, [
            'ok' => true,
            'sources' => $sources,
            'pdftotext' => $probe,
            'migrate_hint' => null,
        ]);
    }

    if ($action === 'batch_status') {
        $batchId = (int) ($_GET['batch_id'] ?? 0);
        if ($batchId <= 0) {
            rl_pdf_api_json(400, ['ok' => false, 'error' => 'batch_id required']);
        }
        $st = $pdo->prepare('SELECT * FROM resource_library_pdf_batches WHERE id = ? LIMIT 1');
        $st->execute([$batchId]);
        $batch = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($batch)) {
            rl_pdf_api_json(404, ['ok' => false, 'error' => 'Batch not found']);
        }
        rl_pdf_api_json(200, ['ok' => true, 'batch' => $batch]);
    }

    if ($action === 'list_batches') {
        $editionId = (int) ($_GET['edition_id'] ?? 0);
        if ($editionId <= 0) {
            rl_pdf_api_json(400, ['ok' => false, 'error' => 'edition_id required']);
        }
        if (!rl_pdf_tables_ok($pdo)) {
            rl_pdf_api_json(503, ['ok' => false, 'error' => 'PDF tables missing']);
        }
        $st = $pdo->prepare('
            SELECT * FROM resource_library_pdf_batches
            WHERE edition_id = ?
            ORDER BY id DESC
            LIMIT 50
        ');
        $st->execute([$editionId]);
        rl_pdf_api_json(200, ['ok' => true, 'batches' => $st->fetchAll(PDO::FETCH_ASSOC) ?: []]);
    }

    if ($action === 'list_articles') {
        $batchId = (int) ($_GET['batch_id'] ?? 0);
        if ($batchId <= 0) {
            rl_pdf_api_json(400, ['ok' => false, 'error' => 'batch_id required']);
        }
        $st = $pdo->prepare('
            SELECT article_key, article_title, legal_state, content_hash, sort_order,
                   LEFT(canonical_text, 400) AS excerpt
            FROM resource_library_pdf_articles_staging
            WHERE batch_id = ?
            ORDER BY sort_order ASC, article_key ASC
            LIMIT 500
        ');
        $st->execute([$batchId]);
        rl_pdf_api_json(200, ['ok' => true, 'articles' => $st->fetchAll(PDO::FETCH_ASSOC) ?: []]);
    }

    if ($action === 'view_diff') {
        $batchId = (int) ($_GET['batch_id'] ?? 0);
        if ($batchId <= 0) {
            rl_pdf_api_json(400, ['ok' => false, 'error' => 'batch_id required']);
        }
        $st = $pdo->prepare('
            SELECT article_key, change_type, old_content_hash, new_content_hash, old_excerpt, new_excerpt
            FROM resource_library_pdf_article_diffs
            WHERE batch_id = ?
            ORDER BY FIELD(change_type, \'new\', \'changed\', \'removed\', \'unchanged\'), article_key ASC
        ');
        $st->execute([$batchId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $summary = ['new' => 0, 'changed' => 0, 'removed' => 0, 'unchanged' => 0];
        foreach ($rows as $r) {
            $t = (string) ($r['change_type'] ?? '');
            if (isset($summary[$t])) {
                $summary[$t]++;
            }
        }
        rl_pdf_api_json(200, ['ok' => true, 'diffs' => $rows, 'summary' => $summary]);
    }

    rl_pdf_api_json(400, ['ok' => false, 'error' => 'Unknown GET action']);
}

if ($method !== 'POST') {
    rl_pdf_api_json(405, ['ok' => false, 'error' => 'Method not allowed']);
}

$rawIn = file_get_contents('php://input');
$data = json_decode((string) $rawIn, true);
if (!is_array($data)) {
    rl_pdf_api_json(400, ['ok' => false, 'error' => 'Invalid JSON body']);
}

$action = trim((string) ($data['action'] ?? ''));

if ($action === 'create_source') {
    if (!rl_pdf_tables_ok($pdo)) {
        rl_pdf_api_json(503, ['ok' => false, 'error' => 'Apply scripts/sql/resource_library_pdf_crawler.sql first']);
    }
    try {
        $defaults = rl_catalog_creation_defaults('pdf_book');
    } catch (InvalidArgumentException $e) {
        rl_pdf_api_json(400, ['ok' => false, 'error' => $e->getMessage()]);
    }
    $title = trim((string) ($data['title'] ?? $defaults['title']));
    if ($title === '') {
        rl_pdf_api_json(400, ['ok' => false, 'error' => 'Title is required']);
    }
    $url = rl_source_verify_sanitize_verify_url_input((string) ($data['official_pdf_url'] ?? ''));
    $urlErr = rl_pdf_validate_official_url($url);
    if ($urlErr !== null) {
        rl_pdf_api_json(400, ['ok' => false, 'error' => $urlErr]);
    }
    $extra = is_array($defaults['extra']) ? $defaults['extra'] : [];
    $extra['official_pdf_url'] = $url;
    $extra['source_verify_url'] = trim((string) ($data['source_verify_url'] ?? ''));
    if ($extra['source_verify_url'] === '') {
        $extra['source_verify_url'] = $url;
    }
    $extra['source_authority'] = trim((string) ($data['source_authority'] ?? ''));
    $extra['jurisdiction'] = trim((string) ($data['jurisdiction'] ?? ''));
    $extra['language'] = trim((string) ($data['language'] ?? ''));
    $extra['notes'] = trim((string) ($data['notes'] ?? ''));
    $extra['source_verify_interval'] = rl_source_verify_normalize_interval((string) ($data['source_verify_interval'] ?? 'weekly'));
    $extra['applicability_tags'] = rl_pdf_normalize_applicability_tags($data['applicability_tags'] ?? []);
    $status = trim((string) ($data['status'] ?? 'draft'));
    if (!in_array($status, ['draft', 'live', 'archived'], true)) {
        $status = 'draft';
    }
    try {
        $newId = rl_catalog_insert_edition(
            $pdo,
            RL_RESOURCE_PDF_BOOK,
            $title,
            trim((string) ($data['revision_code'] ?? '')),
            null,
            $status,
            null,
            null,
            $extra
        );
    } catch (Throwable $e) {
        rl_pdf_api_json(500, ['ok' => false, 'error' => $e->getMessage()]);
    }
    $row = rl_pdf_api_load_edition($pdo, $newId);
    if (!is_array($row)) {
        rl_pdf_api_json(500, ['ok' => false, 'error' => 'Reload failed']);
    }
    $sum = rl_pdf_build_source_summary($pdo, $row);
    rl_pdf_api_json(201, [
        'ok' => true,
        'id' => $newId,
        'source' => $sum['source'],
        'display_status' => $sum['display_status'],
    ]);
}

if ($action === 'update_source') {
    $id = (int) ($data['id'] ?? 0);
    $row = rl_pdf_api_load_edition($pdo, $id);
    if (!is_array($row)) {
        rl_pdf_api_json(404, ['ok' => false, 'error' => 'PDF source not found']);
    }
    $title = trim((string) ($data['title'] ?? ''));
    if ($title === '' || strlen($title) > 512) {
        rl_pdf_api_json(400, ['ok' => false, 'error' => 'Title is required (max 512 characters)']);
    }
    $extra = rl_catalog_decode_extra(isset($row['extra_config_json']) ? (string) $row['extra_config_json'] : null);
    $prevUrl = trim((string) ($extra['official_pdf_url'] ?? ''));
    if (array_key_exists('official_pdf_url', $data)) {
        $url = rl_source_verify_sanitize_verify_url_input((string) $data['official_pdf_url']);
        $urlErr = rl_pdf_validate_official_url($url);
        if ($urlErr !== null) {
            rl_pdf_api_json(400, ['ok' => false, 'error' => $urlErr]);
        }
        $extra['official_pdf_url'] = $url;
        if ($url !== $prevUrl) {
            $extra['source_verify_state'] = [];
            $extra['pdf_monitor_state'] = [];
        }
    }
    if (array_key_exists('source_authority', $data)) {
        $extra['source_authority'] = trim((string) $data['source_authority']);
    }
    if (array_key_exists('jurisdiction', $data)) {
        $extra['jurisdiction'] = trim((string) $data['jurisdiction']);
    }
    if (array_key_exists('language', $data)) {
        $extra['language'] = trim((string) $data['language']);
    }
    if (array_key_exists('notes', $data)) {
        $extra['notes'] = trim((string) $data['notes']);
    }
    if (array_key_exists('applicability_tags', $data)) {
        $extra['applicability_tags'] = rl_pdf_normalize_applicability_tags($data['applicability_tags']);
    }
    $mergedSv = rl_source_verify_merge_user_extra($extra, $data);
    if (isset($mergedSv['error'])) {
        rl_pdf_api_json(400, ['ok' => false, 'error' => $mergedSv['error']]);
    }
    $extra = $mergedSv['extra'];
    $status = trim((string) ($data['status'] ?? (string) ($row['status'] ?? 'draft')));
    if ($status === 'active') {
        $status = 'live';
    }
    if (!in_array($status, ['draft', 'live', 'archived'], true)) {
        rl_pdf_api_json(400, ['ok' => false, 'error' => 'Invalid status']);
    }
    $revCode = trim((string) ($data['revision_code'] ?? (string) ($row['revision_code'] ?? '')));
    if (strlen($revCode) > 128) {
        rl_pdf_api_json(400, ['ok' => false, 'error' => 'Revision code too long']);
    }
    try {
        $pdo->prepare('
            UPDATE resource_library_editions
            SET title = ?, revision_code = ?, status = ?, extra_config_json = ?
            WHERE id = ? AND resource_type = ?
        ')->execute([
            $title,
            $revCode,
            $status,
            rl_catalog_encode_extra($extra),
            $id,
            RL_RESOURCE_PDF_BOOK,
        ]);
    } catch (Throwable $e) {
        rl_pdf_api_json(500, ['ok' => false, 'error' => $e->getMessage()]);
    }
    $row2 = rl_pdf_api_load_edition($pdo, $id);
    if (!is_array($row2)) {
        rl_pdf_api_json(500, ['ok' => false, 'error' => 'Reload failed']);
    }
    $sum = rl_pdf_build_source_summary($pdo, $row2);
    rl_pdf_api_json(200, [
        'ok' => true,
        'source' => $sum['source'],
        'display_status' => $sum['display_status'],
        'ready_batch_id' => $sum['ready_batch_id'],
    ]);
}

if ($action === 'test_url') {
    $testUrl = rl_source_verify_sanitize_verify_url_input(trim((string) ($data['url'] ?? '')));
    if ($testUrl === '') {
        $id = (int) ($data['id'] ?? 0);
        $row = rl_pdf_api_load_edition($pdo, $id);
        if (is_array($row)) {
            $ex = rl_catalog_decode_extra((string) ($row['extra_config_json'] ?? ''));
            $testUrl = trim((string) ($ex['official_pdf_url'] ?? ''));
        }
    }
    $err = rl_pdf_validate_official_url($testUrl);
    if ($err !== null) {
        rl_pdf_api_json(400, ['ok' => false, 'error' => $err]);
    }
    $probe = rl_source_verify_http_probe_headers($testUrl, 25);
    rl_pdf_api_json(200, [
        'ok' => true,
        'reachable' => (bool) ($probe['ok'] ?? false),
        'http_code' => (int) ($probe['http_code'] ?? 0),
        'final_url' => (string) ($probe['final_url'] ?? ''),
        'error' => $probe['error'] ?? null,
        'etag' => $probe['etag'] ?? null,
        'last_modified' => $probe['last_modified'] ?? null,
        'content_length' => $probe['content_length'] ?? null,
    ]);
}

if ($action === 'check_now') {
    $id = (int) ($data['id'] ?? 0);
    $force = !empty($data['force']);
    if ($id <= 0) {
        rl_pdf_api_json(400, ['ok' => false, 'error' => 'id required']);
    }
    try {
        $res = rl_pdf_check_now($pdo, $id, $force);
        $row = rl_pdf_api_load_edition($pdo, $id);
        $sum = is_array($row) ? rl_pdf_build_source_summary($pdo, $row) : null;
        rl_pdf_api_json(200, array_merge(['ok' => true], $res, ['display_status' => $sum['display_status'] ?? null]));
    } catch (Throwable $e) {
        rl_pdf_api_json(400, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

if ($action === 'parse_batch') {
    $batchId = (int) ($data['batch_id'] ?? 0);
    if ($batchId <= 0) {
        rl_pdf_api_json(400, ['ok' => false, 'error' => 'batch_id required']);
    }
    try {
        $res = rl_pdf_parse_batch($pdo, $batchId);
        rl_pdf_api_json(200, ['ok' => true, 'batch_id' => $batchId, 'articles' => $res['articles'], 'diff' => $res['diff']]);
    } catch (Throwable $e) {
        rl_pdf_api_json(400, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

if ($action === 'publish_batch') {
    $batchId = (int) ($data['batch_id'] ?? 0);
    $setLive = !array_key_exists('set_live', $data) || !empty($data['set_live']);
    if ($batchId <= 0) {
        rl_pdf_api_json(400, ['ok' => false, 'error' => 'batch_id required']);
    }
    $st = $pdo->prepare('SELECT edition_id FROM resource_library_pdf_batches WHERE id = ? LIMIT 1');
    $st->execute([$batchId]);
    $editionId = (int) $st->fetchColumn();
    if ($editionId <= 0) {
        rl_pdf_api_json(404, ['ok' => false, 'error' => 'Batch not found']);
    }
    try {
        $uid = rl_pdf_api_current_user_id($pdo);
        $pub = rl_pdf_publish_batch($pdo, $batchId, $uid);
        if ($setLive) {
            $pdo->prepare("UPDATE resource_library_editions SET status = 'live' WHERE id = ? AND resource_type = ?")
                ->execute([$editionId, RL_RESOURCE_PDF_BOOK]);
        }
        $blocks = rl_blocks_stats($pdo, $editionId);
        $row = rl_pdf_api_load_edition($pdo, $editionId);
        $sum = is_array($row) ? rl_pdf_build_source_summary($pdo, $row) : null;
        rl_pdf_api_json(200, [
            'ok' => true,
            'blocks' => $pub['blocks'],
            'sha256' => $pub['sha256'],
            'block_stats' => $blocks,
            'display_status' => $sum['display_status'] ?? 'live',
        ]);
    } catch (Throwable $e) {
        rl_pdf_api_json(400, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

if ($action === 'reject_batch') {
    $batchId = (int) ($data['batch_id'] ?? 0);
    if ($batchId <= 0) {
        rl_pdf_api_json(400, ['ok' => false, 'error' => 'batch_id required']);
    }
    try {
        rl_pdf_reject_batch($pdo, $batchId, rl_pdf_api_current_user_id($pdo));
        rl_pdf_api_json(200, ['ok' => true, 'batch_id' => $batchId]);
    } catch (Throwable $e) {
        rl_pdf_api_json(400, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

rl_pdf_api_json(400, ['ok' => false, 'error' => 'Unknown action']);
