<?php
declare(strict_types=1);

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function slugify(string $s): string
{
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim((string)$s, '-');
}

function template_keys(): array
{
    return [
        'TEXT_LEFT_MEDIA_RIGHT',
        'TEXT_SPLIT_TWO_COL',
        'MEDIA_LEFT_TEXT_RIGHT',
        'DUAL_MEDIA_WITH_TOP_TEXT',
        'MEDIA_CENTER_ONLY',
    ];
}

function cdn_url(string $cdnBase, string $relativePath): string
{
    return rtrim($cdnBase, '/') . '/' . ltrim($relativePath, '/');
}

/**
 * Map program_key to the actual folder name used in Spaces.
 * IMPORTANT: Spaces keys are case-sensitive.
 */
function program_folder_for_images(string $programKey): string
{
    // Your Spaces currently uses "Private" (capital P)
    if ($programKey === 'private') return 'Private';

    // If your Spaces uses Instrument/Commercial with capitals, uncomment:
    // if ($programKey === 'instrument') return 'Instrument';
    // if ($programKey === 'commercial') return 'Commercial';

    // Default: use program_key as-is
    return $programKey;
}

/**
 * Matches your uploaded structure in Spaces:
 * ks_images/{ProgramFolder}/lesson_{lessonId}/lesson_{lessonId}_page_{001}.png
 */
function image_path_for(string $programKey, int $externalLessonId, int $pageNumber): string
{
    $page = str_pad((string)$pageNumber, 3, '0', STR_PAD_LEFT);
    $folder = program_folder_for_images($programKey);

    return sprintf(
        'ks_images/%s/lesson_%d/lesson_%d_page_%s.png',
        $folder,
        $externalLessonId,
        $externalLessonId,
        $page
    );
}

function video_path_for(string $programKey, int $externalLessonId, string $filename): string
{
    // If you also used a capitalized folder under ks_videos, you’ll want a similar mapper.
    return sprintf(
        'ks_videos/%s/lesson_%d/%s',
        $programKey,
        $externalLessonId,
        $filename
    );
}
