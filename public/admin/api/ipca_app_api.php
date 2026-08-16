<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/communication/CommunicationKernel.php';

cw_require_admin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function ipca_app_admin_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function ipca_app_csrf_ok(string $provided): bool
{
    foreach (array('ipca_app_csrf', 'training_videos_csrf') as $key) {
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
        ipca_app_admin_json(401, array('ok' => false, 'error' => 'Authentication is required.'));
    }
    $kernel = new CommunicationKernel($pdo);
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        $snapshot = $kernel->enrollment->snapshot();
        ipca_app_admin_json(200, array(
            'ok' => true,
            'people' => $snapshot['people'],
            'devices' => $snapshot['devices'],
            'reports' => $snapshot['reports'],
            'stats' => $snapshot['stats'],
            'categories' => $kernel->trainingVideos->listCategories(true),
            'entitlements' => $kernel->trainingVideos->categoryEntitlementsByUser(),
        ));
    }

    if ($method !== 'POST') {
        ipca_app_admin_json(405, array('ok' => false, 'error' => 'Method not allowed.'));
    }
    $raw = file_get_contents('php://input');
    $input = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;
    if (!is_array($input)) {
        ipca_app_admin_json(400, array('ok' => false, 'error' => 'Valid JSON is required.'));
    }
    if (!ipca_app_csrf_ok((string)($input['csrf_token'] ?? ''))) {
        ipca_app_admin_json(403, array('ok' => false, 'error' => 'Invalid request token. Refresh and try again.'));
    }

    $action = strtolower(trim((string)($input['action'] ?? '')));
    $result = match ($action) {
        'grant_categories' => $kernel->trainingVideos->grantCategoryEntitlements(
            is_array($input['user_ids'] ?? null) ? $input['user_ids'] : array(),
            is_array($input['category_ids'] ?? null) ? $input['category_ids'] : array(),
            (string)($input['available_from_utc'] ?? ''),
            (string)($input['available_until_utc'] ?? '')
        ),
        'replace_user_categories' => $kernel->trainingVideos->replaceUserCategoryEntitlements(
            (int)($input['user_id'] ?? 0),
            is_array($input['entitlements'] ?? null) ? $input['entitlements'] : array()
        ),
        default => throw new CommunicationException('validation_error', 'Unknown action.', 400),
    };
    ipca_app_admin_json(200, array_merge(array('ok' => true), $result));
} catch (CommunicationException $e) {
    ipca_app_admin_json($e->httpStatus, array(
        'ok' => false,
        'error' => $e->getMessage(),
        'error_code' => $e->errorCode,
    ));
} catch (Throwable $e) {
    ipca_app_admin_json(500, array('ok' => false, 'error' => 'Server error', 'error_code' => 'server_error'));
}
