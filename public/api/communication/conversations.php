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
        $uuid = trim((string)($_GET['conversation_uuid'] ?? ''));
        if ($uuid !== '') {
            CommunicationHttp::json(200, array(
                'ok' => true,
                'conversation' => $kernel->conversations->getForUser($session, $uuid),
            ));
        }
        CommunicationHttp::json(200, array(
            'ok' => true,
            'conversations' => $kernel->conversations->listForUser($session),
        ));
    }

    CommunicationHttp::method('POST');
    $in = CommunicationHttp::input();
    $type = strtolower(trim((string)($in['type'] ?? 'direct')));
    if ($type === 'direct') {
        $conversation = $kernel->conversations->createDirect($session, (string)($in['peer_user_uuid'] ?? ''));
        CommunicationHttp::json(200, array('ok' => true, 'conversation' => $conversation));
    }
    if ($type === 'group') {
        $members = $in['member_user_uuids'] ?? array();
        if (!is_array($members)) {
            $members = array();
        }
        $conversation = $kernel->conversations->createGroup(
            $session,
            (string)($in['title'] ?? ''),
            array_map('strval', $members)
        );
        CommunicationHttp::json(200, array('ok' => true, 'conversation' => $conversation));
    }
    if ($type === 'group_members') {
        $add = $in['add_user_uuids'] ?? array();
        $remove = $in['remove_user_uuids'] ?? array();
        if (!is_array($add)) {
            $add = array();
        }
        if (!is_array($remove)) {
            $remove = array();
        }
        $conversation = $kernel->conversations->updateGroupMembers(
            $session,
            (string)($in['conversation_uuid'] ?? ''),
            array_map('strval', $add),
            array_map('strval', $remove)
        );
        CommunicationHttp::json(200, array('ok' => true, 'conversation' => $conversation));
    }
    throw new CommunicationException('validation_error', 'Unsupported conversation type.', 400);
} catch (CommunicationException $e) {
    CommunicationHttp::fail($e);
} catch (Throwable $e) {
    CommunicationSupport::log('communication.conversations.error', array('error' => 'server_error', 'class' => $e::class, 'message' => $e->getMessage()));
    CommunicationHttp::json(500, array('ok' => false, 'error' => 'Server error', 'error_code' => 'server_error'));
}
