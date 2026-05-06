<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/resource_library_storage.php';
require_once __DIR__ . '/../../../src/resource_library_aim.php';
require_once __DIR__ . '/../../../src/resource_library_catalog.php';

cw_require_admin();

@ini_set('upload_max_filesize', '64M');
@ini_set('post_max_size', '64M');

function rl_crawler_api_json(int $code, array $payload): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * @return array<string, mixed>|null
 */
function rl_crawler_api_load_typed_edition(PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $row = rl_catalog_fetch_edition($pdo, $id);
    if (!$row) {
        return null;
    }
    $rt = rl_catalog_normalize_resource_type(isset($row['resource_type']) ? (string) $row['resource_type'] : null);
    if ($rt !== RL_RESOURCE_CRAWLER && $rt !== RL_RESOURCE_API) {
        return null;
    }

    return $row;
}

/**
 * @return array{counts: array<string, int>, last_run: array<string, mixed>|null}
 */
function rl_crawler_api_aim_stats(PDO $pdo, int $editionId): array
{
    $out = [
        'counts' => ['active' => 0, 'superseded' => 0, 'url_broken' => 0, 'total' => 0],
        'last_run' => null,
    ];
    if (!rl_aim_tables_present($pdo) || $editionId <= 0) {
        return $out;
    }
    $scope = rl_aim_paragraphs_where_edition($pdo, $editionId);
    $stmt = $pdo->prepare("
        SELECT citation_status, COUNT(*) AS c
        FROM resource_library_aim_paragraphs
        WHERE {$scope['where']}
        GROUP BY citation_status
    ");
    $stmt->execute($scope['params']);
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $k = (string) ($r['citation_status'] ?? '');
        $n = (int) ($r['c'] ?? 0);
        if (isset($out['counts'][$k])) {
            $out['counts'][$k] = $n;
        }
        $out['counts']['total'] += $n;
    }
    $out['last_run'] = rl_aim_fetch_last_run($pdo, $editionId);

    return $out;
}

/**
 * @return array{reachable: bool, http_code: int, final_url?: string, error?: string, etag?: string|null, last_modified?: string|null}
 */
function rl_crawler_api_probe_url(string $url, int $timeoutSec = 18): array
{
    $p = rl_source_verify_http_probe($url, $timeoutSec);
    $out = [
        'reachable' => (bool) ($p['ok'] ?? false),
        'http_code' => (int) ($p['http_code'] ?? 0),
        'final_url' => (string) ($p['final_url'] ?? ''),
        'error' => isset($p['error']) ? (string) $p['error'] : null,
    ];
    if (isset($p['etag'])) {
        $out['etag'] = (string) $p['etag'];
    } else {
        $out['etag'] = null;
    }
    if (isset($p['last_modified'])) {
        $out['last_modified'] = (string) $p['last_modified'];
    } else {
        $out['last_modified'] = null;
    }
    if (array_key_exists('page_last_updated', $p)) {
        $out['page_last_updated'] = (string) $p['page_last_updated'];
    } else {
        $out['page_last_updated'] = null;
    }
    $out['page_body_fetch_error'] = isset($p['page_body_fetch_error']) ? (string) $p['page_body_fetch_error'] : null;

    return $out;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        rl_crawler_api_json(400, ['ok' => false, 'error' => 'Missing or invalid id']);
    }
    $row = rl_crawler_api_load_typed_edition($pdo, $id);
    if (!$row) {
        rl_crawler_api_json(404, ['ok' => false, 'error' => 'Edition not found or not a crawler/API resource']);
    }
    $rt = rl_catalog_normalize_resource_type(isset($row['resource_type']) ? (string) $row['resource_type'] : null);
    $src = $rt === RL_RESOURCE_CRAWLER ? rl_catalog_crawler_row_as_source($row) : rl_catalog_api_row_as_source($row);
    $extra = rl_catalog_decode_extra(isset($row['extra_config_json']) ? (string) $row['extra_config_json'] : null);
    $slot = (string) ($extra['crawler_slot'] ?? '');
    $ctype = (string) ($extra['crawler_type'] ?? '');
    $stats = ($rt === RL_RESOURCE_CRAWLER && $slot === 'aim' && $ctype === 'aim_html')
        ? rl_crawler_api_aim_stats($pdo, $id)
        : [
            'counts' => ['active' => 0, 'superseded' => 0, 'url_broken' => 0, 'total' => 0],
            'last_run' => null,
        ];
    rl_crawler_api_json(200, [
        'ok' => true,
        'resource_type' => $rt,
        'source' => $src,
        'counts' => $stats['counts'],
        'last_run' => $stats['last_run'],
    ]);
}

if ($method !== 'POST') {
    rl_crawler_api_json(405, ['ok' => false, 'error' => 'Method not allowed']);
}

$contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
$thumbOk = isset($_FILES['thumbnail_image'])
    && is_array($_FILES['thumbnail_image'])
    && (int) ($_FILES['thumbnail_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;

if ($thumbOk) {
    $editionId = (int) ($_POST['edition_id'] ?? $_POST['crawler_source_id'] ?? 0);
    $row = rl_crawler_api_load_typed_edition($pdo, $editionId);
    if (!$row) {
        rl_crawler_api_json(404, ['ok' => false, 'error' => 'Edition not found or not a crawler/API resource']);
    }
    $tmpImg = (string) ($_FILES['thumbnail_image']['tmp_name'] ?? '');
    if ($tmpImg === '' || !is_uploaded_file($tmpImg)) {
        rl_crawler_api_json(400, ['ok' => false, 'error' => 'Invalid image upload']);
    }
    try {
        $publicPath = rl_write_thumbnail_from_tmp($editionId, $tmpImg);
        $stmtImg = $pdo->prepare('UPDATE resource_library_editions SET thumbnail_path = ? WHERE id = ?');
        $stmtImg->execute([$publicPath, $editionId]);
    } catch (Throwable $e) {
        rl_crawler_api_json(400, ['ok' => false, 'error' => $e->getMessage()]);
    }
    $row = rl_catalog_fetch_edition($pdo, $editionId);
    if (!is_array($row)) {
        rl_crawler_api_json(500, ['ok' => false, 'error' => 'Reload failed']);
    }
    $rt = rl_catalog_normalize_resource_type(isset($row['resource_type']) ? (string) $row['resource_type'] : null);
    $src = $rt === RL_RESOURCE_CRAWLER ? rl_catalog_crawler_row_as_source($row) : rl_catalog_api_row_as_source($row);
    $extra = rl_catalog_decode_extra(isset($row['extra_config_json']) ? (string) $row['extra_config_json'] : null);
    $slot = (string) ($extra['crawler_slot'] ?? '');
    $ctype = (string) ($extra['crawler_type'] ?? '');
    $stats = ($rt === RL_RESOURCE_CRAWLER && $slot === 'aim' && $ctype === 'aim_html')
        ? rl_crawler_api_aim_stats($pdo, $editionId)
        : [
            'counts' => ['active' => 0, 'superseded' => 0, 'url_broken' => 0, 'total' => 0],
            'last_run' => null,
        ];
    rl_crawler_api_json(200, [
        'ok' => true,
        'resource_type' => $rt,
        'source' => $src,
        'counts' => $stats['counts'],
        'last_run' => $stats['last_run'],
    ]);
}

if (!str_contains($contentType, 'application/json')) {
    rl_crawler_api_json(415, ['ok' => false, 'error' => 'Expected application/json']);
}

$rawIn = file_get_contents('php://input');
$data = json_decode((string) $rawIn, true);
if (!is_array($data)) {
    rl_crawler_api_json(400, ['ok' => false, 'error' => 'Invalid JSON body']);
}

$action = (string) ($data['action'] ?? '');

if ($action === 'test_url') {
    $id = (int) ($data['id'] ?? 0);
    $row = rl_crawler_api_load_typed_edition($pdo, $id);
    if (!$row) {
        rl_crawler_api_json(404, ['ok' => false, 'error' => 'Edition not found or not a crawler/API resource']);
    }
    $rt = rl_catalog_normalize_resource_type(isset($row['resource_type']) ? (string) $row['resource_type'] : null);
    $extra = rl_catalog_decode_extra(isset($row['extra_config_json']) ? (string) $row['extra_config_json'] : null);
    $testUrl = trim((string) ($data['url'] ?? ''));
    if ($testUrl === '') {
        if ($rt === RL_RESOURCE_CRAWLER) {
            $testUrl = trim((string) ($extra['allowed_url_prefix'] ?? ''));
        } else {
            $testUrl = trim((string) ($extra['api_base_url'] ?? ''));
        }
    }
    $probe = rl_crawler_api_probe_url($testUrl);
    rl_crawler_api_json(200, [
        'ok' => true,
        'reachable' => $probe['reachable'] ?? false,
        'http_code' => $probe['http_code'] ?? 0,
        'final_url' => $probe['final_url'] ?? '',
        'error' => $probe['error'] ?? null,
        'etag' => $probe['etag'] ?? null,
        'last_modified' => $probe['last_modified'] ?? null,
        'page_last_updated' => $probe['page_last_updated'] ?? null,
        'page_body_fetch_error' => $probe['page_body_fetch_error'] ?? null,
    ]);
}

if ($action !== 'save') {
    rl_crawler_api_json(400, ['ok' => false, 'error' => 'Unknown action']);
}

$id = (int) ($data['id'] ?? 0);
$row = rl_crawler_api_load_typed_edition($pdo, $id);
if (!$row) {
    rl_crawler_api_json(404, ['ok' => false, 'error' => 'Edition not found or not a crawler/API resource']);
}
$rt = rl_catalog_normalize_resource_type(isset($row['resource_type']) ? (string) $row['resource_type'] : null);
$extra = rl_catalog_decode_extra(isset($row['extra_config_json']) ? (string) $row['extra_config_json'] : null);

$label = trim((string) ($data['label'] ?? ''));
if ($label === '' || strlen($label) > 512) {
    rl_crawler_api_json(400, ['ok' => false, 'error' => 'Title is required (max 512 characters).']);
}

$changeNum = trim((string) ($data['change_number'] ?? ''));
if (strlen($changeNum) > 128) {
    rl_crawler_api_json(400, ['ok' => false, 'error' => 'Version / change number is too long.']);
}
$revCode = $changeNum === '' ? '' : $changeNum;

$effRaw = trim((string) ($data['effective_date'] ?? ''));
$effDb = null;
if ($effRaw !== '') {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effRaw)) {
        rl_crawler_api_json(400, ['ok' => false, 'error' => 'Effective / revision date must be YYYY-MM-DD or empty.']);
    }
    $effDb = $effRaw;
}

