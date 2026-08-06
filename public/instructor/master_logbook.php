<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

cw_require_login();
$user = cw_current_user($pdo) ?: array();
$role = strtolower(trim((string)($user['role'] ?? '')));
if (!in_array($role, array('admin', 'supervisor', 'instructor', 'chief_instructor'), true)) {
    redirect(cw_home_path_for_role($role));
}

$cvrMasterLogbookContext = array(
    'audience' => 'instructor',
    'base_path' => '/instructor/master_logbook.php',
    'can_enroll' => false,
    'can_remove' => false,
    'can_edit' => true,
    'show_audio' => false,
    'show_garmin' => false,
    'show_reconstruction' => false,
);

require __DIR__ . '/../admin/master_logbook_intake.php';
