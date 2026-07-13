-- Lightweight versioned mission catalog and Flight Record assignment snapshots.

CREATE TABLE IF NOT EXISTS ipca_missions (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  mission_uuid     CHAR(36) NOT NULL,
  organization_id  BIGINT UNSIGNED NOT NULL DEFAULT 1,
  code             VARCHAR(64) NOT NULL,
  name             VARCHAR(255) NOT NULL,
  status           VARCHAR(32) NOT NULL DEFAULT 'active',
  current_version_id BIGINT UNSIGNED NULL,
  created_at       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_missions_uuid (mission_uuid),
  UNIQUE KEY uk_ipca_missions_code (organization_id, code),
  KEY idx_ipca_missions_status (status, code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Stable mission catalog identity.';

CREATE TABLE IF NOT EXISTS ipca_mission_versions (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  mission_id         BIGINT UNSIGNED NOT NULL,
  mission_version_uuid CHAR(36) NOT NULL,
  version_number     INT UNSIGNED NOT NULL,
  code_snapshot      VARCHAR(64) NOT NULL,
  name_snapshot      VARCHAR(255) NOT NULL,
  description        TEXT NULL,
  exercise_json      JSON NULL,
  effective_from_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  retired_at         DATETIME(3) NULL,
  created_by         BIGINT UNSIGNED NULL,
  created_at         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_mission_versions_uuid (mission_version_uuid),
  UNIQUE KEY uk_ipca_mission_versions_number (mission_id, version_number),
  CONSTRAINT fk_ipca_mission_versions_mission
    FOREIGN KEY (mission_id) REFERENCES ipca_missions(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Immutable mission catalog versions.';

CREATE TABLE IF NOT EXISTS ipca_mission_aliases (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  mission_id      BIGINT UNSIGNED NOT NULL,
  alias           VARCHAR(128) NOT NULL,
  source          VARCHAR(64) NOT NULL DEFAULT 'manual',
  created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_mission_aliases_alias (alias),
  KEY idx_ipca_mission_aliases_mission (mission_id),
  CONSTRAINT fk_ipca_mission_aliases_mission
    FOREIGN KEY (mission_id) REFERENCES ipca_missions(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Mission code/name aliases for imports and instructor shorthand.';

CREATE TABLE IF NOT EXISTS ipca_mission_import_batches (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  import_uuid       CHAR(36) NOT NULL,
  source            VARCHAR(64) NOT NULL,
  status            VARCHAR(32) NOT NULL DEFAULT 'pending',
  raw_storage_path  VARCHAR(1024) NULL,
  result_json       JSON NULL,
  imported_by       BIGINT UNSIGNED NULL,
  created_at        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_mission_import_batches_uuid (import_uuid),
  KEY idx_ipca_mission_import_batches_status (status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Mission catalog import batches.';

CREATE TABLE IF NOT EXISTS ipca_flight_mission_assignments (
  id                         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  assignment_uuid             CHAR(36) NOT NULL,
  flight_record_version_id    BIGINT UNSIGNED NOT NULL,
  leg_version_id              BIGINT UNSIGNED NULL,
  mission_version_id          BIGINT UNSIGNED NOT NULL,
  start_utc                   DATETIME(3) NULL,
  end_utc                     DATETIME(3) NULL,
  source                      VARCHAR(64) NOT NULL DEFAULT 'manual',
  confidence                  DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
  exercise_snapshot_json      JSON NULL,
  instructor_notes            TEXT NULL,
  created_by                  BIGINT UNSIGNED NULL,
  created_at                  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_flight_mission_assignments_uuid (assignment_uuid),
  KEY idx_ipca_flight_mission_assignments_record (flight_record_version_id),
  KEY idx_ipca_flight_mission_assignments_leg (leg_version_id),
  CONSTRAINT fk_ipca_flight_mission_assignments_record
    FOREIGN KEY (flight_record_version_id) REFERENCES ipca_operational_flight_record_versions(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ipca_flight_mission_assignments_leg
    FOREIGN KEY (leg_version_id) REFERENCES ipca_operational_flight_leg_versions(id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_ipca_flight_mission_assignments_mission_version
    FOREIGN KEY (mission_version_id) REFERENCES ipca_mission_versions(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Mission assignment snapshots for Flight Record versions and legs.';
