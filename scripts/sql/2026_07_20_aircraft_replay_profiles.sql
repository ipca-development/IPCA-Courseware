-- Versioned aircraft replay/player presentation profiles.
-- Safe to re-run. These settings are presentation/config metadata and must not
-- be treated as stored replay facts.

CREATE TABLE IF NOT EXISTS ipca_aircraft_replay_profiles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  aircraft_id BIGINT UNSIGNED NULL,
  aircraft_model_code VARCHAR(32) NOT NULL DEFAULT '',
  profile_name VARCHAR(128) NOT NULL DEFAULT 'Default',
  version_number INT UNSIGNED NOT NULL DEFAULT 1,
  active TINYINT(1) NOT NULL DEFAULT 1,
  effective_from_utc DATETIME NULL,
  effective_to_utc DATETIME NULL,
  layout_config_json JSON NULL,
  instrument_override_json JSON NULL,
  trim_config_json JSON NULL,
  schema_version INT UNSIGNED NOT NULL DEFAULT 1,
  changed_by BIGINT UNSIGNED NULL,
  change_reason VARCHAR(512) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_ipca_aircraft_replay_profiles_aircraft (aircraft_id, active, version_number),
  KEY idx_ipca_aircraft_replay_profiles_model (aircraft_model_code, active),
  CONSTRAINT fk_ipca_aircraft_replay_profiles_aircraft
    FOREIGN KEY (aircraft_id) REFERENCES ipca_aircraft_devices(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Aircraft-specific replay/player presentation defaults and overrides.';
