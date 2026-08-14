<?php
declare(strict_types=1);

/**
 * Export the existing read-only reader source for deterministic pagination QA.
 *
 * This script never writes publishing data.
 *
 * Usage:
 * php scripts/export_reader_paginate_source.php --book=OM --output=/tmp/om-source.json
 * php scripts/export_reader_paginate_source.php --version-id=123 --output=/tmp/source.json
 */

$root = dirname(__DIR__);

$loadDotenv = static function (string $path): void {
    if (!is_readable($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $match)) {
            continue;
        }
        if (getenv($match[1]) !== false) {
            continue;
        }
        $value = $match[2];
        if (
            $value !== ''
            && (($value[0] === '"' && str_ends_with($value, '"'))
                || ($value[0] === "'" && str_ends_with($value, "'")))
        ) {
            $value = substr($value, 1, -1);
        }
        putenv($match[1] . '=' . $value);
    }
};

if (!getenv('CW_DB_HOST')) {
    $loadDotenv($root . '/.env');
}

require_once $root . '/src/helpers.php';
require_once $root . '/src/db.php';
require_once $root . '/src/publishing/ControlledPublishingReaderService.php';

$options = getopt('', array('book::', 'version-id::', 'output:'));
$bookKey = strtoupper(trim((string)($options['book'] ?? 'OM')));
$versionId = (int)($options['version-id'] ?? 0);
$output = trim((string)($options['output'] ?? ''));
if ($output === '') {
    fwrite(STDERR, "Provide --output.\n");
    exit(2);
}

$reader = new ControlledPublishingReaderService(cw_db());
$version = $versionId > 0
    ? $reader->resolveVersionById($versionId)
    : $reader->resolveLatestReleasedVersion($bookKey);
if (!is_array($version)) {
    fwrite(STDERR, "No matching manual version found.\n");
    exit(1);
}

$source = $reader->loadReaderPaginateSource($version);
$json = json_encode(
    $source,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
);
if (file_put_contents($output, $json, LOCK_EX) === false) {
    fwrite(STDERR, "Unable to write pagination source fixture.\n");
    exit(1);
}

echo json_encode(array(
    'ok' => true,
    'book_key' => (string)($version['book_key'] ?? $bookKey),
    'version_id' => (int)$version['id'],
    'lifecycle_status' => (string)($version['lifecycle_status'] ?? ''),
    'sections' => count($source['sections'] ?? array()),
    'bytes' => strlen($json),
    'output' => $output,
), JSON_UNESCAPED_SLASHES) . PHP_EOL;
