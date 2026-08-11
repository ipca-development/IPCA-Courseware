-- Phase 9 PRODUCTION SCHEMA PROPOSAL (DO NOT APPLY in Phase 9)
-- Controlled design only. Operational Session remains authoritative.
-- Apply only after readiness gates PASS and explicit instruction.

-- Feature flags (proposed; not enabled)
-- CREATE TABLE IF NOT EXISTS ipca_feature_flags (
--   flag_name VARCHAR(64) PRIMARY KEY,
--   state ENUM('OFF','SHADOW','PILOT_USERS','ON') NOT NULL DEFAULT 'OFF',
--   updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
--   updated_by_user_id BIGINT NULL
-- );

-- Canonical exercise (may extend ipca_flight_exercise_catalog rather than duplicate)
-- ALTER TABLE ipca_flight_exercise_catalog ... already has exercise_code as canonical ID.

-- Proposed new production tables (names illustrative)

/*
CREATE TABLE ipca_exercise_attempts (
  attempt_uuid CHAR(36) NOT NULL PRIMARY KEY,
  operational_session_uuid CHAR(36) NOT NULL,
  source_event_uuid CHAR(36) NULL,
  canonical_exercise_code VARCHAR(64) NOT NULL,
  start_utc DATETIME(3) NOT NULL,
  end_utc DATETIME(3) NULL,
  start_boundary_source VARCHAR(64) NOT NULL,
  end_boundary_source VARCHAR(64) NOT NULL,
  boundary_confidence DECIMAL(6,4) NULL,
  actual_leg_id BIGINT NULL,
  instructor_device VARCHAR(128) NULL,
  idempotency_key VARCHAR(191) NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_attempt_idempotency (idempotency_key),
  KEY idx_attempt_session (operational_session_uuid),
  CONSTRAINT fk_attempt_session FOREIGN KEY (operational_session_uuid)
    REFERENCES ipca_flight_sessions(session_uuid)
);

CREATE TABLE ipca_objective_measurements (
  measurement_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  attempt_uuid CHAR(36) NOT NULL,
  tolerance_pack_id VARCHAR(64) NOT NULL,
  tolerance_pack_version VARCHAR(32) NOT NULL,
  metric VARCHAR(64) NOT NULL,
  actual_value DOUBLE NULL,
  target_value DOUBLE NULL,
  lower_tolerance DOUBLE NULL,
  upper_tolerance DOUBLE NULL,
  unit VARCHAR(16) NULL,
  max_deviation DOUBLE NULL,
  time_outside_tolerance_sec DOUBLE NULL,
  pct_within_tolerance DOUBLE NULL,
  within_standard TINYINT(1) NULL,
  raw_payload_json JSON NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY idx_meas_attempt (attempt_uuid)
);

CREATE TABLE ipca_independence_observations (
  observation_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  attempt_uuid CHAR(36) NULL,
  exercise_group_key VARCHAR(128) NULL,
  operational_session_uuid CHAR(36) NOT NULL,
  independence_state ENUM('ASSISTED','PROMPTED','INDEPENDENT','NOT_OBSERVED') NOT NULL,
  system_suggested_state ENUM('ASSISTED','PROMPTED','INDEPENDENT') NULL,
  source ENUM('INSTRUCTOR_ONE_TAP','INSTRUCTOR_GROUP_CONFIRM','DEFAULT','SYSTEM_SUGGESTION') NOT NULL,
  confirmed_by_user_id BIGINT NULL,
  confirmed_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
);

CREATE TABLE ipca_instructor_interventions (
  intervention_uuid CHAR(36) NOT NULL PRIMARY KEY,
  operational_session_uuid CHAR(36) NOT NULL,
  attempt_uuid CHAR(36) NULL,
  event_type VARCHAR(64) NOT NULL,
  event_utc DATETIME(3) NULL,
  source ENUM('INSTRUCTOR','AI_PROPOSAL','SYSTEM') NOT NULL,
  confirmation_status ENUM('UNCONFIRMED','CONFIRMED','REJECTED') NOT NULL DEFAULT 'UNCONFIRMED',
  evidence_json JSON NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
);

CREATE TABLE ipca_competency_assessments (
  assessment_uuid CHAR(36) NOT NULL PRIMARY KEY,
  operational_session_uuid CHAR(36) NOT NULL,
  attempt_uuid CHAR(36) NULL,
  assessment_version INT NOT NULL,
  evidence_cutoff_utc DATETIME(3) NOT NULL,
  tolerance_pack_version VARCHAR(64) NOT NULL,
  procedure_pack_version VARCHAR(64) NULL,
  ai_model_prompt_version VARCHAR(128) NULL,
  payload_json JSON NOT NULL,
  evidence_completeness ENUM('FULL_EVIDENCE','PARTIAL_EVIDENCE','LIMITED_EVIDENCE') NOT NULL,
  superseded_by CHAR(36) NULL,
  generated_at DATETIME(3) NOT NULL,
  UNIQUE KEY uq_assessment_version (attempt_uuid, assessment_version)
);

CREATE TABLE ipca_debriefs (
  debrief_uuid CHAR(36) NOT NULL PRIMARY KEY,
  operational_session_uuid CHAR(36) NOT NULL,
  assessment_uuid CHAR(36) NOT NULL,
  audience ENUM('INSTRUCTOR','STUDENT') NOT NULL,
  status ENUM('SHADOW','PROPOSED','INSTRUCTOR_REVIEWED','APPROVED','SUPERSEDED') NOT NULL,
  structured_json JSON NOT NULL,
  confirmed_by_user_id BIGINT NULL,
  confirmed_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
);

CREATE TABLE ipca_debrief_claims (
  claim_uuid CHAR(36) NOT NULL PRIMARY KEY,
  debrief_uuid CHAR(36) NOT NULL,
  claim_text TEXT NOT NULL,
  assessment_source VARCHAR(64) NOT NULL,
  confidence VARCHAR(32) NULL,
  evidence_completeness VARCHAR(32) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
);

CREATE TABLE ipca_debrief_claim_evidence (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  claim_uuid CHAR(36) NOT NULL,
  evidence_ref VARCHAR(191) NOT NULL,
  evidence_kind VARCHAR(64) NOT NULL
);

CREATE TABLE ipca_competency_state_history (
  state_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  student_user_id BIGINT NOT NULL,
  program_id BIGINT NULL,
  canonical_exercise_code VARCHAR(64) NOT NULL,
  as_of_utc DATETIME(3) NOT NULL,
  expected_level VARCHAR(8) NULL,
  independence_state VARCHAR(32) NULL,
  objective_summary_json JSON NULL,
  consistency_state VARCHAR(32) NULL,
  context_summary VARCHAR(255) NULL,
  trend VARCHAR(32) NULL,
  evidence_completeness VARCHAR(32) NULL,
  source_assessment_uuid CHAR(36) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
);
*/

-- Explicit non-goals for production:
-- - No opaque student risk score table
-- - No automatic mission reschedule from AI
-- - No silent overwrite of assessment versions
-- - No student access to examiner calibration aggregates
