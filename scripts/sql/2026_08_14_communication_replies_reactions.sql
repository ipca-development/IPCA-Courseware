-- IPCA Communication — quoted replies and emoji reactions.
-- Additive. Re-run safe via the apply script (information_schema ALTERs).

CREATE TABLE IF NOT EXISTS ipca_communication_message_reactions (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  reaction_uuid      CHAR(36) NOT NULL,
  message_id         BIGINT UNSIGNED NOT NULL,
  user_id            BIGINT UNSIGNED NOT NULL,
  device_id          BIGINT UNSIGNED NULL,
  emoji              VARCHAR(16) NOT NULL,
  created_at_utc     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_comm_reaction_uuid (reaction_uuid),
  UNIQUE KEY uk_comm_reaction_user (message_id, user_id),
  KEY idx_comm_reaction_message (message_id),
  CONSTRAINT fk_comm_reaction_msg
    FOREIGN KEY (message_id) REFERENCES ipca_communication_messages(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='One emoji reaction per user per message. Same emoji again clears it.';
