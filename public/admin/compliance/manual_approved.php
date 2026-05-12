<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceShell.php';

compliance_require_access($pdo);

cw_header('Compliance · Approved Manuals');

compliance_render_placeholder(
    'Approved Manuals',
    'Phase 4+ — Manual Control',
    'Released manual revisions, bundled as immutable release packages with a SHA-256 over the locked PDF and a structured snapshot of the included drafts. This is the authority-facing record of which manual was in force on a given date.',
    [
        'bullets' => [
            'Release package fields: target revision, effective date, included drafts.',
            'Multi-sign-off approvals (Accountable Manager / Quality Manager / Compliance Officer / Legal).',
            'Locked PDF + pdf_sha256 captured for integrity.',
            'Each package can link back to the change requests that drove it.',
        ],
        'tables_used' => [
            'ipca_compliance_manual_release_packages',
            'ipca_compliance_manual_release_approvals',
            'ipca_compliance_manual_drafts',
        ],
        'bridges_used' => [
            'ComplianceManualBridge',
            'CompliancePdfExportService (mPDF)',
        ],
    ]
);

cw_footer();
