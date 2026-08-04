<?php
declare(strict_types=1);

/**
 * Phase 2A operational identity backfill CLI.
 *
 * Default: dry-run (no writes).
 * Apply:   php scripts/backfill_operational_reservation_leg_identity.php --apply
 *
 * Options:
 *   --apply
 *   --organization-id=N
 *   --limit=N
 */

$root = dirname(__DIR__);

$loadDotenv = static function (string $path): void {
    if (!is_readable($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $m)) {
            continue;
        }
        $key = $m[1];
        $val = $m[2];
        if ($val !== '' && (($val[0] === '"' && str_ends_with($val, '"')) || ($val[0] === "'" && str_ends_with($val, "'")))) {
            $val = substr($val, 1, -1);
        }
        if (getenv($key) !== false) {
            continue;
        }
        putenv($key . '=' . $val);
        $_ENV[$key] = $val;
    }
};

if (!getenv('CW_DB_HOST')) {
    $loadDotenv($root . '/.env');
}

require_once $root . '/src/db.php';
require_once $root . '/src/time.php';
require_once $root . '/src/CvrOperationalIdentityBackfillService.php';

$apply = false;
$organizationId = null;
$limit = 500;

foreach (array_slice($argv ?? [], 1) as $arg) {
    if ($arg === '--apply') {
        $apply = true;
        continue;
    }
    if (str_starts_with($arg, '--organization-id=')) {
        $organizationId = (int)substr($arg, strlen('--organization-id='));
        continue;
    }
    if (str_starts_with($arg, '--limit=')) {
        $limit = (int)substr($arg, strlen('--limit='));
        continue;
    }
    if ($arg === '--help' || $arg === '-h') {
        echo "Usage: php scripts/backfill_operational_reservation_leg_identity.php [--apply] [--organization-id=N] [--limit=N]\n";
        echo "Default mode is dry-run. --apply requires operational_identity_backfill_enabled.\n";
        exit(0);
    }
    fwrite(STDERR, "Unknown argument: {$arg}\n");
    exit(1);
}

$service = new CvrOperationalIdentityBackfillService($pdo);
try {
    $summary = $service->backfill($organizationId, !$apply, $limit);
} catch (Throwable $e) {
    fwrite(STDERR, 'Backfill failed: ' . $e->getMessage() . "\n");
    exit(1);
}

echo $apply ? "APPLY mode\n" : "DRY-RUN mode (no writes)\n";
echo 'scanned_slots: ' . $summary['scanned_slots'] . "\n";
echo 'reservations_created: ' . $summary['reservations_created'] . "\n";
echo 'legs_created: ' . $summary['legs_created'] . "\n";
echo 'aliases_created: ' . $summary['aliases_created'] . "\n";
echo 'quarantined: ' . $summary['quarantined'] . "\n";
echo 'skipped: ' . $summary['skipped'] . "\n";
echo 'actions: ' . count($summary['actions']) . "\n";
foreach (array_slice($summary['actions'], 0, 50) as $action) {
    echo '- ' . json_encode($action, JSON_UNESCAPED_SLASHES) . "\n";
}
if (count($summary['actions']) > 50) {
    echo '... truncated ...\n';
}
exit(0);
