<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceShell.php';

compliance_require_access($pdo);

cw_header('Compliance · Inbox');

compliance_render_placeholder(
    'Inbox',
    'Phase 7 — Inbox / Postmark',
    'Ingest inbound email (authority correspondence, supplier mail, internal escalations) via Postmark webhook. AI triage classifies and routes each thread; messages attach to cases, audits, findings or change requests as authoritative evidence.',
    [
        'bullets' => [
            'Postmark inbound webhook captures full message + headers + attachments.',
            'AI classifies (AUTHORITY / INTERNAL / SUPPLIER / UNKNOWN / SPAM) and proposes a triage state.',
            'One-click "attach to finding / audit / case" with bidirectional link.',
            'Threaded view per case; replies stay outside this system (use normal mail client).',
        ],
        'tables_used' => [
            'ipca_compliance_inbound_emails',
            'ipca_compliance_inbound_email_attachments',
            'ipca_compliance_email_links',
        ],
        'bridges_used' => [
            'ComplianceInboxService',
            'Postmark inbound webhook',
            'AutomationRuntime (compliance.inbox.email_received)',
        ],
    ]
);

cw_footer();
