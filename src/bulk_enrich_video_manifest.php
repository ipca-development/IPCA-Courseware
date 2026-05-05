<?php
declare(strict_types=1);

/**
 * Kings video manifest helpers for bulk enrich + coverage.
 * Only allows JSON files under public/assets/ (no path traversal).
 */

function bec_video_manifest_assets_dir(): string
{
    return realpath(__DIR__ . '/../public/assets') ?: (__DIR__ . '/../public/assets');
}

/**
 * Resolve a client-supplied manifest filename to an absolute path, or null if invalid.
 */
function bec_resolve_video_manifest_file(?string $basename): ?string
{
    $base = trim((string)$basename);
    if ($base === '') {
        $base = 'kings_videos_manifest.json';
    }
    $base = basename(str_replace(["\0", '\\'], '', $base));
    if ($base === '' || !str_ends_with(strtolower($base), '.json')) {
        return null;
    }
    $dir = bec_video_manifest_assets_dir();
    $full = $dir . DIRECTORY_SEPARATOR . $base;
    $real = realpath($full);
    if ($real === false || !is_file($real)) {
        return null;
    }
    $dirReal = realpath($dir);
    if ($dirReal === false) {
        return null;
    }
    $d = rtrim(str_replace('\\', '/', $dirReal), '/');
    $f = str_replace('\\', '/', $real);
    if ($f !== $d && !str_starts_with($f, $d . '/')) {
        return null;
    }

    return $real;
}

/**
 * @return list<string> basenames of *.json in public/assets (non-recursive)
 */
function bec_list_video_manifest_candidates(): array
{
    $dir = bec_video_manifest_assets_dir();
    if (!is_dir($dir)) {
        return [];
    }
    $out = [];
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        if (!str_ends_with(strtolower($name), '.json')) {
            continue;
        }
        $lower = strtolower($name);
        if (
            str_contains($lower, 'video')
            || str_contains($lower, 'manifest')
            || strpos($lower, 'kings_') === 0
        ) {
            $out[] = $name;
        }
    }
    sort($out);

    return $out;
}

/** @param array<mixed,mixed> $arr */
function bec_manifest_is_numeric_keyed_list(array $arr): bool
{
    if ($arr === []) {
        return true;
    }
    $i = 0;
    foreach ($arr as $k => $_) {
        if ($k !== $i) {
            return false;
        }
        ++$i;
    }

    return true;
}

/**
 * @param array<mixed,mixed> $decoded
 * @return list<array<string,mixed>>
 */
function bec_manifest_rows_from_decoded(array $decoded): array
{
    if (bec_manifest_is_numeric_keyed_list($decoded)) {
        return $decoded;
    }
    foreach (['items', 'videos', 'records', 'entries', 'data', 'slides'] as $k) {
        if (
            isset($decoded[$k])
            && is_array($decoded[$k])
            && bec_manifest_is_numeric_keyed_list($decoded[$k])
        ) {
            return $decoded[$k];
        }
    }

    return [];
}

/**
 * @param array<string,mixed> $item
 * @return list<string>
 */
function bec_manifest_collect_urls_from_item(array $item): array
{
    $urls = [];
    foreach (['videoUrls', 'video_urls', 'videos', 'urls'] as $k) {
        if (!isset($item[$k]) || !is_array($item[$k])) {
            continue;
        }
        foreach ($item[$k] as $u) {
            if (is_string($u) && trim($u) !== '') {
                $urls[] = trim($u);
                continue;
            }
            if (is_array($u)) {
                foreach (['url', 'src', 'href', 'videoUrl'] as $nk) {
                    if (isset($u[$nk]) && is_string($u[$nk]) && trim($u[$nk]) !== '') {
                        $urls[] = trim($u[$nk]);
                        break;
                    }
                }
            }
        }
    }
    foreach (['videoUrl', 'video_url', 'url'] as $k) {
        if (isset($item[$k]) && is_string($item[$k]) && trim($item[$k]) !== '') {
            $urls[] = trim($item[$k]);
        }
    }

    return array_values(array_unique($urls));
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<array{lessonId:int,page:int,videoUrls:list<string>}>
 */
function bec_manifest_items_from_rows(array $rows): array
{
    $out = [];
    foreach ($rows as $item) {
        if (!is_array($item)) {
            continue;
        }
        /** @var array<string,mixed> $item */
        $lid = (int)($item['lessonId'] ?? $item['lesson_id'] ?? $item['external_lesson_id'] ?? $item['lessonID'] ?? 0);
        $pgRaw = $item['page'] ?? $item['pageNumber'] ?? $item['page_num'] ?? null;
        if ($pgRaw === null || $pgRaw === '') {
            continue;
        }
        $pg = (int)$pgRaw;
        $urls = bec_manifest_collect_urls_from_item($item);
        $out[] = ['lessonId' => $lid, 'page' => $pg, 'videoUrls' => $urls];
    }

    return $out;
}

/**
 * @return array{items: list<array{lessonId:int,page:int,videoUrls:list<string>}>, json_error: ?string}
 */
function bec_manifest_decode_items(string $raw): array
{
    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['items' => [], 'json_error' => json_last_error_msg()];
    }
    if (!is_array($decoded)) {
        return ['items' => [], 'json_error' => 'JSON root must be an array or object with a list property.'];
    }
    $rows = bec_manifest_rows_from_decoded($decoded);

    return ['items' => bec_manifest_items_from_rows($rows), 'json_error' => null];
}

