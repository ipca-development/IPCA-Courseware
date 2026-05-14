-- =============================================================================
-- Theory: Q8 + Q9 + pending-reason reminder
-- Notification templates + automation flows for the new instructor-facing events.
-- =============================================================================
-- This seed wires four new event keys into the canonical automations flow so
-- the engine and cron drive instructor emails through the standard
-- AutomationRuntime → notification_templates → cw_send_mail pipeline.
-- NO email body or recipient address is hardcoded in PHP for any of these.
--
-- Event keys (all dispatched by src/courseware_progression_v2.php OR
-- src/time_based_progression_cron.php):
--
--   • deadline_pending_reason_decision_escalated
--       Q8: engine reached missed-deadline handling while the student's earlier
--       deadline_reason_submission is still awaiting instructor decision. The
--       engine refused to auto-extend and created an instructor_approval action.
--
--   • deadline_rejected_reason_review_required
--       Q8: engine reached missed-deadline handling after the instructor
--       already rejected a previous reason. Routes to instructor_approval for
--       a fresh corrective-action decision.
--
--   • deadline_extension_refused_past_due
--       Q9: automatic extension was refused because the projected new deadline
--       was already in the past at the moment of evaluation. Engine routes to
--       instructor_approval for a human decision.
--
--   • instructor_pending_reason_decision_reminder
--       Daily nudge fired by TimeBasedProgressionCron for every lesson_activity
--       row where reason_decision='pending'. Dedupe is calendar-day scoped
--       (training_progression_events.event_code =
--       cron_pending_reason_decision_reminder_dispatched).
--
-- Prerequisites:
--   • Database: ipca_courseware (or your courseware schema).
--   • Tables: automation_event_categories, automation_event_definitions,
--     notification_templates, notification_template_versions,
--     automation_flows, automation_flow_conditions, automation_flow_actions.
--
-- Re-runnable and non-destructive: this script only inserts missing catalog,
-- template, version, flow, condition, and action rows. It never deletes existing
-- event keys, flows, templates, or manually edited admin configuration.
-- =============================================================================

START TRANSACTION;

-- -----------------------------------------------------------------------------
-- Safety / idempotency note:
-- Previous drafts of this seed used DELETE + INSERT to make reruns clean. That is
-- not architecture-safe because an admin may edit templates/flows after the first
-- run. This final seed preserves all existing rows and inserts only missing rows.
-- If you want to reset admin-edited content, do it manually in Admin UI.

-- -----------------------------------------------------------------------------
-- 1) Register the four new event keys in the admin catalog so they appear in
--    the automation flow editor. Category is theory_training.
-- -----------------------------------------------------------------------------
SET @theory_cat_id := (
    SELECT id FROM automation_event_categories
    WHERE category_key = 'theory_training'
    LIMIT 1
);

INSERT INTO automation_event_categories
    (category_key, label, description, sort_order, is_active)
SELECT
    'theory_training',
    'Theory Training',
    'Progress tests, lesson summaries, theory deadlines, and theory progression events.',
    20,
    1
WHERE @theory_cat_id IS NULL;

SET @theory_cat_id := COALESCE(@theory_cat_id, LAST_INSERT_ID());

INSERT INTO automation_event_definitions
    (event_key, label, description, category_id, sort_order, is_active)
SELECT
    'deadline_pending_reason_decision_escalated',
    'Deadline — pending reason decision escalated to instructor',
    'Q8: engine reached missed-deadline handling while the student''s earlier deadline reason submission is still awaiting instructor decision. The engine refuses to auto-extend and creates an instructor_approval action instead.',
    @theory_cat_id,
    140,
    1
WHERE NOT EXISTS (
    SELECT 1 FROM automation_event_definitions
    WHERE event_key = 'deadline_pending_reason_decision_escalated'
)
UNION ALL
SELECT
    'deadline_rejected_reason_review_required',
    'Deadline — rejected reason needs further review',
    'Q8: engine reached missed-deadline handling after the instructor already rejected a previous reason. Routes to instructor_approval for a fresh corrective-action decision.',
    @theory_cat_id,
    145,
    1
