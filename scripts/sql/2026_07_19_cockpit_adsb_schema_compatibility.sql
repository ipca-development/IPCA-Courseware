-- Forward-only compatibility migration for truthful cockpit ADS-B diagnostics.
-- Safe for repeated execution with MySQL 8.0+.

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
  CONSTRAINT fk_ipca_cockpit_adsb_discovery_recording_compat
    FOREIGN KEY (recording_id) REFERENCES ipca_cockpit_recordings(id)
    ON DELETE CASCADE
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
  CONSTRAINT fk_ipca_cockpit_adsb_trace_recording_compat
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
  raw_evidence_id BIGINT UNSIGNED NULL,
  raw_evidence_ref VARCHAR(1024) NULL,
  raw_json LONGTEXT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_adsb_metadata_identifier (aircraft_identifier),
  KEY idx_ipca_adsb_metadata_hex (icao_hex),
  KEY idx_ipca_adsb_metadata_registration (registration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS ipca_add_column_if_missing;
DELIMITER //
CREATE PROCEDURE ipca_add_column_if_missing(IN p_table VARCHAR(128), IN p_column VARCHAR(128), IN p_definition TEXT, IN p_after VARCHAR(128))
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = p_table
      AND COLUMN_NAME = p_column
  ) THEN
    SET @sql = CONCAT(
      'ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition,
      IF(p_after IS NULL OR p_after = '', '', CONCAT(' AFTER `', p_after, '`'))
    );
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
  END IF;
END//
DELIMITER ;

