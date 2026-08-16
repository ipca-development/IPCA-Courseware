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
        $action = strtolower(trim((string)($_GET['action'] ?? 'download')));
        if ($action !== 'download') {
            throw new CommunicationException('validation_error', 'Unknown action.', 400);
        }
        CommunicationHttp::json(200, array_merge(
            array('ok' => true),
            $kernel->attachments->download($session, (string)($_GET['attachment_uuid'] ?? ''))
        ));
    }

    CommunicationHttp::method('POST');
    $in = CommunicationHttp::input();
    $action = strtolower(trim((string)($in['action'] ?? 'presign')));

    if ($action === 'presign') {
        CommunicationHttp::json(200, array_merge(
            array('ok' => true),
            $kernel->attachments->presignPut(
                $session,
                (string)($in['conversation_uuid'] ?? ''),
                (string)($in['filename'] ?? ''),
                (string)($in['mime_type'] ?? ''),
                (int)($in['byte_size'] ?? 0),
                isset($in['attachment_uuid']) ? (string)$in['attachment_uuid'] : null
            )
        ));
    }

    if ($action === 'complete') {
        CommunicationHttp::json(200, array_merge(
            array('ok' => true),
            $kernel->attachments->complete($session, (string)($in['attachment_uuid'] ?? ''))
        ));
    }

    if ($action === 'download') {
        CommunicationHttp::json(200, array_merge(
            array('ok' => true),
            $kernel->attachments->download($session, (string)($in['attachment_uuid'] ?? ''))
        ));
    }

    throw new CommunicationException('validation_error', 'Unknown action.', 400);
} catch (CommunicationException $e) {
    CommunicationHttp::fail($e);
} catch (Throwable $e) {
    CommunicationSupport::log('communication.attachments.error', array('error' => 'server_error', 'class' => $e::class, 'message' => $e->getMessage()));
    CommunicationHttp::json(500, array('ok' => false, 'error' => 'Server error', 'error_code' => 'server_error'));
}
