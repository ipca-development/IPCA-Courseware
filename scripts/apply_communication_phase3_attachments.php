<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/RuntimeSecrets.php';
RuntimeSecrets::ensureCliEnvLoaded();
require_once __DIR__ . '/../src/db.php';

$pdo = cw_db();

$pdo->exec("
    INSERT INTO ipca_communication_app_config (config_key, config_value)
    VALUES ('attachments_enabled', '1')
    ON DUPLICATE KEY UPDATE config_value = '1'
");

$col = (int)$pdo->query("
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'ipca_communication_conversation_members'
      AND COLUMN_NAME = 'last_delivered_seq'
")->fetchColumn();
if ($col === 0) {
    $pdo->exec('ALTER TABLE ipca_communication_conversation_members ADD COLUMN last_delivered_seq INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_read_at_utc');
    echo "added last_delivered_seq\n";
} else {
    echo "last_delivered_seq exists\n";
}

$col = (int)$pdo->query("
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'ipca_communication_conversation_members'
      AND COLUMN_NAME = 'last_delivered_at_utc'
")->fetchColumn();
if ($col === 0) {
    $pdo->exec('ALTER TABLE ipca_communication_conversation_members ADD COLUMN last_delivered_at_utc DATETIME(3) NULL AFTER last_delivered_seq');
    echo "added last_delivered_at_utc\n";
} else {
    echo "last_delivered_at_utc exists\n";
}

$pdo->exec("
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
");

$pdo->exec("
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
");

$flag = (string)$pdo->query("SELECT config_value FROM ipca_communication_app_config WHERE config_key = 'attachments_enabled'")->fetchColumn();
$att = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_communication_attachments'")->fetchColumn();
echo 'attachments_enabled=' . $flag . PHP_EOL;
echo 'attachments_table=' . ($att > 0 ? 'yes' : 'no') . PHP_EOL;
echo 'ok' . PHP_EOL;
