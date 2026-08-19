<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

scheduler_api_run(static function () use ($schedulerKernel, $schedulerApi): array {
    SchedulerHttp::method('GET');
    $session = $schedulerKernel->auth->requireSession();
    return $schedulerApi->scheduleRange(
        $session,
        (string)($_GET['start'] ?? ''),
        (string)($_GET['end'] ?? ''),
        array(
            'aircraft_id' => $_GET['aircraft_id'] ?? null,
            'participant_user_id' => $_GET['participant_user_id'] ?? null,
            'cohort_id' => $_GET['cohort_id'] ?? null,
            'reservation_type' => $_GET['reservation_type'] ?? null,
        )
    );
});
