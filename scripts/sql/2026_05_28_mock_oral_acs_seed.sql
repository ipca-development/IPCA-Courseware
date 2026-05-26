-- Seed Private Pilot ACS catalog and Areas I–VIII

INSERT INTO mock_oral_acs_catalogs (catalog_key, label, rating, version, is_active)
SELECT 'acs_private_pilot', 'ACS Private Pilot Airplane', 'private_pilot', 'PA.VIII', 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM mock_oral_acs_catalogs WHERE catalog_key = 'acs_private_pilot');

SET @catalog_id := (SELECT id FROM mock_oral_acs_catalogs WHERE catalog_key = 'acs_private_pilot' LIMIT 1);

INSERT INTO mock_oral_acs_areas (catalog_id, area_code, title, sort_order, scenario_templates_json)
SELECT @catalog_id, 'I', 'Pilot Qualifications', 1, JSON_ARRAY('Certificate and medical review', 'Currency and recent experience')
FROM DUAL WHERE @catalog_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM mock_oral_acs_areas WHERE catalog_id = @catalog_id AND area_code = 'I');

INSERT INTO mock_oral_acs_areas (catalog_id, area_code, title, sort_order, scenario_templates_json)
SELECT @catalog_id, 'II', 'Airworthiness Requirements', 2, JSON_ARRAY('Preflight inspection scenario', 'Maintenance records review')
FROM DUAL WHERE @catalog_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM mock_oral_acs_areas WHERE catalog_id = @catalog_id AND area_code = 'II');

INSERT INTO mock_oral_acs_areas (catalog_id, area_code, title, sort_order, scenario_templates_json)
SELECT @catalog_id, 'III', 'Weather Information', 3, JSON_ARRAY('Cross-country weather briefing', 'In-flight weather decision')
FROM DUAL WHERE @catalog_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM mock_oral_acs_areas WHERE catalog_id = @catalog_id AND area_code = 'III');

INSERT INTO mock_oral_acs_areas (catalog_id, area_code, title, sort_order, scenario_templates_json)
SELECT @catalog_id, 'IV', 'Cross-Country Flight Planning', 4, JSON_ARRAY('VFR cross-country planning', 'Pilotage and dead reckoning')
FROM DUAL WHERE @catalog_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM mock_oral_acs_areas WHERE catalog_id = @catalog_id AND area_code = 'IV');

INSERT INTO mock_oral_acs_areas (catalog_id, area_code, title, sort_order, scenario_templates_json)
SELECT @catalog_id, 'V', 'National Airspace System', 5, JSON_ARRAY('Class B/C/D transit', 'Special use airspace')
FROM DUAL WHERE @catalog_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM mock_oral_acs_areas WHERE catalog_id = @catalog_id AND area_code = 'V');

INSERT INTO mock_oral_acs_areas (catalog_id, area_code, title, sort_order, scenario_templates_json)
SELECT @catalog_id, 'VI', 'Performance and Limitations', 6, JSON_ARRAY('Density altitude takeoff', 'Weight and balance')
FROM DUAL WHERE @catalog_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM mock_oral_acs_areas WHERE catalog_id = @catalog_id AND area_code = 'VI');

INSERT INTO mock_oral_acs_areas (catalog_id, area_code, title, sort_order, scenario_templates_json)
SELECT @catalog_id, 'VII', 'Operation of Systems', 7, JSON_ARRAY('Engine and electrical systems', 'Fuel and ignition systems')
FROM DUAL WHERE @catalog_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM mock_oral_acs_areas WHERE catalog_id = @catalog_id AND area_code = 'VII');

INSERT INTO mock_oral_acs_areas (catalog_id, area_code, title, sort_order, scenario_templates_json)
SELECT @catalog_id, 'VIII', 'Human Factors', 8, JSON_ARRAY('ADM and risk management', 'Aeromedical factors')
FROM DUAL WHERE @catalog_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM mock_oral_acs_areas WHERE catalog_id = @catalog_id AND area_code = 'VIII');

-- Notification template for mock oral remote auth
INSERT INTO notification_templates (
  notification_key, channel, name, description, is_enabled,
  subject_template, html_template, text_template, allowed_variables_json,
  created_at, updated_at
)
SELECT
  'mock_oral_auth_request',
  'email',
  'Mock Oral Exam Authentication',
  'Email with secure link for off-site mock oral exam authentication.',
  1,
  'Your IPCA Mock Oral Exam Authentication Link',
  '<p>Dear {{student_name}},</p><p>You requested mock oral exam authentication for <strong>{{area_title}}</strong>.</p><p><a href="{{auth_link}}">Open Authentication Page</a></p><p>This link expires at {{expires_at}}.</p>',
  'Dear {{student_name}},\n\nMock oral authentication for {{area_title}}.\n\nOpen: {{auth_link}}\n\nExpires: {{expires_at}}\n',
  '[{"name":"student_name","label":"Student name","type":"text","safe_mode":"escaped","required":true},{"name":"area_title","label":"ACS area","type":"text","safe_mode":"escaped","required":true},{"name":"auth_link","label":"Auth link","type":"text","safe_mode":"escaped","required":true},{"name":"expires_at","label":"Expiry","type":"text","safe_mode":"escaped","required":true},{"name":"support_email","label":"Support","type":"text","safe_mode":"escaped","required":false},{"name":"student_email","label":"Student email","type":"text","safe_mode":"escaped","required":false}]',
  UTC_TIMESTAMP(),
  UTC_TIMESTAMP()
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM notification_templates WHERE notification_key = 'mock_oral_auth_request' AND channel = 'email'
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
