<?php
declare(strict_types=1);

/**
 * Monitor official PDF URLs for pdf_book editions (full file SHA-256, not headers only).
 *
 * Usage:
 *   php cli/cron_resource_library_pdf_monitor.php
 *   php cli/cron_resource_library_pdf_monitor.php --dry-run
 *   php cli/cron_resource_library_pdf_monitor.php --force
 *
 * When a new SHA-256 is detected: download, parse, set batch ready_for_review (never auto-publish).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/resource_library_catalog.php';
require_once __DIR__ . '/../src/resource_library_pdf.php';
require_once __DIR__ . '/../src/resource_library_pdf_import.php';

error_reporting(E_ALL);
ini_set('display_errors', '1');

$argvList = $argv ?? [];
$dryRun = in_array('--dry-run', $argvList, true) || in_array('-n', $argvList, true);
$force = in_array('--force', $argvList, true) || in_array('-f', $argvList, true);

if ($dryRun) {
    fwrite(STDERR, "Dry run: no downloads or database writes.\n");
}
if ($force) {
    fwrite(STDERR, "Force: checking all eligible pdf_book editions regardless of interval.\n");
}

if (!rl_catalog_has_resource_type_column($pdo)) {
    fwrite(STDERR, "Skip: resource_type column missing.\n");
    exit(0);
}
if (!rl_pdf_tables_ok($pdo)) {
    fwrite(STDERR, "Skip: apply scripts/sql/resource_library_pdf_crawler.sql first.\n");
    exit(0);
}

$probe = rl_pdf_pdftotext_probe();
if (!$probe['available']) {
    fwrite(STDERR, rl_pdf_pdftotext_required_error() . "\n");
    exit(1);
}

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$stmt = $pdo->query("
    SELECT id, extra_config_json, status
    FROM resource_library_editions
    WHERE resource_type = 'pdf_book'
");
if ($stmt === false) {
    fwrite(STDERR, "Could not query editions.\n");
    exit(1);
}

$nChecked = 0;
$nSkipped = 0;
$nNew = 0;
$nUnchanged = 0;
$nErrors = 0;

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if (!is_array($row)) {
        break;
    }
    $id = (int) ($row['id'] ?? 0);
    if ($id <= 0) {
        continue;
    }
    if (strtolower(trim((string) ($row['status'] ?? ''))) === 'archived') {
        ++$nSkipped;
        continue;
    }
    $extra = rl_catalog_decode_extra(isset($row['extra_config_json']) ? (string) $row['extra_config_json'] : null);
    $url = trim((string) ($extra['official_pdf_url'] ?? ''));
    if ($url === '') {
        ++$nSkipped;
        continue;
    }
    $interval = rl_source_verify_normalize_interval((string) ($extra['source_verify_interval'] ?? 'off'));
    $state = is_array($extra['source_verify_state'] ?? null) ? $extra['source_verify_state'] : [];
    if (!$force && !rl_source_verify_should_run($state, $interval, $now)) {
        ++$nSkipped;
        continue;
    }

    ++$nChecked;
    $line = 'edition ' . $id;
    if ($dryRun) {
        echo $line . " · DRY_RUN would check " . $url . "\n";
        continue;
    }

    try {
        $res = rl_pdf_check_now($pdo, $id, $force);
        if (!empty($res['unchanged'])) {
            ++$nUnchanged;
            echo $line . " · UNCHANGED " . ($res['sha256'] ?? '') . "\n";
        } else {
            ++$nNew;
            echo $line . " · NEW_BATCH " . ($res['batch_id'] ?? 0) . ' articles=' . ($res['articles'] ?? 0) . " READY_FOR_REVIEW\n";
        }
    } catch (Throwable $e) {
        ++$nErrors;
        fwrite(STDERR, $line . ' · ERROR: ' . $e->getMessage() . "\n");
    }
}

echo 'Done. Checked: ' . $nChecked . ', skipped: ' . $nSkipped . ', new batches: ' . $nNew
    . ', unchanged: ' . $nUnchanged . ', errors: ' . $nErrors;
if ($dryRun) {
    echo ' (dry run)';
}
echo "\n";
exit($nErrors > 0 ? 2 : 0);
