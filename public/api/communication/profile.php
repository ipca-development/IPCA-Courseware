<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/communication/api_bootstrap.php';
require_once __DIR__ . '/../../../src/communication/CommunicationHttp.php';
require_once __DIR__ . '/../../../src/communication/CommunicationKernel.php';

try {
    $kernel = new CommunicationKernel($pdo);
    $session = $kernel->auth->requireSession();
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        CommunicationHttp::json(200, $kernel->profile->get($session));
    }

    CommunicationHttp::method('POST');
    $contentType = strtolower(trim((string)($_SERVER['CONTENT_TYPE'] ?? '')));
    $queryAction = strtolower(trim((string)($_GET['action'] ?? '')));
    if ($queryAction === 'photo' || str_starts_with($contentType, 'image/')) {
        $bytes = (string)file_get_contents('php://input');
        $mime = $contentType !== '' ? $contentType : 'image/jpeg';
        CommunicationHttp::json(200, $kernel->profile->putPhoto($session, $bytes, $mime));
    }

    $in = CommunicationHttp::input();
    $action = strtolower(trim((string)($in['action'] ?? 'save_personal')));

    if ($action === 'save_personal') {
        CommunicationHttp::json(200, $kernel->profile->savePersonal($session, $in));
    }

    if ($action === 'save_emergency') {
        $contacts = is_array($in['emergency_contacts'] ?? null) ? $in['emergency_contacts'] : array();
        CommunicationHttp::json(200, $kernel->profile->saveEmergency($session, $contacts));
    }

    if ($action === 'change_password') {
        CommunicationHttp::json(200, $kernel->profile->changePassword(
            $session,
            (string)($in['current_password'] ?? ''),
            (string)($in['new_password'] ?? ''),
            (string)($in['new_password_confirm'] ?? $in['password_confirm'] ?? '')
        ));
    }

    throw new CommunicationException('validation_error', 'Unknown action.', 400);
} catch (CommunicationException $e) {
    CommunicationHttp::fail($e);
} catch (Throwable $e) {
    CommunicationSupport::log('communication.profile.error', array('error' => 'server_error', 'class' => $e::class, 'message' => $e->getMessage()));
    CommunicationHttp::json(500, array('ok' => false, 'error' => 'Server error', 'error_code' => 'server_error'));
}
