-- FAA Mock Oral Exam Preparation — core schema

CREATE TABLE IF NOT EXISTS mock_oral_acs_catalogs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  catalog_key VARCHAR(64) NOT NULL,
  label VARCHAR(255) NOT NULL,
  rating VARCHAR(64) NOT NULL DEFAULT 'private_pilot',
  version VARCHAR(32) NOT NULL DEFAULT '1.0',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mock_oral_catalog_key (catalog_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mock_oral_acs_areas (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  catalog_id BIGINT UNSIGNED NOT NULL,
  area_code VARCHAR(32) NOT NULL,
  title VARCHAR(255) NOT NULL,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  scenario_templates_json JSON NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mock_oral_area_catalog_code (catalog_id, area_code),
  KEY idx_mock_oral_area_catalog_sort (catalog_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mock_oral_acs_tasks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  area_id BIGINT UNSIGNED NOT NULL,
  task_code VARCHAR(64) NOT NULL,
  element_code VARCHAR(64) NULL,
  title VARCHAR(512) NOT NULL,
  risk_level TINYINT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mock_oral_task_area (area_id),
  KEY idx_mock_oral_task_code (task_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mock_oral_acs_area_lesson_map (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  area_id BIGINT UNSIGNED NOT NULL,
  lesson_id BIGINT UNSIGNED NOT NULL,
  weight DECIMAL(5,2) NOT NULL DEFAULT 1.00,
  notes VARCHAR(512) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mock_oral_area_lesson (area_id, lesson_id),
  KEY idx_mock_oral_area_lesson_lesson (lesson_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_mock_oral_permissions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id BIGINT UNSIGNED NOT NULL,
  cohort_id BIGINT UNSIGNED NOT NULL,
  catalog_id BIGINT UNSIGNED NOT NULL,
  mock_oral_enabled TINYINT(1) NOT NULL DEFAULT 0,
  approved_by_user_id BIGINT UNSIGNED NULL,
  approved_at DATETIME NULL,
  revoked_by_user_id BIGINT UNSIGNED NULL,
  revoked_at DATETIME NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mock_oral_perm_student_cohort_catalog (student_id, cohort_id, catalog_id),
  KEY idx_mock_oral_perm_cohort (cohort_id, mock_oral_enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_mock_oral_module_progress (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id BIGINT UNSIGNED NOT NULL,
  cohort_id BIGINT UNSIGNED NOT NULL,
  area_id BIGINT UNSIGNED NOT NULL,
  sessions_completed INT UNSIGNED NOT NULL DEFAULT 0,
  best_score_pct DECIMAL(5,2) NULL,
  last_session_at DATETIME NULL,
  readiness_status ENUM('not_started','in_progress','ready','needs_work') NOT NULL DEFAULT 'not_started',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mock_oral_module_progress (student_id, cohort_id, area_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS faa_knowledge_test_reports (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  cohort_id BIGINT UNSIGNED NOT NULL,
  catalog_id BIGINT UNSIGNED NULL,
  uploaded_by_user_id BIGINT UNSIGNED NOT NULL,
  original_filename VARCHAR(255) NOT NULL,
  storage_path VARCHAR(512) NOT NULL,
  file_hash CHAR(64) NOT NULL,
  test_date DATE NULL,
  test_code VARCHAR(64) NULL,
  overall_score_pct DECIMAL(5,2) NULL,
  parse_status ENUM('pending','parsed','needs_review','failed') NOT NULL DEFAULT 'pending',
  parse_model VARCHAR(64) NULL,
  parse_raw_json LONGTEXT NULL,
  parsed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_faa_ktr_user_cohort (user_id, cohort_id),
  KEY idx_faa_ktr_parse_status (parse_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS faa_knowledge_test_deficiencies (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  report_id BIGINT UNSIGNED NOT NULL,
  area_id BIGINT UNSIGNED NULL,
  deficiency_code VARCHAR(64) NULL,
  deficiency_label VARCHAR(512) NOT NULL,
  question_topic VARCHAR(512) NULL,
  acs_task_code VARCHAR(64) NULL,
  confidence DECIMAL(5,4) NULL,
  source_page INT UNSIGNED NULL,
  review_status ENUM('auto','confirmed','rejected') NOT NULL DEFAULT 'auto',
  reviewed_by_user_id BIGINT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_faa_ktd_report (report_id),
  KEY idx_faa_ktd_area (area_id),
  KEY idx_faa_ktd_review (review_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mock_oral_remote_authorizations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id BIGINT UNSIGNED NOT NULL,
  cohort_id BIGINT UNSIGNED NOT NULL,
  catalog_id BIGINT UNSIGNED NOT NULL,
  area_id BIGINT UNSIGNED NOT NULL,
  session_id BIGINT UNSIGNED NULL,
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
  KEY idx_mora_student_area_status (student_id, area_id, status),
  KEY idx_mora_student_cohort_status (student_id, cohort_id, status),
  KEY idx_mora_token_hash (request_token_hash),
  KEY idx_mora_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mock_oral_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  cohort_id BIGINT UNSIGNED NOT NULL,
  catalog_id BIGINT UNSIGNED NOT NULL,
  area_id BIGINT UNSIGNED NOT NULL,
  authorization_id BIGINT UNSIGNED NULL,
  status ENUM(
    'auth_pending','blueprint_generating','ready',
    'in_progress','turn_evaluating','debrief_generating',
    'completed','aborted','stale','failed'
  ) NOT NULL DEFAULT 'auth_pending',
  blueprint_json LONGTEXT NULL,
  scenario_json LONGTEXT NULL,
  weak_area_snapshot_json LONGTEXT NULL,
  heygen_session_id VARCHAR(128) NULL,
  heygen_token_issued_at DATETIME NULL,
  max_duration_sec INT UNSIGNED NOT NULL DEFAULT 300,
  started_at DATETIME NULL,
  ended_at DATETIME NULL,
  stale_at DATETIME NULL,
  last_heartbeat_at DATETIME NULL,
  score_pct DECIMAL(5,2) NULL,
  readiness_delta_json LONGTEXT NULL,
  idempotency_key VARCHAR(128) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mock_oral_session_idempotency (idempotency_key),
  KEY idx_mock_oral_session_user_cohort (user_id, cohort_id),
  KEY idx_mock_oral_session_status (status),
  KEY idx_mock_oral_session_area (area_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mock_oral_transcript_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id BIGINT UNSIGNED NOT NULL,
  role ENUM('maya','student','system') NOT NULL,
  turn_index INT UNSIGNED NOT NULL DEFAULT 0,
  event_type VARCHAR(64) NOT NULL DEFAULT 'utterance',
  transcript_text TEXT NULL,
  meta_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mock_oral_te_session (session_id, turn_index)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mock_oral_turn_evaluations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id BIGINT UNSIGNED NOT NULL,
  turn_index INT UNSIGNED NOT NULL,
  acs_task_codes_json LONGTEXT NULL,
  score_pct DECIMAL(5,2) NULL,
  missing_concepts_json LONGTEXT NULL,
  follow_up_directive_json LONGTEXT NULL,
  evaluator_model VARCHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mock_oral_turn_eval (session_id, turn_index)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mock_oral_debriefs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id BIGINT UNSIGNED NOT NULL,
  written_debrief_html LONGTEXT NULL,
  written_debrief_text LONGTEXT NULL,
  weak_areas_json LONGTEXT NULL,
  remediation_json LONGTEXT NULL,
  debrief_model VARCHAR(64) NULL,
  generated_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mock_oral_debrief_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mock_oral_session_deficiencies (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id BIGINT UNSIGNED NOT NULL,
  area_id BIGINT UNSIGNED NULL,
  acs_task_code VARCHAR(64) NULL,
  concept VARCHAR(512) NOT NULL,
  weight DECIMAL(8,4) NOT NULL DEFAULT 1.0000,
  source ENUM('session','prior') NOT NULL DEFAULT 'session',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mock_oral_sd_session (session_id),
  KEY idx_mock_oral_sd_user_area (area_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mock_oral_usage_quotas (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  cohort_id BIGINT UNSIGNED NOT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  sessions_allowed INT UNSIGNED NOT NULL DEFAULT 3,
  sessions_used INT UNSIGNED NOT NULL DEFAULT 0,
  heygen_minutes_used DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mock_oral_quota_period (user_id, cohort_id, period_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
