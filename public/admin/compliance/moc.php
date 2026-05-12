<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceShell.php';

compliance_require_access($pdo);

cw_header('Compliance · Management of Change');

compliance_render_placeholder(
    'Management of Change',
    'Phase 4+ — Manual Control',
    'Cross-cutting Management of Change view: every approved manual change ties to its release package, the affected procedures / checklists / training materials, and the authority notification trail.',
    [
        'bullets' => [
            'See all in-flight changes per authority and per manual.',
            'Track downstream impacts: checklists that must be re-versioned, training materials that need an update, briefings that must go out.',
            'Cross-link to MoC cases (case_type = MANAGEMENT_OF_CHANGE).',
            'Authority notification calendar driven by effective_date.',
        ],
        'tables_used' => [
            'ipca_compliance_cases',
            'ipca_compliance_manual_change_requests',
            'ipca_compliance_manual_release_packages',
            'ipca_compliance_checklist_versions',
        ],
        'bridges_used' => [
            'ComplianceCaseEngine',
        ],
    ]
);

cw_footer();
