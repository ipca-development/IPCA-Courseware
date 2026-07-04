-- Promote additional Garmin G3X replay fields for avionics, alerts, AP/FD, slip/skid, and G-load.
-- Safe to re-run. Columns are appended without AFTER dependencies so older schemas do not fail.

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

CALL ipca_add_replay_column_if_missing('heading_bug_deg', 'DECIMAL(7,2) NULL');
CALL ipca_add_replay_column_if_missing('hcdi_full_scale_ft', 'DECIMAL(10,2) NULL');
CALL ipca_add_replay_column_if_missing('hcdi_scale', 'DECIMAL(10,3) NULL');
CALL ipca_add_replay_column_if_missing('vcdi', 'DECIMAL(10,3) NULL');
CALL ipca_add_replay_column_if_missing('vcdi_full_scale_ft', 'DECIMAL(10,2) NULL');
CALL ipca_add_replay_column_if_missing('vnav_cdi', 'DECIMAL(10,3) NULL');
CALL ipca_add_replay_column_if_missing('vnav_altitude_ft', 'DECIMAL(10,1) NULL');
CALL ipca_add_replay_column_if_missing('nav_distance_nm', 'DECIMAL(10,3) NULL');
CALL ipca_add_replay_column_if_missing('sel_vspeed_fpm', 'DECIMAL(10,1) NULL');
CALL ipca_add_replay_column_if_missing('sel_ias_kt', 'DECIMAL(10,2) NULL');
CALL ipca_add_replay_column_if_missing('density_altitude_ft', 'DECIMAL(10,1) NULL');
CALL ipca_add_replay_column_if_missing('height_agl_ft', 'DECIMAL(10,1) NULL');
CALL ipca_add_replay_column_if_missing('wind_speed_kt', 'DECIMAL(10,2) NULL');
CALL ipca_add_replay_column_if_missing('wind_direction_deg', 'DECIMAL(7,2) NULL');
CALL ipca_add_replay_column_if_missing('elevator_trim_pct', 'DECIMAL(8,3) NULL');
CALL ipca_add_replay_column_if_missing('fd_roll_command_deg', 'DECIMAL(8,3) NULL');
CALL ipca_add_replay_column_if_missing('fd_pitch_command_deg', 'DECIMAL(8,3) NULL');
CALL ipca_add_replay_column_if_missing('fd_altitude_ft', 'DECIMAL(10,1) NULL');
CALL ipca_add_replay_column_if_missing('ap_roll_command_deg', 'DECIMAL(8,3) NULL');
CALL ipca_add_replay_column_if_missing('ap_pitch_command_deg', 'DECIMAL(8,3) NULL');
CALL ipca_add_replay_column_if_missing('ap_vs_command_fpm', 'DECIMAL(10,1) NULL');
CALL ipca_add_replay_column_if_missing('ap_altitude_command_ft', 'DECIMAL(10,1) NULL');
CALL ipca_add_replay_column_if_missing('ap_roll_torque_pct', 'DECIMAL(8,3) NULL');
CALL ipca_add_replay_column_if_missing('ap_pitch_torque_pct', 'DECIMAL(8,3) NULL');
CALL ipca_add_replay_column_if_missing('com1_mhz', 'DECIMAL(8,3) NULL');
CALL ipca_add_replay_column_if_missing('com2_mhz', 'DECIMAL(8,3) NULL');
CALL ipca_add_replay_column_if_missing('nav2_mhz', 'DECIMAL(8,3) NULL');
CALL ipca_add_replay_column_if_missing('lateral_acceleration_g', 'DECIMAL(8,4) NULL');
CALL ipca_add_replay_column_if_missing('normal_acceleration_g', 'DECIMAL(8,4) NULL');
CALL ipca_add_replay_column_if_missing('acceleration_g', 'DECIMAL(8,4) NULL');
CALL ipca_add_replay_column_if_missing('estimated_slip_skid_g', 'DECIMAL(8,4) NULL');
CALL ipca_add_replay_column_if_missing('slip_skid_g', 'DECIMAL(8,4) NULL');
CALL ipca_add_replay_column_if_missing('cas_alert', 'VARCHAR(255) NULL');
CALL ipca_add_replay_column_if_missing('terrain_alert', 'VARCHAR(255) NULL');
CALL ipca_add_replay_column_if_missing('transponder_code', 'VARCHAR(16) NULL');
CALL ipca_add_replay_column_if_missing('transponder_mode', 'VARCHAR(32) NULL');
CALL ipca_add_replay_column_if_missing('nav_source', 'VARCHAR(32) NULL');
CALL ipca_add_replay_column_if_missing('nav_annunciation', 'VARCHAR(64) NULL');
CALL ipca_add_replay_column_if_missing('nav_identifier', 'VARCHAR(64) NULL');
CALL ipca_add_replay_column_if_missing('autopilot_state', 'VARCHAR(64) NULL');
CALL ipca_add_replay_column_if_missing('fd_vertical_mode', 'VARCHAR(64) NULL');

DROP PROCEDURE IF EXISTS ipca_add_replay_column_if_missing;
