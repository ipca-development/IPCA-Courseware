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

function bec_manifest_has_video_from_file(string $manifestPath, int $extLessonId, int $pageNum): bool
{
    if ($manifestPath === '' || !is_file($manifestPath)) {
        return false;
    }
    $raw = file_get_contents($manifestPath);
    $arr = json_decode($raw ?: '', true);
    if (!is_array($arr)) {
        return false;
    }
    foreach ($arr as $item) {
        $lid = (int)($item['lessonId'] ?? 0);
        $pg = (int)($item['page'] ?? 0);
        if ($lid !== $extLessonId || $pg !== $pageNum) {
            continue;
        }
        $urls = $item['videoUrls'] ?? [];

        return is_array($urls) && count($urls) > 0;
    }

    return false;
}

function bec_read_manifest_video_src(string $manifestPath, int $extLessonId, int $pageNum, string $programKey): string
{
    if ($manifestPath === '' || !is_file($manifestPath)) {
        return '';
    }
    $raw = file_get_contents($manifestPath);
    $arr = json_decode($raw ?: '', true);
    if (!is_array($arr)) {
        return '';
    }

    foreach ($arr as $item) {
        $lid = (int)($item['lessonId'] ?? 0);
        $pg = (int)($item['page'] ?? 0);

        if ($lid === $extLessonId && $pg === $pageNum) {
            $urls = $item['videoUrls'] ?? [];
            if (!is_array($urls) || count($urls) === 0) {
                return '';
            }

            $u = (string)$urls[0];
            $base = basename(parse_url($u, PHP_URL_PATH) ?: $u);

            $videosBase = getenv('CW_VIDEOS_BASE') ?: ('ks_videos/' . $programKey);
            $pagePrefix = 'page_' . str_pad((string)$pageNum, 3, '0', STR_PAD_LEFT) . '__';

            return rtrim($videosBase, '/') . '/lesson_' . $extLessonId . '/' . $pagePrefix . $base;
        }
    }

    return '';
}
