-- ECCAIRS 2 machine-to-machine integration (MySQL 8).
-- Additive and re-run safe. Secrets and access/refresh tokens are never stored here.

SET @legacy_reviewed_by_sql = IF(
  EXISTS(
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'ipca_safety_legacy_staging'
      AND column_name = 'reviewed_by_user_id'
  ),
  'SELECT 1',
  'ALTER TABLE ipca_safety_legacy_staging ADD COLUMN reviewed_by_user_id BIGINT UNSIGNED NULL AFTER validation_errors_json'
);
PREPARE legacy_reviewed_by_stmt FROM @legacy_reviewed_by_sql;
EXECUTE legacy_reviewed_by_stmt;
DEALLOCATE PREPARE legacy_reviewed_by_stmt;

SET @legacy_reviewed_at_sql = IF(
  EXISTS(
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'ipca_safety_legacy_staging'
      AND column_name = 'reviewed_at_utc'
  ),
  'SELECT 1',
  'ALTER TABLE ipca_safety_legacy_staging ADD COLUMN reviewed_at_utc DATETIME(3) NULL AFTER reviewed_by_user_id'
);
PREPARE legacy_reviewed_at_stmt FROM @legacy_reviewed_at_sql;
EXECUTE legacy_reviewed_at_stmt;
DEALLOCATE PREPARE legacy_reviewed_at_stmt;

SET @legacy_review_rationale_sql = IF(
  EXISTS(
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'ipca_safety_legacy_staging'
      AND column_name = 'review_rationale'
  ),
  'SELECT 1',
  'ALTER TABLE ipca_safety_legacy_staging ADD COLUMN review_rationale TEXT NULL AFTER reviewed_at_utc'
);
PREPARE legacy_review_rationale_stmt FROM @legacy_review_rationale_sql;
EXECUTE legacy_review_rationale_stmt;
DEALLOCATE PREPARE legacy_review_rationale_stmt;