WHERE NOT EXISTS (
    SELECT 1 FROM automation_event_definitions
    WHERE event_key = 'deadline_rejected_reason_review_required'
)
UNION ALL
SELECT
    'deadline_extension_refused_past_due',
    'Deadline — automatic extension refused (would be past-due)',
    'Q9: an automatic extension was refused because the projected new deadline was already in the past at the moment of evaluation (caused by cron backlog or delayed first tick). Engine creates an instructor_approval action so a human can decide whether to issue a manual fresh extension.',
    @theory_cat_id,
    150,
    1
WHERE NOT EXISTS (
    SELECT 1 FROM automation_event_definitions
    WHERE event_key = 'deadline_extension_refused_past_due'
)
UNION ALL
SELECT
    'instructor_pending_reason_decision_reminder',
    'Instructor reminder — pending reason decision',
    'Daily nudge: lesson_activity.reason_decision is ''pending'' (student submitted, instructor has not yet decided). Dispatched at most once per UTC calendar day per lesson by TimeBasedProgressionCron.',
    @theory_cat_id,
    155,
    1
WHERE NOT EXISTS (
    SELECT 1 FROM automation_event_definitions
    WHERE event_key = 'instructor_pending_reason_decision_reminder'
);

-- -----------------------------------------------------------------------------
-- 2) Shared allowed-variables shape for the chief-instructor templates.
--    Tokens used in HTML must all be listed here so NotificationService can
--    validate before rendering.
-- -----------------------------------------------------------------------------
SET @allowed_chief = '[
  {"name":"chief_instructor_name","label":"Chief instructor name","type":"text","safe_mode":"escaped","required":false,"sample_value":"Chief Instructor","description":""},
  {"name":"chief_instructor_email","label":"Chief instructor email","type":"text","safe_mode":"escaped","required":false,"sample_value":"chief@example.com","description":""},
  {"name":"student_name","label":"Student name","type":"text","safe_mode":"escaped","required":true,"sample_value":"Alex Smith","description":""},
  {"name":"student_email","label":"Student email","type":"text","safe_mode":"escaped","required":false,"sample_value":"student@example.com","description":""},
  {"name":"lesson_title","label":"Lesson title","type":"text","safe_mode":"escaped","required":true,"sample_value":"How You''ll Become Instrument-Rated","description":""},
  {"name":"cohort_title","label":"Cohort title","type":"text","safe_mode":"escaped","required":true,"sample_value":"SPC Spring 2026 FT IR-A","description":""},
  {"name":"effective_deadline_utc","label":"Effective deadline (UTC)","type":"text","safe_mode":"escaped","required":false,"sample_value":"2026-05-11 06:59:00","description":""},
  {"name":"reason_decision","label":"Reason decision","type":"text","safe_mode":"escaped","required":false,"sample_value":"pending","description":""},
  {"name":"escalation_reason","label":"Escalation reason code","type":"text","safe_mode":"escaped","required":false,"sample_value":"pending_reason_decision","description":""},
  {"name":"submitted_reason_action_id","label":"Submitted reason action id","type":"text","safe_mode":"escaped","required":false,"sample_value":"1111","description":""},
  {"name":"submitted_reason_submitted_at_utc","label":"Submitted at (UTC)","type":"text","safe_mode":"escaped","required":false,"sample_value":"2026-05-12 23:21:28","description":""},
  {"name":"submitted_reason_submitted_at_display","label":"Submitted at (display)","type":"text","safe_mode":"escaped","required":false,"sample_value":"Tue May 12, 2026, 23:21 UTC","description":""},
  {"name":"submitted_reason_days_since_submitted","label":"Days since submitted","type":"text","safe_mode":"escaped","required":false,"sample_value":"3","description":""},
  {"name":"approval_url","label":"Instructor approval URL","type":"text","safe_mode":"escaped","required":false,"sample_value":"https://example.com/instructor/instructor_approval.php?token=...","description":""}
]';

-- -----------------------------------------------------------------------------
-- 3a) Q8 — pending_reason_decision_escalated (deadline passed, reason still
--     awaiting decision)
-- -----------------------------------------------------------------------------
SET @sub_q8_pending = '{{cohort_title}} — Action required: {{student_name}} reason awaiting your decision';

