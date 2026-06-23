-- Version registry for printable EASA/FAA logbook layouts.
-- The current production renderer is frozen in:
-- src/flight_training/PrintableLogbookPrint_v1_0_frozen_2026_06_23.php

CREATE TABLE IF NOT EXISTS ipca_logbook_layout_templates (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  format              VARCHAR(16) NOT NULL COMMENT 'easa | faa',
  layout_key          VARCHAR(128) NOT NULL,
  title               VARCHAR(255) NOT NULL,
  status              VARCHAR(32) NOT NULL DEFAULT 'active',
  active_version_id   BIGINT UNSIGNED NULL,
  created_by          INT NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipca_logbook_layout_format_key (format, layout_key),
  KEY idx_ipca_logbook_layout_status (format, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Flight Training - printable logbook layout template registry.';

CREATE TABLE IF NOT EXISTS ipca_logbook_layout_versions (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  template_id           BIGINT UNSIGNED NOT NULL,
  version_label         VARCHAR(32) NOT NULL,
  status                VARCHAR(32) NOT NULL DEFAULT 'active',
  renderer_source_path  VARCHAR(512) NOT NULL,
  source_hash           CHAR(64) NOT NULL,
  renderer_config_json  JSON NULL,
  notes                 TEXT NULL,
  created_by            INT NULL,
  approved_by           INT NULL,
  activated_by          INT NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  approved_at           DATETIME NULL,
  activated_at          DATETIME NULL,
  UNIQUE KEY uk_ipca_logbook_layout_version (template_id, version_label),
  KEY idx_ipca_logbook_layout_versions_status (template_id, status),
  CONSTRAINT fk_ipca_logbook_layout_versions_template
    FOREIGN KEY (template_id) REFERENCES ipca_logbook_layout_templates (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Flight Training - immutable printable logbook layout versions.';

SET @frozen_path := 'src/flight_training/PrintableLogbookPrint_v1_0_frozen_2026_06_23.php';
SET @frozen_hash := '8503996f63470839fda88adbc7d500f1ac1830f92ac8e18775db8ece683126bb';

INSERT INTO ipca_logbook_layout_templates (format, layout_key, title, status)
VALUES
  ('easa', 'easa_standard', 'EASA Printable Logbook', 'active'),
  ('faa', 'faa_standard', 'FAA Printable Logbook', 'active')
ON DUPLICATE KEY UPDATE
  title = VALUES(title),
  status = VALUES(status),
  updated_at = CURRENT_TIMESTAMP;

INSERT INTO ipca_logbook_layout_versions
  (template_id, version_label, status, renderer_source_path, source_hash, renderer_config_json, notes, approved_at, activated_at)
SELECT id, '1.0', 'active', @frozen_path, @frozen_hash,
       JSON_OBJECT('renderer', 'php-svg-cell-map', 'frozen_on', '2026-06-23'),
       'Frozen production layout before student logbook/shared-renderer work.',
       CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM ipca_logbook_layout_templates
WHERE format IN ('easa', 'faa')
ON DUPLICATE KEY UPDATE
  status = VALUES(status),
  renderer_source_path = VALUES(renderer_source_path),
  source_hash = VALUES(source_hash),
  renderer_config_json = VALUES(renderer_config_json),
  notes = VALUES(notes),
  activated_at = VALUES(activated_at);

UPDATE ipca_logbook_layout_templates t
INNER JOIN ipca_logbook_layout_versions v
  ON v.template_id = t.id
 AND v.version_label = '1.0'
SET t.active_version_id = v.id,
    t.updated_at = CURRENT_TIMESTAMP
WHERE t.format IN ('easa', 'faa');
