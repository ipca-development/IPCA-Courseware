<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/AirportLookupService.php';

cw_require_admin();
header('Content-Type: application/json; charset=utf-8');

try {
    $query = trim((string)($_GET['q'] ?? ''));
    $limit = is_numeric($_GET['limit'] ?? null) ? (int)$_GET['limit'] : 20;
    $airports = (new AirportLookupService())->search($query, $limit);
    echo json_encode(array('ok' => true, 'query' => $query, 'airports' => $airports), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(array('ok' => false, 'error' => $e->getMessage()), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
