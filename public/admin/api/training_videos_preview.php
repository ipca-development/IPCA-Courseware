<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/communication/CommunicationKernel.php';

cw_require_admin();
header('Cache-Control: no-store');

try {
    $kernel = new CommunicationKernel($pdo);
    $poster = $kernel->trainingVideos->adminPosterBytes((string)($_GET['video_uuid'] ?? ''));
    header('Content-Type: ' . $poster['mime_type']);
    echo $poster['bytes'];
    exit;
} catch (CommunicationException $e) {
    http_response_code($e->httpStatus);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('ok' => false, 'error' => $e->getMessage()), JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('ok' => false, 'error' => 'The thumbnail could not be loaded.'), JSON_UNESCAPED_SLASHES);
    exit;
}
