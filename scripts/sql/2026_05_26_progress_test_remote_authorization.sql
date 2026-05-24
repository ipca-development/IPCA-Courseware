-- Remote progress test authorization (off-site school network)

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
  '<div style="font-family:Arial,sans-serif;max-width:640px;margin:0 auto;padding:24px;"><p>Hello {{student_name}},</p><p>You requested remote progress test authentication for <strong>{{lesson_title}}</strong> in {{course_title}}.</p><p><a href="{{auth_link}}" style="display:inline-block;padding:12px 18px;background:#12355f;color:#fff;text-decoration:none;border-radius:8px;font-weight:700;">Open authentication page</a></p><p>This link expires at {{expires_at}}.</p><p>If you did not request this, contact {{support_email}}.</p></div>',
  'Hello {{student_name}},\n\nOpen your authentication link: {{auth_link}}\n\nExpires: {{expires_at}}\n\nSupport: {{support_email}}\n',
  '["student_name","lesson_title","course_title","auth_link","expires_at","support_email","student_email"]',
  UTC_TIMESTAMP(),
  UTC_TIMESTAMP()
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM notification_templates WHERE notification_key = 'remote_progress_test_auth_request' AND channel = 'email'
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
