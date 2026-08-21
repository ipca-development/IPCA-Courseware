-- Theory Content Studio Phase 1 foundation.
-- Additive only. Does not DROP tables/columns. Does not rewrite existing IDs,
-- program_key, course.program_id, lesson.course_id, slide.lesson_id, sort_order,
-- page_number, image_path, is_published, course.revision, cohort rows, deadlines,
-- or enrichment rows.
--
-- Inspect production SHOW COLUMNS before applying. If any statement would be
-- destructive or change existing uniqueness of non-null keys, stop.

-- Operational vs Studio-authored programs. Existing rows receive 'operational'
-- via the column default. Existing queries that do not select this column are
-- unchanged.
SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE programs ADD COLUMN authoring_origin VARCHAR(32) NOT NULL DEFAULT ''operational''',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'programs'
    AND COLUMN_NAME = 'authoring_origin'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE programs ADD COLUMN cover_image_path VARCHAR(512) NULL',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'programs'
    AND COLUMN_NAME = 'cover_image_path'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE courses ADD COLUMN cover_image_path VARCHAR(512) NULL',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'courses'
    AND COLUMN_NAME = 'cover_image_path'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE courses ADD COLUMN revision_date DATE NULL',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'courses'
    AND COLUMN_NAME = 'revision_date'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Native Studio lessons must not mint Kings external_lesson_id values.
-- Existing non-null values are not updated. Unique (course_id, external_lesson_id)
-- continues to enforce uniqueness for non-null values.
SET @sql := (
  SELECT IF(
    COUNT(*) > 0 AND SUM(CASE WHEN IS_NULLABLE = 'NO' THEN 1 ELSE 0 END) > 0,
    'ALTER TABLE lessons MODIFY external_lesson_id INT NULL',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'lessons'
    AND COLUMN_NAME = 'external_lesson_id'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS theory_program_revisions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  program_id INT NOT NULL,
  revision_number VARCHAR(32) NOT NULL,
  revision_date DATE NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'draft',
  origin VARCHAR(16) NOT NULL DEFAULT 'studio',
  cover_image_path VARCHAR(512) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tpr_program_revision (program_id, revision_number),
  KEY idx_tpr_program_status (program_id, status),
  KEY idx_tpr_origin (origin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Metadata pointers only. Does not change how students or cohorts resolve content.
INSERT INTO theory_program_revisions (
  program_id, revision_number, revision_date, status, origin, created_at, updated_at
)
SELECT
  p.id,
  COALESCE((
    SELECT NULLIF(TRIM(c.revision), '')
    FROM courses c
    WHERE c.program_id = p.id
    ORDER BY c.id ASC
    LIMIT 1
  ), '1.0'),
  NULL,
  'live',
  'legacy',
  CURRENT_TIMESTAMP,
  CURRENT_TIMESTAMP
FROM programs p
WHERE COALESCE(p.authoring_origin, 'operational') = 'operational'
  AND NOT EXISTS (
    SELECT 1
    FROM theory_program_revisions r
    WHERE r.program_id = p.id
  );
