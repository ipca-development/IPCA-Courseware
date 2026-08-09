<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/FlightScheduleService.php';

cw_require_flight_schedule_editor();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $date = trim((string)($_GET['date'] ?? ''));
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('A valid schedule date is required.');
    }
    $reservations = (new FlightScheduleService($pdo))->listSlots($date, $date);
    echo json_encode(array(
        'ok' => true,
        'date' => $date,
        'reservations' => $reservations,
        'refreshed_at' => gmdate('c'),
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(array('ok' => false, 'error' => $e->getMessage()));
} catch (Throwable $e) {
    error_log('Flight schedule live refresh failed: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(array('ok' => false, 'error' => 'Schedule refresh is temporarily unavailable.'));
}
