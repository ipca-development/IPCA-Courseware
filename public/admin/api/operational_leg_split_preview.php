<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/CvrAdminLegSplitService.php';

header('Content-Type: application/json; charset=utf-8');

cw_require_admin();

$dispatchId = (int)($_GET['dispatch_id'] ?? $_POST['dispatch_id'] ?? 0);
if ($dispatchId <= 0) {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'message' => 'dispatch_id is required.'), JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $preview = (new CvrAdminLegSplitService($pdo))->preview($dispatchId);
    echo json_encode(array('ok' => true, 'preview' => $preview), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'message' => $e->getMessage()), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
