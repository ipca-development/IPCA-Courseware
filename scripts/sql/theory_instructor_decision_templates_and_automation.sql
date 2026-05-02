-- =============================================================================
-- Theory: instructor decision notifications + automation (SSOT path)
-- =============================================================================
-- Creates notification templates + version 1 rows, then automation flows on
-- event_key = instructor_decision_recorded.
--
-- Prerequisites:
--   • Database: ipca_courseware (or your courseware schema).
--   • Event instructor_decision_recorded exists for automation (theory catalog).
--
-- Optional cleanup below removes prior rows keyed by notification_key / flow
-- names so the script can be re-run. Comment out the DELETE blocks if you
-- want to preserve manual edits.
--
-- Review HTML/subject in Admin → Notifications after import.
-- =============================================================================

START TRANSACTION;

-- -----------------------------------------------------------------------------
-- Optional: remove previous seed (templates + versions + automation)
-- -----------------------------------------------------------------------------
DELETE ntv FROM notification_template_versions ntv
INNER JOIN notification_templates nt ON nt.id = ntv.notification_template_id
WHERE nt.notification_key IN (
    'instructor_approval_decision_student',
    'instructor_approval_decision_chief'
);

DELETE FROM notification_templates
WHERE notification_key IN (
    'instructor_approval_decision_student',
    'instructor_approval_decision_chief'
);

DELETE a FROM automation_flow_actions a
INNER JOIN automation_flows f ON f.id = a.flow_id
WHERE f.name IN (
    'Theory — Instructor decision (student email)',
    'Theory — Instructor decision (chief email)'
);

DELETE c FROM automation_flow_conditions c
INNER JOIN automation_flows f ON f.id = c.flow_id
WHERE f.name IN (
    'Theory — Instructor decision (student email)',
    'Theory — Instructor decision (chief email)'
);

DELETE FROM automation_flows
WHERE name IN (
    'Theory — Instructor decision (student email)',
    'Theory — Instructor decision (chief email)'
);

-- -----------------------------------------------------------------------------
-- Allowed variables (shared shape; tokens must cover everything used in HTML)
-- -----------------------------------------------------------------------------
SET @allowed_student = '[
  {"name":"student_name","label":"Student name","type":"text","safe_mode":"escaped","required":true,"sample_value":"","description":""},
  {"name":"lesson_title","label":"Lesson title","type":"text","safe_mode":"escaped","required":true,"sample_value":"","description":""},
  {"name":"cohort_title","label":"Cohort title","type":"text","safe_mode":"escaped","required":true,"sample_value":"","description":""},
  {"name":"decision_notes_html","label":"Decision notes (HTML)","type":"html","safe_mode":"approved_html","required":false,"sample_value":"","description":"From instructor decision form"},
  {"name":"decision_code","label":"Decision code","type":"text","safe_mode":"escaped","required":false,"sample_value":"","description":""},
  {"name":"granted_extra_attempts","label":"Granted extra attempts","type":"text","safe_mode":"escaped","required":false,"sample_value":"","description":""},
  {"name":"training_suspended","label":"Training suspended flag","type":"text","safe_mode":"escaped","required":false,"sample_value":"","description":"0 or 1"},
  {"name":"reopened_effective_deadline_utc","label":"New deadline (UTC)","type":"text","safe_mode":"escaped","required":false,"sample_value":"","description":""}
]';

SET @allowed_chief = '[
  {"name":"chief_instructor_name","label":"Chief instructor name","type":"text","safe_mode":"escaped","required":false,"sample_value":"","description":""},
  {"name":"student_name","label":"Student name","type":"text","safe_mode":"escaped","required":true,"sample_value":"","description":""},
  {"name":"lesson_title","label":"Lesson title","type":"text","safe_mode":"escaped","required":true,"sample_value":"","description":""},
  {"name":"cohort_title","label":"Cohort title","type":"text","safe_mode":"escaped","required":true,"sample_value":"","description":""},
  {"name":"decision_notes_html","label":"Decision notes (HTML)","type":"html","safe_mode":"approved_html","required":false,"sample_value":"","description":""},
  {"name":"decision_code","label":"Decision code","type":"text","safe_mode":"escaped","required":false,"sample_value":"","description":""},
  {"name":"granted_extra_attempts","label":"Granted extra attempts","type":"text","safe_mode":"escaped","required":false,"sample_value":"","description":""},
  {"name":"training_suspended","label":"Training suspended flag","type":"text","safe_mode":"escaped","required":false,"sample_value":"","description":""}
]';

-- -----------------------------------------------------------------------------
-- Layout/styling: IPCA-style transactional HTML (aligned with typical academy mail)
-- -----------------------------------------------------------------------------
SET @sub_student = '{{cohort_title}} — Instructor update: {{lesson_title}}';

