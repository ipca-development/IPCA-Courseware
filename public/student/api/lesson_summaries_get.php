<?php
require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/lesson_summary_service.php';

cw_require_login();

header('Content-Type: application/json; charset=utf-8');

try {
    $u = cw_current_user($pdo);
    $role = (string)($u['role'] ?? '');
    if ($role !== 'student') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }

    $cohortId = (int)($_GET['cohort_id'] ?? 0);
    $lessonId = (int)($_GET['lesson_id'] ?? 0);
    $view = trim((string)($_GET['view'] ?? 'versions'));
    $limit = (int)($_GET['limit'] ?? 12);

    if ($cohortId <= 0) {
        throw new RuntimeException('Missing cohort_id');
    }

    if ($view !== 'versions') {
        throw new RuntimeException('Unsupported view');
    }

    if ($lessonId <= 0) {
        throw new RuntimeException('Missing lesson_id');
    }

    $service = new LessonSummaryService($pdo);
    $versions = $service->getVersionsForLesson((int)$u['id'], $cohortId, $lessonId, $limit);

    echo json_encode([
        'ok' => true,
        'versions' => $versions
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}