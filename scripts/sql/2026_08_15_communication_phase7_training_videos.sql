-- IPCA Communication Phase 7 — private Training Videos.
-- Additive and safe to re-run. Media is private and only issued through presigned GET URLs.

INSERT INTO ipca_communication_app_config (config_key, config_value)
VALUES ('training_videos_enabled', '1')
ON DUPLICATE KEY UPDATE config_value = '1';

CREATE TABLE IF NOT EXISTS ipca_training_videos (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  video_uuid            CHAR(36) NOT NULL,
  title                 VARCHAR(255) NOT NULL,
  description           TEXT NULL,
  storage_key           VARCHAR(512) NULL,
  mime_type             VARCHAR(128) NOT NULL DEFAULT 'video/mp4',
  poster_storage_key    VARCHAR(512) NULL,
  poster_mime_type      VARCHAR(128) NULL,
  duration_ms           INT UNSIGNED NOT NULL DEFAULT 0,
  byte_size             BIGINT UNSIGNED NOT NULL DEFAULT 0,
  status                VARCHAR(32) NOT NULL DEFAULT 'draft',
  created_by_user_id    BIGINT UNSIGNED NOT NULL,
  updated_by_user_id    BIGINT UNSIGNED NOT NULL,
  created_at_utc        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at_utc        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  archived_at_utc       DATETIME(3) NULL,
  deleted_at_utc        DATETIME(3) NULL,
  UNIQUE KEY uk_training_video_uuid (video_uuid),
  UNIQUE KEY uk_training_video_storage (storage_key),
  KEY idx_training_video_feed (status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_training_video_grants (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  video_id              BIGINT UNSIGNED NOT NULL,
  grant_type            VARCHAR(32) NOT NULL,
  grant_value           VARCHAR(191) NOT NULL DEFAULT '',
  available_from_utc    DATETIME(3) NOT NULL,
  available_until_utc   DATETIME(3) NOT NULL,
  created_at_utc        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY idx_training_video_grant (video_id, grant_type, grant_value),
  CONSTRAINT fk_training_video_grant_video
    FOREIGN KEY (video_id) REFERENCES ipca_training_videos(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_training_video_views (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  video_id              BIGINT UNSIGNED NOT NULL,
  user_id               BIGINT UNSIGNED NOT NULL,
  first_viewed_at_utc   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  last_viewed_at_utc    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_training_video_view (video_id, user_id),
  CONSTRAINT fk_training_video_view_video
    FOREIGN KEY (video_id) REFERENCES ipca_training_videos(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_training_video_likes (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  video_id              BIGINT UNSIGNED NOT NULL,
  user_id               BIGINT UNSIGNED NOT NULL,
  created_at_utc        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_training_video_like (video_id, user_id),
  CONSTRAINT fk_training_video_like_video
    FOREIGN KEY (video_id) REFERENCES ipca_training_videos(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_training_video_comments (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  comment_uuid          CHAR(36) NOT NULL,
  video_id              BIGINT UNSIGNED NOT NULL,
  user_id               BIGINT UNSIGNED NOT NULL,
  body                  VARCHAR(2000) NOT NULL,
  created_at_utc        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  deleted_at_utc        DATETIME(3) NULL,
  UNIQUE KEY uk_training_video_comment_uuid (comment_uuid),
  KEY idx_training_video_comments (video_id, id),
  CONSTRAINT fk_training_video_comment_video
    FOREIGN KEY (video_id) REFERENCES ipca_training_videos(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
