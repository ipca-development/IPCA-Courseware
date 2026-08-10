-- Master Logbook CVR leg → individual logbook proposals (students + instructors).
-- Additive and re-run safe.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET collation_connection = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipca_cvr_logbook_proposals (
  id                            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  proposal_uuid                 CHAR(36) NOT NULL,
  organization_id               BIGINT UNSIGNED NOT NULL DEFAULT 1,
  dispatch_id                   BIGINT UNSIGNED NOT NULL,
  workflow_flight_record_uuid   CHAR(36) NOT NULL,
  leg_uuid                      CHAR(36) NULL,
  owner_user_id                 BIGINT UNSIGNED NOT NULL,
  owner_role                    VARCHAR(64) NOT NULL DEFAULT '',
  entry_type                    VARCHAR(64) NOT NULL DEFAULT 'student_dual',
  proposed_duration_ms          BIGINT UNSIGNED NOT NULL DEFAULT 0,
  proposed_values_json          JSON NOT NULL,
  status                        VARCHAR(32) NOT NULL DEFAULT 'PROPOSED',
  target_entry_id               BIGINT UNSIGNED NULL,
  accepted_at                   DATETIME(3) NULL,
  accepted_by                   BIGINT UNSIGNED NULL,
  created_at                    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at                    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_ipca_cvr_logbook_proposals_uuid (proposal_uuid),
  UNIQUE KEY uk_ipca_cvr_logbook_proposals_owner_entry (dispatch_id, owner_user_id, entry_type),
  KEY idx_ipca_cvr_logbook_proposals_owner_status (owner_user_id, status, created_at),
  KEY idx_ipca_cvr_logbook_proposals_flight (workflow_flight_record_uuid),
  KEY idx_ipca_cvr_logbook_proposals_dispatch (dispatch_id),
  CONSTRAINT fk_ipca_cvr_logbook_proposals_dispatch
    FOREIGN KEY (dispatch_id) REFERENCES ipca_cvr_dispatches(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Proposed individual logbook entries generated from Master Logbook CVR legs.';
