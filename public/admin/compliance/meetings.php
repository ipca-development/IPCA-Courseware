<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceShell.php';

compliance_require_access($pdo);

cw_header('Compliance · Meetings');

compliance_render_placeholder(
    'Meetings',
    'Phase 8 — Meetings',
    'Audit opening / closing meetings, management reviews and safety reviews. Recordings, transcripts and AI-summarised minutes become first-class compliance evidence and feed decisions / actions back into findings and CAPs.',
    [
        'bullets' => [
            'Schedule meetings, link to audits or cases.',
            'Upload recordings; auto-transcribe; AI summary + structured highlights.',
            'Capture decisions and action items in-meeting; promote actions to formal CAPs.',
            'Transcripts and summaries are locked once approved — immutable record.',
        ],
        'tables_used' => [
            'ipca_compliance_meetings',
            'ipca_compliance_meeting_attendees',
            'ipca_compliance_meeting_recordings',
            'ipca_compliance_meeting_transcripts',
            'ipca_compliance_meeting_summaries',
            'ipca_compliance_meeting_decisions',
            'ipca_compliance_meeting_actions',
            'ipca_compliance_meeting_links',
        ],
        'bridges_used' => [
            'ComplianceMeetingService',
            'OpenAI (cw_openai_responses)',
        ],
    ]
);

cw_footer();
