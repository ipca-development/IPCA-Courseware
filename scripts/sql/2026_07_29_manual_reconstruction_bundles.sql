-- FlightCircle-free immutable manual evidence bundles for CVR reconstruction.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET collation_connection = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_manual_intake_bundles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  bundle_uuid CHAR(36) NOT NULL,
  version_number INT UNSIGNED NOT NULL DEFAULT 1,
  supersedes_bundle_id BIGINT UNSIGNED NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'draft',
  dispatch_id BIGINT UNSIGNED NOT NULL,
  cockpit_recording_id BIGINT UNSIGNED NOT NULL,
  garmin_csv_file_id BIGINT UNSIGNED NOT NULL,
  adsb_enrichment_id BIGINT UNSIGNED NULL,
  workflow_flight_record_uuid CHAR(36) NOT NULL,
  aircraft_registration VARCHAR(32) NOT NULL,
  mission_code VARCHAR(64) NOT NULL DEFAULT '',
  manifest_sha256 CHAR(64) NULL,
  manifest_json JSON NULL,
  validation_warnings_json JSON NULL,
  transcript_snapshot_id BIGINT UNSIGNED NULL,
  operational_flight_record_version_id BIGINT UNSIGNED NULL,
  reconstruction_job_id BIGINT UNSIGNED NULL,
  replay_status VARCHAR(32) NOT NULL DEFAULT 'not_started',
  processing_error TEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  frozen_at DATETIME(3) NULL,
  started_at DATETIME(3) NULL,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_manual_intake_bundles_uuid (bundle_uuid),
  UNIQUE KEY uk_ipca_manual_intake_bundles_version (workflow_flight_record_uuid, version_number),
  KEY idx_ipca_manual_intake_bundles_status (status, updated_at),
  KEY idx_ipca_manual_intake_bundles_sources (dispatch_id, cockpit_recording_id, garmin_csv_file_id),
  CONSTRAINT fk_ipca_manual_intake_bundles_supersedes
    FOREIGN KEY (supersedes_bundle_id) REFERENCES ipca_manual_intake_bundles(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_manual_intake_bundle_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  bundle_id BIGINT UNSIGNED NOT NULL,
  source_type VARCHAR(32) NOT NULL,
  source_table VARCHAR(64) NOT NULL,
  source_id BIGINT UNSIGNED NOT NULL,
  source_uuid VARCHAR(128) NOT NULL DEFAULT '',
  source_sha256 CHAR(64) NOT NULL,
  metadata_snapshot_json JSON NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_manual_intake_bundle_item_type (bundle_id, source_type),
  KEY idx_ipca_manual_intake_bundle_item_source (source_table, source_id),
  CONSTRAINT fk_ipca_manual_intake_bundle_items_bundle
    FOREIGN KEY (bundle_id) REFERENCES ipca_manual_intake_bundles(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_cockpit_transcript_snapshots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  snapshot_uuid CHAR(36) NOT NULL,
  cockpit_recording_id BIGINT UNSIGNED NOT NULL,
  transcript_sha256 CHAR(64) NOT NULL,
  transcript_text LONGTEXT NOT NULL,
  chunks_manifest_json JSON NOT NULL,
  source_status VARCHAR(32) NOT NULL,
  word_count INT UNSIGNED NOT NULL DEFAULT 0,
  locked_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  locked_by BIGINT UNSIGNED NULL,
  UNIQUE KEY uk_ipca_cockpit_transcript_snapshots_uuid (snapshot_uuid),
  UNIQUE KEY uk_ipca_cockpit_transcript_snapshots_hash (cockpit_recording_id, transcript_sha256),
  KEY idx_ipca_cockpit_transcript_snapshots_recording (cockpit_recording_id, locked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_manual_intake_bundle_audit (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_uuid CHAR(36) NOT NULL,
  bundle_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(64) NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  detail_json JSON NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_manual_intake_bundle_audit_uuid (event_uuid),
  KEY idx_ipca_manual_intake_bundle_audit_bundle (bundle_id, created_at),
  CONSTRAINT fk_ipca_manual_intake_bundle_audit_bundle
    FOREIGN KEY (bundle_id) REFERENCES ipca_manual_intake_bundles(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
