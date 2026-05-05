<?php
declare(strict_types=1);

/**
 * On-disk files for resource library editions (admin upload).
 * JSON: {project}/storage/resource_library/{id}/source.json
 * Cover: {project}/storage/resource_library/{id}/thumb.{jpg|png|webp}
 */

function rl_project_root(): string
{
    return dirname(__DIR__);
}

function rl_edition_dir(int $editionId): string
{
    if ($editionId <= 0) {
        throw new InvalidArgumentException('Invalid edition id');
    }

    return rl_project_root() . '/storage/resource_library/' . $editionId;
}

function rl_source_json_path(int $editionId): string
{
    return rl_edition_dir($editionId) . '/source.json';
}

function rl_ensure_edition_dir(int $editionId): void
{
    $dir = rl_edition_dir($editionId);
    if (is_dir($dir)) {
        return;
    }
    if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create storage directory');
    }
}

/**
 * @return array{present: bool, size: int, modified_iso: ?string}
 */
function rl_source_stat(int $editionId): array
{
    $p = rl_source_json_path($editionId);
    if (!is_file($p)) {
        return ['present' => false, 'size' => 0, 'modified_iso' => null];
    }
    $mtime = @filemtime($p);
    $iso = ($mtime !== false) ? gmdate('c', $mtime) : null;

    return [
        'present' => true,
        'size' => (int)@filesize($p),
        'modified_iso' => $iso,
    ];
}

function rl_write_source_json(int $editionId, string $jsonUtf8): void
{
    rl_ensure_edition_dir($editionId);
    $final = rl_source_json_path($editionId);
    $tmp = $final . '.tmp.' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $jsonUtf8) === false) {
        throw new RuntimeException('Could not write upload');
    }
    if (!rename($tmp, $final)) {
        @unlink($tmp);
        throw new RuntimeException('Could not finalize upload');
    }
}

function rl_delete_source_json(int $editionId): void
{
    $p = rl_source_json_path($editionId);
    if (is_file($p)) {
        @unlink($p);
    }
}

const RL_THUMB_MAX_BYTES = 10485760; // 10 MiB

/**
 * @return array<string, string>
 */
function rl_thumbnail_mime_to_ext(): array
{
    return [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
}

function rl_thumbnail_public_url(int $editionId): string
{
    return '/admin/resource_library_thumb.php?id=' . $editionId;
}

/**
 * Remove uploaded cover images (thumb.jpg/png/webp) for an edition.
 */
function rl_delete_thumbnail_files(int $editionId): void
{
    if ($editionId <= 0) {
        return;
    }
    $dir = rl_edition_dir($editionId);
    if (!is_dir($dir)) {
        return;
    }
    foreach (['thumb.jpg', 'thumb.png', 'thumb.webp'] as $f) {
        $p = $dir . '/' . $f;
        if (is_file($p)) {
            @unlink($p);
        }
    }
}

/**
 * @return array{path: string, mime: string, ext: string}|null
 */
function rl_thumbnail_disk_file(int $editionId): ?array
{
    if ($editionId <= 0) {
        return null;
    }
    $dir = rl_edition_dir($editionId);
    $mimeByExt = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];
    foreach (['thumb.jpg', 'thumb.png', 'thumb.webp'] as $f) {
        $p = $dir . '/' . $f;
        if (is_file($p)) {
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));

            return [
                'path' => $p,
                'mime' => $mimeByExt[$ext] ?? 'application/octet-stream',
                'ext' => $ext,
            ];
        }
    }

    return null;
}

/**
 * Save an uploaded image as thumb.{jpg|png|webp}, replacing any previous thumb.*.
 *
 * @return string Public URL path for thumbnail_path column
 */
function rl_write_thumbnail_from_tmp(int $editionId, string $tmpPath): string
{
    if ($editionId <= 0) {
        throw new InvalidArgumentException('Invalid edition id');
    }
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new RuntimeException('Invalid upload');
    }

    $raw = file_get_contents($tmpPath);
    if ($raw === false) {
        throw new RuntimeException('Could not read image');
    }
    $len = strlen($raw);
    if ($len === 0) {
        throw new RuntimeException('Empty file');
    }
    if ($len > RL_THUMB_MAX_BYTES) {
        throw new RuntimeException('Image is too large (max 10 MB)');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->buffer($raw);
    $map = rl_thumbnail_mime_to_ext();
    if (!isset($map[$mime])) {
        throw new RuntimeException('Unsupported image type. Use JPG, PNG, or WEBP.');
    }
    $ext = $map[$mime];

    rl_ensure_edition_dir($editionId);
    rl_delete_thumbnail_files($editionId);

    $final = rl_edition_dir($editionId) . '/thumb.' . $ext;
    $tmpOut = $final . '.tmp.' . bin2hex(random_bytes(4));
    if (file_put_contents($tmpOut, $raw) === false) {
        throw new RuntimeException('Could not write image');
    }
    if (!rename($tmpOut, $final)) {
        @unlink($tmpOut);
        throw new RuntimeException('Could not finalize image');
    }

    return rl_thumbnail_public_url($editionId);
}
