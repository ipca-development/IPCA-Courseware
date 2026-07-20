<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';

cw_require_admin();

function historical_report_cell(mixed $value): string
{
    $value = (string)$value;
    if ($value !== '' && in_array($value[0], array('=', '+', '-', '@'), true)) {
        $value = "'" . $value;
    }
    return $value;
}

try {
    if (!isset($pdo)) {
        throw new RuntimeException('Database connection unavailable.');
    }
    $batchId = max(0, (int)($_GET['batch_id'] ?? 0));
    $sql = "
        SELECT b.batch_uuid, f.id AS backfill_file_id, f.original_filename, f.sha256, f.exact_duplicate_status,
               f.parse_status, f.classification, f.review_status, f.resolved_aircraft_registration,
               s.classification AS segment_classification, s.departure_airport_code, s.arrival_airport_code,
               s.start_utc, s.end_utc
        FROM ipca_garmin_historical_backfill_files f
        INNER JOIN ipca_garmin_historical_backfill_batches b ON b.id = f.batch_id
        LEFT JOIN ipca_garmin_historical_segments s ON s.backfill_file_id = f.id
    ";
    $params = array();
    if ($batchId > 0) {
        $sql .= ' WHERE f.batch_id = ?';
        $params[] = $batchId;
    }
    $sql .= ' ORDER BY f.created_at DESC, f.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="garmin_historical_backfill_report.csv"');
    $out = fopen('php://output', 'wb');
    fputcsv($out, array('batch_uuid', 'backfill_file_id', 'filename', 'sha256', 'duplicate_status', 'parse_status', 'file_classification', 'review_status', 'aircraft', 'segment_classification', 'dep', 'arr', 'start_utc', 'end_utc'));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row) {
        fputcsv($out, array_map('historical_report_cell', $row));
    }
    fclose($out);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo $e->getMessage();
}
