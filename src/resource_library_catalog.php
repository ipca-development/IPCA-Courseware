<?php
declare(strict_types=1);

require_once __DIR__ . '/resource_library_storage.php';

/** @var non-empty-string */
const RL_RESOURCE_JSON_BOOK = 'json_book';

/** @var non-empty-string */
const RL_RESOURCE_CRAWLER = 'crawler';

/** @var non-empty-string */
const RL_RESOURCE_API = 'api';

require_once __DIR__ . '/resource_library_source_verify.php';

function rl_catalog_has_resource_type_column(PDO $pdo): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    try {
        $stmt = $pdo->prepare('SHOW COLUMNS FROM resource_library_editions LIKE ?');
        $stmt->execute(['resource_type']);
        $cache = (bool) $stmt->fetchColumn();
    } catch (Throwable) {
        $cache = false;
    }

    return $cache;
}

function rl_catalog_normalize_resource_type(?string $t): string
{
    $t = strtolower(trim((string) $t));
    if ($t === RL_RESOURCE_CRAWLER || $t === RL_RESOURCE_API) {
        return $t;
    }

    return RL_RESOURCE_JSON_BOOK;
}

/**
 * @return array<string, mixed>
 */
function rl_catalog_decode_extra(?string $json): array
{
    if ($json === null || trim($json) === '') {
        return [];
    }
    try {
        $d = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return [];
    }

    return is_array($d) ? $d : [];
}

function rl_catalog_encode_extra(array $data): string
{
    $enc = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($enc === false) {
        return '{}';
    }

    return $enc;
}

/**
 * @return array<string, mixed>|null
 */
