<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/communication/CommunicationKernel.php';

cw_require_admin();
header('Cache-Control: no-store');

try {
    $kernel = new CommunicationKernel($pdo);
    $assetUuid = (string)($_GET['asset_uuid'] ?? '');
    $row = $kernel->mediaLibrary->findByUuid($assetUuid);
    if ($row === null) {
        throw new CommunicationException('not_found', 'That photograph was not found.', 404);
    }
    $bytes = $kernel->mediaLibrary->getImageBytes($assetUuid);
    if (!is_string($bytes) || $bytes === '') {
        throw new CommunicationException('not_found', 'That photograph was not found.', 404);
    }
    $mime = strtolower(trim((string)($row['mime_type'] ?? 'image/jpeg')));
    if ($mime === '' || $mime === 'image/jpg') {
        $mime = 'image/jpeg';
    }
    header('Content-Type: ' . $mime);
    echo $bytes;
    exit;
} catch (CommunicationException $e) {
    http_response_code($e->httpStatus);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('ok' => false, 'error' => $e->getMessage()), JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('ok' => false, 'error' => 'The photograph could not be loaded.'), JSON_UNESCAPED_SLASHES);
    exit;
}
