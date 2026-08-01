-- Scheduled Dispatch Start/End backend foundation.
-- Additive and idempotent for MySQL 8.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET collation_connection = utf8mb4_unicode_ci;

SET @table_name := 'ipca_aircraft_operational_config_versions';
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name AND COLUMN_NAME = 'fuel_capacity');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE ipca_aircraft_operational_config_versions ADD COLUMN fuel_capacity DECIMAL(10,3) NOT NULL DEFAULT 13.000 AFTER fuel_discrepancy_usg', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name AND COLUMN_NAME = 'fuel_unit');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE ipca_aircraft_operational_config_versions ADD COLUMN fuel_unit VARCHAR(16) NOT NULL DEFAULT ''USG'' AFTER fuel_capacity', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name AND COLUMN_NAME = 'oil_capacity');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE ipca_aircraft_operational_config_versions ADD COLUMN oil_capacity DECIMAL(10,3) NOT NULL DEFAULT 100.000 AFTER fuel_unit', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name AND COLUMN_NAME = 'oil_unit');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE ipca_aircraft_operational_config_versions ADD COLUMN oil_unit VARCHAR(16) NOT NULL DEFAULT ''%'' AFTER oil_capacity', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS ipca_flight_schedule_slots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  scheduler_record_id CHAR(36) NOT NULL,
  organization_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
  scheduled_date DATE NOT NULL,
  scheduled_start_time DATETIME(3) NOT NULL,
  scheduled_end_time DATETIME(3) NOT NULL,
  aircraft_id BIGINT UNSIGNED NOT NULL,
  mission_id BIGINT UNSIGNED NULL,
  mission_code VARCHAR(64) NOT NULL DEFAULT '',
  planned_departure_airport VARCHAR(8) NOT NULL DEFAULT '',
  planned_destination_airport VARCHAR(8) NOT NULL DEFAULT '',
  status VARCHAR(32) NOT NULL DEFAULT 'scheduled',
  claimed_dispatch_uuid CHAR(36) NULL,
  claimed_at DATETIME(3) NULL,
  notes VARCHAR(1000) NOT NULL DEFAULT '',
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_flight_schedule_slots_record (scheduler_record_id),
  UNIQUE KEY uk_ipca_flight_schedule_slots_claim (claimed_dispatch_uuid),
  KEY idx_ipca_flight_schedule_slots_date_aircraft (scheduled_date, aircraft_id, scheduled_start_time),
  KEY idx_ipca_flight_schedule_slots_status (status, scheduled_start_time),
  CONSTRAINT fk_ipca_flight_schedule_slots_aircraft FOREIGN KEY (aircraft_id)
    REFERENCES ipca_aircraft_devices(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_ipca_flight_schedule_slots_mission FOREIGN KEY (mission_id)
    REFERENCES ipca_missions(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT chk_ipca_flight_schedule_slots_times CHECK (scheduled_end_time > scheduled_start_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Dated aircraft schedule slots available for CVR dispatch claim.';

CREATE TABLE IF NOT EXISTS ipca_flight_schedule_crew (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  schedule_slot_id BIGINT UNSIGNED NOT NULL,
  user_id INT NULL,
  person_name_snapshot VARCHAR(255) NOT NULL,
  crew_role VARCHAR(64) NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_flight_schedule_crew_member (schedule_slot_id, user_id, crew_role),
  KEY idx_ipca_flight_schedule_crew_user (user_id, schedule_slot_id),
  CONSTRAINT fk_ipca_flight_schedule_crew_slot FOREIGN KEY (schedule_slot_id)
    REFERENCES ipca_flight_schedule_slots(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Relational crew assignments for scheduled flight slots.';

SET @table_name := 'ipca_cvr_dispatches';
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name AND COLUMN_NAME = 'scheduler_record_id');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE ipca_cvr_dispatches ADD COLUMN scheduler_record_id CHAR(36) NULL AFTER workflow_flight_record_uuid', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name AND COLUMN_NAME = 'oil_quantity');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE ipca_cvr_dispatches ADD COLUMN oil_quantity DECIMAL(10,3) NULL AFTER oil_percentage', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name AND COLUMN_NAME = 'oil_unit');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE ipca_cvr_dispatches ADD COLUMN oil_unit VARCHAR(16) NULL AFTER oil_quantity', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name AND INDEX_NAME = 'uk_ipca_cvr_dispatches_scheduler');
SET @sql := IF(@idx_exists = 0, 'CREATE UNIQUE INDEX uk_ipca_cvr_dispatches_scheduler ON ipca_cvr_dispatches (scheduler_record_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_name := 'ipca_cvr_flight_closures';
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name AND COLUMN_NAME = 'oil_quantity');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE ipca_cvr_flight_closures ADD COLUMN oil_quantity DECIMAL(10,3) NULL AFTER oil_percentage', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name AND COLUMN_NAME = 'oil_unit');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE ipca_cvr_flight_closures ADD COLUMN oil_unit VARCHAR(16) NULL AFTER oil_quantity', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Apply the known N446CS fluid profile only when that aircraft exists. Existing
-- operational thresholds remain unchanged.
INSERT INTO ipca_aircraft_operational_configs (aircraft_id)
SELECT a.id FROM ipca_aircraft_devices a
WHERE UPPER(REPLACE(REPLACE(a.registration, '-', ''), ' ', '')) = 'N446CS'
ON DUPLICATE KEY UPDATE aircraft_id = VALUES(aircraft_id);

INSERT INTO ipca_aircraft_operational_config_versions
  (config_id, config_version_uuid, version_number, effective_from_utc,
   hobbs_engine_on_rpm_threshold, hobbs_start_confirm_ms, hobbs_stop_confirm_ms,
   tacho_rpm_threshold, movement_groundspeed_kt, movement_confirm_ms,
   fuel_discrepancy_usg, fuel_capacity, fuel_unit, oil_capacity, oil_unit,
   oil_blocking_threshold_percent, timezone_identifier, config_json, change_reason)
SELECT c.id, UUID(),
       (SELECT COALESCE(MAX(vn.version_number), 0) + 1
        FROM ipca_aircraft_operational_config_versions vn WHERE vn.config_id = c.id),
       CURRENT_TIMESTAMP(3),
       COALESCE(cv.hobbs_engine_on_rpm_threshold, 1000.000),
       COALESCE(cv.hobbs_start_confirm_ms, 1000),
       COALESCE(cv.hobbs_stop_confirm_ms, 5000),
       cv.tacho_rpm_threshold,
       COALESCE(cv.movement_groundspeed_kt, 3.000),
       COALESCE(cv.movement_confirm_ms, 3000),
       COALESCE(cv.fuel_discrepancy_usg, 1.000),
       53.000, 'USG', 8.000, 'qt',
       cv.oil_blocking_threshold_percent,
       COALESCE(cv.timezone_identifier, 'America/Los_Angeles'),
       cv.config_json,
       'Seed N446CS scheduled dispatch fluid profile'
FROM ipca_aircraft_operational_configs c
INNER JOIN ipca_aircraft_devices a ON a.id = c.aircraft_id
LEFT JOIN ipca_aircraft_operational_config_versions cv ON cv.id = c.current_version_id
WHERE UPPER(REPLACE(REPLACE(a.registration, '-', ''), ' ', '')) = 'N446CS'
  AND NOT EXISTS (
    SELECT 1 FROM ipca_aircraft_operational_config_versions v
    WHERE v.config_id = c.id
      AND v.fuel_capacity = 53.000 AND v.fuel_unit = 'USG'
      AND v.oil_capacity = 8.000 AND v.oil_unit = 'qt'
  );

UPDATE ipca_aircraft_operational_config_versions v
INNER JOIN ipca_aircraft_operational_configs c ON c.id = v.config_id
INNER JOIN ipca_aircraft_devices a ON a.id = c.aircraft_id
SET v.effective_to_utc = COALESCE(v.effective_to_utc, CURRENT_TIMESTAMP(3))
WHERE UPPER(REPLACE(REPLACE(a.registration, '-', ''), ' ', '')) = 'N446CS'
  AND v.id = c.current_version_id
  AND NOT (v.fuel_capacity = 53.000 AND v.fuel_unit = 'USG'
           AND v.oil_capacity = 8.000 AND v.oil_unit = 'qt');

UPDATE ipca_aircraft_operational_configs c
INNER JOIN ipca_aircraft_devices a ON a.id = c.aircraft_id
SET c.current_version_id = (
  SELECT v.id FROM ipca_aircraft_operational_config_versions v
  WHERE v.config_id = c.id
    AND v.fuel_capacity = 53.000 AND v.fuel_unit = 'USG'
    AND v.oil_capacity = 8.000 AND v.oil_unit = 'qt'
  ORDER BY v.version_number DESC LIMIT 1
)
WHERE UPPER(REPLACE(REPLACE(a.registration, '-', ''), ' ', '')) = 'N446CS'
  AND EXISTS (
    SELECT 1 FROM ipca_aircraft_operational_config_versions v
    WHERE v.config_id = c.id
      AND v.fuel_capacity = 53.000 AND v.fuel_unit = 'USG'
      AND v.oil_capacity = 8.000 AND v.oil_unit = 'qt'
  );
