-- One Annex Book per parent manual, plus an append-only per-annex revision log.
-- Additive: ipca_publishing_annex_book_links remains for legacy standalone annex books.

CREATE TABLE IF NOT EXISTS ipca_publishing_annex_book_map (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  parent_book_id BIGINT UNSIGNED NOT NULL,
  annex_book_id BIGINT UNSIGNED NOT NULL,
  status ENUM('active','rolled_back') NOT NULL DEFAULT 'active',
  migration_json JSON NULL,
  created_by INT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uk_ipca_pub_annex_map_parent (parent_book_id),
  UNIQUE KEY uk_ipca_pub_annex_map_book (annex_book_id),
  CONSTRAINT fk_ipca_pub_annex_map_parent
    FOREIGN KEY (parent_book_id) REFERENCES ipca_publishing_books(id) ON DELETE CASCADE,
  CONSTRAINT fk_ipca_pub_annex_map_book
    FOREIGN KEY (annex_book_id) REFERENCES ipca_publishing_books(id) ON DELETE CASCADE,
  CONSTRAINT fk_ipca_pub_annex_map_actor
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_publishing_annex_revisions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  book_version_id BIGINT UNSIGNED NOT NULL,
  section_id BIGINT UNSIGNED NOT NULL,
  annex_key VARCHAR(128) NOT NULL,
  revision_from VARCHAR(64) NULL,
  revision_to VARCHAR(64) NOT NULL,
  revision_date DATE NOT NULL,
  actor_user_id INT NULL,
  actor_name VARCHAR(255) NOT NULL DEFAULT '',
  source ENUM('create','content_update','reimport','identity','migrate','delete','restore') NOT NULL DEFAULT 'content_update',
  note VARCHAR(512) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_ipca_pub_annex_rev_section (section_id, created_at),
  KEY idx_ipca_pub_annex_rev_version (book_version_id, created_at),
  CONSTRAINT fk_ipca_pub_annex_rev_version
    FOREIGN KEY (book_version_id) REFERENCES ipca_publishing_book_versions(id) ON DELETE CASCADE,
  CONSTRAINT fk_ipca_pub_annex_rev_section
    FOREIGN KEY (section_id) REFERENCES ipca_publishing_book_sections(id) ON DELETE CASCADE,
  CONSTRAINT fk_ipca_pub_annex_rev_actor
    FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
