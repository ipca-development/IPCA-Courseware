<?php
require_once __DIR__ . '/../../../src/bootstrap.php';

cw_require_login();
header('Content-Type: application/json; charset=utf-8');

function json_out(array $x): void {
    echo json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function storage_base_dir(): string {
    return dirname(__DIR__, 3) . '/storage/progress_tests_v2';
}

$u = cw_current_user($pdo);
$role = (string)($u['role'] ?? '');

if ($role !== 'student' && $role !== 'admin') {
    http_response_code(403);
    json_out(['ok' => false, 'error' => 'Forbidden']);
}

$testId = (int)($_POST['test_id'] ?? 0);
$idx    = (int)($_POST['idx'] ?? 0);
$timeout = (int)($_POST['timeout'] ?? 0);

if ($testId <= 0 || $idx <= 0) {
    json_out(['ok' => false, 'error' => 'Missing test_id or idx']);
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

$itemSt = $pdo->prepare("
    SELECT id, idx
    FROM progress_test_items_v2
    WHERE test_id=? AND idx=?
    LIMIT 1
");
$itemSt->execute([$testId, $idx]);
$item = $itemSt->fetch(PDO::FETCH_ASSOC);

if (!$item) {
    json_out(['ok' => false, 'error' => 'Question item not found']);
}

$itemId = (int)$item['id'];

$baseDir = storage_base_dir() . '/' . $testId;
$answersDir = $baseDir . '/answers';

if (!is_dir($answersDir)) {
    @mkdir($answersDir, 0775, true);
}
if (!is_dir($answersDir)) {
    json_out(['ok' => false, 'error' => 'Cannot create answers directory']);
}

$relPath = 'answers/q' . str_pad((string)$idx, 2, '0', STR_PAD_LEFT) . '.webm';
$fullPath = $baseDir . '/' . $relPath;

if ($timeout === 1) {
    $upd = $pdo->prepare("
        UPDATE progress_test_items_v2
        SET transcript_text='[TIMEOUT]',
            audio_path=NULL,
            updated_at=NOW()
        WHERE id=?
    ");
    $upd->execute([$itemId]);

    json_out([
        'ok' => true,
        'test_id' => $testId,
        'idx' => $idx,
        'timeout' => true
    ]);
}

if (empty($_FILES['audio']) || !isset($_FILES['audio']['tmp_name'])) {
    json_out(['ok' => false, 'error' => 'Missing audio upload']);
}

$tmp = $_FILES['audio']['tmp_name'];
$err = (int)($_FILES['audio']['error'] ?? UPLOAD_ERR_OK);

if ($err !== UPLOAD_ERR_OK) {
    json_out(['ok' => false, 'error' => 'Upload failed with error code ' . $err]);
}

if (!is_uploaded_file($tmp)) {
    json_out(['ok' => false, 'error' => 'Invalid uploaded file']);
}

if (!@move_uploaded_file($tmp, $fullPath)) {
    if (!@copy($tmp, $fullPath)) {
        json_out(['ok' => false, 'error' => 'Failed to store uploaded audio']);
    }
}

$upd = $pdo->prepare("
    UPDATE progress_test_items_v2
    SET audio_path=?,
        transcript_text=NULL,
        updated_at=NOW()
    WHERE id=?
");
$upd->execute([$relPath, $itemId]);

json_out([
    'ok' => true,
    'test_id' => $testId,
    'idx' => $idx,
    'audio_path' => $relPath
]);