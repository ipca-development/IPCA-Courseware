<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceShell.php';

compliance_require_access($pdo);

cw_header('Compliance · Monitoring Rules');

compliance_render_placeholder(
    'Monitoring Rules',
    'Phase 9 — Monitoring',
    'Author the rules that drive Live / CAP / FSTD / Safety / Part-IS monitoring. Each rule is either event-driven (subscribes to a compliance.* event_key in the platform automation runtime) or scheduled (CRON / hourly / daily / weekly / monthly).',
    [
        'bullets' => [
            'Compose threshold criteria, alert severity and notification template keys per rule.',
            'Subscribe to platform automation events (compliance.finding.created, compliance.cap.overdue, …) — no new engine.',
            'Manual run for testing; each run logged in ipca_compliance_monitor_runs.',
        ],
        'tables_used' => [
            'ipca_compliance_monitor_rules',
            'ipca_compliance_monitor_runs',
            'ipca_compliance_monitor_results',
            'ipca_compliance_alerts',
        ],
        'bridges_used' => [
            'ComplianceMonitoringEngine',
            'AutomationRuntime → automation_flows, automation_flow_runs',
            'notification_templates / notification_template_versions',
        ],
    ]
);

cw_footer();
