CREATE TABLE IF NOT EXISTS ipca_garmin_normalized_track_artifacts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  provider_name VARCHAR(64) NOT NULL,
  garmin_entry_uuid CHAR(36) NOT NULL,
  track_uuid CHAR(36) NOT NULL,
  artifact_type VARCHAR(64) NOT NULL,
  storage_path VARCHAR(1024) NOT NULL,
  sha256 CHAR(64) NOT NULL,
  file_size_bytes BIGINT UNSIGNED NOT NULL,
  content_type VARCHAR(128) NOT NULL,
  format_version INT NULL,
  session_count INT NOT NULL DEFAULT 0,
  field_count INT NOT NULL DEFAULT 0,
  source_descriptors_json JSON NULL,
  raw_metadata_json JSON NULL,
  first_seen_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  last_seen_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_garmin_track_artifacts (provider_name, garmin_entry_uuid, track_uuid, sha256),
  KEY idx_ipca_garmin_track_artifacts_entry (provider_name, garmin_entry_uuid),
  KEY idx_ipca_garmin_track_artifacts_track (track_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Additive normalized Garmin track JSON artifacts from sync-agent.';
