<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/communication/api_bootstrap.php';
require_once __DIR__ . '/../../../src/communication/CommunicationHttp.php';
require_once __DIR__ . '/../../../src/communication/CommunicationKernel.php';

try {
    CommunicationHttp::method('GET');
    $kernel = new CommunicationKernel($pdo);
    $session = $kernel->auth->requireSession();
    $items = $kernel->systemMessages->needsAttention($session);
    CommunicationHttp::json(200, array(
        'ok' => true,
        'needs_action_count' => count($items),
        'actions' => $items,
    ));
} catch (CommunicationException $e) {
    CommunicationHttp::fail($e);
} catch (Throwable $e) {
    CommunicationSupport::log('communication.actions.error', array('error' => 'server_error', 'class' => $e::class, 'message' => $e->getMessage()));
    CommunicationHttp::json(500, array('ok' => false, 'error' => 'Server error', 'error_code' => 'server_error'));
}
