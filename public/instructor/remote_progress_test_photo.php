<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/progress_test_remote.php';
require_once __DIR__ . '/../../src/instructor_theory_training_report_ai.php';

cw_require_login();

$u = cw_current_user($pdo);
$role = trim((string)($u['role'] ?? ''));
$allowed = ['admin', 'supervisor', 'instructor', 'chief_instructor'];
if (!in_array($role, $allowed, true)) {
    http_response_code(403);
    exit('Forbidden');
}

$authId = (int)($_GET['id'] ?? 0);
if ($authId <= 0) {
    http_response_code(404);
    exit('Not found');
}

ptr_ensure_tables($pdo);
$st = $pdo->prepare('SELECT * FROM progress_test_remote_authorizations WHERE id = ? LIMIT 1');
$st->execute([$authId]);
$auth = $st->fetch(PDO::FETCH_ASSOC);
if (!$auth) {
    http_response_code(404);
    exit('Not found');
}

$cohortId = (int)$auth['cohort_id'];
$studentId = (int)$auth['student_id'];
$photoPath = ptr_photo_absolute_path((string)($auth['student_photo_path'] ?? ''));
if ($photoPath === null) {
    http_response_code(404);
    exit('Photo not found');
}

if ($role !== 'admin') {
    try {
        InstructorTheoryTrainingReportAi::verifyCohortStudent($pdo, $cohortId, $studentId);
    } catch (Throwable $e) {
        http_response_code(403);
        exit('Forbidden');
    }
}

$mime = 'image/jpeg';
$ext = strtolower(pathinfo($photoPath, PATHINFO_EXTENSION));
if ($ext === 'png') {
    $mime = 'image/png';
} elseif ($ext === 'webp') {
    $mime = 'image/webp';
}

header('Content-Type: ' . $mime);
header('Cache-Control: private, no-store');
header('Content-Length: ' . (string)filesize($photoPath));
readfile($photoPath);
exit;
