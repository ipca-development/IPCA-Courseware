-- Add avionics header fields for COM/NAV/XPDR/AFCS replay display.
-- Safe to re-run.

DROP PROCEDURE IF EXISTS ipca_add_replay_avionics_column_if_missing;

DELIMITER //
CREATE PROCEDURE ipca_add_replay_avionics_column_if_missing(
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

CALL ipca_add_replay_avionics_column_if_missing('com1_standby_mhz', 'DECIMAL(6,3) NULL');
CALL ipca_add_replay_avionics_column_if_missing('com2_standby_mhz', 'DECIMAL(6,3) NULL');
CALL ipca_add_replay_avionics_column_if_missing('nav2_standby_mhz', 'DECIMAL(6,3) NULL');
CALL ipca_add_replay_avionics_column_if_missing('fd_lateral_mode', 'VARCHAR(32) NULL');
CALL ipca_add_replay_avionics_column_if_missing('autopilot_armed_mode', 'VARCHAR(32) NULL');
CALL ipca_add_replay_avionics_column_if_missing('com1_name', 'VARCHAR(64) NULL');
CALL ipca_add_replay_avionics_column_if_missing('com1_standby_name', 'VARCHAR(64) NULL');
CALL ipca_add_replay_avionics_column_if_missing('com2_name', 'VARCHAR(64) NULL');
CALL ipca_add_replay_avionics_column_if_missing('com2_standby_name', 'VARCHAR(64) NULL');
CALL ipca_add_replay_avionics_column_if_missing('nav2_name', 'VARCHAR(64) NULL');
CALL ipca_add_replay_avionics_column_if_missing('nav2_standby_name', 'VARCHAR(64) NULL');

DROP PROCEDURE IF EXISTS ipca_add_replay_avionics_column_if_missing;
