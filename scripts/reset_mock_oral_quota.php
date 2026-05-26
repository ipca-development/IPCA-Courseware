<?php
declare(strict_types=1);

/**
 * Reset or inspect mock oral weekly quota for a student.
 *
 * Usage:
 *   php scripts/reset_mock_oral_quota.php --user=14 --cohort=5
 *   php scripts/reset_mock_oral_quota.php --user=14 --cohort=5 --apply
 *   php scripts/reset_mock_oral_quota.php --user=14 --cohort=5 --apply --allow=10
 */

$root = dirname(__DIR__);

$loadDotenv = static function (string $path): void {
    if (!is_readable($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $m)) {
            continue;
        }
        if (getenv($m[1]) !== false) {
            continue;
        }
        $val = $m[2];
        if ($val !== '' && (($val[0] === '"' && str_ends_with($val, '"')) || ($val[0] === "'" && str_ends_with($val, "'")))) {
            $val = substr($val, 1, -1);
        }
        putenv($m[1] . '=' . $val);
    }
};

if (!getenv('CW_DB_HOST')) {
    $loadDotenv($root . '/.env');
}

require_once $root . '/src/db.php';
require_once $root . '/src/mock_oral/SessionQuotaService.php';

$opts = getopt('', ['user:', 'cohort:', 'apply', 'allow::']);
$userId = (int)($opts['user'] ?? 0);
$cohortId = (int)($opts['cohort'] ?? 0);
$apply = array_key_exists('apply', $opts);
$allowOverride = isset($opts['allow']) ? (int)$opts['allow'] : 0;

if ($userId <= 0 || $cohortId <= 0) {
    fwrite(STDERR, "Usage: php scripts/reset_mock_oral_quota.php --user=14 --cohort=5 [--apply] [--allow=10]\n");
    exit(1);
}

try {
    $pdo = cw_db();
} catch (Throwable $e) {
    fwrite(STDERR, 'Cannot connect: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$quotaSvc = new SessionQuotaService($pdo);
$periodStart = gmdate('Y-m-d', strtotime('monday this week UTC'));
$periodEnd = gmdate('Y-m-d', strtotime('sunday this week UTC'));
$started = $quotaSvc->countStartedSessionsThisWeek($userId, $cohortId);
$allowed = $quotaSvc->sessionsAllowedPerWeek($userId, $cohortId);
$prepare = $quotaSvc->canPrepareSession($userId, $cohortId);

echo "User {$userId}, cohort {$cohortId}\n";
echo "Week: {$periodStart} .. {$periodEnd} (UTC)\n";
echo "Allowed per week: {$allowed}\n";
echo "Started this week (billable): {$started}\n";
echo 'Can prepare now: ' . (!empty($prepare['allowed']) ? 'yes' : 'no — ' . ($prepare['message'] ?? '')) . "\n";

$st = $pdo->prepare('SELECT * FROM mock_oral_usage_quotas WHERE user_id = ? AND cohort_id = ? AND period_start = ? LIMIT 1');
$st->execute([$userId, $cohortId, $periodStart]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if ($row) {
    echo "Quota row: sessions_used={$row['sessions_used']} sessions_allowed={$row['sessions_allowed']}\n";
} else {
    echo "No quota row for current week yet.\n";
}

$prepSt = $pdo->prepare('
    SELECT id, status, started_at, created_at
    FROM mock_oral_sessions
    WHERE user_id = ? AND cohort_id = ?
      AND created_at >= ?
    ORDER BY id DESC
    LIMIT 10
');
$prepSt->execute([$userId, $cohortId, $periodStart . ' 00:00:00']);
echo "\nRecent sessions this week:\n";
foreach ($prepSt->fetchAll(PDO::FETCH_ASSOC) as $sess) {
    echo "- #{$sess['id']} status={$sess['status']} started_at=" . ($sess['started_at'] ?? 'null') . "\n";
}

if (!$apply) {
    echo "\nDry run. Pass --apply to sync sessions_used to started count";
    if ($allowOverride > 0) {
        echo " and set sessions_allowed={$allowOverride}";
    }
    echo ".\n";
    exit(0);
}

$quota = $quotaSvc->getOrCreateCurrentPeriod($userId, $cohortId);
$newAllowed = $allowOverride > 0 ? $allowOverride : (int)($quota['sessions_allowed'] ?? $allowed);
$pdo->prepare('
    UPDATE mock_oral_usage_quotas
    SET sessions_used = ?, sessions_allowed = ?, updated_at = UTC_TIMESTAMP()
    WHERE id = ?
')->execute([$started, $newAllowed, (int)$quota['id']]);

echo "\nUpdated quota row id={$quota['id']}: sessions_used={$started}, sessions_allowed={$newAllowed}\n";
