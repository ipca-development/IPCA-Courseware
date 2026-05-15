-- =============================================================================
-- TablePlus / MySQL — import legacy compliance dump tables → ipca_compliance_*
-- =============================================================================
-- MySQL 8.0+ (uses REGEXP_REPLACE, REGEXP_SUBSTR, ROW_NUMBER, JSON functions).
--
-- BEFORE YOU RUN
--   1) Import your TablePlus dump so legacy tables live in a schema, e.g.
--      `legacy_compliance` (tables: audits, findings, finding_rca,
--      finding_actions, compliance_domains, finding_mccf_links, mccf_items,
--      mccf_manuals, mccf_requirements).
--   2) In TablePlus, select the Courseware database that already has Phase 1
--      `ipca_compliance_*` tables (this script writes without qualifying the
--      target — current schema = target).
--   3) Search/replace in this file:
--        legacy_compliance   → your legacy schema name (if different)
--
-- IDEMPOTENCY
--   Skips inserting the audit if `audit_code` already exists (same formula as
--   the PHP importer). Re-run after DELETE of imported rows if needed.
--
-- DOES NOT IMPORT
--   ai_finding_runs, manual_excerpts, audit_log — use the PHP script for AI logs
--   if you need them.
-- =============================================================================

SET NAMES utf8mb4;

START TRANSACTION;

-- ---------------------------------------------------------------------------
-- 1) Audit (one row expected)
-- ---------------------------------------------------------------------------
INSERT INTO ipca_compliance_audits (
  case_id, audit_code, title, authority, audit_category, audit_type,
  audit_entity, external_ref, status, subject,
  start_date, end_date, closed_date,
  lead_auditor_id, created_by, updated_by, created_at, updated_at
)
SELECT
  NULL,
  LEFT(TRIM(BOTH '.' FROM REGEXP_REPLACE(
    TRIM(COALESCE(NULLIF(a.external_ref, ''), 'IMPORT')),
    '[^A-Za-z0-9]+',
    '.'
  )), 64),
  a.title,
  CASE
    WHEN a.audit_entity LIKE '%BCAA%' THEN 'BCAA'
    WHEN UPPER(a.authority) IN ('BCAA', 'FAA', 'EASA', 'INTERNAL', 'OTHER') THEN UPPER(a.authority)
    ELSE 'INTERNAL'
  END,
  CASE UPPER(COALESCE(a.audit_category, ''))
    WHEN 'CAA' THEN 'COMPLIANCE'
    WHEN '' THEN NULL
    ELSE LEFT(a.audit_category, 32)
  END,
  LEFT(a.audit_type, 64),
  NULLIF(TRIM(a.audit_entity), ''),
  NULLIF(TRIM(a.external_ref), ''),
  UPPER(a.status),
  NULLIF(TRIM(BOTH '\n' FROM CONCAT_WS('',
    NULLIF(a.subject, ''),
    IF(CHAR_LENGTH(COALESCE(a.auditors, '')) > 0, CONCAT('\n\nAuditors (legacy):\n', a.auditors), NULL),
    IF(CHAR_LENGTH(COALESCE(a.attendees, '')) > 0, CONCAT('\n\nAttendees (legacy):\n', a.attendees), NULL)
  )), ''),
  NULLIF(NULLIF(a.start_date, '0000-00-00'), NULL),
  NULLIF(NULLIF(a.end_date, '0000-00-00'), NULL),
  NULLIF(NULLIF(a.closed_date, '0000-00-00'), NULL),
  NULL, NULL, NULL,
  COALESCE(a.created_at, NOW()),
  COALESCE(a.updated_at, a.created_at, NOW())
FROM legacy_compliance.audits a
WHERE NOT EXISTS (
  SELECT 1 FROM ipca_compliance_audits t
  WHERE t.audit_code = LEFT(TRIM(BOTH '.' FROM REGEXP_REPLACE(
    TRIM(COALESCE(NULLIF(a.external_ref, ''), 'IMPORT')),
    '[^A-Za-z0-9]+',
    '.'
  )), 64)
)
LIMIT 1;

