<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceShell.php';

compliance_require_access($pdo);

cw_header('Compliance · Procedures');

compliance_render_placeholder(
    'Procedures',
    'Phase 4 — Checklist Creator',
    'Author, version and approve compliance checklist templates that drive audit fieldwork. A checklist version becomes immutable on approval; every audit gets its own snapshot so future template edits never drift the historical record.',
    [
        'bullets' => [
            'Draft → Pending Approval → Approved (locked) → Archived workflow.',
            'Item types: section / question / multi-choice / yes-no / numeric / text / evidence upload.',
            'Items can reference regulations and manual sections.',
            'Audit snapshot stores items_snapshot_json + items_snapshot_sha256 for tamper detection.',
        ],
        'tables_used' => [
            'ipca_compliance_checklist_templates',
            'ipca_compliance_checklist_versions',
            'ipca_compliance_checklist_items',
            'ipca_compliance_audit_checklist_snapshots',
            'ipca_compliance_audit_checklist_answers',
        ],
        'bridges_used' => [
            'ComplianceChecklistEngine',
        ],
    ]
);

cw_footer();
