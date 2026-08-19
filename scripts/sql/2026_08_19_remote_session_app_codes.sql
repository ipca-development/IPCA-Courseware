CREATE TABLE IF NOT EXISTS ipca_remote_session_codes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code_uuid CHAR(36) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  kind VARCHAR(32) NOT NULL,
  authorization_id BIGINT UNSIGNED NULL,
  code_plaintext CHAR(6) NULL,
  expires_at_utc DATETIME(3) NOT NULL,
  viewed_at_utc DATETIME(3) NULL,
  created_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_remote_session_code_uuid (code_uuid),
  KEY idx_remote_session_code_user (user_id, viewed_at_utc, expires_at_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
