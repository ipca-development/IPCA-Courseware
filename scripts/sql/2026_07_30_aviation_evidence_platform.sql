-- IPCA Aviation Evidence Platform — Phase 1 schema (FROZEN 2026-07-29)
-- Apply: mysql ... < scripts/sql/2026_07_30_aviation_evidence_platform.sql
-- Re-run safe: CREATE IF NOT EXISTS; ALTER guarded by information_schema checks where needed.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET collation_connection = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Provider model capabilities (from Phase 0 probe)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ipca_provider_model_capabilities (
  id                          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  provider                    VARCHAR(32) NOT NULL DEFAULT 'openai',
  model                       VARCHAR(64) NOT NULL,
  response_format             VARCHAR(32) NOT NULL DEFAULT 'json',
  supports_segment_timestamps TINYINT(1) NOT NULL DEFAULT 0,
  supports_word_timestamps    TINYINT(1) NOT NULL DEFAULT 0,
  supports_verbose_json       TINYINT(1) NOT NULL DEFAULT 0,
  supports_no_speech_prob     TINYINT(1) NOT NULL DEFAULT 0,
  supports_avg_logprob        TINYINT(1) NOT NULL DEFAULT 0,
  supports_compression_ratio  TINYINT(1) NOT NULL DEFAULT 0,
  supports_detected_language  TINYINT(1) NOT NULL DEFAULT 0,
  supports_usage_tokens       TINYINT(1) NOT NULL DEFAULT 0,
  notes                       TEXT NULL,
  probed_at                   DATETIME(3) NULL,
  created_at                  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at                  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_provider_model_cap (provider, model, response_format)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ipca_provider_model_capabilities
  (provider, model, response_format, supports_segment_timestamps, supports_word_timestamps,
   supports_verbose_json, supports_no_speech_prob, supports_avg_logprob, supports_compression_ratio,
   supports_detected_language, supports_usage_tokens, notes, probed_at)
VALUES
  ('openai', 'gpt-4o-transcribe', 'json', 0, 0, 0, 0, 0, 0, 0, 1,
   'Phase 0 probe: text + usage + request id only; verbose_json rejected HTTP 400', NOW(3)),
  ('openai', 'whisper-1', 'verbose_json', 1, 0, 1, 1, 1, 1, 1, 1,
   'Phase 0 probe: 71 segments; word timestamps unconfirmed (count 0)', NOW(3))
ON DUPLICATE KEY UPDATE
  supports_segment_timestamps = VALUES(supports_segment_timestamps),
  supports_word_timestamps = VALUES(supports_word_timestamps),
  supports_verbose_json = VALUES(supports_verbose_json),
  supports_no_speech_prob = VALUES(supports_no_speech_prob),
  supports_avg_logprob = VALUES(supports_avg_logprob),
  supports_compression_ratio = VALUES(supports_compression_ratio),
  supports_detected_language = VALUES(supports_detected_language),
  supports_usage_tokens = VALUES(supports_usage_tokens),
  notes = VALUES(notes),
  probed_at = VALUES(probed_at),
  updated_at = CURRENT_TIMESTAMP(3);

-- ---------------------------------------------------------------------------
-- Processing runs
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ipca_evidence_processing_runs (
  id                           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  run_uuid                     CHAR(36) NOT NULL,
  recording_id                 BIGINT UNSIGNED NOT NULL,
  parent_run_id                BIGINT UNSIGNED NULL,
  context_package_id           BIGINT UNSIGNED NULL,
  status                       VARCHAR(32) NOT NULL DEFAULT 'pending',
  canonical_timeline_source    VARCHAR(64) NOT NULL DEFAULT 'whisper_segment_timestamps',
  canonical_asr_model          VARCHAR(64) NOT NULL DEFAULT 'whisper-1',
  secondary_asr_model          VARCHAR(64) NULL,
  merge_algorithm_version      VARCHAR(32) NULL,
  speech_quality_version       VARCHAR(32) NULL,
  semantic_validation_version  VARCHAR(32) NULL,
  readability_version          VARCHAR(32) NULL,
  created_by                   BIGINT UNSIGNED NULL,
  created_at                   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  completed_at                 DATETIME(3) NULL,
  UNIQUE KEY uk_ipca_evidence_processing_runs_uuid (run_uuid),
  KEY idx_ipca_evidence_processing_runs_recording (recording_id, created_at),
  KEY idx_ipca_evidence_processing_runs_status (status, created_at),
  CONSTRAINT fk_ipca_evidence_processing_runs_recording
    FOREIGN KEY (recording_id) REFERENCES ipca_cockpit_recordings(id) ON DELETE CASCADE,
  CONSTRAINT fk_ipca_evidence_processing_runs_parent
    FOREIGN KEY (parent_run_id) REFERENCES ipca_evidence_processing_runs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Pass 0 context packages
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ipca_recording_context_packages (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  package_uuid    CHAR(36) NOT NULL,
  recording_id    BIGINT UNSIGNED NOT NULL,
  context_json    JSON NOT NULL,
  context_hash    CHAR(64) NOT NULL,
  created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_recording_context_packages_uuid (package_uuid),
  KEY idx_ipca_recording_context_packages_recording (recording_id, created_at),
  CONSTRAINT fk_ipca_recording_context_packages_recording
    FOREIGN KEY (recording_id) REFERENCES ipca_cockpit_recordings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Knowledge Engine (packs separate from correction evidence)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ipca_knowledge_packs (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  slug         VARCHAR(96) NOT NULL,
  title        VARCHAR(255) NOT NULL,
  pack_type    VARCHAR(64) NOT NULL,
  scope_type   VARCHAR(64) NOT NULL DEFAULT 'organization',
  status       VARCHAR(32) NOT NULL DEFAULT 'draft',
  created_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_knowledge_packs_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_knowledge_pack_versions (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  knowledge_pack_id BIGINT UNSIGNED NOT NULL,
  version_number    INT UNSIGNED NOT NULL,
  status            VARCHAR(32) NOT NULL DEFAULT 'draft',
  published_at      DATETIME(3) NULL,
  created_at        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_knowledge_pack_versions (knowledge_pack_id, version_number),
  CONSTRAINT fk_ipca_knowledge_pack_versions_pack
    FOREIGN KEY (knowledge_pack_id) REFERENCES ipca_knowledge_packs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_knowledge_pack_entries (
  id                        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  knowledge_pack_version_id BIGINT UNSIGNED NOT NULL,
  entry_type                VARCHAR(64) NOT NULL,
  canonical_term            VARCHAR(255) NOT NULL,
  display_form              VARCHAR(255) NULL,
  phonetic_form             VARCHAR(255) NULL,
  variants_json             JSON NULL,
  context_requirements_json JSON NULL,
  confidence                DECIMAL(5,4) NULL,
  evidence_count            INT UNSIGNED NOT NULL DEFAULT 0,
  rejection_count           INT UNSIGNED NOT NULL DEFAULT 0,
  created_at                DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY idx_ipca_knowledge_pack_entries_version (knowledge_pack_version_id, entry_type),
  CONSTRAINT fk_ipca_knowledge_pack_entries_version
    FOREIGN KEY (knowledge_pack_version_id) REFERENCES ipca_knowledge_pack_versions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_knowledge_pack_run_bindings (
  id                        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  processing_run_id         BIGINT UNSIGNED NOT NULL,
  knowledge_pack_id         BIGINT UNSIGNED NOT NULL,
  knowledge_pack_version_id BIGINT UNSIGNED NOT NULL,
  binding_reason            VARCHAR(255) NULL,
  created_at                DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_knowledge_pack_run_bindings (processing_run_id, knowledge_pack_version_id),
  CONSTRAINT fk_ipca_knowledge_pack_run_bindings_run
    FOREIGN KEY (processing_run_id) REFERENCES ipca_evidence_processing_runs(id) ON DELETE CASCADE,
  CONSTRAINT fk_ipca_knowledge_pack_run_bindings_pack
    FOREIGN KEY (knowledge_pack_id) REFERENCES ipca_knowledge_packs(id) ON DELETE CASCADE,
  CONSTRAINT fk_ipca_knowledge_pack_run_bindings_version
    FOREIGN KEY (knowledge_pack_version_id) REFERENCES ipca_knowledge_pack_versions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_knowledge_correction_evidence (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  correction_uuid      CHAR(36) NOT NULL,
  recording_id         BIGINT UNSIGNED NOT NULL,
  speech_segment_id    BIGINT UNSIGNED NULL,
  raw_text             TEXT NOT NULL,
  corrected_text       TEXT NOT NULL,
  scope_type           VARCHAR(64) NOT NULL,
  scope_ref            VARCHAR(128) NULL,
  start_time_ms        INT UNSIGNED NULL,
  end_time_ms          INT UNSIGNED NULL,
  audio_reviewed       TINYINT(1) NOT NULL DEFAULT 0,
  status               VARCHAR(32) NOT NULL DEFAULT 'proposed',
  promoted_pack_entry_id BIGINT UNSIGNED NULL,
  reviewer_user_id     BIGINT UNSIGNED NULL,
  created_at           DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_knowledge_correction_evidence_uuid (correction_uuid),
  KEY idx_ipca_knowledge_correction_evidence_recording (recording_id, status),
  CONSTRAINT fk_ipca_knowledge_correction_evidence_recording
    FOREIGN KEY (recording_id) REFERENCES ipca_cockpit_recordings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Audio chunks (immutable metadata)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ipca_evidence_audio_chunks (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  recording_id        BIGINT UNSIGNED NOT NULL,
  processing_run_id   BIGINT UNSIGNED NOT NULL,
  chunk_index         INT UNSIGNED NOT NULL,
  start_time_ms       INT UNSIGNED NOT NULL,
  end_time_ms         INT UNSIGNED NOT NULL,
  audio_sha256        CHAR(64) NOT NULL,
  byte_length         BIGINT UNSIGNED NULL,
  sample_rate         INT UNSIGNED NULL,
  channels            TINYINT UNSIGNED NULL,
  rms_db              DECIMAL(8,3) NULL,
  clipping_pct        DECIMAL(6,3) NULL,
  vad_coverage_pct    DECIMAL(6,3) NULL,
  created_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_evidence_audio_chunks_run_index (processing_run_id, chunk_index),
  KEY idx_ipca_evidence_audio_chunks_recording (recording_id, chunk_index),
  CONSTRAINT fk_ipca_evidence_audio_chunks_recording
    FOREIGN KEY (recording_id) REFERENCES ipca_cockpit_recordings(id) ON DELETE CASCADE,
  CONSTRAINT fk_ipca_evidence_audio_chunks_run
    FOREIGN KEY (processing_run_id) REFERENCES ipca_evidence_processing_runs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Provider runs (immutable)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ipca_evidence_provider_runs (
  id                            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  provider_run_uuid             CHAR(36) NOT NULL,
  probe_execution_uuid          CHAR(36) NULL,
  probe_label                   VARCHAR(64) NULL,
  audio_chunk_id                BIGINT UNSIGNED NOT NULL,
  processing_run_id             BIGINT UNSIGNED NOT NULL,
  parent_provider_run_id        BIGINT UNSIGNED NULL,
  matching_response_provider_run_id BIGINT UNSIGNED NULL,
  idempotency_key               VARCHAR(128) NOT NULL,
  run_purpose                   VARCHAR(64) NOT NULL DEFAULT 'initial_transcription',
  provider                      VARCHAR(32) NOT NULL DEFAULT 'openai',
  model                         VARCHAR(64) NOT NULL,
  provider_reported_model       VARCHAR(64) NULL,
  response_format               VARCHAR(32) NOT NULL DEFAULT 'json',
  request_config_hash           CHAR(64) NOT NULL,
  request_config_json           JSON NOT NULL,
  prompt_hash                   CHAR(64) NULL,
  prompt_text                   TEXT NULL,
  language_forced               TINYINT(1) NOT NULL DEFAULT 0,
  language_code                 VARCHAR(16) NULL,
  previous_text_used            TINYINT(1) NOT NULL DEFAULT 0,
  timestamp_granularities_json  JSON NULL,
  openai_request_id             VARCHAR(128) NULL,
  http_status                   SMALLINT UNSIGNED NULL,
  success_status                VARCHAR(32) NOT NULL DEFAULT 'unknown',
  error_type                    VARCHAR(64) NULL,
  error_message                 TEXT NULL,
  raw_response_json             LONGTEXT NOT NULL,
  response_sha256               CHAR(64) NOT NULL,
  returned_text                 LONGTEXT NULL,
  source_audio_sha256           CHAR(64) NULL,
  chunk_audio_sha256            CHAR(64) NULL,
  chunk_start_time_ms           INT UNSIGNED NULL,
  chunk_duration_ms             INT UNSIGNED NULL,
  source_audio_duration_ms      INT UNSIGNED NULL,
  transcription_duration_ms     INT UNSIGNED NULL,
  request_started_at            DATETIME(3) NULL,
  request_completed_at          DATETIME(3) NULL,
  latency_ms                    INT UNSIGNED NULL,
  usage_json                    JSON NULL,
  capability_observations_json  JSON NULL,
  evidence_files_json           JSON NULL,
  code_version                  VARCHAR(64) NULL,
  is_canonical_timeline         TINYINT(1) NOT NULL DEFAULT 0,
  retry_count                   INT UNSIGNED NOT NULL DEFAULT 0,
  worker_id                     VARCHAR(128) NULL,
  created_at                    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_evidence_provider_runs_uuid (provider_run_uuid),
  UNIQUE KEY uk_ipca_evidence_provider_runs_idempotency (idempotency_key),
  UNIQUE KEY uk_ipca_evidence_provider_runs_openai_req (openai_request_id),
  KEY idx_ipca_evidence_provider_runs_chunk (audio_chunk_id, created_at),
  KEY idx_ipca_evidence_provider_runs_probe_exec (probe_execution_uuid, probe_label),
  KEY idx_ipca_evidence_provider_runs_response_hash (response_sha256),
  KEY idx_ipca_evidence_provider_runs_model (model, response_format),
  CONSTRAINT fk_ipca_evidence_provider_runs_chunk
    FOREIGN KEY (audio_chunk_id) REFERENCES ipca_evidence_audio_chunks(id) ON DELETE CASCADE,
  CONSTRAINT fk_ipca_evidence_provider_runs_processing
    FOREIGN KEY (processing_run_id) REFERENCES ipca_evidence_processing_runs(id) ON DELETE CASCADE,
  CONSTRAINT fk_ipca_evidence_provider_runs_parent
    FOREIGN KEY (parent_provider_run_id) REFERENCES ipca_evidence_provider_runs(id) ON DELETE SET NULL,
  CONSTRAINT fk_ipca_evidence_provider_runs_matching
    FOREIGN KEY (matching_response_provider_run_id) REFERENCES ipca_evidence_provider_runs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Direct provider observations (not interpretations)
CREATE TABLE IF NOT EXISTS ipca_evidence_provider_observations (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  provider_run_id   BIGINT UNSIGNED NOT NULL,
  observation_key   VARCHAR(128) NOT NULL,
  observation_type  VARCHAR(32) NOT NULL DEFAULT 'string',
  value_boolean     TINYINT(1) NULL,
  value_integer     BIGINT NULL,
  value_string      VARCHAR(512) NULL,
  value_json        JSON NULL,
  created_at        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_evidence_provider_obs (provider_run_id, observation_key),
  KEY idx_ipca_evidence_provider_obs_key (observation_key),
  CONSTRAINT fk_ipca_evidence_provider_obs_run
    FOREIGN KEY (provider_run_id) REFERENCES ipca_evidence_provider_runs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Provider segments (nullable observations per Phase 0)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ipca_evidence_provider_segments (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  provider_run_id       BIGINT UNSIGNED NOT NULL,
  segment_index         INT UNSIGNED NOT NULL,
  provider_segment_id   INT NULL,
  seek_ms               INT UNSIGNED NULL,
  start_time_ms         INT UNSIGNED NOT NULL,
  end_time_ms           INT UNSIGNED NOT NULL,
  text                  TEXT NOT NULL,
  temperature           DECIMAL(6,3) NULL,
  avg_log_probability   DECIMAL(8,5) NULL,
  compression_ratio     DECIMAL(8,5) NULL,
  no_speech_probability DECIMAL(8,5) NULL,
  tokens_json           JSON NULL,
  transient_flag        TINYINT(1) NULL,
  created_at            DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_evidence_provider_segments_run_index (provider_run_id, segment_index),
  KEY idx_ipca_evidence_provider_segments_time (provider_run_id, start_time_ms),
  CONSTRAINT fk_ipca_evidence_provider_segments_run
    FOREIGN KEY (provider_run_id) REFERENCES ipca_evidence_provider_runs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Provider words (optional — word timestamps unconfirmed for whisper-1 probe)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ipca_evidence_provider_words (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  provider_segment_id BIGINT UNSIGNED NOT NULL,
  word_index          INT UNSIGNED NOT NULL,
  text                VARCHAR(255) NOT NULL,
  start_time_ms       INT UNSIGNED NULL,
  end_time_ms         INT UNSIGNED NULL,
  confidence          DECIMAL(8,5) NULL,
  created_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_evidence_provider_words_seg_index (provider_segment_id, word_index),
  CONSTRAINT fk_ipca_evidence_provider_words_segment
    FOREIGN KEY (provider_segment_id) REFERENCES ipca_evidence_provider_segments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Canonical speech segments
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ipca_evidence_speech_segments (
  id                         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  recording_id               BIGINT UNSIGNED NOT NULL,
  processing_run_id          BIGINT UNSIGNED NOT NULL,
  primary_provider_segment_id BIGINT UNSIGNED NULL,
  primary_provider_run_id    BIGINT UNSIGNED NULL,
  start_time_ms              INT UNSIGNED NOT NULL,
  end_time_ms                INT UNSIGNED NOT NULL,
  detected_language          VARCHAR(16) NULL,
  language_confidence        DECIMAL(5,4) NULL,
  created_at                 DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY idx_ipca_evidence_speech_segments_recording (recording_id, start_time_ms),
  KEY idx_ipca_evidence_speech_segments_run (processing_run_id, start_time_ms),
  CONSTRAINT fk_ipca_evidence_speech_segments_recording
    FOREIGN KEY (recording_id) REFERENCES ipca_cockpit_recordings(id) ON DELETE CASCADE,
  CONSTRAINT fk_ipca_evidence_speech_segments_run
    FOREIGN KEY (processing_run_id) REFERENCES ipca_evidence_processing_runs(id) ON DELETE CASCADE,
  CONSTRAINT fk_ipca_evidence_speech_segments_provider_seg
    FOREIGN KEY (primary_provider_segment_id) REFERENCES ipca_evidence_provider_segments(id) ON DELETE SET NULL,
  CONSTRAINT fk_ipca_evidence_speech_segments_provider_run
    FOREIGN KEY (primary_provider_run_id) REFERENCES ipca_evidence_provider_runs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Interpretation revisions (never overwrite)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ipca_evidence_interpretation_revisions (
  id                          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  speech_segment_id           BIGINT UNSIGNED NOT NULL,
  layer                       VARCHAR(32) NOT NULL,
  revision_number             INT UNSIGNED NOT NULL,
  supersedes_interpretation_id BIGINT UNSIGNED NULL,
  text                        TEXT NOT NULL,
  valid_from                  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  invalidated_at              DATETIME(3) NULL,
  stale_status                VARCHAR(32) NOT NULL DEFAULT 'current',
  recalculation_status        VARCHAR(32) NOT NULL DEFAULT 'none',
  calculated_confidence       DECIMAL(5,4) NULL,
  confidence_algorithm_version VARCHAR(32) NULL,
  human_reviewed              TINYINT(1) NOT NULL DEFAULT 0,
  human_review_weight         DECIMAL(5,4) NULL,
  recalculation_timestamp     DATETIME(3) NULL,
  reasoning_json              JSON NULL,
  created_by                  BIGINT UNSIGNED NULL,
  created_at                  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_evidence_interp_rev (speech_segment_id, layer, revision_number),
  KEY idx_ipca_evidence_interp_stale (stale_status, recalculation_status),
  CONSTRAINT fk_ipca_evidence_interp_speech
    FOREIGN KEY (speech_segment_id) REFERENCES ipca_evidence_speech_segments(id) ON DELETE CASCADE,
  CONSTRAINT fk_ipca_evidence_interp_supersedes
    FOREIGN KEY (supersedes_interpretation_id) REFERENCES ipca_evidence_interpretation_revisions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_evidence_interpretation_confidence_factors (
  id                          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  interpretation_revision_id  BIGINT UNSIGNED NOT NULL,
  factor_type                 VARCHAR(16) NOT NULL,
  source_type                 VARCHAR(64) NOT NULL,
  source_id                   BIGINT UNSIGNED NULL,
  weight                      DECIMAL(6,4) NOT NULL,
  description                 VARCHAR(512) NOT NULL,
  created_at                  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY idx_ipca_evidence_interp_factors_rev (interpretation_revision_id),
  CONSTRAINT fk_ipca_evidence_interp_factors_rev
    FOREIGN KEY (interpretation_revision_id) REFERENCES ipca_evidence_interpretation_revisions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Evidence graph edges (relationships only)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ipca_evidence_graph_edges (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  from_type    VARCHAR(64) NOT NULL,
  from_id      BIGINT UNSIGNED NOT NULL,
  to_type      VARCHAR(64) NOT NULL,
  to_id        BIGINT UNSIGNED NOT NULL,
  edge_type    VARCHAR(32) NOT NULL,
  metadata_json JSON NULL,
  created_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY idx_ipca_evidence_graph_edges_from (from_type, from_id),
  KEY idx_ipca_evidence_graph_edges_to (to_type, to_id),
  KEY idx_ipca_evidence_graph_edges_type (edge_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Suppressions (traceable)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ipca_evidence_suppressions (
  id                          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  processing_run_id           BIGINT UNSIGNED NOT NULL,
  speech_segment_id           BIGINT UNSIGNED NULL,
  interpretation_revision_id  BIGINT UNSIGNED NULL,
  suppression_type            VARCHAR(64) NOT NULL,
  reason                      VARCHAR(255) NOT NULL,
  retained_segment_id         BIGINT UNSIGNED NULL,
  suppressed_text             TEXT NULL,
  created_at                  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY idx_ipca_evidence_suppressions_run (processing_run_id),
  CONSTRAINT fk_ipca_evidence_suppressions_run
    FOREIGN KEY (processing_run_id) REFERENCES ipca_evidence_processing_runs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Display blocks & chapters
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ipca_evidence_display_blocks (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  recording_id      BIGINT UNSIGNED NOT NULL,
  processing_run_id BIGINT UNSIGNED NOT NULL,
  start_time_ms     INT UNSIGNED NOT NULL,
  end_time_ms       INT UNSIGNED NOT NULL,
  speech_segment_ids_json JSON NOT NULL,
  created_at        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY idx_ipca_evidence_display_blocks_recording (recording_id, start_time_ms),
  CONSTRAINT fk_ipca_evidence_display_blocks_recording
    FOREIGN KEY (recording_id) REFERENCES ipca_cockpit_recordings(id) ON DELETE CASCADE,
  CONSTRAINT fk_ipca_evidence_display_blocks_run
    FOREIGN KEY (processing_run_id) REFERENCES ipca_evidence_processing_runs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_evidence_chapters (
  id                          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  recording_id                BIGINT UNSIGNED NOT NULL,
  processing_run_id           BIGINT UNSIGNED NOT NULL,
  title                       VARCHAR(255) NOT NULL,
  category                    VARCHAR(64) NULL,
  start_time_ms               INT UNSIGNED NOT NULL,
  end_time_ms                 INT UNSIGNED NOT NULL,
  calculated_confidence       DECIMAL(5,4) NULL,
  confidence_algorithm_version VARCHAR(32) NULL,
  manually_edited             TINYINT(1) NOT NULL DEFAULT 0,
  supporting_segment_ids_json JSON NULL,
  supporting_event_ids_json   JSON NULL,
  created_at                  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY idx_ipca_evidence_chapters_recording (recording_id, start_time_ms),
  CONSTRAINT fk_ipca_evidence_chapters_recording
    FOREIGN KEY (recording_id) REFERENCES ipca_cockpit_recordings(id) ON DELETE CASCADE,
  CONSTRAINT fk_ipca_evidence_chapters_run
    FOREIGN KEY (processing_run_id) REFERENCES ipca_evidence_processing_runs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Published transcript snapshots (immutable)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ipca_evidence_published_transcript_versions (
  id                              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  version_uuid                    CHAR(36) NOT NULL,
  recording_id                    BIGINT UNSIGNED NOT NULL,
  processing_run_id               BIGINT UNSIGNED NOT NULL,
  published_at                    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  published_by                    BIGINT UNSIGNED NULL,
  interpretation_revision_ids_json JSON NOT NULL,
  knowledge_pack_version_ids_json JSON NULL,
  snapshot_json                   JSON NOT NULL,
  legacy_transcript_cache_text    LONGTEXT NULL,
  created_at                      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_evidence_published_transcript_versions_uuid (version_uuid),
  KEY idx_ipca_evidence_published_transcript_recording (recording_id, published_at),
  CONSTRAINT fk_ipca_evidence_published_transcript_recording
    FOREIGN KEY (recording_id) REFERENCES ipca_cockpit_recordings(id) ON DELETE CASCADE,
  CONSTRAINT fk_ipca_evidence_published_transcript_run
    FOREIGN KEY (processing_run_id) REFERENCES ipca_evidence_processing_runs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Extend ipca_cockpit_recordings (legacy cache + evidence FKs)
-- ---------------------------------------------------------------------------
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_cockpit_recordings' AND COLUMN_NAME = 'source_audio_sha256'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_recordings ADD COLUMN source_audio_sha256 CHAR(64) NULL AFTER storage_path',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_cockpit_recordings' AND COLUMN_NAME = 'current_processing_run_id'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_recordings ADD COLUMN current_processing_run_id BIGINT UNSIGNED NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_cockpit_recordings' AND COLUMN_NAME = 'published_transcript_version_id'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_recordings ADD COLUMN published_transcript_version_id BIGINT UNSIGNED NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_cockpit_recordings' AND COLUMN_NAME = 'transcript_cache_generated_at'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_cockpit_recordings ADD COLUMN transcript_cache_generated_at DATETIME(3) NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- FK for knowledge correction → speech segment (deferred until table exists)
SET @fk_exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_knowledge_correction_evidence'
    AND CONSTRAINT_NAME = 'fk_ipca_knowledge_correction_evidence_speech'
);
SET @sql := IF(@fk_exists = 0,
  'ALTER TABLE ipca_knowledge_correction_evidence ADD CONSTRAINT fk_ipca_knowledge_correction_evidence_speech FOREIGN KEY (speech_segment_id) REFERENCES ipca_evidence_speech_segments(id) ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Link processing run → context package
SET @fk_exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_evidence_processing_runs'
    AND CONSTRAINT_NAME = 'fk_ipca_evidence_processing_runs_context'
);
SET @sql := IF(@fk_exists = 0,
  'ALTER TABLE ipca_evidence_processing_runs ADD CONSTRAINT fk_ipca_evidence_processing_runs_context FOREIGN KEY (context_package_id) REFERENCES ipca_recording_context_packages(id) ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Schema version registry
CREATE TABLE IF NOT EXISTS ipca_evidence_schema_versions (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  version         VARCHAR(32) NOT NULL,
  migration_file  VARCHAR(255) NOT NULL,
  applied_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  notes           TEXT NULL,
  UNIQUE KEY uk_ipca_evidence_schema_versions (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ipca_evidence_schema_versions (version, migration_file, notes)
VALUES ('2026.07.30', 'scripts/sql/2026_07_30_aviation_evidence_platform.sql', 'Phase 1 frozen schema with provider observations')
ON DUPLICATE KEY UPDATE applied_at = CURRENT_TIMESTAMP(3), notes = VALUES(notes);

-- Processing run lifecycle (heartbeat lease — no silent stale-worker deletion)
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_evidence_processing_runs' AND COLUMN_NAME = 'heartbeat_at'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_evidence_processing_runs ADD COLUMN heartbeat_at DATETIME(3) NULL AFTER completed_at',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_evidence_processing_runs' AND COLUMN_NAME = 'current_phase'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_evidence_processing_runs ADD COLUMN current_phase VARCHAR(64) NULL AFTER heartbeat_at',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_evidence_processing_runs' AND COLUMN_NAME = 'failure_reason'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE ipca_evidence_processing_runs ADD COLUMN failure_reason VARCHAR(512) NULL AFTER current_phase',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO ipca_evidence_schema_versions (version, migration_file, notes)
VALUES ('2026.07.30.1', 'scripts/sql/2026_07_30_aviation_evidence_platform.sql', 'Processing run heartbeat lifecycle columns')
ON DUPLICATE KEY UPDATE applied_at = CURRENT_TIMESTAMP(3), notes = VALUES(notes);
