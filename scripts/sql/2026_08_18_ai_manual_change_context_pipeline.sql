-- Context-Preserving Impact Finder (additive reasoning pipeline).
-- Requires the AI Manual Change Assistant foundation migration.
-- Re-run safe; existing projects, requirements, findings, and proposals remain authoritative.

CREATE TABLE IF NOT EXISTS ipca_manual_ai_analysis_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id BIGINT UNSIGNED NOT NULL,
  job_id BIGINT UNSIGNED NULL,
  run_uuid CHAR(36) NOT NULL,
  pipeline_version VARCHAR(64) NOT NULL,
  source_fingerprint CHAR(64) NOT NULL,
  scope_fingerprint CHAR(64) NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'running' COMMENT 'running | completed | failed | superseded',
  ai_run_id VARCHAR(191) NULL,
  model_name VARCHAR(128) NULL,
  diagnostics_json JSON NULL,
  started_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  completed_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ima_run_uuid (run_uuid),
  KEY idx_ima_run_project (project_id, created_at),
  KEY idx_ima_run_job (job_id),
  KEY idx_ima_run_fingerprints (project_id, source_fingerprint, scope_fingerprint),
  CONSTRAINT fk_ima_run_project
    FOREIGN KEY (project_id) REFERENCES ipca_manual_ai_projects(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ima_run_job
    FOREIGN KEY (job_id) REFERENCES ipca_manual_ai_jobs(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_manual_ai_change_intents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  analysis_run_id BIGINT UNSIGNED NOT NULL,
  project_id BIGINT UNSIGNED NOT NULL,
  intent_version INT UNSIGNED NOT NULL DEFAULT 1,
  change_type VARCHAR(64) NOT NULL,
  primary_domain VARCHAR(191) NOT NULL,
  summary TEXT NOT NULL,
  legacy_concepts_json JSON NOT NULL,
  replacement_concepts_json JSON NOT NULL,
  affected_workflows_json JSON NOT NULL,
  affected_roles_json JSON NOT NULL,
  important_controls_json JSON NOT NULL,
  transitional_arrangements_json JSON NULL,
  unrelated_subjects_json JSON NULL,
  source_evidence_json JSON NOT NULL,
  confidence DECIMAL(5,4) NOT NULL DEFAULT 0.5000,
  model_name VARCHAR(128) NULL,
  prompt_version VARCHAR(64) NULL,
  superseded_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ima_intent_run_version (analysis_run_id, intent_version),
  KEY idx_ima_intent_project (project_id, created_at),
  CONSTRAINT fk_ima_intent_run
    FOREIGN KEY (analysis_run_id) REFERENCES ipca_manual_ai_analysis_runs(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ima_intent_project
    FOREIGN KEY (project_id) REFERENCES ipca_manual_ai_projects(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_manual_ai_target_workflow_areas (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  analysis_run_id BIGINT UNSIGNED NOT NULL,
  change_intent_id BIGINT UNSIGNED NOT NULL,
  project_id BIGINT UNSIGNED NOT NULL,
  area_key VARCHAR(128) NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NOT NULL,
  target_state_json JSON NOT NULL,
  roles_json JSON NULL,
  controls_json JSON NULL,
  evidence_json JSON NULL,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  status VARCHAR(24) NOT NULL DEFAULT 'active' COMMENT 'active | needs_review | excluded',
  confidence DECIMAL(5,4) NOT NULL DEFAULT 0.5000,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ima_workflow_intent_key (change_intent_id, area_key),
  KEY idx_ima_workflow_run (analysis_run_id, sort_order),
  KEY idx_ima_workflow_project (project_id, status),
  CONSTRAINT fk_ima_workflow_run
    FOREIGN KEY (analysis_run_id) REFERENCES ipca_manual_ai_analysis_runs(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ima_workflow_intent
    FOREIGN KEY (change_intent_id) REFERENCES ipca_manual_ai_change_intents(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ima_workflow_project
    FOREIGN KEY (project_id) REFERENCES ipca_manual_ai_projects(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Requirements remain backward compatible. Every addition is independently guarded
-- so a migration interrupted between ALTER statements can be safely rerun.
SET @has_column := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_manual_ai_requirements'
    AND COLUMN_NAME = 'analysis_run_id'
);
SET @ddl := IF(
  @has_column = 0,
  'ALTER TABLE ipca_manual_ai_requirements ADD COLUMN analysis_run_id BIGINT UNSIGNED NULL AFTER project_id',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_column := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_manual_ai_requirements'
    AND COLUMN_NAME = 'change_intent_id'
);
SET @ddl := IF(
  @has_column = 0,
  'ALTER TABLE ipca_manual_ai_requirements ADD COLUMN change_intent_id BIGINT UNSIGNED NULL AFTER analysis_run_id',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_column := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_manual_ai_requirements'
    AND COLUMN_NAME = 'workflow_area_id'
);
SET @ddl := IF(
  @has_column = 0,
  'ALTER TABLE ipca_manual_ai_requirements ADD COLUMN workflow_area_id BIGINT UNSIGNED NULL AFTER change_intent_id',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_column := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_manual_ai_requirements'
    AND COLUMN_NAME = 'validation_status'
);
SET @ddl := IF(
  @has_column = 0,
  'ALTER TABLE ipca_manual_ai_requirements ADD COLUMN validation_status VARCHAR(24) NOT NULL DEFAULT ''active'' COMMENT ''active | needs_review | extraction_error'' AFTER status',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_column := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_manual_ai_requirements'
    AND COLUMN_NAME = 'validation_diagnostics_json'
);
SET @ddl := IF(
  @has_column = 0,
  'ALTER TABLE ipca_manual_ai_requirements ADD COLUMN validation_diagnostics_json JSON NULL AFTER validation_status',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_column := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_manual_ai_requirements'
    AND COLUMN_NAME = 'validated_at'
);
SET @ddl := IF(
  @has_column = 0,
  'ALTER TABLE ipca_manual_ai_requirements ADD COLUMN validated_at DATETIME(3) NULL AFTER validation_diagnostics_json',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_index := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_manual_ai_requirements'
    AND INDEX_NAME = 'idx_imar_analysis_validation'
);
SET @ddl := IF(
  @has_index = 0,
  'ALTER TABLE ipca_manual_ai_requirements ADD KEY idx_imar_analysis_validation (analysis_run_id, validation_status)',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_index := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_manual_ai_requirements'
    AND INDEX_NAME = 'idx_imar_intent_workflow'
);
SET @ddl := IF(
  @has_index = 0,
  'ALTER TABLE ipca_manual_ai_requirements ADD KEY idx_imar_intent_workflow (change_intent_id, workflow_area_id)',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_constraint := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_manual_ai_requirements'
    AND CONSTRAINT_NAME = 'fk_imar_analysis_run'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @ddl := IF(
  @has_constraint = 0,
  'ALTER TABLE ipca_manual_ai_requirements ADD CONSTRAINT fk_imar_analysis_run FOREIGN KEY (analysis_run_id) REFERENCES ipca_manual_ai_analysis_runs(id) ON DELETE SET NULL ON UPDATE CASCADE',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_constraint := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_manual_ai_requirements'
    AND CONSTRAINT_NAME = 'fk_imar_change_intent'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @ddl := IF(
  @has_constraint = 0,
  'ALTER TABLE ipca_manual_ai_requirements ADD CONSTRAINT fk_imar_change_intent FOREIGN KEY (change_intent_id) REFERENCES ipca_manual_ai_change_intents(id) ON DELETE SET NULL ON UPDATE CASCADE',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_constraint := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_manual_ai_requirements'
    AND CONSTRAINT_NAME = 'fk_imar_workflow_area'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @ddl := IF(
  @has_constraint = 0,
  'ALTER TABLE ipca_manual_ai_requirements ADD CONSTRAINT fk_imar_workflow_area FOREIGN KEY (workflow_area_id) REFERENCES ipca_manual_ai_target_workflow_areas(id) ON DELETE SET NULL ON UPDATE CASCADE',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS ipca_manual_ai_impact_areas (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  analysis_run_id BIGINT UNSIGNED NOT NULL,
  project_id BIGINT UNSIGNED NOT NULL,
  change_intent_id BIGINT UNSIGNED NOT NULL,
  workflow_area_id BIGINT UNSIGNED NULL,
  area_key CHAR(64) NOT NULL,
  title VARCHAR(255) NOT NULL,
  summary TEXT NOT NULL,
  recommended_treatment VARCHAR(32) NOT NULL COMMENT 'KEEP | DELETE | REPLACE | AMEND | ADD | CROSS_REFERENCE | REVIEW',
  procedural_relevance TEXT NOT NULL,
  current_content_validity VARCHAR(32) NULL,
  legacy_concepts_json JSON NULL,
  target_concepts_json JSON NULL,
  evidence_json JSON NOT NULL,
  consolidation_method VARCHAR(32) NOT NULL DEFAULT 'section_reasoning',
  confidence DECIMAL(5,4) NOT NULL DEFAULT 0.5000,
  priority VARCHAR(16) NOT NULL DEFAULT 'normal' COMMENT 'low | normal | high | critical',
  status VARCHAR(24) NOT NULL DEFAULT 'proposed' COMMENT 'proposed | approved | dismissed | composed | applied',
  assigned_reviewer_id INT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ima_impact_run_key (analysis_run_id, area_key),
  KEY idx_ima_impact_project_status (project_id, status, priority),
  KEY idx_ima_impact_workflow (workflow_area_id, status),
  CONSTRAINT fk_ima_impact_run
    FOREIGN KEY (analysis_run_id) REFERENCES ipca_manual_ai_analysis_runs(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ima_impact_project
    FOREIGN KEY (project_id) REFERENCES ipca_manual_ai_projects(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ima_impact_intent
    FOREIGN KEY (change_intent_id) REFERENCES ipca_manual_ai_change_intents(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ima_impact_workflow
    FOREIGN KEY (workflow_area_id) REFERENCES ipca_manual_ai_target_workflow_areas(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_ima_impact_reviewer
    FOREIGN KEY (assigned_reviewer_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_manual_ai_impact_area_requirements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  impact_area_id BIGINT UNSIGNED NOT NULL,
  requirement_id BIGINT UNSIGNED NOT NULL,
  link_role VARCHAR(32) NOT NULL DEFAULT 'supporting' COMMENT 'primary | supporting | coverage | conflicting',
  evidence_json JSON NULL,
  confidence DECIMAL(5,4) NULL,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ima_impact_requirement (impact_area_id, requirement_id, link_role),
  KEY idx_ima_impact_req_requirement (requirement_id),
  CONSTRAINT fk_ima_impact_req_area
    FOREIGN KEY (impact_area_id) REFERENCES ipca_manual_ai_impact_areas(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ima_impact_req_requirement
    FOREIGN KEY (requirement_id) REFERENCES ipca_manual_ai_requirements(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_manual_ai_impact_area_sections (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  impact_area_id BIGINT UNSIGNED NOT NULL,
  section_id BIGINT UNSIGNED NOT NULL,
  link_role VARCHAR(32) NOT NULL DEFAULT 'affected' COMMENT 'primary | affected | context | cross_reference',
  stable_anchor VARCHAR(191) NULL,
  section_content_hash CHAR(64) NULL,
  evidence_json JSON NULL,
  confidence DECIMAL(5,4) NULL,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ima_impact_section (impact_area_id, section_id, link_role),
  KEY idx_ima_impact_section_section (section_id),
  CONSTRAINT fk_ima_impact_section_area
    FOREIGN KEY (impact_area_id) REFERENCES ipca_manual_ai_impact_areas(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ima_impact_section_section
    FOREIGN KEY (section_id) REFERENCES ipca_publishing_book_sections(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_manual_ai_impact_area_blocks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  impact_area_id BIGINT UNSIGNED NOT NULL,
  block_id BIGINT UNSIGNED NOT NULL,
  link_role VARCHAR(32) NOT NULL DEFAULT 'evidence' COMMENT 'primary | affected | evidence | context | legacy_hit',
  stable_anchor VARCHAR(191) NULL,
  expected_block_hash CHAR(64) NULL,
  evidence_excerpt TEXT NULL,
  evidence_json JSON NULL,
  confidence DECIMAL(5,4) NULL,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ima_impact_block (impact_area_id, block_id, link_role),
  KEY idx_ima_impact_block_block (block_id),
  CONSTRAINT fk_ima_impact_block_area
    FOREIGN KEY (impact_area_id) REFERENCES ipca_manual_ai_impact_areas(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ima_impact_block_block
    FOREIGN KEY (block_id) REFERENCES ipca_publishing_book_blocks(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_manual_ai_impact_area_findings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  impact_area_id BIGINT UNSIGNED NOT NULL,
  finding_id BIGINT UNSIGNED NOT NULL,
  link_role VARCHAR(32) NOT NULL DEFAULT 'compatibility' COMMENT 'primary | compatibility | supporting | superseded',
  evidence_json JSON NULL,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ima_impact_finding (impact_area_id, finding_id, link_role),
  KEY idx_ima_impact_finding_finding (finding_id),
  CONSTRAINT fk_ima_impact_finding_area
    FOREIGN KEY (impact_area_id) REFERENCES ipca_manual_ai_impact_areas(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ima_impact_finding_finding
    FOREIGN KEY (finding_id) REFERENCES ipca_manual_ai_findings(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_manual_ai_legacy_hits (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  analysis_run_id BIGINT UNSIGNED NOT NULL,
  project_id BIGINT UNSIGNED NOT NULL,
  change_intent_id BIGINT UNSIGNED NOT NULL,
  book_version_id BIGINT UNSIGNED NOT NULL,
  section_id BIGINT UNSIGNED NOT NULL,
  block_id BIGINT UNSIGNED NOT NULL,
  impact_area_id BIGINT UNSIGNED NULL,
  hit_key CHAR(64) NOT NULL,
  legacy_term VARCHAR(255) NOT NULL,
  normalized_term VARCHAR(255) NOT NULL,
  matched_text VARCHAR(500) NOT NULL,
  match_method VARCHAR(24) NOT NULL DEFAULT 'exact' COMMENT 'exact | normalized | contextual',
  match_start INT UNSIGNED NULL,
  match_length INT UNSIGNED NULL,
  context_excerpt TEXT NOT NULL,
  stable_anchor VARCHAR(191) NOT NULL,
  block_content_hash CHAR(64) NOT NULL,
  priority VARCHAR(16) NOT NULL DEFAULT 'high',
  disposition VARCHAR(24) NOT NULL DEFAULT 'unresolved' COMMENT 'unresolved | replace | retain | false_positive',
  retention_justification TEXT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ima_legacy_run_hit (analysis_run_id, hit_key),
  KEY idx_ima_legacy_block (block_id, disposition),
  KEY idx_ima_legacy_impact (impact_area_id),
  CONSTRAINT fk_ima_legacy_run
    FOREIGN KEY (analysis_run_id) REFERENCES ipca_manual_ai_analysis_runs(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ima_legacy_project
    FOREIGN KEY (project_id) REFERENCES ipca_manual_ai_projects(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ima_legacy_intent
    FOREIGN KEY (change_intent_id) REFERENCES ipca_manual_ai_change_intents(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ima_legacy_version
    FOREIGN KEY (book_version_id) REFERENCES ipca_publishing_book_versions(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ima_legacy_section
    FOREIGN KEY (section_id) REFERENCES ipca_publishing_book_sections(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ima_legacy_block
    FOREIGN KEY (block_id) REFERENCES ipca_publishing_book_blocks(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ima_legacy_impact
    FOREIGN KEY (impact_area_id) REFERENCES ipca_manual_ai_impact_areas(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_manual_ai_scope_warnings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  analysis_run_id BIGINT UNSIGNED NOT NULL,
  project_id BIGINT UNSIGNED NOT NULL,
  book_version_id BIGINT UNSIGNED NULL,
  workflow_area_id BIGINT UNSIGNED NULL,
  warning_key CHAR(64) NOT NULL,
  warning_code VARCHAR(64) NOT NULL,
  severity VARCHAR(16) NOT NULL DEFAULT 'warning' COMMENT 'info | warning | blocking',
  requested_scope TEXT NULL,
  resolved_scope TEXT NULL,
  message TEXT NOT NULL,
  context_json JSON NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'open' COMMENT 'open | acknowledged | resolved',
  acknowledged_by INT NULL,
  acknowledged_at DATETIME(3) NULL,
  resolved_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ima_scope_warning (analysis_run_id, warning_key),
  KEY idx_ima_scope_project (project_id, status, severity),
  CONSTRAINT fk_ima_scope_run
    FOREIGN KEY (analysis_run_id) REFERENCES ipca_manual_ai_analysis_runs(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ima_scope_project
    FOREIGN KEY (project_id) REFERENCES ipca_manual_ai_projects(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ima_scope_version
    FOREIGN KEY (book_version_id) REFERENCES ipca_publishing_book_versions(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_ima_scope_workflow
    FOREIGN KEY (workflow_area_id) REFERENCES ipca_manual_ai_target_workflow_areas(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_ima_scope_actor
    FOREIGN KEY (acknowledged_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_manual_ai_composer_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  analysis_run_id BIGINT UNSIGNED NOT NULL,
  project_id BIGINT UNSIGNED NOT NULL,
  impact_area_id BIGINT UNSIGNED NOT NULL,
  run_uuid CHAR(36) NOT NULL,
  composer_version VARCHAR(64) NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'queued' COMMENT 'queued | running | completed | failed | superseded',
  attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
  idempotency_key CHAR(64) NOT NULL,
  source_fingerprint CHAR(64) NOT NULL,
  scope_fingerprint CHAR(64) NOT NULL,
  context_hash CHAR(64) NOT NULL,
  prompt_hash CHAR(64) NULL,
  result_hash CHAR(64) NULL,
  model_name VARCHAR(128) NULL,
  ai_run_id VARCHAR(191) NULL,
  result_json JSON NULL,
  error_message TEXT NULL,
  created_by INT NULL,
  started_at DATETIME(3) NULL,
  completed_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ima_composer_uuid (run_uuid),
  UNIQUE KEY uk_ima_composer_idempotency (idempotency_key),
  KEY idx_ima_composer_project (project_id, status, created_at),
  KEY idx_ima_composer_impact (impact_area_id, created_at),
  CONSTRAINT fk_ima_composer_analysis
    FOREIGN KEY (analysis_run_id) REFERENCES ipca_manual_ai_analysis_runs(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ima_composer_project
    FOREIGN KEY (project_id) REFERENCES ipca_manual_ai_projects(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ima_composer_impact
    FOREIGN KEY (impact_area_id) REFERENCES ipca_manual_ai_impact_areas(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ima_composer_actor
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_manual_ai_composer_proposals (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  composer_run_id BIGINT UNSIGNED NOT NULL,
  proposal_id BIGINT UNSIGNED NOT NULL,
  link_role VARCHAR(24) NOT NULL DEFAULT 'generated' COMMENT 'generated | revised | superseded',
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ima_composer_proposal (composer_run_id, proposal_id, link_role),
  KEY idx_ima_composer_prop_proposal (proposal_id),
  CONSTRAINT fk_ima_composer_prop_run
    FOREIGN KEY (composer_run_id) REFERENCES ipca_manual_ai_composer_runs(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ima_composer_prop_proposal
    FOREIGN KEY (proposal_id) REFERENCES ipca_manual_ai_proposals(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_manual_ai_consistency_assertions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  analysis_run_id BIGINT UNSIGNED NOT NULL,
  composer_run_id BIGINT UNSIGNED NOT NULL,
  project_id BIGINT UNSIGNED NOT NULL,
  impact_area_id BIGINT UNSIGNED NULL,
  workflow_area_id BIGINT UNSIGNED NULL,
  assertion_key CHAR(64) NOT NULL,
  assertion_type VARCHAR(48) NOT NULL COMMENT 'legacy_term | obsolete_url | contradiction | duplication | dangling_reference | coverage | role_consistency | system_name | transition',
  subject VARCHAR(255) NOT NULL,
  operator VARCHAR(24) NOT NULL DEFAULT 'equals',
  expected_value_json JSON NOT NULL,
  actual_value_json JSON NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'pending' COMMENT 'pending | passed | failed | justified | needs_review',
  severity VARCHAR(16) NOT NULL DEFAULT 'warning',
  rationale TEXT NULL,
  evidence_json JSON NOT NULL,
  justification TEXT NULL,
  checked_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ima_assertion_composer_key (composer_run_id, assertion_key),
  KEY idx_ima_assertion_project (project_id, status, severity),
  KEY idx_ima_assertion_analysis (analysis_run_id, assertion_type),
  CONSTRAINT fk_ima_assertion_analysis
    FOREIGN KEY (analysis_run_id) REFERENCES ipca_manual_ai_analysis_runs(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ima_assertion_composer
    FOREIGN KEY (composer_run_id) REFERENCES ipca_manual_ai_composer_runs(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ima_assertion_project
    FOREIGN KEY (project_id) REFERENCES ipca_manual_ai_projects(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ima_assertion_impact
    FOREIGN KEY (impact_area_id) REFERENCES ipca_manual_ai_impact_areas(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_ima_assertion_workflow
    FOREIGN KEY (workflow_area_id) REFERENCES ipca_manual_ai_target_workflow_areas(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_manual_ai_assertion_requirements (
  assertion_id BIGINT UNSIGNED NOT NULL,
  requirement_id BIGINT UNSIGNED NOT NULL,
  link_role VARCHAR(32) NOT NULL DEFAULT 'coverage',
  evidence_json JSON NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (assertion_id, requirement_id, link_role),
  KEY idx_ima_assert_req_requirement (requirement_id),
  CONSTRAINT fk_ima_assert_req_assertion
    FOREIGN KEY (assertion_id) REFERENCES ipca_manual_ai_consistency_assertions(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ima_assert_req_requirement
    FOREIGN KEY (requirement_id) REFERENCES ipca_manual_ai_requirements(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_manual_ai_assertion_sections (
  assertion_id BIGINT UNSIGNED NOT NULL,
  section_id BIGINT UNSIGNED NOT NULL,
  link_role VARCHAR(32) NOT NULL DEFAULT 'affected',
  evidence_json JSON NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (assertion_id, section_id, link_role),
  KEY idx_ima_assert_section_section (section_id),
  CONSTRAINT fk_ima_assert_section_assertion
    FOREIGN KEY (assertion_id) REFERENCES ipca_manual_ai_consistency_assertions(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ima_assert_section_section
    FOREIGN KEY (section_id) REFERENCES ipca_publishing_book_sections(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_manual_ai_assertion_blocks (
  assertion_id BIGINT UNSIGNED NOT NULL,
  block_id BIGINT UNSIGNED NOT NULL,
  link_role VARCHAR(32) NOT NULL DEFAULT 'evidence',
  evidence_excerpt TEXT NULL,
  evidence_json JSON NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (assertion_id, block_id, link_role),
  KEY idx_ima_assert_block_block (block_id),
  CONSTRAINT fk_ima_assert_block_assertion
    FOREIGN KEY (assertion_id) REFERENCES ipca_manual_ai_consistency_assertions(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ima_assert_block_block
    FOREIGN KEY (block_id) REFERENCES ipca_publishing_book_blocks(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_manual_ai_assertion_findings (
  assertion_id BIGINT UNSIGNED NOT NULL,
  finding_id BIGINT UNSIGNED NOT NULL,
  link_role VARCHAR(32) NOT NULL DEFAULT 'compatibility',
  evidence_json JSON NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (assertion_id, finding_id, link_role),
  KEY idx_ima_assert_finding_finding (finding_id),
  CONSTRAINT fk_ima_assert_finding_assertion
    FOREIGN KEY (assertion_id) REFERENCES ipca_manual_ai_consistency_assertions(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ima_assert_finding_finding
    FOREIGN KEY (finding_id) REFERENCES ipca_manual_ai_findings(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_manual_ai_assertion_proposals (
  assertion_id BIGINT UNSIGNED NOT NULL,
  proposal_id BIGINT UNSIGNED NOT NULL,
  link_role VARCHAR(32) NOT NULL DEFAULT 'evaluated',
  evidence_json JSON NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (assertion_id, proposal_id, link_role),
  KEY idx_ima_assert_prop_proposal (proposal_id),
  CONSTRAINT fk_ima_assert_prop_assertion
    FOREIGN KEY (assertion_id) REFERENCES ipca_manual_ai_consistency_assertions(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ima_assert_prop_proposal
    FOREIGN KEY (proposal_id) REFERENCES ipca_manual_ai_proposals(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
