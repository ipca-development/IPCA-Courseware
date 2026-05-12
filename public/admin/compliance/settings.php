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
    'Compliance OS settings. Today this page is intentionally minimal — global compliance configuration will land here as later phases bring it online (authority directory, default templates, AI model selection, retention policies, and per-admin compliance privileges).',
    [
        'bullets' => [
            'Per-admin Compliance OS access grants (planned: users.is_compliance_admin or ipca_compliance_admin_grants).',
            'Default AI model and prompt-storage policy for ipca_compliance_ai_runs.',
            'Document retention windows per doc_kind.',
            'Authority directory (BCAA, FAA, EASA, INTERNAL, OTHER) — labels, contact threads, default notification keys.',
        ],
        'tables_used' => [
            'ipca_compliance_ai_runs',
            'ipca_compliance_monitor_rules',
        ],
        'bridges_used' => [
            'users (existing platform auth — no parallel user table)',
        ],
    ]
);

cw_footer();
