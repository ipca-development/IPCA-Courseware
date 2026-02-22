<?php
declare(strict_types=1);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function require_method(string $method): void {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== strtoupper($method)) {
        http_response_code(405);
        exit('Method Not Allowed');
    }
}

function redirect(string $path): void {
    header('Location: ' . $path);
    exit;
}

function slugify(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
}

function template_keys(): array {
    return [
        'TEXT_LEFT_MEDIA_RIGHT',
        'TEXT_SPLIT_TWO_COL',
        'MEDIA_LEFT_TEXT_RIGHT',
        'DUAL_MEDIA_WITH_TOP_TEXT',
        'MEDIA_CENTER_ONLY',
    ];
}

function cdn_url(string $cdnBase, string $relativePath): string {
    return rtrim($cdnBase, '/') . '/' . ltrim($relativePath, '/');
}

function image_path_for(string $programKey, int $externalLessonId, int $pageNumber): string {
    // page_001.png formatting:
    $p = str_pad((string)$pageNumber, 3, '0', STR_PAD_LEFT);
    return "ks_images/{$programKey}/lesson_{$externalLessonId}/page_{$p}.png";
}