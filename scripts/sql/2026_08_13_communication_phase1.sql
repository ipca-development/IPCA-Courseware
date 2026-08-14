-- IPCA Communication Phase 1
-- Human messenger identity, devices, conversations, messages, sync log, evidence tables.
-- Does not replace users, schedule, or training records.
-- Re-run safe: CREATE IF NOT EXISTS + INSERT IGNORE seeds.

CREATE TABLE IF NOT EXISTS ipca_communication_app_config (
  config_key      VARCHAR(64) NOT NULL PRIMARY KEY,
  config_value    VARCHAR(255) NOT NULL,
  updated_at_utc  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Operational rollout flags and protocol settings for the IPCA app.';

INSERT IGNORE INTO ipca_communication_app_config (config_key, config_value) VALUES
  ('protocol_version', '1'),
  ('min_app_version', '1.0.0'),
  ('min_ios_version', '17.0'),
  ('messaging_enabled', '1'),
  ('groups_enabled', '1'),
  ('attachments_enabled', '0'),
  ('system_messages_enabled', '0'),
  ('training_enabled', '0'),
  ('community_enabled', '0'),
  ('community_posting_enabled', '0');

CREATE TABLE IF NOT EXISTS ipca_communication_system_actors (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  actor_uuid    CHAR(36) NOT NULL,
  actor_key     VARCHAR(64) NOT NULL,
  display_name  VARCHAR(128) NOT NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_comm_actor_uuid (actor_uuid),
  UNIQUE KEY uk_comm_actor_key (actor_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Official IPCA identities used as message senders. Not login-able users.';

INSERT IGNORE INTO ipca_communication_system_actors (actor_uuid, actor_key, display_name, is_active) VALUES
  ('a1000000-0000-4000-8000-000000000001', 'ipca_training', 'IPCA Training', 1),
  ('a1000000-0000-4000-8000-000000000002', 'ipca_scheduling', 'IPCA Scheduling', 1),
  ('a1000000-0000-4000-8000-000000000003', 'ipca_administration', 'IPCA Administration', 1);

CREATE TABLE IF NOT EXISTS ipca_communication_devices (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  device_uuid        CHAR(36) NOT NULL,
  user_id            BIGINT UNSIGNED NOT NULL,
  organization_id    BIGINT UNSIGNED NOT NULL DEFAULT 1,
  platform           VARCHAR(16) NOT NULL,
  model              VARCHAR(128) NOT NULL DEFAULT '',
  os_version         VARCHAR(64) NOT NULL DEFAULT '',
  app_version        VARCHAR(32) NOT NULL DEFAULT '',
  apns_token         VARCHAR(255) NULL,
  push_authorized    TINYINT(1) NULL,
  last_seen_at_utc   DATETIME(3) NULL,
  last_sync_at_utc   DATETIME(3) NULL,
  last_sync_cursor   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  revoked_at_utc     DATETIME(3) NULL,
  created_at_utc     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at_utc     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_comm_device_uuid (device_uuid),
  KEY idx_comm_device_user (user_id, revoked_at_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Human iPhone/iPad enrollment. Separate from ipca_cvr_devices.';

CREATE TABLE IF NOT EXISTS ipca_communication_device_credentials (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  credential_uuid    CHAR(36) NOT NULL,
  device_id          BIGINT UNSIGNED NOT NULL,
  token_hash         CHAR(64) NOT NULL,
  label              VARCHAR(128) NOT NULL DEFAULT 'session',
  expires_at_utc     DATETIME(3) NULL,
  revoked_at_utc     DATETIME(3) NULL,
  last_used_at_utc   DATETIME(3) NULL,
  created_at_utc     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_comm_cred_uuid (credential_uuid),
  UNIQUE KEY uk_comm_cred_hash (token_hash),
  KEY idx_comm_cred_device (device_id, revoked_at_utc),
  CONSTRAINT fk_comm_cred_device
    FOREIGN KEY (device_id) REFERENCES ipca_communication_devices(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Hashed Bearer credentials for the IPCA app. Plaintext is never stored.';

CREATE TABLE IF NOT EXISTS ipca_communication_conversations (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  conversation_uuid    CHAR(36) NOT NULL,
  organization_id      BIGINT UNSIGNED NOT NULL DEFAULT 1,
  conversation_type    VARCHAR(32) NOT NULL,
  title                VARCHAR(255) NOT NULL DEFAULT '',
  direct_pair_key      CHAR(64) NULL,
  created_by_user_id   BIGINT UNSIGNED NULL,
  last_message_seq     INT UNSIGNED NOT NULL DEFAULT 0,
  last_message_at_utc  DATETIME(3) NULL,
  created_at_utc       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at_utc       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_comm_conv_uuid (conversation_uuid),
  UNIQUE KEY uk_comm_conv_direct_pair (organization_id, direct_pair_key),
  KEY idx_comm_conv_updated (organization_id, last_message_at_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Direct, group, or announcement conversations. Server UUID is authoritative.';

CREATE TABLE IF NOT EXISTS ipca_communication_conversation_members (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  conversation_id  BIGINT UNSIGNED NOT NULL,
  user_id          BIGINT UNSIGNED NOT NULL,
  member_role      VARCHAR(32) NOT NULL DEFAULT 'member',
  last_read_seq    INT UNSIGNED NOT NULL DEFAULT 0,
  last_read_at_utc DATETIME(3) NULL,
  muted            TINYINT(1) NOT NULL DEFAULT 0,
  joined_at_utc    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  left_at_utc      DATETIME(3) NULL,
  UNIQUE KEY uk_comm_member (conversation_id, user_id),
  KEY idx_comm_member_user (user_id, left_at_utc),
  CONSTRAINT fk_comm_member_conv
    FOREIGN KEY (conversation_id) REFERENCES ipca_communication_conversations(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Conversation membership and per-user read cursor.';

CREATE TABLE IF NOT EXISTS ipca_communication_messages (
  id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  message_uuid             CHAR(36) NOT NULL,
  conversation_id          BIGINT UNSIGNED NOT NULL,
  organization_id          BIGINT UNSIGNED NOT NULL DEFAULT 1,
  seq                      INT UNSIGNED NOT NULL,
  client_id                CHAR(36) NOT NULL,
  sender_user_id           BIGINT UNSIGNED NULL,
  sender_device_id         BIGINT UNSIGNED NULL,
  sender_system_actor_id   BIGINT UNSIGNED NULL,
  sender_type              VARCHAR(16) NOT NULL DEFAULT 'user',
  body                     TEXT NOT NULL,
  requires_acknowledgement TINYINT(1) NOT NULL DEFAULT 0,
  reply_allowed            TINYINT(1) NOT NULL DEFAULT 1,
  source_type              VARCHAR(64) NULL,
  source_id                VARCHAR(128) NULL,
  source_event_id          VARCHAR(128) NULL,
  created_at_utc           DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_comm_msg_uuid (message_uuid),
  UNIQUE KEY uk_comm_msg_client (sender_user_id, client_id),
  UNIQUE KEY uk_comm_msg_seq (conversation_id, seq),
  UNIQUE KEY uk_comm_msg_event (source_type, source_id, source_event_id),
  KEY idx_comm_msg_conv_seq (conversation_id, seq),
  KEY idx_comm_msg_created (created_at_utc, id),
  CONSTRAINT fk_comm_msg_conv
    FOREIGN KEY (conversation_id) REFERENCES ipca_communication_conversations(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Server-authoritative messages. client_id provides send idempotency.';

CREATE TABLE IF NOT EXISTS ipca_communication_change_log (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id  BIGINT UNSIGNED NOT NULL DEFAULT 1,
  conversation_id  BIGINT UNSIGNED NOT NULL,
  change_type      VARCHAR(32) NOT NULL,
  entity_uuid      CHAR(36) NOT NULL,
  created_at_utc   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY idx_comm_change_id (id),
  KEY idx_comm_change_conv (conversation_id, id),
  CONSTRAINT fk_comm_change_conv
    FOREIGN KEY (conversation_id) REFERENCES ipca_communication_conversations(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Monotonic sync cursor. Transport-independent; APNs/SSE later only wake clients.';

CREATE TABLE IF NOT EXISTS ipca_communication_message_device_syncs (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  message_id     BIGINT UNSIGNED NOT NULL,
  device_id      BIGINT UNSIGNED NOT NULL,
  user_id        BIGINT UNSIGNED NOT NULL,
  synced_at_utc  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_comm_device_sync (message_id, device_id),
  KEY idx_comm_device_sync_user (user_id, message_id),
  CONSTRAINT fk_comm_sync_msg
    FOREIGN KEY (message_id) REFERENCES ipca_communication_messages(id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_comm_sync_device
    FOREIGN KEY (device_id) REFERENCES ipca_communication_devices(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-device device_synced evidence. Not the same as APNs push_accepted.';

CREATE TABLE IF NOT EXISTS ipca_communication_push_attempts (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  push_uuid          CHAR(36) NOT NULL,
  message_id         BIGINT UNSIGNED NOT NULL,
  device_id          BIGINT UNSIGNED NOT NULL,
  accepted_at_utc    DATETIME(3) NULL,
  failed_at_utc      DATETIME(3) NULL,
  provider_response  VARCHAR(255) NULL,
  created_at_utc     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_comm_push_uuid (push_uuid),
  KEY idx_comm_push_msg_device (message_id, device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Phase 2: APNs acceptance evidence only. Unused in Phase 1.';

CREATE TABLE IF NOT EXISTS ipca_communication_acknowledgements (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  acknowledgement_uuid   CHAR(36) NOT NULL,
  message_id             BIGINT UNSIGNED NOT NULL,
  user_id                BIGINT UNSIGNED NOT NULL,
  device_id              BIGINT UNSIGNED NULL,
  acknowledged_at_utc    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_comm_ack_uuid (acknowledgement_uuid),
  UNIQUE KEY uk_comm_ack_msg_user (message_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Phase 4: explicit acknowledgement evidence. Unused in Phase 1.';
