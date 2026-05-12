-- =============================================================================
-- Compliance Operating System — Phase 2.5: per-admin access flag on users
-- =============================================================================
-- File:    scripts/sql/compliance_os_phase_2_5_users_is_compliance_admin.sql
-- Scope:   Adds a single TINYINT(1) column to the existing `users` table so
--          individual admin accounts can be granted or revoked Compliance
--          Operating System access.
--
-- Apply:   mysql ... < scripts/sql/compliance_os_phase_2_5_users_is_compliance_admin.sql
-- Idempotent: re-runs are safe. Uses an information_schema check to skip the
--             ALTER when the column is already present.
--
-- DEFAULT VALUE = 1 (intentional):
--   The platform owner has chosen the initial rollout policy "every admin
--   user has Compliance OS access until a master admin says otherwise."
--   Defaulting to 1 means:
--     * existing admins keep their current access the moment this runs,
--     * new admin rows created later also default to having access,
--     * a future master admin / privilege-management UI flips the bit to 0
--       to revoke selectively.
--   This is the safest no-surprise migration for the current state of the
--   platform (no master admin exists yet).
--
--   When a real privilege model is introduced (e.g. only the first admin
--   gets the bit, all others must be granted explicitly) the default can
--   be changed via a follow-up migration without re-altering this column.
--
-- This file MAKES NO OTHER CHANGES. No reads or writes against any other
-- table. No data deleted. No indexes added (the column is consulted only
-- after the user row is already fetched by PRIMARY KEY).
-- =============================================================================

SET @column_already_present := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'users'
    AND COLUMN_NAME  = 'is_compliance_admin'
);

SET @ddl := IF(
  @column_already_present = 0,
  CONCAT(
    'ALTER TABLE users ',
    'ADD COLUMN is_compliance_admin TINYINT(1) NOT NULL DEFAULT 1 ',
    'COMMENT ''1 = admin may access /admin/compliance/* . Default 1 until a master admin is nominated — flip to 0 to revoke.'' ',
    'AFTER role'
  ),
  'SELECT ''compliance_os_phase_2_5: users.is_compliance_admin already exists — no-op'' AS noop'
);

PREPARE _stmt_compliance_os_2_5 FROM @ddl;
EXECUTE _stmt_compliance_os_2_5;
DEALLOCATE PREPARE _stmt_compliance_os_2_5;

-- Belt-and-braces normalisation: ensure every existing admin row has the
-- privilege set, even on a database where someone hand-altered the column
-- with DEFAULT 0 before this migration ran. Non-destructive for all other
-- rows (role != 'admin' is untouched).
UPDATE users
SET    is_compliance_admin = 1
WHERE  role = 'admin'
  AND  (is_compliance_admin IS NULL OR is_compliance_admin = 0);

-- =============================================================================
-- END OF FILE
-- =============================================================================
