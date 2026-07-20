-- Cockpit replay alert catalog and per-sample alert/trim metadata.
-- Safe to re-run. Columns are appended without AFTER dependencies so older schemas do not fail.

CREATE TABLE IF NOT EXISTS ipca_garmin_alert_catalog (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  aircraft_type VARCHAR(64) NOT NULL DEFAULT '',
  source_column VARCHAR(32) NOT NULL DEFAULT '',
  alert_key VARCHAR(191) NOT NULL,
  display_text VARCHAR(255) NOT NULL DEFAULT '',
  severity ENUM('warning','caution','info') NOT NULL DEFAULT 'info',
  observation_count INT UNSIGNED NOT NULL DEFAULT 0,
  first_seen_at DATETIME NULL,
  last_seen_at DATETIME NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_ipca_garmin_alert_catalog (aircraft_type, source_column, alert_key),
  KEY idx_ipca_garmin_alert_catalog_aircraft (aircraft_type, severity),
  KEY idx_ipca_garmin_alert_catalog_key (alert_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS ipca_add_replay_column_if_missing;

DELIMITER //
CREATE PROCEDURE ipca_add_replay_column_if_missing(
  IN p_column_name VARCHAR(64),
  IN p_column_definition TEXT
)
BEGIN
  SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'ipca_cockpit_replay_samples'
      AND COLUMN_NAME = p_column_name
  );

  IF @col_exists = 0 THEN
    SET @sql := CONCAT('ALTER TABLE ipca_cockpit_replay_samples ADD COLUMN ', p_column_name, ' ', p_column_definition);
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
  END IF;
END//
DELIMITER ;

CALL ipca_add_replay_column_if_missing('system_alerts_json', 'JSON NULL');
CALL ipca_add_replay_column_if_missing('trim_range_json', 'JSON NULL');

DROP PROCEDURE IF EXISTS ipca_add_replay_column_if_missing;
