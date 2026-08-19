-- Human mobile scheduler API idempotency receipts.
-- This table stores mutation receipts only; schedule truth remains in
-- ipca_flight_schedule_slots and canonical operational identity tables.

CREATE TABLE IF NOT EXISTS ipca_scheduler_api_mutations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  actor_user_id INT NOT NULL,
  organization_id BIGINT UNSIGNED NOT NULL,
  idempotency_key VARCHAR(128) NOT NULL,
  request_sha256 CHAR(64) NOT NULL,
  reservation_uuid CHAR(36) NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'processing',
  created_at_utc DATETIME(3) NOT NULL,
  updated_at_utc DATETIME(3) NOT NULL,
  completed_at_utc DATETIME(3) NULL,
  UNIQUE KEY uk_scheduler_mutation_actor_key (actor_user_id, idempotency_key),
  KEY idx_scheduler_mutation_reservation (reservation_uuid),
  KEY idx_scheduler_mutation_status (status, updated_at_utc),
  CONSTRAINT fk_scheduler_mutation_user
    FOREIGN KEY (actor_user_id) REFERENCES users(id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT chk_scheduler_mutation_status
    CHECK (status IN ('processing', 'completed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Retry-safe human scheduler API mutation receipts; not reservation truth.';
