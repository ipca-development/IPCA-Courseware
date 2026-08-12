-- Ephemeral, admin-controlled live cockpit monitoring.
-- The canonical evidence recorder remains independent and authoritative.
-- Additive and re-run safe.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET collation_connection = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_cvr_monitor_broadcasts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  broadcast_uuid CHAR(36) NOT NULL,
  dispatch_id BIGINT UNSIGNED NOT NULL,
  dispatch_uuid CHAR(36) NOT NULL,
  workflow_flight_record_uuid CHAR(36) NOT NULL,
  operational_session_uuid CHAR(36) NOT NULL,
  device_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'active',
  started_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  last_listener_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  ended_at_utc DATETIME(3) NULL,
  end_reason VARCHAR(64) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_cvr_monitor_broadcast_uuid (broadcast_uuid),
  KEY idx_cvr_monitor_broadcast_device (device_id, status, last_listener_at_utc),
  KEY idx_cvr_monitor_broadcast_session (operational_session_uuid, status),
  CONSTRAINT fk_cvr_monitor_broadcast_dispatch
    FOREIGN KEY (dispatch_id) REFERENCES ipca_cvr_dispatches(id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_cvr_monitor_broadcast_device
    FOREIGN KEY (device_id) REFERENCES ipca_cvr_devices(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_cvr_monitor_listener_leases (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lease_uuid CHAR(36) NOT NULL,
  client_uuid CHAR(36) NOT NULL,
  broadcast_id BIGINT UNSIGNED NOT NULL,
  staff_user_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'active',
  started_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  heartbeat_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  expires_at_utc DATETIME(3) NOT NULL,
  stopped_at_utc DATETIME(3) NULL,
  stop_reason VARCHAR(64) NULL,
  reconnect_count INT UNSIGNED NOT NULL DEFAULT 0,
  audit_metadata_json JSON NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_cvr_monitor_listener_lease_uuid (lease_uuid),
  KEY idx_cvr_monitor_listener_active (broadcast_id, status, expires_at_utc),
  KEY idx_cvr_monitor_listener_user (staff_user_id, status, heartbeat_at_utc),
  CONSTRAINT fk_cvr_monitor_listener_broadcast
    FOREIGN KEY (broadcast_id) REFERENCES ipca_cvr_monitor_broadcasts(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_cvr_monitor_chunks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  chunk_uuid CHAR(36) NOT NULL,
  broadcast_id BIGINT UNSIGNED NOT NULL,
  sequence_number BIGINT UNSIGNED NOT NULL,
  started_at_utc DATETIME(3) NOT NULL,
  duration_seconds DECIMAL(8,3) NOT NULL,
  storage_path VARCHAR(512) NOT NULL,
  sha256 CHAR(64) NOT NULL,
  file_size_bytes BIGINT UNSIGNED NOT NULL,
  uploaded_by_device_id BIGINT UNSIGNED NOT NULL,
  received_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  expires_at_utc DATETIME(3) NOT NULL,
  purged_at_utc DATETIME(3) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_cvr_monitor_chunk_uuid (chunk_uuid),
  UNIQUE KEY uk_cvr_monitor_chunk_sequence (broadcast_id, sequence_number),
  KEY idx_cvr_monitor_chunk_manifest (broadcast_id, received_at_utc),
  KEY idx_cvr_monitor_chunk_expiry (expires_at_utc, purged_at_utc),
  CONSTRAINT fk_cvr_monitor_chunk_broadcast
    FOREIGN KEY (broadcast_id) REFERENCES ipca_cvr_monitor_broadcasts(id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_cvr_monitor_chunk_device
    FOREIGN KEY (uploaded_by_device_id) REFERENCES ipca_cvr_devices(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