SET @html_student = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:640px;margin:0 auto;color:#102845;line-height:1.55;background:#ffffff;">
  <div style="padding:22px 22px 8px 22px;border-bottom:1px solid #e2e8f0;">
    <div style="font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:#64748b;font-weight:800;">IPCA Academy</div>
    <div style="font-size:20px;font-weight:900;margin-top:6px;color:#0f172a;">Instructor decision recorded</div>
    <div style="margin-top:6px;font-size:14px;color:#475569;">{{cohort_title}}</div>
  </div>
  <div style="padding:22px;">
    <p style="margin:0 0 14px 0;">Hello {{student_name}},</p>
    <p style="margin:0 0 18px 0;">An instructor has recorded a formal decision for <strong>{{lesson_title}}</strong>.</p>
    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:16px 18px;margin:0 0 18px 0;">
      <div style="font-size:12px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#64748b;margin-bottom:8px;">Decision summary</div>
      <div style="font-size:14px;color:#0f172a;">{{decision_notes_html}}</div>
    </div>
    <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;font-size:14px;color:#334155;margin-bottom:18px;">
      <tr><td style="padding:6px 0;"><strong>Decision type</strong></td><td style="padding:6px 0;text-align:right;">{{decision_code}}</td></tr>
      <tr><td style="padding:6px 0;"><strong>Extra attempts granted</strong></td><td style="padding:6px 0;text-align:right;">{{granted_extra_attempts}}</td></tr>
      <tr><td style="padding:6px 0;"><strong>Training suspended</strong></td><td style="padding:6px 0;text-align:right;">{{training_suspended}}</td></tr>
      <tr><td style="padding:6px 0;"><strong>Deadline (UTC)</strong></td><td style="padding:6px 0;text-align:right;">{{reopened_effective_deadline_utc}}</td></tr>
    </table>
    <p style="margin:0;font-size:13px;color:#64748b;">Continue in the Academy for next steps. If something looks wrong, contact your instructor team.</p>
  </div>
  <div style="padding:14px 22px;border-top:1px solid #e2e8f0;font-size:11px;color:#94a3b8;">Authorized training communication — IPCA Academy</div>
</div>';

SET @txt_student = '';

SET @sub_chief = '{{cohort_title}} — Instructor decision: {{student_name}} / {{lesson_title}}';

SET @html_chief = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:640px;margin:0 auto;color:#102845;line-height:1.55;background:#ffffff;">
  <div style="padding:22px 22px 8px 22px;border-bottom:1px solid #e2e8f0;">
    <div style="font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:#64748b;font-weight:800;">IPCA Academy · Chief copy</div>
    <div style="font-size:20px;font-weight:900;margin-top:6px;color:#0f172a;">Instructor decision recorded</div>
    <div style="margin-top:6px;font-size:14px;color:#475569;">{{cohort_title}}</div>
  </div>
  <div style="padding:22px;">
    <p style="margin:0 0 14px 0;">Hello {{chief_instructor_name}},</p>
    <p style="margin:0 0 18px 0;">An instructor decision was recorded for student <strong>{{student_name}}</strong> on <strong>{{lesson_title}}</strong>.</p>
    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:16px 18px;margin:0 0 18px 0;">
      <div style="font-size:12px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#64748b;margin-bottom:8px;">Decision summary</div>
      <div style="font-size:14px;color:#0f172a;">{{decision_notes_html}}</div>
    </div>
    <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;font-size:14px;color:#334155;">
      <tr><td style="padding:6px 0;"><strong>Decision type</strong></td><td style="padding:6px 0;text-align:right;">{{decision_code}}</td></tr>
      <tr><td style="padding:6px 0;"><strong>Extra attempts granted</strong></td><td style="padding:6px 0;text-align:right;">{{granted_extra_attempts}}</td></tr>
      <tr><td style="padding:6px 0;"><strong>Training suspended</strong></td><td style="padding:6px 0;text-align:right;">{{training_suspended}}</td></tr>
    </table>
  </div>
  <div style="padding:14px 22px;border-top:1px solid #e2e8f0;font-size:11px;color:#94a3b8;">Operational notification — IPCA Academy</div>
</div>';

SET @txt_chief = '';

-- -----------------------------------------------------------------------------
-- notification_templates (+ mirror columns used by your schema — adjust if needed)
-- -----------------------------------------------------------------------------
INSERT INTO notification_templates (
    notification_key,
    channel,
    name,
    description,
    is_enabled,
    subject_template,
    html_template,
    text_template,
    allowed_variables_json,
    created_at,
    updated_at
) VALUES (
    'instructor_approval_decision_student',
    'email',
    'Theory — Instructor decision (student)',
    'Student email after instructor_decision_recorded automation (token flow: instructor_approval.php).',
    1,
    @sub_student,
    @html_student,
    @txt_student,
    @allowed_student,
    UTC_TIMESTAMP(),
    UTC_TIMESTAMP()
);

SET @tid_student := LAST_INSERT_ID();

