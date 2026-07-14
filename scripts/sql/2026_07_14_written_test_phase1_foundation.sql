-- Written Test Preparation Phase 1 foundation.
-- Additive only. Does not create question, answer, image, attempt, mastery,
-- remediation, or analytics tables.

CREATE TABLE IF NOT EXISTS written_test_programs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  program_key VARCHAR(96) NOT NULL,
  display_name VARCHAR(255) NOT NULL,
  description TEXT NULL,
  authority VARCHAR(64) NOT NULL DEFAULT 'internal',
  certificate_type VARCHAR(128) NULL,
  related_course_id INT NULL,
  program_status ENUM('draft','active','suspended','retired') NOT NULL DEFAULT 'draft',
  feature_availability_state ENUM('disabled','preview','available') NOT NULL DEFAULT 'disabled',
  default_policy_key VARCHAR(128) NULL,
  created_by_user_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by_user_id INT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  retired_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_written_test_program_key (program_key),
  KEY idx_written_test_program_status (program_status, feature_availability_state),
  KEY idx_written_test_program_course (related_course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cohort_written_test_allocations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cohort_id INT NOT NULL,
  written_test_program_id BIGINT UNSIGNED NOT NULL,
  related_course_id INT NULL,
  allocation_status ENUM('draft','active','suspended','completed','retired') NOT NULL DEFAULT 'draft',
  effective_start_at DATETIME NULL,
  effective_end_at DATETIME NULL,
  current_published_policy_version_id BIGINT UNSIGNED NULL,
  allocated_by_user_id INT NULL,
  allocated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by_user_id INT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  suspended_by_user_id INT NULL,
  suspended_at DATETIME NULL,
  suspension_reason TEXT NULL,
  retired_by_user_id INT NULL,
  retired_at DATETIME NULL,
  retirement_reason TEXT NULL,
  PRIMARY KEY (id),
  KEY idx_wt_alloc_cohort_status (cohort_id, allocation_status, effective_start_at, effective_end_at),
  KEY idx_wt_alloc_program_status (written_test_program_id, allocation_status),
  KEY idx_wt_alloc_current_policy (current_published_policy_version_id),
  KEY idx_wt_alloc_course (related_course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS written_test_policy_versions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  allocation_id BIGINT UNSIGNED NOT NULL,
  version_number INT UNSIGNED NOT NULL,
  version_status ENUM('draft','published','superseded','withdrawn') NOT NULL DEFAULT 'draft',
  resolved_policy_json JSON NOT NULL,
  source_scope_json JSON NULL,
  change_summary TEXT NULL,
  effective_start_at DATETIME NULL,
  effective_end_at DATETIME NULL,
  created_by_user_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  published_by_user_id INT NULL,
  published_at DATETIME NULL,
  replaced_policy_version_id BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wt_policy_allocation_version (allocation_id, version_number),
  KEY idx_wt_policy_allocation_status (allocation_id, version_status, version_number),
  KEY idx_wt_policy_replaced (replaced_policy_version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS written_test_access_overrides (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  allocation_id BIGINT UNSIGNED NOT NULL,
  student_id INT NULL,
  override_scope ENUM('cohort','student') NOT NULL,
  requirement_key VARCHAR(128) NOT NULL DEFAULT 'all_access_requirements',
  override_action ENUM('satisfy','waive','deny','revoke') NOT NULL,
  reason TEXT NOT NULL,
  authorized_by_user_id INT NOT NULL,
  authorized_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  effective_start_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NULL,
  revoked_by_user_id INT NULL,
  revoked_at DATETIME NULL,
  revocation_reason TEXT NULL,
  audit_meta_json JSON NULL,
  PRIMARY KEY (id),
  KEY idx_wt_override_alloc_scope (allocation_id, override_scope, requirement_key),
  KEY idx_wt_override_student (student_id, allocation_id, revoked_at, expires_at),
  KEY idx_wt_override_effective (allocation_id, effective_start_at, expires_at, revoked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS written_test_access_approvals (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  allocation_id BIGINT UNSIGNED NOT NULL,
  student_id INT NOT NULL,
  approval_type ENUM('instructor','administrator') NOT NULL,
  approval_status ENUM('pending','approved','revoked','rejected') NOT NULL DEFAULT 'pending',
  reason TEXT NULL,
  approved_by_user_id INT NULL,
  approved_at DATETIME NULL,
  revoked_by_user_id INT NULL,
  revoked_at DATETIME NULL,
  revocation_reason TEXT NULL,
  created_by_user_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_wt_approval_lookup (allocation_id, student_id, approval_type, approval_status),
  KEY idx_wt_approval_student (student_id, approval_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO written_test_programs
  (program_key, display_name, description, authority, certificate_type, program_status, feature_availability_state, created_at, updated_at)
VALUES
  ('faa_private_pilot_written_test_prep', 'FAA Private Pilot Written Test Preparation',
   'Draft foundation program for FAA Private Pilot written test preparation. Not allocated or enabled by default.',
   'FAA', 'Private Pilot', 'draft', 'disabled', UTC_TIMESTAMP(), UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
  display_name = VALUES(display_name),
  description = VALUES(description),
  authority = VALUES(authority),
  certificate_type = VALUES(certificate_type),
  updated_at = UTC_TIMESTAMP();

INSERT INTO system_policy_definitions
  (policy_key, category, value_type, default_value_text, allowed_values_json, validation_rules_json, description_text, is_admin_editable, sort_order)
VALUES
  ('written_test.preparation_enabled', 'written_test', 'bool', '0', NULL, NULL, 'Global feature gate for Written Test Preparation. Allocation is still required separately.', 1, 9000),
  ('written_test.require_complete_ground_school', 'written_test', 'bool', '1', NULL, NULL, 'Require all scoped Ground School lessons to be complete before access.', 1, 9010),
  ('written_test.required_ground_school_completion_pct', 'written_test', 'int', '100', NULL, '{\"min\":0,\"max\":100}', 'Required Ground School completion percentage before access.', 1, 9020),
  ('written_test.require_mandatory_summaries', 'written_test', 'bool', '1', NULL, NULL, 'Require mandatory lesson summaries to be accepted before access.', 1, 9030),
  ('written_test.require_progress_tests_completed', 'written_test', 'bool', '1', NULL, NULL, 'Require required lesson Progress Tests to be passed before access.', 1, 9040),
  ('written_test.minimum_progress_test_score_pct', 'written_test', 'int', '70', NULL, '{\"min\":0,\"max\":100}', 'Minimum Progress Test score for required lesson checks.', 1, 9050),
  ('written_test.require_remediation_resolved', 'written_test', 'bool', '1', NULL, NULL, 'Require mandatory Progress Test remediation items to be resolved before access.', 1, 9060),
  ('written_test.require_instructor_approval', 'written_test', 'bool', '0', NULL, NULL, 'Require instructor approval before access.', 1, 9070),
  ('written_test.require_administrator_approval', 'written_test', 'bool', '0', NULL, NULL, 'Require administrator approval before access.', 1, 9080),
  ('written_test.allow_manual_student_override', 'written_test', 'bool', '0', NULL, NULL, 'Permit authorized student-level manual overrides with mandatory reason.', 1, 9090),
  ('written_test.allow_manual_cohort_override', 'written_test', 'bool', '0', NULL, NULL, 'Permit authorized cohort-level manual overrides with mandatory reason.', 1, 9100),
  ('written_test.display_locked_module_to_allocated_students', 'written_test', 'bool', '1', NULL, NULL, 'Show locked Written Test Preparation card to students with an applicable allocation.', 1, 9110),
  ('written_test.treat_overdue_required_lessons_as_lock_reason', 'written_test', 'bool', '1', NULL, NULL, 'Report overdue required lessons as a separate lock reason.', 1, 9120),
  ('written_test.policy_effective_date_behavior', 'written_test', 'string', 'allocation_window', '[\"allocation_window\",\"policy_version_window\"]', NULL, 'Determines whether allocation or policy-version dates control access timing in Phase 1.', 1, 9130)
ON DUPLICATE KEY UPDATE
  category = VALUES(category),
  value_type = VALUES(value_type),
  default_value_text = VALUES(default_value_text),
  allowed_values_json = VALUES(allowed_values_json),
  validation_rules_json = VALUES(validation_rules_json),
  description_text = VALUES(description_text),
  is_admin_editable = VALUES(is_admin_editable),
  sort_order = VALUES(sort_order);
