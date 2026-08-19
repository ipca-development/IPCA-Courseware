<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

scheduler_api_run(static function () use ($schedulerKernel, $schedulerApi): array {
    SchedulerHttp::method('GET');
    return $schedulerApi->search(
        $schedulerKernel->auth->requireSession(),
        (string)($_GET['q'] ?? ''),
        (int)($_GET['limit'] ?? 20)
    );
});
