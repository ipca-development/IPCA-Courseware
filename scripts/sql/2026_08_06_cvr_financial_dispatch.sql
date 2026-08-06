-- Instructional rates + per-leg financial dispatch (Edit Operational Leg).

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET collation_connection = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_instructional_rates (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  rate_code VARCHAR(64) NOT NULL,
  label VARCHAR(190) NOT NULL,
  rate_usd_per_hour DECIMAL(10,2) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_instructional_rates_code (rate_code),
  KEY idx_ipca_instructional_rates_active (active, sort_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Instructor hourly rates for CVR financial dispatch.';

INSERT INTO ipca_instructional_rates (rate_code, label, rate_usd_per_hour, sort_order, active)
SELECT 'VFR_EXP_SE', 'VFR/EXP/SE', 89.00, 10, 1
WHERE NOT EXISTS (SELECT 1 FROM ipca_instructional_rates WHERE rate_code = 'VFR_EXP_SE');

INSERT INTO ipca_instructional_rates (rate_code, label, rate_usd_per_hour, sort_order, active)
SELECT 'IFR_CPL_CFI_SE', 'IFR/CPL/CFI/SE', 99.00, 20, 1
WHERE NOT EXISTS (SELECT 1 FROM ipca_instructional_rates WHERE rate_code = 'IFR_CPL_CFI_SE');

INSERT INTO ipca_instructional_rates (rate_code, label, rate_usd_per_hour, sort_order, active)
SELECT 'OWN_AIRPLANE', 'OWN AIRPLANE', 109.00, 30, 1
WHERE NOT EXISTS (SELECT 1 FROM ipca_instructional_rates WHERE rate_code = 'OWN_AIRPLANE');

CREATE TABLE IF NOT EXISTS ipca_aircraft_rental_rates (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  aircraft_id BIGINT UNSIGNED NULL,
  aircraft_registration VARCHAR(32) NOT NULL,
  display_label VARCHAR(190) NOT NULL DEFAULT '',
  rate_usd_per_hour DECIMAL(10,2) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_aircraft_rental_rates_reg (aircraft_registration),
  KEY idx_ipca_aircraft_rental_rates_active (active, aircraft_registration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Aircraft hourly rental rates for CVR financial dispatch.';

INSERT INTO ipca_aircraft_rental_rates (aircraft_id, aircraft_registration, display_label, rate_usd_per_hour, active)
SELECT 3, 'N392EA', 'Alpha Pro N392EA', 175.00, 1
WHERE NOT EXISTS (SELECT 1 FROM ipca_aircraft_rental_rates WHERE aircraft_registration = 'N392EA');

INSERT INTO ipca_aircraft_rental_rates (aircraft_id, aircraft_registration, display_label, rate_usd_per_hour, active)
SELECT 1, 'N397EA', 'Alpha Pro N397EA', 175.00, 1
WHERE NOT EXISTS (SELECT 1 FROM ipca_aircraft_rental_rates WHERE aircraft_registration = 'N397EA');

INSERT INTO ipca_aircraft_rental_rates (aircraft_id, aircraft_registration, display_label, rate_usd_per_hour, active)
SELECT 4, 'N428EA', 'Alpha Pro N428EA', 175.00, 1
WHERE NOT EXISTS (SELECT 1 FROM ipca_aircraft_rental_rates WHERE aircraft_registration = 'N428EA');

INSERT INTO ipca_aircraft_rental_rates (aircraft_id, aircraft_registration, display_label, rate_usd_per_hour, active)
SELECT 2, 'N446CS', 'Cessna 172S N446CS', 185.00, 1
WHERE NOT EXISTS (SELECT 1 FROM ipca_aircraft_rental_rates WHERE aircraft_registration = 'N446CS');

CREATE TABLE IF NOT EXISTS ipca_user_account_balances (
  user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  balance_usd DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Running customer account totals for financial dispatch overview.';

CREATE TABLE IF NOT EXISTS ipca_cvr_financial_dispatches (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  dispatch_id BIGINT UNSIGNED NOT NULL,
  workflow_flight_record_uuid CHAR(36) NULL,
  customer_user_id BIGINT UNSIGNED NULL,
  customer_name VARCHAR(190) NOT NULL DEFAULT '',
  instructor_user_id BIGINT UNSIGNED NULL,
  instructor_name VARCHAR(190) NOT NULL DEFAULT '',
  aircraft_registration VARCHAR(32) NOT NULL DEFAULT '',
  aircraft_label VARCHAR(190) NOT NULL DEFAULT '',
  preflight_briefing_hours DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  flight_instruction_hours DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  ground_instruction_hours DECIMAL(6,2) NOT NULL DEFAULT 0.30,
  instructional_rate_id BIGINT UNSIGNED NULL,
  instructional_rate_code VARCHAR(64) NOT NULL DEFAULT '',
  instructional_rate_label VARCHAR(190) NOT NULL DEFAULT '',
  instructional_rate_usd_per_hour DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  aircraft_rate_usd_per_hour DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  aircraft_rental_total_usd DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  flight_instruction_total_usd DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  ground_instruction_total_usd DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  session_total_usd DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  existing_balance_usd DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  grand_total_usd DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  status VARCHAR(32) NOT NULL DEFAULT 'draft',
  locked_at DATETIME(3) NULL,
  locked_by BIGINT UNSIGNED NULL,
  unlocked_at DATETIME(3) NULL,
  unlocked_by BIGINT UNSIGNED NULL,
  unlock_reason VARCHAR(500) NOT NULL DEFAULT '',
  payload_json JSON NULL,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_cvr_financial_dispatches_dispatch (dispatch_id),
  KEY idx_ipca_cvr_financial_dispatches_status (status, locked_at),
  KEY idx_ipca_cvr_financial_dispatches_customer (customer_user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-leg financial dispatch draft/lock for Master Logbook operational legs.';
