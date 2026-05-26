<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/mock_oral/mock_oral_prep.php';

cw_require_login();

try {
    $sessionId = (int)($_GET['session_id'] ?? 0);
    if ($sessionId <= 0) {
        http_response_code(400);
        exit('Missing session_id');
    }

    $u = cw_current_user($pdo);
    $role = (string)($u['role'] ?? '');
    if ($role !== 'student' && $role !== 'admin') {
        http_response_code(403);
        exit('Forbidden');
    }

    $userId = (int)cw_student_view_user_id($pdo, $u);
    $st = $pdo->prepare('SELECT id FROM mock_oral_sessions WHERE id = ? AND user_id = ? LIMIT 1');
    $st->execute([$sessionId, $userId]);
    if (!$st->fetchColumn()) {
        http_response_code(403);
        exit('Forbidden');
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    set_time_limit(180);
    mo_prep_run_blueprint($pdo, $sessionId);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'session_id' => $sessionId, 'status' => 'ready'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($sessionId > 0) {
        try {
            $pdo->prepare("UPDATE mock_oral_sessions SET status = 'failed', updated_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'blueprint_generating'")
                ->execute([$sessionId]);
        } catch (Throwable $ignored) {
        }
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
