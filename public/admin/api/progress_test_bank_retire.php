<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/progress_test_bank.php';

cw_require_admin();
header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '{}', true);
if (!is_array($data)) $data = [];

$lessonId = (int)($data['lesson_id'] ?? 0);
$questionIds = $data['question_ids'] ?? [];
$reason = trim((string)($data['reason'] ?? 'admin_bulk'));

if ($lessonId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'lesson_id required']);
    exit;
}
if (!is_array($questionIds)) $questionIds = [];

try {
    $result = pt_bank_retire_questions($pdo, $lessonId, $questionIds, $reason !== '' ? $reason : 'admin_bulk');
    $list = pt_bank_list_questions($pdo, $lessonId, false);
    echo json_encode(['ok' => true, 'result' => $result, 'bank' => $list['bank'], 'questions' => $list['questions']], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
