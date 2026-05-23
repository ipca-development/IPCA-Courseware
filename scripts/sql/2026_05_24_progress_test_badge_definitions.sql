CREATE TABLE IF NOT EXISTS progress_test_badge_definitions (
  badge_key VARCHAR(64) NOT NULL,
  name VARCHAR(128) NOT NULL,
  description VARCHAR(512) NOT NULL DEFAULT '',
  image_path VARCHAR(255) NOT NULL DEFAULT '',
  theme VARCHAR(32) NOT NULL DEFAULT 'default',
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (badge_key),
  KEY idx_pt_badge_defs_sort (sort_order, badge_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO progress_test_badge_definitions
  (badge_key, name, description, image_path, theme, sort_order, is_active)
VALUES
  ('ready_for_departure', 'Wheels Up', 'First successful pass on your first attempt.', '/assets/badges/01_wheels_up.png', 'departure', 1, 1),
  ('perfect_pattern', 'Perfect Pattern', 'Scored 100% on a first attempt for the first time.', '/assets/badges/02_perfect_pattern.png', 'pattern', 2, 1),
  ('ifr_precision_pilot', 'Elite Aviator', 'Three consecutive progress tests with 100% on first attempt.', '/assets/badges/03_elite_aviator.png', 'ifr', 3, 1),
  ('captain_consistency', 'Platinum Wings', 'Five consecutive progress tests with 100% on first attempt.', '/assets/badges/04_platinum_wings.png', 'captain', 4, 1),
  ('ipca_sky_master', 'IPCA Sky Master', 'Ten consecutive progress tests with 100% on first attempt.', '/assets/badges/05_ipca_skymaster.png', 'master', 5, 1),
  ('ai_contributor', 'Maya''s Copilot', 'Shared feedback to help improve Maya and IPCA training.', '/assets/badges/06_mayas_copilot.png', 'contributor', 6, 1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  description = VALUES(description),
  image_path = VALUES(image_path),
  theme = VALUES(theme),
  sort_order = VALUES(sort_order),
  is_active = VALUES(is_active),
  updated_at = NOW();
