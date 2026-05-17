<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAuthorityDocumentService.php';

compliance_require_access($pdo);

$scope = (string)($_GET['scope'] ?? '');
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$doc = ComplianceAuthorityDocumentService::getDocument($pdo, $scope, $id);
if ($doc === null) {
    http_response_code(404);
    echo 'Document not found.';
    exit;
}

try {
    $path = ComplianceAuthorityDocumentService::absolutePath($doc);
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('Stored file is missing.');
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . addslashes((string)$doc['original_name']) . '"');
    header('Content-Length: ' . (string)filesize($path));
    header('X-Content-Type-Options: nosniff');
    readfile($path);
} catch (Throwable $e) {
    http_response_code(404);
    echo htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
