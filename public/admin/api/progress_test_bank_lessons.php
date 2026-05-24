<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/progress_test_bank.php';

cw_require_admin();
header('Content-Type: application/json; charset=utf-8');

$programId = (int)($_GET['program_id'] ?? 0);
$courseId = (int)($_GET['course_id'] ?? 0);
$lessonId = (int)($_GET['lesson_id'] ?? 0);

if ($programId <= 0 && $courseId <= 0 && $lessonId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'program_id, course_id, or lesson_id required']);
    exit;
}

try {
    $lessons = pt_bank_lessons_coverage($pdo, $programId, $courseId, $lessonId);
    echo json_encode(['ok' => true, 'lessons' => $lessons], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
