<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceShell.php';

compliance_require_access($pdo);

cw_header('Compliance · Reports');

compliance_render_placeholder(
    'Reports',
    'Phase 5 — Audit Lifecycle',
    'Browse and (re)generate compliance reports: CMARs, NCRs, audit reports, RCA/CAP packages, manual release packages and authority evidence bundles. Every locked report has an immutable SHA-256.',
    [
        'bullets' => [
            'Report kinds: CMAR / NCR / INTERIM / FINAL / AUTHORITY_PACKAGE.',
            'Re-render preview vs locked official copy (the locked one is canonical).',
            'PDF integrity hash captured in ipca_compliance_audit_reports.pdf_sha256.',
            'One-click export to the authority Inbox thread for transmittal.',
        ],
        'tables_used' => [
            'ipca_compliance_audit_reports',
            'ipca_compliance_manual_release_packages',
            'ipca_compliance_audits',
            'ipca_compliance_findings',
            'ipca_compliance_corrective_actions',
        ],
        'bridges_used' => [
            'CompliancePdfExportService (mPDF)',
        ],
    ]
);

cw_footer();
