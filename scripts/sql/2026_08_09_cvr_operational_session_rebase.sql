-- Stage 1: canonical Operational Session identity.
-- Reuses ipca_flight_sessions; does not change legacy flight_session_uid semantics.
-- Additive and idempotent for MySQL 8. All rollout flags default off.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET collation_connection = utf8mb4_unicode_ci;

SET @table_name := 'ipca_flight_sessions';

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@table_name AND COLUMN_NAME='reservation_uuid');
SET @sql := IF(@col_exists=0, 'ALTER TABLE ipca_flight_sessions ADD COLUMN reservation_uuid CHAR(36) NULL AFTER session_uuid', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@table_name AND COLUMN_NAME='dispatch_uuid');
SET @sql := IF(@col_exists=0, 'ALTER TABLE ipca_flight_sessions ADD COLUMN dispatch_uuid CHAR(36) NULL AFTER reservation_uuid', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@table_name AND COLUMN_NAME='workflow_flight_record_uuid');
SET @sql := IF(@col_exists=0, 'ALTER TABLE ipca_flight_sessions ADD COLUMN workflow_flight_record_uuid CHAR(36) NULL AFTER dispatch_uuid', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@table_name AND COLUMN_NAME='model_version');
SET @sql := IF(@col_exists=0, 'ALTER TABLE ipca_flight_sessions ADD COLUMN model_version VARCHAR(32) NOT NULL DEFAULT ''legacy_evidence_v1'' AFTER source', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@table_name AND COLUMN_NAME='dispatch_confirmed_at_utc');
SET @sql := IF(@col_exists=0, 'ALTER TABLE ipca_flight_sessions ADD COLUMN dispatch_confirmed_at_utc DATETIME(3) NULL AFTER status', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@table_name AND COLUMN_NAME='starting_hobbs');
SET @sql := IF(@col_exists=0, 'ALTER TABLE ipca_flight_sessions ADD COLUMN starting_hobbs DECIMAL(12,4) NULL AFTER engine_stop_utc', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@table_name AND COLUMN_NAME='starting_tacho');
SET @sql := IF(@col_exists=0, 'ALTER TABLE ipca_flight_sessions ADD COLUMN starting_tacho DECIMAL(12,4) NULL AFTER starting_hobbs', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@table_name AND COLUMN_NAME='starting_fuel_quantity');
SET @sql := IF(@col_exists=0, 'ALTER TABLE ipca_flight_sessions ADD COLUMN starting_fuel_quantity DECIMAL(10,3) NULL AFTER starting_tacho', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@table_name AND COLUMN_NAME='starting_fuel_unit');
SET @sql := IF(@col_exists=0, 'ALTER TABLE ipca_flight_sessions ADD COLUMN starting_fuel_unit VARCHAR(16) NULL AFTER starting_fuel_quantity', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@table_name AND COLUMN_NAME='starting_oil_quantity');
SET @sql := IF(@col_exists=0, 'ALTER TABLE ipca_flight_sessions ADD COLUMN starting_oil_quantity DECIMAL(10,3) NULL AFTER starting_fuel_unit', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@table_name AND COLUMN_NAME='starting_oil_unit');
SET @sql := IF(@col_exists=0, 'ALTER TABLE ipca_flight_sessions ADD COLUMN starting_oil_unit VARCHAR(16) NULL AFTER starting_oil_quantity', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Duplicate audit completed before this migration: the new association columns did
-- not yet exist. Stage 1 intentionally uses non-unique indexes until populated data
-- can be audited; identity equivalence is enforced transactionally in service code.
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@table_name AND INDEX_NAME='idx_ipca_flight_sessions_reservation');
SET @sql := IF(@idx_exists=0, 'CREATE INDEX idx_ipca_flight_sessions_reservation ON ipca_flight_sessions (reservation_uuid, created_at)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@table_name AND INDEX_NAME='idx_ipca_flight_sessions_dispatch_uuid');
SET @sql := IF(@idx_exists=0, 'CREATE INDEX idx_ipca_flight_sessions_dispatch_uuid ON ipca_flight_sessions (dispatch_uuid)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@table_name AND INDEX_NAME='idx_ipca_flight_sessions_workflow_uuid');
SET @sql := IF(@idx_exists=0, 'CREATE INDEX idx_ipca_flight_sessions_workflow_uuid ON ipca_flight_sessions (workflow_flight_record_uuid)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_name := 'ipca_cvr_dispatches';
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@table_name AND COLUMN_NAME='operational_session_uuid');
SET @sql := IF(@col_exists=0, 'ALTER TABLE ipca_cvr_dispatches ADD COLUMN operational_session_uuid CHAR(36) NULL AFTER workflow_flight_record_uuid', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@table_name AND INDEX_NAME='idx_ipca_cvr_dispatches_operational_session');
SET @sql := IF(@idx_exists=0, 'CREATE INDEX idx_ipca_cvr_dispatches_operational_session ON ipca_cvr_dispatches (operational_session_uuid)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_name := 'ipca_cvr_workflow_evidence_batches';
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@table_name AND COLUMN_NAME='operational_session_uuid');
SET @sql := IF(@col_exists=0, 'ALTER TABLE ipca_cvr_workflow_evidence_batches ADD COLUMN operational_session_uuid CHAR(36) NULL AFTER workflow_flight_record_uuid', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_name := 'ipca_cvr_flight_events';
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@table_name AND COLUMN_NAME='operational_session_uuid');
SET @sql := IF(@col_exists=0, 'ALTER TABLE ipca_cvr_flight_events ADD COLUMN operational_session_uuid CHAR(36) NULL AFTER workflow_flight_record_uuid', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_name := 'ipca_cvr_recorder_verifications';
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@table_name AND COLUMN_NAME='operational_session_uuid');
SET @sql := IF(@col_exists=0, 'ALTER TABLE ipca_cvr_recorder_verifications ADD COLUMN operational_session_uuid CHAR(36) NULL AFTER workflow_flight_record_uuid', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_name := 'ipca_cvr_flight_closures';
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@table_name AND COLUMN_NAME='operational_session_uuid');
SET @sql := IF(@col_exists=0, 'ALTER TABLE ipca_cvr_flight_closures ADD COLUMN operational_session_uuid CHAR(36) NULL AFTER workflow_flight_record_uuid', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_name := 'ipca_cockpit_recordings';
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@table_name AND COLUMN_NAME='operational_session_uuid');
SET @sql := IF(@col_exists=0, 'ALTER TABLE ipca_cockpit_recordings ADD COLUMN operational_session_uuid CHAR(36) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @policy_defs_exist := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='system_policy_definitions');
SET @sql := IF(@policy_defs_exist > 0,
  'INSERT INTO system_policy_definitions
     (policy_key, category, value_type, default_value_text, allowed_values_json,
      validation_rules_json, description_text, is_admin_editable, sort_order)
   VALUES
     (''operational_session_model_enabled'', ''cvr_operational_identity'', ''bool'', ''0'', ''[\"0\",\"1\"]'', NULL,
      ''Stage 1: enable reservation-linked Operational Sessions for allowlisted CVR units.'', 1, 9250),
     (''operational_session_model_device_allowlist'', ''cvr_operational_identity'', ''string'', '''', NULL, NULL,
      ''Comma-separated CVR unit identifiers, optionally scoped as UNIT@TAIL, allowed to start the Stage 1 Operational Session model.'', 1, 9260),
     (''gps_leg_derivation_enabled'', ''cvr_operational_identity'', ''bool'', ''0'', ''[\"0\",\"1\"]'', NULL,
      ''Stage 3 placeholder. Disabled until GPS-derived leg contracts are approved.'', 1, 9270),
     (''final_checkin_derived_legs_enabled'', ''cvr_operational_identity'', ''bool'', ''0'', ''[\"0\",\"1\"]'', NULL,
      ''Stage 5 placeholder. Disabled until derived-leg Final Check-In is approved.'', 1, 9280)
   ON DUPLICATE KEY UPDATE
     category=VALUES(category), value_type=VALUES(value_type),
     default_value_text=VALUES(default_value_text), allowed_values_json=VALUES(allowed_values_json),
     description_text=VALUES(description_text), is_admin_editable=VALUES(is_admin_editable),
     sort_order=VALUES(sort_order)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
