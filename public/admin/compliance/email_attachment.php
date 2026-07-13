<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceCommsCenterEngine.php';

compliance_require_access($pdo);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$disposition = (string)($_GET['disposition'] ?? 'attachment');
$disposition = $disposition === 'inline' ? 'inline' : 'attachment';

if ($id <= 0) {
    http_response_code(404);
    echo 'Attachment not found.';
    exit;
}

$st = $pdo->prepare('SELECT * FROM ipca_compliance_email_attachments WHERE id = ? LIMIT 1');
$st->execute(array($id));
$att = $st->fetch(PDO::FETCH_ASSOC);
if (!is_array($att)) {
    http_response_code(404);
    echo 'Attachment not found.';
    exit;
}

$publicUrl = trim((string)($att['public_url'] ?? ''));
if ($publicUrl !== '' && $disposition === 'inline') {
    redirect($publicUrl);
}

$disk = (string)($att['storage_disk'] ?? '');
$key = (string)($att['storage_key'] ?? '');
$bytes = null;

if ($disk === 'local' && $key !== '') {
    $path = ComplianceCommsCenterEngine::storageRoot() . '/' . ltrim($key, '/');
    $root = realpath(ComplianceCommsCenterEngine::storageRoot());
    $real = realpath($path);
    if ($root !== false && $real !== false && str_starts_with($real, $root) && is_file($real)) {
        $bytes = file_get_contents($real);
    }
}

if ($bytes === null && $publicUrl !== '') {
    redirect($publicUrl);
}

if ($bytes === false || $bytes === null) {
    http_response_code(404);
    echo 'Attachment bytes unavailable.';
    exit;
}

$filename = ComplianceCommsCenterEngine::sanitizeFilename((string)($att['original_filename'] ?? 'attachment'));
$type = trim((string)($att['content_type'] ?? ''));
if ($type === '') {
    $type = 'application/octet-stream';
}

header('Content-Type: ' . $type);
header('Content-Length: ' . strlen($bytes));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '', $filename) . '"');
echo $bytes;
