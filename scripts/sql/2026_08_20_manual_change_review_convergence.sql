-- Convergent Independent Review persistence.
-- Additive only: no accepted Architect, structure, draft, or publishing row is rewritten.

CREATE TABLE IF NOT EXISTS ipca_manual_ai_architect_review_baselines (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  baseline_uuid CHAR(36) NOT NULL,
  plan_id BIGINT UNSIGNED NOT NULL,
  review_id BIGINT UNSIGNED NULL,
  impact_baseline_json JSON NOT NULL,
  boundary_baseline_json JSON NOT NULL,
  structure_baseline_json JSON NOT NULL,
  draft_baseline_json JSON NOT NULL,
  target_state_baseline_json JSON NOT NULL,
  limitation_baseline_json JSON NOT NULL,
  governed_facts_baseline_json JSON NOT NULL,
  baseline_fingerprint CHAR(64) NOT NULL,
  created_by INT NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_imaa_review_baseline_uuid (baseline_uuid),
  UNIQUE KEY uk_imaa_review_baseline_hash (plan_id, baseline_fingerprint),
  KEY idx_imaa_review_baseline_plan (plan_id, created_at),
  CONSTRAINT fk_imaa_review_baseline_plan
    FOREIGN KEY (plan_id) REFERENCES ipca_manual_ai_architect_plans(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_review_baseline_review
    FOREIGN KEY (review_id) REFERENCES ipca_manual_ai_architect_reviews(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_review_baseline_actor
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_manual_ai_architect_review_findings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  finding_uuid CHAR(36) NOT NULL,
  plan_id BIGINT UNSIGNED NOT NULL,
  review_id BIGINT UNSIGNED NOT NULL,
  baseline_id BIGINT UNSIGNED NOT NULL,
  finding_fingerprint CHAR(64) NOT NULL,
  finding_class VARCHAR(40) NOT NULL COMMENT 'INFORMATIONAL | MECHANICAL_FIX | HUMAN_DECISION_REQUIRED | TARGETED_AUTHOR_CORRECTION | HARD_INTEGRITY_BLOCKER | POTENTIAL_SCOPE_DEFECT',
  outcome VARCHAR(40) NOT NULL COMMENT 'PASS | HUMAN_CLARIFICATION_REQUIRED | CONFIRMED_DEFECT | POTENTIAL_SCOPE_DEFECT',
  status VARCHAR(32) NOT NULL DEFAULT 'open' COMMENT 'open | question_pending | answered | patch_pending | patched | verified | closed | blocked',
  material TINYINT(1) NOT NULL DEFAULT 1,
  blocking TINYINT(1) NOT NULL DEFAULT 1,
  title VARCHAR(255) NOT NULL,
  human_explanation TEXT NOT NULL,
  unresolved_fact TEXT NULL,
  why_matters TEXT NULL,
  affected_sections_json JSON NOT NULL,
  accepted_wording_json JSON NOT NULL,
  evidence_json JSON NOT NULL,
  resolution_json JSON NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  resolved_at DATETIME(3) NULL,
  UNIQUE KEY uk_imaa_review_finding_uuid (finding_uuid),
  UNIQUE KEY uk_imaa_review_finding_hash (plan_id, finding_fingerprint),
  KEY idx_imaa_review_finding_state (plan_id, review_id, status, finding_class),
  CONSTRAINT fk_imaa_review_finding_plan
    FOREIGN KEY (plan_id) REFERENCES ipca_manual_ai_architect_plans(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_review_finding_review
    FOREIGN KEY (review_id) REFERENCES ipca_manual_ai_architect_reviews(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_review_finding_baseline
    FOREIGN KEY (baseline_id) REFERENCES ipca_manual_ai_architect_review_baselines(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_manual_ai_architect_review_questions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  question_uuid CHAR(36) NOT NULL,
  plan_id BIGINT UNSIGNED NOT NULL,
  finding_id BIGINT UNSIGNED NOT NULL,
  question_fingerprint CHAR(64) NOT NULL,
  question_type VARCHAR(32) NOT NULL COMMENT 'YES_NO | SINGLE_CHOICE | MULTIPLE_CHOICE | CONFLICTING_RULE | CONSTRAINED_OTHER',
  title VARCHAR(255) NOT NULL,
  prompt TEXT NOT NULL,
  choices_json JSON NOT NULL,
  recommendation_json JSON NULL,
  why_asking TEXT NOT NULL,
  affected_sections_json JSON NOT NULL,
  evidence_json JSON NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'pending' COMMENT 'pending | answered | superseded',
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_imaa_review_question_uuid (question_uuid),
  UNIQUE KEY uk_imaa_review_question_hash (plan_id, question_fingerprint),
  KEY idx_imaa_review_question_state (plan_id, status, id),
  CONSTRAINT fk_imaa_review_question_plan
    FOREIGN KEY (plan_id) REFERENCES ipca_manual_ai_architect_plans(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_review_question_finding
    FOREIGN KEY (finding_id) REFERENCES ipca_manual_ai_architect_review_findings(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_manual_ai_architect_review_answers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  answer_uuid CHAR(36) NOT NULL,
  plan_id BIGINT UNSIGNED NOT NULL,
  finding_id BIGINT UNSIGNED NOT NULL,
  question_id BIGINT UNSIGNED NOT NULL,
  answer_fingerprint CHAR(64) NOT NULL,
  question_snapshot_json JSON NOT NULL,
  choices_snapshot_json JSON NOT NULL,
  selected_answer_json JSON NOT NULL,
  explanation TEXT NULL,
  affected_sections_json JSON NOT NULL,
  evidence_snapshot_json JSON NOT NULL,
  governed_fact_json JSON NOT NULL,
  consequence VARCHAR(40) NOT NULL COMMENT 'NO_MANUAL_CHANGE_REQUIRED | TARGETED_WORDING_CHANGE_REQUIRED | STRUCTURAL_CONSEQUENCE',
  wording_change_required TINYINT(1) NOT NULL DEFAULT 0,
  actor_id INT NOT NULL,
  answered_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_imaa_review_answer_uuid (answer_uuid),
  UNIQUE KEY uk_imaa_review_answer_hash (plan_id, answer_fingerprint),
  UNIQUE KEY uk_imaa_review_answer_question (question_id),
  KEY idx_imaa_review_answer_plan (plan_id, answered_at),
  CONSTRAINT fk_imaa_review_answer_plan
    FOREIGN KEY (plan_id) REFERENCES ipca_manual_ai_architect_plans(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_review_answer_finding
    FOREIGN KEY (finding_id) REFERENCES ipca_manual_ai_architect_review_findings(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_review_answer_question
    FOREIGN KEY (question_id) REFERENCES ipca_manual_ai_architect_review_questions(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_review_answer_actor
    FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_manual_ai_architect_review_patches (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  patch_uuid CHAR(36) NOT NULL,
  plan_id BIGINT UNSIGNED NOT NULL,
  baseline_id BIGINT UNSIGNED NOT NULL,
  parent_draft_id BIGINT UNSIGNED NOT NULL,
  resulting_draft_id BIGINT UNSIGNED NULL,
  finding_ids_json JSON NOT NULL,
  scope_json JSON NOT NULL,
  before_payload_json JSON NOT NULL,
  proposed_payload_json JSON NOT NULL,
  unchanged_fingerprints_json JSON NOT NULL,
  patch_fingerprint CHAR(64) NOT NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'PROPOSED' COMMENT 'PROPOSED | HUMAN_ACCEPTED_PENDING_VERIFICATION | VERIFIED | VERIFICATION_FAILED | ADJUSTMENT_REQUESTED | SUPERSEDED',
  verification_json JSON NULL,
  proposed_by INT NOT NULL,
  accepted_by INT NULL,
  proposed_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  accepted_at DATETIME(3) NULL,
  verified_at DATETIME(3) NULL,
  UNIQUE KEY uk_imaa_review_patch_uuid (patch_uuid),
  UNIQUE KEY uk_imaa_review_patch_hash (plan_id, patch_fingerprint),
  KEY idx_imaa_review_patch_state (plan_id, status, id),
  CONSTRAINT fk_imaa_review_patch_plan
    FOREIGN KEY (plan_id) REFERENCES ipca_manual_ai_architect_plans(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_review_patch_baseline
    FOREIGN KEY (baseline_id) REFERENCES ipca_manual_ai_architect_review_baselines(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_review_patch_parent_draft
    FOREIGN KEY (parent_draft_id) REFERENCES ipca_manual_ai_architect_drafts(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_review_patch_result_draft
    FOREIGN KEY (resulting_draft_id) REFERENCES ipca_manual_ai_architect_drafts(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_review_patch_proposer
    FOREIGN KEY (proposed_by) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_review_patch_acceptor
    FOREIGN KEY (accepted_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_manual_ai_architect_review_cycles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cycle_uuid CHAR(36) NOT NULL,
  plan_id BIGINT UNSIGNED NOT NULL,
  review_id BIGINT UNSIGNED NOT NULL,
  baseline_id BIGINT UNSIGNED NOT NULL,
  cycle_number INT UNSIGNED NOT NULL,
  unresolved_before INT UNSIGNED NOT NULL,
  informational_count INT UNSIGNED NOT NULL DEFAULT 0,
  mechanical_fix_count INT UNSIGNED NOT NULL DEFAULT 0,
  human_question_count INT UNSIGNED NOT NULL DEFAULT 0,
  governed_answer_count INT UNSIGNED NOT NULL DEFAULT 0,
  targeted_patch_count INT UNSIGNED NOT NULL DEFAULT 0,
  accepted_patch_count INT UNSIGNED NOT NULL DEFAULT 0,
  hard_blocker_count INT UNSIGNED NOT NULL DEFAULT 0,
  unresolved_after INT UNSIGNED NOT NULL,
  status VARCHAR(32) NOT NULL COMMENT 'OPEN | CONVERGING | READY_TO_APPLY | REVIEW_DIVERGENCE_DETECTED',
  metrics_json JSON NOT NULL,
  cycle_fingerprint CHAR(64) NOT NULL,
  recorded_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_imaa_review_cycle_uuid (cycle_uuid),
  UNIQUE KEY uk_imaa_review_cycle_hash (plan_id, cycle_fingerprint),
  UNIQUE KEY uk_imaa_review_cycle_number (review_id, cycle_number),
  KEY idx_imaa_review_cycle_plan (plan_id, cycle_number),
  CONSTRAINT fk_imaa_review_cycle_plan
    FOREIGN KEY (plan_id) REFERENCES ipca_manual_ai_architect_plans(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_review_cycle_review
    FOREIGN KEY (review_id) REFERENCES ipca_manual_ai_architect_reviews(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_review_cycle_baseline
    FOREIGN KEY (baseline_id) REFERENCES ipca_manual_ai_architect_review_baselines(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE ipca_manual_ai_architect_review_patches
  MODIFY COLUMN status VARCHAR(40) NOT NULL DEFAULT 'PROPOSED'
  COMMENT 'PROPOSED | HUMAN_ACCEPTED_PENDING_VERIFICATION | VERIFIED | VERIFICATION_FAILED | ADJUSTMENT_REQUESTED | SUPERSEDED';

CREATE TABLE IF NOT EXISTS ipca_manual_ai_architect_review_check_metadata (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  check_uuid CHAR(36) NOT NULL,
  plan_id BIGINT UNSIGNED NOT NULL,
  review_id BIGINT UNSIGNED NOT NULL,
  finding_id BIGINT UNSIGNED NOT NULL,
  check_id VARCHAR(191) NOT NULL,
  check_version VARCHAR(32) NOT NULL,
  category VARCHAR(64) NOT NULL,
  severity VARCHAR(32) NOT NULL,
  review_status VARCHAR(24) NOT NULL COMMENT 'PASS | FAIL | INFORMATIONAL',
  resolution_status VARCHAR(24) NOT NULL COMMENT 'UNRESOLVED | VERIFIED | BLOCKED',
  affected_nodes_json JSON NOT NULL,
  required_invariant TEXT NOT NULL,
  observed_state TEXT NOT NULL,
  evidence_references_json JSON NOT NULL,
  allowed_repair_scope_json JSON NOT NULL,
  known_limitations_json JSON NOT NULL,
  first_seen_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  last_verified_at DATETIME(3) NULL,
  verified_at DATETIME(3) NULL,
  UNIQUE KEY uk_imaa_review_check_uuid (check_uuid),
  UNIQUE KEY uk_imaa_review_check_identity (plan_id, review_id, check_id),
  UNIQUE KEY uk_imaa_review_check_finding (finding_id),
  KEY idx_imaa_review_check_state (plan_id, review_id, resolution_status, category),
  CONSTRAINT fk_imaa_review_check_plan
    FOREIGN KEY (plan_id) REFERENCES ipca_manual_ai_architect_plans(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_review_check_review
    FOREIGN KEY (review_id) REFERENCES ipca_manual_ai_architect_reviews(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_review_check_finding
    FOREIGN KEY (finding_id) REFERENCES ipca_manual_ai_architect_review_findings(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
