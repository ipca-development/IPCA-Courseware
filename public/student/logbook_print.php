<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/flight_training/AdminLogbookService.php';

cw_require_student();

$user = cw_current_user($pdo) ?: array();
$studentUserId = cw_student_view_user_id($pdo, $user);
$service = new AdminLogbookService($pdo);
$logbookId = $service->logbookIdForStudent($studentUserId);

$_GET['student_view'] = '1';
$_GET['user_id'] = (string)$studentUserId;
$_GET['logbook_id'] = (string)$logbookId;

require __DIR__ . '/../admin/flight_training/logbooks/print.php';
