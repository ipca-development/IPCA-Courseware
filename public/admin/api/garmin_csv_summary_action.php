<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/AsyncJobService.php';
require_once __DIR__ . '/../../../src/GarminCsvFlightSummaryService.php';

cw_require_admin();

function garmin_csv_summary_redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

$return = trim((string)($_POST['return'] ?? '/admin/flight_log_garmin_connection.php'));
if (!str_starts_with($return, '/admin/flight_log_garmin_connection.php')) {
    $return = '/admin/flight_log_garmin_connection.php';
}
$separator = str_contains($return, '?') ? '&' : '?';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new RuntimeException('POST required.');
    }
    $action = trim((string)($_POST['action'] ?? 'derive_missing_now'));
    $limit = max(1, min(500, (int)($_POST['limit'] ?? 50)));
    $summaryService = new GarminCsvFlightSummaryService($pdo);

    if ($action === 'derive_missing_now') {
        $result = $summaryService->deriveMissingNow($limit);
        garmin_csv_summary_redirect(
            $return . $separator
            . 'summary_processed=' . urlencode((string)($result['processed'] ?? 0))
            . '&summary_failed=' . urlencode((string)($result['failed'] ?? 0))
        );
    }

    if ($action === 'enqueue_missing') {
        $ids = $summaryService->missingCsvFileIds($limit);
        $jobs = new AsyncJobService($pdo);
        $queued = 0;
        foreach ($ids as $csvFileId) {
            if ($jobs->enqueue('GARMIN_CSV_FLIGHT_SUMMARY', 'ipca_garmin_csv_files', (string)$csvFileId, array('csv_file_id' => $csvFileId), null, 80) > 0) {
                $queued++;
            }
        }
        garmin_csv_summary_redirect($return . $separator . 'summary_queued=' . urlencode((string)$queued));
    }

    throw new RuntimeException('Unknown Garmin CSV summary action.');
} catch (Throwable $e) {
    garmin_csv_summary_redirect('/admin/flight_log_garmin_connection.php?error=' . urlencode($e->getMessage()));
}
