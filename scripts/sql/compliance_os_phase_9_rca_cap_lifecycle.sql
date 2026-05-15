-- =============================================================================
-- Compliance Operating System — Phase 9: RCA/CAP governance lifecycle
-- =============================================================================
-- Adds immutable RCA/CAP submission attempts, deadline extension history,
-- centralized approval history, and CAP-to-submission version linkage.
-- Apply: mysql ... < scripts/sql/compliance_os_phase_9_rca_cap_lifecycle.sql
-- =============================================================================

CREATE TABLE IF NOT EXISTS ipca_compliance_rca_cap_submissions (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  finding_id            BIGINT UNSIGNED NOT NULL,
  submission_no         INT UNSIGNED NOT NULL,
  submission_type       ENUM('authority','internal') NOT NULL,
  status                ENUM(
                          'draft',
                          'submitted',
                          'under_review',
                          'partially_approved',
                          'approved',
                          'rejected',
                          'superseded'
                        ) NOT NULL DEFAULT 'draft',
  rca_text              LONGTEXT NULL,
  cap_summary           LONGTEXT NULL,
  proposed_rca_deadline DATE NULL,
  proposed_cap_deadline DATE NULL,
  approved_rca_deadline DATE NULL,
  approved_cap_deadline DATE NULL,
  submitted_at          DATETIME NULL,
  submitted_by          BIGINT UNSIGNED NULL,
  reviewed_at           DATETIME NULL,
  reviewed_by           BIGINT UNSIGNED NULL,
  review_notes          LONGTEXT NULL,
  email_thread_id       BIGINT UNSIGNED NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  locked_at             DATETIME NULL,
  UNIQUE KEY uk_ipcarcsub_finding_no_type (finding_id, submission_no, submission_type),
  KEY idx_ipcarcsub_finding (finding_id),
  KEY idx_ipcarcsub_status (status),
  KEY idx_ipcarcsub_submitted (submitted_at),
  KEY idx_ipcarcsub_email_thread (email_thread_id),
  CONSTRAINT fk_ipcarcsub_finding FOREIGN KEY (finding_id)
    REFERENCES ipca_compliance_findings (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — immutable RCA/CAP submission attempts per finding.';

CREATE TABLE IF NOT EXISTS ipca_compliance_rca_cap_deadline_extensions (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  submission_id     BIGINT UNSIGNED NOT NULL,
  deadline_type     ENUM('rca','cap') NOT NULL,
  extension_no      INT UNSIGNED NOT NULL,
  previous_deadline DATE NOT NULL,
  requested_deadline DATE NOT NULL,
  approved_deadline DATE NULL,
  reason            LONGTEXT NULL,
  status            ENUM('draft','submitted','approved','rejected','withdrawn') NOT NULL DEFAULT 'draft',
  submitted_at      DATETIME NULL,
  reviewed_at       DATETIME NULL,
  reviewed_by       BIGINT UNSIGNED NULL,
  review_notes      LONGTEXT NULL,
  email_thread_id   BIGINT UNSIGNED NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcarcdext_submission_type_no (submission_id, deadline_type, extension_no),
  KEY idx_ipcarcdext_submission (submission_id),
  KEY idx_ipcarcdext_status (status),
  KEY idx_ipcarcdext_email_thread (email_thread_id),
  CONSTRAINT fk_ipcarcdext_submission FOREIGN KEY (submission_id)
    REFERENCES ipca_compliance_rca_cap_submissions (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — RCA/CAP package deadline extension requests.';

CREATE TABLE IF NOT EXISTS ipca_compliance_corrective_action_deadline_extensions (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  corrective_action_id BIGINT UNSIGNED NOT NULL,
  extension_no         INT UNSIGNED NOT NULL,
  previous_deadline    DATE NOT NULL,
  requested_deadline   DATE NOT NULL,
  approved_deadline    DATE NULL,
  reason               LONGTEXT NULL,
  status               ENUM('draft','submitted','approved','rejected','withdrawn') NOT NULL DEFAULT 'draft',
  submitted_at         DATETIME NULL,
  reviewed_at          DATETIME NULL,
  reviewed_by          BIGINT UNSIGNED NULL,
  review_notes         LONGTEXT NULL,
  email_thread_id      BIGINT UNSIGNED NULL,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipcacapdext_action_no (corrective_action_id, extension_no),
  KEY idx_ipcacapdext_action (corrective_action_id),
  KEY idx_ipcacapdext_status (status),
  KEY idx_ipcacapdext_email_thread (email_thread_id),
  CONSTRAINT fk_ipcacapdext_action FOREIGN KEY (corrective_action_id)
    REFERENCES ipca_compliance_corrective_actions (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — corrective-action deadline extension requests.';

CREATE TABLE IF NOT EXISTS ipca_compliance_approvals (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  object_type   VARCHAR(100) NOT NULL,
  object_id     BIGINT UNSIGNED NOT NULL,
  approval_type ENUM('rca','cap','deadline','extension','closure') NOT NULL,
  decision      ENUM('approved','rejected','partially_approved') NOT NULL,
  reviewed_by   BIGINT UNSIGNED NULL,
  reviewed_at   DATETIME NOT NULL,
  notes         LONGTEXT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ipcaappr_object (object_type, object_id),
  KEY idx_ipcaappr_type (approval_type),
  KEY idx_ipcaappr_decision (decision),
  KEY idx_ipcaappr_reviewed (reviewed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Compliance OS — centralized append-only approval history.';

DELIMITER $$
CREATE PROCEDURE ipca_phase9_add_submission_column()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'ipca_compliance_corrective_actions'
       AND COLUMN_NAME = 'submission_id'
  ) THEN
    ALTER TABLE ipca_compliance_corrective_actions
      ADD COLUMN submission_id BIGINT UNSIGNED NULL AFTER finding_id;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'ipca_compliance_corrective_actions'
       AND INDEX_NAME = 'idx_ipcacap_submission'
  ) THEN
    ALTER TABLE ipca_compliance_corrective_actions
      ADD INDEX idx_ipcacap_submission (submission_id);
  END IF;
END$$
DELIMITER ;

CALL ipca_phase9_add_submission_column();
DROP PROCEDURE ipca_phase9_add_submission_column;

-- Backfill one baseline internal submission per finding that has RCA or CAP state.
INSERT INTO ipca_compliance_rca_cap_submissions (
  finding_id, submission_no, submission_type, status, rca_text, cap_summary,
  proposed_cap_deadline, approved_cap_deadline, submitted_at, submitted_by,
  reviewed_at, reviewed_by, review_notes, created_at, updated_at, locked_at
)
SELECT
  f.id,
  1,
  'internal',
  CASE
    WHEN r.locked_at IS NOT NULL THEN 'approved'
    WHEN EXISTS (
      SELECT 1 FROM ipca_compliance_corrective_actions c0
       WHERE c0.finding_id = f.id
         AND COALESCE(c0.status,'') IN ('APPROVED','IN_PROGRESS','COMPLETED','VERIFIED')
    ) THEN 'approved'
    ELSE 'draft'
  END,
  r.root_cause_text,
  (
    SELECT GROUP_CONCAT(CONCAT(c1.action_code, ': ', c1.title) ORDER BY c1.id SEPARATOR '\n')
      FROM ipca_compliance_corrective_actions c1
     WHERE c1.finding_id = f.id
  ),
  (
    SELECT MIN(c2.due_date)
      FROM ipca_compliance_corrective_actions c2
     WHERE c2.finding_id = f.id AND c2.due_date IS NOT NULL
  ),
  (
    SELECT MIN(c3.due_date)
      FROM ipca_compliance_corrective_actions c3
     WHERE c3.finding_id = f.id
       AND c3.due_date IS NOT NULL
       AND COALESCE(c3.status,'') IN ('APPROVED','IN_PROGRESS','COMPLETED','VERIFIED')
  ),
  COALESCE(r.approved_at, f.created_at),
  COALESCE(r.approved_by, f.created_by),
  r.approved_at,
  r.approved_by,
  'Baseline submission generated from pre-Phase-9 RCA/CAP state.',
  f.created_at,
  NOW(),
  r.locked_at
FROM ipca_compliance_findings f
LEFT JOIN ipca_compliance_finding_rca r ON r.finding_id = f.id
WHERE (
    r.id IS NOT NULL
    OR EXISTS (SELECT 1 FROM ipca_compliance_corrective_actions c WHERE c.finding_id = f.id)
  )
  AND NOT EXISTS (
    SELECT 1 FROM ipca_compliance_rca_cap_submissions s
     WHERE s.finding_id = f.id
       AND s.submission_no = 1
       AND s.submission_type = 'internal'
  );

UPDATE ipca_compliance_corrective_actions c
JOIN ipca_compliance_rca_cap_submissions s
  ON s.finding_id = c.finding_id
 AND s.submission_no = 1
 AND s.submission_type = 'internal'
SET c.submission_id = s.id
WHERE c.submission_id IS NULL;

-- New installs with no CAP rows can still enforce immediately. Existing installs
-- should have all current CAP rows attached by the backfill above.
ALTER TABLE ipca_compliance_corrective_actions
  MODIFY COLUMN submission_id BIGINT UNSIGNED NOT NULL;

DELIMITER $$
CREATE PROCEDURE ipca_phase9_add_submission_fk()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'ipca_compliance_corrective_actions'
       AND CONSTRAINT_NAME = 'fk_ipcacap_submission'
  ) THEN
    ALTER TABLE ipca_compliance_corrective_actions
      ADD CONSTRAINT fk_ipcacap_submission FOREIGN KEY (submission_id)
        REFERENCES ipca_compliance_rca_cap_submissions (id) ON DELETE RESTRICT ON UPDATE CASCADE;
  END IF;
END$$
DELIMITER ;

CALL ipca_phase9_add_submission_fk();
DROP PROCEDURE ipca_phase9_add_submission_fk;

-- =============================================================================
-- END OF FILE
-- =============================================================================
