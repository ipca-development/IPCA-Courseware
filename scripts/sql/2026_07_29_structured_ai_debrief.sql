-- Append-only, evidence-cited AI debrief suggestions and instructor-authoritative review.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET collation_connection = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_structured_debriefs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  debrief_uuid CHAR(36) NOT NULL,
  bundle_id BIGINT UNSIGNED NOT NULL,
  mission_version_id BIGINT UNSIGNED NOT NULL,
  transcript_snapshot_id BIGINT UNSIGNED NOT NULL,
  supersedes_debrief_id BIGINT UNSIGNED NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'ai_draft',
  provider VARCHAR(64) NOT NULL DEFAULT 'openai',
  model VARCHAR(128) NOT NULL,
  prompt_version VARCHAR(32) NOT NULL,
  logic_version VARCHAR(32) NOT NULL,
  prompt_sha256 CHAR(64) NOT NULL,
  request_sha256 CHAR(64) NOT NULL,
  response_sha256 CHAR(64) NOT NULL,
  raw_response_json JSON NOT NULL,
  general_text TEXT NOT NULL,
  chronological_review_json JSON NOT NULL,
  mission_assessment_text TEXT NOT NULL,
  summary_next_steps_text TEXT NOT NULL,
  suggested_overall VARCHAR(32) NOT NULL,
  overall_calculation_json JSON NOT NULL,
  uncertainty_json JSON NOT NULL,
  instructor_overall VARCHAR(32) NULL,
  instructor_comments TEXT NULL,
  approved_by BIGINT UNSIGNED NULL,
  approved_at DATETIME(3) NULL,
  released_at DATETIME(3) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_structured_debriefs_uuid (debrief_uuid),
  KEY idx_ipca_structured_debriefs_bundle (bundle_id, created_at),
  KEY idx_ipca_structured_debriefs_status (status, updated_at),
  CONSTRAINT fk_ipca_structured_debriefs_bundle
    FOREIGN KEY (bundle_id) REFERENCES ipca_manual_intake_bundles(id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_ipca_structured_debriefs_snapshot
    FOREIGN KEY (transcript_snapshot_id) REFERENCES ipca_cockpit_transcript_snapshots(id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_ipca_structured_debriefs_supersedes
    FOREIGN KEY (supersedes_debrief_id) REFERENCES ipca_structured_debriefs(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_structured_debrief_evaluations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  evaluation_uuid CHAR(36) NOT NULL,
  debrief_id BIGINT UNSIGNED NOT NULL,
  rubric_type VARCHAR(16) NOT NULL,
  rubric_item_id VARCHAR(128) NOT NULL,
  title VARCHAR(255) NOT NULL,
  required_standard VARCHAR(8) NOT NULL,
  suggested_grade VARCHAR(8) NULL,
  evidence_status VARCHAR(32) NOT NULL,
  completion_status VARCHAR(32) NOT NULL,
  rationale TEXT NOT NULL,
  confidence DECIMAL(5,4) NOT NULL DEFAULT 0,
  evidence_refs_json JSON NOT NULL,
  instructor_prompting_json JSON NOT NULL,
  main_issue TEXT NULL,
  improvement_suggestion TEXT NULL,
  instructor_grade VARCHAR(8) NULL,
  instructor_comment TEXT NULL,
  reviewed_by BIGINT UNSIGNED NULL,
  reviewed_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_structured_debrief_eval_uuid (evaluation_uuid),
  UNIQUE KEY uk_ipca_structured_debrief_eval_item (debrief_id, rubric_type, rubric_item_id),
  KEY idx_ipca_structured_debrief_eval_debrief (debrief_id, rubric_type),
  CONSTRAINT fk_ipca_structured_debrief_eval_debrief
    FOREIGN KEY (debrief_id) REFERENCES ipca_structured_debriefs(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_structured_debrief_audit (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_uuid CHAR(36) NOT NULL,
  debrief_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(64) NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  old_values_json JSON NULL,
  new_values_json JSON NULL,
  reason VARCHAR(255) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_structured_debrief_audit_uuid (event_uuid),
  KEY idx_ipca_structured_debrief_audit_debrief (debrief_id, created_at),
  CONSTRAINT fk_ipca_structured_debrief_audit_debrief
    FOREIGN KEY (debrief_id) REFERENCES ipca_structured_debriefs(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
