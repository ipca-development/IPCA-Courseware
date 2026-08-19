<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/communication/api_bootstrap.php';
require_once __DIR__ . '/../../../src/communication/CommunicationHttp.php';
require_once __DIR__ . '/../../../src/communication/CommunicationKernel.php';
require_once __DIR__ . '/../../../src/remote_session_auth/RemoteSessionAppCodeService.php';

try {
    $kernel = new CommunicationKernel($pdo);
    $session = $kernel->auth->requireSession();
    $codes = new RemoteSessionAppCodeService($pdo);
    $userId = (int)$session['user']['id'];
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        $codeId = CommunicationSupport::requireUuid((string)($_GET['code_id'] ?? ''), 'code_id');
        CommunicationHttp::json(200, $codes->publicEnvelope($userId, $codeId));
    }

    CommunicationHttp::method('POST');
    $in = CommunicationHttp::input();
    $codeId = CommunicationSupport::requireUuid((string)($in['code_id'] ?? ''), 'code_id');
    if (empty($in['viewed'])) {
        throw new CommunicationException('validation_error', 'Mark the code as viewed after you have written it down.', 400);
    }
    $codes->markViewed($userId, $codeId);
    CommunicationHttp::json(200, array('ok' => true));
} catch (CommunicationException $e) {
    CommunicationHttp::fail($e);
} catch (Throwable $e) {
    CommunicationSupport::log('communication.remote_session_code.error', array(
        'error' => 'server_error',
        'class' => $e::class,
        'message' => $e->getMessage(),
    ));
    CommunicationHttp::json(500, array('ok' => false, 'error' => 'Server error', 'error_code' => 'server_error'));
}
