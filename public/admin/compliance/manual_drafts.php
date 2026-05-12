<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceShell.php';

compliance_require_access($pdo);

cw_header('Compliance · Draft Manuals');

compliance_render_placeholder(
    'Draft Manuals',
    'Phase 4+ — Manual Control',
    'Work-in-progress drafts of manual sections (OM, OMM, EASA-IR, Internal). Drafts reference canonical manual rows in the platform\'s existing resource library — no manual content is duplicated.',
    [
        'bullets' => [
            'Originating change request (optional) drives the draft brief.',
            'AI-assisted diff and proposed body; final wording approved by a human.',
            'Drafts move Draft → Under Review → Approved → Published.',
            'Approved drafts are bundled into release packages for authority sign-off.',
        ],
        'tables_used' => [
            'ipca_compliance_manual_drafts',
            'ipca_compliance_manual_change_requests',
            'ipca_compliance_ai_runs',
        ],
        'bridges_used' => [
            'ComplianceManualBridge',
            'resource_library_blocks',
            'resource_library_aim_paragraphs',
            'easa_erules_import_nodes_staging',
        ],
    ]
);

cw_footer();
