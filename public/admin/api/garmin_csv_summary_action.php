<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/AsyncJobService.php';
require_once __DIR__ . '/../../../src/GarminCsvFlightSummaryService.php';
require_once __DIR__ . '/../../../src/GarminTrackFlightSummaryService.php';

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
    $trackSummaryService = new GarminTrackFlightSummaryService($pdo);

    if ($action === 'derive_missing_now') {
        $csvResult = $summaryService->deriveMissingNow($limit);
        $trackResult = $trackSummaryService->deriveMissingNow($limit);
        $processed = (int)($csvResult['processed'] ?? 0) + (int)($trackResult['processed'] ?? 0);
        $failed = (int)($csvResult['failed'] ?? 0) + (int)($trackResult['failed'] ?? 0);
        garmin_csv_summary_redirect(
            $return . $separator
            . 'summary_processed=' . urlencode((string)$processed)
            . '&summary_failed=' . urlencode((string)$failed)
            . '&csv_processed=' . urlencode((string)($csvResult['processed'] ?? 0))
            . '&track_processed=' . urlencode((string)($trackResult['processed'] ?? 0))
        );
    }

    if ($action === 'enqueue_missing') {
        $ids = $summaryService->missingCsvFileIds($limit);
        $trackIds = $trackSummaryService->missingTrackArtifactIds($limit);
        $jobs = new AsyncJobService($pdo);
        $queued = 0;
        foreach ($ids as $csvFileId) {
            if ($jobs->enqueue('GARMIN_CSV_FLIGHT_SUMMARY', 'ipca_garmin_csv_files', (string)$csvFileId, array('csv_file_id' => $csvFileId), null, 80) > 0) {
                $queued++;
            }
        }
        foreach ($trackIds as $trackArtifactId) {
            if ($jobs->enqueue('GARMIN_TRACK_FLIGHT_SUMMARY', 'ipca_garmin_normalized_track_artifacts', (string)$trackArtifactId, array('track_artifact_id' => $trackArtifactId), null, 80) > 0) {
                $queued++;
            }
        }
        garmin_csv_summary_redirect($return . $separator . 'summary_queued=' . urlencode((string)$queued));
    }

    throw new RuntimeException('Unknown Garmin CSV summary action.');
} catch (Throwable $e) {
    garmin_csv_summary_redirect('/admin/flight_log_garmin_connection.php?error=' . urlencode($e->getMessage()));
}
