<?php
declare(strict_types=1);

/**
 * CLI: one-shot FAA AIM HTML bootstrap — crawl under the AIM edition prefix, extract
 * h4.paragraph-title nodes, upsert resource_library_aim_paragraphs, write JSON manifest.
 *
 * Requires: DB env vars (same as app), AIM + editions migrations applied.
 *
 * Usage:
 *   php scripts/aim_bootstrap_import.php [options]
 *
 * Options:
 *   --edition-id=N     resource_library_editions.id (default: CRAWLER_AIM / aim slot)
 *   --index-url=URL    default: {allowed_url_prefix}index.html
 *   --snapshot-dir=DIR default: storage/resource_library/aim_bootstrap_snapshots (gitignored parent)
 *   --max-pages=N      safety cap (default 500)
 *   --sleep-ms=N       delay between HTTP requests (default 300)
 *   --dry-run          crawl + manifest only; no DB writes
 *   --replace          delete existing AIM paragraphs for this edition before upsert
 *   --no-sync-edition  do not update edition revision_date / revision_code from index
 *
 * Example:
 *   php scripts/aim_bootstrap_import.php --replace
 */

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/aim_bootstrap_importer.php';

$argvList = array_slice($argv, 1);
$opts = [
    'edition_id' => 0,
    'index_url' => null,
    'snapshot_dir' => dirname(__DIR__) . '/storage/resource_library/aim_bootstrap_snapshots',
    'max_pages' => 500,
    'sleep_ms' => 300,
    'dry_run' => false,
    'replace' => false,
    'sync_edition' => true,
];

foreach ($argvList as $arg) {
    if ($arg === '--dry-run') {
        $opts['dry_run'] = true;
    } elseif ($arg === '--replace') {
        $opts['replace'] = true;
    } elseif ($arg === '--no-sync-edition') {
        $opts['sync_edition'] = false;
    } elseif (str_starts_with($arg, '--edition-id=')) {
        $opts['edition_id'] = (int) substr($arg, strlen('--edition-id='));
    } elseif (str_starts_with($arg, '--index-url=')) {
        $opts['index_url'] = trim(substr($arg, strlen('--index-url=')));
    } elseif (str_starts_with($arg, '--snapshot-dir=')) {
        $opts['snapshot_dir'] = trim(substr($arg, strlen('--snapshot-dir=')));
    } elseif (str_starts_with($arg, '--max-pages=')) {
        $opts['max_pages'] = max(1, (int) substr($arg, strlen('--max-pages=')));
    } elseif (str_starts_with($arg, '--sleep-ms=')) {
        $opts['sleep_ms'] = max(0, (int) substr($arg, strlen('--sleep-ms=')));
    } elseif ($arg === '-h' || $arg === '--help') {
        fwrite(STDOUT, "See docblock in scripts/aim_bootstrap_import.php for options.\n");
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        exit(1);
    }
}

try {
    $editionId = $opts['edition_id'];
    if ($editionId <= 0) {
        $edition = rl_catalog_fetch_crawler_edition_by_slot($pdo, 'aim');
        if (!$edition) {
            fwrite(STDERR, "AIM crawler edition not found. Apply scripts/sql/resource_library_aim_crawl.sql or pass --edition-id=\n");
            exit(1);
        }
        $editionId = (int) ($edition['id'] ?? 0);
        $row = $edition;
    } else {
        $row = rl_catalog_fetch_edition($pdo, $editionId);
        if (!is_array($row)) {
            fwrite(STDERR, "Edition {$editionId} not found.\n");
            exit(1);
        }
    }

    $extra = rl_catalog_decode_extra(isset($row['extra_config_json']) ? (string) $row['extra_config_json'] : null);
    $prefix = trim((string) ($extra['allowed_url_prefix'] ?? ''));
    if ($prefix === '') {
        fwrite(STDERR, "Edition has no extra_config_json.allowed_url_prefix.\n");
        exit(1);
    }
    $prefix = AimBootstrapImporter::normalizePrefix($prefix);

    $indexUrl = $opts['index_url'];
    if ($indexUrl === null || $indexUrl === '') {
        $indexUrl = rtrim($prefix, '/') . '/index.html';
    }

    $importer = new AimBootstrapImporter(
        $pdo,
        $editionId,
        $prefix,
        $indexUrl,
        $opts['dry_run'],
        $opts['replace'],
        $opts['sync_edition'],
        $opts['snapshot_dir'],
        $opts['max_pages'],
        $opts['sleep_ms'],
    );

    $result = $importer->run();
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

if (empty($result['ok'])) {
    fwrite(STDERR, 'Import failed: ' . ($result['error'] ?? 'unknown') . "\n");
    exit(1);
}

fwrite(STDOUT, sprintf(
    "AIM bootstrap OK — pages: %d, paragraphs: %d, manifest: %s\n",
    (int) ($result['pages'] ?? 0),
    (int) ($result['paragraphs'] ?? 0),
    (string) ($result['manifest_path'] ?? '')
));
