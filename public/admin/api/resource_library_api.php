<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/resource_library_storage.php';
require_once __DIR__ . '/../../../src/resource_library_ingest.php';
require_once __DIR__ . '/../../../src/resource_library_catalog.php';

cw_require_admin();

@ini_set('upload_max_filesize', '64M');
@ini_set('post_max_size', '64M');
@ini_set('memory_limit', '512M');
@set_time_limit(180);

const RL_JSON_MAX_BYTES = 52428800; // 50 MiB cap before decode

/**
 * @return array<string, mixed>|null
 */
function rl_api_load_edition(PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $row = rl_catalog_fetch_edition($pdo, $id);
    if (!is_array($row)) {
        return null;
    }
    if (rl_catalog_has_resource_type_column($pdo)) {
        $rt = rl_catalog_normalize_resource_type(isset($row['resource_type']) ? (string) $row['resource_type'] : null);
        if ($rt !== RL_RESOURCE_JSON_BOOK) {
            return null;
        }
    }

    $out = [
        'id' => (int) ($row['id'] ?? 0),
        'title' => (string) ($row['title'] ?? ''),
        'revision_code' => (string) ($row['revision_code'] ?? ''),
        'revision_date' => $row['revision_date'] ?? null,
        'status' => (string) ($row['status'] ?? ''),
        'thumbnail_path' => $row['thumbnail_path'] ?? null,
        'work_code' => $row['work_code'] ?? null,
        'sort_order' => (int) ($row['sort_order'] ?? 0),
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
    ];
    // Use array_key_exists: column may exist with NULL (isset() is false for null).
    if (array_key_exists('extra_config_json', $row)) {
        $raw = $row['extra_config_json'];
        $ex = rl_catalog_decode_extra($raw !== null && $raw !== '' ? (string) $raw : null);
        $svState = $ex['source_verify_state'] ?? [];
        $out['source_verify_url'] = trim((string) ($ex['source_verify_url'] ?? ''));
        $out['source_verify_interval'] = rl_source_verify_normalize_interval((string) ($ex['source_verify_interval'] ?? 'off'));
        $out['source_verify_state'] = is_array($svState) ? $svState : [];
    }

    return $out;
}

/**
 * WHERE fragment: only json_book rows when resource_type column exists.
 */
function rl_api_json_book_where(PDO $pdo): string
{
    if (!rl_catalog_has_resource_type_column($pdo)) {
        return '';
    }

    return " AND COALESCE(NULLIF(TRIM(resource_type), ''), 'json_book') = 'json_book'";
}

function rl_api_json_out(int $code, array $payload): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function rl_api_upload_err_text(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_OK => 'OK',
        UPLOAD_ERR_INI_SIZE => 'File exceeds server upload limit',
        UPLOAD_ERR_FORM_SIZE => 'File exceeds form limit',
        UPLOAD_ERR_PARTIAL => 'Partial upload',
        UPLOAD_ERR_NO_FILE => 'No file received',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temp directory',
        UPLOAD_ERR_CANT_WRITE => 'Cannot write temp file',
        UPLOAD_ERR_EXTENSION => 'Blocked by extension',
        default => 'Upload error ' . $code,
    };
}

function rl_api_normalize_json_string(string $raw): string
{
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        return substr($raw, 3);
    }

    return $raw;
}

