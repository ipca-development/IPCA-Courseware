-- IPCA Communication Phase 9 — Training video category catalog and watch progress.
-- Additive and safe to re-run. Categories are a closed catalog. Watch progress
-- stays on ipca_training_video_views (no second table). Media stays private.

CREATE TABLE IF NOT EXISTS ipca_training_video_categories (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  slug                  VARCHAR(64) NOT NULL,
  name                  VARCHAR(128) NOT NULL,
  sort_order            INT NOT NULL DEFAULT 0,
  is_active             TINYINT(1) NOT NULL DEFAULT 1,
  created_at_utc        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_training_video_category_slug (slug),
  KEY idx_training_video_category_sort (is_active, sort_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO ipca_training_video_categories (slug, name, sort_order, is_active) VALUES
  ('private-pilot', 'Private Pilot', 10, 1),
  ('instrument', 'Instrument', 20, 1),
  ('commercial', 'Commercial', 30, 1),
  ('cfi', 'CFI', 40, 1),
  ('systems', 'Systems', 50, 1),
  ('uncategorized', 'Uncategorized', 90, 1);
