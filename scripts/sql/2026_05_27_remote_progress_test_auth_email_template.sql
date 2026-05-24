-- Refresh remote progress test auth email to IPCA branded layout (matches instructor approval emails)
-- Sends use notification_template_versions (latest version_no), not notification_templates alone.

UPDATE notification_templates
SET
  html_template = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0; padding:0; width:100%; background-color:#f3f6fb;"><tr><td align="center" style="padding:24px 12px;"><table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:600px; margin:0 auto;"><tr><td style="padding:0;"><table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%; background:linear-gradient(180deg,#122b4a 0%,#1a3a63 100%); border-radius:18px 18px 0 0;"><tr><td align="center" style="padding:28px 24px;"><img src="https://ipca.training/assets/logo/ipca_logo_white.png" alt="IPCA" style="display:block; width:150px; max-width:100%; height:auto;"></td></tr></table><table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%; background-color:#ffffff; border-left:1px solid #e5e7eb; border-right:1px solid #e5e7eb; border-bottom:1px solid #e5e7eb; border-radius:0 0 18px 18px;"><tr><td style="padding:32px 28px; font-family:Arial,Helvetica,sans-serif; color:#1f2937;"><div style="font-size:22px; font-weight:700; color:#111827; margin-bottom:18px;">Remote Progress Test Authentication</div><div style="font-size:15px; line-height:24px; color:#374151; margin-bottom:22px;">Dear {{student_name}},<br><br>You requested remote progress test authentication for <strong>{{lesson_title}}</strong> in <strong>{{course_title}}</strong>.<br><br>Use the secure link below to verify your identity with a live photo and your account password. You will receive a Progress Test Code to enter on your course page before the test begins.<br><br>This link expires at <strong>{{expires_at}}</strong>.</div><table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="background:#1a3a63; border-radius:10px;"><a href="{{auth_link}}" style="display:inline-block; padding:14px 22px; font-family:Arial,Helvetica,sans-serif; font-size:14px; font-weight:700; color:#ffffff; text-decoration:none;">Open Authentication Page</a></td></tr></table><div style="margin-top:22px; font-size:14px; line-height:22px; color:#6b7280;">If you did not request this email, contact <a href="mailto:{{support_email}}" style="color:#1a3a63; text-decoration:none;">{{support_email}}</a>.</div><div style="margin-top:28px; font-size:15px; line-height:24px; color:#374151;">Best regards,<br><strong style="color:#111827;">Kay Vereeken</strong><br>Head of Training</div></td></tr></table></td></tr></table></td></tr></table>',
  text_template = 'Dear {{student_name}},\n\nYou requested remote progress test authentication for {{lesson_title}} in {{course_title}}.\n\nOpen your authentication page: {{auth_link}}\n\nThis link expires at {{expires_at}}.\n\nIf you did not request this email, contact {{support_email}}.\n\nBest regards,\nKay Vereeken\nHead of Training\n',
  updated_at = UTC_TIMESTAMP()
WHERE notification_key = 'remote_progress_test_auth_request'
  AND channel = 'email';

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
  nt.id,
  COALESCE((
    SELECT MAX(v.version_no)
    FROM notification_template_versions v
    WHERE v.notification_template_id = nt.id
  ), 0) + 1,
  nt.notification_key,
  nt.subject_template,
  nt.html_template,
  nt.text_template,
  nt.allowed_variables_json,
  NULL,
  'IPCA branded layout — scripts/sql/2026_05_27_remote_progress_test_auth_email_template.sql',
  UTC_TIMESTAMP()
FROM notification_templates nt
WHERE nt.notification_key = 'remote_progress_test_auth_request'
  AND nt.channel = 'email';
