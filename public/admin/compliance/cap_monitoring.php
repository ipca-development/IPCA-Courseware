<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceShell.php';

compliance_require_access($pdo);

cw_header('Compliance · CAP Monitoring');

compliance_render_placeholder(
    'CAP Monitoring',
    'Phase 9 — Monitoring',
    'Dedicated view for every open Corrective Action Plan: due-date burn-down, overdue actions, effectiveness reviews coming up, and breakdown by responsible owner.',
    [
        'bullets' => [
            'Default monitor rules: compliance.cap.overdue, compliance.cap.effectiveness_due.',
            'Per-owner CAP load and at-risk list.',
            'Drill-through to the parent finding and the original audit.',
        ],
        'tables_used' => [
            'ipca_compliance_corrective_actions',
            'ipca_compliance_effectiveness_reviews',
            'ipca_compliance_monitor_rules',
            'ipca_compliance_alerts',
        ],
        'bridges_used' => [
            'ComplianceMonitoringEngine',
        ],
    ]
);

cw_footer();
