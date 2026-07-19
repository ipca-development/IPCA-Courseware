CREATE TABLE IF NOT EXISTS ipca_cockpit_adsb_candidate_observations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  recording_id BIGINT UNSIGNED NOT NULL,
  aircraft_identifier VARCHAR(32) NOT NULL,
  callsign VARCHAR(32) NOT NULL DEFAULT '',
  discovery_utc DATETIME(3) NOT NULL,
  discovery_latitude DECIMAL(11,7) NULL,
  discovery_longitude DECIMAL(11,7) NULL,
  ownship_latitude DECIMAL(11,7) NULL,
  ownship_longitude DECIMAL(11,7) NULL,
  discovery_distance_nm DECIMAL(8,2) NULL,
  source_type VARCHAR(64) NOT NULL DEFAULT '',
  raw_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ipca_cockpit_adsb_candidate_recording (recording_id, aircraft_identifier),
  KEY idx_ipca_cockpit_adsb_candidate_time (recording_id, discovery_utc),
  CONSTRAINT fk_ipca_cockpit_adsb_candidate_recording
    FOREIGN KEY (recording_id) REFERENCES ipca_cockpit_recordings(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_cockpit_adsb_raw_traces (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  provider VARCHAR(64) NOT NULL DEFAULT 'adsbexchange',
  aircraft_identifier VARCHAR(32) NOT NULL,
  trace_date_utc DATE NOT NULL,
  request_route TEXT NULL,
  http_status INT NULL,
  content_type VARCHAR(128) NULL,
  sha256 CHAR(64) NOT NULL,
  storage_path VARCHAR(1024) NOT NULL,
  byte_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  fetched_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_cockpit_adsb_raw_trace (provider, aircraft_identifier, trace_date_utc, sha256),
  KEY idx_ipca_cockpit_adsb_raw_trace_lookup (provider, aircraft_identifier, trace_date_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_cockpit_adsb_traffic_aircraft_samples (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  recording_id BIGINT UNSIGNED NOT NULL,
  aircraft_identifier VARCHAR(32) NOT NULL,
  sample_time_utc DATETIME(3) NOT NULL,
  seconds_since_start DECIMAL(12,3) NOT NULL DEFAULT 0,
  latitude DECIMAL(11,7) NULL,
  longitude DECIMAL(11,7) NULL,
  altitude_baro_ft DECIMAL(10,1) NULL,
  altitude_geom_ft DECIMAL(10,1) NULL,
  groundspeed_kt DECIMAL(10,2) NULL,
  track_true_deg DECIMAL(7,2) NULL,
  vertical_rate_baro_fpm DECIMAL(10,1) NULL,
  vertical_rate_geom_fpm DECIMAL(10,1) NULL,
  callsign VARCHAR(32) NOT NULL DEFAULT '',
  registration VARCHAR(32) NOT NULL DEFAULT '',
  aircraft_type VARCHAR(32) NOT NULL DEFAULT '',
  source_type VARCHAR(64) NOT NULL DEFAULT '',
  on_ground TINYINT(1) NULL,
  stale_position TINYINT(1) NOT NULL DEFAULT 0,
  new_leg TINYINT(1) NOT NULL DEFAULT 0,
  leg_id INT UNSIGNED NOT NULL DEFAULT 1,
  altitude_source VARCHAR(16) NOT NULL DEFAULT '',
  vertical_rate_source VARCHAR(16) NOT NULL DEFAULT '',
  raw_trace_id BIGINT UNSIGNED NULL,
  raw_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipca_cockpit_adsb_aircraft_sample (recording_id, aircraft_identifier, sample_time_utc, leg_id),
  KEY idx_ipca_cockpit_adsb_aircraft_recording_time (recording_id, aircraft_identifier, sample_time_utc),
  CONSTRAINT fk_ipca_cockpit_adsb_aircraft_sample_recording
    FOREIGN KEY (recording_id) REFERENCES ipca_cockpit_recordings(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_ipca_cockpit_adsb_aircraft_sample_raw
    FOREIGN KEY (raw_trace_id) REFERENCES ipca_cockpit_adsb_raw_traces(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