function rl_api_validate_and_encode_uploaded_json(string $raw): string
{
    $raw = rl_api_normalize_json_string($raw);
    $len = strlen($raw);
    if ($len > RL_JSON_MAX_BYTES) {
        throw new RuntimeException('JSON file is too large (max 50 MB)');
    }
    if ($len === 0) {
        throw new RuntimeException('Empty file');
    }

    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
        throw new RuntimeException('Invalid JSON: ' . $e->getMessage());
    }

    if (!is_array($decoded)) {
        throw new RuntimeException('JSON root must be an object or array');
    }

    $blocks = rl_normalize_resource_library_source_blocks($decoded);
    if ($blocks === []) {
        throw new RuntimeException('JSON contains no importable blocks (use a block array, or chapters[].blocks, or top-level blocks).');
    }

    $out = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($out === false) {
        throw new RuntimeException('Could not serialize JSON');
    }

    return $out;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $id = (int)($_GET['id'] ?? 0);
    $edition = rl_api_load_edition($pdo, $id);
    if (!$edition) {
        rl_api_json_out(404, ['ok' => false, 'error' => 'Edition not found']);
    }

    if (isset($_GET['download']) && (string)$_GET['download'] === '1') {
        $path = rl_source_json_path($id);
        if (!is_file($path)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'No source JSON uploaded for this edition.';
            exit;
        }
        $title = (string)($edition['title'] ?? 'edition');
        $slug = preg_replace('/[^a-zA-Z0-9_-]+/', '_', substr($title, 0, 80));
        $slug = trim($slug, '_') ?: 'edition';
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="resource-library-' . $id . '-' . $slug . '.json"');
        header('Content-Length: ' . (string)filesize($path));
        readfile($path);
        exit;
    }

    $stat = rl_source_stat($id);
    $blocks = rl_blocks_stats($pdo, $id);
    rl_api_json_out(200, [
        'ok' => true,
        'edition' => $edition,
        'source' => $stat,
        'blocks' => $blocks,
    ]);
}

if ($method !== 'POST') {
    rl_api_json_out(405, ['ok' => false, 'error' => 'Method not allowed']);
}

$contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
$hasJsonUpload = isset($_FILES['source_json']) && is_array($_FILES['source_json']);
$hasThumbUpload = isset($_FILES['thumbnail_image']) && is_array($_FILES['thumbnail_image']);

