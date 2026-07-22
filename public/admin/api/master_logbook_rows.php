<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/MasterLogbookReadService.php';

cw_require_admin();
header('Content-Type: application/json; charset=utf-8');

function master_logbook_rows_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $view = strtolower(trim((string)($_GET['view'] ?? 'normal')));
    $includeUnresolved = $view === 'unresolved';
    $sortField = (string)($_GET['sort_field'] ?? 'date');
    $sortDirection = strtolower((string)($_GET['sort_direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $pageSize = max(1, min(100, (int)($_GET['page_size'] ?? 25)));

    $service = new MasterLogbookReadService($pdo);
    $result = $service->listLegRows(array(
        'page' => $page,
        'page_size' => $pageSize,
        'sort_field' => $sortField,
        'sort_direction' => $sortDirection,
        'include_unresolved' => $includeUnresolved,
        'include_simulator' => $includeUnresolved,
        'include_non_flight' => $includeUnresolved,
        'include_diagnostics' => !empty($_GET['include_diagnostics']),
    ));

    master_logbook_rows_json(200, array(
        'ok' => true,
        'view' => $includeUnresolved ? 'unresolved' : 'normal',
        'result' => $result,
    ));
} catch (Throwable $e) {
    master_logbook_rows_json(500, array(
        'ok' => false,
        'error' => $e->getMessage(),
        'type' => get_class($e),
    ));
}