SET @html_q8_pending = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:640px;margin:0 auto;color:#102845;line-height:1.55;background:#ffffff;">
  <div style="padding:22px 22px 8px 22px;border-bottom:1px solid #e2e8f0;">
    <div style="font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:#64748b;font-weight:800;">IPCA Academy · Instructor action</div>
    <div style="font-size:20px;font-weight:900;margin-top:6px;color:#0f172a;">Student reason awaiting your decision</div>
    <div style="margin-top:6px;font-size:14px;color:#475569;">{{cohort_title}}</div>
  </div>
  <div style="padding:22px;">
    <p style="margin:0 0 14px 0;">Hello {{chief_instructor_name}},</p>
    <p style="margin:0 0 18px 0;"><strong>{{student_name}}</strong> submitted a reason for missing the deadline on <strong>{{lesson_title}}</strong>, and the deadline has now passed again without a decision being recorded.</p>
    <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:14px;padding:16px 18px;margin:0 0 18px 0;">
      <div style="font-size:12px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#9a3412;margin-bottom:8px;">Why you are receiving this</div>
      <div style="font-size:14px;color:#0f172a;">The progression engine refused to auto-extend past a pending instructor decision. An instructor_approval action has been created so you can accept (re-extend), reject, or escalate.</div>
    </div>
    <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;font-size:14px;color:#334155;margin-bottom:18px;">
      <tr><td style="padding:6px 0;"><strong>Student</strong></td><td style="padding:6px 0;text-align:right;">{{student_name}}</td></tr>
      <tr><td style="padding:6px 0;"><strong>Lesson</strong></td><td style="padding:6px 0;text-align:right;">{{lesson_title}}</td></tr>
      <tr><td style="padding:6px 0;"><strong>Reason submitted</strong></td><td style="padding:6px 0;text-align:right;">{{submitted_reason_submitted_at_display}}</td></tr>
      <tr><td style="padding:6px 0;"><strong>Days since submission</strong></td><td style="padding:6px 0;text-align:right;">{{submitted_reason_days_since_submitted}}</td></tr>
      <tr><td style="padding:6px 0;"><strong>Effective deadline (UTC)</strong></td><td style="padding:6px 0;text-align:right;">{{effective_deadline_utc}}</td></tr>
    </table>
    <p style="margin:0 0 14px 0;"><a href="{{approval_url}}" style="display:inline-block;padding:12px 22px;background:#1d4ed8;color:#ffffff;text-decoration:none;border-radius:10px;font-weight:700;">Open instructor decision</a></p>
    <p style="margin:0;font-size:13px;color:#64748b;">If something looks wrong, open the Theory Control Center for the full case context.</p>
  </div>
  <div style="padding:14px 22px;border-top:1px solid #e2e8f0;font-size:11px;color:#94a3b8;">Operational notification — IPCA Academy</div>
</div>';

SET @txt_q8_pending = '';

-- -----------------------------------------------------------------------------
-- 3b) Q8 — rejected_reason_review_required (instructor rejected, deadline passed again)
-- -----------------------------------------------------------------------------
SET @sub_q8_rejected = '{{cohort_title}} — Action required: rejected reason needs further review for {{student_name}}';

SET @html_q8_rejected = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:640px;margin:0 auto;color:#102845;line-height:1.55;background:#ffffff;">
  <div style="padding:22px 22px 8px 22px;border-bottom:1px solid #e2e8f0;">
    <div style="font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:#64748b;font-weight:800;">IPCA Academy · Instructor action</div>
    <div style="font-size:20px;font-weight:900;margin-top:6px;color:#0f172a;">Rejected reason: deadline passed again</div>
    <div style="margin-top:6px;font-size:14px;color:#475569;">{{cohort_title}}</div>
  </div>
  <div style="padding:22px;">
    <p style="margin:0 0 14px 0;">Hello {{chief_instructor_name}},</p>
    <p style="margin:0 0 18px 0;">A previously submitted reason for <strong>{{student_name}}</strong> on <strong>{{lesson_title}}</strong> was rejected, and the deadline has passed again.</p>
    <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:14px;padding:16px 18px;margin:0 0 18px 0;">
      <div style="font-size:12px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#991b1b;margin-bottom:8px;">Why you are receiving this</div>
      <div style="font-size:14px;color:#0f172a;">The engine refuses to issue further automatic extensions after a rejected reason. Please decide on the next step: allow a further attempt, fail the lesson, or take other corrective action.</div>
    </div>
    <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;font-size:14px;color:#334155;margin-bottom:18px;">
      <tr><td style="padding:6px 0;"><strong>Student</strong></td><td style="padding:6px 0;text-align:right;">{{student_name}}</td></tr>
      <tr><td style="padding:6px 0;"><strong>Lesson</strong></td><td style="padding:6px 0;text-align:right;">{{lesson_title}}</td></tr>
      <tr><td style="padding:6px 0;"><strong>Effective deadline (UTC)</strong></td><td style="padding:6px 0;text-align:right;">{{effective_deadline_utc}}</td></tr>
    </table>
    <p style="margin:0 0 14px 0;"><a href="{{approval_url}}" style="display:inline-block;padding:12px 22px;background:#b91c1c;color:#ffffff;text-decoration:none;border-radius:10px;font-weight:700;">Open instructor decision</a></p>
  </div>
  <div style="padding:14px 22px;border-top:1px solid #e2e8f0;font-size:11px;color:#94a3b8;">Operational notification — IPCA Academy</div>
