<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/communication/CommunicationKernel.php';

cw_require_admin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function media_library_admin_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function media_library_csrf_ok(string $provided): bool
{
    foreach (array('training_videos_csrf', 'media_library_csrf') as $key) {
        $expected = (string)($_SESSION[$key] ?? '');
        if ($expected !== '' && hash_equals($expected, $provided)) {
            return true;
        }
    }
    return false;
}

try {
    $user = cw_current_user($pdo);
    if (!is_array($user) || (int)($user['id'] ?? 0) < 1) {
        media_library_admin_json(401, array('ok' => false, 'error' => 'Authentication is required.'));
    }
    $kernel = new CommunicationKernel($pdo);
    $service = $kernel->mediaLibrary;
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        $action = strtolower(trim((string)($_GET['action'] ?? 'list')));
        $result = match ($action) {
            'list' => $service->adminList((string)($_GET['orientation'] ?? ''), (int)($_GET['limit'] ?? 120)),
            default => throw new CommunicationException('validation_error', 'Unknown action.', 400),
        };
        media_library_admin_json(200, array_merge(array('ok' => true), $result));
    }

    if ($method !== 'POST') {
        media_library_admin_json(405, array('ok' => false, 'error' => 'Method not allowed.'));
    }
    $raw = file_get_contents('php://input');
    $input = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;
    if (!is_array($input)) {
        media_library_admin_json(400, array('ok' => false, 'error' => 'Valid JSON is required.'));
    }
    if (!media_library_csrf_ok((string)($input['csrf_token'] ?? ''))) {
        media_library_admin_json(403, array('ok' => false, 'error' => 'Invalid request token. Refresh and try again.'));
    }
    $action = strtolower(trim((string)($input['action'] ?? '')));
    $result = match ($action) {
        'delete' => $service->deleteAdmin((string)($input['asset_uuid'] ?? '')),
        default => throw new CommunicationException('validation_error', 'Unknown action.', 400),
    };
    media_library_admin_json(200, array_merge(array('ok' => true), $result));
} catch (CommunicationException $e) {
    media_library_admin_json($e->httpStatus, array(
        'ok' => false,
        'error' => $e->getMessage(),
        'error_code' => $e->errorCode,
    ));
} catch (Throwable $e) {
    media_library_admin_json(500, array('ok' => false, 'error' => 'Server error', 'error_code' => 'server_error'));
}
