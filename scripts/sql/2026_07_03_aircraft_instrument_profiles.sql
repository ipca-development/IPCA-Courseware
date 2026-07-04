-- Canonical aircraft-specific instrument profiles for replay/PFD overlays.
-- Re-run safe.

CREATE TABLE IF NOT EXISTS ipca_aircraft_instrument_profiles (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  aircraft_model_code VARCHAR(32) NOT NULL,
  aircraft_model_name VARCHAR(128) NOT NULL DEFAULT '',
  profile_code        VARCHAR(64) NOT NULL DEFAULT 'default',
  display_name        VARCHAR(128) NOT NULL DEFAULT '',
  airspeed_config_json LONGTEXT NOT NULL,
  engine_config_json   LONGTEXT NULL,
  active              TINYINT(1) NOT NULL DEFAULT 1,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipca_aircraft_instrument_profiles_model_profile (aircraft_model_code, profile_code),
  KEY idx_ipca_aircraft_instrument_profiles_active (active, aircraft_model_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Aircraft-specific replay instrument markings and bugs.';

SET @col_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_aircraft_instrument_profiles'
    AND COLUMN_NAME = 'engine_config_json'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_aircraft_instrument_profiles ADD COLUMN engine_config_json LONGTEXT NULL AFTER airspeed_config_json',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT INTO ipca_aircraft_instrument_profiles (
  aircraft_model_code,
  aircraft_model_name,
  profile_code,
  display_name,
  airspeed_config_json,
  engine_config_json,
  active
) VALUES (
  'PIAT',
  'Alpha Trainer Pro',
  'default',
  'PIAT Garmin G3X Airspeed',
  '{
    "aircraft_model_code": "PIAT",
    "aircraft_model_name": "Alpha Trainer Pro",
    "units": "KT",
    "source": "PIAT canonical airspeed markings",
    "tape_min_kt": 0,
    "tape_max_kt": 160,
    "white_arc": {"from_kt": 42, "to_kt": 70},
    "green_arc": {"from_kt": 49, "to_kt": 104},
    "yellow_arc": {"from_kt": 104, "to_kt": 130},
    "red_line_kt": 130,
    "bugs": [
      {"label": "R", "speed_kt": 50, "description": "Vr Rotation Speed"},
      {"label": "X", "speed_kt": 58, "description": "Vx Best Angle of Climb"},
      {"label": "G", "speed_kt": 68, "description": "Vg Best Glide"},
      {"label": "Y", "speed_kt": 75, "description": "Vy Best Rate of Climb"},
      {"label": "A", "speed_kt": 90, "description": "Va Design Maneuvering Speed"}
    ]
  }',
  '{
    "aircraft_model_code": "PIAT",
    "aircraft_model_name": "Alpha Trainer Pro",
    "source": "PIAT Garmin G3X engine markings",
    "instruments": [
      {"key":"rpm","label":"RPM","min":0,"max":6000,"kind":"arc","value_field":"rpm","decimals":0,"ranges":[{"color":"white","from":0,"to":1750},{"color":"green","from":1750,"to":5500},{"color":"yellow","from":5500,"to":5800},{"color":"red","from":5800,"to":6000}]},
      {"key":"fuel_flow_gph","label":"GPH","min":0,"max":7.9,"value_field":"fuel_flow_gph","decimals":1,"ranges":[{"color":"white","from":0,"to":1.3},{"color":"green","from":1.3,"to":6.6},{"color":"white","from":6.6,"to":7.9}]},
      {"key":"oil_pressure_psi","label":"OIL PSI","min":0,"max":113,"value_field":"oil_pressure_psi","decimals":0,"ranges":[{"color":"red","from":0,"to":12},{"color":"white","from":12,"to":29},{"color":"green","from":29,"to":73},{"color":"yellow","from":73,"to":102},{"color":"red","from":102,"to":113}]},
      {"key":"oil_temp_f","label":"OIL °F","min":104,"max":300,"value_field":"oil_temp_f","decimals":0,"ranges":[{"color":"black","from":104,"to":122},{"color":"white","from":122,"to":194},{"color":"green","from":194,"to":230},{"color":"white","from":230,"to":248},{"color":"yellow","from":248,"to":284},{"color":"red","from":284,"to":300}]},
      {"key":"egt1_f","label":"EGT °F","min":752,"max":1706,"value_field":"egt1_f","decimals":0,"probe_label":"1","ranges":[{"color":"white","from":752,"to":1022},{"color":"green","from":1022,"to":1589},{"color":"yellow","from":1589,"to":1616},{"color":"red","from":1616,"to":1706}]},
      {"key":"fuel_qty_gal","label":"FUEL GAL","min":0,"max":16,"value_field":"fuel_qty_gal","decimals":0,"ranges":[{"color":"yellow","from":0,"to":2},{"color":"green","from":2,"to":16}]},
      {"key":"fuel_pressure_psi","label":"FUEL PSI","min":0,"max":7.3,"value_field":"fuel_pressure_psi","decimals":1,"ranges":[{"color":"white","from":0,"to":2.2},{"color":"green","from":2.2,"to":5.8},{"color":"white","from":5.8,"to":7.3}]},
      {"key":"coolant1_f","label":"COOLANT °F","min":86,"max":266,"value_field":"coolant1_f","decimals":0,"probe_label":"1","ranges":[{"color":"white","from":86,"to":248},{"color":"yellow","from":null,"to":null},{"color":"red","from":248,"to":266}]},
      {"key":"coolant2_f","label":"","min":86,"max":266,"value_field":"coolant2_f","decimals":0,"probe_label":"2","ranges":[{"color":"white","from":86,"to":248},{"color":"yellow","from":null,"to":null},{"color":"red","from":248,"to":266}]},
      {"key":"volts","label":"VOLTS","min":11.5,"max":16,"value_field":"volts","decimals":1,"alert_style":"yellow_label","ranges":[{"color":"red","from":11.5,"to":12.8},{"color":"white","from":12.8,"to":13.2},{"color":"green","from":13.2,"to":14.6},{"color":"yellow","from":14.6,"to":15.5},{"color":"red","from":15.5,"to":16}]},
      {"key":"amps","label":"AMPS","min":-40,"max":40,"value_field":"amps","decimals":0,"kind":"ammeter","ranges":[{"color":"green_line","from":0,"to":20}]}
    ]
  }',
  1
)
ON DUPLICATE KEY UPDATE
  aircraft_model_name = VALUES(aircraft_model_name),
  display_name = VALUES(display_name),
  airspeed_config_json = VALUES(airspeed_config_json),
  engine_config_json = VALUES(engine_config_json),
  active = VALUES(active),
  updated_at = CURRENT_TIMESTAMP;
