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
    'CAP monitoring',
    'Watches corrective actions: overdue items, items due soon and ineffective actions. Use "Run rules now" to re-evaluate all CAP-kind rules and raise alerts.',
    'CAP'
);
