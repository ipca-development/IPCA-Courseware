-- Phase 1 IPCA Garmin Sync: isolated, append-only binary intake.
-- This migration intentionally does not alter or reference Garmin/CVR evidence tables.

CREATE TABLE IF NOT EXISTS ipca_garmin_sync_archive_files (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  object_uuid           CHAR(36) NOT NULL,
  sha256                CHAR(64) NOT NULL,
  byte_count            BIGINT UNSIGNED NOT NULL,
  storage_path          VARCHAR(1024) NOT NULL,
  original_filename     VARCHAR(512) NOT NULL DEFAULT '',
  creator_organization_id BIGINT UNSIGNED NOT NULL,
  creator_device_id     BIGINT UNSIGNED NOT NULL,
  verified_at           DATETIME(3) NOT NULL,
  created_at            DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_garmin_sync_archive_object (object_uuid),
  UNIQUE KEY uk_ipca_garmin_sync_archive_sha256 (sha256),
  UNIQUE KEY uk_ipca_garmin_sync_archive_path (storage_path),
  KEY idx_ipca_garmin_sync_archive_creator (creator_organization_id, creator_device_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Immutable Garmin Sync binaries. SHA-256 is deliberately global content identity: one historical binary has one object; tenant access is granted only through scoped upload receipts.';

CREATE TABLE IF NOT EXISTS ipca_garmin_sync_upload_sessions (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  upload_uuid           CHAR(36) NOT NULL,
  request_uuid          VARCHAR(128) NOT NULL,
  organization_id       BIGINT UNSIGNED NOT NULL,
  device_id             BIGINT UNSIGNED NOT NULL,
  expected_sha256       CHAR(64) NOT NULL,
  expected_byte_count   BIGINT UNSIGNED NOT NULL,
  total_chunks          INT UNSIGNED NOT NULL,
  received_chunks_json  JSON NULL,
  received_byte_count   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  original_filename     VARCHAR(512) NOT NULL DEFAULT '',
  status                VARCHAR(32) NOT NULL DEFAULT 'receiving',
  retry_count           INT UNSIGNED NOT NULL DEFAULT 0,
  last_error_code       VARCHAR(64) NULL,
  last_error_message    TEXT NULL,
  last_error_retryable  TINYINT(1) NULL,
  archive_file_id       BIGINT UNSIGNED NULL,
  receipt_uuid          CHAR(36) NULL,
  receipt_json          JSON NULL,
  finalized_at          DATETIME(3) NULL,
  created_at            DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at            DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_garmin_sync_upload_uuid (upload_uuid),
  UNIQUE KEY uk_ipca_garmin_sync_request_owner (organization_id, device_id, request_uuid),
  KEY idx_ipca_garmin_sync_upload_owner (organization_id, device_id, status, updated_at),
  KEY idx_ipca_garmin_sync_upload_hash (organization_id, expected_sha256),
  KEY idx_ipca_garmin_sync_upload_archive (archive_file_id),
  CONSTRAINT fk_ipca_garmin_sync_upload_archive
    FOREIGN KEY (archive_file_id) REFERENCES ipca_garmin_sync_archive_files(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Resumable Garmin Sync upload requests and immutable verification receipts, scoped to their owning organization and device.';

CREATE TABLE IF NOT EXISTS ipca_garmin_sync_upload_chunks (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  upload_session_id     BIGINT UNSIGNED NOT NULL,
  chunk_index           INT UNSIGNED NOT NULL,
  byte_count            BIGINT UNSIGNED NOT NULL,
  chunk_sha256          CHAR(64) NOT NULL,
  storage_name          VARCHAR(64) NOT NULL,
  received_at           DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_at            DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_garmin_sync_chunk (upload_session_id, chunk_index),
  CONSTRAINT fk_ipca_garmin_sync_chunk_session
    FOREIGN KEY (upload_session_id) REFERENCES ipca_garmin_sync_upload_sessions(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Historical received-chunk state; transient session files may be removed only after a verified receipt is durable.';
