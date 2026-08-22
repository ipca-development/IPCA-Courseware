-- Theory Content Studio structured-slide persistence.
-- Additive only: legacy screenshot slides remain untouched and are classified by
-- the new column default. Geometry belongs exclusively to immutable template versions.

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE slides ADD COLUMN source_category VARCHAR(32) NOT NULL DEFAULT ''legacy_screenshot''',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slides' AND COLUMN_NAME = 'source_category'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS theory_slide_templates (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  template_key VARCHAR(96) NOT NULL,
  name VARCHAR(160) NOT NULL,
  description TEXT NULL,
  owning_program_id INT NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  active_version_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_theory_slide_template_key (template_key),
  KEY idx_theory_slide_template_program (owning_program_id, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS theory_slide_template_versions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  template_id BIGINT UNSIGNED NOT NULL,
  version_number INT UNSIGNED NOT NULL,
  canvas_width SMALLINT UNSIGNED NOT NULL DEFAULT 1600,
  canvas_height SMALLINT UNSIGNED NOT NULL DEFAULT 900,
  created_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_theory_template_version (template_id, version_number),
  CONSTRAINT fk_theory_template_version_template
    FOREIGN KEY (template_id) REFERENCES theory_slide_templates(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS theory_slide_template_placeholders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  template_version_id BIGINT UNSIGNED NOT NULL,
  placeholder_key VARCHAR(96) NOT NULL,
  content_type VARCHAR(16) NOT NULL,
  semantic_role VARCHAR(64) NOT NULL,
  x SMALLINT UNSIGNED NOT NULL,
  y SMALLINT UNSIGNED NOT NULL,
  w SMALLINT UNSIGNED NOT NULL,
  h SMALLINT UNSIGNED NOT NULL,
  reading_order SMALLINT UNSIGNED NOT NULL,
  is_required TINYINT(1) NOT NULL DEFAULT 0,
  allowed_content_json JSON NOT NULL,
  allowed_style_json JSON NOT NULL,
  allowed_behavior_json JSON NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_theory_placeholder_key (template_version_id, placeholder_key),
  UNIQUE KEY uq_theory_placeholder_order (template_version_id, reading_order),
  CONSTRAINT fk_theory_placeholder_version
    FOREIGN KEY (template_version_id) REFERENCES theory_slide_template_versions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS theory_slide_template_guides (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  template_version_id BIGINT UNSIGNED NOT NULL,
  orientation VARCHAR(16) NOT NULL,
  position SMALLINT UNSIGNED NOT NULL,
  is_locked TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_theory_template_guide (template_version_id, orientation, position),
  KEY idx_theory_template_guide_version (template_version_id, id),
  CONSTRAINT fk_theory_template_guide_version
    FOREIGN KEY (template_version_id) REFERENCES theory_slide_template_versions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE theory_slide_template_guides ADD COLUMN is_locked TINYINT(1) NOT NULL DEFAULT 0 AFTER position',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'theory_slide_template_guides'
    AND COLUMN_NAME = 'is_locked'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS theory_course_outline_nodes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_id INT NOT NULL,
  parent_node_id BIGINT UNSIGNED NULL,
  node_type VARCHAR(32) NOT NULL DEFAULT 'topic',
  title VARCHAR(255) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_deleted TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_theory_outline_course_order (course_id, parent_node_id, sort_order, id),
  CONSTRAINT fk_theory_outline_parent
    FOREIGN KEY (parent_node_id) REFERENCES theory_course_outline_nodes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS theory_structured_slides (
  slide_id INT NOT NULL,
  template_version_id BIGINT UNSIGNED NOT NULL,
  outline_node_id BIGINT UNSIGNED NULL,
  content_revision INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (slide_id),
  KEY idx_theory_structured_template (template_version_id),
  KEY idx_theory_structured_outline (outline_node_id),
  CONSTRAINT fk_theory_structured_slide FOREIGN KEY (slide_id) REFERENCES slides(id),
  CONSTRAINT fk_theory_structured_template_version
    FOREIGN KEY (template_version_id) REFERENCES theory_slide_template_versions(id),
  CONSTRAINT fk_theory_structured_outline
    FOREIGN KEY (outline_node_id) REFERENCES theory_course_outline_nodes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS theory_structured_slide_text_values (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slide_id INT NOT NULL,
  placeholder_id BIGINT UNSIGNED NOT NULL,
  lang VARCHAR(16) NOT NULL,
  plain_text MEDIUMTEXT NOT NULL,
  content_json JSON NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_theory_slide_text_value (slide_id, placeholder_id, lang),
  CONSTRAINT fk_theory_text_structured_slide
    FOREIGN KEY (slide_id) REFERENCES theory_structured_slides(slide_id),
  CONSTRAINT fk_theory_text_placeholder
    FOREIGN KEY (placeholder_id) REFERENCES theory_slide_template_placeholders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS theory_structured_slide_media_values (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slide_id INT NOT NULL,
  placeholder_id BIGINT UNSIGNED NOT NULL,
  media_library_id BIGINT UNSIGNED NOT NULL,
  content_json JSON NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_theory_slide_media_value (slide_id, placeholder_id),
  KEY idx_theory_slide_media_asset (media_library_id),
  CONSTRAINT fk_theory_media_structured_slide
    FOREIGN KEY (slide_id) REFERENCES theory_structured_slides(slide_id),
  CONSTRAINT fk_theory_media_placeholder
    FOREIGN KEY (placeholder_id) REFERENCES theory_slide_template_placeholders(id),
  CONSTRAINT fk_theory_media_library
    FOREIGN KEY (media_library_id) REFERENCES ipca_training_media_library(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A safe, reusable system identity and immutable first version.
INSERT INTO theory_slide_templates
  (template_key, name, description, owning_program_id, is_system)
SELECT 'SYSTEM_TITLE_BODY', 'Title and body', 'System 1600x900 title and body template', NULL, 1
WHERE NOT EXISTS (
  SELECT 1 FROM theory_slide_templates WHERE template_key = 'SYSTEM_TITLE_BODY'
);

INSERT INTO theory_slide_template_versions
  (template_id, version_number, canvas_width, canvas_height)
SELECT t.id, 1, 1600, 900
FROM theory_slide_templates t
WHERE t.template_key = 'SYSTEM_TITLE_BODY'
  AND NOT EXISTS (
    SELECT 1 FROM theory_slide_template_versions v
    WHERE v.template_id = t.id AND v.version_number = 1
  );

INSERT INTO theory_slide_template_placeholders
  (template_version_id, placeholder_key, content_type, semantic_role,
   x, y, w, h, reading_order, is_required,
   allowed_content_json, allowed_style_json, allowed_behavior_json)
SELECT v.id, 'title', 'Text', 'heading', 120, 90, 1360, 150, 10, 1,
       JSON_OBJECT('max_length', 180), JSON_OBJECT(), JSON_OBJECT()
FROM theory_slide_template_versions v
JOIN theory_slide_templates t ON t.id = v.template_id
WHERE t.template_key = 'SYSTEM_TITLE_BODY' AND v.version_number = 1
  AND NOT EXISTS (
    SELECT 1 FROM theory_slide_template_placeholders p
    WHERE p.template_version_id = v.id AND p.placeholder_key = 'title'
  );

INSERT INTO theory_slide_template_placeholders
  (template_version_id, placeholder_key, content_type, semantic_role,
   x, y, w, h, reading_order, is_required,
   allowed_content_json, allowed_style_json, allowed_behavior_json)
SELECT v.id, 'body', 'Text', 'body', 120, 280, 1360, 500, 20, 0,
       JSON_OBJECT(), JSON_OBJECT(), JSON_OBJECT()
FROM theory_slide_template_versions v
JOIN theory_slide_templates t ON t.id = v.template_id
WHERE t.template_key = 'SYSTEM_TITLE_BODY' AND v.version_number = 1
  AND NOT EXISTS (
    SELECT 1 FROM theory_slide_template_placeholders p
    WHERE p.template_version_id = v.id AND p.placeholder_key = 'body'
  );

UPDATE theory_slide_templates t
JOIN theory_slide_template_versions v
  ON v.template_id = t.id AND v.version_number = 1
SET t.active_version_id = v.id
WHERE t.template_key = 'SYSTEM_TITLE_BODY' AND t.active_version_id IS NULL;
