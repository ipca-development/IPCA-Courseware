-- IPCA Cockpit Recorder - replay v2 AOA fields.
-- Safe to re-run. Adds Garmin AOA columns when missing.

DROP PROCEDURE IF EXISTS ipca_add_replay_aoa_column_if_missing;

DELIMITER //
CREATE PROCEDURE ipca_add_replay_aoa_column_if_missing(
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

CALL ipca_add_replay_aoa_column_if_missing('aoa', 'DECIMAL(10,4) NULL');
CALL ipca_add_replay_aoa_column_if_missing('aoa_cp', 'DECIMAL(10,4) NULL');

DROP PROCEDURE IF EXISTS ipca_add_replay_aoa_column_if_missing;
