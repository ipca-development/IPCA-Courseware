-- =============================================================================
-- Compliance OS — Update test audit to BCAA CMS audit
-- =============================================================================
-- Purpose:
--   Convert the existing test audit reference `2026.0001` into the historical
--   BCAA Compliance Management System audit record.
--
-- Safe to re-run:
--   - Only updates the row with the old test reference when the target reference
--     does not already exist.
--   - If the target reference already exists, this script leaves data unchanged.
-- =============================================================================

UPDATE ipca_compliance_audits a
LEFT JOIN ipca_compliance_audits existing
  ON existing.audit_code = 'BCAA.ATO.CMS.15'
 AND existing.id <> a.id
SET
  a.audit_code = 'BCAA.ATO.CMS.15',
  a.title = 'BCAA Audit Compliance Management System',
  a.authority = 'BCAA',
  a.audit_type = 'FCL/ATO/Compliance Management System',
  a.start_date = '2024-10-15',
  a.end_date = '2024-10-15',
  a.updated_at = NOW()
WHERE a.audit_code = '2026.0001'
  AND existing.id IS NULL;

SELECT
  id,
  audit_code,
  title,
  authority,
  audit_type,
  start_date,
  end_date
FROM ipca_compliance_audits
WHERE audit_code IN ('2026.0001', 'BCAA.ATO.CMS.15');
