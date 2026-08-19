<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

scheduler_api_run(static function () use ($schedulerKernel, $schedulerApi): array {
    $method = SchedulerHttp::method(array('GET', 'POST', 'PATCH', 'DELETE'));
    $session = $schedulerKernel->auth->requireSession();
    $reservationUuid = strtolower(trim((string)($_GET['reservation_uuid'] ?? '')));
    if ($method === 'GET') {
        return $schedulerApi->reservationDetail($session, $reservationUuid);
    }

    $input = SchedulerHttp::input();
    if ($method === 'POST') {
        return $schedulerApi->createReservation($session, $input, SchedulerHttp::idempotencyKey());
    }
    if ($reservationUuid === '') {
        $reservationUuid = strtolower(trim((string)($input['reservation_uuid'] ?? '')));
    }
    if ($method === 'DELETE') {
        return $schedulerApi->cancelReservation(
            $session,
            $reservationUuid,
            isset($input['expected_updated_at']) ? (string)$input['expected_updated_at'] : null
        );
    }
    $action = strtolower(trim((string)($input['action'] ?? 'update')));
    if ($action === 'reschedule') {
        return $schedulerApi->rescheduleReservation($session, $reservationUuid, $input);
    }
    return $schedulerApi->updateReservation($session, $reservationUuid, $input);
}, (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') ? 201 : 200);
