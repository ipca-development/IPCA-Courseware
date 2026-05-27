<?php
declare(strict_types=1);

require_once __DIR__ . '/resource_library_storage.php';

/** Maximum PDF download size (bytes). */
const RL_PDF_MAX_DOWNLOAD_BYTES = 104857600; // 100 MiB

function rl_pdf_storage_root(): string
{
    return rl_project_root() . '/storage/resource_library/pdf_batches';
}

function rl_pdf_batch_dir(int $editionId, int $batchId): string
{
    if ($editionId <= 0 || $batchId <= 0) {
        throw new InvalidArgumentException('Invalid edition or batch id');
    }

    return rl_pdf_storage_root() . '/' . $editionId . '/' . $batchId;
}

/**
 * @return string Relative path from project root (POSIX slashes).
 */
function rl_pdf_batch_source_relpath(int $editionId, int $batchId): string
{
    return 'storage/resource_library/pdf_batches/' . $editionId . '/' . $batchId . '/source.pdf';
}

/**
 * @return string Relative path from project root.
 */
function rl_pdf_batch_extracted_relpath(int $editionId, int $batchId): string
{
    return 'storage/resource_library/pdf_batches/' . $editionId . '/' . $batchId . '/extracted.txt';
}

function rl_pdf_ensure_batch_dir(int $editionId, int $batchId): void
{
    $dir = rl_pdf_batch_dir($editionId, $batchId);
    if (is_dir($dir)) {
        return;
    }
    if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create PDF batch directory');
    }
}

/**
 * @return array{absolute: string, relpath: string, size: int, sha256: string}
 */
function rl_pdf_store_downloaded_file(int $editionId, int $batchId, string $binary): array
{
    if ($editionId <= 0 || $batchId <= 0) {
        throw new InvalidArgumentException('Invalid edition or batch id');
    }
    $len = strlen($binary);
    if ($len === 0) {
        throw new RuntimeException('Downloaded PDF is empty');
    }
    if ($len > RL_PDF_MAX_DOWNLOAD_BYTES) {
        throw new RuntimeException('PDF exceeds maximum allowed size (' . RL_PDF_MAX_DOWNLOAD_BYTES . ' bytes)');
    }
    if (strncmp($binary, '%PDF-', 5) !== 0) {
        throw new RuntimeException('Downloaded file does not look like a PDF (%PDF- header missing)');
    }

    rl_pdf_ensure_batch_dir($editionId, $batchId);
    $rel = rl_pdf_batch_source_relpath($editionId, $batchId);
    $abs = rl_project_root() . '/' . $rel;
    $tmp = $abs . '.tmp.' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $binary) === false) {
        throw new RuntimeException('Could not write PDF to storage');
    }
    if (!rename($tmp, $abs)) {
        @unlink($tmp);
        throw new RuntimeException('Could not finalize PDF storage');
    }

    $sha = hash('sha256', $binary);
    if ($sha === false) {
        throw new RuntimeException('Could not compute SHA-256');
    }

    return [
        'absolute' => $abs,
        'relpath' => $rel,
        'size' => $len,
        'sha256' => $sha,
    ];
}

/**
 * @return array{absolute: string, relpath: string, size: int}
 */
function rl_pdf_store_extracted_text(int $editionId, int $batchId, string $text): array
{
    if ($editionId <= 0 || $batchId <= 0) {
        throw new InvalidArgumentException('Invalid edition or batch id');
    }
    rl_pdf_ensure_batch_dir($editionId, $batchId);
    $rel = rl_pdf_batch_extracted_relpath($editionId, $batchId);
    $abs = rl_project_root() . '/' . $rel;
    $tmp = $abs . '.tmp.' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $text) === false) {
        throw new RuntimeException('Could not write extracted text');
    }
    if (!rename($tmp, $abs)) {
        @unlink($tmp);
        throw new RuntimeException('Could not finalize extracted text');
    }

    return [
        'absolute' => $abs,
        'relpath' => $rel,
        'size' => strlen($text),
    ];
}

/**
 * @return array{exists: bool, readable: bool, absolute: string, size: ?int}
 */
function rl_pdf_validate_batch_pdf(int $editionId, int $batchId): array
{
    $rel = rl_pdf_batch_source_relpath($editionId, $batchId);
    $abs = rl_project_root() . '/' . $rel;
    $exists = is_file($abs);
    $readable = $exists && is_readable($abs);
    $size = $readable ? @filesize($abs) : false;

    return [
        'exists' => $exists,
        'readable' => $readable,
        'absolute' => $abs,
        'size' => is_int($size) ? $size : null,
    ];
}

/**
 * Read stored extracted text if present.
 */
function rl_pdf_read_extracted_text(int $editionId, int $batchId): ?string
{
    $rel = rl_pdf_batch_extracted_relpath($editionId, $batchId);
    $abs = rl_project_root() . '/' . $rel;
    if (!is_file($abs) || !is_readable($abs)) {
        return null;
    }
    $raw = file_get_contents($abs);
    if ($raw === false) {
        return null;
    }

    return $raw;
}

/**
 * @return array{available: bool, path: ?string, version: ?string}
 */
function rl_pdf_pdftotext_probe(): array
{
    $which = trim((string) shell_exec('command -v pdftotext 2>/dev/null') ?? '');
    if ($which === '' || !is_executable($which)) {
        return ['available' => false, 'path' => null, 'version' => null];
    }
    $ver = trim((string) shell_exec(escapeshellarg($which) . ' -v 2>&1') ?? '');

    return ['available' => true, 'path' => $which, 'version' => $ver !== '' ? $ver : null];
}

function rl_pdf_pdftotext_required_error(): string
{
    return 'PDF text extraction requires pdftotext on the server (poppler-utils package). Install it and retry.';
}
