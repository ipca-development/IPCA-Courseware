-- Rebuildable airport endpoint derivation for Garmin Sync files labeled Flight.
-- Airport matches come from the seeded ipca_airports coordinate catalog.

CREATE TABLE IF NOT EXISTS ipca_garmin_sync_file_airport_analyses (
  id                         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  archive_file_id            BIGINT UNSIGNED NOT NULL,
  departure_airport_code     VARCHAR(8) NULL,
  departure_airport_name     VARCHAR(255) NULL,
  departure_distance_nm      DECIMAL(10,3) NULL,
  arrival_airport_code       VARCHAR(8) NULL,
  arrival_airport_name       VARCHAR(255) NULL,
  arrival_distance_nm        DECIMAL(10,3) NULL,
  derivation_status          VARCHAR(16) NOT NULL,
  confidence                 DECIMAL(4,3) NOT NULL DEFAULT 0,
  analysis_reason            VARCHAR(512) NOT NULL DEFAULT '',
  evidence_json              JSON NULL,
  analyzer_version           VARCHAR(32) NOT NULL,
  analyzed_at                DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_at                 DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at                 DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_garmin_sync_airports_archive (archive_file_id),
  KEY idx_ipca_garmin_sync_airports_departure (departure_airport_code),
  KEY idx_ipca_garmin_sync_airports_arrival (arrival_airport_code),
  CONSTRAINT fk_ipca_garmin_sync_airports_archive
    FOREIGN KEY (archive_file_id) REFERENCES ipca_garmin_sync_archive_files(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Non-operational departure and arrival airport evidence derived from Garmin endpoints and seeded airport coordinates.';
