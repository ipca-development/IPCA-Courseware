-- MCCF integrity score cache + background job progress (survives page navigation).

CREATE TABLE IF NOT EXISTS ipca_mccf_integrity_runs (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  source_set_id         BIGINT UNSIGNED NOT NULL,
  status                VARCHAR(32) NOT NULL DEFAULT 'queued'
                        COMMENT 'queued | running | completed | failed | cancelled',
  total_count           INT UNSIGNED NOT NULL DEFAULT 0,
  processed_count       INT UNSIGNED NOT NULL DEFAULT 0,
  started_at            DATETIME NULL,
  completed_at          DATETIME NULL,
  error_message         TEXT NULL,
  created_by            INT UNSIGNED NULL COMMENT 'users.id when launched from UI',
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_mccf_ir_source_status (source_set_id, status),
  KEY idx_mccf_ir_status_updated (status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='MCCF browser — background integrity scoring runs.';

CREATE TABLE IF NOT EXISTS ipca_mccf_integrity_scores (
  requirement_id        BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  source_set_id         BIGINT UNSIGNED NOT NULL,
  run_id                BIGINT UNSIGNED NULL,
  score                 TINYINT UNSIGNED NOT NULL DEFAULT 0,
  label                 VARCHAR(64) NOT NULL DEFAULT '',
  tone                  VARCHAR(16) NOT NULL DEFAULT 'muted',
  reasons_json          JSON NULL,
  scored_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_mccf_is_source_set (source_set_id),
  KEY idx_mccf_is_run (run_id),
  KEY idx_mccf_is_scored_at (scored_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='MCCF browser — cached integrity scores per requirement.';
