-- Manual Change Architect persistent schema foundation.
-- Additive and re-run safe. Existing AI Manual Change Assistant tables are referenced read-only.
-- Draft, review, and operation records are reserved for future runtime implementation.

CREATE TABLE IF NOT EXISTS ipca_manual_ai_architect_plans (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  plan_uuid CHAR(36) NOT NULL,
  primary_book_version_id BIGINT UNSIGNED NOT NULL,
  linked_legacy_project_id BIGINT UNSIGNED NULL,
  title VARCHAR(255) NOT NULL,
  change_request MEDIUMTEXT NOT NULL,
  objective TEXT NOT NULL,
  stage VARCHAR(32) NOT NULL DEFAULT 'intake' COMMENT 'intake | evidence | target_state | impact | scope | structure | drafting | review | operations | complete',
  status VARCHAR(32) NOT NULL DEFAULT 'draft' COMMENT 'draft | active | blocked | ready_for_review | approved | completed | cancelled | archived',
  source_fingerprint CHAR(64) NOT NULL,
  evidence_fingerprint CHAR(64) NULL,
  target_state_fingerprint CHAR(64) NULL,
  scope_fingerprint CHAR(64) NULL,
  plan_fingerprint CHAR(64) NOT NULL,
  owner_id INT NOT NULL,
  created_by INT NOT NULL,
  updated_by INT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_imaa_plan_uuid (plan_uuid),
  KEY idx_imaa_plan_owner (owner_id, status, stage, updated_at),
  KEY idx_imaa_plan_version (primary_book_version_id, status),
  KEY idx_imaa_plan_legacy (linked_legacy_project_id),
  KEY idx_imaa_plan_fingerprint (plan_fingerprint),
  CONSTRAINT fk_imaa_plan_version
    FOREIGN KEY (primary_book_version_id) REFERENCES ipca_publishing_book_versions(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_plan_legacy
    FOREIGN KEY (linked_legacy_project_id) REFERENCES ipca_manual_ai_projects(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_plan_owner
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_plan_creator
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_plan_updater
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_manual_ai_architect_evidence (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  plan_id BIGINT UNSIGNED NOT NULL,
  evidence_uuid CHAR(36) NOT NULL,
  evidence_type VARCHAR(32) NOT NULL COMMENT 'source_revision | regulation | policy | interview | legacy_content | external_reference | other',
  title VARCHAR(255) NOT NULL,
  source_uri VARCHAR(1000) NULL,
  source_reference VARCHAR(255) NULL,
  source_manual_ai_source_id BIGINT UNSIGNED NULL,
  book_version_id BIGINT UNSIGNED NULL,
  section_id BIGINT UNSIGNED NULL,
  block_id BIGINT UNSIGNED NULL,
  stable_anchor VARCHAR(191) NULL,
  excerpt MEDIUMTEXT NULL,
  evidence_payload_json JSON NULL,
  content_fingerprint CHAR(64) NOT NULL,
  captured_by INT NOT NULL,
  captured_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_imaa_evidence_uuid (evidence_uuid),
  UNIQUE KEY uk_imaa_evidence_plan_hash (plan_id, content_fingerprint),
  KEY idx_imaa_evidence_plan_type (plan_id, evidence_type, captured_at),
  KEY idx_imaa_evidence_source (source_manual_ai_source_id),
  KEY idx_imaa_evidence_location (book_version_id, section_id, block_id),
  CONSTRAINT fk_imaa_evidence_plan
    FOREIGN KEY (plan_id) REFERENCES ipca_manual_ai_architect_plans(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_evidence_source
    FOREIGN KEY (source_manual_ai_source_id) REFERENCES ipca_manual_ai_sources(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_evidence_version
    FOREIGN KEY (book_version_id) REFERENCES ipca_publishing_book_versions(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_evidence_section
    FOREIGN KEY (section_id) REFERENCES ipca_publishing_book_sections(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_evidence_block
    FOREIGN KEY (block_id) REFERENCES ipca_publishing_book_blocks(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_evidence_actor
    FOREIGN KEY (captured_by) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_manual_ai_architect_change_intents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  plan_id BIGINT UNSIGNED NOT NULL,
  intent_uuid CHAR(36) NOT NULL,
  intent_key VARCHAR(191) NOT NULL,
  intent_type VARCHAR(64) NOT NULL,
  title VARCHAR(255) NOT NULL,
  statement TEXT NOT NULL,
  rationale TEXT NULL,
  desired_outcome TEXT NOT NULL,
  required_outcomes_json JSON NOT NULL,
  constraints_json JSON NOT NULL,
  preserved_concepts_json JSON NOT NULL,
  known_limitations_json JSON NOT NULL,
  authoritative_facts_json JSON NOT NULL,
  scope_interpretation_json JSON NOT NULL,
  reasoning_json JSON NULL,
  model_name VARCHAR(191) NULL,
  prompt_version VARCHAR(64) NOT NULL,
  priority VARCHAR(16) NOT NULL DEFAULT 'normal' COMMENT 'low | normal | high | critical',
  status VARCHAR(24) NOT NULL DEFAULT 'proposed' COMMENT 'proposed | validated | approved | superseded | rejected',
  intent_fingerprint CHAR(64) NOT NULL,
  source_legacy_intent_id BIGINT UNSIGNED NULL,
  created_by INT NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_imaa_intent_uuid (intent_uuid),
  UNIQUE KEY uk_imaa_intent_plan_key (plan_id, intent_key),
  UNIQUE KEY uk_imaa_intent_plan_hash (plan_id, intent_fingerprint),
  KEY idx_imaa_intent_plan_status (plan_id, status, priority),
  KEY idx_imaa_intent_legacy (source_legacy_intent_id),
  CONSTRAINT fk_imaa_intent_plan
    FOREIGN KEY (plan_id) REFERENCES ipca_manual_ai_architect_plans(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_intent_legacy
    FOREIGN KEY (source_legacy_intent_id) REFERENCES ipca_manual_ai_change_intents(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_intent_actor
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_manual_ai_architect_target_components (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  plan_id BIGINT UNSIGNED NOT NULL,
  component_uuid CHAR(36) NOT NULL,
  parent_component_id BIGINT UNSIGNED NULL,
  component_key VARCHAR(191) NOT NULL,
  component_type VARCHAR(48) NOT NULL COMMENT 'lifecycle | role | responsibility | human_decision | automatic_action | record_evidence | deadline | control | approval | monitoring | training | closure | limitation | other',
  title VARCHAR(255) NOT NULL,
  target_state TEXT NOT NULL,
  current_state_assumption TEXT NULL,
  manual_level_expression TEXT NULL,
  implementation_details_json JSON NULL,
  source_evidence_json JSON NOT NULL,
  dependencies_json JSON NOT NULL,
  acceptance_criteria_json JSON NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'proposed' COMMENT 'proposed | validated | approved | superseded',
  component_fingerprint CHAR(64) NOT NULL,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_imaa_component_uuid (component_uuid),
  UNIQUE KEY uk_imaa_component_plan_key (plan_id, component_key),
  UNIQUE KEY uk_imaa_component_plan_hash (plan_id, component_fingerprint),
  KEY idx_imaa_component_plan (plan_id, status, component_type, sort_order),
  KEY idx_imaa_component_parent (parent_component_id, sort_order),
  CONSTRAINT fk_imaa_component_plan
    FOREIGN KEY (plan_id) REFERENCES ipca_manual_ai_architect_plans(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_component_parent
    FOREIGN KEY (parent_component_id) REFERENCES ipca_manual_ai_architect_target_components(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_manual_ai_architect_target_coverage (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  plan_id BIGINT UNSIGNED NOT NULL,
  change_intent_id BIGINT UNSIGNED NOT NULL,
  target_component_id BIGINT UNSIGNED NOT NULL,
  evidence_id BIGINT UNSIGNED NULL,
  section_id BIGINT UNSIGNED NULL,
  impact_id BIGINT UNSIGNED NULL,
  coverage_type VARCHAR(24) NOT NULL DEFAULT 'direct' COMMENT 'direct | supporting | partial | conflicting | gap',
  coverage_status VARCHAR(32) NOT NULL DEFAULT 'REVIEW_REQUIRED' COMMENT 'PRESERVED_COVERED | AMEND_EXISTING | ADD_CONTENT | NOT_APPLICABLE | REVIEW_REQUIRED',
  current_coverage TEXT NULL,
  required_change TEXT NULL,
  rationale TEXT NOT NULL,
  canonical_evidence_json JSON NOT NULL,
  confidence DECIMAL(5,4) NULL,
  coverage_fingerprint CHAR(64) NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_imaa_coverage_hash (plan_id, coverage_fingerprint),
  UNIQUE KEY uk_imaa_coverage_matrix (change_intent_id, target_component_id, evidence_id, coverage_type),
  KEY idx_imaa_coverage_plan_status (plan_id, coverage_status),
  KEY idx_imaa_coverage_component (target_component_id, coverage_status),
  KEY idx_imaa_coverage_evidence (evidence_id),
  KEY idx_imaa_coverage_section (section_id, coverage_status),
  KEY idx_imaa_coverage_impact (impact_id, coverage_status),
  CONSTRAINT fk_imaa_coverage_plan
    FOREIGN KEY (plan_id) REFERENCES ipca_manual_ai_architect_plans(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_coverage_intent
    FOREIGN KEY (change_intent_id) REFERENCES ipca_manual_ai_architect_change_intents(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_coverage_component
    FOREIGN KEY (target_component_id) REFERENCES ipca_manual_ai_architect_target_components(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_coverage_evidence
    FOREIGN KEY (evidence_id) REFERENCES ipca_manual_ai_architect_evidence(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_coverage_section
    FOREIGN KEY (section_id) REFERENCES ipca_publishing_book_sections(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_manual_ai_architect_scope_boundaries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  plan_id BIGINT UNSIGNED NOT NULL,
  boundary_uuid CHAR(36) NOT NULL,
  boundary_key VARCHAR(191) NOT NULL,
  classification VARCHAR(32) NOT NULL COMMENT 'MUST_CHANGE | MUST_PRESERVE | OUT_OF_SCOPE | REVIEW_SEPARATELY',
  subject_type VARCHAR(32) NOT NULL COMMENT 'plan | intent | component | version | section | block | concept | other',
  subject_reference VARCHAR(500) NOT NULL,
  book_version_id BIGINT UNSIGNED NULL,
  section_id BIGINT UNSIGNED NULL,
  block_id BIGINT UNSIGNED NULL,
  rationale TEXT NOT NULL,
  source_evidence_id BIGINT UNSIGNED NULL,
  boundary_fingerprint CHAR(64) NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'active' COMMENT 'active | superseded | resolved',
  created_by INT NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_imaa_boundary_uuid (boundary_uuid),
  UNIQUE KEY uk_imaa_boundary_plan_key (plan_id, boundary_key),
  UNIQUE KEY uk_imaa_boundary_plan_hash (plan_id, boundary_fingerprint),
  KEY idx_imaa_boundary_plan (plan_id, classification, status),
  KEY idx_imaa_boundary_location (book_version_id, section_id, block_id),
  KEY idx_imaa_boundary_evidence (source_evidence_id),
  CONSTRAINT fk_imaa_boundary_plan
    FOREIGN KEY (plan_id) REFERENCES ipca_manual_ai_architect_plans(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_boundary_version
    FOREIGN KEY (book_version_id) REFERENCES ipca_publishing_book_versions(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_boundary_section
    FOREIGN KEY (section_id) REFERENCES ipca_publishing_book_sections(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_boundary_block
    FOREIGN KEY (block_id) REFERENCES ipca_publishing_book_blocks(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_boundary_evidence
    FOREIGN KEY (source_evidence_id) REFERENCES ipca_manual_ai_architect_evidence(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_boundary_actor
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_manual_ai_architect_impacts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  plan_id BIGINT UNSIGNED NOT NULL,
  impact_uuid CHAR(36) NOT NULL,
  impact_key VARCHAR(191) NOT NULL,
  change_intent_id BIGINT UNSIGNED NULL,
  target_component_id BIGINT UNSIGNED NULL,
  section_id BIGINT UNSIGNED NULL,
  section_number VARCHAR(64) NULL,
  section_title VARCHAR(500) NOT NULL,
  impact_type VARCHAR(48) NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NOT NULL,
  treatment VARCHAR(32) NOT NULL COMMENT 'PRESERVE | AMEND | REPLACE | RESTRUCTURE | ADD | REMOVE_OBSOLETE | REVIEW_SEPARATELY',
  boundary_classification VARCHAR(32) NOT NULL COMMENT 'MUST_CHANGE | MUST_PRESERVE | OUT_OF_SCOPE | REVIEW_SEPARATELY',
  substantive_rationale TEXT NOT NULL,
  current_state_summary TEXT NOT NULL,
  target_concepts_json JSON NOT NULL,
  preserved_logic_json JSON NOT NULL,
  canonical_evidence_json JSON NOT NULL,
  dependencies_json JSON NOT NULL,
  minimality_test TEXT NOT NULL,
  completeness_test TEXT NOT NULL,
  confidence DECIMAL(5,4) NOT NULL,
  severity VARCHAR(16) NOT NULL DEFAULT 'normal' COMMENT 'low | normal | high | critical',
  status VARCHAR(24) NOT NULL DEFAULT 'proposed' COMMENT 'proposed | validated | approved | dismissed | resolved',
  impact_fingerprint CHAR(64) NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_imaa_impact_uuid (impact_uuid),
  UNIQUE KEY uk_imaa_impact_plan_key (plan_id, impact_key),
  UNIQUE KEY uk_imaa_impact_plan_hash (plan_id, impact_fingerprint),
  KEY idx_imaa_impact_plan (plan_id, status, severity),
  KEY idx_imaa_impact_intent (change_intent_id, status),
  KEY idx_imaa_impact_component (target_component_id, status),
  KEY idx_imaa_impact_section (section_id, status),
  CONSTRAINT fk_imaa_impact_plan
    FOREIGN KEY (plan_id) REFERENCES ipca_manual_ai_architect_plans(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_impact_intent
    FOREIGN KEY (change_intent_id) REFERENCES ipca_manual_ai_architect_change_intents(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_impact_component
    FOREIGN KEY (target_component_id) REFERENCES ipca_manual_ai_architect_target_components(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_impact_section
    FOREIGN KEY (section_id) REFERENCES ipca_publishing_book_sections(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_manual_ai_architect_impact_dependencies (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  plan_id BIGINT UNSIGNED NOT NULL,
  impact_id BIGINT UNSIGNED NOT NULL,
  depends_on_impact_id BIGINT UNSIGNED NOT NULL,
  dependency_type VARCHAR(32) NOT NULL COMMENT 'requires | blocks | informs | conflicts_with | duplicates | sequence_after',
  rationale TEXT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'active' COMMENT 'active | resolved | superseded',
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_imaa_impact_dependency (impact_id, depends_on_impact_id, dependency_type),
  KEY idx_imaa_dependency_plan (plan_id, status),
  KEY idx_imaa_dependency_target (depends_on_impact_id, status),
  CONSTRAINT fk_imaa_dependency_plan
    FOREIGN KEY (plan_id) REFERENCES ipca_manual_ai_architect_plans(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_dependency_impact
    FOREIGN KEY (impact_id) REFERENCES ipca_manual_ai_architect_impacts(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_dependency_target
    FOREIGN KEY (depends_on_impact_id) REFERENCES ipca_manual_ai_architect_impacts(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_manual_ai_architect_legacy_hits (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  plan_id BIGINT UNSIGNED NOT NULL,
  hit_uuid CHAR(36) NOT NULL,
  hit_key CHAR(64) NOT NULL,
  impact_id BIGINT UNSIGNED NULL,
  change_intent_id BIGINT UNSIGNED NULL,
  source_legacy_hit_id BIGINT UNSIGNED NULL,
  book_version_id BIGINT UNSIGNED NOT NULL,
  section_id BIGINT UNSIGNED NOT NULL,
  block_id BIGINT UNSIGNED NOT NULL,
  stable_anchor VARCHAR(191) NOT NULL,
  identity_type VARCHAR(32) NOT NULL COMMENT 'system_name | url | portal | form | role_assignment | workflow_term | other',
  legacy_identity VARCHAR(500) NOT NULL,
  exact_text VARCHAR(1000) NOT NULL,
  match_method VARCHAR(24) NOT NULL COMMENT 'exact | normalized | contextual',
  match_start INT UNSIGNED NULL,
  match_length INT UNSIGNED NULL,
  context_excerpt TEXT NOT NULL,
  block_content_hash CHAR(64) NOT NULL,
  disposition VARCHAR(32) NOT NULL COMMENT 'REMOVE_OR_REPLACE | PRESERVE_WITH_JUSTIFICATION | REVIEW_SEPARATELY',
  disposition_justification TEXT NULL,
  confidence DECIMAL(5,4) NOT NULL,
  proposed_by_model VARCHAR(191) NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'unreviewed' COMMENT 'unreviewed | decided | superseded | resolved',
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_imaa_hit_uuid (hit_uuid),
  UNIQUE KEY uk_imaa_hit_plan_key (plan_id, hit_key),
  KEY idx_imaa_hit_plan_disposition (plan_id, disposition, status),
  KEY idx_imaa_hit_location (book_version_id, section_id, block_id),
  KEY idx_imaa_hit_impact (impact_id),
  KEY idx_imaa_hit_intent (change_intent_id),
  KEY idx_imaa_hit_legacy (source_legacy_hit_id),
  CONSTRAINT fk_imaa_hit_plan
    FOREIGN KEY (plan_id) REFERENCES ipca_manual_ai_architect_plans(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_hit_impact
    FOREIGN KEY (impact_id) REFERENCES ipca_manual_ai_architect_impacts(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_hit_intent
    FOREIGN KEY (change_intent_id) REFERENCES ipca_manual_ai_architect_change_intents(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_hit_legacy
    FOREIGN KEY (source_legacy_hit_id) REFERENCES ipca_manual_ai_legacy_hits(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_hit_version
    FOREIGN KEY (book_version_id) REFERENCES ipca_publishing_book_versions(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_hit_section
    FOREIGN KEY (section_id) REFERENCES ipca_publishing_book_sections(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_hit_block
    FOREIGN KEY (block_id) REFERENCES ipca_publishing_book_blocks(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_manual_ai_architect_legacy_hit_decisions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  decision_uuid CHAR(36) NOT NULL,
  plan_id BIGINT UNSIGNED NOT NULL,
  legacy_hit_id BIGINT UNSIGNED NOT NULL,
  previous_decision_id BIGINT UNSIGNED NULL,
  disposition VARCHAR(32) NOT NULL COMMENT 'REMOVE_OR_REPLACE | PRESERVE_WITH_JUSTIFICATION | REVIEW_SEPARATELY',
  justification TEXT NOT NULL,
  decision_fingerprint CHAR(64) NOT NULL,
  decided_by INT NOT NULL,
  decided_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_imaa_hit_decision_uuid (decision_uuid),
  UNIQUE KEY uk_imaa_hit_decision_hash (legacy_hit_id, decision_fingerprint),
  KEY idx_imaa_hit_decision_history (legacy_hit_id, decided_at, id),
  KEY idx_imaa_hit_decision_plan (plan_id, decided_at),
  KEY idx_imaa_hit_decision_previous (previous_decision_id),
  CONSTRAINT fk_imaa_hit_decision_plan
    FOREIGN KEY (plan_id) REFERENCES ipca_manual_ai_architect_plans(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_hit_decision_hit
    FOREIGN KEY (legacy_hit_id) REFERENCES ipca_manual_ai_architect_legacy_hits(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_hit_decision_previous
    FOREIGN KEY (previous_decision_id) REFERENCES ipca_manual_ai_architect_legacy_hit_decisions(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_hit_decision_actor
    FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_manual_ai_architect_structure_proposals (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  plan_id BIGINT UNSIGNED NOT NULL,
  proposal_uuid CHAR(36) NOT NULL,
  proposal_version INT UNSIGNED NOT NULL DEFAULT 1,
  title VARCHAR(255) NOT NULL,
  rationale TEXT NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'draft' COMMENT 'draft | proposed | approved | superseded | rejected',
  structure_fingerprint CHAR(64) NOT NULL,
  proposed_by INT NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_imaa_structure_uuid (proposal_uuid),
  UNIQUE KEY uk_imaa_structure_plan_version (plan_id, proposal_version),
  UNIQUE KEY uk_imaa_structure_plan_hash (plan_id, structure_fingerprint),
  KEY idx_imaa_structure_plan (plan_id, status, proposal_version),
  CONSTRAINT fk_imaa_structure_plan
    FOREIGN KEY (plan_id) REFERENCES ipca_manual_ai_architect_plans(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_structure_actor
    FOREIGN KEY (proposed_by) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_manual_ai_architect_structure_nodes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  structure_proposal_id BIGINT UNSIGNED NOT NULL,
  node_uuid CHAR(36) NOT NULL,
  parent_node_id BIGINT UNSIGNED NULL,
  source_section_id BIGINT UNSIGNED NULL,
  node_key VARCHAR(191) NOT NULL,
  node_type VARCHAR(32) NOT NULL COMMENT 'part | chapter | section | subsection | annex | cross_reference',
  title VARCHAR(500) NOT NULL,
  purpose TEXT NULL,
  action VARCHAR(24) NOT NULL COMMENT 'ADD | MOVE | RENAME | MERGE | SPLIT | PRESERVE | REMOVE',
  decision_status VARCHAR(24) NOT NULL DEFAULT 'proposed' COMMENT 'proposed | accepted | rejected | superseded',
  decision_rationale TEXT NULL,
  decided_by INT NULL,
  decided_at DATETIME(3) NULL,
  depth SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  node_fingerprint CHAR(64) NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_imaa_node_uuid (node_uuid),
  UNIQUE KEY uk_imaa_node_proposal_key (structure_proposal_id, node_key),
  UNIQUE KEY uk_imaa_node_proposal_hash (structure_proposal_id, node_fingerprint),
  KEY idx_imaa_node_tree (structure_proposal_id, parent_node_id, sort_order),
  KEY idx_imaa_node_source (source_section_id),
  KEY idx_imaa_node_decision (structure_proposal_id, decision_status, sort_order),
  CONSTRAINT fk_imaa_node_proposal
    FOREIGN KEY (structure_proposal_id) REFERENCES ipca_manual_ai_architect_structure_proposals(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_node_parent
    FOREIGN KEY (parent_node_id) REFERENCES ipca_manual_ai_architect_structure_nodes(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_node_source
    FOREIGN KEY (source_section_id) REFERENCES ipca_publishing_book_sections(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_node_decider
    FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_manual_ai_architect_decision_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_uuid CHAR(36) NOT NULL,
  plan_id BIGINT UNSIGNED NOT NULL,
  aggregate_type VARCHAR(48) NOT NULL,
  aggregate_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(64) NOT NULL,
  prior_event_id BIGINT UNSIGNED NULL,
  decision VARCHAR(64) NOT NULL,
  rationale TEXT NULL,
  event_payload_json JSON NOT NULL,
  event_fingerprint CHAR(64) NOT NULL,
  actor_id INT NOT NULL,
  recorded_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_imaa_decision_event_uuid (event_uuid),
  UNIQUE KEY uk_imaa_decision_event_hash (plan_id, event_fingerprint),
  KEY idx_imaa_decision_event_stream (plan_id, aggregate_type, aggregate_id, recorded_at, id),
  KEY idx_imaa_decision_event_prior (prior_event_id),
  CONSTRAINT fk_imaa_decision_event_plan
    FOREIGN KEY (plan_id) REFERENCES ipca_manual_ai_architect_plans(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_decision_event_prior
    FOREIGN KEY (prior_event_id) REFERENCES ipca_manual_ai_architect_decision_events(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_decision_event_actor
    FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_manual_ai_architect_edit_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_uuid CHAR(36) NOT NULL,
  plan_id BIGINT UNSIGNED NOT NULL,
  aggregate_type VARCHAR(48) NOT NULL,
  aggregate_id BIGINT UNSIGNED NOT NULL,
  field_path VARCHAR(500) NOT NULL,
  operation VARCHAR(24) NOT NULL COMMENT 'create | replace | append | remove | reorder',
  prior_value_hash CHAR(64) NULL,
  new_value_hash CHAR(64) NULL,
  patch_json JSON NOT NULL,
  event_fingerprint CHAR(64) NOT NULL,
  actor_id INT NOT NULL,
  recorded_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_imaa_edit_event_uuid (event_uuid),
  UNIQUE KEY uk_imaa_edit_event_hash (plan_id, event_fingerprint),
  KEY idx_imaa_edit_event_stream (plan_id, aggregate_type, aggregate_id, recorded_at, id),
  CONSTRAINT fk_imaa_edit_event_plan
    FOREIGN KEY (plan_id) REFERENCES ipca_manual_ai_architect_plans(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_edit_event_actor
    FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_manual_ai_architect_cross_manual_links (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  link_uuid CHAR(36) NOT NULL,
  plan_id BIGINT UNSIGNED NOT NULL,
  impact_id BIGINT UNSIGNED NULL,
  linked_plan_id BIGINT UNSIGNED NULL,
  target_book_version_id BIGINT UNSIGNED NOT NULL,
  relationship_type VARCHAR(32) NOT NULL COMMENT 'impacts | depends_on | must_align | supersedes | conflicts_with | reference_only',
  description TEXT NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'identified' COMMENT 'identified | acknowledged | planned | resolved | dismissed',
  link_fingerprint CHAR(64) NOT NULL,
  created_by INT NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_imaa_cross_link_uuid (link_uuid),
  UNIQUE KEY uk_imaa_cross_link_hash (plan_id, link_fingerprint),
  KEY idx_imaa_cross_link_plan (plan_id, status),
  KEY idx_imaa_cross_link_impact (impact_id),
  KEY idx_imaa_cross_link_linked_plan (linked_plan_id, status),
  KEY idx_imaa_cross_link_version (target_book_version_id, status),
  CONSTRAINT fk_imaa_cross_link_plan
    FOREIGN KEY (plan_id) REFERENCES ipca_manual_ai_architect_plans(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_cross_link_impact
    FOREIGN KEY (impact_id) REFERENCES ipca_manual_ai_architect_impacts(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_cross_link_linked_plan
    FOREIGN KEY (linked_plan_id) REFERENCES ipca_manual_ai_architect_plans(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_cross_link_version
    FOREIGN KEY (target_book_version_id) REFERENCES ipca_publishing_book_versions(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_cross_link_actor
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Future-compatible persistence only. No runtime behavior is introduced by this migration.
CREATE TABLE IF NOT EXISTS ipca_manual_ai_architect_drafts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  draft_uuid CHAR(36) NOT NULL,
  plan_id BIGINT UNSIGNED NOT NULL,
  structure_proposal_id BIGINT UNSIGNED NULL,
  target_book_version_id BIGINT UNSIGNED NULL,
  draft_version INT UNSIGNED NOT NULL DEFAULT 1,
  status VARCHAR(24) NOT NULL DEFAULT 'reserved' COMMENT 'reserved | queued | generating | generated | superseded | abandoned',
  source_fingerprint CHAR(64) NOT NULL,
  content_fingerprint CHAR(64) NULL,
  draft_payload_json JSON NULL,
  created_by INT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_imaa_draft_uuid (draft_uuid),
  UNIQUE KEY uk_imaa_draft_plan_version (plan_id, draft_version),
  KEY idx_imaa_draft_plan (plan_id, status, created_at),
  CONSTRAINT fk_imaa_draft_plan
    FOREIGN KEY (plan_id) REFERENCES ipca_manual_ai_architect_plans(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_draft_structure
    FOREIGN KEY (structure_proposal_id) REFERENCES ipca_manual_ai_architect_structure_proposals(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_draft_version
    FOREIGN KEY (target_book_version_id) REFERENCES ipca_publishing_book_versions(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_draft_actor
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_manual_ai_architect_reviews (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  review_uuid CHAR(36) NOT NULL,
  plan_id BIGINT UNSIGNED NOT NULL,
  draft_id BIGINT UNSIGNED NULL,
  review_type VARCHAR(32) NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'reserved' COMMENT 'reserved | requested | in_review | approved | changes_requested | rejected | cancelled',
  review_payload_json JSON NULL,
  reviewer_id INT NULL,
  requested_by INT NULL,
  requested_at DATETIME(3) NULL,
  completed_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_imaa_review_uuid (review_uuid),
  KEY idx_imaa_review_plan (plan_id, status, created_at),
  KEY idx_imaa_review_draft (draft_id, status),
  KEY idx_imaa_review_reviewer (reviewer_id, status),
  CONSTRAINT fk_imaa_review_plan
    FOREIGN KEY (plan_id) REFERENCES ipca_manual_ai_architect_plans(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_review_draft
    FOREIGN KEY (draft_id) REFERENCES ipca_manual_ai_architect_drafts(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_review_reviewer
    FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_review_requester
    FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_manual_ai_architect_operations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  operation_uuid CHAR(36) NOT NULL,
  plan_id BIGINT UNSIGNED NOT NULL,
  draft_id BIGINT UNSIGNED NULL,
  review_id BIGINT UNSIGNED NULL,
  operation_type VARCHAR(48) NOT NULL,
  idempotency_key CHAR(64) NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'reserved' COMMENT 'reserved | queued | running | succeeded | failed | cancelled',
  request_fingerprint CHAR(64) NOT NULL,
  result_fingerprint CHAR(64) NULL,
  operation_payload_json JSON NULL,
  result_json JSON NULL,
  error_message TEXT NULL,
  requested_by INT NULL,
  started_at DATETIME(3) NULL,
  completed_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_imaa_operation_uuid (operation_uuid),
  UNIQUE KEY uk_imaa_operation_idempotency (idempotency_key),
  KEY idx_imaa_operation_plan (plan_id, status, created_at),
  KEY idx_imaa_operation_draft (draft_id, status),
  KEY idx_imaa_operation_review (review_id, status),
  CONSTRAINT fk_imaa_operation_plan
    FOREIGN KEY (plan_id) REFERENCES ipca_manual_ai_architect_plans(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_operation_draft
    FOREIGN KEY (draft_id) REFERENCES ipca_manual_ai_architect_drafts(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_operation_review
    FOREIGN KEY (review_id) REFERENCES ipca_manual_ai_architect_reviews(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_imaa_operation_actor
    FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
