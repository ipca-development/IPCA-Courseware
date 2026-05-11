-- EASA Maya — semantic map / curated overlay storage.
-- Apply after the core EASA tables. The chat already works without this table; it just lets
-- admins curate cross-references, "do-not-confuse" warnings, editorial overrides and (optional)
-- regulatory-map patches that are injected into Maya's system prompt at answer time.

CREATE TABLE IF NOT EXISTS easa_semantic_map (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  slot_key VARCHAR(64) NOT NULL DEFAULT 'default',
  json_payload MEDIUMTEXT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by INT UNSIGNED NULL,
  UNIQUE KEY uniq_slot (slot_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
