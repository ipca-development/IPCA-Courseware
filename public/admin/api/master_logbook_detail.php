<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/MasterLogbookReadService.php';

cw_require_admin();
header('Content-Type: application/json; charset=utf-8');

function master_logbook_detail_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $eventKey = trim((string)($_GET['event_key'] ?? ''));
    if ($eventKey === '') {
        master_logbook_detail_json(400, array('ok' => false, 'error' => 'event_key is required.'));
    }

    $service = new MasterLogbookReadService($pdo);
    $detail = $service->eventDetail($eventKey);

    master_logbook_detail_json(200, array(
        'ok' => true,
        'detail' => $detail,
    ));
} catch (InvalidArgumentException $e) {
    master_logbook_detail_json(400, array(
        'ok' => false,
        'error' => $e->getMessage(),
        'type' => get_class($e),
    ));
} catch (Throwable $e) {
    master_logbook_detail_json(500, array(
        'ok' => false,
        'error' => $e->getMessage(),
        'type' => get_class($e),
    ));
}
