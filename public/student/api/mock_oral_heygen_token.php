<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/mock_oral/MockOralSessionService.php';
require_once __DIR__ . '/../../../src/mock_oral/HeyGenLiveAvatarService.php';
require_once __DIR__ . '/../../../src/mock_oral/SessionQuotaService.php';
require_once __DIR__ . '/../../../src/remote_session_auth/remote_session_auth_constants.php';

cw_require_login();
header('Content-Type: application/json; charset=utf-8');

$u = cw_current_user($pdo);
if ((string)($u['role'] ?? '') !== 'student' && (string)($u['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$userId = (int)cw_student_view_user_id($pdo, $u);
$sessionId = (int)($_GET['session_id'] ?? $_POST['session_id'] ?? 0);

try {
    $sessionSvc = new MockOralSessionService($pdo);
    $session = $sessionSvc->loadSessionForUser($sessionId, $userId);
    if (!$session) {
        throw new RuntimeException('Session not found.');
    }
    if ((string)$session['status'] !== 'ready' && (string)$session['status'] !== 'in_progress') {
        throw new RuntimeException('Session is not eligible for HeyGen token.');
    }

    $heygen = new HeyGenLiveAvatarService();
    $token = $heygen->mintSessionToken($sessionId, $userId);
    $pdo->prepare('UPDATE mock_oral_sessions SET heygen_token_issued_at = UTC_TIMESTAMP(), heygen_session_id = ? WHERE id = ?')
        ->execute([(string)($token['token'] ?? 'fallback'), $sessionId]);

    if (($token['presentation_mode'] ?? '') === 'heygen') {
        $quotaSvc = new SessionQuotaService($pdo);
        $quotaSvc->recordHeygenMinutes($userId, (int)$session['cohort_id'], RSA_SESSION_MAX_DURATION_SEC / 60.0);
    }

    echo json_encode(['ok' => true] + $token, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
