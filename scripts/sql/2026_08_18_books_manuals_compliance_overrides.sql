CREATE TABLE IF NOT EXISTS ipca_publishing_compliance_overrides (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  override_uuid CHAR(36) NOT NULL,
  book_version_id BIGINT UNSIGNED NOT NULL,
  override_scope VARCHAR(64) NOT NULL DEFAULT 'all_release_blockers',
  rationale TEXT NOT NULL,
  blockers_json JSON NOT NULL,
  source_fingerprint CHAR(64) NULL,
  actor_user_id INT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uk_ipca_pub_compliance_override_uuid (override_uuid),
  KEY idx_ipca_pub_compliance_override_version (book_version_id, created_at),
  CONSTRAINT fk_ipca_pub_compliance_override_version
    FOREIGN KEY (book_version_id) REFERENCES ipca_publishing_book_versions(id) ON DELETE CASCADE,
  CONSTRAINT fk_ipca_pub_compliance_override_actor
    FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
