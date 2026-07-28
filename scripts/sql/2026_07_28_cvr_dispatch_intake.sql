-- Durable, idempotent Dispatch intake from enrolled CVR recorder units.
-- Additive and re-run safe.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET collation_connection = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_cvr_dispatches (
  id                           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  dispatch_uuid                CHAR(36) NOT NULL,
  organization_id              BIGINT UNSIGNED NOT NULL DEFAULT 1,
  device_id                    BIGINT UNSIGNED NOT NULL,
  workflow_flight_record_uuid  CHAR(36) NOT NULL,
  current_version              INT UNSIGNED NOT NULL DEFAULT 1,
  aircraft_id                  BIGINT UNSIGNED NULL,
  aircraft_registration        VARCHAR(32) NOT NULL DEFAULT '',
  scheduled_date               DATE NOT NULL,
  mission_code                 VARCHAR(64) NOT NULL DEFAULT '',
  crew_json                    JSON NOT NULL,
  starting_hobbs               DECIMAL(12,4) NULL,
  starting_tacho               DECIMAL(12,4) NULL,
  fuel_onboard                 VARCHAR(64) NOT NULL DEFAULT '',
  oil_percentage               TINYINT UNSIGNED NULL,
  dispatch_source              VARCHAR(64) NOT NULL DEFAULT 'iphone_offline_local',
  consent_status               VARCHAR(64) NOT NULL DEFAULT '',
  status                       VARCHAR(64) NOT NULL DEFAULT '',
  cvr_unit_identifier          VARCHAR(32) NOT NULL DEFAULT '',
  beacon_identifier            VARCHAR(64) NOT NULL DEFAULT '',
  first_received_at            DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  last_received_at             DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_at                   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at                   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_cvr_dispatches_uuid (dispatch_uuid),
  UNIQUE KEY uk_ipca_cvr_dispatches_flight_record (workflow_flight_record_uuid),
  KEY idx_ipca_cvr_dispatches_received (last_received_at),
  KEY idx_ipca_cvr_dispatches_aircraft_date (aircraft_registration, scheduled_date),
  KEY idx_ipca_cvr_dispatches_device (device_id, last_received_at),
  CONSTRAINT fk_ipca_cvr_dispatches_device
    FOREIGN KEY (device_id) REFERENCES ipca_cvr_devices(id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_ipca_cvr_dispatches_aircraft
    FOREIGN KEY (aircraft_id) REFERENCES ipca_aircraft_devices(id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Current server projection of each iOS CVR Dispatch.';

CREATE TABLE IF NOT EXISTS ipca_cvr_dispatch_versions (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  dispatch_id           BIGINT UNSIGNED NOT NULL,
  dispatch_version      INT UNSIGNED NOT NULL,
  receipt_uuid          CHAR(36) NOT NULL,
  device_id             BIGINT UNSIGNED NOT NULL,
  payload_sha256        CHAR(64) NOT NULL,
  payload_json          JSON NOT NULL,
  received_at           DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_cvr_dispatch_versions_version (dispatch_id, dispatch_version),
  UNIQUE KEY uk_ipca_cvr_dispatch_versions_receipt (receipt_uuid),
  KEY idx_ipca_cvr_dispatch_versions_hash (payload_sha256),
  KEY idx_ipca_cvr_dispatch_versions_received (received_at),
  CONSTRAINT fk_ipca_cvr_dispatch_versions_dispatch
    FOREIGN KEY (dispatch_id) REFERENCES ipca_cvr_dispatches(id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_ipca_cvr_dispatch_versions_device
    FOREIGN KEY (device_id) REFERENCES ipca_cvr_devices(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Immutable Dispatch payload versions and server receipts.';

CREATE TABLE IF NOT EXISTS ipca_cvr_dispatch_consents (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  consent_uuid          CHAR(36) NOT NULL,
  dispatch_id           BIGINT UNSIGNED NOT NULL,
  dispatch_version      INT UNSIGNED NOT NULL,
  person_id             BIGINT UNSIGNED NULL,
  person_name           VARCHAR(255) NOT NULL DEFAULT '',
  crew_role             VARCHAR(64) NOT NULL DEFAULT '',
  consent_result        TINYINT(1) NOT NULL,
  consented_at          DATETIME(3) NOT NULL,
  source_device_uuid    VARCHAR(96) NOT NULL DEFAULT '',
  consent_text_version  VARCHAR(96) NOT NULL DEFAULT '',
  app_version           VARCHAR(64) NOT NULL DEFAULT '',
  payload_json          JSON NOT NULL,
  received_at           DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_cvr_dispatch_consents_uuid (consent_uuid),
  KEY idx_ipca_cvr_dispatch_consents_dispatch (dispatch_id, dispatch_version),
  KEY idx_ipca_cvr_dispatch_consents_person (person_id, consented_at),
  CONSTRAINT fk_ipca_cvr_dispatch_consents_dispatch
    FOREIGN KEY (dispatch_id) REFERENCES ipca_cvr_dispatches(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Immutable crew consent evidence received with a Dispatch.';
