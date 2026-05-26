<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/mock_oral/mock_oral_prep.php';

cw_require_login();
header('Content-Type: application/json; charset=utf-8');

function mo_prep_api_out(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $u = cw_current_user($pdo);
    $role = (string)($u['role'] ?? '');
    if ($role !== 'student' && $role !== 'admin') {
        mo_prep_api_out(['ok' => false, 'error' => 'Forbidden'], 403);
    }

    $sessionId = (int)($_GET['session_id'] ?? 0);
    if ($sessionId <= 0) {
        mo_prep_api_out(['ok' => false, 'error' => 'Missing session_id']);
    }

    $userId = (int)cw_student_view_user_id($pdo, $u);
    $st = $pdo->prepare('SELECT * FROM mock_oral_sessions WHERE id = ? AND user_id = ? LIMIT 1');
    $st->execute([$sessionId, $userId]);
    $session = $st->fetch(PDO::FETCH_ASSOC);
    if (!$session) {
        mo_prep_api_out(['ok' => false, 'error' => 'Session not found']);
    }

    $prepared = mo_prep_session_is_ready($session);
    $display = mo_prep_progress_label($session);

    mo_prep_api_out([
        'ok' => true,
        'session_id' => $sessionId,
        'status' => (string)($session['status'] ?? ''),
        'prepared' => $prepared,
        'display' => $display,
    ]);
} catch (Throwable $e) {
    mo_prep_api_out(['ok' => false, 'error' => $e->getMessage()], 400);
}
