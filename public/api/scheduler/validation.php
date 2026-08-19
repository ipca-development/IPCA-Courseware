<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

scheduler_api_run(static function () use ($schedulerKernel, $schedulerApi): array {
    SchedulerHttp::method('POST');
    return $schedulerApi->validateReservation(
        $schedulerKernel->auth->requireSession(),
        SchedulerHttp::input()
    );
});
