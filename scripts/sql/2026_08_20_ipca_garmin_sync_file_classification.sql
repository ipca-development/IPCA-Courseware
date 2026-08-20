-- Derived Garmin Sync file classification.
-- Archived bytes remain immutable; this table may be rebuilt as classification improves.

CREATE TABLE IF NOT EXISTS ipca_garmin_sync_file_classifications (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  archive_file_id       BIGINT UNSIGNED NOT NULL,
  source_kind           VARCHAR(32) NOT NULL,
  analysis_eligible     TINYINT(1) NOT NULL DEFAULT 0,
  aircraft_registration VARCHAR(32) NULL,
  product               VARCHAR(128) NULL,
  system_identifier     VARCHAR(128) NULL,
  import_profile        VARCHAR(64) NULL,
  classification_reason VARCHAR(512) NOT NULL DEFAULT '',
  metadata_json         JSON NULL,
  classifier_version    VARCHAR(32) NOT NULL,
  classified_at         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_at            DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at            DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_garmin_sync_classification_archive (archive_file_id),
  KEY idx_ipca_garmin_sync_classification_kind (source_kind, analysis_eligible),
  KEY idx_ipca_garmin_sync_classification_registration (aircraft_registration),
  CONSTRAINT fk_ipca_garmin_sync_classification_archive
    FOREIGN KEY (archive_file_id) REFERENCES ipca_garmin_sync_archive_files(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Rebuildable classification and aircraft identity derived from immutable Garmin Sync archive bytes.';
