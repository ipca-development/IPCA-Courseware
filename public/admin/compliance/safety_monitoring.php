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
    'Safety monitoring',
    'Safety-domain compliance alerts: open high-severity findings, overdue safety CAPs and unresolved safety cases. Rules tagged SAFETY in Monitoring Rules surface here.',
    'SAFETY'
);
