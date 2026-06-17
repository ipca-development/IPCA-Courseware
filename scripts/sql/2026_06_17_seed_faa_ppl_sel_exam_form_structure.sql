-- Seed FAA Private Pilot SEL Practical Test Checklist form template structure.
-- MVP only: sections, starter content blocks, field schema, queryable field rows.
-- Re-run safe: upserts template/version and replaces field rows for v1.0.

SET @seed_actor := (SELECT id FROM users ORDER BY id LIMIT 1);

SET @ppl_sections := JSON_ARRAY(
  JSON_OBJECT('id', 1, 'section_key', 'cover_applicant_information', 'title', 'Cover / Applicant Information', 'sort_order', 10, 'layout', JSON_OBJECT()),
  JSON_OBJECT('id', 2, 'section_key', 'getting_started', 'title', 'Getting Started', 'sort_order', 20, 'layout', JSON_OBJECT()),
  JSON_OBJECT('id', 3, 'section_key', 'iacra', 'title', 'IACRA', 'sort_order', 30, 'layout', JSON_OBJECT()),
  JSON_OBJECT('id', 4, 'section_key', 'knowledge_test_codes', 'title', 'Knowledge Test Codes', 'sort_order', 40, 'layout', JSON_OBJECT()),
  JSON_OBJECT('id', 5, 'section_key', 'applicant_documents', 'title', 'Applicant Documents', 'sort_order', 50, 'layout', JSON_OBJECT()),
  JSON_OBJECT('id', 6, 'section_key', 'aircraft_documents', 'title', 'Aircraft Documents', 'sort_order', 60, 'layout', JSON_OBJECT()),
  JSON_OBJECT('id', 7, 'section_key', 'personal_equipment', 'title', 'Personal Equipment', 'sort_order', 70, 'layout', JSON_OBJECT()),
  JSON_OBJECT('id', 8, 'section_key', 'scenario', 'title', 'Scenario', 'sort_order', 80, 'layout', JSON_OBJECT()),
  JSON_OBJECT('id', 9, 'section_key', 'ground_training', 'title', 'Ground Training', 'sort_order', 90, 'layout', JSON_OBJECT()),
  JSON_OBJECT('id', 10, 'section_key', 'flight_experience', 'title', 'Flight Experience', 'sort_order', 100, 'layout', JSON_OBJECT()),
  JSON_OBJECT('id', 11, 'section_key', 'required_endorsements', 'title', 'Required Endorsements', 'sort_order', 110, 'layout', JSON_OBJECT())
);

