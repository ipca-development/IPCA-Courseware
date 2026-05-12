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
    'Live monitoring',
    'Cross-cut feed of every open compliance alert: overdue CAPs, high-severity findings, overdue audits and Management-of-Change cases. Use this page to triage and dispatch.',
    null
);
