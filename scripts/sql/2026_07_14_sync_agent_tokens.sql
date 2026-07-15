CREATE TABLE IF NOT EXISTS ipca_sync_agent_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  token_uuid CHAR(36) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  display_name VARCHAR(190) NOT NULL,
  scope_json JSON NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_seen_at DATETIME(3) NULL,
  revoked_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_ipca_sync_agent_tokens_uuid (token_uuid),
  UNIQUE KEY uq_ipca_sync_agent_tokens_hash (token_hash),
  KEY idx_ipca_sync_agent_tokens_active (is_active, last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_sync_agent_upload_acknowledgments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  token_id BIGINT UNSIGNED NOT NULL,
  provider_name VARCHAR(64) NOT NULL,
  idempotency_key CHAR(128) NOT NULL,
  garmin_entry_uuid VARCHAR(128) NULL,
  flight_data_log_uuid CHAR(36) NULL,
  sha256 CHAR(64) NOT NULL,
  status VARCHAR(32) NOT NULL,
  garmin_csv_file_id BIGINT UNSIGNED NULL,
  response_json JSON NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_ipca_sync_agent_ack_idempotency (idempotency_key),
  KEY idx_ipca_sync_agent_ack_token (token_id, created_at),
  KEY idx_ipca_sync_agent_ack_source (provider_name, flight_data_log_uuid),
  CONSTRAINT fk_ipca_sync_agent_ack_token FOREIGN KEY (token_id) REFERENCES ipca_sync_agent_tokens(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