CALL ipca_add_column_if_missing('ipca_cockpit_adsb_candidate_observations', 'registration', 'VARCHAR(32) NOT NULL DEFAULT ''''', 'callsign');
CALL ipca_add_column_if_missing('ipca_cockpit_adsb_candidate_observations', 'discovery_source', 'VARCHAR(64) NOT NULL DEFAULT ''unknown''', 'discovery_distance_nm');
CALL ipca_add_column_if_missing('ipca_cockpit_adsb_candidate_observations', 'provider', 'VARCHAR(64) NOT NULL DEFAULT ''''', 'discovery_source');
CALL ipca_add_column_if_missing('ipca_cockpit_adsb_candidate_observations', 'provider_endpoint', 'TEXT NULL', 'provider');
CALL ipca_add_column_if_missing('ipca_cockpit_adsb_candidate_observations', 'raw_evidence_id', 'BIGINT UNSIGNED NULL', 'provider_endpoint');
CALL ipca_add_column_if_missing('ipca_cockpit_adsb_candidate_observations', 'raw_evidence_ref', 'VARCHAR(1024) NULL', 'raw_evidence_id');
CALL ipca_add_column_if_missing('ipca_cockpit_adsb_candidate_observations', 'supplemental', 'TINYINT(1) NOT NULL DEFAULT 0', 'raw_evidence_ref');

CALL ipca_add_column_if_missing('ipca_cockpit_adsb_enrichments', 'historical_geographical_discovery_supported', 'TINYINT(1) NOT NULL DEFAULT 0', 'traffic_sample_count');
CALL ipca_add_column_if_missing('ipca_cockpit_adsb_enrichments', 'historical_geographical_discovery_verified', 'TINYINT(1) NOT NULL DEFAULT 0', 'historical_geographical_discovery_supported');
CALL ipca_add_column_if_missing('ipca_cockpit_adsb_enrichments', 'historical_geographical_discovery_provider', 'VARCHAR(64) NOT NULL DEFAULT ''''', 'historical_geographical_discovery_verified');
CALL ipca_add_column_if_missing('ipca_cockpit_adsb_enrichments', 'historical_geographical_discovery_endpoint', 'TEXT NULL', 'historical_geographical_discovery_provider');
CALL ipca_add_column_if_missing('ipca_cockpit_adsb_enrichments', 'historical_geographical_discovery_error', 'TEXT NULL', 'historical_geographical_discovery_endpoint');
CALL ipca_add_column_if_missing('ipca_cockpit_adsb_enrichments', 'historical_dataset_access_configured', 'TINYINT(1) NOT NULL DEFAULT 0', 'historical_geographical_discovery_error');
CALL ipca_add_column_if_missing('ipca_cockpit_adsb_enrichments', 'historical_trace_access_configured', 'TINYINT(1) NOT NULL DEFAULT 0', 'historical_dataset_access_configured');
CALL ipca_add_column_if_missing('ipca_cockpit_adsb_enrichments', 'adsbx_historical_candidate_count', 'INT UNSIGNED NOT NULL DEFAULT 0', 'historical_trace_access_configured');
CALL ipca_add_column_if_missing('ipca_cockpit_adsb_enrichments', 'local_fleet_candidate_count', 'INT UNSIGNED NOT NULL DEFAULT 0', 'adsbx_historical_candidate_count');
CALL ipca_add_column_if_missing('ipca_cockpit_adsb_enrichments', 'legacy_candidate_count', 'INT UNSIGNED NOT NULL DEFAULT 0', 'local_fleet_candidate_count');
CALL ipca_add_column_if_missing('ipca_cockpit_adsb_enrichments', 'manual_candidate_count', 'INT UNSIGNED NOT NULL DEFAULT 0', 'legacy_candidate_count');
CALL ipca_add_column_if_missing('ipca_cockpit_adsb_enrichments', 'total_candidate_count', 'INT UNSIGNED NOT NULL DEFAULT 0', 'manual_candidate_count');
CALL ipca_add_column_if_missing('ipca_cockpit_adsb_enrichments', 'historical_requests_attempted', 'INT UNSIGNED NOT NULL DEFAULT 0', 'total_candidate_count');
CALL ipca_add_column_if_missing('ipca_cockpit_adsb_enrichments', 'historical_requests_succeeded', 'INT UNSIGNED NOT NULL DEFAULT 0', 'historical_requests_attempted');
CALL ipca_add_column_if_missing('ipca_cockpit_adsb_enrichments', 'historical_requests_failed', 'INT UNSIGNED NOT NULL DEFAULT 0', 'historical_requests_succeeded');
CALL ipca_add_column_if_missing('ipca_cockpit_adsb_enrichments', 'historical_requests_unauthorized', 'INT UNSIGNED NOT NULL DEFAULT 0', 'historical_requests_failed');
CALL ipca_add_column_if_missing('ipca_cockpit_adsb_enrichments', 'historical_requests_forbidden', 'INT UNSIGNED NOT NULL DEFAULT 0', 'historical_requests_unauthorized');
CALL ipca_add_column_if_missing('ipca_cockpit_adsb_enrichments', 'historical_requests_not_found', 'INT UNSIGNED NOT NULL DEFAULT 0', 'historical_requests_forbidden');
CALL ipca_add_column_if_missing('ipca_cockpit_adsb_enrichments', 'historical_requests_rate_limited', 'INT UNSIGNED NOT NULL DEFAULT 0', 'historical_requests_not_found');
CALL ipca_add_column_if_missing('ipca_cockpit_adsb_enrichments', 'historical_snapshots_received', 'INT UNSIGNED NOT NULL DEFAULT 0', 'historical_requests_rate_limited');
CALL ipca_add_column_if_missing('ipca_cockpit_adsb_enrichments', 'historical_aircraft_rows_received', 'INT UNSIGNED NOT NULL DEFAULT 0', 'historical_snapshots_received');
CALL ipca_add_column_if_missing('ipca_cockpit_adsb_enrichments', 'historical_unique_aircraft_discovered', 'INT UNSIGNED NOT NULL DEFAULT 0', 'historical_aircraft_rows_received');
CALL ipca_add_column_if_missing('ipca_cockpit_adsb_enrichments', 'local_fleet_supplement_count', 'INT UNSIGNED NOT NULL DEFAULT 0', 'historical_unique_aircraft_discovered');

UPDATE ipca_cockpit_adsb_candidate_observations
SET discovery_source = CASE
      WHEN source_type = 'fleet' THEN 'local_fleet_supplement'
      WHEN source_type IN ('adsbx_historical_geographical', 'adsbx_historical_snapshot', 'local_fleet_supplement', 'legacy_replay_supplement', 'manual_identifier') THEN source_type
      WHEN source_type IS NULL OR source_type = '' THEN 'unknown'
      ELSE 'unknown'
    END,
    supplemental = CASE WHEN source_type = 'fleet' THEN 1 ELSE supplemental END,
    provider = CASE WHEN source_type = 'fleet' AND provider = '' THEN 'local_registry' ELSE provider END
WHERE discovery_source = 'unknown'
   OR source_type = 'fleet';

DROP PROCEDURE IF EXISTS ipca_add_column_if_missing;
