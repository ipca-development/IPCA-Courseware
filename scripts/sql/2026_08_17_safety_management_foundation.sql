-- Professional Safety Management System foundation (MySQL 8).
-- Additive and re-run safe. Every business row is organization scoped.
-- Confidential identity data is deliberately isolated in ipca_safety_reporter_vault.

INSERT IGNORE INTO ipca_communication_app_config (config_key, config_value) VALUES
  ('safety_reporting_enabled', '0'),
  ('anonymous_reporting_enabled', '0');

CREATE TABLE IF NOT EXISTS ipca_safety_config (
  organization_id BIGINT UNSIGNED NOT NULL,
  config_key VARCHAR(96) NOT NULL,
  config_value JSON NOT NULL,
  updated_by_user_id BIGINT UNSIGNED NULL,
  updated_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (organization_id, config_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO ipca_safety_config (organization_id, config_key, config_value) VALUES
  (1, 'enabled', JSON_OBJECT('value', FALSE)),
  (1, 'anonymous_reporting_enabled', JSON_OBJECT('value', FALSE)),
  (1, 'attachments_enabled', JSON_OBJECT('value', TRUE)),
  (1, 'ai_enabled', JSON_OBJECT('value', FALSE));

CREATE TABLE IF NOT EXISTS ipca_safety_role_assignments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  role_code VARCHAR(48) NOT NULL,
  scope_type VARCHAR(32) NOT NULL DEFAULT 'organization',
  scope_id BIGINT UNSIGNED NULL,
  assigned_by_user_id BIGINT UNSIGNED NULL,
  valid_from_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  valid_until_utc DATETIME(3) NULL,
  revoked_at_utc DATETIME(3) NULL,
  UNIQUE KEY uk_safety_role (organization_id, user_id, role_code, scope_type, scope_id),
  KEY idx_safety_role_lookup (organization_id, user_id, revoked_at_utc, valid_until_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_safety_risk_matrix_versions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  version_uuid CHAR(36) NOT NULL,
  version_number INT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'draft',
  likelihood_scale JSON NOT NULL,
  severity_scale JSON NOT NULL,
  effective_from_utc DATETIME(3) NULL,
  retired_at_utc DATETIME(3) NULL,
  created_by_user_id BIGINT UNSIGNED NULL,
  created_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_safety_matrix_uuid (version_uuid),
  UNIQUE KEY uk_safety_matrix_version (organization_id, version_number),
  KEY idx_safety_matrix_active (organization_id, status, effective_from_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_safety_risk_matrix_cells (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  matrix_version_id BIGINT UNSIGNED NOT NULL,
  likelihood_code VARCHAR(32) NOT NULL,
  severity_code VARCHAR(32) NOT NULL,
  score DECIMAL(8,2) NOT NULL,
  band_code VARCHAR(32) NOT NULL,
  band_label VARCHAR(96) NOT NULL,
  color_hex CHAR(7) NULL,
  requires_acceptance_role VARCHAR(48) NULL,
  UNIQUE KEY uk_safety_matrix_cell (matrix_version_id, likelihood_code, severity_code),
  KEY idx_safety_matrix_cell_org (organization_id, matrix_version_id),
  CONSTRAINT fk_safety_matrix_cell_version FOREIGN KEY (matrix_version_id)
    REFERENCES ipca_safety_risk_matrix_versions(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_safety_reports (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  report_uuid CHAR(36) NOT NULL,
  report_number VARCHAR(48) NULL,
  channel VARCHAR(24) NOT NULL,
  reporter_user_id BIGINT UNSIGNED NULL,
  reporter_subject_hash CHAR(64) NULL,
  anonymous_mailbox_id BIGINT UNSIGNED NULL,
  category_code VARCHAR(64) NULL,
  title VARCHAR(240) NOT NULL,
  narrative MEDIUMTEXT NOT NULL,
  event_at_utc DATETIME(3) NULL,
  location_text VARCHAR(255) NULL,
  aircraft_registration VARCHAR(32) NULL,
  immediate_action TEXT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'draft',
  confidentiality VARCHAR(24) NOT NULL DEFAULT 'standard',
  owner_user_id BIGINT UNSIGNED NULL,
  submitted_at_utc DATETIME(3) NULL,
  triaged_at_utc DATETIME(3) NULL,
  closed_at_utc DATETIME(3) NULL,
  created_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_safety_report_uuid (report_uuid),
  UNIQUE KEY uk_safety_report_number (organization_id, report_number),
  KEY idx_safety_report_owner (organization_id, reporter_user_id, status, updated_at_utc),
  KEY idx_safety_report_subject (organization_id, reporter_subject_hash, status, updated_at_utc),
  KEY idx_safety_report_queue (organization_id, status, submitted_at_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_safety_reporter_vault (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  report_id BIGINT UNSIGNED NOT NULL,
  identity_ciphertext MEDIUMBLOB NOT NULL,
  key_reference VARCHAR(160) NOT NULL,
  identity_digest CHAR(64) NULL,
  created_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  accessed_at_utc DATETIME(3) NULL,
  UNIQUE KEY uk_safety_vault_report (report_id),
  KEY idx_safety_vault_org (organization_id, id),
  CONSTRAINT fk_safety_vault_report FOREIGN KEY (report_id)
    REFERENCES ipca_safety_reports(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Restricted confidential reporter identity vault; never return from general report queries.';

CREATE TABLE IF NOT EXISTS ipca_safety_anonymous_mailboxes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  mailbox_uuid CHAR(36) NOT NULL,
  receipt_code_hash CHAR(64) NOT NULL,
  secret_hash VARCHAR(255) NOT NULL,
  secret_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  failed_attempts INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until_utc DATETIME(3) NULL,
  last_accessed_at_utc DATETIME(3) NULL,
  expires_at_utc DATETIME(3) NULL,
  created_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_safety_mailbox_uuid (mailbox_uuid),
  UNIQUE KEY uk_safety_mailbox_receipt (organization_id, receipt_code_hash),
  KEY idx_safety_mailbox_expiry (organization_id, expires_at_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Only receipt and mailbox secrets hashes are persisted.';

CREATE TABLE IF NOT EXISTS ipca_safety_rate_limits (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  action_code VARCHAR(64) NOT NULL,
  fingerprint_hmac CHAR(64) NOT NULL,
  window_started_at_utc DATETIME(3) NOT NULL,
  request_count INT UNSIGNED NOT NULL DEFAULT 1,
  blocked_until_utc DATETIME(3) NULL,
  updated_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_safety_rate_window (organization_id, action_code, fingerprint_hmac, window_started_at_utc),
  KEY idx_safety_rate_cleanup (window_started_at_utc),
  KEY idx_safety_rate_block (organization_id, action_code, fingerprint_hmac, blocked_until_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Abuse controls store keyed HMAC fingerprints only; never raw network identifiers.';

CREATE TABLE IF NOT EXISTS ipca_safety_idempotency_keys (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  actor_type VARCHAR(24) NOT NULL,
  actor_key_hash CHAR(64) NOT NULL,
  operation_code VARCHAR(64) NOT NULL,
  idempotency_key_hash CHAR(64) NOT NULL,
  request_hash CHAR(64) NOT NULL,
  response_code SMALLINT UNSIGNED NULL,
  response_json JSON NULL,
  locked_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  completed_at_utc DATETIME(3) NULL,
  expires_at_utc DATETIME(3) NOT NULL,
  UNIQUE KEY uk_safety_idem (organization_id, actor_type, actor_key_hash, operation_code, idempotency_key_hash),
  KEY idx_safety_idem_expiry (expires_at_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_safety_attachments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  attachment_uuid CHAR(36) NOT NULL,
  report_id BIGINT UNSIGNED NOT NULL,
  uploaded_by_user_id BIGINT UNSIGNED NULL,
  uploader_reference_hash CHAR(64) NULL,
  anonymous_mailbox_id BIGINT UNSIGNED NULL,
  storage_key VARCHAR(512) NOT NULL,
  original_filename VARCHAR(255) NOT NULL,
  mime_type VARCHAR(128) NOT NULL,
  byte_size BIGINT UNSIGNED NOT NULL,
  sha256_hex CHAR(64) NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'pending',
  malware_scan_status VARCHAR(24) NOT NULL DEFAULT 'pending',
  created_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  uploaded_at_utc DATETIME(3) NULL,
  UNIQUE KEY uk_safety_attachment_uuid (attachment_uuid),
  UNIQUE KEY uk_safety_attachment_key (storage_key),
  KEY idx_safety_attachment_report (organization_id, report_id, status),
  CONSTRAINT fk_safety_attachment_report FOREIGN KEY (report_id)
    REFERENCES ipca_safety_reports(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_safety_reporter_updates (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  update_uuid CHAR(36) NOT NULL,
  report_id BIGINT UNSIGNED NOT NULL,
  direction VARCHAR(24) NOT NULL,
  author_user_id BIGINT UNSIGNED NULL,
  author_reference_hash CHAR(64) NULL,
  author_anonymous_mailbox_id BIGINT UNSIGNED NULL,
  body MEDIUMTEXT NOT NULL,
  visible_to_reporter TINYINT(1) NOT NULL DEFAULT 1,
  created_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_safety_update_uuid (update_uuid),
  KEY idx_safety_update_report (organization_id, report_id, created_at_utc),
  CONSTRAINT fk_safety_update_report FOREIGN KEY (report_id)
    REFERENCES ipca_safety_reports(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_safety_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  event_uuid CHAR(36) NOT NULL,
  aggregate_type VARCHAR(48) NOT NULL,
  aggregate_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(96) NOT NULL,
  actor_type VARCHAR(24) NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  actor_reference_hash CHAR(64) NULL,
  payload_json JSON NULL,
  previous_event_hash CHAR(64) NULL,
  event_hash CHAR(64) NOT NULL,
  occurred_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_safety_event_uuid (event_uuid),
  UNIQUE KEY uk_safety_event_hash (event_hash),
  KEY idx_safety_event_stream (organization_id, aggregate_type, aggregate_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Append-only tamper-evident domain event stream; application never updates or deletes rows.';

CREATE TABLE IF NOT EXISTS ipca_safety_occurrences (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  occurrence_uuid CHAR(36) NOT NULL,
  report_id BIGINT UNSIGNED NOT NULL,
  occurrence_type VARCHAR(96) NULL,
  occurred_at_utc DATETIME(3) NULL,
  state VARCHAR(32) NOT NULL DEFAULT 'candidate',
  regulator_reference VARCHAR(160) NULL,
  created_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_safety_occurrence_uuid (occurrence_uuid),
  KEY idx_safety_occurrence_report (organization_id, report_id),
  CONSTRAINT fk_safety_occurrence_report FOREIGN KEY (report_id)
    REFERENCES ipca_safety_reports(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_safety_reportability_assessments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  occurrence_id BIGINT UNSIGNED NOT NULL,
  framework_code VARCHAR(64) NOT NULL,
  decision VARCHAR(32) NOT NULL,
  rationale TEXT NOT NULL,
  deadline_at_utc DATETIME(3) NULL,
  authority_reference VARCHAR(160) NULL,
  assessed_by_user_id BIGINT UNSIGNED NOT NULL,
  assessed_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  supersedes_id BIGINT UNSIGNED NULL,
  KEY idx_safety_reportability (organization_id, occurrence_id, assessed_at_utc),
  CONSTRAINT fk_safety_reportability_occurrence FOREIGN KEY (occurrence_id)
    REFERENCES ipca_safety_occurrences(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_safety_taxonomy_nodes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  taxonomy_code VARCHAR(64) NOT NULL,
  parent_id BIGINT UNSIGNED NULL,
  label VARCHAR(180) NOT NULL,
  description TEXT NULL,
  node_type VARCHAR(48) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  UNIQUE KEY uk_safety_taxonomy_code (organization_id, taxonomy_code),
  KEY idx_safety_taxonomy_tree (organization_id, parent_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_safety_report_taxonomy (
  organization_id BIGINT UNSIGNED NOT NULL,
  report_id BIGINT UNSIGNED NOT NULL,
  taxonomy_node_id BIGINT UNSIGNED NOT NULL,
  assigned_by_user_id BIGINT UNSIGNED NULL,
  assigned_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (report_id, taxonomy_node_id),
  KEY idx_safety_report_tax_org (organization_id, taxonomy_node_id),
  CONSTRAINT fk_safety_report_tax_report FOREIGN KEY (report_id)
    REFERENCES ipca_safety_reports(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_safety_report_tax_node FOREIGN KEY (taxonomy_node_id)
    REFERENCES ipca_safety_taxonomy_nodes(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_safety_hazards (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  hazard_uuid CHAR(36) NOT NULL,
  source_report_id BIGINT UNSIGNED NULL,
  title VARCHAR(240) NOT NULL,
  description TEXT NOT NULL,
  hazard_status VARCHAR(32) NOT NULL DEFAULT 'open',
  owner_user_id BIGINT UNSIGNED NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  closed_at_utc DATETIME(3) NULL,
  UNIQUE KEY uk_safety_hazard_uuid (hazard_uuid),
  KEY idx_safety_hazard_queue (organization_id, hazard_status, owner_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_safety_controls (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  control_uuid CHAR(36) NOT NULL,
  title VARCHAR(240) NOT NULL,
  description TEXT NOT NULL,
  control_type VARCHAR(48) NOT NULL,
  owner_user_id BIGINT UNSIGNED NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'proposed',
  created_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_safety_control_uuid (control_uuid),
  KEY idx_safety_control_org (organization_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_safety_hazard_controls (
  organization_id BIGINT UNSIGNED NOT NULL,
  hazard_id BIGINT UNSIGNED NOT NULL,
  control_id BIGINT UNSIGNED NOT NULL,
  relationship_type VARCHAR(32) NOT NULL DEFAULT 'mitigates',
  effectiveness_state VARCHAR(32) NOT NULL DEFAULT 'unverified',
  PRIMARY KEY (hazard_id, control_id),
  KEY idx_safety_hazard_control_org (organization_id, control_id),
  CONSTRAINT fk_safety_hc_hazard FOREIGN KEY (hazard_id)
    REFERENCES ipca_safety_hazards(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_safety_hc_control FOREIGN KEY (control_id)
    REFERENCES ipca_safety_controls(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_safety_risk_snapshots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  hazard_id BIGINT UNSIGNED NOT NULL,
  matrix_version_id BIGINT UNSIGNED NOT NULL,
  phase VARCHAR(24) NOT NULL,
  likelihood_code VARCHAR(32) NOT NULL,
  severity_code VARCHAR(32) NOT NULL,
  score DECIMAL(8,2) NOT NULL,
  band_code VARCHAR(32) NOT NULL,
  rationale TEXT NOT NULL,
  assessed_by_user_id BIGINT UNSIGNED NOT NULL,
  assessed_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  accepted_by_user_id BIGINT UNSIGNED NULL,
  accepted_at_utc DATETIME(3) NULL,
  KEY idx_safety_risk_hazard (organization_id, hazard_id, assessed_at_utc),
  CONSTRAINT fk_safety_risk_hazard FOREIGN KEY (hazard_id)
    REFERENCES ipca_safety_hazards(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_safety_risk_matrix FOREIGN KEY (matrix_version_id)
    REFERENCES ipca_safety_risk_matrix_versions(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_safety_investigations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  investigation_uuid CHAR(36) NOT NULL,
  report_id BIGINT UNSIGNED NOT NULL,
  lead_user_id BIGINT UNSIGNED NULL,
  scope_text TEXT NOT NULL,
  methodology VARCHAR(64) NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'planned',
  started_at_utc DATETIME(3) NULL,
  completed_at_utc DATETIME(3) NULL,
  created_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_safety_investigation_uuid (investigation_uuid),
  KEY idx_safety_investigation_queue (organization_id, status, lead_user_id),
  CONSTRAINT fk_safety_investigation_report FOREIGN KEY (report_id)
    REFERENCES ipca_safety_reports(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_safety_investigation_evidence (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  investigation_id BIGINT UNSIGNED NOT NULL,
  evidence_uuid CHAR(36) NOT NULL,
  evidence_type VARCHAR(48) NOT NULL,
  attachment_id BIGINT UNSIGNED NULL,
  source_reference VARCHAR(255) NULL,
  description TEXT NOT NULL,
  integrity_sha256 CHAR(64) NULL,
  collected_by_user_id BIGINT UNSIGNED NOT NULL,
  collected_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_safety_inv_evidence_uuid (evidence_uuid),
  KEY idx_safety_inv_evidence (organization_id, investigation_id),
  CONSTRAINT fk_safety_inv_evidence_investigation FOREIGN KEY (investigation_id)
    REFERENCES ipca_safety_investigations(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_safety_investigation_factors (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  investigation_id BIGINT UNSIGNED NOT NULL,
  parent_factor_id BIGINT UNSIGNED NULL,
  factor_type VARCHAR(64) NOT NULL,
  statement_text TEXT NOT NULL,
  causal_role VARCHAR(32) NOT NULL,
  confidence DECIMAL(5,4) NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY idx_safety_inv_factor (organization_id, investigation_id, factor_type),
  CONSTRAINT fk_safety_inv_factor_investigation FOREIGN KEY (investigation_id)
    REFERENCES ipca_safety_investigations(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_safety_actions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  action_uuid CHAR(36) NOT NULL,
  source_type VARCHAR(48) NOT NULL,
  source_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(240) NOT NULL,
  description TEXT NOT NULL,
  owner_user_id BIGINT UNSIGNED NOT NULL,
  due_at_utc DATETIME(3) NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'open',
  priority VARCHAR(24) NOT NULL DEFAULT 'normal',
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  completed_at_utc DATETIME(3) NULL,
  UNIQUE KEY uk_safety_action_uuid (action_uuid),
  KEY idx_safety_action_queue (organization_id, owner_user_id, status, due_at_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_safety_action_evidence (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  action_id BIGINT UNSIGNED NOT NULL,
  attachment_id BIGINT UNSIGNED NULL,
  note_text TEXT NULL,
  submitted_by_user_id BIGINT UNSIGNED NOT NULL,
  submitted_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY idx_safety_action_evidence (organization_id, action_id),
  CONSTRAINT fk_safety_action_evidence_action FOREIGN KEY (action_id)
    REFERENCES ipca_safety_actions(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_safety_action_effectiveness_reviews (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  action_id BIGINT UNSIGNED NOT NULL,
  outcome VARCHAR(32) NOT NULL,
  method_text TEXT NOT NULL,
  result_text TEXT NOT NULL,
  reviewed_by_user_id BIGINT UNSIGNED NOT NULL,
  reviewed_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  follow_up_due_at_utc DATETIME(3) NULL,
  KEY idx_safety_action_effectiveness (organization_id, action_id, reviewed_at_utc),
  CONSTRAINT fk_safety_action_effectiveness_action FOREIGN KEY (action_id)
    REFERENCES ipca_safety_actions(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_safety_action_closures (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  action_id BIGINT UNSIGNED NOT NULL,
  effectiveness_review_id BIGINT UNSIGNED NOT NULL,
  closure_rationale TEXT NOT NULL,
  closed_by_user_id BIGINT UNSIGNED NOT NULL,
  closed_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_safety_action_closure (action_id),
  CONSTRAINT fk_safety_action_closure_action FOREIGN KEY (action_id)
    REFERENCES ipca_safety_actions(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_safety_action_closure_review FOREIGN KEY (effectiveness_review_id)
    REFERENCES ipca_safety_action_effectiveness_reviews(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_safety_report_closures (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  report_id BIGINT UNSIGNED NOT NULL,
  closure_rationale TEXT NOT NULL,
  closed_by_user_id BIGINT UNSIGNED NOT NULL,
  closed_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY idx_safety_report_closure_report (organization_id, report_id, closed_at_utc),
  CONSTRAINT fk_safety_report_closure_report FOREIGN KEY (report_id)
    REFERENCES ipca_safety_reports(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Human-approved closure decision after all SMS feedback-loop gates pass.';

CREATE TABLE IF NOT EXISTS ipca_safety_links (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  from_type VARCHAR(48) NOT NULL,
  from_id BIGINT UNSIGNED NOT NULL,
  to_type VARCHAR(48) NOT NULL,
  to_id BIGINT UNSIGNED NOT NULL,
  relationship_type VARCHAR(48) NOT NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_safety_link (organization_id, from_type, from_id, to_type, to_id, relationship_type),
  KEY idx_safety_link_reverse (organization_id, to_type, to_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_safety_bulletins (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  bulletin_uuid CHAR(36) NOT NULL,
  title VARCHAR(240) NOT NULL,
  body MEDIUMTEXT NOT NULL,
  audience_json JSON NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'draft',
  requires_acknowledgement TINYINT(1) NOT NULL DEFAULT 0,
  published_by_user_id BIGINT UNSIGNED NULL,
  published_at_utc DATETIME(3) NULL,
  expires_at_utc DATETIME(3) NULL,
  created_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_safety_bulletin_uuid (bulletin_uuid),
  KEY idx_safety_bulletin_active (organization_id, status, published_at_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_safety_bulletin_acknowledgements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  bulletin_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  acknowledged_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  evidence_json JSON NULL,
  UNIQUE KEY uk_safety_bulletin_ack (bulletin_id, user_id),
  KEY idx_safety_bulletin_ack_org (organization_id, user_id),
  CONSTRAINT fk_safety_bulletin_ack_bulletin FOREIGN KEY (bulletin_id)
    REFERENCES ipca_safety_bulletins(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_safety_analytics_snapshots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  snapshot_uuid CHAR(36) NOT NULL,
  period_start_utc DATETIME(3) NOT NULL,
  period_end_utc DATETIME(3) NOT NULL,
  dimensions_json JSON NOT NULL,
  metrics_json JSON NOT NULL,
  generated_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  source_event_high_watermark BIGINT UNSIGNED NULL,
  UNIQUE KEY uk_safety_analytics_uuid (snapshot_uuid),
  KEY idx_safety_analytics_period (organization_id, period_start_utc, period_end_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_safety_exposure_snapshots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  snapshot_uuid CHAR(36) NOT NULL,
  period_start_utc DATETIME(3) NOT NULL,
  period_end_utc DATETIME(3) NOT NULL,
  exposure_unit VARCHAR(48) NOT NULL,
  exposure_value DECIMAL(18,6) NOT NULL,
  source_system VARCHAR(96) NOT NULL,
  source_reference VARCHAR(255) NOT NULL,
  source_digest CHAR(64) NOT NULL,
  dimensions_json JSON NOT NULL,
  provenance_json JSON NOT NULL,
  recorded_by_user_id BIGINT UNSIGNED NOT NULL,
  recorded_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_safety_exposure_uuid (snapshot_uuid),
  UNIQUE KEY uk_safety_exposure_source (organization_id, source_system, source_digest),
  KEY idx_safety_exposure_period (organization_id, exposure_unit, period_start_utc, period_end_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Immutable exposure denominators with source digest and de-identified provenance.';

CREATE TABLE IF NOT EXISTS ipca_safety_cross_domain_snapshots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  snapshot_uuid CHAR(36) NOT NULL,
  domain_code VARCHAR(48) NOT NULL,
  subject_type VARCHAR(48) NOT NULL,
  subject_reference_digest CHAR(64) NOT NULL,
  captured_at_utc DATETIME(3) NOT NULL,
  metrics_json JSON NOT NULL,
  metrics_digest CHAR(64) NOT NULL,
  provenance_json JSON NOT NULL,
  recorded_by_user_id BIGINT UNSIGNED NOT NULL,
  recorded_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_safety_cross_snapshot_uuid (snapshot_uuid),
  UNIQUE KEY uk_safety_cross_snapshot_source
    (organization_id, domain_code, subject_type, subject_reference_digest, captured_at_utc, metrics_digest),
  KEY idx_safety_cross_snapshot_domain (organization_id, domain_code, captured_at_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='De-identified point-in-time metrics; no direct operational-table joins are permitted.';

CREATE TABLE IF NOT EXISTS ipca_safety_cross_domain_links (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  link_uuid CHAR(36) NOT NULL,
  safety_subject_type VARCHAR(48) NOT NULL,
  safety_subject_id BIGINT UNSIGNED NOT NULL,
  snapshot_id BIGINT UNSIGNED NOT NULL,
  relationship_type VARCHAR(48) NOT NULL,
  link_rationale TEXT NOT NULL,
  approved_by_user_id BIGINT UNSIGNED NOT NULL,
  approved_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_safety_cross_link_uuid (link_uuid),
  UNIQUE KEY uk_safety_cross_link
    (organization_id, safety_subject_type, safety_subject_id, snapshot_id, relationship_type),
  KEY idx_safety_cross_link_snapshot (organization_id, snapshot_id),
  CONSTRAINT fk_safety_cross_link_snapshot FOREIGN KEY (snapshot_id)
    REFERENCES ipca_safety_cross_domain_snapshots(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Explicit human-approved bridge required for every cross-domain analytical pair.';

CREATE TABLE IF NOT EXISTS ipca_safety_spis (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  spi_uuid CHAR(36) NOT NULL,
  code VARCHAR(64) NOT NULL,
  name VARCHAR(180) NOT NULL,
  definition_json JSON NOT NULL,
  target_value DECIMAL(18,6) NULL,
  warning_value DECIMAL(18,6) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uk_safety_spi_uuid (spi_uuid),
  UNIQUE KEY uk_safety_spi_code (organization_id, code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_safety_spi_values (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  spi_id BIGINT UNSIGNED NOT NULL,
  period_start_utc DATETIME(3) NOT NULL,
  period_end_utc DATETIME(3) NOT NULL,
  value_decimal DECIMAL(18,6) NOT NULL,
  numerator DECIMAL(18,6) NULL,
  denominator DECIMAL(18,6) NULL,
  computed_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_safety_spi_period (spi_id, period_start_utc, period_end_utc),
  KEY idx_safety_spi_value_org (organization_id, period_end_utc),
  CONSTRAINT fk_safety_spi_value_spi FOREIGN KEY (spi_id)
    REFERENCES ipca_safety_spis(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_safety_ai_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  run_uuid CHAR(36) NOT NULL,
  use_case VARCHAR(64) NOT NULL,
  subject_type VARCHAR(48) NOT NULL,
  subject_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(64) NOT NULL,
  model VARCHAR(96) NOT NULL,
  prompt_template_version VARCHAR(64) NOT NULL,
  input_digest CHAR(64) NOT NULL,
  output_json JSON NULL,
  output_digest CHAR(64) NULL,
  data_classification VARCHAR(32) NOT NULL,
  deidentification_version VARCHAR(64) NOT NULL,
  provenance_json JSON NOT NULL,
  provider_provenance_json JSON NULL,
  status VARCHAR(24) NOT NULL,
  requested_by_user_id BIGINT UNSIGNED NOT NULL,
  requested_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  completed_at_utc DATETIME(3) NULL,
  UNIQUE KEY uk_safety_ai_run_uuid (run_uuid),
  KEY idx_safety_ai_subject (organization_id, subject_type, subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_safety_ai_reviews (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  ai_run_id BIGINT UNSIGNED NOT NULL,
  decision VARCHAR(24) NOT NULL,
  reviewer_user_id BIGINT UNSIGNED NOT NULL,
  review_notes TEXT NOT NULL,
  reviewed_output_digest CHAR(64) NOT NULL,
  reviewed_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_safety_ai_review (ai_run_id),
  CONSTRAINT fk_safety_ai_review_run FOREIGN KEY (ai_run_id)
    REFERENCES ipca_safety_ai_runs(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_safety_legacy_staging (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  import_batch_uuid CHAR(36) NOT NULL,
  source_system VARCHAR(96) NOT NULL,
  source_entity_type VARCHAR(64) NOT NULL,
  source_key VARCHAR(255) NOT NULL,
  payload_json JSON NOT NULL,
  payload_sha256 CHAR(64) NOT NULL,
  validation_status VARCHAR(24) NOT NULL DEFAULT 'pending',
  validation_errors_json JSON NULL,
  reviewed_by_user_id BIGINT UNSIGNED NULL,
  reviewed_at_utc DATETIME(3) NULL,
  review_rationale TEXT NULL,
  staged_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_safety_legacy_source (organization_id, source_system, source_entity_type, source_key),
  KEY idx_safety_legacy_batch (organization_id, import_batch_uuid, validation_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_safety_import_provenance (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  staging_id BIGINT UNSIGNED NOT NULL,
  target_type VARCHAR(48) NOT NULL,
  target_id BIGINT UNSIGNED NOT NULL,
  import_action VARCHAR(24) NOT NULL,
  imported_by_user_id BIGINT UNSIGNED NOT NULL,
  imported_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  mapping_version VARCHAR(64) NOT NULL,
  UNIQUE KEY uk_safety_import_target (staging_id, target_type, target_id),
  KEY idx_safety_import_prov_org (organization_id, target_type, target_id),
  CONSTRAINT fk_safety_import_staging FOREIGN KEY (staging_id)
    REFERENCES ipca_safety_legacy_staging(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