</div>';

SET @txt_q8_rejected = '';

-- -----------------------------------------------------------------------------
-- 3c) Q9 — extension_refused_past_due (would-be deadline already past)
-- -----------------------------------------------------------------------------
SET @sub_q9_past = '{{cohort_title}} — Automatic extension refused for {{student_name}} ({{lesson_title}})';

SET @html_q9_past = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:640px;margin:0 auto;color:#102845;line-height:1.55;background:#ffffff;">
  <div style="padding:22px 22px 8px 22px;border-bottom:1px solid #e2e8f0;">
    <div style="font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:#64748b;font-weight:800;">IPCA Academy · Instructor action</div>
    <div style="font-size:20px;font-weight:900;margin-top:6px;color:#0f172a;">Automatic extension refused</div>
    <div style="margin-top:6px;font-size:14px;color:#475569;">{{cohort_title}}</div>
  </div>
  <div style="padding:22px;">
    <p style="margin:0 0 14px 0;">Hello {{chief_instructor_name}},</p>
    <p style="margin:0 0 18px 0;">An automatic deadline extension for <strong>{{student_name}}</strong> on <strong>{{lesson_title}}</strong> was refused because the new deadline would already be in the past.</p>
    <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:14px;padding:16px 18px;margin:0 0 18px 0;">
      <div style="font-size:12px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#1e40af;margin-bottom:8px;">Why you are receiving this</div>
      <div style="font-size:14px;color:#0f172a;">Issuing an automatic extension to a date already in the past would silently emit a "your new deadline is [past date]" notification to the student. The engine refused. Please decide whether to issue a fresh manual extension or take other corrective action.</div>
    </div>
    <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;font-size:14px;color:#334155;margin-bottom:18px;">
      <tr><td style="padding:6px 0;"><strong>Student</strong></td><td style="padding:6px 0;text-align:right;">{{student_name}}</td></tr>
      <tr><td style="padding:6px 0;"><strong>Lesson</strong></td><td style="padding:6px 0;text-align:right;">{{lesson_title}}</td></tr>
      <tr><td style="padding:6px 0;"><strong>Effective deadline (UTC)</strong></td><td style="padding:6px 0;text-align:right;">{{effective_deadline_utc}}</td></tr>
    </table>
    <p style="margin:0 0 14px 0;"><a href="{{approval_url}}" style="display:inline-block;padding:12px 22px;background:#1d4ed8;color:#ffffff;text-decoration:none;border-radius:10px;font-weight:700;">Open instructor decision</a></p>
  </div>
  <div style="padding:14px 22px;border-top:1px solid #e2e8f0;font-size:11px;color:#94a3b8;">Operational notification — IPCA Academy</div>
</div>';

SET @txt_q9_past = '';

-- -----------------------------------------------------------------------------
-- 3d) Daily reminder — pending reason decision (cron)
-- -----------------------------------------------------------------------------
SET @sub_reminder = '{{cohort_title}} — Reminder: {{student_name}} reason awaiting your decision ({{submitted_reason_days_since_submitted}} day(s))';

