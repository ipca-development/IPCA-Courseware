-- Add GPS velocity components used by the flight path vector.
-- Safe to re-run.

DROP PROCEDURE IF EXISTS ipca_add_replay_velocity_column_if_missing;

DELIMITER //
CREATE PROCEDURE ipca_add_replay_velocity_column_if_missing(
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

CALL ipca_add_replay_velocity_column_if_missing('velocity_e_mps', 'DECIMAL(10,3) NULL');
CALL ipca_add_replay_velocity_column_if_missing('velocity_n_mps', 'DECIMAL(10,3) NULL');
CALL ipca_add_replay_velocity_column_if_missing('velocity_u_mps', 'DECIMAL(10,3) NULL');

DROP PROCEDURE IF EXISTS ipca_add_replay_velocity_column_if_missing;
