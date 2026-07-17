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

function garmin_csv_summary_json(int $code, array $payload): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function garmin_csv_summary_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute(array($table));
    return (int)$stmt->fetchColumn() > 0;
}

function garmin_csv_summary_stats(PDO $pdo): array
{
    $hasCsv = garmin_csv_summary_table_exists($pdo, 'ipca_garmin_csv_files');
    $hasCsvSummary = garmin_csv_summary_table_exists($pdo, 'ipca_garmin_csv_flight_summaries');
    $hasTracks = garmin_csv_summary_table_exists($pdo, 'ipca_garmin_normalized_track_artifacts');
    $hasTrackSummary = garmin_csv_summary_table_exists($pdo, 'ipca_garmin_track_flight_summaries');
    $csvTotal = 0;
    $csvDone = 0;
    if ($hasCsv) {
        $row = $pdo->query("
            SELECT
              COUNT(f.id) AS total,
              SUM(CASE
                WHEN s.csv_file_id IS NULL THEN 0
                WHEN JSON_EXTRACT(s.summary_json, '$.hobbs_exact') IS NULL THEN 0
                WHEN JSON_EXTRACT(s.summary_json, '$.tacho_exact') IS NULL THEN 0
                WHEN JSON_EXTRACT(s.summary_json, '$.hobbs_in') IS NULL THEN 0
                WHEN JSON_EXTRACT(s.summary_json, '$.tacho_in') IS NULL THEN 0
                WHEN s.tail_number IN ('', 'Unknown tail', 'Unknown') THEN 0
                WHEN JSON_EXTRACT(s.summary_json, '$.system_id') IS NULL THEN 0
                WHEN JSON_EXTRACT(s.summary_json, '$.avionics_family') IS NULL THEN 0
                WHEN CAST(JSON_UNQUOTE(JSON_EXTRACT(s.summary_json, '$.hobbs_exact.counter_start_exact')) AS DECIMAL(12,4)) < 0 THEN 0
                WHEN CAST(JSON_UNQUOTE(JSON_EXTRACT(s.summary_json, '$.tacho_exact.counter_start_exact')) AS DECIMAL(12,4)) < 0 THEN 0
                ELSE 1
              END) AS done
            FROM ipca_garmin_csv_files f
            " . ($hasCsvSummary ? "LEFT JOIN ipca_garmin_csv_flight_summaries s ON s.csv_file_id = f.id" : "LEFT JOIN (SELECT NULL AS csv_file_id) s ON 1 = 0") . "
        ")->fetch(PDO::FETCH_ASSOC) ?: array();
        $csvTotal = (int)($row['total'] ?? 0);
        $csvDone = (int)($row['done'] ?? 0);
    }
    $trackTotal = 0;
    $trackDone = 0;
    if ($hasTracks) {
        $row = $pdo->query("
            SELECT
              COUNT(t.id) AS total,
              SUM(CASE
                WHEN s.track_artifact_id IS NULL THEN 0
                WHEN JSON_EXTRACT(s.summary_json, '$.hobbs_exact') IS NULL THEN 0
                WHEN JSON_EXTRACT(s.summary_json, '$.tacho_exact') IS NULL THEN 0
                WHEN JSON_EXTRACT(s.summary_json, '$.hobbs_in') IS NULL THEN 0
                WHEN JSON_EXTRACT(s.summary_json, '$.tacho_in') IS NULL THEN 0
                WHEN s.tail_number IN ('', 'Unknown tail', 'Unknown') THEN 0
                WHEN JSON_EXTRACT(s.summary_json, '$.system_id') IS NULL THEN 0
                WHEN JSON_EXTRACT(s.summary_json, '$.avionics_family') IS NULL THEN 0
                WHEN CAST(JSON_UNQUOTE(JSON_EXTRACT(s.summary_json, '$.hobbs_exact.counter_start_exact')) AS DECIMAL(12,4)) < 0 THEN 0
                WHEN CAST(JSON_UNQUOTE(JSON_EXTRACT(s.summary_json, '$.tacho_exact.counter_start_exact')) AS DECIMAL(12,4)) < 0 THEN 0
                ELSE 1
              END) AS done
            FROM ipca_garmin_normalized_track_artifacts t
            " . ($hasTrackSummary ? "LEFT JOIN ipca_garmin_track_flight_summaries s ON s.track_artifact_id = t.id" : "LEFT JOIN (SELECT NULL AS track_artifact_id) s ON 1 = 0") . "
            WHERE t.artifact_type = 'GARMIN_TRACK_NORMALIZED_JSON'
              AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(t.raw_metadata_json, '$.trackClassification')), '') <> 'GARMIN_GPS_ONLY'
        ")->fetch(PDO::FETCH_ASSOC) ?: array();
        $trackTotal = (int)($row['total'] ?? 0);
        $trackDone = (int)($row['done'] ?? 0);
    }
    $total = $csvTotal + $trackTotal;
    $done = $csvDone + $trackDone;
    return array(
        'total' => $total,
        'done' => $done,
        'remaining' => max(0, $total - $done),
        'percent' => $total > 0 ? min(100, (int)round(($done / $total) * 100)) : 100,
        'csv_total' => $csvTotal,
        'csv_done' => $csvDone,
        'track_total' => $trackTotal,
        'track_done' => $trackDone,
    );
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
    $wantsJson = (string)($_POST['format'] ?? '') === 'json';
    $summaryService = new GarminCsvFlightSummaryService($pdo);
    $trackSummaryService = new GarminTrackFlightSummaryService($pdo);

    if ($action === 'status') {
        garmin_csv_summary_json(200, array('ok' => true, 'status' => garmin_csv_summary_stats($pdo)));
    }

    if ($action === 'process_next') {
        $csvResult = $summaryService->deriveMissingNow($limit);
        $trackResult = $trackSummaryService->deriveMissingNow($limit);
        garmin_csv_summary_json(200, array(
            'ok' => true,
            'csv' => $csvResult,
            'tracks' => $trackResult,
            'processed' => (int)($csvResult['processed'] ?? 0) + (int)($trackResult['processed'] ?? 0),
            'failed' => (int)($csvResult['failed'] ?? 0) + (int)($trackResult['failed'] ?? 0),
            'status' => garmin_csv_summary_stats($pdo),
        ));
    }

    if ($action === 'derive_missing_now') {
        $csvResult = $summaryService->deriveMissingNow($limit);
        $trackResult = $trackSummaryService->deriveMissingNow($limit);
        $processed = (int)($csvResult['processed'] ?? 0) + (int)($trackResult['processed'] ?? 0);
        $failed = (int)($csvResult['failed'] ?? 0) + (int)($trackResult['failed'] ?? 0);
        if ($wantsJson) {
            garmin_csv_summary_json(200, array('ok' => true, 'processed' => $processed, 'failed' => $failed, 'status' => garmin_csv_summary_stats($pdo)));
        }
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
    if ((string)($_POST['format'] ?? '') === 'json') {
        garmin_csv_summary_json(500, array('ok' => false, 'error' => $e->getMessage()));
    }
    garmin_csv_summary_redirect('/admin/flight_log_garmin_connection.php?error=' . urlencode($e->getMessage()));
}
