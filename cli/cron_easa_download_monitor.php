<?php
declare(strict_types=1);

/**
 * Daily (or on-demand) polite probe of EASA download URLs (HEAD / fallback GET headers).
 *
 *   php cli/cron_easa_download_monitor.php
 *   php cli/cron_easa_download_monitor.php --dry-run
 *   php cli/cron_easa_download_monitor.php --force   (ignore daily throttle — optional future)
 *
 * Install: run once per day via cron (same host as the app). Does not download full XML.
 */

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/easa_download_monitor.php';

$dry = in_array('--dry-run', $argv, true);

if (!easa_download_monitor_tables_ok($pdo)) {
    fwrite(STDERR, "Skip: apply scripts/sql/resource_library_easa_erules.sql first.\n");
    exit(0);
}

if ($dry) {
    fwrite(STDOUT, "[dry-run] Would probe " . (int) $pdo->query('SELECT COUNT(*) FROM easa_download_monitor')->fetchColumn() . " URL(s).\n");
    exit(0);
}

$res = easa_download_monitor_probe_all($pdo);
fwrite(STDOUT, 'Probed ' . (int) $res['probed'] . " URL(s).\n");
foreach ($res['errors'] as $e) {
    fwrite(STDERR, $e . "\n");
}

exit(0);
