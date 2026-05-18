-- Maya Summary Coach V3 voice prototype session evidence.

CREATE TABLE IF NOT EXISTS student_summary_voice_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  cohort_id BIGINT UNSIGNED NULL,
  lesson_id BIGINT UNSIGNED NOT NULL,
  blueprint_version_id BIGINT UNSIGNED NOT NULL,
  realtime_session_id VARCHAR(191) NULL,
  started_at DATETIME NOT NULL,
  ended_at DATETIME NULL,
  status ENUM('starting','active','paused','ended','failed') NOT NULL DEFAULT 'starting',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user_lesson (user_id, lesson_id),
  KEY idx_blueprint_version (blueprint_version_id),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_summary_voice_transcript_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  voice_session_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  cohort_id BIGINT UNSIGNED NULL,
  lesson_id BIGINT UNSIGNED NOT NULL,
  blueprint_version_id BIGINT UNSIGNED NOT NULL,
  section_id VARCHAR(128) NULL,
  role ENUM('student','maya','system') NOT NULL,
  transcript_text TEXT NOT NULL,
  event_type VARCHAR(64) NOT NULL DEFAULT 'transcript',
  state_snapshot_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_voice_session (voice_session_id),
  KEY idx_user_lesson (user_id, lesson_id),
  KEY idx_section (section_id),
  KEY idx_created (created_at),
  CONSTRAINT fk_ssvtm_session
    FOREIGN KEY (voice_session_id)
    REFERENCES student_summary_voice_sessions (id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
