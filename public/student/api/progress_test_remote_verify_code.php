<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/courseware_progression_v2.php';

cw_require_login();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function ptr_verify_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ptr_verify_json(['ok' => false, 'error' => 'POST required'], 405);
    }

    $u = cw_current_user($pdo);
    $role = (string)($u['role'] ?? '');
    if ($role !== 'student' && $role !== 'admin') {
        ptr_verify_json(['ok' => false, 'error' => 'Forbidden'], 403);
    }

    $raw = (string)file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $data = $_POST;
    }

    $cohortId = (int)($data['cohort_id'] ?? 0);
    $lessonId = (int)($data['lesson_id'] ?? 0);
    $code = trim((string)($data['code'] ?? ''));
    if ($cohortId <= 0 || $lessonId <= 0) {
        ptr_verify_json(['ok' => false, 'error' => 'Missing cohort_id or lesson_id'], 400);
    }

    $userId = $role === 'admin' ? (int)($data['user_id'] ?? $u['id']) : (int)$u['id'];
    if ($userId <= 0) {
        ptr_verify_json(['ok' => false, 'error' => 'Invalid user'], 403);
    }

    if ($role === 'student') {
        $en = $pdo->prepare("SELECT 1 FROM cohort_students WHERE cohort_id = ? AND user_id = ? AND status = 'active' LIMIT 1");
        $en->execute([$cohortId, $userId]);
        if (!$en->fetchColumn()) {
            ptr_verify_json(['ok' => false, 'error' => 'Not actively enrolled'], 403);
        }
    }

    $cookieHeader = (string)($_SERVER['HTTP_COOKIE'] ?? '');
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $engine = new CoursewareProgressionV2($pdo);
    $result = $engine->verifyRemoteProgressTestCodeAndStartAttempt(
        $userId,
        $cohortId,
        $lessonId,
        $code,
        $cookieHeader
    );
    ptr_verify_json($result);
} catch (Throwable $e) {
    ptr_verify_json(['ok' => false, 'error' => $e->getMessage()], 400);
}
