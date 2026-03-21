<?php
require_once __DIR__ . '/../../../src/bootstrap.php';

cw_require_login();

header('Content-Type: application/json; charset=utf-8');

try {
    $u = cw_current_user($pdo);
    $role = (string)($u['role'] ?? '');
    if ($role !== 'student' && $role !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok'=>false,'error'=>'Forbidden']);
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
    $lessonId  = (int)($data['lesson_id'] ?? 0);
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
            echo json_encode(['ok'=>false,'error'=>'Not enrolled in this cohort']);
            exit;
        }
    }

    $plain = trim(preg_replace('/\s+/', ' ', strip_tags($summaryHtml)));

    // Allow partial saves, but avoid storing pure empties
    if ($plain === '') {
        echo json_encode(['ok'=>false,'error'=>'Summary is empty']);
        exit;
    }

    $existingSt = $pdo->prepare("
      SELECT review_status
      FROM lesson_summaries
      WHERE user_id=? AND cohort_id=? AND lesson_id=?
      LIMIT 1
    ");
    $existingSt->execute([$userId, $cohortId, $lessonId]);
    $existing = $existingSt->fetch(PDO::FETCH_ASSOC);

    $existingReviewStatus = (string)($existing['review_status'] ?? '');
    $newReviewStatus = $existingReviewStatus !== '' ? $existingReviewStatus : 'pending';

    if ($existingReviewStatus === 'needs_revision') {
        $newReviewStatus = 'pending';
    }

    $stmt = $pdo->prepare("
      INSERT INTO lesson_summaries
      (
        user_id,
        cohort_id,
        lesson_id,
        summary_html,
        summary_plain,
        review_status
      )
      VALUES (?,?,?,?,?,?)
      ON DUPLICATE KEY UPDATE
        summary_html=VALUES(summary_html),
        summary_plain=VALUES(summary_plain),
        review_status=VALUES(review_status),
        updated_at=CURRENT_TIMESTAMP
    ");
    $stmt->execute([$userId, $cohortId, $lessonId, $summaryHtml, $plain, $newReviewStatus]);

    echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}