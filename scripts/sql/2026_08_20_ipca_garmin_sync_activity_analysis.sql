-- Rebuildable Power-up versus Flight evidence for classified Garmin flight CSVs.
-- This remains isolated from operational flight records and does not mutate archives.

CREATE TABLE IF NOT EXISTS ipca_garmin_sync_file_activity_analyses (
  id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  archive_file_id         BIGINT UNSIGNED NOT NULL,
  activity_kind           VARCHAR(16) NOT NULL,
  duration_seconds        INT UNSIGNED NOT NULL DEFAULT 0,
  sample_count            INT UNSIGNED NOT NULL DEFAULT 0,
  engine_sample_count     INT UNSIGNED NOT NULL DEFAULT 0,
  airborne_sample_count   INT UNSIGNED NOT NULL DEFAULT 0,
  maximum_rpm             DECIMAL(10,2) NULL,
  maximum_ground_speed_kt DECIMAL(10,2) NULL,
  maximum_airspeed_kt     DECIMAL(10,2) NULL,
  maximum_position_radius_nm DECIMAL(10,3) NULL,
  analysis_reason         VARCHAR(512) NOT NULL DEFAULT '',
  evidence_json           JSON NULL,
  analyzer_version        VARCHAR(32) NOT NULL,
  analyzed_at             DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_at              DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at              DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_garmin_sync_activity_archive (archive_file_id),
  KEY idx_ipca_garmin_sync_activity_kind (activity_kind, analyzed_at),
  CONSTRAINT fk_ipca_garmin_sync_activity_archive
    FOREIGN KEY (archive_file_id) REFERENCES ipca_garmin_sync_archive_files(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Non-operational telemetry evidence distinguishing Power-up logs from files showing sustained flight.';
