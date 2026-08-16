-- IPCA Communication Phase 2 leftover (delivered cursor) + Phase 3 private attachments.
-- Additive. Does not change Phase 1 message identity or APNs tables.
-- Re-run safe: CREATE IF NOT EXISTS + information_schema-guarded ALTERs in the apply script.

INSERT IGNORE INTO ipca_communication_app_config (config_key, config_value) VALUES
  ('attachments_enabled', '1');

UPDATE ipca_communication_app_config
SET config_value = '1'
WHERE config_key = 'attachments_enabled';

CREATE TABLE IF NOT EXISTS ipca_communication_attachments (
  id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  attachment_uuid         CHAR(36) NOT NULL,
  conversation_id         BIGINT UNSIGNED NOT NULL,
  organization_id         BIGINT UNSIGNED NOT NULL DEFAULT 1,
  uploaded_by_user_id     BIGINT UNSIGNED NOT NULL,
  uploaded_by_device_id   BIGINT UNSIGNED NULL,
  storage_key             VARCHAR(512) NOT NULL,
  original_filename       VARCHAR(255) NOT NULL DEFAULT '',
  mime_type               VARCHAR(128) NOT NULL,
  byte_size               INT UNSIGNED NOT NULL,
  status                  VARCHAR(32) NOT NULL DEFAULT 'pending',
  uploaded_at_utc         DATETIME(3) NULL,
  created_at_utc          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_comm_att_uuid (attachment_uuid),
  UNIQUE KEY uk_comm_att_key (storage_key),
  KEY idx_comm_att_conv_status (conversation_id, status),
  CONSTRAINT fk_comm_att_conv
    FOREIGN KEY (conversation_id) REFERENCES ipca_communication_conversations(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Private chat objects. Status pending until Spaces HEAD succeeds; ACL stays private.';

CREATE TABLE IF NOT EXISTS ipca_communication_message_attachments (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  message_id      BIGINT UNSIGNED NOT NULL,
  attachment_id   BIGINT UNSIGNED NOT NULL,
  sort_order      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uk_comm_msg_att (message_id, attachment_id),
  KEY idx_comm_att_msg (attachment_id),
  CONSTRAINT fk_comm_msg_att_msg
    FOREIGN KEY (message_id) REFERENCES ipca_communication_messages(id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_comm_msg_att_att
    FOREIGN KEY (attachment_id) REFERENCES ipca_communication_attachments(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Links uploaded private objects to a server_received message.';