SET @new_audit_id := LAST_INSERT_ID();

-- If this audit_code already existed, reuse that row’s id (helps re-run in TablePlus).
SET @new_audit_id := IF(
  @new_audit_id > 0,
  @new_audit_id,
  IFNULL((
    SELECT t.id
    FROM ipca_compliance_audits t
    WHERE t.audit_code = (
      SELECT LEFT(TRIM(BOTH '.' FROM REGEXP_REPLACE(
        TRIM(COALESCE(NULLIF(a.external_ref, ''), 'IMPORT')),
        '[^A-Za-z0-9]+',
        '.'
      )), 64)
      FROM legacy_compliance.audits a
      LIMIT 1
    )
    LIMIT 1
  ), 0)
);

SELECT
  IF(
    @new_audit_id = 0,
    'STOP: no audit id resolved (legacy `audits` empty or audit_code mismatch). ROLLBACK.',
    CONCAT('Using audit id=', @new_audit_id, ' — proceed with findings (re-run may skip duplicates).')
  ) AS import_audit_status;

-- ---------------------------------------------------------------------------
-- 2) Findings
-- ---------------------------------------------------------------------------
INSERT INTO ipca_compliance_findings (
  audit_id, case_id, finding_code, reference, title, description,
  classification, severity, status, domain_code, requirement_key,
  regulation_summary, raised_date, target_date, closed_date,
  cap_selected_option, cap_selected_effort, notes,
  created_by, updated_by, created_at, updated_at
)
SELECT
  @new_audit_id,
  NULL,
  CONCAT(
    'NCR-2026-',
    LPAD(
      COALESCE(NULLIF(REGEXP_SUBSTR(f.reference, '[0-9]+$'), ''), '0'),
      5,
      '0'
    )
  ),
  NULLIF(TRIM(f.reference), ''),
  f.title,
  f.description,
  UPPER(f.classification),
  UPPER(f.severity),
  UPPER(f.status),
  d.code,
  NULLIF(TRIM(f.requirement_key), ''),
  NULLIF(TRIM(f.regulation_ref), ''),
  COALESCE(NULLIF(f.raised_date, '0000-00-00'), CURDATE()),
  NULLIF(NULLIF(f.target_date, '0000-00-00'), NULL),
  NULLIF(NULLIF(f.closed_date, '0000-00-00'), NULL),
  NULLIF(TRIM(f.cap_selected_option), ''),
  NULLIF(TRIM(f.cap_selected_effort), ''),
  NULLIF(TRIM(f.notes), ''),
  NULL, NULL,
  COALESCE(f.created_at, NOW()),
  COALESCE(f.updated_at, f.created_at, NOW())
FROM legacy_compliance.findings f
LEFT JOIN legacy_compliance.compliance_domains d ON d.id <=> f.domain_id
WHERE NOT EXISTS (
  SELECT 1 FROM ipca_compliance_findings x
  WHERE x.audit_id = @new_audit_id
    AND x.finding_code = CONCAT(
      'NCR-2026-',
      LPAD(
        COALESCE(NULLIF(REGEXP_SUBSTR(f.reference, '[0-9]+$'), ''), '0'),
        5,
        '0'
      )
    )
)
ORDER BY f.reference;

-- ---------------------------------------------------------------------------
-- 3) Map legacy finding UUID (binary) → new BIGINT
-- ---------------------------------------------------------------------------
CREATE TEMPORARY TABLE _ipca_legacy_finding_map (
  legacy_id BINARY(16) NOT NULL PRIMARY KEY,
  new_id BIGINT UNSIGNED NOT NULL,
  KEY idx_new (new_id)
) ENGINE=Memory;

INSERT INTO _ipca_legacy_finding_map (legacy_id, new_id)
SELECT f.id, i.id
FROM legacy_compliance.findings f
INNER JOIN ipca_compliance_findings i
  ON i.audit_id = @new_audit_id
 AND (i.reference <=> NULLIF(TRIM(f.reference), ''));