if ($hasJsonUpload || $hasThumbUpload || str_contains($contentType, 'multipart/form-data')) {
    $editionId = (int)($_POST['edition_id'] ?? 0);
    $edition = rl_api_load_edition($pdo, $editionId);
    if (!$edition) {
        rl_api_json_out(404, ['ok' => false, 'error' => 'Edition not found']);
    }

    if ($hasThumbUpload) {
        $terr = (int)$_FILES['thumbnail_image']['error'];
        if ($terr !== UPLOAD_ERR_OK && $terr !== UPLOAD_ERR_NO_FILE) {
            rl_api_json_out(400, ['ok' => false, 'error' => 'Cover image: ' . rl_api_upload_err_text($terr)]);
        }
    }
    if ($hasJsonUpload) {
        $jerr = (int)$_FILES['source_json']['error'];
        if ($jerr !== UPLOAD_ERR_OK && $jerr !== UPLOAD_ERR_NO_FILE) {
            rl_api_json_out(400, ['ok' => false, 'error' => 'JSON: ' . rl_api_upload_err_text($jerr)]);
        }
    }

    $didThumb = false;
    $didJson = false;

    if ($hasThumbUpload && (int)$_FILES['thumbnail_image']['error'] === UPLOAD_ERR_OK) {
        $tmpImg = (string)($_FILES['thumbnail_image']['tmp_name'] ?? '');
        if ($tmpImg === '' || !is_uploaded_file($tmpImg)) {
            rl_api_json_out(400, ['ok' => false, 'error' => 'Invalid image upload']);
        }
        try {
            $publicPath = rl_write_thumbnail_from_tmp($editionId, $tmpImg);
            $stmtImg = $pdo->prepare(
                'UPDATE resource_library_editions SET thumbnail_path = ? WHERE id = ?' . rl_api_json_book_where($pdo)
            );
            $stmtImg->execute([$publicPath, $editionId]);
            $didThumb = true;
        } catch (Throwable $e) {
            rl_api_json_out(400, ['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    if ($hasJsonUpload && (int)$_FILES['source_json']['error'] === UPLOAD_ERR_OK) {
        $tmp = (string)($_FILES['source_json']['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            rl_api_json_out(400, ['ok' => false, 'error' => 'Invalid JSON upload']);
        }
        $raw = file_get_contents($tmp);
        if ($raw === false) {
            rl_api_json_out(500, ['ok' => false, 'error' => 'Could not read uploaded file']);
        }
        try {
            $encoded = rl_api_validate_and_encode_uploaded_json($raw);
            rl_write_source_json($editionId, $encoded);
            $didJson = true;
        } catch (Throwable $e) {
            rl_api_json_out(400, ['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    if (!$didThumb && !$didJson) {
        rl_api_json_out(400, [
            'ok' => false,
            'error' => 'No file received. Drop a cover image (JPG/PNG/WEBP) and/or choose a JSON file, then upload.',
        ]);
    }

    $row = rl_api_load_edition($pdo, $editionId);
    rl_api_json_out(200, [
        'ok' => true,
        'edition' => $row,
        'source' => rl_source_stat($editionId),
        'blocks' => rl_blocks_stats($pdo, $editionId),
        'did_thumbnail' => $didThumb,
        'did_source_json' => $didJson,
    ]);
}

if (!str_contains($contentType, 'application/json')) {
    rl_api_json_out(415, ['ok' => false, 'error' => 'Expected application/json']);
}

$rawIn = file_get_contents('php://input');
$data = json_decode((string)$rawIn, true);
if (!is_array($data)) {
    rl_api_json_out(400, ['ok' => false, 'error' => 'Invalid JSON body']);
}

$action = (string)($data['action'] ?? '');

if ($action === 'delete_source') {
    $id = (int)($data['id'] ?? 0);
    $edition = rl_api_load_edition($pdo, $id);
    if (!$edition) {
        rl_api_json_out(404, ['ok' => false, 'error' => 'Edition not found']);
    }
    rl_delete_source_json($id);
    rl_delete_blocks_for_edition($pdo, $id);
    rl_api_json_out(200, [
        'ok' => true,
        'source' => rl_source_stat($id),
        'blocks' => rl_blocks_stats($pdo, $id),
    ]);
}

if ($action === 'test_source_verify') {
    $id = (int) ($data['id'] ?? 0);
    $edition = rl_api_load_edition($pdo, $id);
    if (!$edition) {
        rl_api_json_out(404, ['ok' => false, 'error' => 'Edition not found']);
    }
    $row = rl_catalog_fetch_edition($pdo, $id);
    $extra = is_array($row) ? rl_catalog_decode_extra(isset($row['extra_config_json']) ? (string) $row['extra_config_json'] : null) : [];
    $testUrl = trim((string) ($data['url'] ?? ''));
    if ($testUrl === '') {
        $testUrl = rl_source_verify_resolve_url($extra, RL_RESOURCE_JSON_BOOK);
    }
    if ($testUrl === '') {
        rl_api_json_out(400, ['ok' => false, 'error' => 'Set an official verify URL for this edition, or pass a url in the request.']);
    }
    $testUrl = rl_source_verify_sanitize_verify_url_input($testUrl);
    $probe = rl_source_verify_http_probe($testUrl);
    rl_api_json_out(200, [
        'ok' => true,
        'reachable' => (bool) ($probe['ok'] ?? false),
        'http_code' => (int) ($probe['http_code'] ?? 0),
        'final_url' => (string) ($probe['final_url'] ?? ''),
        'error' => $probe['error'] ?? null,
        'etag' => $probe['etag'] ?? null,
        'last_modified' => $probe['last_modified'] ?? null,
        'page_last_updated' => array_key_exists('page_last_updated', $probe) ? (string) $probe['page_last_updated'] : null,
        'page_body_fetch_error' => $probe['page_body_fetch_error'] ?? null,
    ]);
}

if ($action === 'import_blocks') {
    @set_time_limit(600);
    $id = (int)($data['id'] ?? 0);
    $edition = rl_api_load_edition($pdo, $id);
    if (!$edition) {
        rl_api_json_out(404, ['ok' => false, 'error' => 'Edition not found']);
    }
    try {
        $stats = rl_ingest_blocks_from_source_file($pdo, $id);
    } catch (Throwable $e) {
        rl_api_json_out(400, ['ok' => false, 'error' => $e->getMessage()]);
    }
    $row = rl_api_load_edition($pdo, $id);
    rl_api_json_out(200, [
        'ok' => true,
        'imported' => $stats['imported'],
        'chapter_count' => $stats['chapter_count'],
        'edition' => $row,
        'source' => rl_source_stat($id),
        'blocks' => rl_blocks_stats($pdo, $id),
    ]);
}

if ($action === 'create') {
    if (!rl_catalog_has_resource_type_column($pdo)) {
        rl_api_json_out(400, ['ok' => false, 'error' => 'Apply scripts/sql/resource_library_editions_extend_types.sql so resource_type exists, then retry.']);
    }
    try {
        $def = rl_catalog_creation_defaults('json_book');
    } catch (Throwable $e) {
        rl_api_json_out(500, ['ok' => false, 'error' => $e->getMessage()]);
    }
    $title = trim((string) ($data['title'] ?? $def['title']));
    $revisionCode = trim((string) ($data['revision_code'] ?? $def['revision_code']));
    $revisionDate = trim((string) ($data['revision_date'] ?? (string) $def['revision_date']));
    $workIn = array_key_exists('work_code', $data) ? trim((string) $data['work_code']) : ($def['work_code'] ?? '');
    $workCreate = $workIn === '' ? null : $workIn;
    if ($title === '' || strlen($title) > 512) {
        rl_api_json_out(400, ['ok' => false, 'error' => 'Title is required (max 512 characters).']);
    }
    if ($revisionCode === '' || strlen($revisionCode) > 128) {
        rl_api_json_out(400, ['ok' => false, 'error' => 'Version / revision code is required (max 128 characters).']);
    }
    if ($revisionDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $revisionDate)) {
        rl_api_json_out(400, ['ok' => false, 'error' => 'revision_date must be YYYY-MM-DD']);
    }
    if ($workCreate !== null && strlen($workCreate) > 64) {
        rl_api_json_out(400, ['ok' => false, 'error' => 'Work code is too long']);
    }
    try {
        $newId = rl_catalog_insert_edition(
            $pdo,
            RL_RESOURCE_JSON_BOOK,
            $title,
            $revisionCode,
            $revisionDate,
            'draft',
            $workCreate,
            null,
            is_array($def['extra']) ? $def['extra'] : []
        );
    } catch (Throwable $e) {
        rl_api_json_out(500, ['ok' => false, 'error' => $e->getMessage()]);
    }
    $rowOut = rl_api_load_edition($pdo, $newId);
    if (!$rowOut) {
        rl_api_json_out(500, ['ok' => false, 'error' => 'Created edition could not be loaded']);
    }
    rl_api_json_out(201, [
        'ok' => true,
        'id' => $newId,
        'edition' => $rowOut,
        'source' => rl_source_stat($newId),
        'blocks' => rl_blocks_stats($pdo, $newId),
    ]);
}

if ($action !== 'save') {
    rl_api_json_out(400, ['ok' => false, 'error' => 'Unknown action']);
}

$id = (int)($data['id'] ?? 0);
$edition = rl_api_load_edition($pdo, $id);
if (!$edition) {
    rl_api_json_out(404, ['ok' => false, 'error' => 'Edition not found']);
}

$title = trim((string)($data['title'] ?? ''));
if ($title === '') {
    rl_api_json_out(400, ['ok' => false, 'error' => 'Title is required']);
}
if (strlen($title) > 512) {
    rl_api_json_out(400, ['ok' => false, 'error' => 'Title is too long']);
}

$revisionCode = trim((string)($data['revision_code'] ?? ''));
if ($revisionCode === '') {
    rl_api_json_out(400, ['ok' => false, 'error' => 'Version / revision code is required']);
}
if (strlen($revisionCode) > 128) {
    rl_api_json_out(400, ['ok' => false, 'error' => 'Revision code is too long']);
}

$revisionDate = trim((string)($data['revision_date'] ?? ''));
if ($revisionDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $revisionDate)) {
    rl_api_json_out(400, ['ok' => false, 'error' => 'Revision date must be YYYY-MM-DD']);
}

$status = trim((string)($data['status'] ?? ''));
if (!in_array($status, ['draft', 'live', 'archived'], true)) {
    rl_api_json_out(400, ['ok' => false, 'error' => 'Invalid status']);
}

$workCode = trim((string)($data['work_code'] ?? ''));
if (strlen($workCode) > 64) {
    rl_api_json_out(400, ['ok' => false, 'error' => 'Work code is too long']);
}
$workCodeDb = $workCode === '' ? null : $workCode;

$sortOrder = (int)($data['sort_order'] ?? 0);

$thumb = trim((string)($data['thumbnail_path'] ?? ''));
if (strlen($thumb) > 1024) {
    rl_api_json_out(400, ['ok' => false, 'error' => 'Thumbnail path is too long']);
}
$thumbDb = $thumb === '' ? null : $thumb;

$rowFull = rl_catalog_fetch_edition($pdo, $id);
if (!is_array($rowFull)) {
    rl_api_json_out(500, ['ok' => false, 'error' => 'Reload failed']);
}
$extra = rl_catalog_decode_extra(
    array_key_exists('extra_config_json', $rowFull) && $rowFull['extra_config_json'] !== null && $rowFull['extra_config_json'] !== ''
        ? (string) $rowFull['extra_config_json']
        : null
);
$mergedSv = rl_source_verify_merge_user_extra($extra, $data);
if (isset($mergedSv['error'])) {
    rl_api_json_out(400, ['ok' => false, 'error' => $mergedSv['error']]);
}
$extra = $mergedSv['extra'];
$extraEnc = array_key_exists('extra_config_json', $rowFull) ? rl_catalog_encode_extra($extra) : null;

try {
    if ($thumbDb === null) {
        rl_delete_thumbnail_files($id);
    }
    if ($extraEnc !== null) {
        $stmt = $pdo->prepare('
            UPDATE resource_library_editions
            SET title = ?, revision_code = ?, revision_date = ?, status = ?, thumbnail_path = ?, work_code = ?, sort_order = ?, extra_config_json = ?
            WHERE id = ?
            ' . rl_api_json_book_where($pdo) . '
        ');
        $stmt->execute([
            $title,
            $revisionCode,
            $revisionDate,
            $status,
            $thumbDb,
            $workCodeDb,
            $sortOrder,
            $extraEnc,
            $id,
        ]);
    } else {
        $stmt = $pdo->prepare('
            UPDATE resource_library_editions
            SET title = ?, revision_code = ?, revision_date = ?, status = ?, thumbnail_path = ?, work_code = ?, sort_order = ?
            WHERE id = ?
            ' . rl_api_json_book_where($pdo) . '
        ');
        $stmt->execute([
            $title,
            $revisionCode,
            $revisionDate,
            $status,
            $thumbDb,
            $workCodeDb,
            $sortOrder,
            $id,
        ]);
    }
} catch (Throwable $e) {
    rl_api_json_out(500, ['ok' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}

$row = rl_api_load_edition($pdo, $id);
rl_api_json_out(200, [
    'ok' => true,
    'edition' => $row,
    'source' => rl_source_stat($id),
    'blocks' => rl_blocks_stats($pdo, $id),
]);
