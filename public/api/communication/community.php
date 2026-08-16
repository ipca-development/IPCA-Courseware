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
        $action = strtolower(trim((string)($_GET['action'] ?? 'feed')));
        if ($action === 'feed') {
            CommunicationHttp::json(200, array_merge(
                array('ok' => true),
                $kernel->community->feed($session, (int)($_GET['cursor'] ?? 0))
            ));
        }
        if ($action === 'post') {
            CommunicationHttp::json(200, array_merge(
                array('ok' => true),
                $kernel->community->post($session, (string)($_GET['post_uuid'] ?? ''))
            ));
        }
        if ($action === 'comments') {
            CommunicationHttp::json(200, array_merge(
                array('ok' => true),
                $kernel->community->comments($session, (string)($_GET['post_uuid'] ?? ''))
            ));
        }
        if ($action === 'download') {
            CommunicationHttp::json(200, array_merge(
                array('ok' => true),
                $kernel->community->downloadMedia($session, (string)($_GET['media_uuid'] ?? ''))
            ));
        }
        throw new CommunicationException('validation_error', 'Unknown action.', 400);
    }

    CommunicationHttp::method('POST');
    $in = CommunicationHttp::input();
    $action = strtolower(trim((string)($in['action'] ?? 'create')));

    if ($action === 'presign') {
        CommunicationHttp::json(200, array_merge(
            array('ok' => true),
            $kernel->community->presignMedia(
                $session,
                (string)($in['filename'] ?? ''),
                (string)($in['mime_type'] ?? ''),
                (int)($in['byte_size'] ?? 0),
                (int)($in['duration_ms'] ?? 0),
                isset($in['media_uuid']) ? (string)$in['media_uuid'] : null
            )
        ));
    }
    if ($action === 'complete') {
        CommunicationHttp::json(200, array_merge(
            array('ok' => true),
            $kernel->community->completeMedia($session, (string)($in['media_uuid'] ?? ''))
        ));
    }
    if ($action === 'create') {
        $media = $in['media_uuids'] ?? array();
        if (!is_array($media)) {
            $media = array();
        }
        CommunicationHttp::json(200, array_merge(
            array('ok' => true),
            $kernel->community->create(
                $session,
                (string)($in['caption'] ?? ''),
                $media,
                isset($in['post_uuid']) ? (string)$in['post_uuid'] : null,
                (string)($in['body'] ?? '')
            )
        ));
    }
    if ($action === 'like') {
        CommunicationHttp::json(200, array_merge(
            array('ok' => true),
            $kernel->community->like($session, (string)($in['post_uuid'] ?? ''))
        ));
    }
    if ($action === 'unlike') {
        CommunicationHttp::json(200, array_merge(
            array('ok' => true),
            $kernel->community->unlike($session, (string)($in['post_uuid'] ?? ''))
        ));
    }
    if ($action === 'comment') {
        CommunicationHttp::json(200, array_merge(
            array('ok' => true),
            $kernel->community->comment(
                $session,
                (string)($in['post_uuid'] ?? ''),
                (string)($in['body'] ?? ''),
                isset($in['comment_uuid']) ? (string)$in['comment_uuid'] : null
            )
        ));
    }
    if ($action === 'delete') {
        CommunicationHttp::json(200, array_merge(
            array('ok' => true),
            $kernel->community->deletePost($session, (string)($in['post_uuid'] ?? ''))
        ));
    }
    if ($action === 'report') {
        CommunicationHttp::json(200, array_merge(
            array('ok' => true),
            $kernel->community->report(
                $session,
                (string)($in['post_uuid'] ?? ''),
                (string)($in['reason'] ?? ''),
                (string)($in['details'] ?? '')
            )
        ));
    }

    throw new CommunicationException('validation_error', 'Unknown action.', 400);
} catch (CommunicationException $e) {
    CommunicationHttp::fail($e);
} catch (Throwable $e) {
    CommunicationSupport::log('communication.community.error', array('error' => 'server_error', 'class' => $e::class, 'message' => $e->getMessage()));
    CommunicationHttp::json(500, array('ok' => false, 'error' => 'Server error', 'error_code' => 'server_error'));
}
