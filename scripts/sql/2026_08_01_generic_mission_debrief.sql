-- Allow an evidence-led generic debrief when a scheduled mission has no canonical version.
SET @mission_version_nullable := (
  SELECT IS_NULLABLE
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_structured_debriefs'
    AND COLUMN_NAME = 'mission_version_id'
  LIMIT 1
);

SET @sql := IF(
  @mission_version_nullable = 'NO',
  'ALTER TABLE ipca_structured_debriefs MODIFY COLUMN mission_version_id BIGINT UNSIGNED NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
