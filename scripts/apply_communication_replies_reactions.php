<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/RuntimeSecrets.php';
RuntimeSecrets::ensureCliEnvLoaded();
require_once __DIR__ . '/../src/db.php';

$pdo = cw_db();

$col = (int)$pdo->query("
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'ipca_communication_messages'
      AND COLUMN_NAME = 'reply_to_message_id'
")->fetchColumn();
if ($col === 0) {
    $pdo->exec('ALTER TABLE ipca_communication_messages ADD COLUMN reply_to_message_id BIGINT UNSIGNED NULL AFTER reply_allowed');
    echo "added reply_to_message_id\n";
} else {
    echo "reply_to_message_id exists\n";
}

$idx = (int)$pdo->query("
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'ipca_communication_messages'
      AND INDEX_NAME = 'idx_comm_msg_reply'
")->fetchColumn();
if ($idx === 0) {
    $pdo->exec('ALTER TABLE ipca_communication_messages ADD KEY idx_comm_msg_reply (reply_to_message_id)');
    echo "added idx_comm_msg_reply\n";
} else {
    echo "idx_comm_msg_reply exists\n";
}

$pdo->exec("
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
");

$count = (int)$pdo->query('SELECT COUNT(*) FROM ipca_communication_message_reactions')->fetchColumn();
echo 'reactions_table_ok rows=' . $count . PHP_EOL;
echo 'ok' . PHP_EOL;
