<?php
require_once __DIR__ . '/../../../src/bootstrap.php';

cw_require_login();

function file_or_404(string $path, string $contentType): void {
    if (!is_file($path)) {
        http_response_code(404);
        exit('File not found');
    }
    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-store');
    readfile($path);
    exit;
}

function storage_base_dir(): string {
    return dirname(__DIR__, 3) . '/storage/progress_tests_v2';
}

$u = cw_current_user($pdo);
$role = (string)($u['role'] ?? '');
if ($role !== 'student' && $role !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

$testId = (int)($_GET['test_id'] ?? 0);
$kind   = (string)($_GET['kind'] ?? '');
$idx    = (int)($_GET['idx'] ?? 0);

if ($testId <= 0 || $kind === '') {
    http_response_code(400);
    exit('Missing parameters');
}

$userId = (int)($u['id'] ?? 0);

if ($role === 'student') {
    $own = $pdo->prepare("SELECT 1 FROM progress_tests_v2 WHERE id=? AND user_id=? LIMIT 1");
    $own->execute([$testId, $userId]);
    if (!$own->fetchColumn()) {
        http_response_code(403);
        exit('Forbidden');
    }
}

$baseDir = storage_base_dir() . '/' . $testId;

if ($kind === 'intro') {
    file_or_404($baseDir . '/intro.mp3', 'audio/mpeg');
}

if ($kind === 'item') {
    if ($idx <= 0) {
        http_response_code(400);
        exit('Missing idx');
    }
    $fname = 'q' . str_pad((string)$idx, 2, '0', STR_PAD_LEFT) . '.mp3';
    file_or_404($baseDir . '/' . $fname, 'audio/mpeg');
}

if ($kind === 'result') {
    file_or_404($baseDir . '/result.mp3', 'audio/mpeg');
}

http_response_code(400);
exit('Invalid kind');