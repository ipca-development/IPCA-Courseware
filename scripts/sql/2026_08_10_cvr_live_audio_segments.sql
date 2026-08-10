-- Immutable finalized audio segments uploaded while an Operational Session is active.

CREATE TABLE IF NOT EXISTS ipca_cvr_live_audio_segments (
  id                         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  segment_uuid               CHAR(36) NOT NULL,
  operational_session_uuid   CHAR(36) NOT NULL,
  workflow_flight_record_uuid CHAR(36) NOT NULL,
  recording_uid              VARCHAR(96) NOT NULL,
  segment_index              INT UNSIGNED NOT NULL,
  started_at                 DATETIME(3) NOT NULL,
  duration_seconds           DECIMAL(10,3) NOT NULL,
  storage_path               VARCHAR(512) NOT NULL,
  sha256                     CHAR(64) NOT NULL,
  file_size_bytes            BIGINT UNSIGNED NOT NULL,
  transcription_status       VARCHAR(32) NOT NULL DEFAULT 'queued',
  transcript_text            LONGTEXT NULL,
  provider_result_json       JSON NULL,
  transcription_error        TEXT NULL,
  uploaded_by_device_id      BIGINT UNSIGNED NULL,
  transcribed_at             DATETIME(3) NULL,
  created_at                 DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at                 DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_cvr_live_audio_segment_uuid (segment_uuid),
  UNIQUE KEY uk_ipca_cvr_live_audio_recording_index (recording_uid, segment_index),
  KEY idx_ipca_cvr_live_audio_session (operational_session_uuid, segment_index),
  KEY idx_ipca_cvr_live_audio_flight (workflow_flight_record_uuid, segment_index),
  KEY idx_ipca_cvr_live_audio_transcription (transcription_status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Immutable in-flight CVR audio segments and incremental server transcripts.';
