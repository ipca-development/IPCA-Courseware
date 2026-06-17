-- IPCA Cockpit Recorder POC.
-- Re-run safe: creates the upload/transcription tracking table if needed.
-- Apply: mysql ... < scripts/sql/2026_06_17_cockpit_recorder_poc.sql

CREATE TABLE IF NOT EXISTS ipca_cockpit_recordings (
  id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  recording_uid            VARCHAR(96) NOT NULL,
  started_at               DATETIME NULL,
  duration_seconds         DECIMAL(12,3) NOT NULL DEFAULT 0,
  input_device             VARCHAR(255) NOT NULL DEFAULT '',
  language                 VARCHAR(16) NOT NULL DEFAULT 'en',
  upload_status            VARCHAR(32) NOT NULL DEFAULT 'pending'
                           COMMENT 'pending | uploading | uploaded | failed',
  transcription_status     VARCHAR(32) NOT NULL DEFAULT 'pending'
                           COMMENT 'pending | queued | transcribing | ready | failed',
  transcription_progress   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  original_filename        VARCHAR(255) NULL,
  mime_type                VARCHAR(128) NULL,
  file_extension           VARCHAR(16) NULL,
  file_size_bytes          BIGINT UNSIGNED NOT NULL DEFAULT 0,
  storage_path             VARCHAR(1024) NULL,
  transcript_text          LONGTEXT NULL,
  error_message            TEXT NULL,
  uploaded_at              DATETIME NULL,
  transcription_started_at DATETIME NULL,
  transcription_completed_at DATETIME NULL,
  created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipca_cockpit_recordings_uid (recording_uid),
  KEY idx_ipca_cockpit_recordings_started (started_at),
  KEY idx_ipca_cockpit_recordings_upload (upload_status, updated_at),
  KEY idx_ipca_cockpit_recordings_transcription (transcription_status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cockpit Recorder POC - uploaded iPad audio and stub transcription status.';
