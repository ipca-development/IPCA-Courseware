-- Fix mock oral auth email: ensure notification template version + automation flow exist.
-- Safe to run multiple times. Runtime bootstrap (mo_ensure_remote_email_automation) also applies this on first request.

SET @notification_key := 'mock_oral_auth_request';
SET @subject := 'Your IPCA Mock Oral Exam Authentication Link';
SET @html := '<p>Dear {{student_name}},</p><p>You requested mock oral exam authentication for <strong>{{area_title}}</strong>.</p><p><a href="{{auth_link}}">Open Authentication Page</a></p><p>This link expires at {{expires_at}}.</p><p>If you did not request this email, contact {{support_email}}.</p>';
SET @text := 'Dear {{student_name}},\n\nMock oral authentication for {{area_title}}.\n\nOpen: {{auth_link}}\n\nExpires: {{expires_at}}\n';
SET @allowed := '[{"name":"student_name","label":"Student name","type":"text","safe_mode":"escaped","required":true},{"name":"area_title","label":"ACS area","type":"text","safe_mode":"escaped","required":true},{"name":"auth_link","label":"Auth link","type":"text","safe_mode":"escaped","required":true},{"name":"expires_at","label":"Expiry","type":"text","safe_mode":"escaped","required":true},{"name":"support_email","label":"Support","type":"text","safe_mode":"escaped","required":false},{"name":"student_email","label":"Student email","type":"text","safe_mode":"escaped","required":false}]';

INSERT INTO notification_templates (
  notification_key, channel, name, description, is_enabled,
  subject_template, html_template, text_template, allowed_variables_json,
  created_at, updated_at
)
SELECT @notification_key, 'email', 'Mock Oral Exam Authentication', 'Email with secure link for mock oral exam authentication.', 1,
  @subject, @html, @text, @allowed, UTC_TIMESTAMP(), UTC_TIMESTAMP()
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM notification_templates WHERE notification_key COLLATE utf8mb4_unicode_ci = @notification_key COLLATE utf8mb4_unicode_ci AND channel = 'email'
);

SET @template_id := (SELECT id FROM notification_templates WHERE notification_key COLLATE utf8mb4_unicode_ci = @notification_key COLLATE utf8mb4_unicode_ci AND channel = 'email' LIMIT 1);

UPDATE notification_templates
SET subject_template = @subject,
    html_template = @html,
    text_template = @text,
    allowed_variables_json = @allowed,
    is_enabled = 1,
    updated_at = UTC_TIMESTAMP()
WHERE id = @template_id;

INSERT INTO notification_template_versions (
  notification_template_id, version_no, notification_key,
  subject_template, html_template, text_template, allowed_variables_json,
  changed_by_user_id, change_note, created_at
)
SELECT @template_id, 1, @notification_key, @subject, @html, @text, @allowed, NULL, 'Migration: mock oral auth email live version', UTC_TIMESTAMP()
FROM DUAL
WHERE @template_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM notification_template_versions WHERE notification_template_id = @template_id
  );

INSERT INTO automation_flows (name, description, event_key, is_active, priority, created_at, updated_at)
SELECT 'Theory — Mock oral auth email', 'send_email → mock_oral_auth_request', 'mock_oral_auth_requested', 1, 10, UTC_TIMESTAMP(), UTC_TIMESTAMP()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM automation_flows WHERE event_key = 'mock_oral_auth_requested');

SET @flow_id := (SELECT id FROM automation_flows WHERE event_key = 'mock_oral_auth_requested' LIMIT 1);

INSERT INTO automation_flow_actions (flow_id, action_key, config_json, sort_order)
SELECT @flow_id, 'send_email', '{"notification_key":"mock_oral_auth_request","to_email":"{{student_email}}","to_name":"{{student_name}}"}', 10
FROM DUAL
WHERE @flow_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM automation_flow_actions WHERE flow_id = @flow_id AND action_key = 'send_email');
