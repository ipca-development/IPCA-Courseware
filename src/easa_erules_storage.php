<?php
declare(strict_types=1);

require_once __DIR__ . '/resource_library_storage.php';

function easa_erules_storage_root(): string
{
    return rl_project_root() . '/storage/easa_erules';
}

function easa_erules_batch_dir(int $batchId): string
{
    if ($batchId <= 0) {
        throw new InvalidArgumentException('Invalid batch id');
    }

    return easa_erules_storage_root() . '/batches/' . $batchId;
}

/**
 * @return string Relative path from project root (POSIX slashes).
 */
function easa_erules_batch_relative_path(int $batchId): string
{
    return 'storage/easa_erules/batches/' . $batchId . '/source.xml';
}

function easa_erules_ensure_batch_dir(int $batchId): void
{
    $dir = easa_erules_batch_dir($batchId);
    if (is_dir($dir)) {
        return;
    }
    if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create EASA batch directory');
    }
}

/**
 * Copy uploaded tmp file into canonical batch storage (immutable evidence).
 *
 * @return array{absolute:string,relpath:string,size:int}
 */
function easa_erules_store_batch_upload(int $batchId, string $tmpPath): array
{
    if ($batchId <= 0) {
        throw new InvalidArgumentException('Invalid batch id');
    }
    if ($tmpPath === '' || !is_file($tmpPath) || !is_readable($tmpPath)) {
        throw new RuntimeException('Invalid temporary upload path');
    }

    easa_erules_ensure_batch_dir($batchId);
    $dest = easa_erules_batch_dir($batchId) . '/source.xml';
    if (!copy($tmpPath, $dest)) {
        throw new RuntimeException('Could not store XML file');
    }
    $size = filesize($dest);
    if ($size === false) {
        throw new RuntimeException('Could not stat stored XML');
    }

    return [
        'absolute' => $dest,
        'relpath' => easa_erules_batch_relative_path($batchId),
        'size' => (int) $size,
    ];
}

/**
 * Runtime storage health snapshot (optionally include a batch source.xml check).
 *
 * @return array<string,mixed>
 */
function easa_erules_storage_health(?int $batchId = null): array
{
    $root = rl_project_root();
    $storageRoot = easa_erules_storage_root();
    $batchesDir = $storageRoot . '/batches';
    $out = [
        'project_root' => $root,
        'storage_root' => $storageRoot,
        'batches_dir' => $batchesDir,
        'storage_root_exists' => is_dir($storageRoot),
        'storage_root_writable' => is_dir($storageRoot) && is_writable($storageRoot),
        'batches_dir_exists' => is_dir($batchesDir),
        'batches_dir_writable' => is_dir($batchesDir) && is_writable($batchesDir),
    ];

    if ($batchId !== null && $batchId > 0) {
        $rel = easa_erules_batch_relative_path($batchId);
        $abs = $root . '/' . $rel;
        $exists = is_file($abs);
        $readable = $exists && is_readable($abs);
        $size = $exists ? @filesize($abs) : false;
        $sha = $readable ? @hash_file('sha256', $abs) : false;
        $out['batch'] = [
            'batch_id' => $batchId,
            'expected_relpath' => $rel,
            'expected_absolute_path' => $abs,
            'source_exists' => $exists,
            'source_readable' => $readable,
            'source_size_bytes' => is_int($size) ? $size : null,
            'source_sha256' => is_string($sha) ? $sha : null,
        ];
    }

    return $out;
}
