-- ADS-B traffic archive for KTRM safety coverage, replay traffic, and hazard review.

CREATE TABLE IF NOT EXISTS ipca_adsb_coverage_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  job_uuid CHAR(36) NOT NULL,
  organization_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
  scope VARCHAR(32) NOT NULL DEFAULT 'ktrm_baseline',
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  center_latitude DECIMAL(11,7) NOT NULL,
  center_longitude DECIMAL(11,7) NOT NULL,
  radius_nm DECIMAL(8,2) NOT NULL DEFAULT 15.00,
  query_start_utc DATETIME(3) NOT NULL,
  query_end_utc DATETIME(3) NOT NULL,
  bucket_seconds INT UNSIGNED NOT NULL DEFAULT 300,
  source_ref_type VARCHAR(64) NULL,
  source_ref_id BIGINT UNSIGNED NULL,
  tile_count INT UNSIGNED NOT NULL DEFAULT 0,
  completed_tile_count INT UNSIGNED NOT NULL DEFAULT 0,
  sample_count INT UNSIGNED NOT NULL DEFAULT 0,
  error_message TEXT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_adsb_coverage_jobs_uuid (job_uuid),
  KEY idx_ipca_adsb_coverage_jobs_scope_status (scope, status, query_start_utc),
  KEY idx_ipca_adsb_coverage_jobs_source (source_ref_type, source_ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_adsb_coverage_tiles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tile_uuid CHAR(36) NOT NULL,
  job_id BIGINT UNSIGNED NULL,
  scope VARCHAR(32) NOT NULL DEFAULT 'ktrm_baseline',
  provider VARCHAR(64) NOT NULL DEFAULT 'adsbexchange',
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  center_latitude DECIMAL(11,7) NOT NULL,
  center_longitude DECIMAL(11,7) NOT NULL,
  radius_nm DECIMAL(8,2) NOT NULL DEFAULT 15.00,
  bucket_start_utc DATETIME(3) NOT NULL,
  bucket_end_utc DATETIME(3) NOT NULL,
  raw_payload_id BIGINT UNSIGNED NULL,
  sample_count INT UNSIGNED NOT NULL DEFAULT 0,
  empty_result TINYINT(1) NOT NULL DEFAULT 0,
  attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  fetched_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_adsb_coverage_tile_uuid (tile_uuid),
  UNIQUE KEY uk_ipca_adsb_coverage_tile_area_time (scope, provider, center_latitude, center_longitude, radius_nm, bucket_start_utc, bucket_end_utc),
  KEY idx_ipca_adsb_coverage_tiles_status (status, bucket_start_utc),
  KEY idx_ipca_adsb_coverage_tiles_job (job_id, status),
  CONSTRAINT fk_ipca_adsb_coverage_tiles_job
    FOREIGN KEY (job_id) REFERENCES ipca_adsb_coverage_jobs(id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_adsb_raw_payloads (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  payload_uuid CHAR(36) NOT NULL,
  provider VARCHAR(64) NOT NULL DEFAULT 'adsbexchange',
  request_url TEXT NULL,
  request_json JSON NULL,
  http_status INT NULL,
  sha256 CHAR(64) NOT NULL,
  storage_path VARCHAR(1024) NOT NULL,
  byte_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  fetched_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_adsb_raw_payload_uuid (payload_uuid),
  UNIQUE KEY uk_ipca_adsb_raw_payload_sha (sha256),
  KEY idx_ipca_adsb_raw_payload_provider_time (provider, fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_adsb_traffic_samples (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  provider VARCHAR(64) NOT NULL DEFAULT 'adsbexchange',
  raw_payload_id BIGINT UNSIGNED NULL,
  sample_time_utc DATETIME(3) NOT NULL,
  aircraft_hex CHAR(6) NOT NULL DEFAULT '',
  callsign VARCHAR(32) NOT NULL DEFAULT '',
  latitude DECIMAL(11,7) NOT NULL,
  longitude DECIMAL(11,7) NOT NULL,
  altitude_ft DECIMAL(10,1) NULL,
  groundspeed_kt DECIMAL(10,2) NULL,
  track_deg DECIMAL(7,2) NULL,
  vertical_speed_fpm DECIMAL(10,1) NULL,
  source_distance_nm DECIMAL(8,2) NULL,
  raw_json JSON NULL,
  sample_hash CHAR(64) NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_adsb_traffic_sample_hash (sample_hash),
  KEY idx_ipca_adsb_traffic_time (sample_time_utc),
  KEY idx_ipca_adsb_traffic_hex_time (aircraft_hex, sample_time_utc),
  KEY idx_ipca_adsb_traffic_lat_lon_time (latitude, longitude, sample_time_utc),
  CONSTRAINT fk_ipca_adsb_traffic_raw_payload
    FOREIGN KEY (raw_payload_id) REFERENCES ipca_adsb_raw_payloads(id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_adsb_flight_traffic_links (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  traffic_sample_id BIGINT UNSIGNED NOT NULL,
  source_ref_type VARCHAR(64) NOT NULL,
  source_ref_id BIGINT UNSIGNED NOT NULL,
  replay_time_s DECIMAL(12,3) NULL,
  distance_nm DECIMAL(8,2) NULL,
  bearing_deg DECIMAL(7,2) NULL,
  relative_altitude_ft DECIMAL(10,1) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_adsb_flight_traffic_link (traffic_sample_id, source_ref_type, source_ref_id),
  KEY idx_ipca_adsb_flight_traffic_source (source_ref_type, source_ref_id, replay_time_s),
  CONSTRAINT fk_ipca_adsb_flight_traffic_sample
    FOREIGN KEY (traffic_sample_id) REFERENCES ipca_adsb_traffic_samples(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_garmin_avionics_alert_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_uuid CHAR(36) NOT NULL,
  provider_name VARCHAR(64) NOT NULL DEFAULT 'GARMIN',
  garmin_csv_file_id BIGINT UNSIGNED NOT NULL,
  garmin_entry_uuid VARCHAR(80) NOT NULL DEFAULT '',
  canonical_track_uuid VARCHAR(80) NOT NULL DEFAULT '',
  sample_time_utc DATETIME(3) NULL,
  replay_time_s DECIMAL(12,3) NULL,
  csv_row_number INT UNSIGNED NULL,
  alert_type VARCHAR(32) NOT NULL DEFAULT 'unknown',
  raw_column_name VARCHAR(128) NOT NULL DEFAULT '',
  raw_alert_text TEXT NOT NULL,
  normalized_alert_text VARCHAR(255) NOT NULL DEFAULT '',
  latitude DECIMAL(11,7) NULL,
  longitude DECIMAL(11,7) NULL,
  altitude_ft DECIMAL(10,1) NULL,
  alert_hash CHAR(64) NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_garmin_alert_uuid (event_uuid),
  UNIQUE KEY uk_ipca_garmin_alert_hash (alert_hash),
  KEY idx_ipca_garmin_alert_csv_time (garmin_csv_file_id, sample_time_utc),
  KEY idx_ipca_garmin_alert_entry_time (garmin_entry_uuid, sample_time_utc),
  KEY idx_ipca_garmin_alert_type_time (alert_type, sample_time_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_adsb_hazard_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  hazard_uuid CHAR(36) NOT NULL,
  source_ref_type VARCHAR(64) NOT NULL,
  source_ref_id BIGINT UNSIGNED NOT NULL,
  garmin_alert_event_id BIGINT UNSIGNED NULL,
  traffic_sample_id BIGINT UNSIGNED NULL,
  hazard_type VARCHAR(32) NOT NULL DEFAULT 'adsb_cpa_only',
  severity VARCHAR(32) NOT NULL DEFAULT 'advisory',
  cpa_time_utc DATETIME(3) NULL,
  replay_time_s DECIMAL(12,3) NULL,
  horizontal_distance_nm DECIMAL(8,3) NULL,
  vertical_separation_ft DECIMAL(10,1) NULL,
  closure_rate_kt DECIMAL(10,2) NULL,
  traffic_aircraft_hex CHAR(6) NOT NULL DEFAULT '',
  traffic_callsign VARCHAR(32) NOT NULL DEFAULT '',
  evidence_json JSON NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_adsb_hazard_uuid (hazard_uuid),
  KEY idx_ipca_adsb_hazard_source (source_ref_type, source_ref_id, replay_time_s),
  KEY idx_ipca_adsb_hazard_severity (severity, cpa_time_utc),
  KEY idx_ipca_adsb_hazard_garmin_alert (garmin_alert_event_id),
  KEY idx_ipca_adsb_hazard_traffic_sample (traffic_sample_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
