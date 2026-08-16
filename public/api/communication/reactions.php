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
    $message = $kernel->messages->setReaction(
        $session,
        (string)($in['message_uuid'] ?? ''),
        (string)($in['emoji'] ?? ''),
        (string)($in['reaction_uuid'] ?? CommunicationSupport::uuid())
    );
    CommunicationHttp::json(200, array('ok' => true, 'message' => $message));
} catch (CommunicationException $e) {
    CommunicationHttp::fail($e);
} catch (Throwable $e) {
    CommunicationSupport::log('communication.reactions.error', array('error' => 'server_error', 'class' => $e::class, 'message' => $e->getMessage()));
    CommunicationHttp::json(500, array('ok' => false, 'error' => 'Server error', 'error_code' => 'server_error'));
}
