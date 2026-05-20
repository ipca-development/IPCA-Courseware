-- Airport Operations Flip Board Display System
-- Apply once to add operational TV/kiosk messages.

CREATE TABLE IF NOT EXISTS tv_screen_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  screen_key VARCHAR(64) NOT NULL DEFAULT 'main',
  message_type ENUM('standard','urgent','schedule','night') NOT NULL DEFAULT 'standard',
  title VARCHAR(160) NOT NULL,
  body TEXT NOT NULL,
  priority INT NOT NULL DEFAULT 10,
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  display_duration_seconds INT NOT NULL DEFAULT 12,
  announce_audio_enabled TINYINT(1) NOT NULL DEFAULT 0,
  voice_text TEXT NULL,
  audio_url VARCHAR(512) NULL,
  status ENUM('draft','active','inactive','archived') NOT NULL DEFAULT 'draft',
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tv_screen_active (screen_key, status, starts_at, ends_at, priority),
  KEY idx_tv_screen_priority (message_type, priority, id),
  KEY idx_tv_screen_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tv_screen_messages (
  screen_key,
  message_type,
  title,
  body,
  priority,
  display_duration_seconds,
  announce_audio_enabled,
  voice_text,
  status
)
SELECT
  'main',
  'standard',
  'IPCA OPERATIONS',
  'TRAINING CENTER OPEN\nCHECK DISPATCH BOARD\nMONITOR INSTRUCTOR CALLS',
  10,
  12,
  0,
  NULL,
  'active'
WHERE NOT EXISTS (
  SELECT 1
  FROM tv_screen_messages
  WHERE screen_key = 'main'
    AND title = 'IPCA OPERATIONS'
  LIMIT 1
);
