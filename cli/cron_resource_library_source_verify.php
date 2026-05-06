<?php
declare(strict_types=1);

/**
 * CLI cron: probe official source URLs for json_book and crawler editions when
 * extra_config_json.source_verify_interval is not "off".
 *
 * Point system cron here (daily or hourly is fine); each edition is probed only
 * when its interval has elapsed since source_verify_state.checked_at.
 *
 * Usage:
 *   php cli/cron_resource_library_source_verify.php
 *   php cli/cron_resource_library_source_verify.php --dry-run
 *
 * With --dry-run (or -n): probes and prints the same log lines but does not UPDATE the database.
 *
 * Environment: same database credentials as the web app (see src/db.php).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/resource_library_catalog.php';

error_reporting(E_ALL);
ini_set('display_errors', '1');

$argvList = $argv ?? [];
$dryRun = in_array('--dry-run', $argvList, true) || in_array('-n', $argvList, true);
if ($dryRun) {
    fwrite(STDERR, "Dry run: no database writes.\n");
}

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

if (!rl_catalog_has_resource_type_column($pdo)) {
    $sql = 'SELECT id, extra_config_json, status FROM resource_library_editions';
} else {
    $sql = "SELECT id, resource_type, extra_config_json, status
            FROM resource_library_editions
            WHERE resource_type IN ('json_book', 'crawler')";
}

$stmt = $pdo->query($sql);
if ($stmt === false) {
    fwrite(STDERR, "Could not query editions.\n");
    exit(1);
}

$nChecked = 0;
$nSkipped = 0;
$nErrors = 0;

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if (!is_array($row)) {
        break;
    }
    $id = (int) ($row['id'] ?? 0);
    if ($id <= 0) {
        continue;
    }
    $st = strtolower(trim((string) ($row['status'] ?? '')));
    if ($st === 'archived') {
        ++$nSkipped;
        continue;
    }
    $rt = rl_catalog_has_resource_type_column($pdo)
        ? rl_catalog_normalize_resource_type(isset($row['resource_type']) ? (string) $row['resource_type'] : null)
        : RL_RESOURCE_JSON_BOOK;
    if ($rt !== RL_RESOURCE_JSON_BOOK && $rt !== RL_RESOURCE_CRAWLER) {
        ++$nSkipped;
        continue;
    }

    $extra = rl_catalog_decode_extra(isset($row['extra_config_json']) ? (string) $row['extra_config_json'] : null);
    $interval = rl_source_verify_normalize_interval((string) ($extra['source_verify_interval'] ?? 'off'));
    $state = is_array($extra['source_verify_state'] ?? null) ? $extra['source_verify_state'] : [];
    if (!rl_source_verify_should_run($state, $interval, $now)) {
        ++$nSkipped;
        continue;
    }
    $url = rl_source_verify_resolve_url($extra, $rt);
    if ($url === '') {
        ++$nSkipped;
        continue;
    }

    $probe = rl_source_verify_http_probe($url);
    $newState = rl_source_verify_advance_state($state, $probe, $now);
    $extra['source_verify_state'] = $newState;
    $enc = rl_catalog_encode_extra($extra);
    try {
        if (!$dryRun) {
            $up = $pdo->prepare('UPDATE resource_library_editions SET extra_config_json = ? WHERE id = ?');
            $up->execute([$enc, $id]);
        }
        ++$nChecked;
        if (!($probe['ok'] ?? false)) {
            ++$nErrors;
        }
        $line = 'edition ' . $id . ' (' . $rt . ') HTTP ' . ($probe['http_code'] ?? 0) . ' ' . $url;
        if (!empty($newState['change_detected'])) {
            $line .= ' · CHANGE_DETECTED';
        }
        if (!empty($newState['page_last_updated'])) {
            $line .= ' · page_last_updated=' . $newState['page_last_updated'];
        }
        if ($dryRun) {
            $line .= ' · DRY_RUN';
        }
        echo $line . "\n";
    } catch (Throwable $e) {
        ++$nErrors;
        fwrite(STDERR, 'edition ' . $id . ' DB error: ' . $e->getMessage() . "\n");
    }
}

echo 'Done. Probes run: ' . $nChecked . ', skipped: ' . $nSkipped . ', with HTTP/DB issues: ' . $nErrors;
if ($dryRun) {
    echo ' (dry run, no DB writes)';
}
echo "\n";
exit($nErrors > 0 ? 2 : 0);