SET @ppl_blocks := JSON_ARRAY(
  JSON_OBJECT('id', 1, 'section_id', 1, 'block_key', 'cover_title', 'stable_anchor', 'cover-title', 'block_type', 'heading', 'payload', JSON_OBJECT('text', 'Private Pilot SEL Practical Test Checklist', 'level', 1, 'paragraph_style', 'title'), 'sort_order', 10, 'is_system_managed', 0),
  JSON_OBJECT('id', 2, 'section_id', 1, 'block_key', 'cover_intro', 'stable_anchor', 'cover-intro', 'block_type', 'paragraph', 'payload', JSON_OBJECT('html', 'Applicant and instructor information for the Private Pilot Single-Engine Land practical test checklist.', 'paragraph_style', 'body'), 'sort_order', 20, 'is_system_managed', 0),
  JSON_OBJECT('id', 3, 'section_id', 1, 'block_key', 'applicant_full_name', 'stable_anchor', 'applicant-full-name', 'block_type', 'field', 'payload', JSON_OBJECT('field_key', 'applicant_full_name', 'field_type', 'text', 'label', 'Applicant full name', 'required', true, 'assigned_role', 'student', 'variable_key', 'student.full_name', 'placeholder', 'Student full name'), 'sort_order', 30, 'is_system_managed', 0),
  JSON_OBJECT('id', 4, 'section_id', 1, 'block_key', 'applicant_phone', 'stable_anchor', 'applicant-phone', 'block_type', 'field', 'payload', JSON_OBJECT('field_key', 'applicant_phone', 'field_type', 'text', 'label', 'Applicant phone', 'required', false, 'assigned_role', 'student', 'variable_key', 'student.phone', 'placeholder', 'Student phone'), 'sort_order', 40, 'is_system_managed', 0),
  JSON_OBJECT('id', 5, 'section_id', 1, 'block_key', 'applicant_email', 'stable_anchor', 'applicant-email', 'block_type', 'field', 'payload', JSON_OBJECT('field_key', 'applicant_email', 'field_type', 'text', 'label', 'Applicant email', 'required', true, 'assigned_role', 'student', 'variable_key', 'student.email', 'placeholder', 'Student email'), 'sort_order', 50, 'is_system_managed', 0),
  JSON_OBJECT('id', 6, 'section_id', 1, 'block_key', 'checkride_date', 'stable_anchor', 'checkride-date', 'block_type', 'date', 'payload', JSON_OBJECT('field_key', 'checkride_date', 'field_type', 'date', 'label', 'Checkride date', 'required', false, 'assigned_role', 'instructor', 'variable_key', '', 'placeholder', 'Date'), 'sort_order', 60, 'is_system_managed', 0),

  JSON_OBJECT('id', 10, 'section_id', 2, 'block_key', 'getting_started_title', 'stable_anchor', 'getting-started-title', 'block_type', 'heading', 'payload', JSON_OBJECT('text', 'Getting Started', 'level', 2, 'paragraph_style', 'subtitle_1'), 'sort_order', 10, 'is_system_managed', 0),
  JSON_OBJECT('id', 11, 'section_id', 2, 'block_key', 'getting_started_text', 'stable_anchor', 'getting-started-text', 'block_type', 'paragraph', 'payload', JSON_OBJECT('html', 'Use this checklist to verify applicant readiness, required documents, endorsements, aircraft documents, equipment, and scenario preparation before the practical test.', 'paragraph_style', 'body'), 'sort_order', 20, 'is_system_managed', 0),

  JSON_OBJECT('id', 20, 'section_id', 3, 'block_key', 'iacra_title', 'stable_anchor', 'iacra-title', 'block_type', 'heading', 'payload', JSON_OBJECT('text', 'IACRA', 'level', 2, 'paragraph_style', 'subtitle_1'), 'sort_order', 10, 'is_system_managed', 0),
  JSON_OBJECT('id', 21, 'section_id', 3, 'block_key', 'iacra_username', 'stable_anchor', 'iacra-username', 'block_type', 'field', 'payload', JSON_OBJECT('field_key', 'iacra_username', 'field_type', 'text', 'label', 'IACRA username', 'required', false, 'assigned_role', 'student', 'variable_key', '', 'placeholder', 'IACRA username'), 'sort_order', 20, 'is_system_managed', 0),
  JSON_OBJECT('id', 22, 'section_id', 3, 'block_key', 'iacra_ftn', 'stable_anchor', 'iacra-ftn', 'block_type', 'field', 'payload', JSON_OBJECT('field_key', 'iacra_ftn', 'field_type', 'text', 'label', 'FTN', 'required', true, 'assigned_role', 'student', 'variable_key', '', 'placeholder', 'FAA Tracking Number'), 'sort_order', 30, 'is_system_managed', 0),

  JSON_OBJECT('id', 30, 'section_id', 4, 'block_key', 'knowledge_test_title', 'stable_anchor', 'knowledge-test-title', 'block_type', 'heading', 'payload', JSON_OBJECT('text', 'Knowledge Test Codes', 'level', 2, 'paragraph_style', 'subtitle_1'), 'sort_order', 10, 'is_system_managed', 0),
  JSON_OBJECT('id', 31, 'section_id', 4, 'block_key', 'knowledge_test_score', 'stable_anchor', 'knowledge-test-score', 'block_type', 'field', 'payload', JSON_OBJECT('field_key', 'knowledge_test_score', 'field_type', 'text', 'label', 'Knowledge test score', 'required', false, 'assigned_role', 'instructor', 'variable_key', 'knowledge_test.score', 'placeholder', 'Score'), 'sort_order', 20, 'is_system_managed', 0),
  JSON_OBJECT('id', 32, 'section_id', 4, 'block_key', 'knowledge_test_deficient_codes', 'stable_anchor', 'knowledge-test-deficient-codes', 'block_type', 'field', 'payload', JSON_OBJECT('field_key', 'knowledge_test_deficient_codes', 'field_type', 'textarea', 'label', 'Knowledge test deficient codes', 'required', false, 'assigned_role', 'instructor', 'variable_key', 'knowledge_test.deficient_codes', 'placeholder', 'Deficient codes'), 'sort_order', 30, 'is_system_managed', 0),

  JSON_OBJECT('id', 40, 'section_id', 5, 'block_key', 'applicant_documents_title', 'stable_anchor', 'applicant-documents-title', 'block_type', 'heading', 'payload', JSON_OBJECT('text', 'Applicant Documents', 'level', 2, 'paragraph_style', 'subtitle_1'), 'sort_order', 10, 'is_system_managed', 0),
  JSON_OBJECT('id', 41, 'section_id', 5, 'block_key', 'applicant_documents_verified', 'stable_anchor', 'applicant-documents-verified', 'block_type', 'checkbox', 'payload', JSON_OBJECT('field_key', 'applicant_documents_verified', 'field_type', 'checkbox', 'label', 'Applicant documents verified', 'required', true, 'assigned_role', 'instructor', 'variable_key', '', 'placeholder', ''), 'sort_order', 20, 'is_system_managed', 0),

  JSON_OBJECT('id', 50, 'section_id', 6, 'block_key', 'aircraft_documents_title', 'stable_anchor', 'aircraft-documents-title', 'block_type', 'heading', 'payload', JSON_OBJECT('text', 'Aircraft Documents', 'level', 2, 'paragraph_style', 'subtitle_1'), 'sort_order', 10, 'is_system_managed', 0),
  JSON_OBJECT('id', 51, 'section_id', 6, 'block_key', 'aircraft_documents_verified', 'stable_anchor', 'aircraft-documents-verified', 'block_type', 'checkbox', 'payload', JSON_OBJECT('field_key', 'aircraft_documents_verified', 'field_type', 'checkbox', 'label', 'Aircraft documents verified', 'required', true, 'assigned_role', 'instructor', 'variable_key', '', 'placeholder', ''), 'sort_order', 20, 'is_system_managed', 0),

  JSON_OBJECT('id', 60, 'section_id', 7, 'block_key', 'personal_equipment_title', 'stable_anchor', 'personal-equipment-title', 'block_type', 'heading', 'payload', JSON_OBJECT('text', 'Personal Equipment', 'level', 2, 'paragraph_style', 'subtitle_1'), 'sort_order', 10, 'is_system_managed', 0),
  JSON_OBJECT('id', 61, 'section_id', 7, 'block_key', 'personal_equipment_verified', 'stable_anchor', 'personal-equipment-verified', 'block_type', 'checkbox', 'payload', JSON_OBJECT('field_key', 'personal_equipment_verified', 'field_type', 'checkbox', 'label', 'Personal equipment verified', 'required', true, 'assigned_role', 'student', 'variable_key', '', 'placeholder', ''), 'sort_order', 20, 'is_system_managed', 0),

  JSON_OBJECT('id', 70, 'section_id', 8, 'block_key', 'scenario_title', 'stable_anchor', 'scenario-title', 'block_type', 'heading', 'payload', JSON_OBJECT('text', 'Scenario', 'level', 2, 'paragraph_style', 'subtitle_1'), 'sort_order', 10, 'is_system_managed', 0),
  JSON_OBJECT('id', 71, 'section_id', 8, 'block_key', 'scenario_notes', 'stable_anchor', 'scenario-notes', 'block_type', 'field', 'payload', JSON_OBJECT('field_key', 'scenario_notes', 'field_type', 'textarea', 'label', 'Scenario notes', 'required', false, 'assigned_role', 'instructor', 'variable_key', '', 'placeholder', 'Scenario-specific notes'), 'sort_order', 20, 'is_system_managed', 0),

  JSON_OBJECT('id', 80, 'section_id', 9, 'block_key', 'ground_training_title', 'stable_anchor', 'ground-training-title', 'block_type', 'heading', 'payload', JSON_OBJECT('text', 'Ground Training', 'level', 2, 'paragraph_style', 'subtitle_1'), 'sort_order', 10, 'is_system_managed', 0),
  JSON_OBJECT('id', 81, 'section_id', 9, 'block_key', 'theory_completion', 'stable_anchor', 'theory-completion', 'block_type', 'field', 'payload', JSON_OBJECT('field_key', 'theory_completion', 'field_type', 'text', 'label', 'Theory completion', 'required', false, 'assigned_role', 'instructor', 'variable_key', 'theory.completion', 'placeholder', 'Theory completion'), 'sort_order', 20, 'is_system_managed', 0),

  JSON_OBJECT('id', 90, 'section_id', 10, 'block_key', 'flight_experience_title', 'stable_anchor', 'flight-experience-title', 'block_type', 'heading', 'payload', JSON_OBJECT('text', 'Flight Experience', 'level', 2, 'paragraph_style', 'subtitle_1'), 'sort_order', 10, 'is_system_managed', 0),
  JSON_OBJECT('id', 91, 'section_id', 10, 'block_key', 'flight_total_time', 'stable_anchor', 'flight-total-time', 'block_type', 'field', 'payload', JSON_OBJECT('field_key', 'flight_total_time', 'field_type', 'text', 'label', 'Total flight time', 'required', false, 'assigned_role', 'instructor', 'variable_key', '', 'placeholder', 'Total time'), 'sort_order', 20, 'is_system_managed', 0),

  JSON_OBJECT('id', 100, 'section_id', 11, 'block_key', 'required_endorsements_title', 'stable_anchor', 'required-endorsements-title', 'block_type', 'heading', 'payload', JSON_OBJECT('text', 'Required Endorsements', 'level', 2, 'paragraph_style', 'subtitle_1'), 'sort_order', 10, 'is_system_managed', 0),
  JSON_OBJECT('id', 101, 'section_id', 11, 'block_key', 'instructor_signature', 'stable_anchor', 'instructor-signature', 'block_type', 'signature', 'payload', JSON_OBJECT('field_key', 'instructor_signature', 'field_type', 'signature', 'label', 'Instructor signature', 'required', true, 'assigned_role', 'instructor', 'variable_key', '', 'placeholder', ''), 'sort_order', 20, 'is_system_managed', 0),
  JSON_OBJECT('id', 102, 'section_id', 11, 'block_key', 'student_initials', 'stable_anchor', 'student-initials', 'block_type', 'initial', 'payload', JSON_OBJECT('field_key', 'student_initials', 'field_type', 'initial', 'label', 'Student initials', 'required', false, 'assigned_role', 'student', 'variable_key', '', 'placeholder', ''), 'sort_order', 30, 'is_system_managed', 0)
);