SET @html_reminder = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:640px;margin:0 auto;color:#102845;line-height:1.55;background:#ffffff;">
  <div style="padding:22px 22px 8px 22px;border-bottom:1px solid #e2e8f0;">
    <div style="font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:#64748b;font-weight:800;">IPCA Academy · Daily reminder</div>
    <div style="font-size:20px;font-weight:900;margin-top:6px;color:#0f172a;">Pending decision: student reason</div>
    <div style="margin-top:6px;font-size:14px;color:#475569;">{{cohort_title}}</div>
  </div>
  <div style="padding:22px;">
    <p style="margin:0 0 14px 0;">Hello {{chief_instructor_name}},</p>
    <p style="margin:0 0 18px 0;"><strong>{{student_name}}</strong> submitted a reason for missing the deadline on <strong>{{lesson_title}}</strong>, and your decision is still pending after {{submitted_reason_days_since_submitted}} day(s).</p>
    <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;font-size:14px;color:#334155;margin-bottom:18px;">
      <tr><td style="padding:6px 0;"><strong>Student</strong></td><td style="padding:6px 0;text-align:right;">{{student_name}}</td></tr>
      <tr><td style="padding:6px 0;"><strong>Lesson</strong></td><td style="padding:6px 0;text-align:right;">{{lesson_title}}</td></tr>
      <tr><td style="padding:6px 0;"><strong>Submitted</strong></td><td style="padding:6px 0;text-align:right;">{{submitted_reason_submitted_at_display}}</td></tr>
      <tr><td style="padding:6px 0;"><strong>Days since submission</strong></td><td style="padding:6px 0;text-align:right;">{{submitted_reason_days_since_submitted}}</td></tr>
      <tr><td style="padding:6px 0;"><strong>Effective deadline (UTC)</strong></td><td style="padding:6px 0;text-align:right;">{{effective_deadline_utc}}</td></tr>
    </table>
    <p style="margin:0;font-size:13px;color:#64748b;">Open the Theory Control Center to act on this pending case. This reminder is sent at most once per day until a decision is recorded.</p>
  </div>
  <div style="padding:14px 22px;border-top:1px solid #e2e8f0;font-size:11px;color:#94a3b8;">Operational reminder — IPCA Academy</div>
</div>';

SET @txt_reminder = '';

-- -----------------------------------------------------------------------------
-- 4) Notification templates + versions
-- -----------------------------------------------------------------------------
SET @tid_q8_pending := (
    SELECT id FROM notification_templates
    WHERE notification_key = 'deadline_pending_reason_decision_escalated_chief'
      AND channel = 'email'
    LIMIT 1
);

INSERT INTO notification_templates (
    notification_key, channel, name, description, is_enabled,
    subject_template, html_template, text_template, allowed_variables_json,
    created_at, updated_at
) SELECT
    'deadline_pending_reason_decision_escalated_chief', 'email',
    'Theory — Pending reason decision escalated (chief)',
    'Chief instructor email when the engine refuses to auto-extend past a pending reason decision.',
    1, @sub_q8_pending, @html_q8_pending, @txt_q8_pending, @allowed_chief,
    UTC_TIMESTAMP(), UTC_TIMESTAMP()
WHERE @tid_q8_pending IS NULL;
SET @tid_q8_pending := COALESCE(@tid_q8_pending, LAST_INSERT_ID());

SET @tid_q8_rejected := (
    SELECT id FROM notification_templates
    WHERE notification_key = 'deadline_rejected_reason_review_required_chief'
      AND channel = 'email'
    LIMIT 1
);

INSERT INTO notification_templates (
    notification_key, channel, name, description, is_enabled,
    subject_template, html_template, text_template, allowed_variables_json,
    created_at, updated_at
) SELECT
    'deadline_rejected_reason_review_required_chief', 'email',
    'Theory — Rejected reason needs review (chief)',
    'Chief instructor email when the engine refuses to auto-extend after a rejected reason and the deadline passed again.',
    1, @sub_q8_rejected, @html_q8_rejected, @txt_q8_rejected, @allowed_chief,
    UTC_TIMESTAMP(), UTC_TIMESTAMP()
WHERE @tid_q8_rejected IS NULL;
SET @tid_q8_rejected := COALESCE(@tid_q8_rejected, LAST_INSERT_ID());

