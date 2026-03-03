<?php
require_once __DIR__ . '/../../../src/bootstrap.php';

cw_require_login();

$u = cw_current_user($pdo);
$role = (string)($u['role'] ?? '');
if ($role !== 'student' && $role !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Forbidden']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    echo json_encode(['ok'=>false,'error'=>'Invalid JSON']);
    exit;
}

$cohortId = (int)($data['cohort_id'] ?? 0);
$lessonId  = (int)($data['lesson_id'] ?? 0);
$summaryHtml = (string)($data['summary_html'] ?? '');

if ($cohortId <= 0 || $lessonId <= 0) {
    echo json_encode(['ok'=>false,'error'=>'Missing cohort_id or lesson_id']);
    exit;
}

$userId = (int)$u['id'];

if ($role === 'student') {
    $chk = $pdo->prepare("SELECT 1 FROM cohort_students WHERE cohort_id=? AND user_id=? LIMIT 1");
    $chk->execute([$cohortId, $userId]);
    if (!$chk->fetchColumn()) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'error'=>'Forbidden']);
        exit;
    }
}

// basic anti-empty
if (trim(strip_tags($summaryHtml)) === '') {
    echo json_encode(['ok'=>false,'error'=>'Summary is empty']);
    exit;
}

$stmt = $pdo->prepare("
  INSERT INTO lesson_summaries (user_id, cohort_id, lesson_id, summary_html, summary_plain)
  VALUES (?,?,?,?,?)
  ON DUPLICATE KEY UPDATE
    summary_html=VALUES(summary_html),
    summary_plain=VALUES(summary_plain),
    updated_at=CURRENT_TIMESTAMP
");

$plain = trim(preg_replace('/\s+/', ' ', strip_tags($summaryHtml)));

$stmt->execute([$userId, $cohortId, $lessonId, $summaryHtml, $plain]);

echo json_encode(['ok'=>true]);