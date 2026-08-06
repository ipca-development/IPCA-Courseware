-- Aircraft fuel uplift ledger for Master Logbook fleet status cards.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET collation_connection = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_aircraft_fuel_uplifts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uplift_uuid CHAR(36) NOT NULL,
  aircraft_id BIGINT UNSIGNED NOT NULL,
  aircraft_registration VARCHAR(32) NOT NULL,
  uplifted_at DATETIME(3) NOT NULL,
  fuel_after_usg DECIMAL(8,2) NOT NULL,
  fuel_unit VARCHAR(16) NOT NULL DEFAULT 'USG',
  notes VARCHAR(500) NOT NULL DEFAULT '',
  created_by BIGINT UNSIGNED NULL,
  deleted_at DATETIME(3) NULL,
  deleted_by BIGINT UNSIGNED NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_aircraft_fuel_uplifts_uuid (uplift_uuid),
  KEY idx_ipca_aircraft_fuel_uplifts_aircraft_time (aircraft_registration, uplifted_at, id),
  KEY idx_ipca_aircraft_fuel_uplifts_active (aircraft_id, deleted_at, uplifted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Admin-logged fuel after refueling for Master Logbook fleet cards.';
