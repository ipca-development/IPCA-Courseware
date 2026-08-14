-- IPCA Communication Phase 2 — APNs device environment + push attempt idempotency.
-- Additive. Does not change Phase 1 message/sync tables.
-- Re-run safe.

INSERT IGNORE INTO ipca_communication_app_config (config_key, config_value) VALUES
  ('push_enabled', '1');

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE ipca_communication_devices ADD COLUMN apns_environment VARCHAR(16) NULL AFTER push_authorized',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_communication_devices'
    AND COLUMN_NAME = 'apns_environment'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE ipca_communication_push_attempts ADD UNIQUE KEY uk_comm_push_msg_device (message_id, device_id)',
    'SELECT 1'
  )
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_communication_push_attempts'
    AND INDEX_NAME = 'uk_comm_push_msg_device'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
