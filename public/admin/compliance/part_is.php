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
    'Cyber / Part-IS',
    'Information-security (Part-IS) compliance signals: cyber incidents, control failures and access-review CAPs. Define rules in Monitoring Rules with kind = CYBER to surface them here.',
    'CYBER'
);
