-- Recipient-specific email delivery audit for expiring private replay shares.

CREATE TABLE IF NOT EXISTS ipca_replay_debrief_share_deliveries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  delivery_uuid CHAR(36) NOT NULL,
  share_id BIGINT UNSIGNED NOT NULL,
  recipient_type VARCHAR(24) NOT NULL DEFAULT 'custom',
  recipient_name VARCHAR(160) NOT NULL DEFAULT '',
  recipient_email VARCHAR(254) NOT NULL,
  delivery_status VARCHAR(24) NOT NULL DEFAULT 'pending',
  provider VARCHAR(48) NULL,
  provider_message_id VARCHAR(255) NULL,
  delivery_error VARCHAR(1000) NULL,
  sent_at DATETIME(3) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_replay_share_delivery_uuid (delivery_uuid),
  UNIQUE KEY uk_ipca_replay_share_delivery_share (share_id),
  KEY idx_ipca_replay_share_delivery_recipient (recipient_email, created_at),
  KEY idx_ipca_replay_share_delivery_status (delivery_status, created_at),
  CONSTRAINT fk_ipca_replay_share_delivery_share
    FOREIGN KEY (share_id) REFERENCES ipca_replay_debrief_shares(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
