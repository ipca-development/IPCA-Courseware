CREATE TABLE IF NOT EXISTS progress_test_v4_card_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  attempt_id BIGINT UNSIGNED NOT NULL,
  item_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  card_state ENUM('ready','asking','listening','evaluating','clarification','complete') NOT NULL DEFAULT 'ready',
  live_transcript TEXT NULL,
  clarification_used TINYINT(1) NOT NULL DEFAULT 0,
  answer_chunk_count INT UNSIGNED NOT NULL DEFAULT 0,
  answer_started_at DATETIME NULL,
  updated_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ptv4_card_attempt_item (attempt_id, item_id),
  KEY idx_ptv4_card_attempt (attempt_id, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS progress_test_v4_answer_chunks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  attempt_id BIGINT UNSIGNED NOT NULL,
  item_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  chunk_index INT UNSIGNED NOT NULL,
  storage_path VARCHAR(512) NOT NULL,
  transcript_text TEXT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ptv4_chunk (attempt_id, item_id, chunk_index),
  KEY idx_ptv4_chunks_item (attempt_id, item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS progress_test_v4_question_audio_cache (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  text_hash CHAR(64) NOT NULL,
  voice VARCHAR(64) NOT NULL,
  language VARCHAR(16) NOT NULL DEFAULT 'en',
  spoken_text TEXT NOT NULL,
  audio_url VARCHAR(1024) NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ptv4_qaudio (text_hash, voice, language)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
