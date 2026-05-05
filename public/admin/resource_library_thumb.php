<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/resource_library_storage.php';

cw_require_admin();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Bad id';
    exit;
}

$stmt = $pdo->prepare('SELECT id FROM resource_library_editions WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
if (!$stmt->fetchColumn()) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Edition not found';
    exit;
}

$file = rl_thumbnail_disk_file($id);
if (!$file) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'No cover image uploaded';
    exit;
}

header('Content-Type: ' . $file['mime']);
header('Cache-Control: private, max-age=3600');
header('Content-Length: ' . (string)filesize($file['path']));
readfile($file['path']);
