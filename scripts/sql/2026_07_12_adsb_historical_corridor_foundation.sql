-- ADS-B historical corridor foundation for Flight Records.
-- Additive only; does not modify existing cockpit recorder replay tables.

CREATE TABLE IF NOT EXISTS ipca_adsb_historical_requests (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  request_uuid           CHAR(36) NOT NULL,
  organization_id        BIGINT UNSIGNED NOT NULL DEFAULT 1,
  session_id             BIGINT UNSIGNED NULL,
  flight_record_id       BIGINT UNSIGNED NULL,
  provider               VARCHAR(64) NOT NULL DEFAULT 'adsbexchange',
  status                 VARCHAR(32) NOT NULL DEFAULT 'pending',
  query_start_utc        DATETIME(3) NOT NULL,
  query_end_utc          DATETIME(3) NOT NULL,
  center_latitude        DECIMAL(11,7) NULL,
  center_longitude       DECIMAL(11,7) NULL,
  search_radius_nm       DECIMAL(8,2) NOT NULL DEFAULT 10.00,
  raw_storage_path       VARCHAR(1024) NULL,
  normalized_storage_path VARCHAR(1024) NULL,
  traffic_sample_count   INT UNSIGNED NOT NULL DEFAULT 0,
  error_message          TEXT NULL,
  requested_by           BIGINT UNSIGNED NULL,
  created_at             DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at             DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_adsb_historical_requests_uuid (request_uuid),
  KEY idx_ipca_adsb_historical_requests_session (session_id, status),
  KEY idx_ipca_adsb_historical_requests_record (flight_record_id, status),
  KEY idx_ipca_adsb_historical_requests_status (status, updated_at),
  CONSTRAINT fk_ipca_adsb_historical_requests_session
    FOREIGN KEY (session_id) REFERENCES ipca_flight_sessions(id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_ipca_adsb_historical_requests_record
    FOREIGN KEY (flight_record_id) REFERENCES ipca_operational_flight_records(id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Flight Record ADS-B historical corridor fetch requests and immutable raw storage pointers.';

CREATE TABLE IF NOT EXISTS ipca_adsb_historical_traffic_samples (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  request_id             BIGINT UNSIGNED NOT NULL,
  session_id             BIGINT UNSIGNED NULL,
  flight_record_id       BIGINT UNSIGNED NULL,
  sample_time_utc        DATETIME(3) NULL,
  aircraft_hex           CHAR(6) NOT NULL DEFAULT '',
  callsign               VARCHAR(32) NOT NULL DEFAULT '',
  latitude               DECIMAL(11,7) NULL,
  longitude              DECIMAL(11,7) NULL,
  altitude_ft            DECIMAL(10,1) NULL,
  groundspeed_kt         DECIMAL(10,2) NULL,
  track_deg              DECIMAL(7,2) NULL,
  vertical_speed_fpm     DECIMAL(10,1) NULL,
  distance_nm            DECIMAL(8,2) NULL,
  raw_json               JSON NULL,
  created_at             DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY idx_ipca_adsb_hist_traffic_session_time (session_id, sample_time_utc),
  KEY idx_ipca_adsb_hist_traffic_record_time (flight_record_id, sample_time_utc),
  KEY idx_ipca_adsb_hist_traffic_hex (aircraft_hex),
  CONSTRAINT fk_ipca_adsb_hist_traffic_request
    FOREIGN KEY (request_id) REFERENCES ipca_adsb_historical_requests(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Normalized ADS-B corridor traffic samples for additive replay overlays.';