SET @tid_q9_past := (
    SELECT id FROM notification_templates
    WHERE notification_key = 'deadline_extension_refused_past_due_chief'
      AND channel = 'email'
    LIMIT 1
);

INSERT INTO notification_templates (
    notification_key, channel, name, description, is_enabled,
    subject_template, html_template, text_template, allowed_variables_json,
    created_at, updated_at
) SELECT
    'deadline_extension_refused_past_due_chief', 'email',
    'Theory — Automatic extension refused (past-due) (chief)',
    'Chief instructor email when the engine refuses an automatic extension because the projected new deadline is already in the past.',
    1, @sub_q9_past, @html_q9_past, @txt_q9_past, @allowed_chief,
    UTC_TIMESTAMP(), UTC_TIMESTAMP()
WHERE @tid_q9_past IS NULL;
SET @tid_q9_past := COALESCE(@tid_q9_past, LAST_INSERT_ID());

SET @tid_reminder := (
    SELECT id FROM notification_templates
    WHERE notification_key = 'instructor_pending_reason_decision_reminder_chief'
      AND channel = 'email'
    LIMIT 1
);

INSERT INTO notification_templates (
    notification_key, channel, name, description, is_enabled,
    subject_template, html_template, text_template, allowed_variables_json,
    created_at, updated_at
) SELECT
    'instructor_pending_reason_decision_reminder_chief', 'email',
    'Theory — Daily reminder, pending reason decision (chief)',
    'Chief instructor daily nudge for lessons where the student submitted a reason and an instructor decision is still pending.',
    1, @sub_reminder, @html_reminder, @txt_reminder, @allowed_chief,
    UTC_TIMESTAMP(), UTC_TIMESTAMP()
WHERE @tid_reminder IS NULL;
SET @tid_reminder := COALESCE(@tid_reminder, LAST_INSERT_ID());

-- versions (required: sends use latest version row)
INSERT INTO notification_template_versions
    (notification_template_id, version_no, notification_key, subject_template, html_template, text_template, allowed_variables_json, changed_by_user_id, change_note, created_at)
SELECT @tid_q8_pending, 1, 'deadline_pending_reason_decision_escalated_chief', @sub_q8_pending, @html_q8_pending, @txt_q8_pending, @allowed_chief, NULL, 'Seed: scripts/sql/theory_q8_q9_pending_reason_templates_and_automation.sql', UTC_TIMESTAMP()
WHERE NOT EXISTS (
    SELECT 1 FROM notification_template_versions
    WHERE notification_template_id = @tid_q8_pending AND version_no = 1
)
UNION ALL
SELECT @tid_q8_rejected, 1, 'deadline_rejected_reason_review_required_chief', @sub_q8_rejected, @html_q8_rejected, @txt_q8_rejected, @allowed_chief, NULL, 'Seed: scripts/sql/theory_q8_q9_pending_reason_templates_and_automation.sql', UTC_TIMESTAMP()
WHERE NOT EXISTS (
    SELECT 1 FROM notification_template_versions
    WHERE notification_template_id = @tid_q8_rejected AND version_no = 1
)
UNION ALL
SELECT @tid_q9_past, 1, 'deadline_extension_refused_past_due_chief', @sub_q9_past, @html_q9_past, @txt_q9_past, @allowed_chief, NULL, 'Seed: scripts/sql/theory_q8_q9_pending_reason_templates_and_automation.sql', UTC_TIMESTAMP()
WHERE NOT EXISTS (
    SELECT 1 FROM notification_template_versions
    WHERE notification_template_id = @tid_q9_past AND version_no = 1
)
UNION ALL
SELECT @tid_reminder, 1, 'instructor_pending_reason_decision_reminder_chief', @sub_reminder, @html_reminder, @txt_reminder, @allowed_chief, NULL, 'Seed: scripts/sql/theory_q8_q9_pending_reason_templates_and_automation.sql', UTC_TIMESTAMP()
WHERE NOT EXISTS (
    SELECT 1 FROM notification_template_versions
    WHERE notification_template_id = @tid_reminder AND version_no = 1
);

