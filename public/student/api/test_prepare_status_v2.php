<?php
require_once __DIR__ . '/../../../src/bootstrap.php';

cw_require_login();
header('Content-Type: application/json; charset=utf-8');

function json_out(array $x): void {
    echo json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $u = cw_current_user($pdo);
    $role = (string)($u['role'] ?? '');

    if ($role !== 'student' && $role !== 'admin') {
        http_response_code(403);
        json_out(['ok' => false, 'error' => 'Forbidden']);
    }

    $testId = (int)($_GET['test_id'] ?? 0);
    if ($testId <= 0) {
        json_out(['ok' => false, 'error' => 'Missing test_id']);
    }

    $userId = (int)($u['id'] ?? 0);

    if ($role === 'student') {
        $own = $pdo->prepare("
            SELECT 1
            FROM progress_tests_v2
            WHERE id=? AND user_id=?
            LIMIT 1
        ");
        $own->execute([$testId, $userId]);

        if (!$own->fetchColumn()) {
            http_response_code(403);
            json_out(['ok' => false, 'error' => 'Forbidden']);
        }
    }

    $st = $pdo->prepare("
        SELECT
            id,
            status,
            progress_pct,
            status_text,
            updated_at
        FROM progress_tests_v2
        WHERE id=?
        LIMIT 1
    ");
    $st->execute([$testId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        json_out(['ok' => false, 'error' => 'Test not found']);
    }

    $pct = isset($row['progress_pct']) ? (int)$row['progress_pct'] : 0;
    if ($pct < 0) $pct = 0;
    if ($pct > 100) $pct = 100;

    $text = trim((string)($row['status_text'] ?? ''));
    if ($text === '') {
        $status = (string)($row['status'] ?? '');
        if ($status === 'preparing') {
            $text = 'Preparing progress test...';
        } elseif ($status === 'ready') {
            $text = 'Progress test ready.';
        } else {
            $text = 'Working...';
        }
    }

    json_out([
        'ok'           => true,
        'test_id'      => (int)$row['id'],
        'status'       => (string)($row['status'] ?? ''),
        'progress_pct' => $pct,
        'status_text'  => $text,
        'updated_at'   => (string)($row['updated_at'] ?? '')
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    json_out([
        'ok'    => false,
        'error' => $e->getMessage()
    ]);
}