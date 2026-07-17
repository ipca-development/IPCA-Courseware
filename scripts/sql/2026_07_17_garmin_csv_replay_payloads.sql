CREATE TABLE IF NOT EXISTS ipca_garmin_csv_replay_payloads (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  garmin_csv_file_id BIGINT UNSIGNED NOT NULL,
  csv_sha256 CHAR(64) NOT NULL,
  builder_version VARCHAR(64) NOT NULL,
  replay_key VARCHAR(160) NOT NULL DEFAULT '',
  payload_storage_path VARCHAR(512) NOT NULL DEFAULT '',
  sample_count INT UNSIGNED NOT NULL DEFAULT 0,
  build_status VARCHAR(32) NOT NULL DEFAULT 'pending',
  last_error TEXT NULL,
  built_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_garmin_csv_replay_current (garmin_csv_file_id, csv_sha256, builder_version),
  KEY idx_garmin_csv_replay_status (build_status, built_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
