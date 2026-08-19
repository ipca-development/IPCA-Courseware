<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/communication/api_bootstrap.php';
require_once __DIR__ . '/../../../src/communication/CommunicationKernel.php';
require_once __DIR__ . '/../../../src/communication/CommunicationHttp.php';
require_once __DIR__ . '/../../../src/scheduler/SchedulerHttp.php';
require_once __DIR__ . '/../../../src/scheduler/SchedulerApiService.php';

$schedulerKernel = new CommunicationKernel($pdo);
$schedulerApi = new SchedulerApiService($pdo);

/**
 * @param callable():array<string,mixed> $operation
 */
function scheduler_api_run(callable $operation, int $successStatus = 200): never
{
    try {
        SchedulerHttp::json($successStatus, $operation());
    } catch (CommunicationException $e) {
        $code = in_array($e->errorCode, array('account_ineligible'), true)
            ? 'account_ineligible'
            : 'unauthenticated';
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
            error_log('Scheduler API failure: ' . $e::class . ': ' . $e->getMessage());
        }
        SchedulerHttp::json($mapped->httpStatus, $mapped->payload(SchedulerHttp::requestId()));
    }
}
