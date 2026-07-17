SET @table_name := 'ipca_garmin_csv_files';

SET @has_system_identifier := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = @table_name
    AND COLUMN_NAME = 'system_identifier'
);
SET @sql := IF(
  @has_system_identifier = 0,
  'ALTER TABLE ipca_garmin_csv_files ADD COLUMN system_identifier VARCHAR(128) NOT NULL DEFAULT '''' AFTER product',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_airframe_hours_start := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = @table_name
    AND COLUMN_NAME = 'airframe_hours_start'
);
SET @sql := IF(
  @has_airframe_hours_start = 0,
  'ALTER TABLE ipca_garmin_csv_files ADD COLUMN airframe_hours_start DECIMAL(12,4) NULL AFTER system_identifier',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_engine_hours_start := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = @table_name
    AND COLUMN_NAME = 'engine_hours_start'
);
SET @sql := IF(
  @has_engine_hours_start = 0,
  'ALTER TABLE ipca_garmin_csv_files ADD COLUMN engine_hours_start DECIMAL(12,4) NULL AFTER airframe_hours_start',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS ipca_garmin_system_identifier_mappings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  system_identifier VARCHAR(128) NOT NULL,
  device_identifier VARCHAR(64) NOT NULL DEFAULT '',
  aircraft_id BIGINT UNSIGNED NULL,
  tail_number VARCHAR(32) NOT NULL DEFAULT '',
  avionics_family VARCHAR(32) NOT NULL DEFAULT 'unknown',
  default_quality VARCHAR(32) NOT NULL DEFAULT 'unknown',
  provides_full_avionics TINYINT(1) NOT NULL DEFAULT 0,
  provides_counter_headers TINYINT(1) NOT NULL DEFAULT 0,
  source VARCHAR(64) NOT NULL DEFAULT 'admin',
  confidence VARCHAR(32) NOT NULL DEFAULT 'manual',
  effective_from_utc DATETIME(3) NULL,
  effective_to_utc DATETIME(3) NULL,
  notes VARCHAR(512) NOT NULL DEFAULT '',
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_garmin_system_identifier_active (system_identifier, effective_to_utc),
  KEY idx_garmin_system_identifier_tail (tail_number),
  KEY idx_garmin_system_identifier_aircraft (aircraft_id),
  CONSTRAINT fk_garmin_system_identifier_aircraft
    FOREIGN KEY (aircraft_id) REFERENCES ipca_aircraft_devices(id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Manual/audited mapping from Garmin G3X system identifiers to aircraft tails.';

SET @table_name := 'ipca_garmin_system_identifier_mappings';

SET @has_device_identifier := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name AND COLUMN_NAME = 'device_identifier'
);
SET @sql := IF(@has_device_identifier = 0,
  'ALTER TABLE ipca_garmin_system_identifier_mappings ADD COLUMN device_identifier VARCHAR(64) NOT NULL DEFAULT '''' AFTER system_identifier',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_avionics_family := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name AND COLUMN_NAME = 'avionics_family'
);
SET @sql := IF(@has_avionics_family = 0,
  'ALTER TABLE ipca_garmin_system_identifier_mappings ADD COLUMN avionics_family VARCHAR(32) NOT NULL DEFAULT ''unknown'' AFTER tail_number',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_default_quality := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name AND COLUMN_NAME = 'default_quality'
);
SET @sql := IF(@has_default_quality = 0,
  'ALTER TABLE ipca_garmin_system_identifier_mappings ADD COLUMN default_quality VARCHAR(32) NOT NULL DEFAULT ''unknown'' AFTER avionics_family',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_full_avionics := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name AND COLUMN_NAME = 'provides_full_avionics'
);
SET @sql := IF(@has_full_avionics = 0,
  'ALTER TABLE ipca_garmin_system_identifier_mappings ADD COLUMN provides_full_avionics TINYINT(1) NOT NULL DEFAULT 0 AFTER default_quality',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_counter_headers := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name AND COLUMN_NAME = 'provides_counter_headers'
);
SET @sql := IF(@has_counter_headers = 0,
  'ALTER TABLE ipca_garmin_system_identifier_mappings ADD COLUMN provides_counter_headers TINYINT(1) NOT NULL DEFAULT 0 AFTER provides_full_avionics',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT INTO ipca_garmin_system_identifier_mappings
  (system_identifier, device_identifier, aircraft_id, tail_number, avionics_family, default_quality,
   provides_full_avionics, provides_counter_headers, source, confidence, notes)
SELECT m.system_identifier, m.device_identifier, a.id, m.tail_number, m.avionics_family, m.default_quality,
       m.provides_full_avionics, m.provides_counter_headers, 'verified_avionics', 'verified', m.notes
FROM (
  SELECT '60002CE173A4F' AS system_identifier, '' AS device_identifier, 'N392EA' AS tail_number, 'G3X' AS avionics_family, 'full_avionics' AS default_quality, 1 AS provides_full_avionics, 1 AS provides_counter_headers, 'Verified from GDU 460 system information screenshot.' AS notes
  UNION ALL SELECT '21D7CA03E', '', 'N392EA', 'legacy_garmin_flight_data', 'low_quality', 0, 0, 'Legacy Garmin flight data source matched to N392EA rental/logbook evidence; not counter-capable.'
  UNION ALL SELECT 'A31006335CF1D', '5GJ007528', 'N392EA', 'GNX375', 'gps_limited', 0, 0, 'Garmin Pilot/GNX375 source; GPS-limited, no G3X counter headers.'
  UNION ALL SELECT '60002CE0956A8', '', 'N397EA', 'G3X', 'full_avionics', 1, 1, 'Verified from GDU 460 system information screenshot.'
  UNION ALL SELECT 'A31006335B7A5', '5GJ007531', 'N397EA', 'GNX375', 'gps_limited', 0, 0, 'Garmin Pilot/GNX375 source; GPS-limited, no G3X counter headers.'
  UNION ALL SELECT '60002D0680804', '', 'N428EA', 'G3X', 'full_avionics', 1, 1, 'Verified from GDU 460 system information screenshot.'
  UNION ALL SELECT 'A31006388A838', '5GJ008864', 'N428EA', 'GNX375', 'gps_limited', 0, 0, 'Garmin Pilot/GNX375 source; GPS-limited, no G3X counter headers.'
  UNION ALL SELECT '257D7A0B6', '', 'N446CS', 'G1000_NXI', 'unknown_until_csv', 0, 0, 'C172SP G1000 NXi system; inspect CSV contents before treating as debrief/counter-capable.'
  UNION ALL SELECT '25F28C2A8', '', 'N641TH', 'G1000_NXI', 'unknown_until_csv', 0, 0, 'C172SP G1000 NXi system; inspect CSV contents before treating as debrief/counter-capable.'
) AS m
LEFT JOIN ipca_aircraft_devices a ON a.registration = m.tail_number
WHERE NOT EXISTS (
  SELECT 1 FROM ipca_garmin_system_identifier_mappings existing
  WHERE existing.system_identifier = m.system_identifier
    AND existing.effective_to_utc IS NULL
);
