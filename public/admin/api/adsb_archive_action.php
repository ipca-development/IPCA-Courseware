<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/AdsbTrafficArchiveService.php';

cw_require_admin();

function adsb_archive_redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

$return = trim((string)($_POST['return'] ?? '/admin/adsb_traffic_archive.php'));
if (!str_starts_with($return, '/admin/adsb_traffic_archive.php')) {
    $return = '/admin/adsb_traffic_archive.php';
}
$separator = str_contains($return, '?') ? '&' : '?';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new RuntimeException('POST required.');
    }
    @set_time_limit(0);
    $service = new AdsbTrafficArchiveService($pdo);
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'schedule_recent_ktrm') {
        $minutes = is_numeric($_POST['lookback_minutes'] ?? null) ? (int)$_POST['lookback_minutes'] : 180;
        $result = $service->scheduleRecentKtrmCoverage($minutes);
        adsb_archive_redirect($return . $separator . 'scheduled=' . (int)($result['tiles_created'] ?? 0));
    }

    if ($action === 'process_tile') {
        $result = $service->fetchNextPendingTile();
        adsb_archive_redirect($return . $separator . 'processed=' . urlencode((string)($result['status'] ?? 'unknown')) . '&samples=' . (int)($result['samples'] ?? 0));
    }

    if ($action === 'process_batch') {
        $limit = is_numeric($_POST['limit'] ?? null) ? max(1, min(25, (int)$_POST['limit'])) : 5;
        $processed = 0;
        $samples = 0;
        for ($i = 0; $i < $limit; $i++) {
            $result = $service->fetchNextPendingTile();
            if ((string)($result['status'] ?? '') === 'idle') {
                break;
            }
            $processed++;
            $samples += (int)($result['samples'] ?? 0);
        }
        adsb_archive_redirect($return . $separator . 'batch_processed=' . $processed . '&samples=' . $samples);
    }

    throw new RuntimeException('Unknown ADS-B archive action.');
} catch (Throwable $e) {
    adsb_archive_redirect('/admin/adsb_traffic_archive.php?error=' . urlencode($e->getMessage()));
}
