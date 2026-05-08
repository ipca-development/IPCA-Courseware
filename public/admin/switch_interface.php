<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

cw_require_login();

$u = cw_current_user($pdo);
if (!$u || (string)($u['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

$mode = strtolower(trim((string)($_GET['mode'] ?? '')));
if ($mode !== 'admin' && $mode !== 'instructor' && $mode !== 'student') {
    $mode = 'admin';
}

$_SESSION[CW_ADMIN_SHELL_MODE_KEY] = $mode;

$targetStudentId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
if ($mode === 'student' && $targetStudentId > 0) {
    if (cw_users_id_is_student($pdo, $targetStudentId)) {
        $_SESSION[CW_ADMIN_STUDENT_PREVIEW_ID_KEY] = $targetStudentId;
    }
}

redirect(match ($mode) {
    'instructor' => '/instructor/cohorts.php',
    'student' => '/student/dashboard.php',
    default => '/admin/dashboard.php',
});
