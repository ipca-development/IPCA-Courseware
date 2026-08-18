-- Books & Manuals workflow metadata. Additive only: existing publishing rows remain authoritative.

CREATE TABLE IF NOT EXISTS ipca_publishing_book_profiles (
  book_id BIGINT UNSIGNED NOT NULL,
  manual_type VARCHAR(64) NOT NULL DEFAULT 'operations',
  approval_route ENUM('internal','authority') NOT NULL DEFAULT 'internal',
  authority_code VARCHAR(64) NULL,
  created_by INT NULL,
  updated_by INT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (book_id),
  CONSTRAINT fk_ipca_pub_book_profiles_book
    FOREIGN KEY (book_id) REFERENCES ipca_publishing_books(id) ON DELETE CASCADE,
  CONSTRAINT fk_ipca_pub_book_profiles_created_by
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_ipca_pub_book_profiles_updated_by
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_publishing_version_workflow (
  book_version_id BIGINT UNSIGNED NOT NULL,
  update_sequence BIGINT UNSIGNED NOT NULL DEFAULT 1,
  update_code VARCHAR(160) NOT NULL,
  source_fingerprint CHAR(64) NULL,
  page_map_hash CHAR(64) NULL,
  manifest_hash CHAR(64) NULL,
  last_transition_at DATETIME(3) NULL,
  last_transition_by INT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (book_version_id),
  UNIQUE KEY uk_ipca_pub_version_workflow_code (update_code),
  CONSTRAINT fk_ipca_pub_version_workflow_version
    FOREIGN KEY (book_version_id) REFERENCES ipca_publishing_book_versions(id) ON DELETE CASCADE,
  CONSTRAINT fk_ipca_pub_version_workflow_actor
    FOREIGN KEY (last_transition_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO ipca_publishing_version_workflow
  (book_version_id, update_sequence, update_code)
SELECT bv.id, 1, CONCAT(
  LEFT(UPPER(b.book_key), 48), '-V', bv.id, '-', LEFT(bv.version_label, 64), '-U000001'
)
FROM ipca_publishing_book_versions bv
INNER JOIN ipca_publishing_books b ON b.id = bv.book_id;

CREATE TABLE IF NOT EXISTS ipca_publishing_lifecycle_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  book_version_id BIGINT UNSIGNED NOT NULL,
  from_status VARCHAR(32) NOT NULL,
  to_status VARCHAR(32) NOT NULL,
  action_key VARCHAR(64) NOT NULL,
  note TEXT NULL,
  actor_user_id INT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_ipca_pub_lifecycle_version (book_version_id, created_at),
  CONSTRAINT fk_ipca_pub_lifecycle_version
    FOREIGN KEY (book_version_id) REFERENCES ipca_publishing_book_versions(id) ON DELETE CASCADE,
  CONSTRAINT fk_ipca_pub_lifecycle_actor
    FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_publishing_version_audiences (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  book_version_id BIGINT UNSIGNED NOT NULL,
  audience_type ENUM('role','user') NOT NULL DEFAULT 'role',
  audience_key VARCHAR(128) NOT NULL,
  assigned_by INT NULL,
  assigned_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uk_ipca_pub_version_audience (book_version_id, audience_type, audience_key),
  KEY idx_ipca_pub_audience_lookup (audience_type, audience_key, book_version_id),
  CONSTRAINT fk_ipca_pub_audience_version
    FOREIGN KEY (book_version_id) REFERENCES ipca_publishing_book_versions(id) ON DELETE CASCADE,
  CONSTRAINT fk_ipca_pub_audience_actor
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preserve visibility of manuals that were already released before this module.
INSERT IGNORE INTO ipca_publishing_version_audiences
  (book_version_id, audience_type, audience_key, assigned_by)
SELECT bv.id, 'role', reader_roles.role_key, NULL
FROM ipca_publishing_book_versions bv
CROSS JOIN (
  SELECT 'student' AS role_key
  UNION ALL SELECT 'instructor'
  UNION ALL SELECT 'supervisor'
  UNION ALL SELECT 'chief_instructor'
  UNION ALL SELECT 'admin'
) reader_roles
WHERE bv.lifecycle_status = 'released';

CREATE TABLE IF NOT EXISTS ipca_publishing_audit_snapshots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  snapshot_uuid CHAR(36) NOT NULL,
  book_version_id BIGINT UNSIGNED NOT NULL,
  audit_type ENUM('internal','authority') NOT NULL DEFAULT 'internal',
  status ENUM('running','passed','failed','error') NOT NULL DEFAULT 'running',
  coverage_percent DECIMAL(6,2) NOT NULL DEFAULT 0,
  covered_count INT UNSIGNED NOT NULL DEFAULT 0,
  insufficient_count INT UNSIGNED NOT NULL DEFAULT 0,
  missing_count INT UNSIGNED NOT NULL DEFAULT 0,
  result_json JSON NOT NULL,
  source_fingerprint CHAR(64) NULL,
  created_by INT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  completed_at DATETIME(3) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_ipca_pub_audit_uuid (snapshot_uuid),
  KEY idx_ipca_pub_audit_version (book_version_id, created_at),
  CONSTRAINT fk_ipca_pub_audit_version
    FOREIGN KEY (book_version_id) REFERENCES ipca_publishing_book_versions(id) ON DELETE CASCADE,
  CONSTRAINT fk_ipca_pub_audit_actor
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_publishing_annex_book_links (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  parent_book_id BIGINT UNSIGNED NOT NULL,
  annex_book_id BIGINT UNSIGNED NOT NULL,
  legacy_book_version_id BIGINT UNSIGNED NULL,
  legacy_section_id BIGINT UNSIGNED NULL,
  annex_key VARCHAR(128) NOT NULL,
  migration_status ENUM('planned','copied','validated','activated','rolled_back') NOT NULL DEFAULT 'planned',
  validation_hash CHAR(64) NULL,
  migration_json JSON NULL,
  created_by INT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uk_ipca_pub_annex_source (legacy_book_version_id, legacy_section_id),
  UNIQUE KEY uk_ipca_pub_annex_book (annex_book_id),
  UNIQUE KEY uk_ipca_pub_annex_parent_key (parent_book_id, annex_key),
  CONSTRAINT fk_ipca_pub_annex_parent
    FOREIGN KEY (parent_book_id) REFERENCES ipca_publishing_books(id) ON DELETE CASCADE,
  CONSTRAINT fk_ipca_pub_annex_book
    FOREIGN KEY (annex_book_id) REFERENCES ipca_publishing_books(id) ON DELETE CASCADE,
  CONSTRAINT fk_ipca_pub_annex_legacy_version
    FOREIGN KEY (legacy_book_version_id) REFERENCES ipca_publishing_book_versions(id) ON DELETE SET NULL,
  CONSTRAINT fk_ipca_pub_annex_legacy_section
    FOREIGN KEY (legacy_section_id) REFERENCES ipca_publishing_book_sections(id) ON DELETE SET NULL,
  CONSTRAINT fk_ipca_pub_annex_actor
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