SET @ppl_field_schema := JSON_ARRAY(
  JSON_OBJECT('field_key', 'applicant_full_name', 'field_type', 'text', 'label', 'Applicant full name', 'required', true, 'assigned_role', 'student', 'variable_key', 'student.full_name'),
  JSON_OBJECT('field_key', 'applicant_phone', 'field_type', 'text', 'label', 'Applicant phone', 'required', false, 'assigned_role', 'student', 'variable_key', 'student.phone'),
  JSON_OBJECT('field_key', 'applicant_email', 'field_type', 'text', 'label', 'Applicant email', 'required', true, 'assigned_role', 'student', 'variable_key', 'student.email'),
  JSON_OBJECT('field_key', 'checkride_date', 'field_type', 'date', 'label', 'Checkride date', 'required', false, 'assigned_role', 'instructor', 'variable_key', ''),
  JSON_OBJECT('field_key', 'iacra_username', 'field_type', 'text', 'label', 'IACRA username', 'required', false, 'assigned_role', 'student', 'variable_key', ''),
  JSON_OBJECT('field_key', 'iacra_ftn', 'field_type', 'text', 'label', 'FTN', 'required', true, 'assigned_role', 'student', 'variable_key', ''),
  JSON_OBJECT('field_key', 'knowledge_test_score', 'field_type', 'text', 'label', 'Knowledge test score', 'required', false, 'assigned_role', 'instructor', 'variable_key', 'knowledge_test.score'),
  JSON_OBJECT('field_key', 'knowledge_test_deficient_codes', 'field_type', 'textarea', 'label', 'Knowledge test deficient codes', 'required', false, 'assigned_role', 'instructor', 'variable_key', 'knowledge_test.deficient_codes'),
  JSON_OBJECT('field_key', 'applicant_documents_verified', 'field_type', 'checkbox', 'label', 'Applicant documents verified', 'required', true, 'assigned_role', 'instructor', 'variable_key', ''),
  JSON_OBJECT('field_key', 'aircraft_documents_verified', 'field_type', 'checkbox', 'label', 'Aircraft documents verified', 'required', true, 'assigned_role', 'instructor', 'variable_key', ''),
  JSON_OBJECT('field_key', 'personal_equipment_verified', 'field_type', 'checkbox', 'label', 'Personal equipment verified', 'required', true, 'assigned_role', 'student', 'variable_key', ''),
  JSON_OBJECT('field_key', 'scenario_notes', 'field_type', 'textarea', 'label', 'Scenario notes', 'required', false, 'assigned_role', 'instructor', 'variable_key', ''),
  JSON_OBJECT('field_key', 'theory_completion', 'field_type', 'text', 'label', 'Theory completion', 'required', false, 'assigned_role', 'instructor', 'variable_key', 'theory.completion'),
  JSON_OBJECT('field_key', 'flight_total_time', 'field_type', 'text', 'label', 'Total flight time', 'required', false, 'assigned_role', 'instructor', 'variable_key', ''),
  JSON_OBJECT('field_key', 'instructor_signature', 'field_type', 'signature', 'label', 'Instructor signature', 'required', true, 'assigned_role', 'instructor', 'variable_key', ''),
  JSON_OBJECT('field_key', 'student_initials', 'field_type', 'initial', 'label', 'Student initials', 'required', false, 'assigned_role', 'student', 'variable_key', '')
);

