<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/progress_test_bank.php';

cw_require_admin();
header('Content-Type: application/json; charset=utf-8');

$lessonId = (int)($_GET['lesson_id'] ?? 0);
$includeRetired = !empty($_GET['include_retired']);

if ($lessonId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'lesson_id required']);
    exit;
}

try {
    $payload = pt_bank_list_questions($pdo, $lessonId, $includeRetired);
    echo json_encode(['ok' => true] + $payload, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
