<?php
require_once __DIR__ . '/../../../src/bootstrap.php';

cw_require_login();

function storage_base_dir(): string {
    return '/tmp/progress_tests_v2';
}

$u = cw_current_user($pdo);
$role = (string)($u['role'] ?? '');

if ($role !== 'student' && $role !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

$testId = (int)($_GET['test_id'] ?? 0);
$kind   = (string)($_GET['kind'] ?? '');
$itemId = (int)($_GET['item_id'] ?? 0);

if ($testId <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Missing test_id');
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
        header('Content-Type: text/plain; charset=utf-8');
        exit('Forbidden');
    }
}

$baseDir = storage_base_dir() . '/' . $testId;
$audioFile = '';

if ($kind === 'intro') {
    $audioFile = $baseDir . '/intro.mp3';
} elseif ($kind === 'result') {
    $audioFile = $baseDir . '/result.mp3';
} elseif ($kind === 'question') {
    if ($itemId <= 0) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Missing item_id');
    }
    $audioFile = $baseDir . '/q_' . $itemId . '.mp3';
} else {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Invalid kind');
}

if (!is_dir($baseDir)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Base test directory not found: ' . $baseDir);
}

if (!is_file($audioFile)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Audio not found: ' . $audioFile);
}

$size = @filesize($audioFile);
if ($size === false || $size <= 0) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Audio file is empty or unreadable: ' . $audioFile);
}

header('Content-Type: audio/mpeg');
header('Content-Length: ' . $size);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

readfile($audioFile);
exit;