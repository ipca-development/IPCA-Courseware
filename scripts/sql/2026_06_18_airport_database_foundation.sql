-- IPCA Flight Training Airport Database foundation.
-- Stores airport position/elevation/towered status plus runway metadata for
-- logbook distance calculations and future requirement checks.

CREATE TABLE IF NOT EXISTS ipca_airports (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  icao_identifier    CHAR(4) NOT NULL,
  full_name          VARCHAR(255) NOT NULL,
  city               VARCHAR(128) NULL,
  region             VARCHAR(128) NULL,
  country            VARCHAR(128) NULL,
  latitude_deg       DECIMAL(10,7) NOT NULL,
  longitude_deg      DECIMAL(10,7) NOT NULL,
  elevation_ft       INT NULL,
  is_towered         TINYINT(1) NOT NULL DEFAULT 0,
  source             VARCHAR(64) NOT NULL DEFAULT 'manual',
  source_confidence  DECIMAL(4,3) NULL,
  source_json        JSON NULL,
  fetched_at         DATETIME NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipca_airports_icao (icao_identifier),
  KEY idx_ipca_airports_country_region (country, region),
  KEY idx_ipca_airports_towered (is_towered)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Flight Training - airport position and operating metadata.';

CREATE TABLE IF NOT EXISTS ipca_airport_runways (
  id                         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  airport_id                 BIGINT UNSIGNED NOT NULL,
  runway_identifier          VARCHAR(16) NOT NULL,
  magnetic_direction_deg     DECIMAL(5,1) NULL,
  length_ft                  INT NULL,
  surface                    VARCHAR(64) NULL,
  source_json                JSON NULL,
  created_at                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipca_airport_runway (airport_id, runway_identifier),
  KEY idx_ipca_airport_runways_airport (airport_id),
  CONSTRAINT fk_ipca_airport_runways_airport
    FOREIGN KEY (airport_id) REFERENCES ipca_airports (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Flight Training - runway headings and lengths per airport.';

INSERT INTO ipca_airports
  (icao_identifier, full_name, city, region, country, latitude_deg, longitude_deg, elevation_ft, is_towered, source, source_confidence, fetched_at)
VALUES
  ('KTRM', 'Jacqueline Cochran Regional Airport', 'Thermal', 'California', 'United States', 33.6267000, -116.1597000, -115, 1, 'seed', 1.000, CURRENT_TIMESTAMP),
  ('KPSP', 'Palm Springs International Airport', 'Palm Springs', 'California', 'United States', 33.8297000, -116.5067000, 477, 1, 'seed', 1.000, CURRENT_TIMESTAMP),
  ('EBAW', 'Antwerp International Airport', 'Antwerp', 'Antwerp', 'Belgium', 51.1894000, 4.4603000, 39, 1, 'seed', 1.000, CURRENT_TIMESTAMP),
  ('EBBR', 'Brussels Airport', 'Brussels', 'Flemish Brabant', 'Belgium', 50.9014000, 4.4844000, 184, 1, 'seed', 1.000, CURRENT_TIMESTAMP),
  ('EBLG', 'Liege Airport', 'Liege', 'Liege', 'Belgium', 50.6374000, 5.4432000, 659, 1, 'seed', 1.000, CURRENT_TIMESTAMP),
  ('EBOS', 'Ostend-Bruges International Airport', 'Ostend', 'West Flanders', 'Belgium', 51.1998000, 2.8747000, 13, 1, 'seed', 1.000, CURRENT_TIMESTAMP),
  ('EBKT', 'Kortrijk-Wevelgem International Airport', 'Kortrijk', 'West Flanders', 'Belgium', 50.8172000, 3.2047000, 64, 0, 'seed', 1.000, CURRENT_TIMESTAMP)
ON DUPLICATE KEY UPDATE
  full_name = VALUES(full_name),
  city = VALUES(city),
  region = VALUES(region),
  country = VALUES(country),
  latitude_deg = VALUES(latitude_deg),
  longitude_deg = VALUES(longitude_deg),
  elevation_ft = VALUES(elevation_ft),
  is_towered = VALUES(is_towered),
  updated_at = CURRENT_TIMESTAMP;
