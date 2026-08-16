<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/communication/api_bootstrap.php';
require_once __DIR__ . '/../../../src/communication/CommunicationHttp.php';
require_once __DIR__ . '/../../../src/communication/CommunicationKernel.php';

try {
    CommunicationHttp::method('POST');
    $kernel = new CommunicationKernel($pdo);
    $session = $kernel->auth->requireSession();
    $in = CommunicationHttp::input();
    $ack = $kernel->systemMessages->acknowledge(
        $session,
        (string)($in['message_uuid'] ?? ''),
        (string)($in['acknowledgement_uuid'] ?? CommunicationSupport::uuid())
    );
    CommunicationHttp::json(200, array('ok' => true, 'acknowledgement' => $ack));
} catch (CommunicationException $e) {
    CommunicationHttp::fail($e);
} catch (Throwable $e) {
    CommunicationSupport::log('communication.acknowledgements.error', array('error' => 'server_error', 'class' => $e::class, 'message' => $e->getMessage()));
    CommunicationHttp::json(500, array('ok' => false, 'error' => 'Server error', 'error_code' => 'server_error'));
}
