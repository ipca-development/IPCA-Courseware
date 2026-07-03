-- Canonical aircraft-specific instrument profiles for replay/PFD overlays.
-- Re-run safe.

CREATE TABLE IF NOT EXISTS ipca_aircraft_instrument_profiles (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  aircraft_model_code VARCHAR(32) NOT NULL,
  aircraft_model_name VARCHAR(128) NOT NULL DEFAULT '',
  profile_code        VARCHAR(64) NOT NULL DEFAULT 'default',
  display_name        VARCHAR(128) NOT NULL DEFAULT '',
  airspeed_config_json LONGTEXT NOT NULL,
  active              TINYINT(1) NOT NULL DEFAULT 1,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipca_aircraft_instrument_profiles_model_profile (aircraft_model_code, profile_code),
  KEY idx_ipca_aircraft_instrument_profiles_active (active, aircraft_model_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Aircraft-specific replay instrument markings and bugs.';

INSERT INTO ipca_aircraft_instrument_profiles (
  aircraft_model_code,
  aircraft_model_name,
  profile_code,
  display_name,
  airspeed_config_json,
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
  1
)
ON DUPLICATE KEY UPDATE
  aircraft_model_name = VALUES(aircraft_model_name),
  display_name = VALUES(display_name),
  airspeed_config_json = VALUES(airspeed_config_json),
  active = VALUES(active),
  updated_at = CURRENT_TIMESTAMP;
