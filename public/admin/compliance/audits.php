<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceShell.php';

compliance_require_access($pdo);

cw_header('Compliance · Audits');

compliance_render_placeholder(
    'Audits',
    'Phase 5 — Audit Lifecycle',
    'Plan, schedule and execute internal and authority audits end-to-end: scope, assignments, checklists, fieldwork, findings, CMAR / NCR generation and follow-up tracking.',
    [
        'bullets' => [
            'Create audits with authority, type, scope and lead auditor.',
            'Assign auditors and external participants with explicit roles.',
            'Bind a versioned checklist via an immutable per-audit snapshot.',
            'Generate authority-ready PDF reports (CMAR / NCR / interim / final).',
        ],
        'tables_used' => [
            'ipca_compliance_audits',
            'ipca_compliance_audit_assignments',
            'ipca_compliance_audit_participants',
            'ipca_compliance_audit_documents',
            'ipca_compliance_audit_reports',
            'ipca_compliance_audit_checklist_snapshots',
            'ipca_compliance_audit_checklist_answers',
        ],
        'bridges_used' => [
            'ComplianceAuditEngine',
            'ComplianceChecklistEngine',
            'CompliancePdfExportService (mPDF)',
        ],
    ]
);

cw_footer();
