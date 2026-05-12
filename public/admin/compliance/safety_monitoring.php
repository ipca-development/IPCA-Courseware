<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceShell.php';

compliance_require_access($pdo);

cw_header('Compliance · Safety Monitoring');

compliance_render_placeholder(
    'Safety Monitoring',
    'Phase 9 — Monitoring',
    'Safety-side compliance view: hazard reports, occurrence reports, safety-related findings, and the safety actions that feed back into corrective action plans. Distinct from CAP monitoring in that it focuses on safety-classified events.',
    [
        'bullets' => [
            'Hazard / occurrence intake links to compliance cases.',
            'Safety-flagged findings filtered out of the operational view.',
            'Trend lines per authority and per safety category.',
        ],
        'tables_used' => [
            'ipca_compliance_findings',
            'ipca_compliance_monitor_results',
            'ipca_compliance_alerts',
            'ipca_compliance_cases',
        ],
        'bridges_used' => [
            'ComplianceMonitoringEngine',
        ],
    ]
);

cw_footer();
