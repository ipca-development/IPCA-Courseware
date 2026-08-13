-- Append-only administrative corrections for locked CVR flight log projections.

CREATE TABLE IF NOT EXISTS ipca_cvr_flight_log_adjustments (
  id                          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  adjustment_uuid             CHAR(36) NOT NULL,
  organization_id             BIGINT UNSIGNED NOT NULL DEFAULT 1,
  device_id                   BIGINT UNSIGNED NOT NULL,
  workflow_flight_record_uuid CHAR(36) NOT NULL,
  dispatch_uuid               CHAR(36) NOT NULL,
  departure_airport           VARCHAR(8) NOT NULL DEFAULT '',
  arrival_airport             VARCHAR(8) NOT NULL DEFAULT '',
  crew_json                   JSON NOT NULL,
  starting_hobbs              DECIMAL(12,4) NULL,
  starting_tacho              DECIMAL(12,4) NULL,
  fuel_onboard                VARCHAR(64) NULL,
  ending_hobbs                DECIMAL(12,4) NOT NULL,
  ending_tacho                DECIMAL(12,4) NOT NULL,
  fuel_remaining              VARCHAR(64) NOT NULL,
  reason                      VARCHAR(255) NOT NULL DEFAULT 'PIN-protected iOS flight log adjustment',
  created_at                  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_cvr_flight_log_adjustments_uuid (adjustment_uuid),
  KEY idx_ipca_cvr_flight_log_adjustments_flight (workflow_flight_record_uuid, created_at),
  KEY idx_ipca_cvr_flight_log_adjustments_device (device_id, created_at),
  CONSTRAINT fk_ipca_cvr_flight_log_adjustments_device
    FOREIGN KEY (device_id) REFERENCES ipca_cvr_devices(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Append-only PIN-authorized corrections projected over locked CVR flight logs.';
