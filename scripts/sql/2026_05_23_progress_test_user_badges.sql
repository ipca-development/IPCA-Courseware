CREATE TABLE IF NOT EXISTS progress_test_user_badges (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  badge_key VARCHAR(64) NOT NULL,
  attempt_id BIGINT UNSIGNED NULL,
  lesson_id BIGINT UNSIGNED NULL,
  cohort_id BIGINT UNSIGNED NULL,
  earned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  meta_json JSON NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pt_user_badge (user_id, badge_key),
  KEY idx_pt_user_badges_user (user_id, earned_at),
  KEY idx_pt_user_badges_attempt (attempt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
