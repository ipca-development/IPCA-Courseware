<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/AdsbTrafficArchiveService.php';

cw_require_admin();
header('Content-Type: application/json; charset=utf-8');

try {
    $target = trim((string)($_GET['target'] ?? 'ktrm_live'));
    $hours = is_numeric($_GET['hours'] ?? null) ? (int)$_GET['hours'] : 6;
    $payload = (new AdsbTrafficArchiveService($pdo))->dashboardData($target, $hours);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(array('ok' => false, 'error' => $e->getMessage()), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
