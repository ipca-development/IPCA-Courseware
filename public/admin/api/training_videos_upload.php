<?php
declare(strict_types=1);

@ini_set('max_execution_time', '7200');
@ini_set('max_input_time', '7200');
@ini_set('memory_limit', '256M');
ignore_user_abort(true);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/communication/CommunicationKernel.php';

cw_require_admin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function training_videos_upload_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $user = cw_current_user($pdo);
    if (!is_array($user) || (int)($user['id'] ?? 0) < 1) {
        training_videos_upload_json(401, array('ok' => false, 'error' => 'Authentication is required.'));
    }
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
        training_videos_upload_json(405, array('ok' => false, 'error' => 'Method not allowed.'));
    }
    $expected = (string)($_SESSION['training_videos_csrf'] ?? '');
    $provided = (string)($_SERVER['HTTP_X_IPCA_CSRF'] ?? ($_GET['csrf_token'] ?? ''));
    if ($expected === '' || !hash_equals($expected, $provided)) {
        training_videos_upload_json(403, array('ok' => false, 'error' => 'Invalid request token. Refresh and try again.'));
    }
    $kind = strtolower(trim((string)($_GET['kind'] ?? '')));
    $videoUuid = (string)($_GET['video_uuid'] ?? '');
    $mimeType = strtolower(trim((string)($_SERVER['CONTENT_TYPE'] ?? '')));
    $mimeType = preg_replace('/;.*$/', '', $mimeType) ?? $mimeType;
    $byteSize = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($byteSize < 1) {
        training_videos_upload_json(400, array('ok' => false, 'error' => 'The upload was empty.'));
    }
    $stream = fopen('php://input', 'rb');
    if ($stream === false) {
        training_videos_upload_json(500, array('ok' => false, 'error' => 'Could not read the upload.'));
    }
    try {
        $kernel = new CommunicationKernel($pdo);
        $result = $kernel->trainingVideos->putAdminObject(
            $videoUuid,
            $kind,
            $mimeType,
            $byteSize,
            $stream,
            (int)($_GET['duration_ms'] ?? 0)
        );
    } finally {
        fclose($stream);
    }
    training_videos_upload_json(200, array_merge(array('ok' => true), $result));
} catch (CommunicationException $e) {
    training_videos_upload_json($e->httpStatus, array(
        'ok' => false,
        'error' => $e->getMessage(),
        'error_code' => $e->errorCode,
    ));
} catch (Throwable $e) {
    training_videos_upload_json(500, array(
        'ok' => false,
        'error' => 'The video could not be stored. Try again.',
        'error_code' => 'server_error',
    ));
}
