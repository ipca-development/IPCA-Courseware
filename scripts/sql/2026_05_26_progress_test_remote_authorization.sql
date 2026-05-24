CREATE TABLE IF NOT EXISTS student_remote_test_permissions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id BIGINT UNSIGNED NOT NULL,
  cohort_id BIGINT UNSIGNED NOT NULL,
  remote_testing_enabled TINYINT(1) NOT NULL DEFAULT 0,
  approved_by_user_id BIGINT UNSIGNED NULL,
  approved_at DATETIME NULL,
  revoked_by_user_id BIGINT UNSIGNED NULL,
  revoked_at DATETIME NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_remote_perm_student_cohort (student_id, cohort_id),
  KEY idx_remote_perm_cohort (cohort_id, remote_testing_enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS progress_test_remote_authorizations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id BIGINT UNSIGNED NOT NULL,
  cohort_id BIGINT UNSIGNED NOT NULL,
  course_id BIGINT UNSIGNED NULL,
  lesson_id BIGINT UNSIGNED NOT NULL,
  progress_test_id BIGINT UNSIGNED NULL,
  progress_test_attempt_id BIGINT UNSIGNED NULL,
  request_token_hash CHAR(64) NOT NULL,
  verification_code_hash CHAR(64) NULL,
  status ENUM(
    'REQUESTED','EMAIL_SENT','AUTHENTICATED','CODE_VERIFIED','USED',
    'EXPIRED','REVOKED','FAILED'
  ) NOT NULL DEFAULT 'REQUESTED',
  valid_from DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  authenticated_at DATETIME NULL,
  code_verified_at DATETIME NULL,
  used_at DATETIME NULL,
  revoked_at DATETIME NULL,
  requested_ip VARCHAR(45) NULL,
  requested_user_agent_hash CHAR(64) NULL,
  authenticated_ip VARCHAR(45) NULL,
  authenticated_user_agent_hash CHAR(64) NULL,
  student_photo_path VARCHAR(255) NULL,
  student_photo_hash CHAR(64) NULL,
  failed_code_attempts INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ptr_auth_student_lesson_status (student_id, lesson_id, status),
  KEY idx_ptr_auth_student_cohort_status (student_id, cohort_id, status),
  KEY idx_ptr_auth_token_hash (request_token_hash),
  KEY idx_ptr_auth_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notification template
INSERT INTO notification_templates (
  notification_key, channel, name, description, is_enabled,
  subject_template, html_template, text_template, allowed_variables_json,
  created_at, updated_at
)
SELECT
  'remote_progress_test_auth_request',
  'email',
  'Remote Progress Test Authentication',
  'Email with secure link for off-site progress test authentication.',
  1,
  'Your IPCA Progress Test Authentication Link',
  '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0; padding:0; width:100%; background-color:#f3f6fb;"><tr><td align="center" style="padding:24px 12px;"><table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:600px; margin:0 auto;"><tr><td style="padding:0;"><table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%; background:linear-gradient(180deg,#122b4a 0%,#1a3a63 100%); border-radius:18px 18px 0 0;"><tr><td align="center" style="padding:28px 24px;"><img src="https://ipca.training/assets/logo/ipca_logo_white.png" alt="IPCA" style="display:block; width:150px; max-width:100%; height:auto;"></td></tr></table><table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%; background-color:#ffffff; border-left:1px solid #e5e7eb; border-right:1px solid #e5e7eb; border-bottom:1px solid #e5e7eb; border-radius:0 0 18px 18px;"><tr><td style="padding:32px 28px; font-family:Arial,Helvetica,sans-serif; color:#1f2937;"><div style="font-size:22px; font-weight:700; color:#111827; margin-bottom:18px;">Remote Progress Test Authentication</div><div style="font-size:15px; line-height:24px; color:#374151; margin-bottom:22px;">Dear {{student_name}},<br><br>You requested remote progress test authentication for <strong>{{lesson_title}}</strong> in <strong>{{course_title}}</strong>.<br><br>Use the secure link below to verify your identity with a live photo and your account password. You will receive a Progress Test Code to enter on your course page before the test begins.<br><br>This link expires at <strong>{{expires_at}}</strong>.</div><table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="background:#1a3a63; border-radius:10px;"><a href="{{auth_link}}" style="display:inline-block; padding:14px 22px; font-family:Arial,Helvetica,sans-serif; font-size:14px; font-weight:700; color:#ffffff; text-decoration:none;">Open Authentication Page</a></td></tr></table><div style="margin-top:22px; font-size:14px; line-height:22px; color:#6b7280;">If you did not request this email, contact <a href="mailto:{{support_email}}" style="color:#1a3a63; text-decoration:none;">{{support_email}}</a>.</div><div style="margin-top:28px; font-size:15px; line-height:24px; color:#374151;">Best regards,<br><strong style="color:#111827;">Kay Vereeken</strong><br>Head of Training</div></td></tr></table></td></tr></table></td></tr></table>',
  'Dear {{student_name}},\n\nYou requested remote progress test authentication for {{lesson_title}} in {{course_title}}.\n\nOpen your authentication page: {{auth_link}}\n\nThis link expires at {{expires_at}}.\n\nIf you did not request this email, contact {{support_email}}.\n\nBest regards,\nKay Vereeken\nHead of Training\n',
  '["student_name","lesson_title","course_title","auth_link","expires_at","support_email","student_email"]',
  UTC_TIMESTAMP(),
  UTC_TIMESTAMP()
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM notification_templates WHERE notification_key = 'remote_progress_test_auth_request' AND channel = 'email'
);

SET @ptr_remote_tpl_id := (
  SELECT id FROM notification_templates
  WHERE notification_key = 'remote_progress_test_auth_request' AND channel = 'email'
  ORDER BY id DESC
  LIMIT 1
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
)
SELECT
  @ptr_remote_tpl_id,
  1,
  'remote_progress_test_auth_request',
  nt.subject_template,
  nt.html_template,
  nt.text_template,
  nt.allowed_variables_json,
  NULL,
  'Seed: scripts/sql/2026_05_26_progress_test_remote_authorization.sql',
  UTC_TIMESTAMP()
FROM notification_templates nt
WHERE nt.id = @ptr_remote_tpl_id
  AND NOT EXISTS (
    SELECT 1 FROM notification_template_versions v
    WHERE v.notification_template_id = @ptr_remote_tpl_id AND v.version_no = 1
  );

INSERT INTO automation_flows (name, description, event_key, is_active, priority, created_at, updated_at)
SELECT
  'Theory — Remote progress test auth email',
  'send_email → remote_progress_test_auth_request',
  'remote_progress_test_requested',
  1,
  10,
  UTC_TIMESTAMP(),
  UTC_TIMESTAMP()
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM automation_flows WHERE event_key = 'remote_progress_test_requested' LIMIT 1
);

SET @flow_remote := (SELECT id FROM automation_flows WHERE event_key = 'remote_progress_test_requested' ORDER BY id DESC LIMIT 1);

INSERT INTO automation_flow_actions (flow_id, action_key, config_json, sort_order)
SELECT @flow_remote, 'send_email', '{"notification_key":"remote_progress_test_auth_request","to_email":"{{student_email}}","to_name":"{{student_name}}"}', 10
FROM DUAL
WHERE @flow_remote IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM automation_flow_actions WHERE flow_id = @flow_remote AND action_key = 'send_email' LIMIT 1);