-- -----------------------------------------------------------------------------
-- 5) Automation flows (one per event_key). Each flow:
--    • is gated by a chief_instructor_email is_not_empty condition (so the
--      flow becomes a no-op for cohorts where no chief is configured).
--    • runs a single send_email action targeting the chief instructor.
-- -----------------------------------------------------------------------------

-- 5a) Q8 — pending_reason_decision_escalated
SET @flow_q8_pending := (
    SELECT id FROM automation_flows
    WHERE name = 'Theory — Pending reason decision escalated (chief email)'
      AND event_key = 'deadline_pending_reason_decision_escalated'
    LIMIT 1
);

INSERT INTO automation_flows
    (name, description, event_key, is_active, priority, created_at, updated_at)
SELECT
    'Theory — Pending reason decision escalated (chief email)',
    'Q8: send chief instructor an email when the engine refuses to auto-extend past a pending reason decision.',
    'deadline_pending_reason_decision_escalated',
    1, 10, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
WHERE @flow_q8_pending IS NULL;
SET @flow_q8_pending := COALESCE(@flow_q8_pending, LAST_INSERT_ID());

INSERT INTO automation_flow_conditions
    (flow_id, field_key, operator, value_text, value_number, sort_order)
SELECT @flow_q8_pending, 'chief_instructor_email', 'is_not_empty', NULL, NULL, 10
WHERE NOT EXISTS (
    SELECT 1 FROM automation_flow_conditions
    WHERE flow_id = @flow_q8_pending
      AND field_key = 'chief_instructor_email'
      AND operator = 'is_not_empty'
);

INSERT INTO automation_flow_actions
    (flow_id, action_key, config_json, sort_order)
SELECT
    @flow_q8_pending,
    'send_email',
    '{"notification_key":"deadline_pending_reason_decision_escalated_chief","to_email":"{{chief_instructor_email}}","to_name":"{{chief_instructor_name}}"}',
    10
WHERE NOT EXISTS (
    SELECT 1 FROM automation_flow_actions
    WHERE flow_id = @flow_q8_pending
      AND action_key = 'send_email'
      AND config_json = '{"notification_key":"deadline_pending_reason_decision_escalated_chief","to_email":"{{chief_instructor_email}}","to_name":"{{chief_instructor_name}}"}'
);

-- 5b) Q8 — rejected_reason_review_required
SET @flow_q8_rejected := (
    SELECT id FROM automation_flows
    WHERE name = 'Theory — Rejected reason needs review (chief email)'
      AND event_key = 'deadline_rejected_reason_review_required'
    LIMIT 1
);

INSERT INTO automation_flows
    (name, description, event_key, is_active, priority, created_at, updated_at)
SELECT
    'Theory — Rejected reason needs review (chief email)',
    'Q8: send chief instructor an email when the engine refuses to auto-extend after a rejected reason.',
    'deadline_rejected_reason_review_required',
    1, 10, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
WHERE @flow_q8_rejected IS NULL;
SET @flow_q8_rejected := COALESCE(@flow_q8_rejected, LAST_INSERT_ID());

INSERT INTO automation_flow_conditions
    (flow_id, field_key, operator, value_text, value_number, sort_order)
SELECT @flow_q8_rejected, 'chief_instructor_email', 'is_not_empty', NULL, NULL, 10
WHERE NOT EXISTS (
    SELECT 1 FROM automation_flow_conditions
    WHERE flow_id = @flow_q8_rejected
      AND field_key = 'chief_instructor_email'
      AND operator = 'is_not_empty'
);

INSERT INTO automation_flow_actions
    (flow_id, action_key, config_json, sort_order)
SELECT
    @flow_q8_rejected,
    'send_email',
    '{"notification_key":"deadline_rejected_reason_review_required_chief","to_email":"{{chief_instructor_email}}","to_name":"{{chief_instructor_name}}"}',
    10
WHERE NOT EXISTS (
    SELECT 1 FROM automation_flow_actions
    WHERE flow_id = @flow_q8_rejected
      AND action_key = 'send_email'
      AND config_json = '{"notification_key":"deadline_rejected_reason_review_required_chief","to_email":"{{chief_instructor_email}}","to_name":"{{chief_instructor_name}}"}'
);

