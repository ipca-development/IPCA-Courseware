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
    $device = $kernel->auth->upsertDevice((int)$session['user']['id'], array(
        'device_uuid' => (string)($in['device_uuid'] ?? $session['device']['device_uuid']),
        'platform' => (string)($in['platform'] ?? $session['device']['platform']),
        'model' => (string)($in['model'] ?? $session['device']['model'] ?? ''),
        'os_version' => (string)($in['os_version'] ?? $session['device']['os_version'] ?? ''),
        'app_version' => (string)($in['app_version'] ?? $session['device']['app_version'] ?? ''),
    ));
    CommunicationHttp::json(200, array(
        'ok' => true,
        'device' => $kernel->auth->publicDevice($device),
    ));
} catch (CommunicationException $e) {
    CommunicationHttp::fail($e);
} catch (Throwable $e) {
    CommunicationSupport::log('communication.devices.error', array('error' => 'server_error', 'class' => $e::class, 'message' => $e->getMessage()));
    CommunicationHttp::json(500, array('ok' => false, 'error' => 'Server error', 'error_code' => 'server_error'));
}
