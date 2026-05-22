CREATE TABLE IF NOT EXISTS progress_test_v4_debug_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  attempt_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  cohort_id BIGINT UNSIGNED NULL,
  lesson_id BIGINT UNSIGNED NULL,
  item_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(64) NOT NULL,
  event_detail TEXT NULL,
  meta_json JSON NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_ptv4_debug_attempt (attempt_id, created_at),
  KEY idx_ptv4_debug_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS progress_test_oral_integrity_reviews (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  attempt_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  cohort_id BIGINT UNSIGNED NOT NULL,
  lesson_id BIGINT UNSIGNED NOT NULL,
  analysis_json JSON NOT NULL,
  summary_text TEXT NULL,
  model VARCHAR(64) NULL,
  generated_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pt_integrity_attempt (attempt_id),
  KEY idx_pt_integrity_lookup (cohort_id, user_id, lesson_id, generated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_feedback (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  cohort_id BIGINT UNSIGNED NULL,
  lesson_id BIGINT UNSIGNED NULL,
  attempt_id BIGINT UNSIGNED NULL,
  type VARCHAR(128) NOT NULL,
  rating_json JSON NULL,
  free_text TEXT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_user_feedback_type (type, created_at),
  KEY idx_user_feedback_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
