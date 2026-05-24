-- Progress test lesson question bank (admin-built via Bulk Enrich)

CREATE TABLE IF NOT EXISTS progress_test_lesson_banks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lesson_id INT NOT NULL,
  content_fingerprint CHAR(64) NOT NULL,
  recommended_pool_size TINYINT UNSIGNED NOT NULL DEFAULT 5,
  status ENUM('building','ready','stale') NOT NULL DEFAULT 'building',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pt_lesson_bank (lesson_id),
  KEY idx_pt_bank_fingerprint (content_fingerprint)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS progress_test_bank_questions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  bank_id BIGINT UNSIGNED NOT NULL,
  lesson_id INT NOT NULL,
  sort_idx INT NOT NULL DEFAULT 0,
  kind VARCHAR(16) NOT NULL,
  prompt TEXT NOT NULL,
  options_json JSON NOT NULL,
  correct_json JSON NOT NULL,
  prompt_hash CHAR(64) NOT NULL,
  audio_url VARCHAR(1024) NULL,
  validation_score SMALLINT UNSIGNED NULL,
  validation_flags JSON NULL,
  status ENUM('active','retired') NOT NULL DEFAULT 'active',
  retired_at DATETIME NULL,
  retired_reason VARCHAR(64) NULL,
  first_attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
  avg_first_score_pct DECIMAL(5,2) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pt_bq_bank_status (bank_id, status),
  KEY idx_pt_bq_lesson_status (lesson_id, status),
  KEY idx_pt_bq_prompt_hash (prompt_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS progress_test_bank_question_usage (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  bank_question_id BIGINT UNSIGNED NOT NULL,
  user_id INT NOT NULL,
  attempt_id INT NOT NULL,
  first_score_pct DECIMAL(5,2) NOT NULL,
  first_evaluated_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pt_bqu_user_question (user_id, bank_question_id),
  KEY idx_pt_bqu_question (bank_question_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Link attempt items back to bank rows for first-attempt stats
ALTER TABLE progress_test_items_v2
  ADD COLUMN bank_question_id BIGINT UNSIGNED NULL AFTER correct_json;