-- ---------------------------------------------------------------------------
-- 4) RCA
-- ---------------------------------------------------------------------------
INSERT INTO ipca_compliance_finding_rca (
  finding_id, method, steps_json, root_cause_text, ai_assisted, ai_run_id,
  approved_by, approved_by_name, approved_at, locked_at, locked_by, lock_reason,
  created_by, updated_by, created_at, updated_at
)
SELECT
  m.new_id,
  'FIVE_WHYS',
  CAST(
    IF(JSON_VALID(fr.steps_json), fr.steps_json, JSON_ARRAY()) AS JSON
  ),
  NULLIF(TRIM(fr.root_cause_text), ''),
  0, NULL,
  NULL,
  NULLIF(TRIM(fr.approved_by_name), ''),
  IF(
    fr.approved_at IS NULL OR fr.approved_at < '1970-01-02',
    NULL,
    fr.approved_at
  ),
  IF(
    fr.locked_at IS NULL OR fr.locked_at < '1970-01-02',
    NULL,
    fr.locked_at
  ),
  NULL,
  NULLIF(TRIM(fr.lock_reason), ''),
  NULL, NULL,
  COALESCE(fr.created_at, NOW()),
  COALESCE(fr.updated_at, fr.created_at, NOW())
FROM legacy_compliance.finding_rca fr
INNER JOIN _ipca_legacy_finding_map m ON m.legacy_id = fr.finding_id
WHERE NOT EXISTS (
  SELECT 1 FROM ipca_compliance_finding_rca r2 WHERE r2.finding_id = m.new_id
);

-- ---------------------------------------------------------------------------
-- 5) Corrective actions (finding_actions)
-- ---------------------------------------------------------------------------
INSERT INTO ipca_compliance_corrective_actions (
  finding_id, case_id, action_code, action_type, title, description, status,
  effort, responsible_user_id, responsible_name, due_date,
  started_at, completed_at, verified_at, verified_by,
  ai_assisted, ai_run_id, created_by, updated_by, created_at, updated_at
)
SELECT
  m.new_id,
  NULL,
  CONCAT('CAP-IMP-', m.new_id, '-', LPAD(w.rn, 2, '0')),
  CASE UPPER(TRIM(fa.action_type))
    WHEN 'PREVENTIVE' THEN 'PREVENTIVE'
    WHEN 'CONTAINMENT' THEN 'CONTAINMENT'
    WHEN 'IMMEDIATE' THEN 'IMMEDIATE'
    ELSE 'CORRECTIVE'
  END,
  LEFT(fa.description, 255),
  fa.description,
  IF(
    fa.completed_at IS NOT NULL AND fa.completed_at >= '1970-01-02',
    'COMPLETED',
    'PROPOSED'
  ),
  NULL, NULL, NULL,
  NULLIF(NULLIF(fa.due_date, '0000-00-00'), NULL),
  NULL,
  IF(
    fa.completed_at IS NULL OR fa.completed_at < '1970-01-02',
    NULL,
    fa.completed_at
  ),
  NULL, NULL,
  0, NULL,
  NULL, NULL,
  COALESCE(fa.created_at, NOW()),
  NOW()
FROM legacy_compliance.finding_actions fa
INNER JOIN _ipca_legacy_finding_map m ON m.legacy_id = fa.finding_id
INNER JOIN (
  SELECT
    id AS fa_id,
    finding_id AS fa_fid,
    ROW_NUMBER() OVER (PARTITION BY finding_id ORDER BY id) AS rn
  FROM legacy_compliance.finding_actions
) w ON w.fa_id = fa.id AND w.fa_fid = fa.finding_id
WHERE NOT EXISTS (
  SELECT 1 FROM ipca_compliance_corrective_actions c
  WHERE c.finding_id = m.new_id
    AND c.action_code = CONCAT('CAP-IMP-', m.new_id, '-', LPAD(w.rn, 2, '0'))
);

