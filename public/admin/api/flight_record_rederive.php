<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/FlightRecordDerivationService.php';

cw_require_admin();

function flight_record_rederive_redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

$csvFileId = (int)($_POST['csv_file_id'] ?? $_GET['csv_file_id'] ?? 0);
$return = trim((string)($_POST['return'] ?? '/admin/flight_records.php'));
if (!str_starts_with($return, '/admin/flight_records.php')) {
    $return = '/admin/flight_records.php';
}

try {
    if ($csvFileId <= 0) {
        throw new RuntimeException('Garmin CSV file id is required.');
    }

    $result = (new FlightRecordDerivationService($pdo))->deriveFromCsvFile($csvFileId);
    $separator = str_contains($return, '?') ? '&' : '?';
    flight_record_rederive_redirect(
        $return
        . $separator
        . 'rederived=1&version=' . urlencode((string)($result['version_uuid'] ?? ''))
        . '&readiness=' . urlencode((string)($result['readiness_status'] ?? ''))
    );
} catch (Throwable $e) {
    flight_record_rederive_redirect('/admin/flight_records.php?error=' . urlencode($e->getMessage()));
}
