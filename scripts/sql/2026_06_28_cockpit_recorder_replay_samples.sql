-- IPCA Cockpit Recorder - fixed-rate replay samples (v2).
-- Re-run safe: creates replay sample table only.
-- Apply: mysql ... < scripts/sql/2026_06_28_cockpit_recorder_replay_samples.sql

CREATE TABLE IF NOT EXISTS ipca_cockpit_replay_samples (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  recording_id         BIGINT UNSIGNED NOT NULL,
  sample_index         INT UNSIGNED NOT NULL,
  time_s               DECIMAL(12,3) NOT NULL DEFAULT 0,
  latitude             DECIMAL(11,7) NULL,
  longitude            DECIMAL(11,7) NULL,
  altitude_ft          DECIMAL(10,1) NULL,
  heading_deg          DECIMAL(7,2) NULL,
  pitch_deg            DECIMAL(7,2) NULL,
  roll_deg             DECIMAL(7,2) NULL,
  ground_speed_kt      DECIMAL(10,2) NULL,
  vertical_speed_fpm   DECIMAL(10,1) NULL,
  phase                VARCHAR(64) NULL,
  position_quality     VARCHAR(16) NOT NULL DEFAULT 'unknown',
  altitude_quality     VARCHAR(16) NOT NULL DEFAULT 'unknown',
  attitude_quality     VARCHAR(16) NOT NULL DEFAULT 'unknown',
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipca_cockpit_replay_samples_recording_index (recording_id, sample_index),
  KEY idx_ipca_cockpit_replay_samples_recording_time (recording_id, time_s),
  CONSTRAINT fk_ipca_cockpit_replay_samples_recording
    FOREIGN KEY (recording_id) REFERENCES ipca_cockpit_recordings(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Fixed-rate replay-optimized samples for Cesium playback.';
