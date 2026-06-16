-- IPCA manual e-reader: per-user reading progress (released versions only).

CREATE TABLE IF NOT EXISTS ipca_manual_reading_progress (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id           INT NOT NULL,
  book_version_id   BIGINT UNSIGNED NOT NULL,
  section_id        BIGINT UNSIGNED NOT NULL,
  stable_anchor     VARCHAR(191) NULL,
  scroll_pct        SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Stores global page number for frozen page maps',
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_imrp_user_version (user_id, book_version_id),
  KEY idx_imrp_book_version (book_version_id),
  KEY idx_imrp_section (section_id),
  CONSTRAINT fk_imrp_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_imrp_version FOREIGN KEY (book_version_id)
    REFERENCES ipca_publishing_book_versions (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_imrp_section FOREIGN KEY (section_id)
    REFERENCES ipca_publishing_book_sections (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Manual e-reader last reading position per user and book version.';
