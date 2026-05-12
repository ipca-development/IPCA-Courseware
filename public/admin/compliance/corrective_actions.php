<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceShell.php';

compliance_require_access($pdo);

cw_header('Compliance · Corrective Actions');

compliance_render_placeholder(
    'Corrective Actions',
    'Phase 3 — Wrap Findings / RCA / CAP',
    'Manage Corrective Action Plans across all open findings. Track ownership, due dates, evidence of completion and post-implementation effectiveness reviews — without losing any of the proven logic from the legacy CAP module.',
    [
        'bullets' => [
            'Suggest CAP options via AI (logged in ipca_compliance_ai_runs), final selection by a human.',
            'CAP types: Corrective / Preventive / Containment / Immediate.',
            'Effectiveness reviews with re-review scheduling.',
            'Authority sign-off captured by name + locked timestamp.',
        ],
        'tables_used' => [
            'ipca_compliance_corrective_actions',
            'ipca_compliance_cap_evidence',
            'ipca_compliance_effectiveness_reviews',
            'ipca_compliance_ai_runs',
        ],
        'bridges_used' => [
            'ComplianceRcaCapEngine',
            'ComplianceEvidenceService',
            'AutomationRuntime (compliance.cap.created, compliance.cap.overdue)',
        ],
    ]
);

cw_footer();
