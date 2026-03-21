<?php
require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/lesson_summary_service.php';

cw_require_login();

header('Content-Type: application/json; charset=utf-8');

try {
    $u = cw_current_user($pdo);
    $role = (string)($u['role'] ?? '');
    if ($role !== 'student' && $role !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        throw new RuntimeException('Empty request body');
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid JSON');
    }

    $cohortId = (int)($data['cohort_id'] ?? 0);
    $lessonId = (int)($data['lesson_id'] ?? 0);
    $summaryHtml = (string)($data['summary_html'] ?? '');

    if ($cohortId <= 0 || $lessonId <= 0) {
        throw new RuntimeException('Missing cohort_id or lesson_id');
    }

    $userId = (int)$u['id'];

    // Student must be enrolled in cohort
    if ($role === 'student') {
        $chk = $pdo->prepare("SELECT 1 FROM cohort_students WHERE cohort_id=? AND user_id=? LIMIT 1");
        $chk->execute([$cohortId, $userId]);
        if (!$chk->fetchColumn()) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Not enrolled in this cohort']);
            exit;
        }
    }

    $service = new LessonSummaryService($pdo);

    $result = $service->saveSummary(
        $userId,
        $cohortId,
        $lessonId,
        $summaryHtml,
        'student'
    );

    if (!empty($result['ok'])) {
        echo json_encode($result);
        exit;
    }

    echo json_encode($result);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}