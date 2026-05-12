<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceShell.php';

compliance_require_access($pdo);

cw_header('Compliance · FSTD Monitoring');

compliance_render_placeholder(
    'FSTD Monitoring',
    'Phase 9 — Monitoring',
    'Flight Simulation Training Device monitoring view: qualification status, recurrent evaluation due-dates, configuration changes that trigger re-qualification, and any open findings tied to an FSTD asset.',
    [
        'bullets' => [
            'Per-device qualification calendar with expiry warnings.',
            'Open findings filtered to FSTD scope.',
            'Configuration-change events that trigger compliance review.',
        ],
        'tables_used' => [
            'ipca_compliance_monitor_rules',
            'ipca_compliance_monitor_results',
            'ipca_compliance_alerts',
            'ipca_compliance_findings',
        ],
        'bridges_used' => [
            'ComplianceMonitoringEngine',
        ],
    ]
);

cw_footer();
