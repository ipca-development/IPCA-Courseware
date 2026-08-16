<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/communication/CommunicationKernel.php';

cw_require_admin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function training_videos_admin_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $user = cw_current_user($pdo);
    if (!is_array($user) || (int)($user['id'] ?? 0) < 1) {
        training_videos_admin_json(401, array('ok' => false, 'error' => 'Authentication is required.'));
    }
    $kernel = new CommunicationKernel($pdo);
    $service = $kernel->trainingVideos;
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        $action = strtolower(trim((string)($_GET['action'] ?? 'list')));
        $result = match ($action) {
            'list' => $service->adminCatalog(),
            'detail' => $service->adminDetail((string)($_GET['video_uuid'] ?? '')),
            default => throw new CommunicationException('validation_error', 'Unknown action.', 400),
        };
        training_videos_admin_json(200, array_merge(array('ok' => true), $result));
    }

    if ($method !== 'POST') {
        training_videos_admin_json(405, array('ok' => false, 'error' => 'Method not allowed.'));
    }
    $raw = file_get_contents('php://input');
    $input = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;
    if (!is_array($input)) {
        training_videos_admin_json(400, array('ok' => false, 'error' => 'Valid JSON is required.'));
    }
    $expected = (string)($_SESSION['training_videos_csrf'] ?? '');
    if ($expected === '' || !hash_equals($expected, (string)($input['csrf_token'] ?? ''))) {
        training_videos_admin_json(403, array('ok' => false, 'error' => 'Invalid request token. Refresh and try again.'));
    }

    $action = strtolower(trim((string)($input['action'] ?? '')));
    $videoUuid = (string)($input['video_uuid'] ?? '');
    $result = match ($action) {
        'save', 'create', 'update', 'grants' => $service->saveAdmin(
            $input,
            is_array($input['grants'] ?? null) ? $input['grants'] : array(),
            (int)$user['id']
        ),
        'archive' => $service->archiveAdmin($videoUuid, (int)$user['id']),
        'delete' => $service->deleteAdmin($videoUuid, (int)$user['id']),
        'presign_video' => $service->presignAdminUpload(
            $videoUuid,
            'video',
            (string)($input['mime_type'] ?? ''),
            (int)($input['byte_size'] ?? 0),
            (string)($input['filename'] ?? '')
        ),
        'presign_poster' => $service->presignAdminUpload(
            $videoUuid,
            'poster',
            (string)($input['mime_type'] ?? ''),
            (int)($input['byte_size'] ?? 0),
            (string)($input['filename'] ?? '')
        ),
        'complete_video' => $service->completeAdminUpload($videoUuid, 'video', (int)($input['duration_ms'] ?? 0)),
        'complete_poster' => $service->completeAdminUpload($videoUuid, 'poster'),
        default => throw new CommunicationException('validation_error', 'Unknown action.', 400),
    };
    training_videos_admin_json(200, array_merge(array('ok' => true), $result));
} catch (CommunicationException $e) {
    training_videos_admin_json($e->httpStatus, array(
        'ok' => false,
        'error' => $e->getMessage(),
        'error_code' => $e->errorCode,
    ));
} catch (Throwable $e) {
    training_videos_admin_json(500, array('ok' => false, 'error' => 'Server error', 'error_code' => 'server_error'));
}
