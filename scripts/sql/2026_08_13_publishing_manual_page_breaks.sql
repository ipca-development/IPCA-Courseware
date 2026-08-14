-- Additive editorial page-break instructions for authoritative pagination.
-- Existing blocks and payloads remain unchanged.

CREATE TABLE IF NOT EXISTS ipca_publishing_manual_page_breaks (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  book_version_id      BIGINT UNSIGNED NOT NULL,
  before_block_anchor  VARCHAR(191) NOT NULL,
  created_by_user_id   INT UNSIGNED NULL,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcmpb_version_anchor (book_version_id, before_block_anchor),
  KEY idx_ipcmpb_version (book_version_id),
  CONSTRAINT fk_ipcmpb_version FOREIGN KEY (book_version_id)
    REFERENCES ipca_publishing_book_versions (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Controlled Publishing — persistent manual page breaks before stable blocks';
