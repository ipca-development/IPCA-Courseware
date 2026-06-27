-- G3X Flight Stream CSV upload support for cockpit recorder.

SET @table_name := 'ipca_cockpit_recordings';

SET @after_clause := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = @table_name
        AND COLUMN_NAME = 'gps_sample_count'
    ),
    ' AFTER gps_sample_count',
    ''
  )
);

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = @table_name
        AND COLUMN_NAME = 'g3x_storage_path'
    ),
    'SELECT 1',
    CONCAT('ALTER TABLE ipca_cockpit_recordings ADD COLUMN g3x_storage_path VARCHAR(1024) NULL', @after_clause)
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = @table_name
        AND COLUMN_NAME = 'g3x_file_size_bytes'
    ),
    'SELECT 1',
    'ALTER TABLE ipca_cockpit_recordings ADD COLUMN g3x_file_size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER g3x_storage_path'
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = @table_name
        AND COLUMN_NAME = 'g3x_row_count'
    ),
    'SELECT 1',
    'ALTER TABLE ipca_cockpit_recordings ADD COLUMN g3x_row_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER g3x_file_size_bytes'
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = @table_name
        AND COLUMN_NAME = 'g3x_aircraft_ident'
    ),
    'SELECT 1',
    'ALTER TABLE ipca_cockpit_recordings ADD COLUMN g3x_aircraft_ident VARCHAR(32) NOT NULL DEFAULT '''' AFTER g3x_row_count'
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = @table_name
        AND COLUMN_NAME = 'g3x_imported_at'
    ),
    'SELECT 1',
    'ALTER TABLE ipca_cockpit_recordings ADD COLUMN g3x_imported_at DATETIME NULL AFTER g3x_aircraft_ident'
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = @table_name
        AND COLUMN_NAME = 'g3x_time_offset_seconds'
    ),
    'SELECT 1',
    'ALTER TABLE ipca_cockpit_recordings ADD COLUMN g3x_time_offset_seconds DOUBLE NULL AFTER g3x_imported_at'
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