SET @ppl_variable_map := JSON_OBJECT(
  'student.full_name', JSON_OBJECT('label', 'Student full name'),
  'student.phone', JSON_OBJECT('label', 'Student phone'),
  'student.email', JSON_OBJECT('label', 'Student email'),
  'instructor.full_name', JSON_OBJECT('label', 'Instructor full name'),
  'instructor.phone', JSON_OBJECT('label', 'Instructor phone'),
  'instructor.email', JSON_OBJECT('label', 'Instructor email'),
  'course.name', JSON_OBJECT('label', 'Course name'),
  'theory.completion', JSON_OBJECT('label', 'Theory completion'),
  'knowledge_test.score', JSON_OBJECT('label', 'Knowledge test score'),
  'knowledge_test.deficient_codes', JSON_OBJECT('label', 'Knowledge test deficient codes')
);

SET @ppl_content := JSON_OBJECT(
  'document_type', 'form',
  'schema_version', 1,
  'title', 'FAA Private Pilot SEL Practical Test Checklist',
  'layout', JSON_OBJECT('page', 'letter', 'orientation', 'portrait'),
  'page_header', JSON_OBJECT('enabled', false),
  'page_footer', JSON_OBJECT('enabled', false),
  'book_styles', JSON_OBJECT('page_header', JSON_OBJECT('enabled', false), 'page_footer', JSON_OBJECT('enabled', false)),
  'sections', @ppl_sections,
  'blocks', @ppl_blocks
);

