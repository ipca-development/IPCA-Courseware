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

error_reporting(E_ALL);
ini_set('display_errors', '1');

if (function_exists('ob_get_level')) {
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
}
if (function_exists('ob_implicit_flush')) {
    @ob_implicit_flush(true);
}

/**
 * @param resource $stream
 */
function aim_cli_log($stream, string $message): void
{
    fwrite($stream, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL);
    if (function_exists('fflush')) {
        @fflush($stream);
    }
}

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
            aim_cli_log(STDERR, "AIM crawler edition not found. Apply scripts/sql/resource_library_aim_crawl.sql or pass --edition-id=");
            exit(1);
        }
        $editionId = (int) ($edition['id'] ?? 0);
        $row = $edition;
    } else {
        $row = rl_catalog_fetch_edition($pdo, $editionId);
        if (!is_array($row)) {
            aim_cli_log(STDERR, "Edition {$editionId} not found.");
            exit(1);
        }
    }

    $extra = rl_catalog_decode_extra(isset($row['extra_config_json']) ? (string) $row['extra_config_json'] : null);
    $prefix = trim((string) ($extra['allowed_url_prefix'] ?? ''));
    if ($prefix === '') {
        aim_cli_log(STDERR, "Edition has no extra_config_json.allowed_url_prefix.");
        exit(1);
    }
    $prefix = AimBootstrapImporter::normalizePrefix($prefix);

    $indexUrl = $opts['index_url'];
    if ($indexUrl === null || $indexUrl === '') {
        $indexUrl = rtrim($prefix, '/') . '/index.html';
    }
    aim_cli_log(STDOUT, 'AIM bootstrap import starting.');
    aim_cli_log(STDOUT, 'Edition ID: ' . $editionId);
    aim_cli_log(STDOUT, 'Allowed prefix: ' . $prefix);
    aim_cli_log(STDOUT, 'Index URL: ' . $indexUrl);
    aim_cli_log(STDOUT, 'Snapshot dir: ' . (string)$opts['snapshot_dir']);
    aim_cli_log(STDOUT, sprintf(
        'Flags: dry_run=%s replace=%s sync_edition=%s max_pages=%d sleep_ms=%d',
        $opts['dry_run'] ? 'yes' : 'no',
        $opts['replace'] ? 'yes' : 'no',
        $opts['sync_edition'] ? 'yes' : 'no',
        (int)$opts['max_pages'],
        (int)$opts['sleep_ms']
    ));

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
        static function (string $msg): void {
            aim_cli_log(STDOUT, $msg);
        }
    );

    $result = $importer->run();
} catch (Throwable $e) {
    aim_cli_log(STDERR, 'Error: ' . $e->getMessage());
    aim_cli_log(STDERR, 'At: ' . $e->getFile() . ':' . $e->getLine());
    $trace = $e->getTraceAsString();
    if ($trace !== '') {
        aim_cli_log(STDERR, "Trace:\n" . $trace);
    }
    exit(1);
}

if (empty($result['ok'])) {
    aim_cli_log(STDERR, 'Import failed: ' . ($result['error'] ?? 'unknown'));
    exit(1);
}

aim_cli_log(STDOUT, sprintf(
    "AIM bootstrap OK - pages: %d, paragraphs: %d, manifest: %s",
    (int) ($result['pages'] ?? 0),
    (int) ($result['paragraphs'] ?? 0),
    (string) ($result['manifest_path'] ?? '')
));
