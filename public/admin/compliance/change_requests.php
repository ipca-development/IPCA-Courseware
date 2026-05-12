<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceShell.php';

compliance_require_access($pdo);

cw_header('Compliance · Change Requests');

compliance_render_placeholder(
    'Change Requests',
    'Phase 4+ — Manual Control',
    'Manual change requests: every proposed edit to an approved manual section is captured here with rationale, priority, and the triggering finding / audit / authority email. Approval moves the request into a Draft.',
    [
        'bullets' => [
            'Request workflow: Draft → Submitted → Under Review → Approved → Released.',
            'Target identified by manual_kind + manual_ref_id (canonical reference, not duplicated content).',
            'Polymorphic links to the triggering finding / audit / inbound email.',
            'AI can suggest rephrasings; the final wording is always human-approved.',
        ],
        'tables_used' => [
            'ipca_compliance_manual_change_requests',
            'ipca_compliance_manual_change_request_links',
            'ipca_compliance_ai_runs',
        ],
        'bridges_used' => [
            'ComplianceManualBridge',
            'AutomationRuntime (compliance.manual.change_requested)',
        ],
    ]
);

cw_footer();
