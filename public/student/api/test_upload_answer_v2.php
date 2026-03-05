<?php
require_once __DIR__ . '/../../../src/bootstrap.php';

cw_require_login();
header('Content-Type: application/json; charset=utf-8');

function json_out(array $x): void {
    echo json_encode($x, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

function ensure_dir(string $dir): void {
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (!is_dir($dir)) throw new RuntimeException("Cannot create directory: " . $dir);
}

function storage_base_dir(): string {
    return dirname(__DIR__, 3) . '/storage/progress_tests_v2';
}

try {
    $u = cw_current_user($pdo);
    $role = (string)($u['role'] ?? '');
    if ($role !== 'student' && $role !== 'admin') {
        http_response_code(403);
        json_out(['ok'=>false,'error'=>'Forbidden']);
    }

    $testId = (int)($_POST['test_id'] ?? 0);
    $idx    = (int)($_POST['idx'] ?? 0);
    $timeoutOnly = ((string)($_POST['timeout'] ?? '') === '1');

    if ($testId <= 0 || $idx <= 0) {
        json_out(['ok'=>false,'error'=>'Missing test_id or idx']);
    }

    $userId = (int)($u['id'] ?? 0);
    if ($role === 'student') {
        $own = $pdo->prepare("SELECT 1 FROM progress_tests_v2 WHERE id=? AND user_id=? LIMIT 1");
        $own->execute([$testId, $userId]);
        if (!$own->fetchColumn()) {
            http_response_code(403);
            json_out(['ok'=>false,'error'=>'Forbidden']);
        }
    }

    $itemSt = $pdo->prepare("SELECT id FROM progress_test_items_v2 WHERE test_id=? AND idx=? LIMIT 1");
    $itemSt->execute([$testId, $idx]);
    $itemId = (int)($itemSt->fetchColumn() ?: 0);
    if ($itemId <= 0) json_out(['ok'=>false,'error'=>'Item not found']);

    $baseDir = storage_base_dir() . '/' . $testId;
    $answersDir = $baseDir . '/answers';
    ensure_dir(storage_base_dir());
    ensure_dir($baseDir);
    ensure_dir($answersDir);

    if ($timeoutOnly) {
        $up = $pdo->prepare("
          UPDATE progress_test_items_v2
          SET transcript_text=?, audio_path=?, updated_at=NOW()
          WHERE id=?
        ");
        $up->execute(['[TIMEOUT]', null, $itemId]);

        $pdo->prepare("UPDATE progress_tests_v2 SET status='in_progress', updated_at=NOW() WHERE id=?")->execute([$testId]);

        json_out(['ok'=>true]);
    }

    if (empty($_FILES['audio']) || !is_uploaded_file($_FILES['audio']['tmp_name'])) {
        json_out(['ok'=>false,'error'=>'Missing uploaded audio']);
    }

    $tmp = $_FILES['audio']['tmp_name'];
    $destName = 'q' . str_pad((string)$idx, 2, '0', STR_PAD_LEFT) . '.webm';
    $dest = $answersDir . '/' . $destName;

    if (!@move_uploaded_file($tmp, $dest)) {
        if (!@copy($tmp, $dest)) {
            throw new RuntimeException('Failed to save uploaded audio');
        }
    }

    $relPath = 'answers/' . $destName;

    $up = $pdo->prepare("
      UPDATE progress_test_items_v2
      SET audio_path=?, updated_at=NOW()
      WHERE id=?
    ");
    $up->execute([$relPath, $itemId]);

    $pdo->prepare("UPDATE progress_tests_v2 SET status='in_progress', updated_at=NOW() WHERE id=?")->execute([$testId]);

    json_out(['ok'=>true]);

} catch (Throwable $e) {
    http_response_code(400);
    json_out(['ok'=>false,'error'=>$e->getMessage()]);
}