<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceCapEngine.php';

compliance_require_access($pdo);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$evidence = ComplianceCapEngine::getEvidenceById($pdo, $id);
if ($evidence === null) {
    http_response_code(404);
    echo 'Evidence not found.';
    exit;
}

try {
    $path = ComplianceCapEngine::evidenceAbsolutePath($evidence);
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('Stored evidence file is missing.');
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $filename = basename((string)($evidence['title'] ?: 'cap-evidence.pdf'));
    $filesize = filesize($path);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . str_replace('"', '', $filename) . '"');
    if ($filesize !== false) {
        header('Content-Length: ' . (string)$filesize);
    }
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=300');
    readfile($path);
    exit;
} catch (Throwable $e) {
    http_response_code(404);
    echo htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
