<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceShell.php';

compliance_require_access($pdo);

cw_header('Compliance · Cyber / Part-IS');

compliance_render_placeholder(
    'Cyber / Part-IS',
    'Phase 10 — Part-IS / Digital Governance',
    'EASA Part-IS information-security compliance: asset inventory, risk register, security incidents, periodic access reviews, and supplier / third-party reviews. Scaffolded now; populated when the CMS lifecycle is stable.',
    [
        'bullets' => [
            'Asset inventory: systems, services, databases, applications, devices, processes, data sets.',
            'Risk register with inherent vs residual scoring and review cadence.',
            'Incident timeline: detected → triaging → contained → resolved → post-mortem → closed.',
            'Periodic access reviews + supplier reviews on a calendar driven by Monitoring Rules.',
        ],
        'tables_used' => [
            'ipca_compliance_is_assets',
            'ipca_compliance_is_risks',
            'ipca_compliance_is_incidents',
            'ipca_compliance_is_access_reviews',
            'ipca_compliance_is_supplier_reviews',
        ],
        'bridges_used' => [
            'ComplianceMonitoringEngine (review-due rules)',
        ],
    ]
);

cw_footer();