INSERT INTO notification_templates (
    notification_key,
    channel,
    name,
    description,
    is_enabled,
    subject_template,
    html_template,
    text_template,
    allowed_variables_json,
    created_at,
    updated_at
) VALUES (
    'instructor_approval_decision_chief',
    'email',
    'Theory — Instructor decision (chief)',
    'Chief instructor copy after instructor_decision_recorded automation.',
    1,
    @sub_chief,
    @html_chief,
    @txt_chief,
    @allowed_chief,
    UTC_TIMESTAMP(),
    UTC_TIMESTAMP()
);

SET @tid_chief := LAST_INSERT_ID();

-- -----------------------------------------------------------------------------
-- notification_template_versions (required: sends use latest version row)
-- -----------------------------------------------------------------------------
INSERT INTO notification_template_versions (
    notification_template_id,
    version_no,
    notification_key,
    subject_template,
    html_template,
    text_template,
    allowed_variables_json,
    changed_by_user_id,
    change_note,
    created_at
) VALUES (
    @tid_student,
    1,
    'instructor_approval_decision_student',
    @sub_student,
    @html_student,
    @txt_student,
    @allowed_student,
    NULL,
    'Seed: scripts/sql/theory_instructor_decision_templates_and_automation.sql',
    UTC_TIMESTAMP()
);

INSERT INTO notification_template_versions (
    notification_template_id,
    version_no,
    notification_key,
    subject_template,
    html_template,
    text_template,
    allowed_variables_json,
    changed_by_user_id,
    change_note,
    created_at
) VALUES (
    @tid_chief,
    1,
    'instructor_approval_decision_chief',
    @sub_chief,
    @html_chief,
    @txt_chief,
    @allowed_chief,
    NULL,
    'Seed: scripts/sql/theory_instructor_decision_templates_and_automation.sql',
    UTC_TIMESTAMP()
);

-- -----------------------------------------------------------------------------
-- Automation: separate flows so chief send skips when email empty
-- -----------------------------------------------------------------------------
INSERT INTO automation_flows (
    name,
    description,
    event_key,
    is_active,
    priority,
    created_at,
    updated_at
) VALUES (
    'Theory — Instructor decision (student email)',
    'send_email → instructor_approval_decision_student',
    'instructor_decision_recorded',
    1,
    10,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
);

SET @flow_student := LAST_INSERT_ID();

INSERT INTO automation_flow_actions (flow_id, action_key, config_json, sort_order) VALUES (
    @flow_student,
    'send_email',
    '{"notification_key":"instructor_approval_decision_student","to_email":"{{student_email}}","to_name":"{{student_name}}"}',
    10
);

INSERT INTO automation_flows (
    name,
    description,
    event_key,
    is_active,
    priority,
    created_at,
    updated_at
) VALUES (
    'Theory — Instructor decision (chief email)',
    'send_email → instructor_approval_decision_chief (only if chief email present)',
    'instructor_decision_recorded',
    1,
    20,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
);

SET @flow_chief := LAST_INSERT_ID();

INSERT INTO automation_flow_conditions (
    flow_id,
    field_key,
    operator,
    value_text,
    value_number,
    sort_order
) VALUES (
    @flow_chief,
    'chief_instructor_email',
    'is_not_empty',
    NULL,
    NULL,
    10
);

INSERT INTO automation_flow_actions (flow_id, action_key, config_json, sort_order) VALUES (
    @flow_chief,
    'send_email',
    '{"notification_key":"instructor_approval_decision_chief","to_email":"{{chief_instructor_email}}","to_name":"{{chief_instructor_name}}"}',
    10
);

COMMIT;

-- =============================================================================
-- OPTIONAL: clone HTML/layout from an existing template instead of the embedded
-- layout above. Run manually (not inside this transaction) after inspecting
-- tokens used in the cloned HTML — extend allowed_variables_json to match.
/*
SET @src_id := (
    SELECT nt.id FROM notification_templates nt
    WHERE nt.notification_key = 'instructor_approval_required' AND nt.channel = 'email'
    LIMIT 1
);

INSERT INTO notification_templates (
    notification_key, channel, name, description, is_enabled,
    subject_template, html_template, text_template, allowed_variables_json,
    created_at, updated_at
)
SELECT
    'instructor_approval_decision_student',
    nt.channel,
    'Theory — Instructor decision (student)',
    'Cloned layout from instructor_approval_required — edit tokens/copy.',
    1,
    '{{cohort_title}} — Instructor update: {{lesson_title}}',
    v.html_template,
    COALESCE(v.text_template, ''),
    @allowed_student,
    UTC_TIMESTAMP(),
    UTC_TIMESTAMP()
FROM notification_templates nt
JOIN notification_template_versions v ON v.notification_template_id = nt.id
WHERE nt.id = @src_id
ORDER BY v.version_no DESC, v.id DESC
LIMIT 1;

-- Then INSERT matching notification_template_versions row for LAST_INSERT_ID()
-- using the same subject/html/text/@allowed_student as above.
*/