INSERT INTO ipca_form_templates
  (template_key, title, description, category, status, metadata_json, created_by)
VALUES
  ('FAA_PPL_SEL_EXAM',
   'FAA Private Pilot SEL Practical Test Checklist',
   'Private Pilot Single-Engine Land practical test checklist / exam preparation form.',
   'Practical Exams',
   'draft',
   JSON_OBJECT('seed', '2026_06_17_seed_faa_ppl_sel_exam_form_structure', 'mvp', true),
   @seed_actor)
ON DUPLICATE KEY UPDATE
  title = VALUES(title),
  description = VALUES(description),
  category = VALUES(category),
  status = 'draft',
  metadata_json = VALUES(metadata_json),
  updated_at = CURRENT_TIMESTAMP;

SET @ppl_template_id := (SELECT id FROM ipca_form_templates WHERE template_key = 'FAA_PPL_SEL_EXAM' LIMIT 1);

INSERT INTO ipca_form_template_versions
  (template_id, version_label, lifecycle_status, title, content_json,
   variable_map_json, field_schema_json, content_hash, created_by)
VALUES
  (@ppl_template_id, '1.0', 'draft',
   'FAA Private Pilot SEL Practical Test Checklist v1.0',
   @ppl_content, @ppl_variable_map, @ppl_field_schema,
   SHA2(CONCAT(CAST(@ppl_content AS CHAR), CAST(@ppl_field_schema AS CHAR)), 256),
   @seed_actor)
