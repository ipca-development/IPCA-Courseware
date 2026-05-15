-- =============================================================================
-- Compliance Operating System — Phase 9.1: Normalize legacy audit references
-- =============================================================================
-- Normalises imported audit references such as:
--   LEGACY-AUD-BCAA-ATO-OUT-1  ->  BCAA.ATO.OUT.1
--   AUD-BCAA-ATO-OUT-1         ->  BCAA.ATO.OUT.1
--   LEGACY_AUD_BCAA_ATO_OUT_1  ->  BCAA.ATO.OUT.1
--
-- Safety:
-- - Skips rows where the target audit_code already exists.
-- - Skips duplicate target codes within the cleanup set.
-- - Prints skipped rows before applying updates.
-- =============================================================================

DROP TEMPORARY TABLE IF EXISTS tmp_ipca_legacy_audit_code_cleanup;
DROP TEMPORARY TABLE IF EXISTS tmp_ipca_legacy_audit_code_duplicates;

CREATE TEMPORARY TABLE tmp_ipca_legacy_audit_code_cleanup (
  id       BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  old_code VARCHAR(64) NOT NULL,
  new_code VARCHAR(64) NOT NULL,
  KEY idx_tmp_ipca_legacy_audit_new_code (new_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tmp_ipca_legacy_audit_code_cleanup (id, old_code, new_code)
SELECT
  id,
  audit_code AS old_code,
  TRIM(BOTH '.' FROM REGEXP_REPLACE(
    REGEXP_REPLACE(audit_code, '^(LEGACY[-_]+)?AUD[-_]+', ''),
    '[^A-Za-z0-9]+',
    '.'
  )) AS new_code
FROM ipca_compliance_audits
WHERE audit_code REGEXP '^(LEGACY[-_]+)?AUD[-_]+';

CREATE TEMPORARY TABLE tmp_ipca_legacy_audit_code_duplicates (
  new_code VARCHAR(64) NOT NULL PRIMARY KEY
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tmp_ipca_legacy_audit_code_duplicates (new_code)
SELECT new_code
FROM tmp_ipca_legacy_audit_code_cleanup
GROUP BY new_code
HAVING COUNT(*) > 1;

SELECT
  c.old_code,
  c.new_code,
  'SKIPPED: target audit_code already exists' AS note
FROM tmp_ipca_legacy_audit_code_cleanup c
JOIN ipca_compliance_audits existing
  ON existing.audit_code = c.new_code
 AND existing.id <> c.id;

SELECT
  c.old_code,
  c.new_code,
  'SKIPPED: more than one legacy audit would map to this same target code' AS note
FROM tmp_ipca_legacy_audit_code_cleanup c
JOIN tmp_ipca_legacy_audit_code_duplicates dup ON dup.new_code = c.new_code;

UPDATE ipca_compliance_audits a
JOIN tmp_ipca_legacy_audit_code_cleanup c ON c.id = a.id
LEFT JOIN ipca_compliance_audits existing
  ON existing.audit_code = c.new_code
 AND existing.id <> a.id
LEFT JOIN tmp_ipca_legacy_audit_code_duplicates dup ON dup.new_code = c.new_code
SET a.audit_code = c.new_code
WHERE existing.id IS NULL
  AND dup.new_code IS NULL
  AND c.new_code <> ''
  AND c.new_code <> c.old_code;

DROP TEMPORARY TABLE IF EXISTS tmp_ipca_legacy_audit_code_duplicates;
DROP TEMPORARY TABLE IF EXISTS tmp_ipca_legacy_audit_code_cleanup;

-- =============================================================================
-- END OF FILE
-- =============================================================================
