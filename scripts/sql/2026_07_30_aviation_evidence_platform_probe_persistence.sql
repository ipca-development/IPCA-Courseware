-- Supplemental migration: Phase 0 probe persistence columns (2026-07-30)
-- Safe to re-run: guarded by information_schema checks.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ipca_evidence_provider_runs extended columns
SET @tbl := 'ipca_evidence_provider_runs';

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = 'probe_execution_uuid');
SET @sql := IF(@col = 0, 'ALTER TABLE ipca_evidence_provider_runs ADD COLUMN probe_execution_uuid CHAR(36) NULL AFTER provider_run_uuid', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = 'probe_label');
SET @sql := IF(@col = 0, 'ALTER TABLE ipca_evidence_provider_runs ADD COLUMN probe_label VARCHAR(64) NULL AFTER probe_execution_uuid', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = 'matching_response_provider_run_id');
SET @sql := IF(@col = 0, 'ALTER TABLE ipca_evidence_provider_runs ADD COLUMN matching_response_provider_run_id BIGINT UNSIGNED NULL AFTER parent_provider_run_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = 'provider_reported_model');
SET @sql := IF(@col = 0, 'ALTER TABLE ipca_evidence_provider_runs ADD COLUMN provider_reported_model VARCHAR(64) NULL AFTER model', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = 'prompt_hash');
SET @sql := IF(@col = 0, 'ALTER TABLE ipca_evidence_provider_runs ADD COLUMN prompt_hash CHAR(64) NULL AFTER request_config_json', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = 'success_status');
SET @sql := IF(@col = 0, 'ALTER TABLE ipca_evidence_provider_runs ADD COLUMN success_status VARCHAR(32) NOT NULL DEFAULT ''unknown'' AFTER http_status', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = 'error_type');
SET @sql := IF(@col = 0, 'ALTER TABLE ipca_evidence_provider_runs ADD COLUMN error_type VARCHAR(64) NULL AFTER success_status', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = 'error_message');
SET @sql := IF(@col = 0, 'ALTER TABLE ipca_evidence_provider_runs ADD COLUMN error_message TEXT NULL AFTER error_type', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = 'returned_text');
SET @sql := IF(@col = 0, 'ALTER TABLE ipca_evidence_provider_runs ADD COLUMN returned_text LONGTEXT NULL AFTER response_sha256', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = 'source_audio_sha256');
SET @sql := IF(@col = 0, 'ALTER TABLE ipca_evidence_provider_runs ADD COLUMN source_audio_sha256 CHAR(64) NULL AFTER returned_text', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = 'chunk_audio_sha256');
SET @sql := IF(@col = 0, 'ALTER TABLE ipca_evidence_provider_runs ADD COLUMN chunk_audio_sha256 CHAR(64) NULL AFTER source_audio_sha256', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = 'chunk_start_time_ms');
SET @sql := IF(@col = 0, 'ALTER TABLE ipca_evidence_provider_runs ADD COLUMN chunk_start_time_ms INT UNSIGNED NULL AFTER chunk_audio_sha256', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = 'chunk_duration_ms');
SET @sql := IF(@col = 0, 'ALTER TABLE ipca_evidence_provider_runs ADD COLUMN chunk_duration_ms INT UNSIGNED NULL AFTER chunk_start_time_ms', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = 'request_started_at');
SET @sql := IF(@col = 0, 'ALTER TABLE ipca_evidence_provider_runs ADD COLUMN request_started_at DATETIME(3) NULL AFTER transcription_duration_ms', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = 'request_completed_at');
SET @sql := IF(@col = 0, 'ALTER TABLE ipca_evidence_provider_runs ADD COLUMN request_completed_at DATETIME(3) NULL AFTER request_started_at', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = 'latency_ms');
SET @sql := IF(@col = 0, 'ALTER TABLE ipca_evidence_provider_runs ADD COLUMN latency_ms INT UNSIGNED NULL AFTER request_completed_at', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = 'capability_observations_json');
SET @sql := IF(@col = 0, 'ALTER TABLE ipca_evidence_provider_runs ADD COLUMN capability_observations_json JSON NULL AFTER usage_json', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = 'evidence_files_json');
SET @sql := IF(@col = 0, 'ALTER TABLE ipca_evidence_provider_runs ADD COLUMN evidence_files_json JSON NULL AFTER capability_observations_json', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = 'code_version');
SET @sql := IF(@col = 0, 'ALTER TABLE ipca_evidence_provider_runs ADD COLUMN code_version VARCHAR(64) NULL AFTER evidence_files_json', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Indexes (ignore if exist)
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND INDEX_NAME = 'idx_ipca_evidence_provider_runs_probe_exec');
SET @sql := IF(@idx = 0, 'ALTER TABLE ipca_evidence_provider_runs ADD KEY idx_ipca_evidence_provider_runs_probe_exec (probe_execution_uuid, probe_label)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND INDEX_NAME = 'idx_ipca_evidence_provider_runs_response_hash');
SET @sql := IF(@idx = 0, 'ALTER TABLE ipca_evidence_provider_runs ADD KEY idx_ipca_evidence_provider_runs_response_hash (response_sha256)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND INDEX_NAME = 'uk_ipca_evidence_provider_runs_openai_req');
SET @sql := IF(@idx = 0, 'ALTER TABLE ipca_evidence_provider_runs ADD UNIQUE KEY uk_ipca_evidence_provider_runs_openai_req (openai_request_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND CONSTRAINT_NAME = 'fk_ipca_evidence_provider_runs_matching');
SET @sql := IF(@fk = 0, 'ALTER TABLE ipca_evidence_provider_runs ADD CONSTRAINT fk_ipca_evidence_provider_runs_matching FOREIGN KEY (matching_response_provider_run_id) REFERENCES ipca_evidence_provider_runs(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Observations table (if base migration skipped it)
CREATE TABLE IF NOT EXISTS ipca_evidence_provider_observations (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  provider_run_id   BIGINT UNSIGNED NOT NULL,
  observation_key   VARCHAR(128) NOT NULL,
  observation_type  VARCHAR(32) NOT NULL DEFAULT 'string',
  value_boolean     TINYINT(1) NULL,
  value_integer     BIGINT NULL,
  value_string      VARCHAR(512) NULL,
  value_json        JSON NULL,
  created_at        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_evidence_provider_obs (provider_run_id, observation_key),
  KEY idx_ipca_evidence_provider_obs_key (observation_key),
  CONSTRAINT fk_ipca_evidence_provider_obs_run
    FOREIGN KEY (provider_run_id) REFERENCES ipca_evidence_provider_runs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ipca_evidence_schema_versions (version, migration_file, notes)
VALUES ('2026.07.30.1', 'scripts/sql/2026_07_30_aviation_evidence_platform_probe_persistence.sql', 'Phase 0 probe persistence columns')
ON DUPLICATE KEY UPDATE applied_at = CURRENT_TIMESTAMP(3), notes = VALUES(notes);
