<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceShell.php';

compliance_require_access($pdo);

cw_header('Compliance · Live Monitoring');

compliance_render_placeholder(
    'Live Monitoring',
    'Phase 9 — Monitoring',
    'Real-time compliance health: in-flight audits, fieldwork progress, RCAs in draft, CAPs approaching due dates, monitor rule hits in the last 24 hours, and any unacknowledged alerts.',
    [
        'bullets' => [
            'Stream of live monitor results across all rule kinds.',
            'Heatmap of open work per authority / per area.',
            'Alert acknowledgement workflow (open → acknowledged → resolved).',
        ],
        'tables_used' => [
            'ipca_compliance_monitor_runs',
            'ipca_compliance_monitor_results',
            'ipca_compliance_alerts',
            'ipca_compliance_case_events',
        ],
        'bridges_used' => [
            'ComplianceMonitoringEngine',
            'AutomationRuntime (existing platform runtime, no new engine)',
        ],
    ]
);

cw_footer();
