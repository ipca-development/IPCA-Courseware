<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/AdsbTrafficArchiveService.php';

$command = trim((string)($argv[1] ?? 'status'));

try {
    $pdo = cw_db();
    $archive = new AdsbTrafficArchiveService($pdo);
    if ($command === 'status') {
        echo json_encode($archive->status(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
        exit(0);
    }
    if ($command === 'schedule-live') {
        if (($argv[2] ?? '') === 'all') {
            $result = $archive->scheduleRecentLiveTargetCoverage(defaultLookbackMinutes(), defaultBucketSeconds());
            echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
            exit(0);
        }
        $result = $archive->scheduleRecentLivePointCoverage(
            argFloat(2, defaultLat()),
            argFloat(3, defaultLon()),
            argFloat(4, defaultRadiusNm()),
            argInt(5, defaultLookbackMinutes()),
            argInt(6, defaultBucketSeconds()),
            defaultScope()
        );
        echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
        exit(0);
    }
    if ($command === 'fetch-next') {
        $limit = max(1, min(100, argInt(2, 1)));
        $results = array();
        for ($i = 0; $i < $limit; $i++) {
            $result = $archive->fetchNextPendingTile();
            $results[] = $result;
            if (($result['status'] ?? '') === 'idle') {
                break;
            }
        }
        echo json_encode(array('ok' => true, 'results' => $results), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
        exit(0);
    }
    if ($command === 'run-once') {
        if (isset($argv[2])) {
            $schedule = $archive->scheduleRecentLivePointCoverage(argFloat(2, defaultLat()), argFloat(3, defaultLon()), argFloat(4, defaultRadiusNm()), argInt(5, defaultLookbackMinutes()), argInt(6, defaultBucketSeconds()), defaultScope());
            $fetchLimit = max(1, (int)($schedule['tiles_created'] ?? 1));
            $fetches = array();
            for ($i = 0; $i < $fetchLimit; $i++) {
                $fetches[] = $archive->fetchNextPendingTile();
            }
            echo json_encode(array('ok' => true, 'schedule' => $schedule, 'fetches' => $fetches), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
            exit(0);
        }
        $cycles = defaultHighResolutionCycles();
        $intervalSeconds = defaultHighResolutionIntervalSeconds();
        $runs = array();
        for ($cycle = 0; $cycle < $cycles; $cycle++) {
            $schedule = $archive->scheduleLiveTargetSnapshotCoverage($cycle > 0);
            $fetchLimit = max(1, (int)($schedule['tiles_created'] ?? 1));
            $fetches = array();
            for ($i = 0; $i < $fetchLimit; $i++) {
                $fetches[] = $archive->fetchNextPendingTile();
            }
            $runs[] = array('cycle' => $cycle + 1, 'high_resolution_only' => $cycle > 0, 'schedule' => $schedule, 'fetches' => $fetches);
            if ($cycle + 1 < $cycles) {
                sleep($intervalSeconds);
            }
        }
        echo json_encode(array('ok' => true, 'mode' => 'home_high_resolution', 'cycles' => $cycles, 'interval_seconds' => $intervalSeconds, 'runs' => $runs), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
        exit(0);
    }
    if ($command === 'run-snapshot') {
        $schedule = isset($argv[2])
            ? $archive->scheduleLivePointSnapshotCoverage(argFloat(2, defaultLat()), argFloat(3, defaultLon()), argFloat(4, defaultRadiusNm()), argInt(5, defaultBucketSeconds()), defaultScope())
            : $archive->scheduleLiveTargetSnapshotCoverage(false);
        $fetchLimit = max(1, (int)($schedule['tiles_created'] ?? 1));
        $fetches = array();
        for ($i = 0; $i < $fetchLimit; $i++) {
            $fetches[] = $archive->fetchNextPendingTile();
        }
        echo json_encode(array('ok' => true, 'schedule' => $schedule, 'fetches' => $fetches), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
        exit(0);
    }
    if ($command === 'loop') {
        $intervalSeconds = max(10, min(900, argInt(2, defaultHighResolutionIntervalSeconds())));
        $iterations = max(0, argInt(3, 0));
        $count = 0;
        while ($iterations === 0 || $count < $iterations) {
            $schedule = $archive->scheduleLiveTargetSnapshotCoverage(false);
            $fetches = array();
            $fetchLimit = max(1, (int)($schedule['tiles_created'] ?? 1));
            for ($i = 0; $i < $fetchLimit; $i++) {
                $fetches[] = $archive->fetchNextPendingTile();
            }
            echo json_encode(array('ok' => true, 'iteration' => $count + 1, 'schedule' => $schedule, 'fetches' => $fetches), JSON_UNESCAPED_SLASHES) . "\n";
            $count++;
            if ($iterations !== 0 && $count >= $iterations) {
                break;
            }
            sleep($intervalSeconds);
        }
        exit(0);
    }
    usage();
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

function usage(): never
{
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php scripts/cockpit_adsb_live_archive.php status\n");
    fwrite(STDERR, "  php scripts/cockpit_adsb_live_archive.php schedule-live [lat lon radius-nm lookback-min bucket-sec]\n");
    fwrite(STDERR, "  php scripts/cockpit_adsb_live_archive.php schedule-live all\n");
    fwrite(STDERR, "  php scripts/cockpit_adsb_live_archive.php fetch-next [limit]\n");
    fwrite(STDERR, "  php scripts/cockpit_adsb_live_archive.php run-once [optional lat lon radius-nm lookback-min bucket-sec]\n");
    fwrite(STDERR, "  php scripts/cockpit_adsb_live_archive.php run-snapshot [optional lat lon radius-nm bucket-sec]\n");
    fwrite(STDERR, "  php scripts/cockpit_adsb_live_archive.php loop [interval-sec] [iterations]\n");
    exit(2);
}

function argFloat(int $index, float $default): float
{
    global $argv;
    return isset($argv[$index]) && is_numeric($argv[$index]) ? (float)$argv[$index] : $default;
}

function argInt(int $index, int $default): int
{
    global $argv;
    return isset($argv[$index]) && is_numeric($argv[$index]) ? (int)$argv[$index] : $default;
}

function defaultLat(): float
{
    return (float)(getenv('CW_ADSB_LIVE_CENTER_LAT') ?: 33.626701);
}

function defaultLon(): float
{
    return (float)(getenv('CW_ADSB_LIVE_CENTER_LON') ?: -116.160156);
}

function defaultRadiusNm(): float
{
    return (float)(getenv('CW_ADSB_LIVE_RADIUS_NM') ?: 25.0);
}

function defaultLookbackMinutes(): int
{
    return (int)(getenv('CW_ADSB_LIVE_LOOKBACK_MINUTES') ?: 1);
}

function defaultBucketSeconds(): int
{
    return (int)(getenv('CW_ADSB_LIVE_BUCKET_SECONDS') ?: 60);
}

function defaultHighResolutionIntervalSeconds(): int
{
    return max(10, min(60, (int)(getenv('CW_ADSB_HOME_LIVE_INTERVAL_SECONDS') ?: 10)));
}

function defaultHighResolutionCycles(): int
{
    return max(1, min(12, (int)(getenv('CW_ADSB_HOME_LIVE_CYCLES') ?: 6)));
}

function defaultScope(): string
{
    return trim((string)(getenv('CW_ADSB_LIVE_SCOPE') ?: 'ktrm_live'));
}
