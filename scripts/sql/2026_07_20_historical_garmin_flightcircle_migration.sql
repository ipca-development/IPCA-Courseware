-- Historical Garmin CSV Backfill and FlightCircle migration foundation.
-- Safe to run repeatedly on MySQL/MariaDB.

SET @db_name := DATABASE();

CREATE TABLE IF NOT EXISTS ipca_source_evidence (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  evidence_uuid CHAR(36) NOT NULL,
  organization_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
  source_system VARCHAR(64) NOT NULL,
  source_type VARCHAR(96) NOT NULL,
  source_label VARCHAR(255) NOT NULL DEFAULT '',
  storage_path VARCHAR(1024) NOT NULL DEFAULT '',
  sha256 CHAR(64) NULL,
  file_size_bytes BIGINT UNSIGNED NULL,
  external_source_id VARCHAR(191) NOT NULL DEFAULT '',
  external_source_hash CHAR(64) NOT NULL DEFAULT '',
  evidence_status VARCHAR(32) NOT NULL DEFAULT 'active',
  metadata_json JSON NULL,
  created_by_user_id INT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_source_evidence_uuid (evidence_uuid),
  KEY idx_ipca_source_evidence_hash (source_system, sha256),
  KEY idx_ipca_source_evidence_external (source_system, external_source_hash),
  KEY idx_ipca_source_evidence_type (source_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Immutable or derived source evidence references used by canonical historical records.';

CREATE TABLE IF NOT EXISTS ipca_aircraft_operations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  operation_uuid CHAR(36) NOT NULL,
  organization_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
  aircraft_id BIGINT UNSIGNED NULL,
  aircraft_registration VARCHAR(32) NOT NULL DEFAULT '',
  resource_identifier VARCHAR(64) NOT NULL DEFAULT '',
  resource_type VARCHAR(32) NOT NULL DEFAULT 'aircraft',
  operation_type VARCHAR(64) NOT NULL DEFAULT 'dispatch_session',
  source_mode VARCHAR(64) NOT NULL DEFAULT 'historical_migration',
  operation_status VARCHAR(32) NOT NULL DEFAULT 'proposed',
  review_status VARCHAR(32) NOT NULL DEFAULT 'needs_review',
  scheduled_start_local DATETIME(3) NULL,
  scheduled_end_local DATETIME(3) NULL,
  check_in_local DATETIME(3) NULL,
  operation_start_utc DATETIME(3) NULL,
  operation_end_utc DATETIME(3) NULL,
  time_zone VARCHAR(64) NOT NULL DEFAULT '',
  user_text VARCHAR(255) NOT NULL DEFAULT '',
  instructor_text VARCHAR(255) NOT NULL DEFAULT '',
  reservation_type VARCHAR(96) NOT NULL DEFAULT '',
  rules_text VARCHAR(32) NOT NULL DEFAULT '',
  route_text VARCHAR(255) NOT NULL DEFAULT '',
  mission_notes TEXT NULL,
  source_identity_hash CHAR(64) NOT NULL DEFAULT '',
  source_summary_json JSON NULL,
  completeness_json JSON NULL,
  created_by_user_id INT NULL,
  approved_by_user_id INT NULL,
  approved_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_aircraft_operations_uuid (operation_uuid),
  UNIQUE KEY uk_ipca_aircraft_operations_source (source_mode, source_identity_hash),
  KEY idx_ipca_aircraft_operations_aircraft_time (aircraft_registration, scheduled_start_local),
  KEY idx_ipca_aircraft_operations_resource (resource_type, resource_identifier, scheduled_start_local),
  KEY idx_ipca_aircraft_operations_review (review_status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Source-neutral aircraft dispatch/operation records, including historical migration candidates.';

CREATE TABLE IF NOT EXISTS ipca_aircraft_operation_evidence_links (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  operation_id BIGINT UNSIGNED NOT NULL,
  evidence_id BIGINT UNSIGNED NULL,
  source_system VARCHAR(64) NOT NULL DEFAULT '',
  source_table VARCHAR(96) NOT NULL DEFAULT '',
  source_record_id VARCHAR(128) NOT NULL DEFAULT '',
  relationship_type VARCHAR(64) NOT NULL DEFAULT 'supporting_evidence',
  confidence_score DECIMAL(5,2) NULL,
  evidence_json JSON NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_operation_evidence_source (operation_id, source_system, source_table, source_record_id, relationship_type),
  KEY idx_ipca_operation_evidence_operation (operation_id),
  KEY idx_ipca_operation_evidence_evidence (evidence_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Links canonical operations to raw FlightCircle, Garmin, recorder, manual, or future evidence.';

CREATE TABLE IF NOT EXISTS ipca_meter_readings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  meter_reading_uuid CHAR(36) NOT NULL,
  operation_id BIGINT UNSIGNED NULL,
  aircraft_id BIGINT UNSIGNED NULL,
  aircraft_registration VARCHAR(32) NOT NULL DEFAULT '',
  resource_identifier VARCHAR(64) NOT NULL DEFAULT '',
  meter_type VARCHAR(32) NOT NULL,
  reading_scope VARCHAR(32) NOT NULL DEFAULT 'operation',
  reading_out DECIMAL(12,4) NULL,
  reading_in DECIMAL(12,4) NULL,
  reading_delta DECIMAL(12,4) NULL,
  effective_reading DECIMAL(12,4) NULL,
  source_system VARCHAR(64) NOT NULL DEFAULT '',
  source_record_id VARCHAR(128) NOT NULL DEFAULT '',
  source_precedence VARCHAR(64) NOT NULL DEFAULT '',
  confidence_score DECIMAL(5,2) NULL,
  continuity_status VARCHAR(32) NOT NULL DEFAULT 'unchecked',
  review_status VARCHAR(32) NOT NULL DEFAULT 'needs_review',
  original_values_json JSON NULL,
  correction_json JSON NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_meter_readings_uuid (meter_reading_uuid),
  KEY idx_ipca_meter_readings_operation (operation_id, meter_type),
  KEY idx_ipca_meter_readings_aircraft (aircraft_registration, meter_type, reading_out),
  KEY idx_ipca_meter_readings_review (review_status, continuity_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Source-neutral Hobbs, Tach, TTAF, and future aircraft meter readings.';

CREATE TABLE IF NOT EXISTS ipca_fuel_transactions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  fuel_transaction_uuid CHAR(36) NOT NULL,
  operation_id BIGINT UNSIGNED NULL,
  aircraft_registration VARCHAR(32) NOT NULL DEFAULT '',
  transaction_type VARCHAR(64) NOT NULL,
  quantity DECIMAL(12,4) NULL,
  unit VARCHAR(16) NOT NULL DEFAULT '',
  source_system VARCHAR(64) NOT NULL DEFAULT '',
  source_record_id VARCHAR(128) NOT NULL DEFAULT '',
  confidence_score DECIMAL(5,2) NULL,
  review_status VARCHAR(32) NOT NULL DEFAULT 'needs_review',
  source_json JSON NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_fuel_transactions_uuid (fuel_transaction_uuid),
  KEY idx_ipca_fuel_transactions_operation (operation_id),
  KEY idx_ipca_fuel_transactions_aircraft (aircraft_registration, transaction_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Source-neutral dispatch, return, added, indicated, used, calculated, and billed fuel facts.';

CREATE TABLE IF NOT EXISTS ipca_crew_assignments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  crew_assignment_uuid CHAR(36) NOT NULL,
  operation_id BIGINT UNSIGNED NULL,
  flight_session_id BIGINT UNSIGNED NULL,
  flight_leg_id BIGINT UNSIGNED NULL,
  person_user_id INT NULL,
  source_person_text VARCHAR(255) NOT NULL DEFAULT '',
  source_role_text VARCHAR(96) NOT NULL DEFAULT '',
  resolved_role VARCHAR(64) NOT NULL DEFAULT 'unknown_crew_role',
  mapping_status VARCHAR(32) NOT NULL DEFAULT 'unmapped',
  confidence_score DECIMAL(5,2) NULL,
  review_status VARCHAR(32) NOT NULL DEFAULT 'needs_review',
  source_system VARCHAR(64) NOT NULL DEFAULT '',
  source_record_id VARCHAR(128) NOT NULL DEFAULT '',
  evidence_json JSON NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_crew_assignments_uuid (crew_assignment_uuid),
  KEY idx_ipca_crew_assignments_operation (operation_id),
  KEY idx_ipca_crew_assignments_user (person_user_id, resolved_role),
  KEY idx_ipca_crew_assignments_review (review_status, mapping_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Source-neutral crew role assignments reconstructed from migration and future workflows.';

CREATE TABLE IF NOT EXISTS ipca_operational_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_uuid CHAR(36) NOT NULL,
  operation_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(64) NOT NULL,
  event_time_utc DATETIME(3) NULL,
  event_time_local DATETIME(3) NULL,
  source_system VARCHAR(64) NOT NULL DEFAULT '',
  source_record_id VARCHAR(128) NOT NULL DEFAULT '',
  confidence_score DECIMAL(5,2) NULL,
  review_status VARCHAR(32) NOT NULL DEFAULT 'needs_review',
  event_json JSON NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_operational_events_uuid (event_uuid),
  KEY idx_ipca_operational_events_operation (operation_id, event_type),
  KEY idx_ipca_operational_events_time (event_time_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Source-neutral operation events such as dispatch, return, engine, taxi, takeoff, landing, and verification.';

CREATE TABLE IF NOT EXISTS ipca_migration_cutovers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cutover_uuid CHAR(36) NOT NULL,
  organization_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
  aircraft_id BIGINT UNSIGNED NULL,
  aircraft_registration VARCHAR(32) NOT NULL DEFAULT '',
  cutover_effective_at DATETIME(3) NOT NULL,
  historical_source_mode VARCHAR(64) NOT NULL DEFAULT 'flightcircle_migration',
  post_cutover_source_mode VARCHAR(64) NOT NULL DEFAULT 'ipca_independent',
  status VARCHAR(32) NOT NULL DEFAULT 'needs_review',
  last_historical_operation_id BIGINT UNSIGNED NULL,
  first_independent_operation_id BIGINT UNSIGNED NULL,
  historical_hobbs_in DECIMAL(12,4) NULL,
  independent_hobbs_out DECIMAL(12,4) NULL,
  hobbs_difference DECIMAL(12,4) NULL,
  historical_tach_in DECIMAL(12,4) NULL,
  independent_tach_out DECIMAL(12,4) NULL,
  tach_difference DECIMAL(12,4) NULL,
  tolerance_json JSON NULL,
  unresolved_counts_json JSON NULL,
  accepted_exceptions_json JSON NULL,
  approved_by_user_id INT NULL,
  approved_at DATETIME(3) NULL,
  approval_note TEXT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_migration_cutovers_uuid (cutover_uuid),
  UNIQUE KEY uk_ipca_migration_cutovers_aircraft (organization_id, aircraft_registration, cutover_effective_at),
  KEY idx_ipca_migration_cutovers_status (status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Formal aircraft/organization migration cutover approvals and continuity checks.';

CREATE TABLE IF NOT EXISTS ipca_garmin_historical_backfill_batches (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  batch_uuid CHAR(36) NOT NULL,
  organization_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
  created_by_user_id INT NULL,
  source_notes TEXT NULL,
  selected_aircraft_hint VARCHAR(32) NOT NULL DEFAULT '',
  upload_status VARCHAR(32) NOT NULL DEFAULT 'created',
  processing_status VARCHAR(32) NOT NULL DEFAULT 'pending',
  file_count INT UNSIGNED NOT NULL DEFAULT 0,
  total_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  duplicate_count INT UNSIGNED NOT NULL DEFAULT 0,
  classified_count INT UNSIGNED NOT NULL DEFAULT 0,
  needs_review_count INT UNSIGNED NOT NULL DEFAULT 0,
  completed_count INT UNSIGNED NOT NULL DEFAULT 0,
  failed_count INT UNSIGNED NOT NULL DEFAULT 0,
  counters_json JSON NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  completed_at DATETIME(3) NULL,
  UNIQUE KEY uk_ipca_garmin_backfill_batches_uuid (batch_uuid),
  KEY idx_ipca_garmin_backfill_batches_status (processing_status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Historical Garmin SD-card CSV backfill upload batches.';

CREATE TABLE IF NOT EXISTS ipca_garmin_historical_backfill_files (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  batch_id BIGINT UNSIGNED NOT NULL,
  csv_file_id BIGINT UNSIGNED NULL,
  original_filename VARCHAR(255) NOT NULL DEFAULT '',
  relative_path VARCHAR(1024) NOT NULL DEFAULT '',
  sha256 CHAR(64) NOT NULL,
  file_size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  exact_duplicate_status VARCHAR(32) NOT NULL DEFAULT 'new',
  existing_csv_file_id BIGINT UNSIGNED NULL,
  semantic_duplicate_status VARCHAR(32) NOT NULL DEFAULT 'unchecked',
  selected_aircraft_hint VARCHAR(32) NOT NULL DEFAULT '',
  resolved_aircraft_registration VARCHAR(32) NOT NULL DEFAULT '',
  parse_status VARCHAR(32) NOT NULL DEFAULT 'uploaded',
  classification VARCHAR(64) NOT NULL DEFAULT 'Needs Review',
  confidence_score DECIMAL(5,2) NULL,
  review_status VARCHAR(32) NOT NULL DEFAULT 'pending',
  error_json JSON NULL,
  evidence_summary_json JSON NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_garmin_backfill_file_batch_sha (batch_id, sha256, original_filename),
  KEY idx_ipca_garmin_backfill_file_csv (csv_file_id),
  KEY idx_ipca_garmin_backfill_file_status (batch_id, parse_status, review_status),
  KEY idx_ipca_garmin_backfill_file_sha (sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Historical Garmin CSV files linked to immutable evidence rows.';

CREATE TABLE IF NOT EXISTS ipca_garmin_historical_segments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  segment_uuid CHAR(36) NOT NULL,
  backfill_file_id BIGINT UNSIGNED NOT NULL,
  csv_file_id BIGINT UNSIGNED NULL,
  segment_index INT UNSIGNED NOT NULL DEFAULT 1,
  segment_type VARCHAR(64) NOT NULL DEFAULT 'whole_file',
  classification VARCHAR(64) NOT NULL DEFAULT 'Needs Review',
  confidence_score DECIMAL(5,2) NULL,
  start_utc DATETIME(3) NULL,
  end_utc DATETIME(3) NULL,
  local_start DATETIME(3) NULL,
  local_end DATETIME(3) NULL,
  aircraft_registration VARCHAR(32) NOT NULL DEFAULT '',
  departure_airport_code VARCHAR(16) NULL,
  arrival_airport_code VARCHAR(16) NULL,
  linked_session_id BIGINT UNSIGNED NULL,
  linked_operation_id BIGINT UNSIGNED NULL,
  review_status VARCHAR(32) NOT NULL DEFAULT 'pending',
  evidence_json JSON NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_garmin_historical_segments_uuid (segment_uuid),
  UNIQUE KEY uk_ipca_garmin_historical_segments_file_idx (backfill_file_id, segment_index),
  KEY idx_ipca_garmin_historical_segments_csv (csv_file_id),
  KEY idx_ipca_garmin_historical_segments_review (review_status, classification)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Operational segments classified from historical Garmin CSV evidence.';

CREATE TABLE IF NOT EXISTS ipca_garmin_historical_review_decisions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  decision_uuid CHAR(36) NOT NULL,
  object_type VARCHAR(64) NOT NULL,
  object_id BIGINT UNSIGNED NOT NULL,
  old_status VARCHAR(64) NOT NULL DEFAULT '',
  new_status VARCHAR(64) NOT NULL DEFAULT '',
  old_classification VARCHAR(64) NOT NULL DEFAULT '',
  new_classification VARCHAR(64) NOT NULL DEFAULT '',
  reason TEXT NULL,
  decision_json JSON NULL,
  actor_user_id INT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_garmin_review_decisions_uuid (decision_uuid),
  KEY idx_ipca_garmin_review_decisions_object (object_type, object_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Audit trail for historical Garmin backfill review and bulk decisions.';

CREATE TABLE IF NOT EXISTS ipca_flightcircle_import_batches (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  batch_uuid CHAR(36) NOT NULL,
  organization_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
  created_by_user_id INT NULL,
  import_status VARCHAR(32) NOT NULL DEFAULT 'created',
  export_type VARCHAR(64) NOT NULL DEFAULT 'unknown',
  original_filename VARCHAR(255) NOT NULL DEFAULT '',
  sha256 CHAR(64) NOT NULL DEFAULT '',
  file_size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  row_count INT UNSIGNED NOT NULL DEFAULT 0,
  aircraft_row_count INT UNSIGNED NOT NULL DEFAULT 0,
  simulator_row_count INT UNSIGNED NOT NULL DEFAULT 0,
  ignored_row_count INT UNSIGNED NOT NULL DEFAULT 0,
  unknown_resource_count INT UNSIGNED NOT NULL DEFAULT 0,
  identity_review_count INT UNSIGNED NOT NULL DEFAULT 0,
  operation_candidate_count INT UNSIGNED NOT NULL DEFAULT 0,
  active_dataset TINYINT(1) NOT NULL DEFAULT 0,
  superseded_by_batch_id BIGINT UNSIGNED NULL,
  superseded_at DATETIME(3) NULL,
  error_json JSON NULL,
  counters_json JSON NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  completed_at DATETIME(3) NULL,
  UNIQUE KEY uk_ipca_flightcircle_batches_uuid (batch_uuid),
  KEY idx_ipca_flightcircle_batches_active (active_dataset, import_status, completed_at),
  KEY idx_ipca_flightcircle_batches_status (import_status, created_at),
  KEY idx_ipca_flightcircle_batches_sha (sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='FlightCircle historical CSV import batches.';

CREATE TABLE IF NOT EXISTS ipca_flightcircle_raw_files (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  batch_id BIGINT UNSIGNED NOT NULL,
  evidence_id BIGINT UNSIGNED NULL,
  original_filename VARCHAR(255) NOT NULL DEFAULT '',
  storage_path VARCHAR(1024) NOT NULL DEFAULT '',
  sha256 CHAR(64) NOT NULL,
  file_size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  header_json JSON NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_flightcircle_raw_files_batch_sha (batch_id, sha256),
  KEY idx_ipca_flightcircle_raw_files_evidence (evidence_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Immutable FlightCircle raw export files.';

CREATE TABLE IF NOT EXISTS ipca_flightcircle_raw_rows (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  batch_id BIGINT UNSIGNED NOT NULL,
  raw_file_id BIGINT UNSIGNED NOT NULL,
  `row_number` INT UNSIGNED NOT NULL,
  source_row_identity_hash CHAR(64) NOT NULL,
  source_row_hash CHAR(64) NOT NULL,
  row_json JSON NOT NULL,
  parse_status VARCHAR(32) NOT NULL DEFAULT 'parsed',
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_flightcircle_raw_rows_file_row (raw_file_id, `row_number`),
  UNIQUE KEY uk_ipca_flightcircle_raw_rows_identity (batch_id, source_row_identity_hash),
  KEY idx_ipca_flightcircle_raw_rows_hash (source_row_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Preserved FlightCircle source rows with stable identity hashes.';

CREATE TABLE IF NOT EXISTS ipca_flightcircle_staging_records (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  batch_id BIGINT UNSIGNED NOT NULL,
  raw_row_id BIGINT UNSIGNED NOT NULL,
  operation_id BIGINT UNSIGNED NULL,
  resource_identifier VARCHAR(64) NOT NULL DEFAULT '',
  resource_type VARCHAR(32) NOT NULL DEFAULT 'unknown',
  import_disposition VARCHAR(32) NOT NULL DEFAULT 'needs_review',
  tail_number VARCHAR(32) NOT NULL DEFAULT '',
  user_text VARCHAR(255) NOT NULL DEFAULT '',
  instructor_text VARCHAR(255) NOT NULL DEFAULT '',
  reservation_type VARCHAR(96) NOT NULL DEFAULT '',
  rules_text VARCHAR(32) NOT NULL DEFAULT '',
  route_text VARCHAR(255) NOT NULL DEFAULT '',
  depart_local DATETIME(3) NULL,
  return_local DATETIME(3) NULL,
  check_in_local DATETIME(3) NULL,
  hours DECIMAL(8,2) NULL,
  hobbs_out DECIMAL(12,4) NULL,
  hobbs_in DECIMAL(12,4) NULL,
  hobbs_total DECIMAL(12,4) NULL,
  tach_out DECIMAL(12,4) NULL,
  tach_in DECIMAL(12,4) NULL,
  tach_total DECIMAL(12,4) NULL,
  ttaf_out DECIMAL(12,4) NULL,
  ttaf_in DECIMAL(12,4) NULL,
  ttaf_total DECIMAL(12,4) NULL,
  fuel_remaining DECIMAL(12,4) NULL,
  fuel_added DECIMAL(12,4) NULL,
  notes_text TEXT NULL,
  normalized_json JSON NULL,
  warnings_json JSON NULL,
  review_status VARCHAR(32) NOT NULL DEFAULT 'needs_review',
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_flightcircle_staging_raw (raw_row_id),
  KEY idx_ipca_flightcircle_staging_batch (batch_id, resource_type, import_disposition),
  KEY idx_ipca_flightcircle_staging_tail_time (tail_number, depart_local),
  KEY idx_ipca_flightcircle_staging_operation (operation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Source-neutral normalized FlightCircle operation/activity candidates.';

CREATE TABLE IF NOT EXISTS ipca_flightcircle_user_mappings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  source_name_hash CHAR(64) NOT NULL,
  source_name VARCHAR(255) NOT NULL,
  parsed_first_name VARCHAR(128) NOT NULL DEFAULT '',
  parsed_middle_name VARCHAR(128) NOT NULL DEFAULT '',
  parsed_last_name VARCHAR(128) NOT NULL DEFAULT '',
  suggested_role_context VARCHAR(64) NOT NULL DEFAULT '',
  ipca_user_id INT NULL,
  mapping_status VARCHAR(32) NOT NULL DEFAULT 'suggested_create_user',
  confidence_score DECIMAL(5,2) NULL,
  evidence_json JSON NULL,
  confirmed_by_user_id INT NULL,
  confirmed_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_flightcircle_user_mappings_name (source_name_hash),
  KEY idx_ipca_flightcircle_user_mappings_user (ipca_user_id),
  KEY idx_ipca_flightcircle_user_mappings_status (mapping_status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='FlightCircle source-name to canonical IPCA.training user mapping suggestions and approvals.';

CREATE TABLE IF NOT EXISTS ipca_historical_logbook_proposals (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  proposal_uuid CHAR(36) NOT NULL,
  operation_id BIGINT UNSIGNED NULL,
  staging_record_id BIGINT UNSIGNED NULL,
  owner_user_id INT NULL,
  source_person_text VARCHAR(255) NOT NULL DEFAULT '',
  entry_type VARCHAR(64) NOT NULL DEFAULT 'student_flight',
  activity_type VARCHAR(64) NOT NULL DEFAULT 'flight',
  proposed_duration_hours DECIMAL(8,2) NULL,
  review_status VARCHAR(32) NOT NULL DEFAULT 'Proposed',
  source_system VARCHAR(64) NOT NULL DEFAULT '',
  source_record_id VARCHAR(128) NOT NULL DEFAULT '',
  proposed_values_json JSON NULL,
  manual_correction_json JSON NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_historical_logbook_proposals_uuid (proposal_uuid),
  UNIQUE KEY uk_ipca_historical_logbook_proposals_source (source_system, source_record_id, source_person_text, entry_type),
  KEY idx_ipca_historical_logbook_proposals_operation (operation_id),
  KEY idx_ipca_historical_logbook_proposals_review (review_status, activity_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Reviewable historical student/instructor logbook proposals linked to canonical operations or staging activities.';

CREATE TABLE IF NOT EXISTS ipca_flightcircle_garmin_matches (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  match_uuid CHAR(36) NOT NULL,
  staging_record_id BIGINT UNSIGNED NOT NULL,
  operation_id BIGINT UNSIGNED NULL,
  garmin_segment_id BIGINT UNSIGNED NULL,
  csv_file_id BIGINT UNSIGNED NULL,
  match_status VARCHAR(32) NOT NULL DEFAULT 'candidate',
  confidence_score DECIMAL(5,2) NULL,
  evidence_json JSON NULL,
  conflict_json JSON NULL,
  decided_by_user_id INT NULL,
  decided_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_fc_garmin_matches_uuid (match_uuid),
  UNIQUE KEY uk_ipca_fc_garmin_matches_candidate (staging_record_id, garmin_segment_id, csv_file_id),
  KEY idx_ipca_fc_garmin_matches_status (match_status, confidence_score)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='FlightCircle-to-Garmin match candidates, conflicts, and decisions.';
