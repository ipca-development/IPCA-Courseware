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
