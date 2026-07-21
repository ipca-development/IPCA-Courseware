<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/FlightCircleHistoricalImportService.php';

cw_require_admin();

function flightcircle_staging_rows_json(int $code, array $payload): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $filters = array(
        'resource_type' => trim((string)($_GET['fc_resource'] ?? 'aircraft')),
        'tail' => strtoupper(trim((string)($_GET['fc_tail'] ?? ''))),
        'student' => trim((string)($_GET['fc_student'] ?? '')),
        'instructor' => trim((string)($_GET['fc_instructor'] ?? '')),
        'date_from' => trim((string)($_GET['fc_from'] ?? '')),
        'date_to' => trim((string)($_GET['fc_to'] ?? '')),
        'sort' => trim((string)($_GET['fc_sort'] ?? 'date_desc')),
        'limit' => trim((string)($_GET['fc_limit'] ?? '50')),
    );
    $status = (new FlightCircleHistoricalImportService($pdo))->status(1, $filters);
    if (empty($status['ready'])) {
        throw new RuntimeException((string)($status['message'] ?? 'FlightCircle migration is not ready.'));
    }
    flightcircle_staging_rows_json(200, array(
        'ok' => true,
        'rows' => $status['recent_staging_records'] ?? array(),
        'shown' => count((array)($status['recent_staging_records'] ?? array())),
        'filtered_count' => (int)($status['recent_staging_filtered_count'] ?? 0),
        'limit' => (string)($status['recent_staging_limit'] ?? ''),
    ));
} catch (Throwable $e) {
    flightcircle_staging_rows_json(500, array('ok' => false, 'error' => $e->getMessage()));
}
