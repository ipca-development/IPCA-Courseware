-- IPCA CVR / Flight Workflow Phase 1 foundation.
-- Additive only. Does not alter protected cockpit recorder audio/replay tables.
-- Target runtime confirmed: MySQL 8.0.45, InnoDB, ANSI_QUOTES enabled.

CREATE TABLE IF NOT EXISTS ipca_audit_events (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  audit_uuid           CHAR(36) NOT NULL,
  request_uuid         CHAR(36) NULL,
  organization_id      BIGINT UNSIGNED NOT NULL DEFAULT 1,
  actor_type           VARCHAR(32) NOT NULL DEFAULT 'system',
  actor_user_id        BIGINT UNSIGNED NULL,
  actor_device_id      BIGINT UNSIGNED NULL,
  action               VARCHAR(96) NOT NULL,
  entity_type          VARCHAR(96) NOT NULL,
  entity_id            VARCHAR(128) NOT NULL DEFAULT '',
  before_json          JSON NULL,
  after_json           JSON NULL,
  reason               VARCHAR(512) NULL,
  source               VARCHAR(64) NOT NULL DEFAULT 'system',
  ip_address           VARCHAR(64) NULL,
  user_agent           VARCHAR(255) NULL,
  created_at           DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_audit_events_uuid (audit_uuid),
  UNIQUE KEY uk_ipca_audit_events_request (request_uuid),
  KEY idx_ipca_audit_events_entity (entity_type, entity_id, created_at),
  KEY idx_ipca_audit_events_actor (actor_type, actor_user_id, actor_device_id, created_at),
  KEY idx_ipca_audit_events_org_time (organization_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Shared immutable audit events for CVR and flight workflow.';

CREATE TABLE IF NOT EXISTS ipca_validation_results (
  id                              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  validation_uuid                 CHAR(36) NOT NULL,
  organization_id                 BIGINT UNSIGNED NOT NULL DEFAULT 1,
  entity_type                     VARCHAR(96) NOT NULL,
  entity_id                       VARCHAR(128) NOT NULL,
  affected_field                  VARCHAR(128) NULL,
  machine_code                    VARCHAR(128) NOT NULL,
  severity                        VARCHAR(32) NOT NULL,
  human_explanation               TEXT NOT NULL,
  evidence_json                   JSON NULL,
  user_acknowledgement_allowed    TINYINT(1) NOT NULL DEFAULT 0,
  resolver_role                   VARCHAR(64) NULL,
  blocks_operational_finalization TINYINT(1) NOT NULL DEFAULT 0,
  blocks_logbook_acceptance       TINYINT(1) NOT NULL DEFAULT 0,
  blocks_dispatch                 TINYINT(1) NOT NULL DEFAULT 0,
  status                          VARCHAR(32) NOT NULL DEFAULT 'open',
  resolved_by                     BIGINT UNSIGNED NULL,
  resolved_at                     DATETIME(3) NULL,
  resolution_note                 TEXT NULL,
  created_at                      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at                      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_validation_results_uuid (validation_uuid),
  KEY idx_ipca_validation_results_entity (entity_type, entity_id, severity, status),
  KEY idx_ipca_validation_results_org_status (organization_id, status, severity),
  CONSTRAINT chk_ipca_validation_results_severity
    CHECK (severity IN ('INFO','WARNING','REVIEW_REQUIRED','BLOCKING','INVALID'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Shared validation findings with consistent severity semantics.';

CREATE TABLE IF NOT EXISTS ipca_cvr_devices (
  id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  device_uuid              CHAR(36) NOT NULL,
  organization_id          BIGINT UNSIGNED NOT NULL DEFAULT 1,
  aircraft_id              BIGINT UNSIGNED NULL,
  aircraft_registration    VARCHAR(32) NOT NULL DEFAULT '',
  display_name             VARCHAR(128) NOT NULL DEFAULT '',
  mdm_provider             VARCHAR(64) NOT NULL DEFAULT 'jamf_now',
  mdm_device_identifier    VARCHAR(128) NULL,
  active                   TINYINT(1) NOT NULL DEFAULT 1,
  revoked_at               DATETIME(3) NULL,
  last_seen_at             DATETIME(3) NULL,
  created_at               DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at               DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_cvr_devices_uuid (device_uuid),
  KEY idx_ipca_cvr_devices_aircraft (aircraft_id, active),
  KEY idx_ipca_cvr_devices_registration (aircraft_registration, active),
  CONSTRAINT fk_ipca_cvr_devices_aircraft
    FOREIGN KEY (aircraft_id) REFERENCES ipca_aircraft_devices(id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Dedicated aircraft iPhone CVR devices.';

CREATE TABLE IF NOT EXISTS ipca_cvr_device_credentials (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  credential_uuid       CHAR(36) NOT NULL,
  device_id             BIGINT UNSIGNED NOT NULL,
  token_hash            CHAR(64) NOT NULL,
  label                 VARCHAR(128) NOT NULL DEFAULT '',
  issued_at             DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  expires_at            DATETIME(3) NULL,
  rotated_from_id       BIGINT UNSIGNED NULL,
  revoked_at            DATETIME(3) NULL,
  last_used_at          DATETIME(3) NULL,
  created_at            DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_cvr_device_credentials_uuid (credential_uuid),
  UNIQUE KEY uk_ipca_cvr_device_credentials_hash (token_hash),
  KEY idx_ipca_cvr_device_credentials_device (device_id, revoked_at, expires_at),
  CONSTRAINT fk_ipca_cvr_device_credentials_device
    FOREIGN KEY (device_id) REFERENCES ipca_cvr_devices(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Hashed bearer credentials for aircraft iPhone CVR devices.';

CREATE TABLE IF NOT EXISTS ipca_cvr_device_enrollments (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  enrollment_uuid        CHAR(36) NOT NULL,
  organization_id        BIGINT UNSIGNED NOT NULL DEFAULT 1,
  aircraft_id            BIGINT UNSIGNED NULL,
  aircraft_registration  VARCHAR(32) NOT NULL DEFAULT '',
  enrollment_code_hash   CHAR(64) NOT NULL,
  status                 VARCHAR(32) NOT NULL DEFAULT 'pending',
  expires_at             DATETIME(3) NOT NULL,
  consumed_by_device_id  BIGINT UNSIGNED NULL,
  consumed_at            DATETIME(3) NULL,
  created_by             BIGINT UNSIGNED NULL,
  created_at             DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at             DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_cvr_device_enrollments_uuid (enrollment_uuid),
  UNIQUE KEY uk_ipca_cvr_device_enrollments_code (enrollment_code_hash),
  KEY idx_ipca_cvr_device_enrollments_status (status, expires_at),
  CONSTRAINT fk_ipca_cvr_device_enrollments_aircraft
    FOREIGN KEY (aircraft_id) REFERENCES ipca_aircraft_devices(id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='One-time enrollment codes for Jamf-managed iPhone CVR devices.';

CREATE TABLE IF NOT EXISTS ipca_aircraft_operational_configs (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  aircraft_id            BIGINT UNSIGNED NOT NULL,
  organization_id        BIGINT UNSIGNED NOT NULL DEFAULT 1,
  current_version_id     BIGINT UNSIGNED NULL,
  created_at             DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at             DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_aircraft_operational_configs_aircraft (aircraft_id),
  CONSTRAINT fk_ipca_aircraft_operational_configs_aircraft
    FOREIGN KEY (aircraft_id) REFERENCES ipca_aircraft_devices(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Stable aircraft operational configuration identity.';

CREATE TABLE IF NOT EXISTS ipca_aircraft_operational_config_versions (
  id                              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  config_id                        BIGINT UNSIGNED NOT NULL,
  config_version_uuid              CHAR(36) NOT NULL,
  version_number                   INT UNSIGNED NOT NULL,
  effective_from_utc               DATETIME(3) NOT NULL,
  effective_to_utc                 DATETIME(3) NULL,
  hobbs_engine_on_rpm_threshold    DECIMAL(10,3) NOT NULL DEFAULT 1000.000,
  hobbs_start_confirm_ms           INT UNSIGNED NOT NULL DEFAULT 1000,
  hobbs_stop_confirm_ms            INT UNSIGNED NOT NULL DEFAULT 5000,
  tacho_rpm_threshold              DECIMAL(10,3) NULL,
  movement_groundspeed_kt          DECIMAL(10,3) NOT NULL DEFAULT 3.000,
  movement_confirm_ms              INT UNSIGNED NOT NULL DEFAULT 3000,
  fuel_discrepancy_usg             DECIMAL(10,3) NOT NULL DEFAULT 1.000,
  oil_blocking_threshold_percent   TINYINT UNSIGNED NULL,
  timezone_identifier              VARCHAR(64) NULL,
  config_json                      JSON NULL,
  changed_by                       BIGINT UNSIGNED NULL,
  change_reason                    VARCHAR(512) NULL,
  created_at                       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_aircraft_operational_config_versions_uuid (config_version_uuid),
  UNIQUE KEY uk_ipca_aircraft_operational_config_versions_number (config_id, version_number),
  KEY idx_ipca_aircraft_operational_config_versions_effective (config_id, effective_from_utc, effective_to_utc),
  CONSTRAINT fk_ipca_aircraft_operational_config_versions_config
    FOREIGN KEY (config_id) REFERENCES ipca_aircraft_operational_configs(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Versioned authoritative aircraft thresholds and operational rules.';

CREATE TABLE IF NOT EXISTS ipca_flight_sessions (
  id                         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  session_uuid                CHAR(36) NOT NULL,
  organization_id             BIGINT UNSIGNED NOT NULL DEFAULT 1,
  device_id                   BIGINT UNSIGNED NULL,
  aircraft_id                 BIGINT UNSIGNED NULL,
  aircraft_registration       VARCHAR(32) NOT NULL DEFAULT '',
  source                      VARCHAR(64) NOT NULL DEFAULT 'cvr_device',
  status                      VARCHAR(32) NOT NULL DEFAULT 'open',
  avionics_on_utc             DATETIME(3) NULL,
  avionics_off_utc            DATETIME(3) NULL,
  engine_start_utc            DATETIME(3) NULL,
  engine_stop_utc             DATETIME(3) NULL,
  exact_hobbs_duration_ms     BIGINT UNSIGNED NULL,
  exact_tacho_duration_ms     BIGINT UNSIGNED NULL,
  current_flight_record_id    BIGINT UNSIGNED NULL,
  created_at                  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at                  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_flight_sessions_uuid (session_uuid),
  KEY idx_ipca_flight_sessions_device (device_id, created_at),
  KEY idx_ipca_flight_sessions_aircraft_time (aircraft_id, avionics_on_utc),
  KEY idx_ipca_flight_sessions_status (status, updated_at),
  CONSTRAINT fk_ipca_flight_sessions_device
    FOREIGN KEY (device_id) REFERENCES ipca_cvr_devices(id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_ipca_flight_sessions_aircraft
    FOREIGN KEY (aircraft_id) REFERENCES ipca_aircraft_devices(id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Evidence flight sessions, normally one avionics on/off cycle.';

CREATE TABLE IF NOT EXISTS ipca_flight_session_segments (
  id                         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  segment_uuid                CHAR(36) NOT NULL,
  session_id                  BIGINT UNSIGNED NOT NULL,
  recording_id                BIGINT UNSIGNED NULL,
  recording_uid               VARCHAR(96) NOT NULL DEFAULT '',
  segment_index               INT UNSIGNED NOT NULL DEFAULT 1,
  started_at_utc              DATETIME(3) NULL,
  ended_at_utc                DATETIME(3) NULL,
  original_session_uid        VARCHAR(96) NOT NULL DEFAULT '',
  source_gap_summary          VARCHAR(512) NULL,
  created_at                  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_flight_session_segments_uuid (segment_uuid),
  KEY idx_ipca_flight_session_segments_session (session_id, segment_index),
  KEY idx_ipca_flight_session_segments_recording (recording_uid),
  CONSTRAINT fk_ipca_flight_session_segments_session
    FOREIGN KEY (session_id) REFERENCES ipca_flight_sessions(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Original evidence segments linked to normalized sessions.';

CREATE TABLE IF NOT EXISTS ipca_flight_session_merge_events (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  merge_event_uuid       CHAR(36) NOT NULL,
  session_id             BIGINT UNSIGNED NOT NULL,
  previous_segment_id    BIGINT UNSIGNED NULL,
  next_segment_id        BIGINT UNSIGNED NULL,
  gap_duration_ms        BIGINT UNSIGNED NOT NULL DEFAULT 0,
  merge_reason           VARCHAR(128) NOT NULL,
  merge_confidence       DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
  admin_override         TINYINT(1) NOT NULL DEFAULT 0,
  override_by            BIGINT UNSIGNED NULL,
  override_reason        VARCHAR(512) NULL,
  created_at             DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_flight_session_merge_events_uuid (merge_event_uuid),
  KEY idx_ipca_flight_session_merge_events_session (session_id, created_at),
  CONSTRAINT fk_ipca_flight_session_merge_events_session
    FOREIGN KEY (session_id) REFERENCES ipca_flight_sessions(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Session segment merge/split evidence and Admin overrides.';

CREATE TABLE IF NOT EXISTS ipca_audio_evidence_links (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  link_uuid               CHAR(36) NOT NULL,
  session_id              BIGINT UNSIGNED NOT NULL,
  recording_id            BIGINT UNSIGNED NULL,
  recording_uid           VARCHAR(96) NOT NULL DEFAULT '',
  coverage_start_utc      DATETIME(3) NULL,
  coverage_end_utc        DATETIME(3) NULL,
  covered_duration_ms     BIGINT UNSIGNED NULL,
  audio_status            VARCHAR(32) NOT NULL DEFAULT 'AUDIO_MISSING',
  reupload_available      TINYINT(1) NOT NULL DEFAULT 0,
  created_at              DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_audio_evidence_links_uuid (link_uuid),
  KEY idx_ipca_audio_evidence_links_session (session_id, audio_status),
  KEY idx_ipca_audio_evidence_links_recording (recording_uid),
  CONSTRAINT fk_ipca_audio_evidence_links_session
    FOREIGN KEY (session_id) REFERENCES ipca_flight_sessions(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT chk_ipca_audio_evidence_links_status
    CHECK (audio_status IN ('AUDIO_COMPLETE','AUDIO_PARTIAL','AUDIO_MISSING','AUDIO_CONFLICT','AUDIO_REUPLOAD_AVAILABLE'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Audio evidence coverage links; does not alter protected audio storage.';

CREATE TABLE IF NOT EXISTS ipca_garmin_csv_upload_requests (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  upload_uuid            CHAR(36) NOT NULL,
  request_uuid           CHAR(36) NOT NULL,
  organization_id        BIGINT UNSIGNED NOT NULL DEFAULT 1,
  device_id              BIGINT UNSIGNED NULL,
  session_id             BIGINT UNSIGNED NULL,
  status                 VARCHAR(32) NOT NULL DEFAULT 'receiving',
  original_filename      VARCHAR(255) NOT NULL DEFAULT '',
  total_chunks           INT UNSIGNED NOT NULL DEFAULT 0,
  total_size_bytes       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  received_chunks_json   JSON NULL,
  assembled_path         VARCHAR(1024) NULL,
  error_message          TEXT NULL,
  created_at             DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at             DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_garmin_csv_upload_requests_uuid (upload_uuid),
  UNIQUE KEY uk_ipca_garmin_csv_upload_requests_request (request_uuid),
  KEY idx_ipca_garmin_csv_upload_requests_device (device_id, status, updated_at),
  KEY idx_ipca_garmin_csv_upload_requests_session (session_id, status),
  CONSTRAINT fk_ipca_garmin_csv_upload_requests_device
    FOREIGN KEY (device_id) REFERENCES ipca_cvr_devices(id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_ipca_garmin_csv_upload_requests_session
    FOREIGN KEY (session_id) REFERENCES ipca_flight_sessions(id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Idempotent CVR Garmin CSV upload requests.';

CREATE TABLE IF NOT EXISTS ipca_garmin_csv_files (
  id                         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  csv_file_uuid               CHAR(36) NOT NULL,
  organization_id             BIGINT UNSIGNED NOT NULL DEFAULT 1,
  upload_request_id           BIGINT UNSIGNED NULL,
  session_id                  BIGINT UNSIGNED NULL,
  device_id                   BIGINT UNSIGNED NULL,
  aircraft_id                 BIGINT UNSIGNED NULL,
  aircraft_registration       VARCHAR(32) NOT NULL DEFAULT '',
  source                      VARCHAR(64) NOT NULL DEFAULT 'iphone_files_import',
  original_filename           VARCHAR(255) NOT NULL DEFAULT '',
  storage_path                VARCHAR(1024) NOT NULL,
  sha256                      CHAR(64) NOT NULL,
  file_size_bytes             BIGINT UNSIGNED NOT NULL,
  mime_type                   VARCHAR(96) NOT NULL DEFAULT 'text/csv',
  import_profile              VARCHAR(64) NOT NULL DEFAULT 'garmin_g3x',
  aircraft_ident              VARCHAR(128) NOT NULL DEFAULT '',
  product                     VARCHAR(128) NOT NULL DEFAULT '',
  system_identifier           VARCHAR(128) NOT NULL DEFAULT '',
  airframe_hours_start        DECIMAL(12,4) NULL,
  engine_hours_start          DECIMAL(12,4) NULL,
  first_valid_sample_utc      DATETIME(3) NULL,
  last_valid_sample_utc       DATETIME(3) NULL,
  valid_row_count             INT UNSIGNED NOT NULL DEFAULT 0,
  active_for_session          TINYINT(1) NOT NULL DEFAULT 0,
  evidence_status             VARCHAR(32) NOT NULL DEFAULT 'received',
  created_at                  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at                  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_garmin_csv_files_uuid (csv_file_uuid),
  UNIQUE KEY uk_ipca_garmin_csv_files_sha256 (sha256),
  KEY idx_ipca_garmin_csv_files_session (session_id, active_for_session),
  KEY idx_ipca_garmin_csv_files_aircraft_time (aircraft_id, first_valid_sample_utc),
  KEY idx_ipca_garmin_csv_files_device (device_id, created_at),
  CONSTRAINT fk_ipca_garmin_csv_files_upload
    FOREIGN KEY (upload_request_id) REFERENCES ipca_garmin_csv_upload_requests(id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_ipca_garmin_csv_files_session
    FOREIGN KEY (session_id) REFERENCES ipca_flight_sessions(id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_ipca_garmin_csv_files_device
    FOREIGN KEY (device_id) REFERENCES ipca_cvr_devices(id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_ipca_garmin_csv_files_aircraft
    FOREIGN KEY (aircraft_id) REFERENCES ipca_aircraft_devices(id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Immutable Garmin CSV evidence files with SHA-256 identity.';

CREATE TABLE IF NOT EXISTS ipca_garmin_csv_fingerprints (
  id                          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  csv_file_id                  BIGINT UNSIGNED NOT NULL,
  fingerprint_uuid             CHAR(36) NOT NULL,
  parser_version               VARCHAR(32) NOT NULL DEFAULT 'phase1-v1',
  normalized_header_hash        CHAR(64) NOT NULL DEFAULT '',
  first_rows_hash               CHAR(64) NOT NULL DEFAULT '',
  last_rows_hash                CHAR(64) NOT NULL DEFAULT '',
  gps_path_summary_hash         CHAR(64) NOT NULL DEFAULT '',
  utc_duration_ms               BIGINT UNSIGNED NULL,
  source_filename               VARCHAR(255) NOT NULL DEFAULT '',
  fingerprint_json              JSON NULL,
  created_at                    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_garmin_csv_fingerprints_uuid (fingerprint_uuid),
  UNIQUE KEY uk_ipca_garmin_csv_fingerprints_file (csv_file_id),
  KEY idx_ipca_garmin_csv_fingerprints_header (normalized_header_hash),
  CONSTRAINT fk_ipca_garmin_csv_fingerprints_file
    FOREIGN KEY (csv_file_id) REFERENCES ipca_garmin_csv_files(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Deterministic content fingerprints for duplicate/supersession checks.';

CREATE TABLE IF NOT EXISTS ipca_garmin_csv_validation_results (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  validation_uuid        CHAR(36) NOT NULL,
  csv_file_id            BIGINT UNSIGNED NOT NULL,
  status                 VARCHAR(32) NOT NULL,
  severity               VARCHAR(32) NOT NULL DEFAULT 'INFO',
  row_count              INT UNSIGNED NOT NULL DEFAULT 0,
  valid_timestamp_count  INT UNSIGNED NOT NULL DEFAULT 0,
  warning_count          INT UNSIGNED NOT NULL DEFAULT 0,
  error_count            INT UNSIGNED NOT NULL DEFAULT 0,
  details_json           JSON NULL,
  created_at             DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_garmin_csv_validation_results_uuid (validation_uuid),
  KEY idx_ipca_garmin_csv_validation_results_file (csv_file_id, created_at),
  KEY idx_ipca_garmin_csv_validation_results_status (status, severity),
  CONSTRAINT fk_ipca_garmin_csv_validation_results_file
    FOREIGN KEY (csv_file_id) REFERENCES ipca_garmin_csv_files(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Fast Garmin CSV validation outcomes.';

CREATE TABLE IF NOT EXISTS ipca_garmin_csv_session_matches (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  match_uuid             CHAR(36) NOT NULL,
  csv_file_id            BIGINT UNSIGNED NOT NULL,
  session_id             BIGINT UNSIGNED NULL,
  match_status           VARCHAR(32) NOT NULL DEFAULT 'pending',
  confidence             DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
  match_method           VARCHAR(128) NOT NULL DEFAULT '',
  evidence_json          JSON NULL,
  admin_overridden_by    BIGINT UNSIGNED NULL,
  admin_override_reason  VARCHAR(512) NULL,
  created_at             DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at             DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_garmin_csv_session_matches_uuid (match_uuid),
  KEY idx_ipca_garmin_csv_session_matches_file (csv_file_id, match_status),
  KEY idx_ipca_garmin_csv_session_matches_session (session_id, match_status),
  CONSTRAINT fk_ipca_garmin_csv_session_matches_file
    FOREIGN KEY (csv_file_id) REFERENCES ipca_garmin_csv_files(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ipca_garmin_csv_session_matches_session
    FOREIGN KEY (session_id) REFERENCES ipca_flight_sessions(id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='CSV-to-session match decisions and review state.';

CREATE TABLE IF NOT EXISTS ipca_garmin_csv_supersession_links (
  id                         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  supersession_uuid           CHAR(36) NOT NULL,
  superseding_csv_file_id     BIGINT UNSIGNED NOT NULL,
  superseded_csv_file_id      BIGINT UNSIGNED NOT NULL,
  classification              VARCHAR(32) NOT NULL,
  confidence                  DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
  comparison_json             JSON NULL,
  created_at                  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_garmin_csv_supersession_uuid (supersession_uuid),
  UNIQUE KEY uk_ipca_garmin_csv_supersession_pair (superseding_csv_file_id, superseded_csv_file_id),
  KEY idx_ipca_garmin_csv_supersession_superseded (superseded_csv_file_id),
  CONSTRAINT fk_ipca_garmin_csv_supersession_new
    FOREIGN KEY (superseding_csv_file_id) REFERENCES ipca_garmin_csv_files(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ipca_garmin_csv_supersession_old
    FOREIGN KEY (superseded_csv_file_id) REFERENCES ipca_garmin_csv_files(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Duplicate, superseding, and conflict relationships between CSV evidence files.';

CREATE TABLE IF NOT EXISTS ipca_operational_flight_records (
  id                         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  flight_record_uuid          CHAR(36) NOT NULL,
  organization_id             BIGINT UNSIGNED NOT NULL DEFAULT 1,
  session_id                  BIGINT UNSIGNED NOT NULL,
  current_version_id          BIGINT UNSIGNED NULL,
  status                      VARCHAR(32) NOT NULL DEFAULT 'draft',
  created_at                  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at                  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_operational_flight_records_uuid (flight_record_uuid),
  KEY idx_ipca_operational_flight_records_session (session_id, status),
  CONSTRAINT fk_ipca_operational_flight_records_session
    FOREIGN KEY (session_id) REFERENCES ipca_flight_sessions(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Stable operational flight record identity for a session.';

CREATE TABLE IF NOT EXISTS ipca_operational_flight_record_versions (
  id                               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  flight_record_id                  BIGINT UNSIGNED NOT NULL,
  version_uuid                      CHAR(36) NOT NULL,
  version_number                    INT UNSIGNED NOT NULL,
  status                            VARCHAR(32) NOT NULL DEFAULT 'draft',
  source                            VARCHAR(64) NOT NULL DEFAULT 'system',
  config_version_id                 BIGINT UNSIGNED NULL,
  exact_hobbs_duration_ms           BIGINT UNSIGNED NULL,
  exact_tacho_duration_ms           BIGINT UNSIGNED NULL,
  hobbs_start_hours                 DECIMAL(12,4) NULL,
  hobbs_end_hours                   DECIMAL(12,4) NULL,
  tacho_start_hours                 DECIMAL(12,4) NULL,
  tacho_end_hours                   DECIMAL(12,4) NULL,
  fuel_start_usg                    DECIMAL(10,3) NULL,
  fuel_end_usg                      DECIMAL(10,3) NULL,
  fuel_used_usg                     DECIMAL(10,3) NULL,
  total_night_duration_ms           BIGINT UNSIGNED NULL,
  cross_country_easa_qualified      TINYINT(1) NOT NULL DEFAULT 0,
  cross_country_faa_qualified       TINYINT(1) NOT NULL DEFAULT 0,
  landing_event_count               INT UNSIGNED NOT NULL DEFAULT 0,
  readiness_status                  VARCHAR(32) NOT NULL DEFAULT 'not_ready',
  summary_json                      JSON NULL,
  finalized_at                      DATETIME(3) NULL,
  finalized_by                      BIGINT UNSIGNED NULL,
  created_at                        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_operational_flight_record_versions_uuid (version_uuid),
  UNIQUE KEY uk_ipca_operational_flight_record_versions_number (flight_record_id, version_number),
  KEY idx_ipca_operational_flight_record_versions_status (status, readiness_status),
  CONSTRAINT fk_ipca_operational_flight_record_versions_record
    FOREIGN KEY (flight_record_id) REFERENCES ipca_operational_flight_records(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Immutable version snapshots of operational flight records.';

CREATE TABLE IF NOT EXISTS ipca_operational_flight_leg_versions (
  id                              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  leg_version_uuid                 CHAR(36) NOT NULL,
  flight_record_version_id         BIGINT UNSIGNED NOT NULL,
  leg_index                        INT UNSIGNED NOT NULL,
  allocation_start_utc             DATETIME(3) NOT NULL,
  allocation_end_utc               DATETIME(3) NOT NULL,
  allocated_hobbs_duration_ms      BIGINT UNSIGNED NOT NULL,
  allocated_tacho_duration_ms      BIGINT UNSIGNED NULL,
  departure_airport_event_id       BIGINT UNSIGNED NULL,
  arrival_airport_event_id         BIGINT UNSIGNED NULL,
  departure_airport_code           VARCHAR(16) NULL,
  arrival_airport_code             VARCHAR(16) NULL,
  takeoff_utc                      DATETIME(3) NULL,
  landing_utc                      DATETIME(3) NULL,
  first_movement_utc               DATETIME(3) NULL,
  final_stop_utc                   DATETIME(3) NULL,
  administrative_departure_utc     DATETIME(3) NULL,
  administrative_arrival_utc       DATETIME(3) NULL,
  fuel_start_usg                   DECIMAL(10,3) NULL,
  fuel_end_usg                     DECIMAL(10,3) NULL,
  fuel_used_usg                    DECIMAL(10,3) NULL,
  fuel_method                      VARCHAR(64) NULL,
  fuel_confidence                  DECIMAL(5,4) NULL,
  night_duration_ms                BIGINT UNSIGNED NULL,
  cross_country_easa_qualified     TINYINT(1) NOT NULL DEFAULT 0,
  cross_country_faa_qualified      TINYINT(1) NOT NULL DEFAULT 0,
  landing_event_count              INT UNSIGNED NOT NULL DEFAULT 0,
  notes                            TEXT NULL,
  created_at                       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_operational_flight_leg_versions_uuid (leg_version_uuid),
  UNIQUE KEY uk_ipca_operational_flight_leg_versions_index (flight_record_version_id, leg_index),
  KEY idx_ipca_operational_flight_leg_versions_time (allocation_start_utc, allocation_end_utc),
  CONSTRAINT fk_ipca_operational_flight_leg_versions_record_version
    FOREIGN KEY (flight_record_version_id) REFERENCES ipca_operational_flight_record_versions(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Versioned leg allocations with separate operational event times.';

CREATE TABLE IF NOT EXISTS ipca_flight_airport_event_versions (
  id                         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_version_uuid          CHAR(36) NOT NULL,
  flight_record_version_id    BIGINT UNSIGNED NOT NULL,
  leg_version_id              BIGINT UNSIGNED NULL,
  event_type                  VARCHAR(64) NOT NULL,
  event_time_utc              DATETIME(3) NOT NULL,
  approved_time_utc           DATETIME(3) NULL,
  source                      VARCHAR(64) NOT NULL DEFAULT 'system',
  detection_method            VARCHAR(128) NOT NULL DEFAULT '',
  confidence                  DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
  airport_code                VARCHAR(16) NULL,
  runway_identifier           VARCHAR(16) NULL,
  latitude                    DECIMAL(11,7) NULL,
  longitude                   DECIMAL(11,7) NULL,
  manually_corrected          TINYINT(1) NOT NULL DEFAULT 0,
  original_detected_json      JSON NULL,
  current_approved_json       JSON NULL,
  created_at                  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_flight_airport_event_versions_uuid (event_version_uuid),
  KEY idx_ipca_flight_airport_event_versions_record (flight_record_version_id, event_type, event_time_utc),
  KEY idx_ipca_flight_airport_event_versions_leg (leg_version_id, event_time_utc),
  CONSTRAINT fk_ipca_flight_airport_event_versions_record_version
    FOREIGN KEY (flight_record_version_id) REFERENCES ipca_operational_flight_record_versions(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ipca_flight_airport_event_versions_leg_version
    FOREIGN KEY (leg_version_id) REFERENCES ipca_operational_flight_leg_versions(id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT chk_ipca_flight_airport_event_versions_type
    CHECK (event_type IN ('AVIONICS_ON','AVIONICS_OFF','AUDIO_START','AUDIO_END','ENGINE_START','ENGINE_STOP','FIRST_MOVEMENT','FINAL_STOP','TAKEOFF','LANDING','TOUCH_AND_GO','STOP_AND_GO','LOW_APPROACH','GO_AROUND','MISSED_APPROACH','INTERMEDIATE_AIRPORT_ARRIVAL','INTERMEDIATE_AIRPORT_DEPARTURE','REFUEL_INFERRED','SESSION_GAP_START','SESSION_GAP_END'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Shared versioned event taxonomy for operational records.';

CREATE TABLE IF NOT EXISTS ipca_flight_crew_member_versions (
  id                         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  crew_member_version_uuid    CHAR(36) NOT NULL,
  flight_record_version_id    BIGINT UNSIGNED NOT NULL,
  user_id                     BIGINT UNSIGNED NULL,
  display_name_snapshot       VARCHAR(255) NOT NULL DEFAULT '',
  seat                        VARCHAR(32) NULL,
  logging_functions_json      JSON NULL,
  source                      VARCHAR(64) NOT NULL DEFAULT 'manual',
  confidence                  DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
  created_at                  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_flight_crew_member_versions_uuid (crew_member_version_uuid),
  KEY idx_ipca_flight_crew_member_versions_record (flight_record_version_id, user_id),
  CONSTRAINT fk_ipca_flight_crew_member_versions_record_version
    FOREIGN KEY (flight_record_version_id) REFERENCES ipca_operational_flight_record_versions(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Versioned crew and logging function snapshots.';

CREATE TABLE IF NOT EXISTS ipca_operational_calculation_versions (
  id                         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  calculation_uuid            CHAR(36) NOT NULL,
  flight_record_version_id    BIGINT UNSIGNED NOT NULL,
  leg_version_id              BIGINT UNSIGNED NULL,
  calculation_type            VARCHAR(64) NOT NULL,
  method                      VARCHAR(128) NOT NULL,
  version                     VARCHAR(32) NOT NULL DEFAULT 'phase1-v1',
  exact_value_json            JSON NULL,
  display_value_json          JSON NULL,
  source_json                 JSON NULL,
  confidence                  DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
  created_at                  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_operational_calculation_versions_uuid (calculation_uuid),
  KEY idx_ipca_operational_calculation_versions_record (flight_record_version_id, calculation_type),
  KEY idx_ipca_operational_calculation_versions_leg (leg_version_id, calculation_type),
  CONSTRAINT fk_ipca_operational_calculation_versions_record_version
    FOREIGN KEY (flight_record_version_id) REFERENCES ipca_operational_flight_record_versions(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ipca_operational_calculation_versions_leg_version
    FOREIGN KEY (leg_version_id) REFERENCES ipca_operational_flight_leg_versions(id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Versioned deterministic and manual-exception calculation results.';

CREATE TABLE IF NOT EXISTS ipca_flight_manual_input_versions (
  id                         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  manual_input_uuid           CHAR(36) NOT NULL,
  flight_record_version_id    BIGINT UNSIGNED NOT NULL,
  leg_version_id              BIGINT UNSIGNED NULL,
  input_type                  VARCHAR(64) NOT NULL,
  source_type                 VARCHAR(64) NOT NULL DEFAULT 'MANUAL_EXCEPTION',
  value_json                  JSON NOT NULL,
  reason                      VARCHAR(512) NOT NULL,
  entered_by                  BIGINT UNSIGNED NULL,
  approved_by                 BIGINT UNSIGNED NULL,
  approved_at                 DATETIME(3) NULL,
  created_at                  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_flight_manual_input_versions_uuid (manual_input_uuid),
  KEY idx_ipca_flight_manual_input_versions_record (flight_record_version_id, input_type),
  CONSTRAINT fk_ipca_flight_manual_input_versions_record_version
    FOREIGN KEY (flight_record_version_id) REFERENCES ipca_operational_flight_record_versions(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ipca_flight_manual_input_versions_leg_version
    FOREIGN KEY (leg_version_id) REFERENCES ipca_operational_flight_leg_versions(id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Versioned manual operational fallback inputs and approvals.';

CREATE TABLE IF NOT EXISTS ipca_logbook_proposal_groups (
  id                         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  proposal_group_uuid         CHAR(36) NOT NULL,
  organization_id             BIGINT UNSIGNED NOT NULL DEFAULT 1,
  session_id                  BIGINT UNSIGNED NOT NULL,
  flight_record_version_id    BIGINT UNSIGNED NOT NULL,
  owner_user_id               BIGINT UNSIGNED NOT NULL,
  entry_type                  VARCHAR(64) NOT NULL DEFAULT 'student_flight',
  allowed_duration_ms         BIGINT UNSIGNED NOT NULL,
  accepted_duration_ms        BIGINT UNSIGNED NOT NULL DEFAULT 0,
  status                      VARCHAR(32) NOT NULL DEFAULT 'open',
  created_at                  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at                  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_logbook_proposal_groups_uuid (proposal_group_uuid),
  KEY idx_ipca_logbook_proposal_groups_owner (owner_user_id, session_id, status),
  KEY idx_ipca_logbook_proposal_groups_record (flight_record_version_id),
  CONSTRAINT fk_ipca_logbook_proposal_groups_session
    FOREIGN KEY (session_id) REFERENCES ipca_flight_sessions(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ipca_logbook_proposal_groups_record_version
    FOREIGN KEY (flight_record_version_id) REFERENCES ipca_operational_flight_record_versions(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Lockable proposal groups enforcing accepted aggregate duration.';

CREATE TABLE IF NOT EXISTS ipca_flight_record_logbook_proposals (
  id                         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  proposal_uuid               CHAR(36) NOT NULL,
  request_uuid                CHAR(36) NULL,
  proposal_group_id           BIGINT UNSIGNED NOT NULL,
  flight_record_version_id    BIGINT UNSIGNED NOT NULL,
  leg_version_id              BIGINT UNSIGNED NULL,
  owner_user_id               BIGINT UNSIGNED NOT NULL,
  logbook_id                  BIGINT UNSIGNED NULL,
  target_entry_id             BIGINT UNSIGNED NULL,
  entry_type                  VARCHAR(64) NOT NULL DEFAULT 'student_flight',
  entry_variant               VARCHAR(64) NOT NULL DEFAULT '',
  proposed_duration_ms        BIGINT UNSIGNED NOT NULL,
  status                      VARCHAR(32) NOT NULL DEFAULT 'PROPOSED',
  validation_status           VARCHAR(32) NOT NULL DEFAULT 'not_checked',
  proposed_values_json        JSON NOT NULL,
  discrepancy_json            JSON NULL,
  mission_snapshot_json       JSON NULL,
  created_at                  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at                  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_flight_record_logbook_proposals_uuid (proposal_uuid),
  UNIQUE KEY uk_ipca_flight_record_logbook_proposals_request (request_uuid),
  KEY idx_ipca_flight_record_logbook_proposals_group (proposal_group_id, status),
  KEY idx_ipca_flight_record_logbook_proposals_owner (owner_user_id, status),
  KEY idx_ipca_flight_record_logbook_proposals_leg (leg_version_id),
  CONSTRAINT fk_ipca_flight_record_logbook_proposals_group
    FOREIGN KEY (proposal_group_id) REFERENCES ipca_logbook_proposal_groups(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ipca_flight_record_logbook_proposals_record_version
    FOREIGN KEY (flight_record_version_id) REFERENCES ipca_operational_flight_record_versions(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ipca_flight_record_logbook_proposals_leg_version
    FOREIGN KEY (leg_version_id) REFERENCES ipca_operational_flight_leg_versions(id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Flight Record proposals kept outside official logbook rows until acceptance.';

CREATE TABLE IF NOT EXISTS ipca_logbook_proposal_category_mappings (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  proposal_id          BIGINT UNSIGNED NOT NULL,
  category_key         VARCHAR(64) NOT NULL,
  duration_ms          BIGINT UNSIGNED NOT NULL DEFAULT 0,
  value_json           JSON NULL,
  created_at           DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_logbook_proposal_category (proposal_id, category_key),
  CONSTRAINT fk_ipca_logbook_proposal_category_proposal
    FOREIGN KEY (proposal_id) REFERENCES ipca_flight_record_logbook_proposals(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Multiple logging functions/time categories within one official logbook proposal.';

CREATE TABLE IF NOT EXISTS ipca_accepted_logbook_proposal_links (
  id                         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  accepted_link_uuid          CHAR(36) NOT NULL,
  accepted_identity_key       CHAR(64) NOT NULL,
  proposal_group_id           BIGINT UNSIGNED NOT NULL,
  proposal_id                 BIGINT UNSIGNED NOT NULL,
  logbook_entry_id            BIGINT UNSIGNED NOT NULL,
  accepted_duration_ms        BIGINT UNSIGNED NOT NULL,
  accepted_by                 BIGINT UNSIGNED NULL,
  accepted_at                 DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_at                  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_accepted_logbook_proposal_links_uuid (accepted_link_uuid),
  UNIQUE KEY uk_ipca_accepted_logbook_proposal_links_identity (accepted_identity_key),
  KEY idx_ipca_accepted_logbook_proposal_links_group (proposal_group_id),
  KEY idx_ipca_accepted_logbook_proposal_links_entry (logbook_entry_id),
  CONSTRAINT fk_ipca_accepted_logbook_proposal_links_group
    FOREIGN KEY (proposal_group_id) REFERENCES ipca_logbook_proposal_groups(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ipca_accepted_logbook_proposal_links_proposal
    FOREIGN KEY (proposal_id) REFERENCES ipca_flight_record_logbook_proposals(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Accepted proposal identities, one official row per owner/session/leg-group/entry type unless variant approved.';

CREATE TABLE IF NOT EXISTS ipca_async_jobs (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  job_uuid              CHAR(36) NOT NULL,
  organization_id       BIGINT UNSIGNED NOT NULL DEFAULT 1,
  queue_name            VARCHAR(64) NOT NULL DEFAULT 'cvr',
  job_type              VARCHAR(96) NOT NULL,
  entity_type           VARCHAR(96) NOT NULL DEFAULT '',
  entity_id             VARCHAR(128) NOT NULL DEFAULT '',
  idempotency_key       CHAR(64) NOT NULL,
  priority              INT NOT NULL DEFAULT 100,
  status                VARCHAR(32) NOT NULL DEFAULT 'pending',
  available_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  claimed_by            VARCHAR(128) NULL,
  claimed_at            DATETIME(3) NULL,
  lease_expires_at      DATETIME(3) NULL,
  heartbeat_at          DATETIME(3) NULL,
  attempt_count         INT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts          INT UNSIGNED NOT NULL DEFAULT 3,
  dependency_json       JSON NULL,
  payload_json          JSON NULL,
  result_json           JSON NULL,
  last_error            TEXT NULL,
  created_at            DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at            DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_async_jobs_uuid (job_uuid),
  UNIQUE KEY uk_ipca_async_jobs_idempotency (idempotency_key),
  KEY idx_ipca_async_jobs_claim (queue_name, status, available_at, priority, id),
  KEY idx_ipca_async_jobs_lease (status, lease_expires_at),
  KEY idx_ipca_async_jobs_entity (job_type, entity_type, entity_id),
  CONSTRAINT chk_ipca_async_jobs_status
    CHECK (status IN ('pending','claimed','running','retry_wait','succeeded','failed','dead_letter','cancel_requested','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Database-backed queue foundation for CVR workflow jobs.';
