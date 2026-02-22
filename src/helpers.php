<?php
declare(strict_types=1);

/**
 * Escape HTML
 */
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/**
 * Redirect helper
 */
function redirect(string $path): void {
    header('Location: ' . $path);
    exit;
}

/**
 * Slugify helper
 */
function slugify(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
}

/**
 * Available template keys
 */
function template_keys(): array {
    return [
        'TEXT_LEFT_MEDIA_RIGHT',
        'TEXT_SPLIT_TWO_COL',
        'MEDIA_LEFT_TEXT_RIGHT',
        'DUAL_MEDIA_WITH_TOP_TEXT',
        'MEDIA_CENTER_ONLY',
    ];
}

/**
 * Build full CDN URL from relative path
 */
function cdn_url(string $cdnBase, string $relativePath): string {
    return rtrim($cdnBase, '/') . '/' . ltrim($relativePath, '/');
}

/**
 * Build screenshot image path
 *
 * IMPORTANT:
 * This matches your uploaded file structure:
 *
 * ks_images/private/lesson_10002/lesson_10002_page_001.png
 *
 * If your filenames differ, adjust here only.
 */
function image_path_for(string $programKey, int $externalLessonId, int $pageNumber): string {

    // Zero-pad page to 3 digits: 1 → 001
    $page = str_pad((string)$pageNumber, 3, '0', STR_PAD_LEFT);

    return sprintf(
        'ks_images/%s/lesson_%d/lesson_%d_page_%s.png',
        $programKey,
        $externalLessonId,
        $externalLessonId,
        $page
    );
}

/**
 * Optional: Build video path (if you later store relative video paths)
 */
function video_path_for(string $programKey, int $externalLessonId, int $pageNumber, string $filename): string {

    return sprintf(
        'ks_videos/%s/lesson_%d/%s',
        $programKey,
        $externalLessonId,
        $filename
    );
}
