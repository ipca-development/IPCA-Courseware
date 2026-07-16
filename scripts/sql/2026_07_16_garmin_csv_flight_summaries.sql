CREATE TABLE IF NOT EXISTS ipca_garmin_csv_flight_summaries (
  csv_file_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  derivation_status VARCHAR(32) NOT NULL DEFAULT 'pending',
  tail_number VARCHAR(32) NOT NULL DEFAULT '',
  departure_airport_code VARCHAR(16) NULL,
  arrival_airport_code VARCHAR(16) NULL,
  departure_time_utc DATETIME(3) NULL,
  arrival_time_utc DATETIME(3) NULL,
  elapsed_seconds INT UNSIGNED NULL,
  hobbs_start_utc DATETIME(3) NULL,
  hobbs_end_utc DATETIME(3) NULL,
  hobbs_duration_seconds INT UNSIGNED NULL,
  hobbs_status VARCHAR(32) NOT NULL DEFAULT '',
  row_count INT UNSIGNED NOT NULL DEFAULT 0,
  calculation_version VARCHAR(64) NOT NULL DEFAULT '',
  display_label VARCHAR(255) NOT NULL DEFAULT '',
  summary_json JSON NULL,
  exception_json JSON NULL,
  derived_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  KEY idx_garmin_csv_summary_departure (departure_time_utc),
  KEY idx_garmin_csv_summary_tail (tail_number),
  CONSTRAINT fk_garmin_csv_summary_file
    FOREIGN KEY (csv_file_id) REFERENCES ipca_garmin_csv_files(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cached operational flight summary derived from Garmin CSV headers and rows.';

CREATE TABLE IF NOT EXISTS ipca_garmin_track_flight_summaries (
  track_artifact_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  derivation_status VARCHAR(32) NOT NULL DEFAULT 'pending',
  tail_number VARCHAR(32) NOT NULL DEFAULT '',
  departure_airport_code VARCHAR(16) NULL,
  arrival_airport_code VARCHAR(16) NULL,
  departure_time_utc DATETIME(3) NULL,
  arrival_time_utc DATETIME(3) NULL,
  elapsed_seconds INT UNSIGNED NULL,
  hobbs_start_utc DATETIME(3) NULL,
  hobbs_end_utc DATETIME(3) NULL,
  hobbs_duration_seconds INT UNSIGNED NULL,
  hobbs_status VARCHAR(32) NOT NULL DEFAULT '',
  row_count INT UNSIGNED NOT NULL DEFAULT 0,
  calculation_version VARCHAR(64) NOT NULL DEFAULT '',
  display_label VARCHAR(255) NOT NULL DEFAULT '',
  summary_json JSON NULL,
  exception_json JSON NULL,
  derived_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  KEY idx_garmin_track_summary_departure (departure_time_utc),
  KEY idx_garmin_track_summary_tail (tail_number),
  CONSTRAINT fk_garmin_track_summary_artifact
    FOREIGN KEY (track_artifact_id) REFERENCES ipca_garmin_normalized_track_artifacts(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cached operational flight summary derived from normalized Garmin track JSON.';
