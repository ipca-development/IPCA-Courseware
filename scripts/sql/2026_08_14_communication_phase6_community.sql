-- IPCA Communication Phase 6 — Community chronological feed.
-- Isolated from messaging sync/outbox. Additive. Re-run safe.

INSERT INTO ipca_communication_app_config (config_key, config_value)
VALUES
  ('community_enabled', '1'),
  ('community_posting_enabled', '1')
ON DUPLICATE KEY UPDATE config_value = '1';

CREATE TABLE IF NOT EXISTS ipca_community_posts (
  id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  post_uuid               CHAR(36) NOT NULL,
  author_user_id          BIGINT UNSIGNED NOT NULL,
  author_device_id        BIGINT UNSIGNED NULL,
  organization_id         BIGINT UNSIGNED NOT NULL DEFAULT 1,
  school_scope            VARCHAR(64) NULL,
  program_scope           VARCHAR(64) NULL,
  caption                 VARCHAR(2000) NOT NULL DEFAULT '',
  body                    TEXT NULL,
  status                  VARCHAR(32) NOT NULL DEFAULT 'published',
  created_at_utc          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  deleted_at_utc          DATETIME(3) NULL,
  deleted_by_user_id      BIGINT UNSIGNED NULL,
  UNIQUE KEY uk_community_post_uuid (post_uuid),
  KEY idx_community_feed (status, id),
  KEY idx_community_author (author_user_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Chronological Community posts. school_scope/program_scope unused in V1.';

CREATE TABLE IF NOT EXISTS ipca_community_post_media (
  id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  media_uuid              CHAR(36) NOT NULL,
  post_id                 BIGINT UNSIGNED NULL,
  organization_id         BIGINT UNSIGNED NOT NULL DEFAULT 1,
  uploaded_by_user_id     BIGINT UNSIGNED NOT NULL,
  uploaded_by_device_id   BIGINT UNSIGNED NULL,
  storage_key             VARCHAR(512) NOT NULL,
  original_filename       VARCHAR(255) NOT NULL DEFAULT '',
  mime_type               VARCHAR(128) NOT NULL,
  kind                    VARCHAR(16) NOT NULL,
  byte_size               INT UNSIGNED NOT NULL,
  duration_ms             INT UNSIGNED NOT NULL DEFAULT 0,
  poster_storage_key      VARCHAR(512) NULL,
  sort_order              TINYINT UNSIGNED NOT NULL DEFAULT 0,
  status                  VARCHAR(32) NOT NULL DEFAULT 'pending',
  uploaded_at_utc         DATETIME(3) NULL,
  created_at_utc          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_community_media_uuid (media_uuid),
  UNIQUE KEY uk_community_media_key (storage_key),
  KEY idx_community_media_post (post_id, sort_order),
  KEY idx_community_media_uploader (uploaded_by_user_id, status),
  CONSTRAINT fk_community_media_post
    FOREIGN KEY (post_id) REFERENCES ipca_community_posts(id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Community photo/short-video objects. Published posts are served from the Spaces CDN.';

CREATE TABLE IF NOT EXISTS ipca_community_likes (
  id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  like_uuid               CHAR(36) NOT NULL,
  post_id                 BIGINT UNSIGNED NOT NULL,
  user_id                 BIGINT UNSIGNED NOT NULL,
  device_id               BIGINT UNSIGNED NULL,
  created_at_utc          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_community_like_uuid (like_uuid),
  UNIQUE KEY uk_community_like_user (post_id, user_id),
  CONSTRAINT fk_community_like_post
    FOREIGN KEY (post_id) REFERENCES ipca_community_posts(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_community_comments (
  id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  comment_uuid            CHAR(36) NOT NULL,
  post_id                 BIGINT UNSIGNED NOT NULL,
  user_id                 BIGINT UNSIGNED NOT NULL,
  device_id               BIGINT UNSIGNED NULL,
  body                    VARCHAR(2000) NOT NULL,
  created_at_utc          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  deleted_at_utc          DATETIME(3) NULL,
  UNIQUE KEY uk_community_comment_uuid (comment_uuid),
  KEY idx_community_comments_post (post_id, id),
  CONSTRAINT fk_community_comment_post
    FOREIGN KEY (post_id) REFERENCES ipca_community_posts(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Flat Community comments. Newest last in the thread.';

CREATE TABLE IF NOT EXISTS ipca_community_reports (
  id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  report_uuid             CHAR(36) NOT NULL,
  post_id                 BIGINT UNSIGNED NOT NULL,
  reporter_user_id        BIGINT UNSIGNED NOT NULL,
  reporter_device_id      BIGINT UNSIGNED NULL,
  reason                  VARCHAR(32) NOT NULL,
  details                 VARCHAR(2000) NOT NULL DEFAULT '',
  created_at_utc          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_community_report_uuid (report_uuid),
  UNIQUE KEY uk_community_report_user (post_id, reporter_user_id),
  KEY idx_community_reports_created (created_at_utc),
  CONSTRAINT fk_community_report_post
    FOREIGN KEY (post_id) REFERENCES ipca_community_posts(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Basic Community moderation reports. No V1 admin UI.';