function rl_catalog_fetch_edition(PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM resource_library_editions WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/**
 * @return list<array<string, mixed>>
 */
function rl_catalog_fetch_editions_by_type(PDO $pdo, string $resourceType): array
{
    if (!rl_catalog_has_resource_type_column($pdo)) {
        return $resourceType === RL_RESOURCE_JSON_BOOK
            ? rl_catalog_fetch_all_editions_legacy_json_only($pdo)
            : [];
    }
    $rt = rl_catalog_normalize_resource_type($resourceType);
    if ($rt === RL_RESOURCE_JSON_BOOK) {
        $stmt = $pdo->query("
            SELECT id, title, revision_code, revision_date, status, thumbnail_path, work_code, sort_order,
                   resource_type, extra_config_json, created_at, updated_at
            FROM resource_library_editions
            WHERE COALESCE(NULLIF(TRIM(resource_type), ''), 'json_book') = 'json_book'
            ORDER BY FIELD(status, 'live', 'draft', 'archived'), sort_order ASC, title ASC, id ASC
        ");
    } else {
        $stmt = $pdo->prepare('
            SELECT id, title, revision_code, revision_date, status, thumbnail_path, work_code, sort_order,
                   resource_type, extra_config_json, created_at, updated_at
            FROM resource_library_editions
            WHERE resource_type = ?
            ORDER BY FIELD(status, \'live\', \'draft\', \'archived\'), sort_order ASC, title ASC, id ASC
        ');
        $stmt->execute([$rt]);
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return is_array($rows) ? $rows : [];
}

/**
 * @return list<array<string, mixed>>
 */
function rl_catalog_fetch_all_editions_legacy_json_only(PDO $pdo): array
{
    $stmt = $pdo->query('
        SELECT id, title, revision_code, revision_date, status, thumbnail_path, work_code, sort_order, created_at, updated_at
        FROM resource_library_editions
        ORDER BY FIELD(status, \'live\', \'draft\', \'archived\'), sort_order ASC, title ASC, id ASC
    ');

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Crawler edition for a slot (e.g. aim) stored in extra_config_json.crawler_slot.
 *
 * @return array<string, mixed>|null
 */
function rl_catalog_fetch_crawler_edition_by_slot(PDO $pdo, string $slot): ?array
{
    if (!rl_catalog_has_resource_type_column($pdo)) {
        return null;
    }
    $slot = strtolower(trim($slot));
    if ($slot === '') {
        return null;
    }
    $stmt = $pdo->prepare("
        SELECT *
        FROM resource_library_editions
        WHERE resource_type = 'crawler'
          AND JSON_UNQUOTE(JSON_EXTRACT(extra_config_json, '$.crawler_slot')) = ?
        ORDER BY id ASC
        LIMIT 1
    ");
    $stmt->execute([$slot]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/**
 * Flatten an editions row (crawler) into the legacy "source" shape used by admin JS.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function rl_catalog_crawler_row_as_source(array $row): array
{
    $extra = rl_catalog_decode_extra(isset($row['extra_config_json']) ? (string) $row['extra_config_json'] : null);
    $st = (string) ($row['status'] ?? 'draft');
    if ($st === 'active') {
        $st = 'live';
    }

    $svState = $extra['source_verify_state'] ?? [];

    return [
        'id' => (int) ($row['id'] ?? 0),
        'label' => (string) ($row['title'] ?? ''),
        'allowed_url_prefix' => (string) ($extra['allowed_url_prefix'] ?? ''),
        'change_number' => (string) ($row['revision_code'] ?? ''),
        'effective_date' => $row['revision_date'] ?? null,
        'status' => $st,
        'notes' => (string) ($extra['notes'] ?? ''),
        'thumbnail_path' => $row['thumbnail_path'] ?? null,
        'crawler_slot' => (string) ($extra['crawler_slot'] ?? ''),
        'crawler_type' => (string) ($extra['crawler_type'] ?? ''),
        'resource_type' => rl_catalog_normalize_resource_type(isset($row['resource_type']) ? (string) $row['resource_type'] : null),
        'work_code' => (string) ($row['work_code'] ?? ''),
        'source_verify_url' => trim((string) ($extra['source_verify_url'] ?? '')),
        'source_verify_interval' => rl_source_verify_normalize_interval((string) ($extra['source_verify_interval'] ?? 'off')),
        'source_verify_state' => is_array($svState) ? $svState : [],
    ];
}

/**
 * Flatten API edition row for admin JS.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function rl_catalog_api_row_as_source(array $row): array
{
    $extra = rl_catalog_decode_extra(isset($row['extra_config_json']) ? (string) $row['extra_config_json'] : null);
    $st = (string) ($row['status'] ?? 'draft');
    if ($st === 'active') {
        $st = 'live';
    }
    $svState = $extra['source_verify_state'] ?? [];

    return [
        'id' => (int) ($row['id'] ?? 0),
        'label' => (string) ($row['title'] ?? ''),
        'api_base_url' => (string) ($extra['api_base_url'] ?? ''),
        'change_number' => (string) ($row['revision_code'] ?? ''),
        'effective_date' => $row['revision_date'] ?? null,
        'status' => $st,
        'notes' => (string) ($extra['notes'] ?? ''),
        'thumbnail_path' => $row['thumbnail_path'] ?? null,
        'resource_type' => RL_RESOURCE_API,
        'work_code' => (string) ($row['work_code'] ?? ''),
        'source_verify_url' => trim((string) ($extra['source_verify_url'] ?? '')),
        'source_verify_interval' => rl_source_verify_normalize_interval((string) ($extra['source_verify_interval'] ?? 'off')),
        'source_verify_state' => is_array($svState) ? $svState : [],
    ];
}

/**
 * Cover image URL for any edition row (uses on-disk thumb under storage/resource_library/{id}/ when path empty).
 *
 * @param array<string, mixed> $editionRow
 */
function rl_catalog_edition_thumb_src(array $editionRow): string
{
    $id = (int) ($editionRow['id'] ?? 0);
    $p = trim((string) ($editionRow['thumbnail_path'] ?? ''));
    if ($p !== '') {
        if (str_starts_with($p, 'http://') || str_starts_with($p, 'https://')) {
            return $p;
        }
        if ($p[0] !== '/') {
            return '/' . $p;
        }

        return $p;
    }
    if ($id > 0 && rl_thumbnail_disk_file($id)) {
        return '/admin/resource_library_thumb.php?id=' . $id;
    }

    return '/assets/icons/documents.svg';
}
