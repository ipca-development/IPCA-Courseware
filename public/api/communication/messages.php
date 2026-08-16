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
        $conversationUuid = (string)($_GET['conversation_uuid'] ?? '');
        $beforeSeq = isset($_GET['before_seq']) ? (int)$_GET['before_seq'] : null;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        CommunicationHttp::json(200, array(
            'ok' => true,
            'messages' => $kernel->messages->page($session, $conversationUuid, $beforeSeq, $limit),
        ));
    }

    CommunicationHttp::method('POST');
    $in = CommunicationHttp::input();
    $attachmentUuids = $in['attachment_uuids'] ?? array();
    if (!is_array($attachmentUuids)) {
        $attachmentUuids = array();
    }
    $message = $kernel->messages->send(
        $session,
        (string)($in['conversation_uuid'] ?? ''),
        (string)($in['client_id'] ?? ''),
        (string)($in['body'] ?? ''),
        $attachmentUuids,
        0,
        isset($in['reply_to_message_uuid']) && (string)$in['reply_to_message_uuid'] !== ''
            ? (string)$in['reply_to_message_uuid']
            : null
    );
    CommunicationHttp::json(200, array('ok' => true, 'message' => $message));
} catch (CommunicationException $e) {
    CommunicationHttp::fail($e);
} catch (Throwable $e) {
    CommunicationSupport::log('communication.messages.error', array('error' => 'server_error', 'class' => $e::class, 'message' => $e->getMessage()));
    CommunicationHttp::json(500, array('ok' => false, 'error' => 'Server error', 'error_code' => 'server_error'));
}
