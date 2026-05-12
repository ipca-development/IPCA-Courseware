<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceMonitorView.php';

$user = compliance_require_access($pdo);

ComplianceMonitorView::render(
    $pdo,
    $user,
    'FSTD monitoring',
    'Flight-simulator / training-device compliance signals. Hook FSTD-specific evaluators (qualification windows, fidelity audits, downtime) into the monitor framework via Monitoring Rules.',
    'FSTD'
);
