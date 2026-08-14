<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/communication/api_bootstrap.php';
require_once __DIR__ . '/../../../src/communication/CommunicationHttp.php';
require_once __DIR__ . '/../../../src/communication/CommunicationKernel.php';

try {
    CommunicationHttp::method('POST');
    $in = CommunicationHttp::input();
    $kernel = new CommunicationKernel($pdo);
    $action = strtolower(trim((string)($in['action'] ?? 'login')));

    if ($action === 'login') {
        $device = is_array($in['device'] ?? null) ? $in['device'] : array();
        CommunicationHttp::json(200, $kernel->auth->login(
            (string)($in['email'] ?? ''),
            (string)($in['password'] ?? ''),
            $device
        ));
    }

    if ($action === 'logout') {
        $session = $kernel->auth->requireSession();
        $kernel->auth->logout($session);
        CommunicationHttp::json(200, array('ok' => true));
    }

    throw new CommunicationException('validation_error', 'Unknown action.', 400);
} catch (CommunicationException $e) {
    CommunicationHttp::fail($e);
} catch (Throwable $e) {
    CommunicationSupport::log('communication.auth.error', array('error' => 'server_error', 'class' => $e::class, 'message' => $e->getMessage()));
    CommunicationHttp::json(500, array('ok' => false, 'error' => 'Server error', 'error_code' => 'server_error'));
}
