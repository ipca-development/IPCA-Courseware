<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/CompliancePdfExportService.php';

compliance_require_access($pdo);

$findingId = isset($_GET['finding_id']) ? (int)$_GET['finding_id'] : 0;
if ($findingId <= 0) {
    http_response_code(400);
    echo 'Missing or invalid finding_id';
    exit;
}

try {
    $exportData = CompliancePdfExportService::buildExportPayload($pdo, $findingId);
    CompliancePdfExportService::streamInline($exportData);
    exit;
} catch (Throwable $e) {
    http_response_code(400);
    echo 'PDF export failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    exit;
}
