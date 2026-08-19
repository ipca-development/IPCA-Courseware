<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/communication/api_bootstrap.php';
require_once __DIR__ . '/../../../src/communication/CommunicationKernel.php';
require_once __DIR__ . '/../../../src/scheduler/SchedulerHttp.php';

try {
    SchedulerHttp::method('POST');
    $input = SchedulerHttp::input();
    $kernel = new CommunicationKernel($pdo);
    $action = strtolower(trim((string)($input['action'] ?? 'login')));
    if ($action === 'login') {
        $device = is_array($input['device'] ?? null) ? $input['device'] : array();
        SchedulerHttp::json(200, $kernel->auth->login(
            (string)($input['email'] ?? ''),
            (string)($input['password'] ?? ''),
            $device,
            false
        ));
    }
    if ($action === 'logout') {
        $session = $kernel->auth->requireSession();
        $kernel->auth->logout($session);
        SchedulerHttp::json(200, array('ok' => true));
    }
    throw new SchedulerApiException('invalid_request', 'Unknown authentication action.', 400, false, true);
} catch (CommunicationException $e) {
    $code = $e->errorCode === 'account_ineligible' ? 'account_ineligible' : 'unauthenticated';
    SchedulerHttp::json($e->httpStatus, (new SchedulerApiException(
        $code,
        $e->getMessage(),
        $e->httpStatus,
        false,
        false,
        $e
    ))->payload(SchedulerHttp::requestId()));
} catch (Throwable $e) {
    $mapped = SchedulerApiException::fromThrowable($e);
    if ($mapped->errorCode === 'server_error') {
        error_log('Scheduler auth API failure: ' . $e::class . ': ' . $e->getMessage());
    }
    SchedulerHttp::json($mapped->httpStatus, $mapped->payload(SchedulerHttp::requestId()));
}
