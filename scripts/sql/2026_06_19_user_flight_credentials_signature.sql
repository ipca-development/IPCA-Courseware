-- Flight credentials and reusable electronic signature images for users.
-- Used by Forms and printable logbooks for medical/license/signature auto-fill.

CREATE TABLE IF NOT EXISTS ipca_user_flight_credentials (
  id                                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id                                 INT NOT NULL,
  pilot_certificate_number                VARCHAR(128) NULL,
  pilot_certificate_level                 VARCHAR(128) NULL,
  pilot_certificate_issuer                VARCHAR(128) NULL,
  pilot_certificate_expiration_date        DATE NULL,
  medical_certificate_class               VARCHAR(64) NULL,
  medical_certificate_issuer              VARCHAR(128) NULL,
  medical_certificate_expiration_date      DATE NULL,
  medical_restrictions                    TEXT NULL,
  instructor_certificate_number           VARCHAR(128) NULL,
  instructor_certificate_expiration_date   DATE NULL,
  ground_instructor_certificate_number    VARCHAR(128) NULL,
  license_country                         VARCHAR(64) NULL,
  signature_image_path                    VARCHAR(512) NULL,
  signature_image_hash                    CHAR(64) NULL,
  signature_captured_at                   DATETIME NULL,
  metadata_json                           JSON NULL,
  created_at                              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipca_user_flight_credentials_user (user_id),
  KEY idx_ipca_user_flight_credentials_signature (signature_image_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='User medical, pilot/instructor license data, and reusable electronic signature image.';

SET @users_id_type := (
  SELECT COLUMN_TYPE
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'id'
  LIMIT 1
);
SET @users_id_type := COALESCE(@users_id_type, 'INT');

SET @sql := CONCAT('ALTER TABLE ipca_user_flight_credentials MODIFY user_id ', @users_id_type, ' NOT NULL');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_fk := (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ipca_user_flight_credentials'
    AND CONSTRAINT_NAME = 'fk_ipca_user_flight_credentials_user'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(@has_fk = 0,
  'ALTER TABLE ipca_user_flight_credentials ADD CONSTRAINT fk_ipca_user_flight_credentials_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
