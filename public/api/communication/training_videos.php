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
        $result = match ($action) {
            'feed' => $kernel->trainingVideos->feed($session, (int)($_GET['cursor'] ?? 0)),
            'detail' => $kernel->trainingVideos->detail($session, (string)($_GET['video_uuid'] ?? '')),
            'play', 'playback' => $kernel->trainingVideos->playback(
                $session,
                (string)($_GET['video_uuid'] ?? ''),
                false
            ),
            'download' => $kernel->trainingVideos->playback(
                $session,
                (string)($_GET['video_uuid'] ?? ''),
                true
            ),
            'comments' => $kernel->trainingVideos->comments($session, (string)($_GET['video_uuid'] ?? '')),
            default => throw new CommunicationException('validation_error', 'Unknown action.', 400),
        };
        CommunicationHttp::json(200, array_merge(array('ok' => true), $result));
    }

    CommunicationHttp::method('POST');
    $input = CommunicationHttp::input();
    $action = strtolower(trim((string)($input['action'] ?? '')));
    $videoUuid = (string)($input['video_uuid'] ?? '');
    $result = match ($action) {
        'view' => $kernel->trainingVideos->view($session, $videoUuid),
        'like' => $kernel->trainingVideos->like($session, $videoUuid),
        'unlike' => $kernel->trainingVideos->unlike($session, $videoUuid),
        'comment' => $kernel->trainingVideos->comment(
            $session,
            $videoUuid,
            (string)($input['body'] ?? ''),
            isset($input['comment_uuid']) ? (string)$input['comment_uuid'] : null
        ),
        'progress' => $kernel->trainingVideos->progress(
            $session,
            $videoUuid,
            (int)($input['position_ms'] ?? 0),
            (int)($input['duration_ms'] ?? 0)
        ),
        default => throw new CommunicationException('validation_error', 'Unknown action.', 400),
    };
    CommunicationHttp::json(200, array_merge(array('ok' => true), $result));
} catch (CommunicationException $e) {
    CommunicationHttp::fail($e);
} catch (Throwable $e) {
    CommunicationSupport::log('communication.training_videos.error', array(
        'error' => 'server_error',
        'class' => $e::class,
        'message' => $e->getMessage(),
    ));
    CommunicationHttp::json(500, array('ok' => false, 'error' => 'Server error', 'error_code' => 'server_error'));
}
