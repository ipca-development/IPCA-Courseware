-- Dedicated Garmin Sync device enrollment and bearer credentials.
-- Additive only: this migration does not alter archive/upload data or reference CVR tables.

CREATE TABLE IF NOT EXISTS ipca_garmin_sync_devices (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  device_uuid        CHAR(36) NOT NULL,
  organization_id    BIGINT UNSIGNED NOT NULL,
  display_name       VARCHAR(128) NOT NULL DEFAULT '',
  active             TINYINT(1) NOT NULL DEFAULT 1,
  revoked_at         DATETIME(3) NULL,
  last_seen_at       DATETIME(3) NULL,
  created_at         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_garmin_sync_devices_uuid (device_uuid),
  KEY idx_ipca_garmin_sync_devices_org (organization_id, active, revoked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Devices authorized only for the IPCA Garmin Sync API.';

CREATE TABLE IF NOT EXISTS ipca_garmin_sync_device_enrollments (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  enrollment_uuid    CHAR(36) NOT NULL,
  organization_id    BIGINT UNSIGNED NOT NULL,
  code_hash          CHAR(64) NOT NULL,
  status             ENUM('pending','consumed','revoked') NOT NULL DEFAULT 'pending',
  expires_at         DATETIME(3) NOT NULL,
  consumed_at        DATETIME(3) NULL,
  revoked_at         DATETIME(3) NULL,
  created_by         BIGINT UNSIGNED NOT NULL,
  created_at         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_garmin_sync_enrollment_uuid (enrollment_uuid),
  UNIQUE KEY uk_ipca_garmin_sync_enrollment_hash (code_hash),
  KEY idx_ipca_garmin_sync_enrollment_state (organization_id, status, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='One-time Garmin Sync enrollment codes; only SHA-256 hashes are retained.';

CREATE TABLE IF NOT EXISTS ipca_garmin_sync_device_credentials (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  credential_uuid    CHAR(36) NOT NULL,
  device_id          BIGINT UNSIGNED NOT NULL,
  token_hash         CHAR(64) NOT NULL,
  expires_at         DATETIME(3) NULL,
  revoked_at         DATETIME(3) NULL,
  last_used_at       DATETIME(3) NULL,
  created_at         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_garmin_sync_credential_uuid (credential_uuid),
  UNIQUE KEY uk_ipca_garmin_sync_credential_hash (token_hash),
  KEY idx_ipca_garmin_sync_credential_device (device_id, revoked_at, expires_at),
  CONSTRAINT fk_ipca_garmin_sync_credential_device
    FOREIGN KEY (device_id) REFERENCES ipca_garmin_sync_devices(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Hashed bearer credentials dedicated to Garmin Sync devices.';
