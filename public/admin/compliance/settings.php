<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceShell.php';

compliance_require_access($pdo);

cw_header('Compliance · Settings');

compliance_render_placeholder(
    'Settings',
    'Phase 2+ — Continuous',
    'Compliance OS settings. The per-admin access flag (`users.is_compliance_admin`) is now in place — the management UI that lets a master admin toggle it for other admins lands as the first real feature on this page.',
    [
        'bullets' => [
            'Per-admin Compliance OS access toggle (column `users.is_compliance_admin` exists; UI pending).',
            'Default AI model and prompt-storage policy for ipca_compliance_ai_runs.',
            'Document retention windows per doc_kind.',
            'Authority directory (BCAA, FAA, EASA, INTERNAL, OTHER) — labels, contact threads, default notification keys.',
        ],
        'tables_used' => [
            'users (column: is_compliance_admin)',
            'ipca_compliance_ai_runs',
            'ipca_compliance_monitor_rules',
        ],
        'bridges_used' => [
            'ComplianceAccess.php (gates every /admin/compliance/* page)',
        ],
    ]
);

cw_footer();
