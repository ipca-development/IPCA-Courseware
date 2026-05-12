<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceShell.php';

compliance_require_access($pdo);

cw_header('Compliance · Regulations');

compliance_render_placeholder(
    'Regulations',
    'Phase 3 — Regulatory Bridge',
    'Compliance-facing view of the platform\'s canonical regulatory libraries (FAA AIM, eCFR, EASA Easy Access Rules). No regulatory engine is rebuilt — this is a bridge view that lets compliance officers find rules and attach them to findings, CAPs, or change requests.',
    [
        'bullets' => [
            'Search FAA AIM paragraphs, eCFR sections and EASA eRules nodes through one panel.',
            'Two-step regulatory verification reused as-is (no duplication).',
            'Attach verified citations directly to a finding or change request.',
            'Cite-as-of-date snapshot stored in ipca_compliance_finding_regulatory_links.',
        ],
        'tables_used' => [
            'ipca_compliance_finding_regulatory_links',
            'ipca_compliance_ai_runs',
        ],
        'bridges_used' => [
            'ComplianceRegulatoryBridge',
            'resource_library_aim_paragraphs',
            'easa_erules_import_nodes_staging',
            'easa_semantic_map',
            'src/resource_library_source_verify.php (two-step verification)',
            'src/ecfr_api_client.php',
        ],
    ]
);

cw_footer();
