<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/AdsbTrafficArchiveService.php';

function adsb_archive_cron_json(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$expectedToken = trim((string)getenv('CW_ADSB_ARCHIVE_CRON_TOKEN'));
$providedToken = trim((string)($_GET['token'] ?? $_SERVER['HTTP_X_ADSB_ARCHIVE_TOKEN'] ?? ''));
if ($expectedToken === '') {
    adsb_archive_cron_json(500, array('ok' => false, 'error' => 'CW_ADSB_ARCHIVE_CRON_TOKEN is not configured.'));
}
if (!hash_equals($expectedToken, $providedToken)) {
    adsb_archive_cron_json(401, array('ok' => false, 'error' => 'Invalid ADS-B archive cron token.'));
}

try {
    @set_time_limit(0);
    $service = new AdsbTrafficArchiveService($pdo);
    $lookbackMinutes = is_numeric($_GET['lookback_minutes'] ?? null) ? (int)$_GET['lookback_minutes'] : 5;
    $limit = is_numeric($_GET['limit'] ?? null) ? max(1, min(25, (int)$_GET['limit'])) : 5;
    $scheduled = $service->scheduleRecentKtrmCoverage($lookbackMinutes);
    $processed = 0;
    $samples = 0;
    $results = array();
    for ($i = 0; $i < $limit; $i++) {
        $result = $service->fetchNextPendingTile();
        $results[] = $result;
        if ((string)($result['status'] ?? '') === 'idle') {
            break;
        }
        $processed++;
        $samples += (int)($result['samples'] ?? 0);
    }
    adsb_archive_cron_json(200, array(
        'ok' => true,
        'scheduled_tiles' => (int)($scheduled['tiles_created'] ?? 0),
        'processed_tiles' => $processed,
        'samples' => $samples,
        'results' => $results,
        'status' => $service->status(),
    ));
} catch (Throwable $e) {
    adsb_archive_cron_json(500, array('ok' => false, 'error' => $e->getMessage()));
}
