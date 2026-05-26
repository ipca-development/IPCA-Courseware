<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/mock_oral/mock_oral_bootstrap.php';
require_once __DIR__ . '/../../../src/mock_oral/MockOralSessionService.php';

cw_require_login();

$u = cw_current_user($pdo);
$role = (string)($u['role'] ?? '');
if ($role !== 'student' && $role !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

$userId = (int)cw_student_view_user_id($pdo, $u);
$sessionId = (int)($_GET['session_id'] ?? 0);
$text = trim((string)($_GET['text'] ?? ''));

if ($sessionId <= 0 || $text === '') {
    http_response_code(400);
    exit('Missing session_id or text');
}
if (strlen($text) > 4000) {
    $text = substr($text, 0, 4000);
}

$svc = new MockOralSessionService($pdo);
$session = $svc->loadSessionForUser($sessionId, $userId);
if (!$session) {
    http_response_code(403);
    exit('Forbidden');
}

$audio = mo_synthesize_speech_mp3($text);
if ($audio === '') {
    http_response_code(502);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Speech synthesis failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: audio/mpeg');
header('Cache-Control: no-store');
echo $audio;