/**
 * Read + parse manifest with simple mtime cache (coverage hits this per slide).
 *
 * @return array{items: list<array{lessonId:int,page:int,videoUrls:list<string>}>, json_error: ?string}
 */
function bec_manifest_parse_file_cached(string $manifestPath): array
{
    static $stPath = '';
    static $stMtime = -1;
    /** @var array{items:list,json_error:?string} $stVal */
    static $stVal = ['items' => [], 'json_error' => null];

    if ($manifestPath === '' || !is_file($manifestPath)) {
        return ['items' => [], 'json_error' => null];
    }
    $mt = (int)@filemtime($manifestPath);
    if ($stPath === $manifestPath && $stMtime === $mt) {
        return $stVal;
    }
    $stPath = $manifestPath;
    $stMtime = $mt;
    $raw = file_get_contents($manifestPath) ?: '';
    $stVal = bec_manifest_decode_items($raw);

    return $stVal;
}

/**
 * @return list<array{lessonId:int,page:int,videoUrls:list<string>}>
 */
function bec_manifest_items_from_file(string $manifestPath): array
{
    return bec_manifest_parse_file_cached($manifestPath)['items'];
}

function bec_manifest_has_video_from_file(string $manifestPath, int $extLessonId, int $pageNum): bool
{
    foreach (bec_manifest_items_from_file($manifestPath) as $item) {
        if (
            $item['lessonId'] === $extLessonId
            && $item['page'] === $pageNum
            && count($item['videoUrls']) > 0
        ) {
            return true;
        }
    }

    return false;
}

function bec_read_manifest_video_src(string $manifestPath, int $extLessonId, int $pageNum, string $programKey): string
{
    foreach (bec_manifest_items_from_file($manifestPath) as $item) {
        if ($item['lessonId'] !== $extLessonId || $item['page'] !== $pageNum) {
            continue;
        }
        if (count($item['videoUrls']) === 0) {
            return '';
        }

        $u = (string)$item['videoUrls'][0];
        $base = basename(parse_url($u, PHP_URL_PATH) ?: $u);

        $videosBase = getenv('CW_VIDEOS_BASE') ?: ('ks_videos/' . $programKey);
        $pagePrefix = 'page_' . str_pad((string)$pageNum, 3, '0', STR_PAD_LEFT) . '__';

        return rtrim($videosBase, '/') . '/lesson_' . $extLessonId . '/' . $pagePrefix . $base;
    }

    return '';
}

/**
 * Diagnostics for coverage API (helps debug "no videos recognized").
 *
 * @return array{
 *   resolved_file: ?string,
 *   requested: string,
 *   entry_count: int,
 *   entries_with_video_urls: int,
 *   json_error: ?string,
 *   hint: ?string
 * }
 */
function bec_manifest_coverage_stats(?string $resolvedPath, string $requestedBasename): array
{
    $requestedBasename = trim($requestedBasename);
    if ($requestedBasename === '') {
        $requestedBasename = 'kings_videos_manifest.json';
    }
    if ($resolvedPath === null || $resolvedPath === '') {
        return [
            'resolved_file' => null,
            'requested' => $requestedBasename,
            'entry_count' => 0,
            'entries_with_video_urls' => 0,
            'json_error' => null,
            'hint' => 'Manifest file not found or blocked (must be *.json basename under public/assets/).',
        ];
    }
    $parsed = bec_manifest_parse_file_cached($resolvedPath);
    $items = $parsed['items'];
    $with = 0;
    foreach ($items as $it) {
        if (count($it['videoUrls']) > 0) {
            ++$with;
        }
    }
    $hint = null;
    if ($parsed['json_error'] !== null) {
        $hint = 'Invalid JSON: ' . $parsed['json_error'];
    } elseif ($with === 0 && $items !== []) {
        $hint = 'Rows parsed but no video URLs — check keys (videoUrls, video_url, url, …).';
    } elseif ($items === []) {
        $hint = 'No rows parsed. Expected top-level [...] or { "items": [...] } with lessonId + page + videoUrls.';
    }

    return [
        'resolved_file' => basename($resolvedPath),
        'requested' => $requestedBasename,
        'entry_count' => count($items),
        'entries_with_video_urls' => $with,
        'json_error' => $parsed['json_error'],
        'hint' => $hint,
    ];
}
