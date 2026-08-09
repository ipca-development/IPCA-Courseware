-- Stage 1: immutable Duty Assignment snapshot and explicit pilot functions.
-- Additive, re-run safe, and feature-gated. No historical backfill.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET collation_connection = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_operational_reservation_duties (
  reservation_uuid CHAR(36) NOT NULL,
  organization_id BIGINT UNSIGNED NOT NULL,
  contract_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  fingerprint_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  duty_fingerprint_sha256 CHAR(64) NOT NULL,
  primary_customer_identity_key VARCHAR(128) NOT NULL,
  aircraft_device_id BIGINT UNSIGNED NOT NULL,
  aircraft_registration_snapshot VARCHAR(32) NOT NULL,
  reservation_type VARCHAR(32) NOT NULL,
  activity_domain VARCHAR(32) NOT NULL,
  training_assignment_category VARCHAR(32) NOT NULL,
  mission_id BIGINT UNSIGNED NULL,
  mission_code_snapshot VARCHAR(64) NOT NULL DEFAULT '',
  duty_snapshot_json JSON NOT NULL,
  source VARCHAR(32) NOT NULL,
  created_by_user_id BIGINT UNSIGNED NULL,
  created_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (reservation_uuid),
  KEY idx_op_duty_org_fingerprint (organization_id, duty_fingerprint_sha256),
  KEY idx_op_duty_customer (organization_id, primary_customer_identity_key),
  KEY idx_op_duty_aircraft (organization_id, aircraft_device_id),
  KEY idx_op_duty_mission (organization_id, mission_id),
  CONSTRAINT fk_op_duty_reservation FOREIGN KEY (reservation_uuid)
    REFERENCES ipca_operational_reservations(reservation_uuid)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_op_duty_uuid_lower CHECK (
    reservation_uuid = LOWER(reservation_uuid) AND CHAR_LENGTH(reservation_uuid) = 36
  ),
  CONSTRAINT chk_op_duty_hash_lower CHECK (
    duty_fingerprint_sha256 = LOWER(duty_fingerprint_sha256)
    AND CHAR_LENGTH(duty_fingerprint_sha256) = 64
  ),
  CONSTRAINT chk_op_duty_versions CHECK (
    contract_version >= 1 AND fingerprint_version >= 1
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Immutable one-to-one Duty Assignment snapshot for each canonical reservation.';

CREATE TABLE IF NOT EXISTS ipca_operational_reservation_duty_participants (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reservation_uuid CHAR(36) NOT NULL,
  organization_id BIGINT UNSIGNED NOT NULL,
  person_identity_key VARCHAR(128) NOT NULL,
  person_user_id BIGINT UNSIGNED NULL,
  external_person_uuid CHAR(36) NULL,
  person_name_snapshot VARCHAR(255) NOT NULL,
  participant_role VARCHAR(32) NOT NULL,
  pilot_function VARCHAR(8) NOT NULL DEFAULT 'NONE',
  is_pic TINYINT(1) NOT NULL DEFAULT 0,
  is_primary_customer TINYINT(1) NOT NULL DEFAULT 0,
  is_accountable TINYINT(1) NOT NULL DEFAULT 1,
  sequence_number INT UNSIGNED NOT NULL,
  created_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uk_op_duty_participant (
    reservation_uuid, person_identity_key, participant_role, pilot_function
  ),
  KEY idx_op_duty_participant_person (organization_id, person_identity_key),
  CONSTRAINT fk_op_duty_participant_reservation FOREIGN KEY (reservation_uuid)
    REFERENCES ipca_operational_reservations(reservation_uuid)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_op_duty_participant_pilot CHECK (
    pilot_function IN ('NONE','PF','PM')
  ),
  CONSTRAINT chk_op_duty_participant_identity CHECK (
    CHAR_LENGTH(TRIM(person_identity_key)) > 0
    AND (person_user_id IS NOT NULL OR external_person_uuid IS NOT NULL)
  ),
  CONSTRAINT chk_op_duty_participant_sequence CHECK (sequence_number >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Normalized immutable participant and pilot-function snapshot for a Duty Assignment.';

SET @has_pilot_function := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_flight_schedule_crew'
    AND COLUMN_NAME = 'pilot_function'
);
SET @sql := IF(@has_pilot_function = 0,
  'ALTER TABLE ipca_flight_schedule_crew ADD COLUMN pilot_function VARCHAR(8) NOT NULL DEFAULT ''NONE'' AFTER crew_role',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_is_pic := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_flight_schedule_crew'
    AND COLUMN_NAME = 'is_pic'
);
SET @sql := IF(@has_is_pic = 0,
  'ALTER TABLE ipca_flight_schedule_crew ADD COLUMN is_pic TINYINT(1) NOT NULL DEFAULT 0 AFTER pilot_function',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_pilot_check := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_flight_schedule_crew'
    AND CONSTRAINT_NAME = 'chk_flight_schedule_crew_pilot_function'
);
SET @sql := IF(@has_pilot_check = 0,
  'ALTER TABLE ipca_flight_schedule_crew ADD CONSTRAINT chk_flight_schedule_crew_pilot_function CHECK (pilot_function IN (''NONE'',''PF'',''PM''))',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @policy_defs_exist := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'system_policy_definitions'
);
SET @sql := IF(@policy_defs_exist > 0,
  'INSERT INTO system_policy_definitions
     (policy_key, category, value_type, default_value_text, allowed_values_json,
      validation_rules_json, description_text, is_admin_editable, sort_order)
   VALUES
     (''duty_assignment_snapshot_write_enabled'', ''cvr_operational_identity'', ''bool'', ''0'', ''[\"0\",\"1\"]'', NULL,
      ''Stage 1: write immutable Duty Assignment snapshots for new canonical reservations.'', 1, 9230),
     (''duty_assignment_enforcement_enabled'', ''cvr_operational_identity'', ''bool'', ''0'', ''[\"0\",\"1\"]'', NULL,
      ''Stage 1: reject material Duty Assignment identity mismatches and in-place mutation.'', 1, 9240)
   ON DUPLICATE KEY UPDATE
     category=VALUES(category), value_type=VALUES(value_type),
     default_value_text=VALUES(default_value_text),
     allowed_values_json=VALUES(allowed_values_json),
     description_text=VALUES(description_text),
     is_admin_editable=VALUES(is_admin_editable), sort_order=VALUES(sort_order)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
