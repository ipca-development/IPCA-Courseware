-- Garmin Cloud provider foundation.
-- Additive only. Does not alter protected audio/replay/logbook behavior.
-- Depends on 2026_07_12_cvr_flight_workflow_phase1_foundation.sql.

CREATE TABLE IF NOT EXISTS ipca_garmin_provider_states (
  id                              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id                 BIGINT UNSIGNED NOT NULL DEFAULT 1,
  provider_name                   VARCHAR(64) NOT NULL,
  provider_type                   VARCHAR(64) NOT NULL,
  worker_mode                     VARCHAR(64) NOT NULL DEFAULT 'remote_worker',
  enabled                         TINYINT(1) NOT NULL DEFAULT 0,
  scheduled_sync_enabled          TINYINT(1) NOT NULL DEFAULT 0,
  deployment_acceptance_passed    TINYINT(1) NOT NULL DEFAULT 0,
  authentication_status           VARCHAR(64) NOT NULL DEFAULT 'not_configured',
  connection_status               VARCHAR(64) NOT NULL DEFAULT 'not_configured',
  reauthentication_required       TINYINT(1) NOT NULL DEFAULT 0,
  browser_profile_present         TINYINT(1) NOT NULL DEFAULT 0,
  worker_reachable                TINYINT(1) NOT NULL DEFAULT 0,
  safe_account_label              VARCHAR(255) NULL,
  last_authenticated_at           DATETIME(3) NULL,
  last_connection_test_at         DATETIME(3) NULL,
  last_successful_sync_at         DATETIME(3) NULL,
  last_attempted_sync_at          DATETIME(3) NULL,
  last_initial_sync_at            DATETIME(3) NULL,
  last_incremental_sync_at        DATETIME(3) NULL,
  last_reconciliation_at          DATETIME(3) NULL,
  last_version_cursor             TEXT NULL,
  last_version_cursor_decoded_at  DATETIME(3) NULL,
  acceptance_checks_json          JSON NULL,
  last_error_code                 VARCHAR(128) NULL,
  last_error_summary              TEXT NULL,
  created_at                      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at                      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_garmin_provider_states_provider (organization_id, provider_name),
  KEY idx_ipca_garmin_provider_states_status (enabled, authentication_status, connection_status),
  CONSTRAINT chk_ipca_garmin_provider_states_worker_mode
    CHECK (worker_mode IN ('remote_worker','server_worker','local_cli')),
  CONSTRAINT chk_ipca_garmin_provider_states_auth
    CHECK (authentication_status IN ('not_configured','authentication_required','authenticating','authenticated','verification_overdue','session_expired','sync_error','disabled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Garmin Cloud provider state. Does not store credentials, cookies, or MFA values.';

CREATE TABLE IF NOT EXISTS ipca_garmin_logbook_entries (
  id                          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id             BIGINT UNSIGNED NOT NULL DEFAULT 1,
  provider_name               VARCHAR(64) NOT NULL,
  garmin_entry_uuid           CHAR(36) NOT NULL,
  garmin_version              TEXT NULL,
  entry_date                  DATE NULL,
  aircraft_registration       VARCHAR(32) NULL,
  aircraft_type_uuid          CHAR(36) NULL,
  generated_track_start_utc   DATETIME(3) NULL,
  generated_track_stop_utc    DATETIME(3) NULL,
  generating_device_name      VARCHAR(255) NULL,
  canonical_track_uuid        CHAR(36) NULL,
  provisional                 TINYINT(1) NOT NULL DEFAULT 0,
  locked_at                   DATETIME(3) NULL,
  deleted_at                  DATETIME(3) NULL,
  raw_entry_json              JSON NULL,
  first_seen_at               DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  last_seen_at                DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_at                  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at                  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_garmin_logbook_entries_uuid (provider_name, garmin_entry_uuid),
  KEY idx_ipca_garmin_logbook_entries_aircraft (aircraft_registration),
  KEY idx_ipca_garmin_logbook_entries_track_start (generated_track_start_utc),
  KEY idx_ipca_garmin_logbook_entries_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Current Garmin Cloud logbook entry metadata from provider sync.';

CREATE TABLE IF NOT EXISTS ipca_garmin_logbook_entry_versions (
  id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  garmin_logbook_entry_id  BIGINT UNSIGNED NOT NULL,
  version_uuid             CHAR(36) NOT NULL,
  garmin_version           TEXT NULL,
  raw_entry_json           JSON NOT NULL,
  change_reason            VARCHAR(128) NOT NULL DEFAULT 'sync',
  created_at               DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_garmin_entry_versions_uuid (version_uuid),
  KEY idx_ipca_garmin_entry_versions_entry (garmin_logbook_entry_id, created_at),
  CONSTRAINT fk_ipca_garmin_entry_versions_entry
    FOREIGN KEY (garmin_logbook_entry_id) REFERENCES ipca_garmin_logbook_entries(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Append-only Garmin logbook entry raw JSON history.';

CREATE TABLE IF NOT EXISTS ipca_garmin_flight_data_sources (
  id                                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id                     BIGINT UNSIGNED NOT NULL DEFAULT 1,
  provider_name                       VARCHAR(64) NOT NULL,
  flight_data_log_uuid                CHAR(36) NOT NULL,
  garmin_logbook_entry_id             BIGINT UNSIGNED NULL,
  garmin_csv_file_id                  BIGINT UNSIGNED NULL,
  download_status                     VARCHAR(64) NOT NULL DEFAULT 'pending',
  format_status                       VARCHAR(64) NULL,
  data_log_type                       VARCHAR(64) NULL,
  completeness_status                 VARCHAR(64) NULL,
  validation_status                   VARCHAR(64) NULL,
  validation_severity                 VARCHAR(64) NULL,
  import_status                       VARCHAR(64) NULL,
  match_status                        VARCHAR(64) NULL,
  source_role                         VARCHAR(64) NULL,
  source_filename                     VARCHAR(255) NULL,
  source_content_type                 VARCHAR(255) NULL,
  source_etag                         VARCHAR(255) NULL,
  stored_file_path                    VARCHAR(1024) NULL,
  sha256                              CHAR(64) NULL,
  file_size_bytes                     BIGINT UNSIGNED NULL,
  csv_first_timestamp_utc             DATETIME(3) NULL,
  csv_last_timestamp_utc              DATETIME(3) NULL,
  matched_flight_session_id           BIGINT UNSIGNED NULL,
  match_confidence                    DECIMAL(5,4) NULL,
  capabilities_json                   JSON NULL,
  canonical_fields_json               JSON NULL,
  detected_columns_json               JSON NULL,
  parser_profile                      VARCHAR(128) NULL,
  parser_version                      VARCHAR(64) NULL,
  valid_sample_count                  BIGINT UNSIGNED NULL,
  invalid_sample_count                BIGINT UNSIGNED NULL,
  field_coverage_json                 JSON NULL,
  classification_reason               TEXT NULL,
  supports_full_replay                TINYINT(1) NOT NULL DEFAULT 0,
  supports_gps_replay                 TINYINT(1) NOT NULL DEFAULT 0,
  supports_hobbs_calculation          TINYINT(1) NOT NULL DEFAULT 0,
  supports_tacho_calculation          TINYINT(1) NOT NULL DEFAULT 0,
  supports_operational_flight_record  TINYINT(1) NOT NULL DEFAULT 0,
  retry_count                         INT UNSIGNED NOT NULL DEFAULT 0,
  next_retry_at                       DATETIME(3) NULL,
  downloaded_at                       DATETIME(3) NULL,
  classified_at                       DATETIME(3) NULL,
  validated_at                        DATETIME(3) NULL,
  imported_at                         DATETIME(3) NULL,
  ignored_at                          DATETIME(3) NULL,
  ignored_by                          BIGINT UNSIGNED NULL,
  last_error_code                     VARCHAR(128) NULL,
  last_error_summary                  TEXT NULL,
  created_at                          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at                          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_garmin_flight_data_source_uuid (provider_name, flight_data_log_uuid),
  KEY idx_ipca_garmin_flight_data_source_entry (garmin_logbook_entry_id),
  KEY idx_ipca_garmin_flight_data_source_csv (garmin_csv_file_id),
  KEY idx_ipca_garmin_flight_data_source_status (download_status, data_log_type, match_status),
  KEY idx_ipca_garmin_flight_data_source_session (matched_flight_session_id, match_status),
  KEY idx_ipca_garmin_flight_data_source_sha (sha256),
  CONSTRAINT fk_ipca_garmin_flight_data_source_entry
    FOREIGN KEY (garmin_logbook_entry_id) REFERENCES ipca_garmin_logbook_entries(id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_ipca_garmin_flight_data_source_csv
    FOREIGN KEY (garmin_csv_file_id) REFERENCES ipca_garmin_csv_files(id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_ipca_garmin_flight_data_source_session
    FOREIGN KEY (matched_flight_session_id) REFERENCES ipca_flight_sessions(id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT chk_ipca_garmin_flight_data_source_download
    CHECK (download_status IN ('pending','downloading','downloaded','failed','authentication_required')),
  CONSTRAINT chk_ipca_garmin_flight_data_source_format
    CHECK (format_status IS NULL OR format_status IN ('supported','unsupported','invalid')),
  CONSTRAINT chk_ipca_garmin_flight_data_source_type
    CHECK (data_log_type IS NULL OR data_log_type IN ('FULL_AVIONICS','GPS_ONLY','PARTIAL_AVIONICS','UNKNOWN_SUPPORTED','UNSUPPORTED_FORMAT','INVALID'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Each Garmin flightDataLogUUID, classified independently and linked to immutable evidence.';

CREATE TABLE IF NOT EXISTS ipca_garmin_logbook_entry_data_logs (
  garmin_logbook_entry_id       BIGINT UNSIGNED NOT NULL,
  garmin_flight_data_source_id  BIGINT UNSIGNED NOT NULL,
  position_index                INT UNSIGNED NOT NULL DEFAULT 0,
  created_at                    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (garmin_logbook_entry_id, garmin_flight_data_source_id),
  KEY idx_ipca_garmin_entry_data_logs_source (garmin_flight_data_source_id),
  CONSTRAINT fk_ipca_garmin_entry_data_logs_entry
    FOREIGN KEY (garmin_logbook_entry_id) REFERENCES ipca_garmin_logbook_entries(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ipca_garmin_entry_data_logs_source
    FOREIGN KEY (garmin_flight_data_source_id) REFERENCES ipca_garmin_flight_data_sources(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Many-to-many link between Garmin entries and all referenced flight-data logs.';

CREATE TABLE IF NOT EXISTS ipca_garmin_source_groups (
  id                                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  source_group_uuid                     CHAR(36) NOT NULL,
  organization_id                       BIGINT UNSIGNED NOT NULL DEFAULT 1,
  provider_name                         VARCHAR(64) NOT NULL,
  garmin_logbook_entry_id               BIGINT UNSIGNED NULL,
  garmin_entry_uuid                     CHAR(36) NULL,
  matched_flight_session_id             BIGINT UNSIGNED NULL,
  operational_flight_record_id          BIGINT UNSIGNED NULL,
  primary_operational_source_id         BIGINT UNSIGNED NULL,
  primary_replay_source_id              BIGINT UNSIGNED NULL,
  review_status                         VARCHAR(64) NOT NULL DEFAULT 'pending',
  group_match_status                    VARCHAR(64) NOT NULL DEFAULT 'pending',
  group_match_confidence                DECIMAL(5,4) NULL,
  union_coverage_start_utc              DATETIME(3) NULL,
  union_coverage_end_utc                DATETIME(3) NULL,
  source_selection_reason               TEXT NULL,
  selection_version                     VARCHAR(64) NOT NULL DEFAULT 'garmin-source-selection-v1',
  created_at                            DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at                            DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_garmin_source_groups_uuid (source_group_uuid),
  KEY idx_ipca_garmin_source_groups_entry (garmin_logbook_entry_id),
  KEY idx_ipca_garmin_source_groups_session (matched_flight_session_id, group_match_status),
  CONSTRAINT fk_ipca_garmin_source_groups_entry
    FOREIGN KEY (garmin_logbook_entry_id) REFERENCES ipca_garmin_logbook_entries(id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_ipca_garmin_source_groups_session
    FOREIGN KEY (matched_flight_session_id) REFERENCES ipca_flight_sessions(id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_ipca_garmin_source_groups_record
    FOREIGN KEY (operational_flight_record_id) REFERENCES ipca_operational_flight_records(id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Same-flight Garmin source groups, normally one group per Garmin logbook entry.';

CREATE TABLE IF NOT EXISTS ipca_garmin_source_group_members (
  source_group_id               BIGINT UNSIGNED NOT NULL,
  garmin_flight_data_source_id  BIGINT UNSIGNED NOT NULL,
  member_status                 VARCHAR(64) NOT NULL DEFAULT 'active',
  source_specific_warning       TEXT NULL,
  created_at                    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (source_group_id, garmin_flight_data_source_id),
  KEY idx_ipca_garmin_source_group_members_source (garmin_flight_data_source_id),
  CONSTRAINT fk_ipca_garmin_source_group_members_group
    FOREIGN KEY (source_group_id) REFERENCES ipca_garmin_source_groups(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ipca_garmin_source_group_members_source
    FOREIGN KEY (garmin_flight_data_source_id) REFERENCES ipca_garmin_flight_data_sources(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='All source logs retained under a Garmin same-flight source group.';

CREATE TABLE IF NOT EXISTS ipca_garmin_source_role_assignments (
  id                            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  role_assignment_uuid           CHAR(36) NOT NULL,
  source_group_id                BIGINT UNSIGNED NOT NULL,
  garmin_flight_data_source_id   BIGINT UNSIGNED NOT NULL,
  source_role                    VARCHAR(64) NOT NULL,
  selection_reason               TEXT NULL,
  assigned_by                    BIGINT UNSIGNED NULL,
  assigned_at                    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_garmin_source_role_uuid (role_assignment_uuid),
  UNIQUE KEY uk_ipca_garmin_source_role_unique (source_group_id, garmin_flight_data_source_id, source_role),
  KEY idx_ipca_garmin_source_role_source (garmin_flight_data_source_id),
  CONSTRAINT fk_ipca_garmin_source_role_group
    FOREIGN KEY (source_group_id) REFERENCES ipca_garmin_source_groups(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ipca_garmin_source_role_source
    FOREIGN KEY (garmin_flight_data_source_id) REFERENCES ipca_garmin_flight_data_sources(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT chk_ipca_garmin_source_role
    CHECK (source_role IN ('PRIMARY_OPERATIONAL','PRIMARY_REPLAY','SUPPORTING_GPS','SUPPORTING_AVIONICS','ALTERNATE','SUPERSEDED','CONFLICT','INVALID_EXCLUDED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Deterministic role selection for complementary Garmin source logs.';

CREATE TABLE IF NOT EXISTS ipca_garmin_sync_runs (
  id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  run_uuid                 CHAR(36) NOT NULL,
  organization_id          BIGINT UNSIGNED NOT NULL DEFAULT 1,
  provider_name            VARCHAR(64) NOT NULL,
  sync_type                VARCHAR(64) NOT NULL,
  trigger_type             VARCHAR(64) NOT NULL,
  triggered_by             BIGINT UNSIGNED NULL,
  status                   VARCHAR(64) NOT NULL,
  cursor_before            TEXT NULL,
  cursor_after             TEXT NULL,
  started_at               DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  completed_at             DATETIME(3) NULL,
  entries_received         INT UNSIGNED NOT NULL DEFAULT 0,
  entries_upserted         INT UNSIGNED NOT NULL DEFAULT 0,
  entries_deleted          INT UNSIGNED NOT NULL DEFAULT 0,
  data_logs_discovered     INT UNSIGNED NOT NULL DEFAULT 0,
  files_downloaded         INT UNSIGNED NOT NULL DEFAULT 0,
  files_validated          INT UNSIGNED NOT NULL DEFAULT 0,
  files_imported           INT UNSIGNED NOT NULL DEFAULT 0,
  files_unmatched          INT UNSIGNED NOT NULL DEFAULT 0,
  full_avionics_count      INT UNSIGNED NOT NULL DEFAULT 0,
  gps_only_count           INT UNSIGNED NOT NULL DEFAULT 0,
  partial_avionics_count   INT UNSIGNED NOT NULL DEFAULT 0,
  unsupported_count        INT UNSIGNED NOT NULL DEFAULT 0,
  invalid_count            INT UNSIGNED NOT NULL DEFAULT 0,
  error_code               VARCHAR(128) NULL,
  error_summary            TEXT NULL,
  created_at               DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at               DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_garmin_sync_runs_uuid (run_uuid),
  KEY idx_ipca_garmin_sync_runs_status (provider_name, status, started_at),
  KEY idx_ipca_garmin_sync_runs_type (provider_name, sync_type, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Garmin Cloud sync/reconciliation run summary.';

CREATE TABLE IF NOT EXISTS ipca_garmin_sync_run_items (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  sync_run_id       BIGINT UNSIGNED NOT NULL,
  item_type         VARCHAR(64) NOT NULL,
  item_identifier   VARCHAR(128) NOT NULL,
  status            VARCHAR(64) NOT NULL,
  result_json       JSON NULL,
  error_code        VARCHAR(128) NULL,
  error_summary     TEXT NULL,
  created_at        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  KEY idx_ipca_garmin_sync_run_items_run (sync_run_id, item_type),
  KEY idx_ipca_garmin_sync_run_items_identifier (item_identifier),
  CONSTRAINT fk_ipca_garmin_sync_run_items_run
    FOREIGN KEY (sync_run_id) REFERENCES ipca_garmin_sync_runs(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-entry/per-flight-data status for Garmin sync runs.';

SET @has_garmin_csv_upload_source := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_garmin_csv_files'
    AND COLUMN_NAME = 'upload_source'
);
SET @sql := IF(
  @has_garmin_csv_upload_source = 0,
  'ALTER TABLE ipca_garmin_csv_files ADD COLUMN upload_source VARCHAR(64) NOT NULL DEFAULT ''iphone_files_import'' AFTER source',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_garmin_csv_provider_name := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_garmin_csv_files'
    AND COLUMN_NAME = 'provider_name'
);
SET @sql := IF(
  @has_garmin_csv_provider_name = 0,
  'ALTER TABLE ipca_garmin_csv_files ADD COLUMN provider_name VARCHAR(64) NULL AFTER upload_source',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
