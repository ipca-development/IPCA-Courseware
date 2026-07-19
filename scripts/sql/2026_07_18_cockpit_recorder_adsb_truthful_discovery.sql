ALTER TABLE ipca_cockpit_adsb_candidate_observations
  ADD COLUMN registration VARCHAR(32) NOT NULL DEFAULT '' AFTER callsign,
  ADD COLUMN discovery_source VARCHAR(64) NOT NULL DEFAULT 'unknown' AFTER discovery_distance_nm,
  ADD COLUMN provider VARCHAR(64) NOT NULL DEFAULT '' AFTER discovery_source,
  ADD COLUMN provider_endpoint TEXT NULL AFTER provider,
  ADD COLUMN raw_evidence_ref VARCHAR(1024) NULL AFTER provider_endpoint,
  ADD COLUMN supplemental TINYINT(1) NOT NULL DEFAULT 0 AFTER raw_evidence_ref;

CREATE TABLE IF NOT EXISTS ipca_cockpit_adsb_discovery_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  recording_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(64) NOT NULL DEFAULT 'adsbexchange',
  capability VARCHAR(64) NOT NULL DEFAULT 'historical_geographical',
  endpoint TEXT NULL,
  method VARCHAR(12) NOT NULL DEFAULT 'GET',
  requested_utc DATETIME(3) NULL,
  latitude DECIMAL(11,7) NULL,
  longitude DECIMAL(11,7) NULL,
  radius_nm DECIMAL(8,2) NULL,
  http_status INT NULL,
  response_headers LONGTEXT NULL,
  content_type VARCHAR(128) NULL,
  provider_response_utc DATETIME(3) NULL,
  response_body_preview TEXT NULL,
  parsed_aircraft_count INT UNSIGNED NOT NULL DEFAULT 0,
  returned_identifiers_json LONGTEXT NULL,
  request_duration_ms INT UNSIGNED NULL,
  transport_error TEXT NULL,
  json_parse_error TEXT NULL,
  result_status VARCHAR(64) NOT NULL DEFAULT 'unsupported_capability',
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY idx_ipca_cockpit_adsb_discovery_recording (recording_id, requested_utc),
  KEY idx_ipca_cockpit_adsb_discovery_status (recording_id, result_status),
  CONSTRAINT fk_ipca_cockpit_adsb_discovery_recording
    FOREIGN KEY (recording_id) REFERENCES ipca_cockpit_recordings(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_adsb_aircraft_metadata_registry (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  aircraft_identifier VARCHAR(32) NOT NULL,
  icao_hex CHAR(6) NOT NULL DEFAULT '',
  registration VARCHAR(32) NOT NULL DEFAULT '',
  manufacturer VARCHAR(128) NOT NULL DEFAULT '',
  model VARCHAR(128) NOT NULL DEFAULT '',
  type_code VARCHAR(32) NOT NULL DEFAULT '',
  operator VARCHAR(128) NOT NULL DEFAULT '',
  country VARCHAR(64) NOT NULL DEFAULT '',
  military TINYINT(1) NULL,
  interesting TINYINT(1) NULL,
  first_seen_utc DATETIME(3) NULL,
  last_seen_utc DATETIME(3) NULL,
  metadata_source VARCHAR(64) NOT NULL DEFAULT '',
  metadata_retrieved_at DATETIME(3) NULL,
  raw_evidence_ref VARCHAR(1024) NULL,
  raw_json LONGTEXT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_adsb_metadata_identifier (aircraft_identifier),
  KEY idx_ipca_adsb_metadata_hex (icao_hex),
  KEY idx_ipca_adsb_metadata_registration (registration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_cockpit_adsb_trace_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  recording_id BIGINT UNSIGNED NOT NULL,
  aircraft_identifier VARCHAR(32) NOT NULL,
  trace_date_utc DATE NOT NULL,
  provider VARCHAR(64) NOT NULL DEFAULT 'adsbexchange',
  endpoint TEXT NULL,
  http_status INT NULL,
  result_status VARCHAR(64) NOT NULL DEFAULT 'unknown',
  raw_trace_row_count INT UNSIGNED NOT NULL DEFAULT 0,
  normalized_row_count INT UNSIGNED NOT NULL DEFAULT 0,
  first_sample_utc DATETIME(3) NULL,
  last_sample_utc DATETIME(3) NULL,
  failure_reason TEXT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY idx_ipca_cockpit_adsb_trace_recording (recording_id, aircraft_identifier, trace_date_utc),
  CONSTRAINT fk_ipca_cockpit_adsb_trace_recording
    FOREIGN KEY (recording_id) REFERENCES ipca_cockpit_recordings(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE ipca_cockpit_adsb_enrichments
  ADD COLUMN historical_geographical_discovery_supported TINYINT(1) NOT NULL DEFAULT 0 AFTER traffic_sample_count,
  ADD COLUMN historical_geographical_discovery_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER historical_geographical_discovery_supported,
  ADD COLUMN historical_geographical_discovery_provider VARCHAR(64) NOT NULL DEFAULT '' AFTER historical_geographical_discovery_verified,
  ADD COLUMN historical_geographical_discovery_endpoint TEXT NULL AFTER historical_geographical_discovery_provider,
  ADD COLUMN historical_geographical_discovery_error TEXT NULL AFTER historical_geographical_discovery_endpoint,
  ADD COLUMN historical_dataset_access_configured TINYINT(1) NOT NULL DEFAULT 0 AFTER historical_geographical_discovery_error,
  ADD COLUMN historical_trace_access_configured TINYINT(1) NOT NULL DEFAULT 0 AFTER historical_dataset_access_configured,
  ADD COLUMN adsbx_historical_candidate_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER historical_trace_access_configured,
  ADD COLUMN local_fleet_candidate_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER adsbx_historical_candidate_count,
  ADD COLUMN legacy_candidate_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER local_fleet_candidate_count,
  ADD COLUMN manual_candidate_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER legacy_candidate_count,
  ADD COLUMN total_candidate_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER manual_candidate_count,
  ADD COLUMN historical_requests_attempted INT UNSIGNED NOT NULL DEFAULT 0 AFTER total_candidate_count,
  ADD COLUMN historical_requests_succeeded INT UNSIGNED NOT NULL DEFAULT 0 AFTER historical_requests_attempted,
  ADD COLUMN historical_requests_failed INT UNSIGNED NOT NULL DEFAULT 0 AFTER historical_requests_succeeded,
  ADD COLUMN historical_requests_unauthorized INT UNSIGNED NOT NULL DEFAULT 0 AFTER historical_requests_failed,
  ADD COLUMN historical_requests_forbidden INT UNSIGNED NOT NULL DEFAULT 0 AFTER historical_requests_unauthorized,
  ADD COLUMN historical_requests_not_found INT UNSIGNED NOT NULL DEFAULT 0 AFTER historical_requests_forbidden,
  ADD COLUMN historical_requests_rate_limited INT UNSIGNED NOT NULL DEFAULT 0 AFTER historical_requests_not_found,
  ADD COLUMN historical_snapshots_received INT UNSIGNED NOT NULL DEFAULT 0 AFTER historical_requests_rate_limited,
  ADD COLUMN historical_aircraft_rows_received INT UNSIGNED NOT NULL DEFAULT 0 AFTER historical_snapshots_received,
  ADD COLUMN historical_unique_aircraft_discovered INT UNSIGNED NOT NULL DEFAULT 0 AFTER historical_aircraft_rows_received,
  ADD COLUMN local_fleet_supplement_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER historical_unique_aircraft_discovered;
