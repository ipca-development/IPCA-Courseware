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

$userId = (int)cw_student_view_user_id($pdo, $u);
$token = trim((string)($_POST['token'] ?? ''));
$password = (string)($_POST['password'] ?? '');

$photoBinary = '';
$photoMime = 'image/jpeg';
if (!empty($_FILES['photo']['tmp_name']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
    $photoBinary = (string)file_get_contents($_FILES['photo']['tmp_name']);
    $photoMime = mime_content_type($_FILES['photo']['tmp_name']) ?: 'image/jpeg';
} elseif (!empty($_POST['photo_data_url'])) {
    $dataUrl = (string)$_POST['photo_data_url'];
    if (preg_match('#^data:(image/[a-zA-Z0-9.+-]+);base64,#', $dataUrl, $m)) {
        $photoMime = $m[1];
        $photoBinary = base64_decode(substr($dataUrl, strlen($m[0])), true) ?: '';
    }
}

try {
    if ($token === '' || $password === '' || $photoBinary === '') {
        throw new RuntimeException('Password and live photo are required.');
    }
    $engine = new CoursewareProgressionV2($pdo);
    $result = $engine->authenticateMockOralToken($token, $userId, $password, $photoBinary, $photoMime);
    echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
