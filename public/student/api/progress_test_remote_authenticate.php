<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/courseware_progression_v2.php';

cw_require_login();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function ptr_auth_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ptr_auth_json(['ok' => false, 'error' => 'POST required'], 405);
    }

    $u = cw_current_user($pdo);
    $role = (string)($u['role'] ?? '');
    if ($role !== 'student' && $role !== 'admin') {
        ptr_auth_json(['ok' => false, 'error' => 'Forbidden'], 403);
    }

    $token = trim((string)($_POST['token'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if ($token === '' || $password === '') {
        ptr_auth_json(['ok' => false, 'error' => 'Token and password are required.'], 400);
    }

    $userId = (int)cw_student_view_user_id($pdo, $u);
    if ($userId <= 0) {
        ptr_auth_json(['ok' => false, 'error' => 'Invalid user'], 403);
    }

    $photoBinary = '';
    $photoMime = '';
    if (!empty($_FILES['photo']['tmp_name']) && is_uploaded_file((string)$_FILES['photo']['tmp_name'])) {
        $photoBinary = (string)file_get_contents((string)$_FILES['photo']['tmp_name']);
        $photoMime = trim((string)($_FILES['photo']['type'] ?? ''));
        if ($photoMime === '') {
            $photoMime = 'image/jpeg';
        }
    } else {
        $dataUrl = trim((string)($_POST['photo_data_url'] ?? ''));
        if ($dataUrl !== '' && preg_match('#^data:(image/(?:jpeg|png|webp));base64,#i', $dataUrl, $m)) {
            $photoMime = strtolower($m[1]);
            $photoBinary = (string)base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1), true);
        }
    }

    if ($photoBinary === '') {
        ptr_auth_json(['ok' => false, 'error' => 'A live photo is required.'], 400);
    }

    $engine = new CoursewareProgressionV2($pdo);
    $result = $engine->authenticateRemoteProgressTestToken($token, $userId, $password, $photoBinary, $photoMime);
    ptr_auth_json($result);
} catch (Throwable $e) {
    ptr_auth_json(['ok' => false, 'error' => $e->getMessage()], 400);
}