ON DUPLICATE KEY UPDATE
  lifecycle_status = 'draft',
  title = VALUES(title),
  content_json = VALUES(content_json),
  variable_map_json = VALUES(variable_map_json),
  field_schema_json = VALUES(field_schema_json),
  content_hash = VALUES(content_hash);

SET @ppl_version_id := (
  SELECT id
  FROM ipca_form_template_versions
  WHERE template_id = @ppl_template_id
    AND version_label = '1.0'
  LIMIT 1
);

UPDATE ipca_form_templates
SET current_version_id = @ppl_version_id,
    updated_at = CURRENT_TIMESTAMP
WHERE id = @ppl_template_id;

DELETE FROM ipca_form_fields WHERE template_version_id = @ppl_version_id;

INSERT INTO ipca_form_fields
  (template_version_id, field_key, field_type, label, required, assigned_role,
   variable_key, validation_json, position_json, metadata_json, sort_order)
VALUES
  (@ppl_version_id, 'applicant_full_name', 'text', 'Applicant full name', 1, 'student', 'student.full_name', JSON_OBJECT(), JSON_OBJECT('block_key', 'applicant_full_name'), JSON_OBJECT('block_type', 'field'), 10),
  (@ppl_version_id, 'applicant_phone', 'text', 'Applicant phone', 0, 'student', 'student.phone', JSON_OBJECT(), JSON_OBJECT('block_key', 'applicant_phone'), JSON_OBJECT('block_type', 'field'), 20),
  (@ppl_version_id, 'applicant_email', 'text', 'Applicant email', 1, 'student', 'student.email', JSON_OBJECT(), JSON_OBJECT('block_key', 'applicant_email'), JSON_OBJECT('block_type', 'field'), 30),
  (@ppl_version_id, 'checkride_date', 'date', 'Checkride date', 0, 'instructor', NULL, JSON_OBJECT(), JSON_OBJECT('block_key', 'checkride_date'), JSON_OBJECT('block_type', 'date'), 40),
  (@ppl_version_id, 'iacra_username', 'text', 'IACRA username', 0, 'student', NULL, JSON_OBJECT(), JSON_OBJECT('block_key', 'iacra_username'), JSON_OBJECT('block_type', 'field'), 50),
  (@ppl_version_id, 'iacra_ftn', 'text', 'FTN', 1, 'student', NULL, JSON_OBJECT(), JSON_OBJECT('block_key', 'iacra_ftn'), JSON_OBJECT('block_type', 'field'), 60),
  (@ppl_version_id, 'knowledge_test_score', 'text', 'Knowledge test score', 0, 'instructor', 'knowledge_test.score', JSON_OBJECT(), JSON_OBJECT('block_key', 'knowledge_test_score'), JSON_OBJECT('block_type', 'field'), 70),
  (@ppl_version_id, 'knowledge_test_deficient_codes', 'textarea', 'Knowledge test deficient codes', 0, 'instructor', 'knowledge_test.deficient_codes', JSON_OBJECT(), JSON_OBJECT('block_key', 'knowledge_test_deficient_codes'), JSON_OBJECT('block_type', 'field'), 80),
  (@ppl_version_id, 'applicant_documents_verified', 'checkbox', 'Applicant documents verified', 1, 'instructor', NULL, JSON_OBJECT(), JSON_OBJECT('block_key', 'applicant_documents_verified'), JSON_OBJECT('block_type', 'checkbox'), 90),
  (@ppl_version_id, 'aircraft_documents_verified', 'checkbox', 'Aircraft documents verified', 1, 'instructor', NULL, JSON_OBJECT(), JSON_OBJECT('block_key', 'aircraft_documents_verified'), JSON_OBJECT('block_type', 'checkbox'), 100),
  (@ppl_version_id, 'personal_equipment_verified', 'checkbox', 'Personal equipment verified', 1, 'student', NULL, JSON_OBJECT(), JSON_OBJECT('block_key', 'personal_equipment_verified'), JSON_OBJECT('block_type', 'checkbox'), 110),
  (@ppl_version_id, 'scenario_notes', 'textarea', 'Scenario notes', 0, 'instructor', NULL, JSON_OBJECT(), JSON_OBJECT('block_key', 'scenario_notes'), JSON_OBJECT('block_type', 'field'), 120),
  (@ppl_version_id, 'theory_completion', 'text', 'Theory completion', 0, 'instructor', 'theory.completion', JSON_OBJECT(), JSON_OBJECT('block_key', 'theory_completion'), JSON_OBJECT('block_type', 'field'), 130),
  (@ppl_version_id, 'flight_total_time', 'text', 'Total flight time', 0, 'instructor', NULL, JSON_OBJECT(), JSON_OBJECT('block_key', 'flight_total_time'), JSON_OBJECT('block_type', 'field'), 140),
  (@ppl_version_id, 'instructor_signature', 'signature', 'Instructor signature', 1, 'instructor', NULL, JSON_OBJECT(), JSON_OBJECT('block_key', 'instructor_signature'), JSON_OBJECT('block_type', 'signature'), 150),
  (@ppl_version_id, 'student_initials', 'initial', 'Student initials', 0, 'student', NULL, JSON_OBJECT(), JSON_OBJECT('block_key', 'student_initials'), JSON_OBJECT('block_type', 'initial'), 160);

INSERT INTO ipca_form_audit_log
  (template_id, template_version_id, actor_user_id, actor_type, event_type, event_json)
VALUES
  (@ppl_template_id, @ppl_version_id, @seed_actor, 'system', 'template_seeded',
   JSON_OBJECT('seed', '2026_06_17_seed_faa_ppl_sel_exam_form_structure', 'section_count', 11, 'field_count', 16));
