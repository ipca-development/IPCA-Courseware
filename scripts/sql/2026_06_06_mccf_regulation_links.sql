-- MCCF requirement → applicable regulation source links (EASA Resource Library / eRules).
-- Apply after 2026_05_18_controlled_publishing_canonical_sources.sql and EASA eRules staging tables.

CREATE TABLE IF NOT EXISTS ipca_canonical_requirement_regulation_links (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  requirement_id        BIGINT UNSIGNED NOT NULL,
  source_set_id         BIGINT UNSIGNED NOT NULL,
  rule_token            VARCHAR(128) NOT NULL COMMENT 'Normalized rule id e.g. ORA.ATO.130(A)',
  link_role             VARCHAR(16) NOT NULL DEFAULT 'PRIMARY' COMMENT 'PRIMARY | AMC | GM | SUPPORTING',
  target_source         VARCHAR(32) NOT NULL DEFAULT 'easa_erules' COMMENT 'easa_erules | resource_library | other',
  target_batch_id       BIGINT UNSIGNED NULL COMMENT 'easa_erules_import_batches.id when target_source=easa_erules',
  target_node_uid       VARCHAR(96) NULL,
  target_erules_id      VARCHAR(256) NULL,
  target_title          VARCHAR(512) NULL,
  target_breadcrumb     TEXT NULL,
  match_confidence      VARCHAR(16) NOT NULL DEFAULT 'AUTO' COMMENT 'AUTO | VERIFIED | MANUAL | UNRESOLVED',
  notes                 TEXT NULL,
  source_status         VARCHAR(32) NOT NULL DEFAULT 'active' COMMENT 'active | superseded | retired',
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcareg_req_token (requirement_id, rule_token, target_source),
  KEY idx_ipcareg_source_set (source_set_id),
  KEY idx_ipcareg_req (requirement_id),
  KEY idx_ipcareg_target (target_source, target_batch_id, target_node_uid),
  KEY idx_ipcareg_token (rule_token),
  CONSTRAINT fk_ipcareg_req FOREIGN KEY (requirement_id)
    REFERENCES ipca_canonical_requirements (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ipcareg_source_set FOREIGN KEY (source_set_id)
    REFERENCES ipca_canonical_source_sets (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Controlled Publishing — MCCF requirement to applicable regulation source links.';
