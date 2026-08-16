-- IPCA Communication Phase 10 — Unique thumbnails, optional video end dates,
-- and per-user category access. Additive and safe to re-run. Media stays private.

CREATE TABLE IF NOT EXISTS ipca_training_video_category_entitlements (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id               BIGINT UNSIGNED NOT NULL,
  category_id           BIGINT UNSIGNED NOT NULL,
  available_from_utc    DATETIME(3) NULL,
  available_until_utc   DATETIME(3) NULL,
  created_at_utc        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at_utc        DATETIME(3) NULL,
  UNIQUE KEY uk_tv_category_entitlement (user_id, category_id),
  KEY idx_tv_category_entitlement_user (user_id, category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
