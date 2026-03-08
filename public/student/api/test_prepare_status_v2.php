<?php
require_once __DIR__ . '/../../../src/bootstrap.php';

cw_require_login();
header('Content-Type: application/json; charset=utf-8');

function json_ok(array $x): void {
    echo json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $u = cw_current_user($pdo);
    $role = (string)($u['role'] ?? '');

    if ($role !== 'student' && $role !== 'admin') {
        http_response_code(403);
        json_ok(['ok' => false, 'error' => 'Forbidden']);
    }

    $testId = (int)($_GET['test_id'] ?? 0);
    if ($testId <= 0) {
        json_ok(['ok' => false, 'error' => 'Missing test_id']);
    }

    $userId = (int)($u['id'] ?? 0);

    if ($role === 'student') {
        $st = $pdo->prepare("
            SELECT id, user_id, status, progress_pct, status_text, manifest_json, updated_at
            FROM progress_tests_v2
            WHERE id=? AND user_id=?
            LIMIT 1
        ");
        $st->execute([$testId, $userId]);
    } else {
        $st = $pdo->prepare("
            SELECT id, user_id, status, progress_pct, status_text, manifest_json, updated_at
            FROM progress_tests_v2
            WHERE id=?
            LIMIT 1
        ");
        $st->execute([$testId]);
    }

    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        json_ok(['ok' => false, 'error' => 'Test not found']);
    }

    $manifest = [];
    $manifestRaw = trim((string)($row['manifest_json'] ?? ''));
    if ($manifestRaw !== '') {
        $decoded = json_decode($manifestRaw, true);
        if (is_array($decoded)) {
            $manifest = $decoded;
        }
    }

    json_ok([
        'ok'           => true,
        'test_id'      => (int)$row['id'],
        'status'       => (string)($row['status'] ?? ''),
        'progress_pct' => (int)($row['progress_pct'] ?? 0),
        'status_text'  => (string)($row['status_text'] ?? ''),
        'updated_at'   => (string)($row['updated_at'] ?? ''),
        'manifest'     => $manifest
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    json_ok([
        'ok'    => false,
        'error' => $e->getMessage()
    ]);
}