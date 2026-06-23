-- IPCA Flight Intelligence Platform - Phase 1 reconstruction foundation.
-- Re-run safe: creates derived reconstruction tables and guarded status columns.
-- Apply: mysql ... < scripts/sql/2026_06_23_cockpit_recorder_reconstruction_foundation.sql

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_recordings'
    AND COLUMN_NAME = 'reconstruction_status'
);
SET @after_clause := IF((
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_recordings'
    AND COLUMN_NAME = 'health_analyzed_at'
) > 0, ' AFTER health_analyzed_at', ' AFTER updated_at');
SET @sql := IF(@col_exists = 0,
  CONCAT('ALTER TABLE ipca_cockpit_recordings ADD COLUMN reconstruction_status VARCHAR(32) NOT NULL DEFAULT ''not_started''', @after_clause),
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_recordings'
    AND COLUMN_NAME = 'timeline_status'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_recordings ADD COLUMN timeline_status VARCHAR(32) NOT NULL DEFAULT ''not_started'' AFTER reconstruction_status',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_recordings'
    AND COLUMN_NAME = 'adsb_status'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_recordings ADD COLUMN adsb_status VARCHAR(32) NOT NULL DEFAULT ''not_started'' AFTER timeline_status',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_recordings'
    AND COLUMN_NAME = 'reconstruction_summary_json'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_recordings ADD COLUMN reconstruction_summary_json LONGTEXT NULL AFTER adsb_status',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_cockpit_recordings'
    AND COLUMN_NAME = 'reconstructed_at'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_recordings ADD COLUMN reconstructed_at DATETIME NULL AFTER reconstruction_summary_json',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS ipca_cockpit_reconstruction_jobs (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  recording_id    BIGINT UNSIGNED NOT NULL,
  status          VARCHAR(32) NOT NULL DEFAULT 'pending',
  progress        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  error_message   TEXT NULL,
  started_at      DATETIME NULL,
  completed_at    DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_ipca_cockpit_recon_jobs_recording (recording_id, id),
  KEY idx_ipca_cockpit_recon_jobs_status (status, updated_at),
  CONSTRAINT fk_ipca_cockpit_recon_jobs_recording
    FOREIGN KEY (recording_id) REFERENCES ipca_cockpit_recordings(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cockpit reconstruction rebuild jobs.';

CREATE TABLE IF NOT EXISTS ipca_cockpit_flight_samples (
  id                         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  recording_id               BIGINT UNSIGNED NOT NULL,
  sample_index               INT UNSIGNED NOT NULL,
  sample_time_utc            DATETIME(3) NULL,
  seconds_since_start        DECIMAL(12,3) NOT NULL DEFAULT 0,
  source_mask                VARCHAR(32) NOT NULL DEFAULT '',
  latitude                   DECIMAL(11,7) NULL,
  longitude                  DECIMAL(11,7) NULL,
  gps_altitude_m             DECIMAL(10,2) NULL,
  gps_altitude_ft            DECIMAL(10,1) NULL,
  baro_altitude_ft           DECIMAL(10,1) NULL,
  vertical_speed_fpm         DECIMAL(10,1) NULL,
  groundspeed_kt             DECIMAL(10,2) NULL,
  magnetic_track_deg         DECIMAL(7,2) NULL,
  true_track_deg             DECIMAL(7,2) NULL,
  pitch_deg                  DECIMAL(7,2) NULL,
  roll_deg                   DECIMAL(7,2) NULL,
  yaw_deg                    DECIMAL(7,2) NULL,
  magnetic_heading_deg       DECIMAL(7,2) NULL,
  true_heading_deg           DECIMAL(7,2) NULL,
  acceleration_g             DECIMAL(8,3) NULL,
  wind_direction_deg         DECIMAL(7,2) NULL,
  wind_speed_kt              DECIMAL(7,2) NULL,
  heading_bug_deg            DECIMAL(7,2) NULL,
  altitude_bug_ft            DECIMAL(10,1) NULL,
  autopilot_status           VARCHAR(64) NULL,
  g3x_row_json               LONGTEXT NULL,
  created_at                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipca_cockpit_samples_recording_index (recording_id, sample_index),
  KEY idx_ipca_cockpit_samples_recording_time (recording_id, seconds_since_start),
  KEY idx_ipca_cockpit_samples_recording_utc (recording_id, sample_time_utc),
  CONSTRAINT fk_ipca_cockpit_samples_recording
    FOREIGN KEY (recording_id) REFERENCES ipca_cockpit_recordings(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Canonical replay samples derived from GPS, AHRS, and later ADS-B.';

CREATE TABLE IF NOT EXISTS ipca_cockpit_flight_phases (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  recording_id          BIGINT UNSIGNED NOT NULL,
  phase                 VARCHAR(64) NOT NULL,
  phase_order           INT UNSIGNED NOT NULL DEFAULT 0,
  start_seconds         DECIMAL(12,3) NOT NULL DEFAULT 0,
  end_seconds           DECIMAL(12,3) NOT NULL DEFAULT 0,
  confidence            DECIMAL(4,3) NOT NULL DEFAULT 0,
  summary_json          LONGTEXT NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_ipca_cockpit_phases_recording_order (recording_id, phase_order),
  KEY idx_ipca_cockpit_phases_recording_time (recording_id, start_seconds, end_seconds),
  CONSTRAINT fk_ipca_cockpit_phases_recording
    FOREIGN KEY (recording_id) REFERENCES ipca_cockpit_recordings(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Detected flight phases for reconstruction and replay.';

CREATE TABLE IF NOT EXISTS ipca_cockpit_timeline_events (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  recording_id          BIGINT UNSIGNED NOT NULL,
  event_type            VARCHAR(80) NOT NULL,
  phase                 VARCHAR(64) NOT NULL DEFAULT '',
  start_seconds         DECIMAL(12,3) NOT NULL DEFAULT 0,
  end_seconds           DECIMAL(12,3) NULL,
  confidence            DECIMAL(4,3) NOT NULL DEFAULT 0,
  evidence_json         LONGTEXT NULL,
  notes                 TEXT NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_ipca_cockpit_timeline_recording_time (recording_id, start_seconds),
  KEY idx_ipca_cockpit_timeline_recording_type (recording_id, event_type),
  CONSTRAINT fk_ipca_cockpit_timeline_recording
    FOREIGN KEY (recording_id) REFERENCES ipca_cockpit_recordings(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Chronological reconstruction events. No grading or ACS evaluation.';

CREATE TABLE IF NOT EXISTS ipca_cockpit_adsb_enrichments (
  id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  recording_id             BIGINT UNSIGNED NOT NULL,
  status                   VARCHAR(32) NOT NULL DEFAULT 'not_started'
                           COMMENT 'not_started | pending | fetching | processing | ready | failed | not_available',
  provider                 VARCHAR(64) NOT NULL DEFAULT 'adsbexchange',
  aircraft_hex             CHAR(6) NOT NULL DEFAULT '',
  query_start_utc          DATETIME NULL,
  query_end_utc            DATETIME NULL,
  raw_storage_path         VARCHAR(1024) NULL,
  normalized_storage_path  VARCHAR(1024) NULL,
  ownship_sample_count     INT UNSIGNED NOT NULL DEFAULT 0,
  traffic_sample_count     INT UNSIGNED NOT NULL DEFAULT 0,
  error_message            TEXT NULL,
  created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipca_cockpit_adsb_recording (recording_id),
  KEY idx_ipca_cockpit_adsb_status (status, updated_at),
  CONSTRAINT fk_ipca_cockpit_adsb_recording
    FOREIGN KEY (recording_id) REFERENCES ipca_cockpit_recordings(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='ADS-B enrichment status and storage pointers.';

CREATE TABLE IF NOT EXISTS ipca_cockpit_adsb_ownship_samples (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  recording_id          BIGINT UNSIGNED NOT NULL,
  sample_time_utc       DATETIME(3) NULL,
  seconds_since_start   DECIMAL(12,3) NOT NULL DEFAULT 0,
  latitude              DECIMAL(11,7) NULL,
  longitude             DECIMAL(11,7) NULL,
  baro_altitude_ft      DECIMAL(10,1) NULL,
  vertical_speed_fpm    DECIMAL(10,1) NULL,
  groundspeed_kt        DECIMAL(10,2) NULL,
  track_deg             DECIMAL(7,2) NULL,
  heading_deg           DECIMAL(7,2) NULL,
  on_ground             TINYINT(1) NULL,
  raw_json              LONGTEXT NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ipca_cockpit_adsb_ownship_recording_time (recording_id, seconds_since_start),
  CONSTRAINT fk_ipca_cockpit_adsb_ownship_recording
    FOREIGN KEY (recording_id) REFERENCES ipca_cockpit_recordings(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Normalized ownship ADS-B samples. Scaffold for later enrichment.';

CREATE TABLE IF NOT EXISTS ipca_cockpit_adsb_traffic_samples (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  recording_id          BIGINT UNSIGNED NOT NULL,
  sample_time_utc       DATETIME(3) NULL,
  seconds_since_start   DECIMAL(12,3) NOT NULL DEFAULT 0,
  aircraft_hex          CHAR(6) NOT NULL DEFAULT '',
  callsign              VARCHAR(32) NOT NULL DEFAULT '',
  latitude              DECIMAL(11,7) NULL,
  longitude             DECIMAL(11,7) NULL,
  altitude_ft           DECIMAL(10,1) NULL,
  groundspeed_kt        DECIMAL(10,2) NULL,
  track_deg             DECIMAL(7,2) NULL,
  distance_nm           DECIMAL(8,2) NULL,
  bearing_deg           DECIMAL(7,2) NULL,
  relative_altitude_ft  DECIMAL(10,1) NULL,
  raw_json              LONGTEXT NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ipca_cockpit_adsb_traffic_recording_time (recording_id, seconds_since_start),
  KEY idx_ipca_cockpit_adsb_traffic_hex (aircraft_hex),
  CONSTRAINT fk_ipca_cockpit_adsb_traffic_recording
    FOREIGN KEY (recording_id) REFERENCES ipca_cockpit_recordings(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Nearby traffic ADS-B samples. Scaffold for later enrichment.';