$status = trim((string) ($data['status'] ?? ''));
if ($status === 'active') {
    $status = 'live';
}
if (!in_array($status, ['draft', 'live', 'archived'], true)) {
    rl_crawler_api_json(400, ['ok' => false, 'error' => 'Invalid status (use draft, live, or archived).']);
}

$notes = trim((string) ($data['notes'] ?? ''));

$thumb = trim((string) ($data['thumbnail_path'] ?? ''));
if (strlen($thumb) > 1024) {
    rl_crawler_api_json(400, ['ok' => false, 'error' => 'Thumbnail path is too long.']);
}
$thumbDb = $thumb === '' ? null : $thumb;

if ($rt === RL_RESOURCE_CRAWLER) {
    $prefix = trim((string) ($data['allowed_url_prefix'] ?? ''));
    if ($prefix === '' || strlen($prefix) > 1024) {
        rl_crawler_api_json(400, ['ok' => false, 'error' => 'Main URL / allowed path prefix is required (max 1024 characters).']);
    }
    if (!preg_match('#^https://#i', $prefix)) {
        rl_crawler_api_json(400, ['ok' => false, 'error' => 'Main URL must use HTTPS.']);
    }
    $extra['allowed_url_prefix'] = $prefix;
    $extra['notes'] = $notes;
    if (empty($extra['crawler_slot'])) {
        $extra['crawler_slot'] = 'aim';
    }
    if (empty($extra['crawler_type'])) {
        $extra['crawler_type'] = 'aim_html';
    }
    $mergedSv = rl_source_verify_merge_user_extra($extra, $data);
    if (isset($mergedSv['error'])) {
        rl_crawler_api_json(400, ['ok' => false, 'error' => $mergedSv['error']]);
    }
    $extra = $mergedSv['extra'];
} else {
    $apiUrl = trim((string) ($data['api_base_url'] ?? ''));
    if ($apiUrl !== '' && strlen($apiUrl) > 1024) {
        rl_crawler_api_json(400, ['ok' => false, 'error' => 'API base URL is too long.']);
    }
    if ($apiUrl !== '' && !preg_match('#^https://#i', $apiUrl)) {
        rl_crawler_api_json(400, ['ok' => false, 'error' => 'API base URL must use HTTPS or be left empty.']);
    }
    $extra['api_base_url'] = $apiUrl;
    $extra['notes'] = $notes;
    $mergedSv = rl_source_verify_merge_user_extra($extra, $data);
    if (isset($mergedSv['error'])) {
        rl_crawler_api_json(400, ['ok' => false, 'error' => $mergedSv['error']]);
    }
    $extra = $mergedSv['extra'];
}

try {
    if ($thumbDb === null) {
        rl_delete_thumbnail_files($id);
    }
    $enc = rl_catalog_encode_extra($extra);
    $stmt = $pdo->prepare('
        UPDATE resource_library_editions
        SET title = ?, revision_code = ?, revision_date = ?, status = ?, thumbnail_path = ?, extra_config_json = ?
        WHERE id = ? AND resource_type IN (\'crawler\', \'api\')
    ');
    $stmt->execute([$label, $revCode, $effDb, $status, $thumbDb, $enc, $id]);
} catch (Throwable $e) {
    rl_crawler_api_json(500, ['ok' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}

$row = rl_catalog_fetch_edition($pdo, $id);
if (!is_array($row)) {
    rl_crawler_api_json(500, ['ok' => false, 'error' => 'Reload failed']);
}
$rt = rl_catalog_normalize_resource_type(isset($row['resource_type']) ? (string) $row['resource_type'] : null);
$src = $rt === RL_RESOURCE_CRAWLER ? rl_catalog_crawler_row_as_source($row) : rl_catalog_api_row_as_source($row);
$extra = rl_catalog_decode_extra(isset($row['extra_config_json']) ? (string) $row['extra_config_json'] : null);
$slot = (string) ($extra['crawler_slot'] ?? '');
$ctype = (string) ($extra['crawler_type'] ?? '');
$stats = ($rt === RL_RESOURCE_CRAWLER && $slot === 'aim' && $ctype === 'aim_html')
    ? rl_crawler_api_aim_stats($pdo, $id)
    : [
        'counts' => ['active' => 0, 'superseded' => 0, 'url_broken' => 0, 'total' => 0],
        'last_run' => null,
    ];
rl_crawler_api_json(200, [
    'ok' => true,
    'resource_type' => $rt,
    'source' => $src,
    'counts' => $stats['counts'],
    'last_run' => $stats['last_run'],
]);
