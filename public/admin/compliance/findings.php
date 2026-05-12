<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceShell.php';

compliance_require_access($pdo);

cw_header('Compliance · Findings');

compliance_render_placeholder(
    'Findings',
    'Phase 3 — Wrap Findings / RCA / CAP',
    'Raise, classify and resolve compliance findings (NCRs). Each finding can be raised in isolation or against an audit, supports AI-assisted 5-Whys RCA, links to regulations and manual sections, and drives corrective action plans.',
    [
        'bullets' => [
            'Create findings with classification (Level 1-3 / Observation / Information) and severity.',
            'Attach evidence, link to regulations (eCFR, FAA AIM, EASA eRules) and manual sections.',
            'AI-assisted 5-Whys RCA chain; locked once approved.',
            'Roll up CAP status into the parent finding.',
        ],
        'tables_used' => [
            'ipca_compliance_findings',
            'ipca_compliance_finding_documents',
            'ipca_compliance_finding_regulatory_links',
            'ipca_compliance_finding_manual_links',
            'ipca_compliance_finding_mccf_links',
            'ipca_compliance_finding_rca',
            'ipca_compliance_ai_runs',
        ],
        'bridges_used' => [
            'ComplianceFindingEngine',
            'ComplianceRcaCapEngine',
            'ComplianceRegulatoryBridge → resource_library_*, easa_erules_*',
            'ComplianceManualBridge → resource_library_blocks / easa_erules_import_nodes_staging',
        ],
    ]
);

cw_footer();
