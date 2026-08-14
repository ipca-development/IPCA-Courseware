<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/communication/api_bootstrap.php';
require_once __DIR__ . '/../../../src/communication/CommunicationHttp.php';
require_once __DIR__ . '/../../../src/communication/CommunicationKernel.php';

try {
    if (!in_array(strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')), array('GET', 'POST'), true)) {
        throw new CommunicationException('method_not_allowed', 'Method not allowed.', 405);
    }
    $kernel = new CommunicationKernel($pdo);
    $session = $kernel->auth->requireSession();
    $in = CommunicationHttp::input();
    $cursor = (int)($in['cursor'] ?? $_GET['cursor'] ?? 0);
    CommunicationHttp::json(200, $kernel->sync->pull($session, $cursor));
} catch (CommunicationException $e) {
    CommunicationHttp::fail($e);
} catch (Throwable $e) {
    CommunicationSupport::log('communication.sync.error', array('error' => 'server_error', 'class' => $e::class, 'message' => $e->getMessage()));
    CommunicationHttp::json(500, array('ok' => false, 'error' => 'Server error', 'error_code' => 'server_error'));
}
