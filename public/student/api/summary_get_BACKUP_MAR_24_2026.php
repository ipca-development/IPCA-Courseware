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

$cohortId = (int)($_GET['cohort_id'] ?? 0);
$lessonId  = (int)($_GET['lesson_id'] ?? 0);

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

$stmt = $pdo->prepare("
  SELECT
    summary_html,
    updated_at,
    review_status,
    review_feedback,
    review_notes_by_instructor
  FROM lesson_summaries
  WHERE user_id=? AND cohort_id=? AND lesson_id=?
  LIMIT 1
");
$stmt->execute([$userId, $cohortId, $lessonId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode([
  'ok' => true,
  'summary_html' => $row ? (string)$row['summary_html'] : '',
  'updated_at' => $row ? (string)$row['updated_at'] : null,
  'review_status' => $row ? (string)$row['review_status'] : 'missing',
  'review_feedback' => $row ? (string)($row['review_feedback'] ?? '') : '',
  'review_notes_by_instructor' => $row ? (string)($row['review_notes_by_instructor'] ?? '') : ''
]);