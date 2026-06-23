-- IPCA Cockpit Recorder POC - transcription chunk metadata.
-- Re-run safe: creates the chunk tracking table if needed.
-- Apply: mysql ... < scripts/sql/2026_06_22_cockpit_recorder_transcription_chunks.sql

CREATE TABLE IF NOT EXISTS ipca_cockpit_recording_transcription_chunks (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  recording_id       BIGINT UNSIGNED NOT NULL,
  chunk_index        INT UNSIGNED NOT NULL,
  start_seconds      DECIMAL(12,3) NOT NULL DEFAULT 0,
  end_seconds        DECIMAL(12,3) NOT NULL DEFAULT 0,
  status             VARCHAR(32) NOT NULL DEFAULT 'queued'
                     COMMENT 'queued | transcribing | ready | failed',
  text_length        INT UNSIGNED NOT NULL DEFAULT 0,
  transcript_text    LONGTEXT NULL,
  error_message      TEXT NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipca_cockpit_tx_chunks_recording_index (recording_id, chunk_index),
  KEY idx_ipca_cockpit_tx_chunks_recording (recording_id, chunk_index),
  KEY idx_ipca_cockpit_tx_chunks_status (status, updated_at),
  CONSTRAINT fk_ipca_cockpit_tx_chunks_recording
    FOREIGN KEY (recording_id) REFERENCES ipca_cockpit_recordings(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cockpit Recorder POC - per-chunk transcription metadata.';
