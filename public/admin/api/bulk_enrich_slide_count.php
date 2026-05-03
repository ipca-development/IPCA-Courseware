<?php
/**
 * JSON slide count for the same scope as bulk_enrich_run.php (used to auto-split main-form runs).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

cw_require_admin();

$scope = (string)($_GET['scope'] ?? 'course');
$courseId = (int)($_GET['course_id'] ?? 0);
$lessonId = (int)($_GET['lesson_id'] ?? 0);

if ($courseId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'course_id required'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($scope === 'lesson' && $lessonId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'lesson_id required for scope=lesson'], JSON_UNESCAPED_UNICODE);
    exit;
}

$sql = "
  SELECT COUNT(*)
  FROM slides s
  INNER JOIN lessons l ON l.id = s.lesson_id
  WHERE COALESCE(s.is_deleted, 0) = 0
";
$params = [];

if ($scope === 'course') {
    $sql .= ' AND l.course_id = ? ';
    $params[] = $courseId;
} else {
    $sql .= ' AND l.id = ? ';
    $params[] = $lessonId;
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$n = (int) $stmt->fetchColumn();

echo json_encode(['ok' => true, 'slide_count' => $n], JSON_UNESCAPED_UNICODE);
