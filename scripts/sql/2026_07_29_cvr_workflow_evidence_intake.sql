-- Immutable CVR workflow evidence: events, recorder verification, and flight closure.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET collation_connection = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_cvr_workflow_evidence_batches (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  batch_uuid CHAR(36) NOT NULL,
  component_uuid VARCHAR(128) NOT NULL,
  workflow_flight_record_uuid CHAR(36) NOT NULL,
  dispatch_uuid CHAR(36) NOT NULL,
  device_id BIGINT UNSIGNED NOT NULL,
  component_type VARCHAR(64) NOT NULL,
  payload_sha256 CHAR(64) NOT NULL,
  payload_json JSON NOT NULL,
  receipt_uuid CHAR(36) NOT NULL,
  received_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_cvr_workflow_evidence_component (component_uuid),
  UNIQUE KEY uk_ipca_cvr_workflow_evidence_receipt (receipt_uuid),
  KEY idx_ipca_cvr_workflow_evidence_flight (workflow_flight_record_uuid, component_type),
  KEY idx_ipca_cvr_workflow_evidence_device (device_id, received_at),
  CONSTRAINT fk_ipca_cvr_workflow_evidence_device
    FOREIGN KEY (device_id) REFERENCES ipca_cvr_devices(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_cvr_flight_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_uuid CHAR(36) NOT NULL,
  batch_id BIGINT UNSIGNED NOT NULL,
  workflow_flight_record_uuid CHAR(36) NOT NULL,
  recording_session_uuid VARCHAR(96) NULL,
  event_type VARCHAR(96) NOT NULL,
  timestamp_utc DATETIME(3) NOT NULL,
  timestamp_local DATETIME(3) NOT NULL,
  device_monotonic_time DECIMAL(16,6) NULL,
  audio_offset_seconds DECIMAL(14,3) NULL,
  latitude DECIMAL(11,8) NULL,
  longitude DECIMAL(11,8) NULL,
  altitude DECIMAL(12,3) NULL,
  ground_speed DECIMAL(10,3) NULL,
  source VARCHAR(64) NOT NULL DEFAULT '',
  confidence DECIMAL(5,4) NOT NULL DEFAULT 1.0000,
  creation_method VARCHAR(64) NOT NULL DEFAULT '',
  user_identity VARCHAR(128) NULL,
  payload_sha256 CHAR(64) NOT NULL,
  payload_json JSON NOT NULL,
  received_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_cvr_flight_events_uuid (event_uuid),
  KEY idx_ipca_cvr_flight_events_flight_time (workflow_flight_record_uuid, timestamp_utc),
  KEY idx_ipca_cvr_flight_events_type (event_type, timestamp_utc),
  CONSTRAINT fk_ipca_cvr_flight_events_batch
    FOREIGN KEY (batch_id) REFERENCES ipca_cvr_workflow_evidence_batches(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_cvr_recorder_verifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  verification_uuid CHAR(36) NOT NULL,
  batch_id BIGINT UNSIGNED NOT NULL,
  workflow_flight_record_uuid CHAR(36) NOT NULL,
  dispatch_uuid CHAR(36) NOT NULL,
  verified_at DATETIME(3) NOT NULL,
  payload_sha256 CHAR(64) NOT NULL,
  payload_json JSON NOT NULL,
  received_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_cvr_recorder_verifications_uuid (verification_uuid),
  KEY idx_ipca_cvr_recorder_verifications_flight (workflow_flight_record_uuid),
  CONSTRAINT fk_ipca_cvr_recorder_verifications_batch
    FOREIGN KEY (batch_id) REFERENCES ipca_cvr_workflow_evidence_batches(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_cvr_flight_closures (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  closure_uuid CHAR(36) NOT NULL,
  batch_id BIGINT UNSIGNED NOT NULL,
  workflow_flight_record_uuid CHAR(36) NOT NULL,
  ending_hobbs DECIMAL(12,4) NULL,
  ending_tacho DECIMAL(12,4) NULL,
  fuel_remaining VARCHAR(64) NULL,
  oil_percentage TINYINT UNSIGNED NULL,
  maintenance_remark TEXT NULL,
  payload_sha256 CHAR(64) NOT NULL,
  payload_json JSON NOT NULL,
  received_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_cvr_flight_closures_uuid (closure_uuid),
  KEY idx_ipca_cvr_flight_closures_flight (workflow_flight_record_uuid, received_at),
  CONSTRAINT fk_ipca_cvr_flight_closures_batch
    FOREIGN KEY (batch_id) REFERENCES ipca_cvr_workflow_evidence_batches(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
