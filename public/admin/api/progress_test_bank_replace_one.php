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
$count = max(1, min(10, (int)($data['count'] ?? 1)));
$kindPreference = trim((string)($data['kind_preference'] ?? ''));

if ($lessonId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'lesson_id required']);
    exit;
}

try {
    $results = [];
    for ($i = 0; $i < $count; $i++) {
        $results[] = pt_bank_replace_one($pdo, $lessonId, $kindPreference !== '' ? $kindPreference : null);
    }
    $list = pt_bank_list_questions($pdo, $lessonId, false);
    echo json_encode([
        'ok' => true,
        'replacements' => $results,
        'bank' => $list['bank'],
        'questions' => $list['questions'],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
