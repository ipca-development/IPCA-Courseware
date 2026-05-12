<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/CompliancePackagePdfService.php';

compliance_require_access($pdo);

$packageId = isset($_GET['package_id']) ? (int)$_GET['package_id'] : 0;
if ($packageId <= 0) {
    http_response_code(400);
    echo 'Missing or invalid package_id';
    exit;
}

try {
    $payload = CompliancePackagePdfService::fetchOrRegenerate($pdo, $packageId);
    if ($payload['bytes'] === '') {
        throw new RuntimeException('Generated PDF is empty');
    }

    $disposition = isset($_GET['dl']) && $_GET['dl'] === '1' ? 'attachment' : 'inline';

    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . $disposition . '; filename="' . $payload['filename'] . '"');
    header('Content-Length: ' . (string)strlen($payload['bytes']));
    header('X-Content-SHA256: ' . $payload['sha256']);
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $payload['bytes'];
    exit;
} catch (Throwable $e) {
    http_response_code(400);
    echo 'PDF export failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    exit;
}
