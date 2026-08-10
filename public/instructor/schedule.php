<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

cw_require_flight_schedule_editor();
$user = cw_current_user($pdo) ?: array();
$role = strtolower(trim((string)($user['role'] ?? '')));

// Admins keep the admin Schedule entry point; staff use this instructor surface.
if ($role === 'admin') {
    redirect('/admin/schedule.php' . (isset($_SERVER['QUERY_STRING']) && (string)$_SERVER['QUERY_STRING'] !== ''
        ? ('?' . (string)$_SERVER['QUERY_STRING'])
        : ''));
}

$flightScheduleContext = array(
    'audience' => 'instructor',
    'base_path' => '/instructor/schedule.php',
    'actor_type' => 'instructor',
);

require __DIR__ . '/../admin/schedule.php';
