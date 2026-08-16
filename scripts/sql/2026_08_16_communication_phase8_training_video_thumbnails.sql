-- IPCA Communication Phase 8 — Media Library and auto-generated training-video thumbnails.
-- Additive and safe to re-run. Photographs stay private and are only issued through presigned GET URLs.

CREATE TABLE IF NOT EXISTS ipca_training_media_library (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  asset_uuid            CHAR(36) NOT NULL,
  storage_key           VARCHAR(512) NOT NULL,
  original_filename     VARCHAR(255) NOT NULL DEFAULT '',
  mime_type             VARCHAR(128) NOT NULL DEFAULT 'image/jpeg',
  byte_size             BIGINT UNSIGNED NOT NULL DEFAULT 0,
  width                 INT UNSIGNED NOT NULL DEFAULT 0,
  height                INT UNSIGNED NOT NULL DEFAULT 0,
  orientation           VARCHAR(16) NOT NULL DEFAULT 'landscape',
  analysis_json         JSON NULL,
  analysis_text         TEXT NULL,
  analysis_status       VARCHAR(32) NOT NULL DEFAULT 'pending',
  created_by_user_id    BIGINT UNSIGNED NOT NULL,
  created_at_utc        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  deleted_at_utc        DATETIME(3) NULL,
  UNIQUE KEY uk_training_media_uuid (asset_uuid),
  UNIQUE KEY uk_training_media_storage (storage_key),
  KEY idx_training_media_orientation (orientation, deleted_at_utc, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
