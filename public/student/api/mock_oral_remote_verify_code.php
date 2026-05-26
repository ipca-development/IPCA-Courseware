<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/courseware_progression_v2.php';

cw_require_login();
header('Content-Type: application/json; charset=utf-8');

$u = cw_current_user($pdo);
if ((string)($u['role'] ?? '') !== 'student' && (string)($u['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = $_POST;
}

$userId = (int)cw_student_view_user_id($pdo, $u);
$cohortId = (int)($body['cohort_id'] ?? 0);
$areaId = (int)($body['area_id'] ?? 0);
$code = trim((string)($body['code'] ?? ''));

try {
    if ($code === '') {
        throw new RuntimeException('Enter your 6-digit code.');
    }
    $engine = new CoursewareProgressionV2($pdo);
    $result = $engine->verifyMockOralCodeAndPrepareSession(
        $userId,
        $cohortId,
        $areaId,
        $code,
        (string)($_SERVER['HTTP_COOKIE'] ?? '')
    );
    echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