CREATE TABLE IF NOT EXISTS ipca_safety_eccairs_connections (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  environment VARCHAR(24) NOT NULL,
  base_url VARCHAR(255) NOT NULL,
  token_path VARCHAR(255) NOT NULL DEFAULT '/auth/api/token',
  create_path VARCHAR(255) NOT NULL DEFAULT '/occurrences/create',
  get_path_template VARCHAR(255) NOT NULL DEFAULT '/occurrences/get/{e2id}',
  reporting_entity_id VARCHAR(96) NULL,
  responsible_entity_id VARCHAR(96) NULL,
  taxonomy_version VARCHAR(64) NULL,
  general_version VARCHAR(64) NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  production_transmission_enabled TINYINT(1) NOT NULL DEFAULT 0,
  updated_by_user_id BIGINT UNSIGNED NULL,
  updated_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
    ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_safety_e2_connection (organization_id, environment),
  KEY idx_safety_e2_enabled (organization_id, enabled, environment)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Non-secret ECCAIRS 2 connection configuration; credentials remain in secret management.';

INSERT IGNORE INTO ipca_safety_eccairs_connections
  (organization_id, environment, base_url, token_path, create_path, get_path_template,
   enabled, production_transmission_enabled)
VALUES
  (1, 'sandbox', 'https://api.sandbox.aviationreporting.eu', '/auth/api/token',
   '/occurrences/create', '/occurrences/get/{e2id}', 0, 0),
  (1, 'uat', 'https://api.uat-aviationreporting.eu', '/auth/api/token',
   '/occurrences/create', '/occurrences/get/{e2id}', 0, 0),
  (1, 'production', 'https://api.aviationreporting.eu', '/auth/api/token',
   '/occurrences/create', '/occurrences/get/{e2id}', 0, 0);

CREATE TABLE IF NOT EXISTS ipca_safety_eccairs_taxonomy_packages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  package_uuid CHAR(36) NOT NULL,
  taxonomy_name VARCHAR(160) NOT NULL,
  taxonomy_version VARCHAR(64) NOT NULL,
  schema_version VARCHAR(64) NOT NULL,
  generated_at_utc DATETIME(3) NULL,
  source_sha256 CHAR(64) NOT NULL,
  source_byte_size BIGINT UNSIGNED NOT NULL,
  manifest_json JSON NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'imported',
  imported_by_user_id BIGINT UNSIGNED NOT NULL,
  imported_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  activated_by_user_id BIGINT UNSIGNED NULL,
  activated_at_utc DATETIME(3) NULL,
  UNIQUE KEY uk_safety_e2_taxonomy_uuid (package_uuid),
  UNIQUE KEY uk_safety_e2_taxonomy_source (organization_id, source_sha256),
  UNIQUE KEY uk_safety_e2_taxonomy_version
    (organization_id, taxonomy_name, taxonomy_version, schema_version),
  KEY idx_safety_e2_taxonomy_active (organization_id, status, taxonomy_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Immutable imported ECCAIRS taxonomy package manifest and source fingerprint.';

CREATE TABLE IF NOT EXISTS ipca_safety_eccairs_taxonomy_entities (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  taxonomy_package_id BIGINT UNSIGNED NOT NULL,
  entity_code VARCHAR(64) NOT NULL,
  entity_name VARCHAR(160) NOT NULL,
  parent_path VARCHAR(512) NOT NULL DEFAULT '',
  entity_path VARCHAR(512) NOT NULL DEFAULT '',
  is_link TINYINT(1) NOT NULL DEFAULT 0,
  is_linked TINYINT(1) NOT NULL DEFAULT 0,
  sequence_number INT NOT NULL DEFAULT 0,
  UNIQUE KEY uk_safety_e2_taxonomy_entity
    (taxonomy_package_id, entity_code, parent_path),
  KEY idx_safety_e2_taxonomy_entity_org
    (organization_id, taxonomy_package_id, entity_code),
  CONSTRAINT fk_safety_e2_taxonomy_entity_package FOREIGN KEY (taxonomy_package_id)
    REFERENCES ipca_safety_eccairs_taxonomy_packages(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_safety_eccairs_taxonomy_attributes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  taxonomy_package_id BIGINT UNSIGNED NOT NULL,
  attribute_code VARCHAR(64) NOT NULL,
  attribute_name VARCHAR(160) NOT NULL,
  entity_code VARCHAR(64) NOT NULL,
  entity_name VARCHAR(160) NOT NULL,
  datatype_code VARCHAR(32) NOT NULL,
  default_unit_code VARCHAR(64) NULL,
  value_list_code VARCHAR(64) NULL,
  min_instances INT NULL,
  max_instances INT NULL,
  sequence_number INT NOT NULL DEFAULT 0,
  UNIQUE KEY uk_safety_e2_taxonomy_attribute
    (taxonomy_package_id, entity_code, attribute_code),
  KEY idx_safety_e2_taxonomy_attribute_org
    (organization_id, taxonomy_package_id, attribute_code),
  CONSTRAINT fk_safety_e2_taxonomy_attribute_package FOREIGN KEY (taxonomy_package_id)
    REFERENCES ipca_safety_eccairs_taxonomy_packages(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_safety_eccairs_taxonomy_values (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  taxonomy_package_id BIGINT UNSIGNED NOT NULL,
  value_list_code VARCHAR(64) NOT NULL,
  value_code VARCHAR(128) NOT NULL,
  value_label VARCHAR(500) NOT NULL,
  parent_value_code VARCHAR(128) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  sequence_number INT NOT NULL DEFAULT 0,
  metadata_json JSON NULL,
  UNIQUE KEY uk_safety_e2_taxonomy_value
    (taxonomy_package_id, value_list_code, value_code),
  KEY idx_safety_e2_taxonomy_value_org
    (organization_id, taxonomy_package_id, value_list_code),
  CONSTRAINT fk_safety_e2_taxonomy_value_package FOREIGN KEY (taxonomy_package_id)
    REFERENCES ipca_safety_eccairs_taxonomy_packages(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_safety_eccairs_mappings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  mapping_version VARCHAR(64) NOT NULL,
  taxonomy_version VARCHAR(64) NOT NULL,
  source_field VARCHAR(128) NOT NULL,
  target_entity_code VARCHAR(64) NOT NULL,
  target_entity_path VARCHAR(512) NOT NULL DEFAULT 'Occurrence',
  target_entity_path_sha256 CHAR(64) NOT NULL,
  target_attribute_code VARCHAR(64) NOT NULL,
  transform_code VARCHAR(64) NOT NULL DEFAULT 'identity',
  value_map_json JSON NULL,
  required_state VARCHAR(24) NOT NULL DEFAULT 'optional',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by_user_id BIGINT UNSIGNED NULL,
  created_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_safety_e2_mapping
    (organization_id, mapping_version, source_field, target_entity_code,
     target_entity_path_sha256, target_attribute_code),
  KEY idx_safety_e2_mapping_active
    (organization_id, taxonomy_version, mapping_version, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Versioned IPCA-to-ECCAIRS taxonomy mappings; no silent fallback between versions.';

CREATE TABLE IF NOT EXISTS ipca_safety_eccairs_submissions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  submission_uuid CHAR(36) NOT NULL,
  occurrence_id BIGINT UNSIGNED NOT NULL,
  assessment_id BIGINT UNSIGNED NOT NULL,
  environment VARCHAR(24) NOT NULL,
  payload_version INT UNSIGNED NOT NULL,
  mapping_version VARCHAR(64) NOT NULL,
  taxonomy_version VARCHAR(64) NOT NULL,
  general_version VARCHAR(64) NULL,
  canonical_json JSON NOT NULL,
  canonical_sha256 CHAR(64) NOT NULL,
  envelope_json JSON NOT NULL,
  envelope_sha256 CHAR(64) NOT NULL,
  validation_json JSON NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'draft',
  approved_by_user_id BIGINT UNSIGNED NULL,
  approved_at_utc DATETIME(3) NULL,
  approval_rationale TEXT NULL,
  queued_at_utc DATETIME(3) NULL,
  next_attempt_at_utc DATETIME(3) NULL,
  remote_e2_id VARCHAR(96) NULL,
  remote_version VARCHAR(32) NULL,
  remote_status VARCHAR(32) NULL,
  accepted_at_utc DATETIME(3) NULL,
  last_error_code VARCHAR(96) NULL,
  last_error_summary VARCHAR(1000) NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
    ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_safety_e2_submission_uuid (submission_uuid),
  UNIQUE KEY uk_safety_e2_occurrence_version
    (organization_id, occurrence_id, environment, payload_version),
  UNIQUE KEY uk_safety_e2_canonical
    (organization_id, occurrence_id, environment, canonical_sha256),
  KEY idx_safety_e2_queue
    (organization_id, environment, status, next_attempt_at_utc),
  KEY idx_safety_e2_remote (organization_id, remote_e2_id),
  CONSTRAINT fk_safety_e2_submission_occurrence FOREIGN KEY (occurrence_id)
    REFERENCES ipca_safety_occurrences(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_safety_e2_submission_assessment FOREIGN KEY (assessment_id)
    REFERENCES ipca_safety_reportability_assessments(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Immutable transport-neutral occurrence snapshots and their transmission state.';

CREATE TABLE IF NOT EXISTS ipca_safety_eccairs_approvals (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  submission_id BIGINT UNSIGNED NOT NULL,
  decision VARCHAR(24) NOT NULL,
  canonical_sha256 CHAR(64) NOT NULL,
  rationale TEXT NOT NULL,
  decided_by_user_id BIGINT UNSIGNED NOT NULL,
  decided_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_safety_e2_approval (submission_id),
  KEY idx_safety_e2_approval_org (organization_id, decided_at_utc),
  CONSTRAINT fk_safety_e2_approval_submission FOREIGN KEY (submission_id)
    REFERENCES ipca_safety_eccairs_submissions(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Human decision bound to the exact transport-neutral canonical digest.';

SET @approval_correlation_cleanup_sql = IF(
  EXISTS(
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'ipca_safety_eccairs_approvals'
      AND column_name = 'correlation_sha256'
  ),
  'ALTER TABLE ipca_safety_eccairs_approvals DROP COLUMN correlation_sha256',
  'SELECT 1'
);
PREPARE approval_correlation_cleanup_stmt FROM @approval_correlation_cleanup_sql;
EXECUTE approval_correlation_cleanup_stmt;
DEALLOCATE PREPARE approval_correlation_cleanup_stmt;

CREATE TABLE IF NOT EXISTS ipca_safety_eccairs_artifacts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  submission_id BIGINT UNSIGNED NOT NULL,
  artifact_uuid CHAR(36) NOT NULL,
  transport VARCHAR(24) NOT NULL,
  artifact_version INT UNSIGNED NOT NULL,
  content_type VARCHAR(128) NOT NULL,
  artifact_json JSON NULL,
  storage_reference VARCHAR(512) NULL,
  artifact_sha256 CHAR(64) NOT NULL,
  schema_version VARCHAR(64) NOT NULL,
  generated_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_safety_e2_artifact_uuid (artifact_uuid),
  UNIQUE KEY uk_safety_e2_artifact_version
    (submission_id, transport, artifact_version),
  UNIQUE KEY uk_safety_e2_artifact_digest
    (submission_id, transport, artifact_sha256),
  KEY idx_safety_e2_artifact_org
    (organization_id, submission_id, transport),
  CONSTRAINT fk_safety_e2_artifact_submission FOREIGN KEY (submission_id)
    REFERENCES ipca_safety_eccairs_submissions(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Transport-specific REST or E5X artifacts derived from an approved canonical snapshot.';

CREATE TABLE IF NOT EXISTS ipca_safety_eccairs_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  submission_id BIGINT UNSIGNED NOT NULL,
  attempt_uuid CHAR(36) NOT NULL,
  operation VARCHAR(32) NOT NULL,
  attempt_number INT UNSIGNED NOT NULL,
  request_sha256 CHAR(64) NOT NULL,
  response_sha256 CHAR(64) NULL,
  http_status SMALLINT UNSIGNED NULL,
  outcome VARCHAR(32) NOT NULL,
  error_code VARCHAR(96) NULL,
  error_summary VARCHAR(1000) NULL,
  started_at_utc DATETIME(3) NOT NULL,
  completed_at_utc DATETIME(3) NULL,
  UNIQUE KEY uk_safety_e2_attempt_uuid (attempt_uuid),
  UNIQUE KEY uk_safety_e2_attempt_number (submission_id, operation, attempt_number),
  KEY idx_safety_e2_attempt_org (organization_id, started_at_utc),
  CONSTRAINT fk_safety_e2_attempt_submission FOREIGN KEY (submission_id)
    REFERENCES ipca_safety_eccairs_submissions(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Sanitized request/response digests and operational outcomes; never stores access tokens.';

CREATE TABLE IF NOT EXISTS ipca_safety_eccairs_status_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  submission_id BIGINT UNSIGNED NOT NULL,
  remote_e2_id VARCHAR(96) NOT NULL,
  remote_version VARCHAR(32) NULL,
  remote_status VARCHAR(32) NOT NULL,
  response_sha256 CHAR(64) NOT NULL,
  observed_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_safety_e2_status_observation
    (submission_id, remote_version, remote_status, response_sha256),
  KEY idx_safety_e2_status_org (organization_id, observed_at_utc),
  CONSTRAINT fk_safety_e2_status_submission FOREIGN KEY (submission_id)
    REFERENCES ipca_safety_eccairs_submissions(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_safety_legacy_document_extractions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  import_batch_uuid CHAR(36) NOT NULL,
  source_reference VARCHAR(255) NOT NULL,
  source_sha256 CHAR(64) NOT NULL,
  source_byte_size BIGINT UNSIGNED NOT NULL,
  extraction_method VARCHAR(64) NOT NULL,
  extraction_version VARCHAR(64) NOT NULL,
  extracted_payload_json JSON NOT NULL,
  extracted_payload_sha256 CHAR(64) NOT NULL,
  review_status VARCHAR(24) NOT NULL DEFAULT 'pending',
  reviewed_by_user_id BIGINT UNSIGNED NULL,
  reviewed_at_utc DATETIME(3) NULL,
  review_notes TEXT NULL,
  destruction_authorized_by_user_id BIGINT UNSIGNED NULL,
  destruction_authorized_at_utc DATETIME(3) NULL,
  destroyed_at_utc DATETIME(3) NULL,
  created_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_safety_legacy_document_source
    (organization_id, import_batch_uuid, source_reference),
  UNIQUE KEY uk_safety_legacy_document_digest
    (organization_id, import_batch_uuid, source_sha256),
  KEY idx_safety_legacy_document_review
    (organization_id, import_batch_uuid, review_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Restricted structured extraction and review provenance; source binaries are not application attachments.';

CREATE TABLE IF NOT EXISTS ipca_safety_eccairs_historical_correlations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  correlation_uuid CHAR(36) NOT NULL,
  taxonomy_package_id BIGINT UNSIGNED NOT NULL,
  source_system VARCHAR(96) NOT NULL,
  source_entity_type VARCHAR(64) NOT NULL,
  source_key VARCHAR(255) NOT NULL,
  target_type VARCHAR(48) NULL,
  target_id BIGINT UNSIGNED NULL,
  entity_code VARCHAR(64) NOT NULL,
  attribute_code VARCHAR(64) NULL,
  value_list_code VARCHAR(64) NULL,
  value_code VARCHAR(128) NULL,
  mapping_method VARCHAR(32) NOT NULL,
  confidence DECIMAL(5,4) NOT NULL,
  rationale TEXT NOT NULL,
  correlation_sha256 CHAR(64) NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'pending',
  proposed_by_user_id BIGINT UNSIGNED NULL,
  proposed_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  reviewed_by_user_id BIGINT UNSIGNED NULL,
  reviewed_at_utc DATETIME(3) NULL,
  UNIQUE KEY uk_safety_e2_historical_correlation_uuid (correlation_uuid),
  UNIQUE KEY uk_safety_e2_historical_correlation
    (organization_id, taxonomy_package_id, correlation_sha256),
  KEY idx_safety_e2_historical_review
    (organization_id, taxonomy_package_id, status, confidence),
  CONSTRAINT fk_safety_e2_historical_taxonomy FOREIGN KEY (taxonomy_package_id)
    REFERENCES ipca_safety_eccairs_taxonomy_packages(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Human-reviewed taxonomy correlations for historical data; never an E2 transmission queue.';
