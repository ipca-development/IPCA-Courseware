<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceShell.php';

compliance_require_access($pdo);

cw_header('Compliance · Dashboard');

compliance_render_placeholder(
    'Dashboard',
    'Phase 6 — Case Layer',
    'The Compliance Dashboard surfaces every live compliance case, open findings by severity, CAPs nearing their due date, monitor alerts awaiting acknowledgement, and a fast handoff into any of the sub-modules.',
    [
        'bullets' => [
            'Live KPIs across audits, findings, CAPs, meetings, monitor alerts and Part-IS incidents.',
            'Per-authority compliance posture (BCAA, FAA, EASA, INTERNAL).',
            'Top-of-stack queue: actions waiting on the current admin.',
            'Drill-throughs into any case, audit or finding.',
        ],
        'tables_used' => [
            'ipca_compliance_cases',
            'ipca_compliance_audits',
            'ipca_compliance_findings',
            'ipca_compliance_corrective_actions',
            'ipca_compliance_alerts',
            'ipca_compliance_case_events',
        ],
        'bridges_used' => [
            'ComplianceDashboardRepository',
            'AutomationRuntime (event keys: compliance.*)',
        ],
    ]
);

cw_footer();
