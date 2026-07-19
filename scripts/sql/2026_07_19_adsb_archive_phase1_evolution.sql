-- Phase 1 ADS-B archive evolution.
-- Additive only: evolves existing ipca_adsb_* tables into the local archive foundation.

CREATE TABLE IF NOT EXISTS ipca_adsb_geographic_definitions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  definition_uuid CHAR(36) NOT NULL,
  name VARCHAR(160) NOT NULL,
  definition_type VARCHAR(32) NOT NULL,
  configuration_json JSON NOT NULL,
  minimum_altitude_ft DECIMAL(10,1) NULL,
  maximum_altitude_ft DECIMAL(10,1) NOT NULL DEFAULT 10000.0,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  live_monitoring_enabled TINYINT(1) NOT NULL DEFAULT 0,
  historical_backfill_enabled TINYINT(1) NOT NULL DEFAULT 0,
  replay_query_enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_adsb_geo_def_uuid (definition_uuid),
  KEY idx_ipca_adsb_geo_def_enabled (enabled, live_monitoring_enabled, historical_backfill_enabled),
  KEY idx_ipca_adsb_geo_def_type (definition_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_adsb_archive_settings (
  setting_key VARCHAR(96) NOT NULL PRIMARY KEY,
  setting_value TEXT NULL,
  value_type VARCHAR(32) NOT NULL DEFAULT 'string',
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS ipca_adsb_add_column_if_missing;
DELIMITER //
CREATE PROCEDURE ipca_adsb_add_column_if_missing(IN p_table VARCHAR(128), IN p_column VARCHAR(128), IN p_definition TEXT, IN p_after VARCHAR(128))
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

CALL ipca_adsb_add_column_if_missing('ipca_adsb_traffic_samples', 'provider_record_key', 'VARCHAR(128) NULL', 'provider');
CALL ipca_adsb_add_column_if_missing('ipca_adsb_traffic_samples', 'source_mode', 'VARCHAR(32) NOT NULL DEFAULT ''UNKNOWN''', 'provider_record_key');
CALL ipca_adsb_add_column_if_missing('ipca_adsb_traffic_samples', 'icao24', 'VARCHAR(32) NOT NULL DEFAULT ''''', 'source_mode');
CALL ipca_adsb_add_column_if_missing('ipca_adsb_traffic_samples', 'baro_altitude_ft', 'DECIMAL(10,1) NULL', 'altitude_ft');
CALL ipca_adsb_add_column_if_missing('ipca_adsb_traffic_samples', 'geo_altitude_ft', 'DECIMAL(10,1) NULL', 'baro_altitude_ft');
CALL ipca_adsb_add_column_if_missing('ipca_adsb_traffic_samples', 'on_ground', 'TINYINT(1) NULL', 'geo_altitude_ft');
CALL ipca_adsb_add_column_if_missing('ipca_adsb_traffic_samples', 'true_heading_deg', 'DECIMAL(7,2) NULL', 'track_deg');
CALL ipca_adsb_add_column_if_missing('ipca_adsb_traffic_samples', 'squawk', 'VARCHAR(16) NULL', 'vertical_speed_fpm');
CALL ipca_adsb_add_column_if_missing('ipca_adsb_traffic_samples', 'category', 'VARCHAR(32) NULL', 'squawk');
CALL ipca_adsb_add_column_if_missing('ipca_adsb_traffic_samples', 'position_source', 'VARCHAR(64) NULL', 'category');
CALL ipca_adsb_add_column_if_missing('ipca_adsb_traffic_samples', 'receiver_id', 'VARCHAR(128) NULL', 'position_source');
CALL ipca_adsb_add_column_if_missing('ipca_adsb_traffic_samples', 'signal_quality', 'DECIMAL(10,3) NULL', 'receiver_id');
CALL ipca_adsb_add_column_if_missing('ipca_adsb_traffic_samples', 'nic', 'INT NULL', 'signal_quality');
CALL ipca_adsb_add_column_if_missing('ipca_adsb_traffic_samples', 'nac_p', 'INT NULL', 'nic');
CALL ipca_adsb_add_column_if_missing('ipca_adsb_traffic_samples', 'sil', 'INT NULL', 'nac_p');
CALL ipca_adsb_add_column_if_missing('ipca_adsb_traffic_samples', 'emergency_status', 'VARCHAR(64) NULL', 'sil');
CALL ipca_adsb_add_column_if_missing('ipca_adsb_traffic_samples', 'ingestion_batch_id', 'BIGINT UNSIGNED NULL', 'raw_payload_id');
CALL ipca_adsb_add_column_if_missing('ipca_adsb_traffic_samples', 'normalization_version', 'VARCHAR(32) NOT NULL DEFAULT ''legacy_v1''', 'ingestion_batch_id');
CALL ipca_adsb_add_column_if_missing('ipca_adsb_traffic_samples', 'quality_flags_json', 'JSON NULL', 'normalization_version');
CALL ipca_adsb_add_column_if_missing('ipca_adsb_traffic_samples', 'observation_fingerprint', 'CHAR(64) NULL', 'quality_flags_json');

CALL ipca_adsb_add_column_if_missing('ipca_adsb_raw_payloads', 'source_mode', 'VARCHAR(32) NOT NULL DEFAULT ''UNKNOWN''', 'provider');
CALL ipca_adsb_add_column_if_missing('ipca_adsb_raw_payloads', 'content_type', 'VARCHAR(128) NULL', 'http_status');
CALL ipca_adsb_add_column_if_missing('ipca_adsb_raw_payloads', 'compression', 'VARCHAR(32) NULL', 'content_type');
CALL ipca_adsb_add_column_if_missing('ipca_adsb_raw_payloads', 'metadata_json', 'JSON NULL', 'byte_size');

CALL ipca_adsb_add_column_if_missing('ipca_adsb_coverage_jobs', 'provider', 'VARCHAR(64) NOT NULL DEFAULT ''adsbexchange''', 'scope');
CALL ipca_adsb_add_column_if_missing('ipca_adsb_coverage_jobs', 'source_mode', 'VARCHAR(32) NOT NULL DEFAULT ''UNKNOWN''', 'provider');
CALL ipca_adsb_add_column_if_missing('ipca_adsb_coverage_jobs', 'geographic_definition_id', 'BIGINT UNSIGNED NULL', 'source_mode');
CALL ipca_adsb_add_column_if_missing('ipca_adsb_coverage_jobs', 'request_parameters_json', 'JSON NULL', 'geographic_definition_id');
CALL ipca_adsb_add_column_if_missing('ipca_adsb_coverage_jobs', 'started_at', 'DATETIME(3) NULL', 'request_parameters_json');
CALL ipca_adsb_add_column_if_missing('ipca_adsb_coverage_jobs', 'completed_at', 'DATETIME(3) NULL', 'started_at');
CALL ipca_adsb_add_column_if_missing('ipca_adsb_coverage_jobs', 'returned_count', 'INT UNSIGNED NOT NULL DEFAULT 0', 'sample_count');
CALL ipca_adsb_add_column_if_missing('ipca_adsb_coverage_jobs', 'inserted_count', 'INT UNSIGNED NOT NULL DEFAULT 0', 'returned_count');
CALL ipca_adsb_add_column_if_missing('ipca_adsb_coverage_jobs', 'duplicate_count', 'INT UNSIGNED NOT NULL DEFAULT 0', 'inserted_count');
CALL ipca_adsb_add_column_if_missing('ipca_adsb_coverage_jobs', 'invalid_count', 'INT UNSIGNED NOT NULL DEFAULT 0', 'duplicate_count');
CALL ipca_adsb_add_column_if_missing('ipca_adsb_coverage_jobs', 'coverage_status', 'VARCHAR(32) NOT NULL DEFAULT ''UNKNOWN''', 'invalid_count');
CALL ipca_adsb_add_column_if_missing('ipca_adsb_coverage_jobs', 'coverage_metrics_json', 'JSON NULL', 'coverage_status');

CALL ipca_adsb_add_column_if_missing('ipca_adsb_coverage_tiles', 'source_mode', 'VARCHAR(32) NOT NULL DEFAULT ''UNKNOWN''', 'provider');
CALL ipca_adsb_add_column_if_missing('ipca_adsb_coverage_tiles', 'geographic_definition_id', 'BIGINT UNSIGNED NULL', 'source_mode');
CALL ipca_adsb_add_column_if_missing('ipca_adsb_coverage_tiles', 'result_status', 'VARCHAR(64) NOT NULL DEFAULT ''PENDING''', 'status');
CALL ipca_adsb_add_column_if_missing('ipca_adsb_coverage_tiles', 'returned_count', 'INT UNSIGNED NOT NULL DEFAULT 0', 'sample_count');
CALL ipca_adsb_add_column_if_missing('ipca_adsb_coverage_tiles', 'inserted_count', 'INT UNSIGNED NOT NULL DEFAULT 0', 'returned_count');
CALL ipca_adsb_add_column_if_missing('ipca_adsb_coverage_tiles', 'duplicate_count', 'INT UNSIGNED NOT NULL DEFAULT 0', 'inserted_count');
CALL ipca_adsb_add_column_if_missing('ipca_adsb_coverage_tiles', 'invalid_count', 'INT UNSIGNED NOT NULL DEFAULT 0', 'duplicate_count');
CALL ipca_adsb_add_column_if_missing('ipca_adsb_coverage_tiles', 'request_parameters_json', 'JSON NULL', 'invalid_count');
CALL ipca_adsb_add_column_if_missing('ipca_adsb_coverage_tiles', 'coverage_metrics_json', 'JSON NULL', 'request_parameters_json');

UPDATE ipca_adsb_traffic_samples
SET icao24 = LOWER(aircraft_hex)
WHERE (icao24 = '' OR icao24 IS NULL)
  AND aircraft_hex <> '';

UPDATE ipca_adsb_traffic_samples
SET baro_altitude_ft = altitude_ft
WHERE baro_altitude_ft IS NULL
  AND altitude_ft IS NOT NULL;

UPDATE ipca_adsb_traffic_samples
SET quality_flags_json = JSON_OBJECT('source_mode', 'unknown_existing_row', 'altitude_source', IF(altitude_ft IS NULL, 'unknown', 'legacy_altitude_ft')),
    normalization_version = COALESCE(NULLIF(normalization_version, ''), 'legacy_v1')
WHERE quality_flags_json IS NULL;

UPDATE ipca_adsb_traffic_samples
SET observation_fingerprint = sample_hash
WHERE observation_fingerprint IS NULL
  AND sample_hash IS NOT NULL
  AND sample_hash <> '';

UPDATE ipca_adsb_raw_payloads
SET metadata_json = JSON_OBJECT('phase1_backfill', true)
WHERE metadata_json IS NULL;

INSERT IGNORE INTO ipca_adsb_archive_settings (setting_key, setting_value, value_type)
VALUES ('live_archive_cutover_utc', NULL, 'datetime');

DROP PROCEDURE IF EXISTS ipca_adsb_add_column_if_missing;
