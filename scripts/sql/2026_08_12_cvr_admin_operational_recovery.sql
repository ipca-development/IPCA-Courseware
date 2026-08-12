-- Administrative recovery for evidence-bearing accidental Dispatches and
-- provenance for online Operational Session leg verification.
-- Additive and re-run safe.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET collation_connection = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_cvr_dispatch_release_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  release_uuid CHAR(36) NOT NULL,
  dispatch_id BIGINT UNSIGNED NOT NULL,
  dispatch_uuid CHAR(36) NOT NULL,
  scheduler_record_id CHAR(36) NULL,
  workflow_flight_record_uuid CHAR(36) NULL,
  operational_session_uuid CHAR(36) NULL,
  release_mode VARCHAR(32) NOT NULL,
  reason_code VARCHAR(64) NOT NULL,
  reason_text VARCHAR(512) NULL,
  evidence_summary_json JSON NOT NULL,
  actor_type VARCHAR(32) NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  released_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uk_ipca_cvr_dispatch_release_uuid (release_uuid),
  KEY idx_ipca_cvr_dispatch_release_dispatch (dispatch_id, released_at_utc),
  KEY idx_ipca_cvr_dispatch_release_session (operational_session_uuid),
  CONSTRAINT fk_ipca_cvr_dispatch_release_dispatch
    FOREIGN KEY (dispatch_id) REFERENCES ipca_cvr_dispatches(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Immutable reasons and evidence classification for Dispatch release.';

SET @table_name := 'ipca_operational_session_leg_reviews';

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = @table_name
    AND COLUMN_NAME = 'reviewed_by_user_id'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE ipca_operational_session_leg_reviews ADD COLUMN reviewed_by_user_id BIGINT UNSIGNED NULL AFTER reviewed_by_device_id',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = @table_name
    AND COLUMN_NAME = 'review_source'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE ipca_operational_session_leg_reviews ADD COLUMN review_source VARCHAR(32) NOT NULL DEFAULT ''cvr_device'' AFTER reviewed_by_user_id',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
