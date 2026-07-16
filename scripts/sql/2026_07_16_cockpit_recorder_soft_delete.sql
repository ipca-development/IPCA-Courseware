-- Soft delete support for cockpit recorder admin cleanup.

SET @table_name := 'ipca_cockpit_recordings';

SET @after_clause := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = @table_name
        AND COLUMN_NAME = 'updated_at'
    ),
    ' AFTER updated_at',
    ''
  )
);

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = @table_name
        AND COLUMN_NAME = 'deleted_at'
    ),
    'SELECT 1',
    CONCAT('ALTER TABLE ipca_cockpit_recordings ADD COLUMN deleted_at DATETIME NULL', @after_clause)
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
        AND COLUMN_NAME = 'deleted_by_user_id'
    ),
    'SELECT 1',
    'ALTER TABLE ipca_cockpit_recordings ADD COLUMN deleted_by_user_id BIGINT UNSIGNED NULL AFTER deleted_at'
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
        AND COLUMN_NAME = 'delete_reason'
    ),
    'SELECT 1',
    'ALTER TABLE ipca_cockpit_recordings ADD COLUMN delete_reason VARCHAR(255) NOT NULL DEFAULT '''' AFTER deleted_by_user_id'
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @index_exists := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = @table_name
    AND INDEX_NAME = 'idx_cockpit_recordings_deleted'
);

SET @sql := IF(
  @index_exists > 0,
  'SELECT 1',
  'CREATE INDEX idx_cockpit_recordings_deleted ON ipca_cockpit_recordings (deleted_at, started_at, id)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
