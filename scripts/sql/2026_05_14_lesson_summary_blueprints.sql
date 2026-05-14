-- Lesson Summary Blueprints
--
-- Canonical lesson-level structure maps for Maya Summary Coach.
-- The parent row is only a stable lesson pointer/status record. Every
-- generation writes an immutable version row; blueprint_json is never
-- overwritten in place.

CREATE TABLE IF NOT EXISTS lesson_summary_blueprints (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lesson_id BIGINT UNSIGNED NOT NULL,
  active_version_id BIGINT UNSIGNED NULL,
  current_status ENUM('missing','active','stale','draft','failed') NOT NULL DEFAULT 'missing',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_lesson (lesson_id),
  KEY idx_active_version (active_version_id),
  KEY idx_current_status (current_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lesson_summary_blueprint_versions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  blueprint_id BIGINT UNSIGNED NOT NULL,
  lesson_id BIGINT UNSIGNED NOT NULL,
  version_number INT UNSIGNED NOT NULL,
  status ENUM('draft','active','stale','failed','superseded') NOT NULL DEFAULT 'draft',
  blueprint_json LONGTEXT NOT NULL,
  source_enrichment_hash CHAR(64) NOT NULL,
  confidence_score DECIMAL(5,2) NULL,
  warnings_json LONGTEXT NULL,
  generated_by VARCHAR(64) NOT NULL DEFAULT 'ai',
  generation_reason VARCHAR(64) NULL,
  generated_at DATETIME NOT NULL,
  activated_at DATETIME NULL,
  superseded_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_blueprint_version (blueprint_id, version_number),
  KEY idx_lesson_status (lesson_id, status),
  KEY idx_lesson_hash (lesson_id, source_enrichment_hash),
  KEY idx_blueprint_status (blueprint_id, status),
  CONSTRAINT fk_lsbv_blueprint
    FOREIGN KEY (blueprint_id)
    REFERENCES lesson_summary_blueprints (id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