-- 5c) Q9 — extension_refused_past_due
SET @flow_q9_past := (
    SELECT id FROM automation_flows
    WHERE name = 'Theory — Automatic extension refused (chief email)'
      AND event_key = 'deadline_extension_refused_past_due'
    LIMIT 1
);

INSERT INTO automation_flows
    (name, description, event_key, is_active, priority, created_at, updated_at)
SELECT
    'Theory — Automatic extension refused (chief email)',
    'Q9: send chief instructor an email when the engine refuses an automatic extension because the projected new deadline is past-due.',
    'deadline_extension_refused_past_due',
    1, 10, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
WHERE @flow_q9_past IS NULL;
SET @flow_q9_past := COALESCE(@flow_q9_past, LAST_INSERT_ID());

INSERT INTO automation_flow_conditions
    (flow_id, field_key, operator, value_text, value_number, sort_order)
SELECT @flow_q9_past, 'chief_instructor_email', 'is_not_empty', NULL, NULL, 10
WHERE NOT EXISTS (
    SELECT 1 FROM automation_flow_conditions
    WHERE flow_id = @flow_q9_past
      AND field_key = 'chief_instructor_email'
      AND operator = 'is_not_empty'
);

INSERT INTO automation_flow_actions
    (flow_id, action_key, config_json, sort_order)
SELECT
    @flow_q9_past,
    'send_email',
    '{"notification_key":"deadline_extension_refused_past_due_chief","to_email":"{{chief_instructor_email}}","to_name":"{{chief_instructor_name}}"}',
    10
WHERE NOT EXISTS (
    SELECT 1 FROM automation_flow_actions
    WHERE flow_id = @flow_q9_past
      AND action_key = 'send_email'
      AND config_json = '{"notification_key":"deadline_extension_refused_past_due_chief","to_email":"{{chief_instructor_email}}","to_name":"{{chief_instructor_name}}"}'
);

-- 5d) Daily pending-reason reminder (cron-driven)
SET @flow_reminder := (
    SELECT id FROM automation_flows
    WHERE name = 'Theory — Daily reminder, pending reason decision (chief email)'
      AND event_key = 'instructor_pending_reason_decision_reminder'
    LIMIT 1
);

INSERT INTO automation_flows
    (name, description, event_key, is_active, priority, created_at, updated_at)
SELECT
    'Theory — Daily reminder, pending reason decision (chief email)',
    'Daily nudge fired by TimeBasedProgressionCron for lesson_activity rows where reason_decision = ''pending''. Dedupe is once-per-UTC-day per lesson.',
    'instructor_pending_reason_decision_reminder',
    1, 10, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
WHERE @flow_reminder IS NULL;
SET @flow_reminder := COALESCE(@flow_reminder, LAST_INSERT_ID());

INSERT INTO automation_flow_conditions
    (flow_id, field_key, operator, value_text, value_number, sort_order)
SELECT @flow_reminder, 'chief_instructor_email', 'is_not_empty', NULL, NULL, 10
WHERE NOT EXISTS (
    SELECT 1 FROM automation_flow_conditions
    WHERE flow_id = @flow_reminder
      AND field_key = 'chief_instructor_email'
      AND operator = 'is_not_empty'
);

INSERT INTO automation_flow_actions
    (flow_id, action_key, config_json, sort_order)
SELECT
    @flow_reminder,
    'send_email',
    '{"notification_key":"instructor_pending_reason_decision_reminder_chief","to_email":"{{chief_instructor_email}}","to_name":"{{chief_instructor_name}}"}',
    10
WHERE NOT EXISTS (
    SELECT 1 FROM automation_flow_actions
    WHERE flow_id = @flow_reminder
      AND action_key = 'send_email'
      AND config_json = '{"notification_key":"instructor_pending_reason_decision_reminder_chief","to_email":"{{chief_instructor_email}}","to_name":"{{chief_instructor_name}}"}'
);

COMMIT;

-- =============================================================================
-- After applying:
--   • Open Admin → Automations and verify all 4 new flows are visible + active
--     under the Theory category.
--   • Open Admin → Notifications and verify all 4 new templates exist with
--     version 1.
--   • Test dispatch via the automation_flows_api.php "test dispatch" hook with
--     a minimal context containing chief_instructor_email + the required vars
--     to confirm rendering before relying on production cron.
-- =============================================================================