-- ---------------------------------------------------------------------------
-- 6) MCCF links — from findings.requirement_key (+ subject from mccf_requirements)
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO ipca_compliance_finding_mccf_links (
  finding_id, requirement_key, manual_code, mccf_subject, link_type, notes
)
SELECT
  m.new_id,
  TRIM(f.requirement_key),
  UPPER(
    REGEXP_SUBSTR(
      SUBSTRING_INDEX(TRIM(f.requirement_key), '|', 1),
      '^[A-Za-z_]+'
    )
  ),
  (
    SELECT LEFT(mr.subject, 255)
    FROM legacy_compliance.mccf_requirements mr
    WHERE mr.requirement_key = TRIM(f.requirement_key)
    LIMIT 1
  ),
  'PRIMARY',
  'TablePlus SQL import: findings.requirement_key'
FROM legacy_compliance.findings f
INNER JOIN _ipca_legacy_finding_map m ON m.legacy_id = f.id
WHERE f.requirement_key IS NOT NULL
  AND TRIM(f.requirement_key) <> '';

-- ---------------------------------------------------------------------------
-- 7) MCCF links — legacy finding_mccf_links (needs mccf_items + mccf_manuals)
--    Tuple match to mccf_requirements for canonical requirement_key
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO ipca_compliance_finding_mccf_links (
  finding_id, requirement_key, manual_code, mccf_subject, link_type, notes
)
SELECT
  m.new_id,
  mr.requirement_key,
  UPPER(TRIM(mr.manual_code)),
  LEFT(mr.subject, 255),
  'PRIMARY',
  CONCAT('TablePlus SQL import: finding_mccf_links item_id=', fl.mccf_item_id)
FROM legacy_compliance.finding_mccf_links fl
INNER JOIN _ipca_legacy_finding_map m ON m.legacy_id = fl.finding_id
INNER JOIN legacy_compliance.mccf_items mi ON mi.id = fl.mccf_item_id
INNER JOIN legacy_compliance.mccf_manuals mm ON mm.id = mi.manual_id
INNER JOIN legacy_compliance.mccf_requirements mr
  ON UPPER(TRIM(mr.manual_code)) = UPPER(TRIM(mm.code))
 AND UPPER(TRIM(mr.item_no)) = UPPER(TRIM(mi.item_number))
 AND UPPER(TRIM(mr.sub_item_no)) = UPPER(TRIM(mi.subitem_number))
 AND (
  CASE
    WHEN UPPER(TRIM(mr.manual_part)) REGEXP '^P[0-9]+$'
      THEN UPPER(TRIM(mr.manual_part))
    WHEN TRIM(mr.manual_part) REGEXP '[0-9]+'
      THEN CONCAT('P', REGEXP_SUBSTR(mr.manual_part, '[0-9]+'))
    ELSE 'P0'
  END
 ) = (
  CASE
    WHEN UPPER(TRIM(mi.part)) REGEXP '^P[0-9]+$'
      THEN UPPER(TRIM(mi.part))
    WHEN TRIM(mi.part) REGEXP '^[0-9]+$'
      THEN CONCAT('P', TRIM(mi.part))
    WHEN UPPER(mi.part) REGEXP 'PART'
      THEN CONCAT('P', REGEXP_SUBSTR(mi.part, '[0-9]+'))
    ELSE 'P0'
  END
 );

-- ---------------------------------------------------------------------------
-- Review then commit or rollback
-- ---------------------------------------------------------------------------
-- SELECT COUNT(*) AS findings FROM ipca_compliance_findings WHERE audit_id = @new_audit_id;
-- SELECT COUNT(*) AS rca FROM ipca_compliance_finding_rca r INNER JOIN ipca_compliance_findings f ON f.id = r.finding_id WHERE f.audit_id = @new_audit_id;
-- SELECT COUNT(*) AS cap FROM ipca_compliance_corrective_actions c INNER JOIN ipca_compliance_findings f ON f.id = c.finding_id WHERE f.audit_id = @new_audit_id;

COMMIT;

-- If anything looks wrong, use ROLLBACK; instead of COMMIT; before closing the tab.
