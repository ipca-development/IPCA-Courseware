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
        CommunicationHttp::json(200, array(
            'ok' => true,
            'messages' => $kernel->systemMessages->evidence($session, isset($_GET['limit']) ? (int)$_GET['limit'] : 50),
        ));
    }

    CommunicationHttp::method('POST');
    $in = CommunicationHttp::input();
    $recipients = $in['recipient_user_uuids'] ?? array();
    if (!is_array($recipients)) {
        $recipients = array();
    }
    $message = $kernel->systemMessages->publishFromSession(
        $session,
        (string)($in['actor_key'] ?? 'ipca_administration'),
        (string)($in['body'] ?? ''),
        array_map('strval', $recipients),
        !empty($in['requires_acknowledgement']),
        !empty($in['reply_allowed']),
        isset($in['source_type']) ? (string)$in['source_type'] : null,
        isset($in['source_id']) ? (string)$in['source_id'] : null,
        isset($in['source_event_id']) ? (string)$in['source_event_id'] : null
    );
    CommunicationHttp::json(200, array('ok' => true, 'message' => $message));
} catch (CommunicationException $e) {
    CommunicationHttp::fail($e);
} catch (Throwable $e) {
    CommunicationSupport::log('communication.system_messages.error', array('error' => 'server_error', 'class' => $e::class, 'message' => $e->getMessage()));
    CommunicationHttp::json(500, array('ok' => false, 'error' => 'Server error', 'error_code' => 'server_error'));
}
